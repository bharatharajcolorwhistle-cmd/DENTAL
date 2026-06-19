<?php
/**
 * CLI cron: delete messaging chat messages older than 7 days
 *
 * Retains one week of messages to manage database size. Messages older than
 * the retention window are permanently removed when this job runs.
 *
 * Example (Windows Task Scheduler / daily cron):
 *   php C:\xampp\htdocs\dev_dental\cron\cleanup_messaging_messages.php
 *
 * CloudPanel example (daily at 3:00 AM):
 *   /usr/bin/php8.5 /home/user/htdocs/site/cron/cleanup_messaging_messages.php
 *
 * Optional override in config/config.php:
 *   define('DCMT_MESSAGING_RETENTION_DAYS', 7);
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/messaging_functions.php';

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$retentionDays = defined('DCMT_MESSAGING_RETENTION_DAYS') ? (int) DCMT_MESSAGING_RETENTION_DAYS : 7;
if ($retentionDays < 1) {
    $retentionDays = 7;
}

$logsDir = rtrim(dcmt_get_logs_path(), '/\\');
if (!is_dir($logsDir) && !mkdir($logsDir, 0750, true) && !is_dir($logsDir)) {
    fwrite(STDERR, "Unable to create logs directory: {$logsDir}\n");
    exit(1);
}

try {
    $result = dcmt_messaging_cleanup_old_messages($dcmt_pdo, $retentionDays);
    $message = sprintf(
        'Messaging cleanup: deleted %d message(s) older than %d day(s); updated %d conversation(s), %d participant read pointer(s), cleared %d reply reference(s).',
        $result['deleted'],
        $retentionDays,
        $result['conversations_updated'],
        $result['participants_updated'],
        $result['reply_refs_cleared']
    );
    $line = date('Y-m-d H:i:s') . ' - ' . $message;
    file_put_contents($logsDir . '/messaging_cleanup.log', $line . PHP_EOL, FILE_APPEND | LOCK_EX);
    fwrite(STDOUT, $line . "\n");
    exit(0);
} catch (Throwable $e) {
    $error = 'Messaging cleanup cron failed: ' . $e->getMessage();
    file_put_contents($logsDir . '/messaging_cleanup.log', date('Y-m-d H:i:s') . ' - ERROR: ' . $error . PHP_EOL, FILE_APPEND | LOCK_EX);
    fwrite(STDERR, $error . "\n");
    exit(1);
}
