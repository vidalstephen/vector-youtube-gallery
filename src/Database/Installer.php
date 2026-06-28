<?php
/**
 * Database installer — invoked on plugin activation.
 *
 * Runs dbDelta() on every schema statement. dbDelta is idempotent: it
 * creates missing tables, adds missing columns, drops columns that are no
 * longer in the SQL. We treat all output as informational.
 *
 * After schema install, registers the current DB version and runs the
 * migrator for any post-1.0 data migrations.
 *
 * @package VectorYT\Gallery\Database
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Database;

use VectorYT\Gallery\Logging\Logger;

defined( 'ABSPATH' ) || exit;

final class Installer {

    private const DB_VERSION_OPTION = 'vyg_db_version';

    public function __construct(
        private readonly Migrator $migrator,
        private readonly Logger $logger,
    ) {}

    public function install(): void {
        require_once ABSPATH . 'wp-admin/includes/upgrade.php';

        $previous = get_option( self::DB_VERSION_OPTION, '0.0.0' );
        $current  = VYG_DB_VERSION;

        $this->logger->info( 'Running dbDelta on all vyg_* tables', array(
            'from_version' => $previous,
            'to_version'   => $current,
        ) );

        foreach ( Schema::all_create_statements() as $name => $sql ) {
            $result = dbDelta( $sql );
            $this->logger->info( 'dbDelta: ' . $name, array(
                'changes' => is_array( $result ) ? count( $result ) : 0,
            ) );
        }

        // Run post-schema data migrations (e.g. vyg_sources_draft → vyg_sources).
        $this->migrator->run( $previous, $current );

        update_option( self::DB_VERSION_OPTION, $current, false );
    }

    public function uninstall(): void {
        // Phase 6 will implement the data-removal path. Phase 2 just records intent.
        $this->logger->info( 'uninstall() called — Phase 2 stub (data preserved)' );
    }

    public function current_db_version(): string {
        return (string) get_option( self::DB_VERSION_OPTION, '0.0.0' );
    }
}