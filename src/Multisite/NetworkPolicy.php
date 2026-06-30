<?php
/**
 * Multisite policy + per-site cleanup tool.
 *
 * Phase 12.4 introduces explicit, auditable handling of WordPress
 * Multisite installs. The plugin's policy is "per-site":
 *   - Each site has its own `vyg_*` tables (`{$wpdb->prefix}vyg_videos`,
 *     etc). The `{$wpdb->prefix}` is already per-site in WP, so tables
 *     naturally do not leak.
 *   - Each site has its own settings (the `vyg_settings` option).
 *   - There is no `vyg_settings` site-meta entry; instead, the network
 *     admin can see per-site summaries via `wp vyg network
 *     diagnostics` and toggle the per-site plugin from the network
 *     plugins screen.
 *   - On network activation, we run the activation hook on every site
 *     so each site's tables are created. On a single-site install,
 *     the activation hook runs as before.
 *   - On uninstall, only the active site's plugin data is removed.
 *     Network admins get a separate command to clean up a site's
 *     data when removing the site: `wp vyg site-cleanup <site_id>`.
 *
 * This class is the canonical source of truth for those decisions
 * and is exercised by both the live Plugin activation hook and the
 * `wp vyg site-cleanup` and `wp vyg network-diagnostics` CLI
 * subcommands.
 *
 * @package VectorYT\Gallery\Multisite
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Multisite;

use VectorYT\Gallery\Plugin;
use WP_Site;

defined('ABSPATH') || exit;

class NetworkPolicy
{
    /**
     * Run the standard activation hook across every site in a
     * multisite network. On a single-site install this is a no-op
     * (the regular activation hook fires automatically). This is
     * only called when the plugin is network-activated.
     */
    public static function on_network_activate(): void
    {
        if (!self::is_multisite()) {
            // Single-site install; the normal activation hook
            // already ran. Nothing to do here.
            return;
        }
        $site_ids = self::get_site_ids();
        if (empty($site_ids)) {
            return;
        }
        // Walk every site, switch into it, run the per-site
        // activation, then restore. We don't want to leave the
        // switched context behind if any activation throws.
        $original = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;
        foreach ($site_ids as $site_id) {
            if (function_exists('switch_to_blog')) {
                switch_to_blog((int) $site_id);
            }
            try {
                Plugin::on_activate();
            } catch (\Throwable $e) {
                // Best-effort: log + continue. We do not want one
                // bad site to block the rest of the network.
                if (function_exists('error_log')) {
                    error_log(sprintf('vyg: network activation failed for site %d: %s', (int) $site_id, $e->getMessage()));
                }
            } finally {
                if ($original && function_exists('restore_current_blog')) {
                    restore_current_blog();
                }
            }
        }
    }

    /**
     * Drop every VYG table + every VYG option for a single site.
     * The caller (CLI or uninstall hook) is responsible for
     * `switch_to_blog()` if the target site is not the current one.
     */
    public static function site_uninstall(): int
    {
        global $wpdb;
        $dropped = 0;

        // Drop every table prefixed with vyg_ in the current site.
        $tables = $wpdb->get_results(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($wpdb->prefix . 'vyg_') . '%'
            )
        );
        foreach ((array) $tables as $row_obj) {
            // SHOW TABLES returns a column whose name is the table
            // name, but the key varies. The first scalar in the
            // row is always the table name.
            $row = (array) $row_obj;
            $table_name = (string) reset($row);
            if ('' === $table_name) {
                continue;
            }
            $wpdb->query("DROP TABLE IF EXISTS `{$table_name}`");
            $dropped++;
        }

        // Drop every vyg_* option (settings, version, sync state).
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s",
                $wpdb->esc_like('vyg_') . '%'
            )
        );

        // Drop the cache-version sentinel used by FeedQueryCache.
        delete_option('vyg_feed_query_cache_version');

        // Drop scheduled cron events for the current site.
        $hooks = array(
            'vyg_cron_incremental_all',
            'vyg_cron_metadata_refresh',
            'vyg_cron_live_poll',
            'vyg_cron_data_retention',
            'vyg_cron_analytics_retention',
            'vyg_cron_quota_reset',
        );
        foreach ($hooks as $hook) {
            if (function_exists('wp_clear_scheduled_hook')) {
                wp_clear_scheduled_hook($hook);
            }
        }

        // Drop orphan transients. Transients in WP are stored as
        // `_transient_vyg_xxx` / `_transient_timeout_vyg_xxx`.
        $wpdb->query(
            $wpdb->prepare(
                "DELETE FROM {$wpdb->options} WHERE option_name LIKE %s OR option_name LIKE %s",
                $wpdb->esc_like('_transient_vyg_') . '%',
                $wpdb->esc_like('_transient_timeout_vyg_') . '%'
            )
        );

        return $dropped;
    }

    /**
     * Build a per-site diagnostics summary. On a single-site install
     * returns a single entry for the current site. On a multisite
     * network returns one entry per site, with the same shape as
     * `Command::diagnostics()` minus the runtime fields.
     *
     * @return array<int,array<string,mixed>>
     */
    public static function network_diagnostics(): array
    {
        if (!self::is_multisite()) {
            return array(self::current_site_snapshot());
        }
        $out = array();
        $original = function_exists('get_current_blog_id') ? get_current_blog_id() : 0;
        foreach (self::get_site_ids() as $site_id) {
            if (function_exists('switch_to_blog')) {
                switch_to_blog((int) $site_id);
            }
            try {
                $out[] = self::current_site_snapshot();
            } finally {
                if ($original && function_exists('restore_current_blog')) {
                    restore_current_blog();
                }
            }
        }
        return $out;
    }

    /**
     * @return array<string,mixed>
     */
    private static function current_site_snapshot(): array
    {
        global $wpdb;
        $site_id = function_exists('get_current_blog_id') ? (int) get_current_blog_id() : 0;
        return array(
            'site_id'      => $site_id,
            'site_url'     => function_exists('home_url') ? home_url() : '',
            'vyg_active'   => self::is_plugin_active_on_current_site(),
            'counts'       => array(
                'sources'  => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sources"),
                'feeds'    => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_feeds"),
                'videos'   => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_videos"),
                'jobs'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sync_jobs"),
                'logs'     => (int) $wpdb->get_var("SELECT COUNT(*) FROM {$wpdb->prefix}vyg_sync_logs"),
            ),
            'cache_version' => (int) get_option('vyg_feed_query_cache_version_global', 0),
        );
    }

    /**
     * @return array<int,int>
     */
    private static function get_site_ids(): array
    {
        if (!function_exists('get_sites')) {
            return array();
        }
        $sites = get_sites(array('fields' => 'ids', 'number' => 0));
        return array_map('intval', (array) $sites);
    }

    public static function is_multisite(): bool
    {
        return function_exists('is_multisite') && is_multisite();
    }

    public static function is_network_active(): bool
    {
        if (!self::is_multisite()) {
            return false;
        }
        if (!function_exists('is_plugin_active_for_network')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('is_plugin_active_for_network')) {
            return false;
        }
        return (bool) is_plugin_active_for_network(VYG_PLUGIN_BASENAME);
    }

    private static function is_plugin_active_on_current_site(): bool
    {
        if (!function_exists('is_plugin_active')) {
            require_once ABSPATH . 'wp-admin/includes/plugin.php';
        }
        if (!function_exists('is_plugin_active')) {
            return false;
        }
        return is_plugin_active(VYG_PLUGIN_BASENAME);
    }
}
