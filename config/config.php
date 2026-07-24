<?php
/**
 * Application Configuration
 * Dental Clinic Management System
 */

// Set timezone to Mexico (UTC-6)
date_default_timezone_set('America/Mexico_City');

require_once __DIR__ . '/env.php';
dcmt_load_env_file(dirname(__DIR__) . DIRECTORY_SEPARATOR . '.env');

// Set session configuration BEFORE starting session
ini_set('session.gc_maxlifetime', 28800); // 8 hours
ini_set('session.cookie_lifetime', 28800); // 8 hours
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);
ini_set('session.cookie_httponly', 1);

// Application constants
define('DCMT_APP_NAME', 'Dental Clinic Management System');
define('DCMT_APP_VERSION', '1.0.0');
define('DCMT_APP_URL', rtrim((string) dcmt_env('DCMT_APP_URL', 'https://orthokidssmile.eduwhistle.com'), '/'));
define('DCMT_UPLOAD_PATH', __DIR__ . '/../uploads/');
define('DCMT_MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// Database configuration (single source — also used by config/database.php)
if (!defined('DCMT_DB_HOST')) {
    define('DCMT_DB_HOST', (string) dcmt_env('DCMT_DB_HOST', 'localhost'));
}
if (!defined('DCMT_DB_NAME')) {
    define('DCMT_DB_NAME', (string) dcmt_env('DCMT_DB_NAME', 'orthokidssmile-db'));
}
if (!defined('DCMT_DB_USER')) {
    define('DCMT_DB_USER', (string) dcmt_env('DCMT_DB_USER', 'orthokidssmile-db-user'));
}
if (!defined('DCMT_DB_PASS')) {
    define('DCMT_DB_PASS', (string) dcmt_env('DCMT_DB_PASS', 'WE7PCtr56oV3mZ19925Y'));
}

// Database backup directory (outside htdocs on CloudPanel). Leave empty to auto-detect.
// Example: define('DCMT_BACKUP_PATH', '/home/eduwhistle-orthokidssmile/backups/dental-database');
if (!defined('DCMT_BACKUP_PATH')) {
    define('DCMT_BACKUP_PATH', (string) dcmt_env('DCMT_BACKUP_PATH', ''));
}
define('DCMT_MESSAGING_RETENTION_DAYS', 7);

// Security configuration
define('DCMT_SESSION_LIFETIME', 28800); // 8 hours
define('DCMT_CSRF_TOKEN_LIFETIME', 3600); // 1 hour
define('DCMT_PASSWORD_MIN_LENGTH', 8);

// Pagination
if (!defined('DCMT_PER_PAGE')) {
    define('DCMT_PER_PAGE', 20);
}

// Import limits (to prevent site overload)
define('DCMT_MAX_IMPORT_RECORDS', 500); // Maximum records per import attempt

// Date format
define('DCMT_DATE_FORMAT', 'Y-m-d');
define('DCMT_DATETIME_FORMAT', 'Y-m-d H:i:s');

// Environment: set DCMT_ENV=production on live servers (or DCMT_DEBUG=0 locally).
$dcmt_env = strtolower(trim((string) (getenv('DCMT_ENV') ?: '')));
$dcmt_is_production = in_array($dcmt_env, ['production', 'prod'], true);
define('DCMT_IS_PRODUCTION', $dcmt_is_production);

if ($dcmt_is_production) {
    define('DCMT_DEBUG_MODE', false);
} else {
    $dcmt_debug_env = getenv('DCMT_DEBUG');
    define(
        'DCMT_DEBUG_MODE',
        $dcmt_debug_env === false
            ? true
            : filter_var($dcmt_debug_env, FILTER_VALIDATE_BOOLEAN)
    );
}

if (DCMT_DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(E_ALL);
    ini_set('display_errors', 0);
    ini_set('log_errors', 1);
}

if ($dcmt_is_production && DCMT_DEBUG_MODE) {
    error_log('SECURITY WARNING: DCMT_DEBUG_MODE is enabled while DCMT_ENV=production.');
}

if (!function_exists('dcmt_get_logs_path')) {
    /**
     * Resolve the writable logs directory (prefer outside htdocs in production).
     */
    function dcmt_get_logs_path(): string
    {
        static $resolved = null;
        if ($resolved !== null) {
            return $resolved;
        }

        $configured = trim((string) dcmt_env('DCMT_LOGS_PATH', ''));
        if ($configured !== '') {
            $resolved = rtrim($configured, '/\\') . DIRECTORY_SEPARATOR;
            return $resolved;
        }

        $root = dirname(__DIR__);
        if (defined('DCMT_IS_PRODUCTION') && DCMT_IS_PRODUCTION) {
            $htdocsDir = dirname($root);
            if (basename($htdocsDir) === 'htdocs') {
                $resolved = dirname($htdocsDir) . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR . 'dental' . DIRECTORY_SEPARATOR;
                return $resolved;
            }
        }

        $resolved = $root . DIRECTORY_SEPARATOR . 'logs' . DIRECTORY_SEPARATOR;
        return $resolved;
    }
}

if (!function_exists('dcmt_ensure_logs_directory')) {
    function dcmt_ensure_logs_directory(): void
    {
        $logsDir = dcmt_get_logs_path();
        if (!is_dir($logsDir) && !mkdir($logsDir, 0750, true) && !is_dir($logsDir)) {
            return;
        }

        $htaccess = $logsDir . '.htaccess';
        if (!is_file($htaccess)) {
            file_put_contents($htaccess, "Require all denied\n");
        }
    }
}

dcmt_ensure_logs_directory();

if (!DCMT_DEBUG_MODE) {
    ini_set('error_log', dcmt_get_logs_path() . 'php_errors.log');
}

if (PHP_SAPI !== 'cli' && DCMT_IS_PRODUCTION && !dcmt_request_is_https()) {
    $host = trim((string) ($_SERVER['HTTP_HOST'] ?? ''));
    $uri = (string) ($_SERVER['REQUEST_URI'] ?? '/');
    if ($host !== '') {
        header('Location: https://' . $host . $uri, true, 301);
        exit;
    }
}

if (PHP_SAPI !== 'cli' && session_status() === PHP_SESSION_NONE) {
    $dcmt_cookie_path = '/';
    $dcmt_app_url_parts = parse_url(DCMT_APP_URL);
    if (is_array($dcmt_app_url_parts) && !empty($dcmt_app_url_parts['path'])) {
        $dcmt_cookie_path = rtrim($dcmt_app_url_parts['path'], '/') . '/';
    }

    $dcmt_is_https = dcmt_request_is_https();

    if ($dcmt_is_https) {
        ini_set('session.cookie_secure', 1);
    }

    session_set_cookie_params([
        'lifetime' => 28800,
        'path' => $dcmt_cookie_path,
        'secure' => $dcmt_is_https,
        'httponly' => true,
        'samesite' => 'Lax'
    ]);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

require_once __DIR__ . '/../includes/audit_functions.php';
require_once __DIR__ . '/../includes/login_rate_limit.php';
require_once __DIR__ . '/../includes/upload_validation.php';

if (!function_exists('dcmt_send_security_headers')) {
    function dcmt_send_security_headers(): void
    {
        if (headers_sent()) {
            return;
        }
        header('X-Frame-Options: SAMEORIGIN');
        header('X-Content-Type-Options: nosniff');
        header('Referrer-Policy: strict-origin-when-cross-origin');
        header('Permissions-Policy: geolocation=(), microphone=(), camera=()');
        if (function_exists('dcmt_request_is_https') && dcmt_request_is_https()) {
            header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
        }
        $csp = [
            "default-src 'self'",
            "script-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://code.jquery.com",
            "style-src 'self' 'unsafe-inline' https://cdn.jsdelivr.net https://cdnjs.cloudflare.com https://fonts.googleapis.com",
            "img-src 'self' data: blob:",
            "font-src 'self' data: https://cdnjs.cloudflare.com https://fonts.gstatic.com",
            "connect-src 'self'",
            "frame-ancestors 'self'",
        ];
        header('Content-Security-Policy: ' . implode('; ', $csp));
    }
}

if (PHP_SAPI !== 'cli') {
    dcmt_send_security_headers();
}

// Helper functions

/**
 * Build a local CSS/JS URL with automatic cache-busting.
 * Uses the file's last-modified time so browsers fetch a fresh copy after you
 * deploy an update — clients do not need a hard refresh.
 *
 * @param string $path Path from project root, e.g. 'assets/css/main.css'
 * @param string $base_path URL prefix for the current page, e.g. '../../' or $base_path
 */
function dcmt_asset(string $path, string $base_path = ''): string {
    $path = ltrim(str_replace('\\', '/', $path), '/');
    $fs_path = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, $path);
    $version = is_file($fs_path)
        ? (string) filemtime($fs_path)
        : (defined('DCMT_APP_VERSION') ? (string) DCMT_APP_VERSION : '1');
    return $base_path . $path . '?v=' . rawurlencode($version);
}

function dcmt_redirect($url) {
    header("Location: $url");
    exit();
}

function dcmt_get_current_user() {
    if (!isset($_SESSION['dcmt_user']) || empty($_SESSION['dcmt_user']) || !is_array($_SESSION['dcmt_user'])) {
        return null;
    }
    return $_SESSION['dcmt_user'];
}

function dcmt_is_logged_in() {
    return isset($_SESSION['dcmt_user']) && !empty($_SESSION['dcmt_user']);
}

// Enhanced session validation with timeout checking
function dcmt_validate_session() {
    // Check if user is logged in
    if (!dcmt_is_logged_in()) {
        return false;
    }
    
    // Check if session data is valid
    $user = dcmt_get_current_user();
    if (!$user || !isset($user['dcmt_id']) || !isset($user['dcmt_username'])) {
        return false;
    }
    
    // Check session timeout with grace period
    if (isset($_SESSION['dcmt_last_activity'])) {
        $timeout = DCMT_SESSION_LIFETIME;
        $time_since_activity = time() - $_SESSION['dcmt_last_activity'];
        
        // Add 5-minute grace period to prevent premature expiration
        if ($time_since_activity > ($timeout + 300)) {
            // Session expired, destroy it
            session_destroy();
            $_SESSION = array();
            return false;
        }
    } else {
        // If no last activity time, set it now
        $_SESSION['dcmt_last_activity'] = time();
    }
    
    // Update last activity time only if it's been more than 5 minutes
    // This prevents excessive session updates
    if (!isset($_SESSION['dcmt_last_activity']) || 
        (time() - $_SESSION['dcmt_last_activity']) > 300) {
        $_SESSION['dcmt_last_activity'] = time();
    }
    
    return true;
}

function dcmt_is_admin() {
    $user = dcmt_get_current_user();
    if (!$user) {
        return false;
    }
    if (($user['dcmt_role'] ?? '') === 'admin') {
        return true;
    }
    if (($user['dcmt_role'] ?? '') === 'doctor' && !function_exists('dcmt_get_owner_doctor_user_ids')) {
        $owner_helper = __DIR__ . '/../includes/dcmt_owner_doctor.php';
        if (file_exists($owner_helper)) {
            require_once $owner_helper;
        }
    }
    if (($user['dcmt_role'] ?? '') === 'doctor' && function_exists('dcmt_get_owner_doctor_user_ids')) {
        $uid = (int) ($user['dcmt_id'] ?? 0);
        return $uid > 0 && in_array($uid, dcmt_get_owner_doctor_user_ids(), true);
    }
    return false;
}

// Function to check if user is admin or doctor
function dcmt_is_admin_or_doctor() {
    $user = dcmt_get_current_user();
    return $user && ($user['dcmt_role'] === 'admin' || $user['dcmt_role'] === 'doctor');
}

require_once __DIR__ . '/../includes/permission_functions.php';

// Function to extend session lifetime
function dcmt_extend_session() {
    if (dcmt_is_logged_in()) {
        $_SESSION['dcmt_last_activity'] = time();
        // Regenerate session ID periodically for security
        if (!isset($_SESSION['dcmt_session_regenerated']) || 
            (time() - $_SESSION['dcmt_session_regenerated']) > 1800) { // 30 minutes
            session_regenerate_id(true);
            $_SESSION['dcmt_session_regenerated'] = time();
        }
    }
}

function dcmt_generate_csrf_token() {
    $token_time = $_SESSION['dcmt_csrf_token_time'] ?? 0;
    if (!isset($_SESSION['dcmt_csrf_token'])
        || ($token_time > 0 && (time() - $token_time) > DCMT_CSRF_TOKEN_LIFETIME)) {
        $_SESSION['dcmt_csrf_token'] = bin2hex(random_bytes(32));
        $_SESSION['dcmt_csrf_token_time'] = time();
    }
    return $_SESSION['dcmt_csrf_token'];
}

function dcmt_verify_csrf_token($token) {
    return isset($_SESSION['dcmt_csrf_token']) && 
           hash_equals($_SESSION['dcmt_csrf_token'], $token);
}

function dcmt_sanitize_input($input) {
    if (is_array($input)) {
        return array_map('dcmt_sanitize_input', $input);
    }
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

if (!function_exists('dcmt_normalize_patient_name_for_compare')) {
    function dcmt_normalize_patient_name_for_compare($name) {
        $name = trim((string) $name);
        if ($name === '') {
            return '';
        }
        $name = preg_replace('/\s+/', ' ', $name);
        if (function_exists('mb_strtolower')) {
            return mb_strtolower($name, 'UTF-8');
        }
        return strtolower($name);
    }
}

if (!function_exists('dcmt_phone_digits_for_compare')) {
    function dcmt_phone_digits_for_compare($phone) {
        $digits = preg_replace('/\D+/', '', (string) $phone);
        if (strncmp($digits, '00', 2) === 0) {
            $digits = substr($digits, 2);
        }
        return $digits;
    }
}

if (!function_exists('dcmt_phone_digit_variants_for_compare')) {
    function dcmt_phone_digit_variants_for_compare($phone) {
        $digits = dcmt_phone_digits_for_compare($phone);
        if ($digits === '') {
            return [];
        }

        $variants = [$digits];

        if (strlen($digits) > 10) {
            $variants[] = substr($digits, -10);
        }

        if (strncmp($digits, '52', 2) === 0 && strlen($digits) > 2) {
            $variants[] = substr($digits, 2);
        } elseif (strlen($digits) === 10) {
            $variants[] = '52' . $digits;
        }

        $variants = array_values(array_unique(array_filter($variants, fn($v) => $v !== '')));
        return array_slice($variants, 0, 4);
    }
}

if (!function_exists('dcmt_find_patient_by_name_and_phone')) {
    function dcmt_find_patient_by_name_and_phone(PDO $pdo, $patient_name, $phone, $exclude_patient_id = null) {
        $patient_name = trim((string) $patient_name);
        if ($patient_name === '') {
            return null;
        }

        $phone_variants = dcmt_phone_digit_variants_for_compare($phone);
        if (empty($phone_variants)) {
            return null;
        }

        $phone_placeholders = implode(',', array_fill(0, count($phone_variants), '?'));
        $phone_expr = "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(dcmt_phone, ' ', ''), '-', ''), '(', ''), ')', ''), '+', ''), '.', '')";

        $sql = "
            SELECT dcmt_id, dcmt_patient_name, dcmt_first_name, dcmt_fathers_last_name, dcmt_mothers_last_name, dcmt_gender, dcmt_phone, dcmt_email
            FROM dcmt_patients
            WHERE LOWER(TRIM(dcmt_patient_name)) COLLATE utf8mb4_unicode_ci
                  = LOWER(TRIM(CONVERT(? USING utf8mb4))) COLLATE utf8mb4_unicode_ci
              AND {$phone_expr} IN ({$phone_placeholders})
        ";

        $params = array_merge([$patient_name], $phone_variants);
        if ($exclude_patient_id !== null) {
            $sql .= " AND dcmt_id <> ? ";
            $params[] = (int) $exclude_patient_id;
        }
        $sql .= " LIMIT 1";

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

function dcmt_validate_email($email) {
    return filter_var($email, FILTER_VALIDATE_EMAIL);
}

// Updated currency function to use database setting
function dcmt_format_currency($amount, $currency = null) {
    // If no currency specified, get from database settings
    if ($currency === null) {
        $currency = dcmt_get_site_setting('currency_type', 'USD');
    }
    
    return number_format($amount, 2) . ' ' . $currency;
}

// Function to get current currency from settings
function dcmt_get_current_currency() {
    return dcmt_get_site_setting('currency_type', 'USD');
}

function dcmt_format_date($date, $format = DCMT_DATE_FORMAT) {
    if (empty($date)) return '';
    $timestamp = strtotime($date);
    return $timestamp ? date($format, $timestamp) : '';
}

function dcmt_get_month_name($month) {
    $months = [
        1 => 'January', 2 => 'February', 3 => 'March', 4 => 'April',
        5 => 'May', 6 => 'June', 7 => 'July', 8 => 'August',
        9 => 'September', 10 => 'October', 11 => 'November', 12 => 'December'
    ];
    return $months[(int)$month] ?? '';
}

function dcmt_get_current_month() {
    return date('n'); // Current month number (1-12)
}

function dcmt_get_current_year() {
    return date('Y'); // Current year
}

// Helper function to get current date in Mexico timezone
function dcmt_get_current_date($format = DCMT_DATE_FORMAT) {
    return date($format);
}

// Helper function to get current datetime in Mexico timezone
function dcmt_get_current_datetime($format = DCMT_DATETIME_FORMAT) {
    return date($format);
}

function dcmt_log_activity($action, $details = '') {
    try {
        $user = dcmt_get_current_user();
        $userId = 0;
        $username = 'Unknown';
        
        if ($user && is_array($user)) {
            $userId = isset($user['dcmt_id']) ? $user['dcmt_id'] : 0;
            $username = isset($user['dcmt_username']) ? $user['dcmt_username'] : 'Unknown';
        }
        
        $logEntry = date('Y-m-d H:i:s') . " - User: $username (ID: $userId) - Action: $action";
        if ($details) {
            $logEntry .= " - Details: $details";
        }
        
        // Ensure logs directory exists
        $logsDir = dcmt_get_logs_path();
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0750, true);
        }
        
        // Write to log file
        error_log($logEntry . PHP_EOL, 3, $logsDir . 'activity.log');
        
        // Also store in database
        try {
            // Try to get the global database connection
            if (isset($GLOBALS['dcmt_pdo']) && $GLOBALS['dcmt_pdo']) {
                $stmt = $GLOBALS['dcmt_pdo']->prepare("INSERT INTO dcmt_activity_log (dcmt_user, dcmt_activity, dcmt_details, dcmt_created_at) VALUES (?, ?, ?, NOW())");
                $stmt->execute([$username, $action, $details]);
            }
        } catch (Exception $db_e) {
            // If database logging fails, continue with file logging
            error_log("Database activity logging failed: " . $db_e->getMessage());
        }
        
    } catch (Exception $e) {
        // If logging fails, don't break the application
        error_log("Activity logging failed: " . $e->getMessage());
    }
}

function dcmt_show_message($message, $type = 'info') {
    $_SESSION['dcmt_message'] = [
        'text' => $message,
        'type' => $type
    ];
}

function dcmt_get_message() {
    if (isset($_SESSION['dcmt_message'])) {
        $message = $_SESSION['dcmt_message'];
        unset($_SESSION['dcmt_message']);
        return $message;
    }
    return null;
}

function dcmt_validate_required_fields($data, $required_fields) {
    $errors = [];
    foreach ($required_fields as $field) {
        if (empty($data[$field])) {
            $errors[] = ucfirst(str_replace('_', ' ', $field)) . ' is required';
        }
    }
    return [
        'valid' => empty($errors),
        'errors' => $errors
    ];
}

function dcmt_validate_numeric_field($value, $field_name, $min = null, $max = null) {
    if (!is_numeric($value)) {
        return ucfirst(str_replace('_', ' ', $field_name)) . ' must be a number';
    }
    
    if ($min !== null && $value < $min) {
        return ucfirst(str_replace('_', ' ', $field_name)) . " must be at least $min";
    }
    
    if ($max !== null && $value > $max) {
        return ucfirst(str_replace('_', ' ', $field_name)) . " must not exceed $max";
    }
    
    return null;
}

function dcmt_validate_date_field($value, $field_name) {
    $timestamp = strtotime($value);
    if (!$timestamp) {
        return ucfirst(str_replace('_', ' ', $field_name)) . ' must be a valid date';
    }
    
    $date = date('Y-m-d', $timestamp);
    if ($date !== $value) {
        return ucfirst(str_replace('_', ' ', $field_name)) . ' must be in YYYY-MM-DD format';
    }
    
    return null;
}

// Function to get site settings
function dcmt_get_site_setting($key, $default = '') {
    global $dcmt_pdo;
    
    // Check if database connection is available
    if (!isset($dcmt_pdo) || $dcmt_pdo === null) {
        return $default;
    }
    
    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = ?");
        $stmt->execute([$key]);
        $result = $stmt->fetch();
        
        return $result ? $result['dcmt_setting_value'] : $default;
    } catch (PDOException $e) {
        error_log("Error fetching site setting '$key': " . $e->getMessage());
        return $default;
    }
}

// Function to get site name
function dcmt_get_site_name() {
    return dcmt_get_site_setting('site_name', DCMT_APP_NAME);
}

// Function to get logo path
function dcmt_get_logo_path() {
    return dcmt_get_site_setting('logo_path', '');
}

// Function to get site description (header tagline, max 30 characters).
// Returns empty string when not set in General Settings (no default fallback).
function dcmt_get_site_description() {
    $value = trim((string) dcmt_get_site_setting('site_description', ''));
    if ($value === '') {
        return '';
    }

    if (function_exists('mb_substr')) {
        return mb_substr($value, 0, 30);
    }

    return substr($value, 0, 30);
}



// Create uploads directory if it doesn't exist
if (!is_dir(DCMT_UPLOAD_PATH)) {
    mkdir(DCMT_UPLOAD_PATH, 0755, true);
}

// Language Support Functions
function dcmt_get_language() {
    global $dcmt_pdo;
    
    // Check if database connection is available
    if (!isset($dcmt_pdo) || $dcmt_pdo === null) {
        return 'es'; // Default language
    }
    
    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'language'");
        $stmt->execute();
        $result = $stmt->fetch();
        
        $language = $result ? $result['dcmt_setting_value'] : 'es';
        
        // Validate language code
        if (!in_array($language, ['en', 'es'])) {
            $language = 'es'; // Default to Spanish
        }
        
        return $language;
    } catch (PDOException $e) {
        error_log("Error fetching language setting: " . $e->getMessage());
        return 'es'; // Default to Spanish on error
    }
}

// Translation helper function
function trans($module, $key, $default = null) {
    static $translations = [];
    static $current_lang = null;
    
    // Get current language from database (always fresh)
    $lang = dcmt_get_language();
    
    // Clear translations cache if language changed
    if ($current_lang !== $lang) {
        $translations = [];
        $current_lang = $lang;
    }
    
    if (!isset($translations[$module])) {
        $file = __DIR__ . "/../lang/" . $lang . "/{$module}.php";
        $translations[$module] = file_exists($file) ? include $file : [];
    }
    
    // Return translation if exists, otherwise return key or default
    if (isset($translations[$module][$key])) {
        return $translations[$module][$key];
    }
    
    // If no translation found, try fallback to Spanish
    if ($lang !== 'es') {
        $fallback_file = __DIR__ . "/../lang/es/{$module}.php";
        if (file_exists($fallback_file)) {
            $fallback_translations = include $fallback_file;
            if (isset($fallback_translations[$key])) {
                return $fallback_translations[$key];
            }
        }
    }
    
    // Return default value or key itself
    return $default !== null ? $default : $key;
}

// Backward compatibility - define APP_LANG for any code that still uses it
// Only define if database is available
if (!defined('APP_LANG') && isset($dcmt_pdo) && $dcmt_pdo !== null) {
    define('APP_LANG', dcmt_get_language());
} elseif (!defined('APP_LANG')) {
    define('APP_LANG', 'es'); // Default fallback
}

// Helper function to get available languages
function dcmt_get_available_languages() {
    return [
        'es' => 'Español',
        'en' => 'English'
    ];
}

// Helper function to check if user has any activity statistics records
function dcmt_user_has_activity_records($user_id) {
    global $dcmt_pdo;
    
    if (!$dcmt_pdo) {
        return false;
    }
    
    try {
        // Get user's username for checking activity log
        $user_stmt = $dcmt_pdo->prepare("SELECT dcmt_username FROM dcmt_users WHERE dcmt_id = ?");
        $user_stmt->execute([$user_id]);
        $user_data = $user_stmt->fetch();
        
        if (!$user_data) {
            return false;
        }
        
        $username = $user_data['dcmt_username'];
        
        // Check if user has records in any of these tables:
        $tables_to_check = [
            'dcmt_activity_log' => 'dcmt_user',
            'dcmt_income' => 'dcmt_created_by',
            'dcmt_expenses' => 'dcmt_created_by',
            'dcmt_expense_categories' => 'dcmt_created_by',
            'dcmt_inventory' => 'dcmt_created_by',
            'dcmt_inventory_categories' => 'dcmt_created_by',
            'dcmt_income_payment_methods' => 'dcmt_created_by',
            'dcmt_expense_payment_methods' => 'dcmt_created_by',
            'dcmt_income_payment_status' => 'dcmt_created_by',
            'dcmt_services' => 'dcmt_created_by',
            'dcmt_settings' => 'dcmt_created_by',
            'dcmt_doctor_services' => 'dcmt_created_by'
        ];
        
        foreach ($tables_to_check as $table => $column) {
            $sql = "SELECT COUNT(*) FROM $table WHERE $column = ?";
            $stmt = $dcmt_pdo->prepare($sql);
            $stmt->execute([$username]);
            $count = $stmt->fetchColumn();
            
            if ($count > 0) {
                return true;
            }
        }
        
        return false;
        
    } catch (PDOException $e) {
        error_log("Error checking user activity records: " . $e->getMessage());
        return false;
    }
}
?>
