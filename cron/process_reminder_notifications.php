<?php
/**
 * CLI cron: process reminder advance notifications (2 hours before scheduled time)
 *
 * Example (Windows Task Scheduler / cron every 5 minutes):
 *   php C:\xampp\htdocs\dev_dental\cron\process_reminder_notifications.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "This script must be run from the command line.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/reminder_functions.php';

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

try {
    $processed = dcmt_reminder_process_due_notifications($dcmt_pdo);
    fwrite(STDOUT, date('Y-m-d H:i:s') . " - Marked {$processed} reminder notification(s) as sent.\n");
    exit(0);
} catch (Throwable $e) {
    fwrite(STDERR, 'Reminder cron failed: ' . $e->getMessage() . "\n");
    exit(1);
}
