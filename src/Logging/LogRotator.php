<?php
/**
 * Log rotation + log file management.
 *
 * Phase 12.5 hardens the file-based logger with size-based rotation
 * and an explicit retention policy. The rotator runs:
 *   - On every cron `vyg_cron_log_rotation` event (daily by default).
 *   - On demand via `wp vyg log rotate`.
 *
 * Rotation policy:
 *   - If the active log file is bigger than `log_max_size_mb`, the
 *     file is renamed to `<name>.1.log`, the previous `.1.log` (if
 *     any) to `.2.log`, etc. up to `log_max_files`. Anything older
 *     than the configured max is deleted.
 *   - If the file does not exist yet, rotation is a no-op.
 *
 * The rotator never modifies the active log file; it only renames /
 * deletes the prior segments. WP_DEBUG_LOG (the file WP writes to
 * when WP_DEBUG_LOG is set) is left intact — we are only managing
 * the segments we own.
 *
 * @package VectorYT\Gallery\Logging
 */

declare(strict_types=1);

namespace VectorYT\Gallery\Logging;

use VectorYT\Gallery\Settings\SettingsRepository;

defined('ABSPATH') || exit;

class LogRotator
{
    public const DEFAULT_MAX_SIZE_MB = 5;
    public const DEFAULT_MAX_FILES = 5;
    public const MAX_SIZE_MB_CEILING = 100;
    public const MAX_FILES_CEILING = 50;

    public function __construct(
        private readonly SettingsRepository $settings,
        /** Absolute path to the active log file. */
        private readonly string $log_file_path,
        /** Absolute path to the directory where segments are stored. */
        private readonly string $segments_dir,
    ) {}

    /**
     * Run a rotation pass. Returns the number of segments deleted
     * (0 if no rotation was needed). Safe to call repeatedly; the
     * underlying rename is a no-op when the file is below the size
     * threshold.
     */
    public function rotate(): int
    {
        $max_size = $this->max_size_bytes();
        if (!is_file($this->log_file_path)) {
            return 0;
        }
        clearstatcache(true, $this->log_file_path);
        $size = (int) @filesize($this->log_file_path);
        if ($size < $max_size) {
            return 0;
        }

        $max_files = $this->max_files();
        if ($max_files < 1) {
            // Operator disabled retention; the active file just
            // keeps growing. We do not delete it.
            return 0;
        }

        if (!is_dir($this->segments_dir)) {
            wp_mkdir_p($this->segments_dir);
        }

        // Drop the oldest segment first (max_files + 1 → max_files).
        $oldest = $this->segment_path($max_files);
        if (is_file($oldest)) {
            @unlink($oldest);
        }

        // Shift every segment N → N+1, in reverse, so we do not
        // overwrite a newer file with an older one.
        for ($i = $max_files - 1; $i >= 1; $i--) {
            $from = $this->segment_path($i);
            $to   = $this->segment_path($i + 1);
            if (is_file($from)) {
                @rename($from, $to);
            }
        }

        // The active log file becomes the .1.log segment.
        $first = $this->segment_path(1);
        @rename($this->log_file_path, $first);

        // Recreate the active log file as a fresh empty file.
        $handle = @fopen($this->log_file_path, 'wb');
        if ($handle) {
            fclose($handle);
        }

        return 1; // We rotated exactly one file.
    }

    public function segments(): array
    {
        if (!is_dir($this->segments_dir)) {
            return array();
        }
        $out = array();
        $handle = opendir($this->segments_dir);
        if ($handle) {
            while (($entry = readdir($handle)) !== false) {
                if ('.' === $entry || '..' === $entry) {
                    continue;
                }
                $path = $this->segments_dir . DIRECTORY_SEPARATOR . $entry;
                if (!is_file($path)) {
                    continue;
                }
                $out[] = array(
                    'name' => $entry,
                    'path' => $path,
                    'size' => (int) @filesize($path),
                    'mtime' => (int) @filemtime($path),
                );
            }
            closedir($handle);
        }
        return $out;
    }

    public function max_size_bytes(): int
    {
        $mb = (int) $this->settings->get('log_max_size_mb', self::DEFAULT_MAX_SIZE_MB);
        $mb = max(1, min(self::MAX_SIZE_MB_CEILING, $mb));
        return $mb * 1024 * 1024;
    }

    public function max_files(): int
    {
        $n = (int) $this->settings->get('log_max_files', self::DEFAULT_MAX_FILES);
        return max(0, min(self::MAX_FILES_CEILING, $n));
    }

    public function log_file_path(): string
    {
        return $this->log_file_path;
    }

    public function segments_dir(): string
    {
        return $this->segments_dir;
    }

    private function segment_path(int $n): string
    {
        return $this->segments_dir . DIRECTORY_SEPARATOR . 'debug.' . $n . '.log';
    }
}
