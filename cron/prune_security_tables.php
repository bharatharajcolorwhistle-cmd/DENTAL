<?php
/**
 * CLI cron: prune security-related database tables (audit log, login attempts).
 *
 * Example (weekly cron):
 *   php C:\xampp\htdocs\dev_dental\cron\prune_security_tables.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/db_security.php';

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    fwrite(STDERR, "Database connection unavailable.\n");
    exit(1);
}

$logsDir = rtrim(dcmt_get_logs_path(), '/\\');
if (!is_dir($logsDir) && !mkdir($logsDir, 0750, true) && !is_dir($logsDir)) {
    fwrite(STDERR, "Unable to create logs directory: {$logsDir}\n");
    exit(1);
}

$auditRemoved = dcmt_db_prune_audit_log($dcmt_pdo);
$loginRemoved = dcmt_db_prune_login_attempts($dcmt_pdo, 30);

$message = sprintf(
    'Pruned %d audit log row(s) (retention %d days) and %d login attempt row(s) (retention 30 days).',
    $auditRemoved,
    dcmt_db_audit_retention_days(),
    $loginRemoved
);

$logFile = $logsDir . 'security_prune.log';
file_put_contents($logFile, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL, FILE_APPEND | LOCK_EX);

fwrite(STDOUT, date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL);
exit(0);
