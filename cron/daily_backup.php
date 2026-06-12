<?php
/**
 * CLI cron: daily MySQL database backup
 *
 * Backups are stored outside htdocs when possible:
 *   /home/{site-user}/backups/dental-database/
 * Override with DCMT_BACKUP_PATH in config/config.php if needed.
 *
 * Example (Windows Task Scheduler / daily cron):
 *   php C:\xampp\htdocs\dev_dental\cron\daily_backup.php
 *
 * CloudPanel example (daily at 2:00 AM):
 *   /usr/bin/php8.5 /home/eduwhistle-orthokidssmile/htdocs/orthokidssmile.eduwhistle.com/DENTAL/cron/daily_backup.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit('Forbidden');
}

$root = dirname(__DIR__);
$configFile = $root . '/config/config.php';

if (!is_file($configFile)) {
    fwrite(STDERR, "Config file not found: {$configFile}\n");
    exit(1);
}

require_once $configFile;

$required = ['DCMT_DB_HOST', 'DCMT_DB_NAME', 'DCMT_DB_USER', 'DCMT_DB_PASS'];
foreach ($required as $constant) {
    if (!defined($constant)) {
        fwrite(STDERR, "Missing database constant: {$constant}\n");
        exit(1);
    }
}

$backupDir = dcmt_backup_resolve_directory($root);
$logsDir = $root . '/logs';
$retentionDays = 30;

if (!is_dir($backupDir) && !mkdir($backupDir, 0750, true) && !is_dir($backupDir)) {
    fwrite(STDERR, "Unable to create backup directory: {$backupDir}\n");
    exit(1);
}

if (!is_dir($logsDir) && !mkdir($logsDir, 0755, true) && !is_dir($logsDir)) {
    fwrite(STDERR, "Unable to create logs directory: {$logsDir}\n");
    exit(1);
}

if (dcmt_backup_is_inside_htdocs($backupDir)) {
    dcmt_backup_protect_directory($backupDir);
}

$mysqldump = dcmt_backup_find_mysqldump();
if ($mysqldump === null) {
    dcmt_backup_log($logsDir, 'ERROR: mysqldump binary not found.');
    fwrite(STDERR, "mysqldump binary not found.\n");
    exit(1);
}

$timestamp = date('Y-m-d_His');
$dbName = (string) DCMT_DB_NAME;
$safeDbName = preg_replace('/[^a-zA-Z0-9_-]/', '_', $dbName) ?: 'database';
$sqlFile = $backupDir . "/{$safeDbName}_{$timestamp}.sql";
$gzFile = $sqlFile . '.gz';

$credentialsFile = dcmt_backup_create_credentials_file(
    $backupDir,
    (string) DCMT_DB_HOST,
    (string) DCMT_DB_USER,
    (string) DCMT_DB_PASS
);

if ($credentialsFile === null) {
    dcmt_backup_log($logsDir, 'ERROR: Unable to create temporary credentials file.');
    fwrite(STDERR, "Unable to create temporary credentials file.\n");
    exit(1);
}

try {
    $command = sprintf(
        '%s --defaults-extra-file=%s --single-transaction --routines --triggers --events --set-gtid-purged=OFF --default-character-set=utf8mb4 %s > %s 2>&1',
        escapeshellarg($mysqldump),
        escapeshellarg($credentialsFile),
        escapeshellarg($dbName),
        escapeshellarg($sqlFile)
    );

    exec($command, $output, $exitCode);

    if ($exitCode !== 0 || !is_file($sqlFile) || filesize($sqlFile) === 0) {
        $message = 'Backup failed: ' . implode("\n", $output);
        dcmt_backup_log($logsDir, 'ERROR: ' . $message);
        dcmt_backup_cleanup_file($sqlFile);
        fwrite(STDERR, $message . "\n");
        exit(1);
    }

    $gzOk = dcmt_backup_gzip_file($sqlFile, $gzFile);
    dcmt_backup_cleanup_file($sqlFile);

    if (!$gzOk || !is_file($gzFile) || filesize($gzFile) === 0) {
        dcmt_backup_log($logsDir, 'ERROR: Failed to compress backup file.');
        dcmt_backup_cleanup_file($gzFile);
        fwrite(STDERR, "Failed to compress backup file.\n");
        exit(1);
    }

    $removed = dcmt_backup_prune_old_files($backupDir, $retentionDays);
    $sizeMb = round(filesize($gzFile) / 1024 / 1024, 2);
    $logMessage = sprintf(
        'Backup completed: %s (%.2f MB) at %s. Removed %d old backup(s).',
        basename($gzFile),
        $sizeMb,
        $backupDir,
        $removed
    );

    dcmt_backup_log($logsDir, $logMessage);
    fwrite(STDOUT, date('Y-m-d H:i:s') . ' - ' . $logMessage . "\n");
    exit(0);
} finally {
    dcmt_backup_cleanup_file($credentialsFile);
}

function dcmt_backup_resolve_directory(string $root): string
{
    if (defined('DCMT_BACKUP_PATH')) {
        $configuredPath = trim((string) DCMT_BACKUP_PATH);
        if ($configuredPath !== '') {
            return rtrim($configuredPath, '/\\');
        }
    }

    $htdocsDir = dirname(dirname($root));
    if (basename($htdocsDir) === 'htdocs') {
        $siteUserHome = dirname($htdocsDir);
        return $siteUserHome . '/backups/dental-database';
    }

    return $root . '/backups/database';
}

function dcmt_backup_is_inside_htdocs(string $directory): bool
{
    $normalized = str_replace('\\', '/', $directory);
    return strpos($normalized, '/htdocs/') !== false;
}

function dcmt_backup_find_mysqldump(): ?string
{
    $candidates = [
        '/usr/bin/mysqldump',
        '/usr/local/bin/mysqldump',
        '/bin/mysqldump',
    ];

    foreach ($candidates as $path) {
        if (is_executable($path)) {
            return $path;
        }
    }

    $which = trim((string) shell_exec('command -v mysqldump 2>/dev/null'));
    return $which !== '' && is_executable($which) ? $which : null;
}

function dcmt_backup_create_credentials_file(
    string $backupDir,
    string $host,
    string $user,
    string $password
): ?string {
    $file = $backupDir . '/.mysqldump-' . getmypid() . '.cnf';
    $content = "[client]\n"
        . 'host=' . $host . "\n"
        . 'user=' . $user . "\n"
        . 'password=' . $password . "\n";

    if (file_put_contents($file, $content, LOCK_EX) === false) {
        return null;
    }

    chmod($file, 0600);
    return $file;
}

function dcmt_backup_gzip_file(string $sourceFile, string $targetFile): bool
{
    $input = fopen($sourceFile, 'rb');
    if ($input === false) {
        return false;
    }

    $output = gzopen($targetFile, 'wb9');
    if ($output === false) {
        fclose($input);
        return false;
    }

    while (!feof($input)) {
        $chunk = fread($input, 1024 * 512);
        if ($chunk === false) {
            fclose($input);
            gzclose($output);
            return false;
        }

        if ($chunk !== '' && gzwrite($output, $chunk) === false) {
            fclose($input);
            gzclose($output);
            return false;
        }
    }

    fclose($input);
    gzclose($output);
    return true;
}

function dcmt_backup_prune_old_files(string $backupDir, int $retentionDays): int
{
    if ($retentionDays < 1) {
        return 0;
    }

    $cutoff = time() - ($retentionDays * 86400);
    $removed = 0;

    foreach (glob($backupDir . '/*.sql.gz') ?: [] as $file) {
        if (is_file($file) && filemtime($file) < $cutoff && unlink($file)) {
            $removed++;
        }
    }

    return $removed;
}

function dcmt_backup_protect_directory(string $directory): void
{
    $htaccess = $directory . '/.htaccess';
    if (!is_file($htaccess)) {
        file_put_contents($htaccess, "Require all denied\n");
    }
}

function dcmt_backup_cleanup_file(?string $file): void
{
    if ($file !== null && is_file($file)) {
        unlink($file);
    }
}

function dcmt_backup_log(string $logsDir, string $message): void
{
    $line = date('Y-m-d H:i:s') . ' - ' . $message . PHP_EOL;
    file_put_contents($logsDir . '/backup.log', $line, FILE_APPEND | LOCK_EX);
}
