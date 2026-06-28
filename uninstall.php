<?php
/**
 * Uninstall handler — runs ONLY when the user deletes the plugin from WP admin.
 *
 * By design, this is the ONLY code path that deletes plugin-owned data.
 * Deactivation keeps data intact (per plan §21).
 *
 * Phase 0: stub. Phase 6 will:
 *   - Respect the "delete data on uninstall" admin setting
 *   - Drop all vyg_* tables
 *   - Delete all vyg_* options
 *   - Clear all vyg_* transients
 *   - Remove uploaded debug logs
 *
 * @package VectorYT\Gallery
 */

declare(strict_types=1);

defined( 'WP_UNINSTALL_PLUGIN' ) || exit;

// Phase 0: nothing to delete yet. Tables and options don't exist.
// Phase 6 will add: delete_option('vyg_settings'), drop_table calls, etc.