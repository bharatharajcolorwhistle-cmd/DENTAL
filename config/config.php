<?php
/**
 * Application Configuration
 * Dental Clinic Management System
 */

// Set timezone to Mexico (UTC-6)
date_default_timezone_set('America/Mexico_City');

// Set session configuration BEFORE starting session
ini_set('session.gc_maxlifetime', 28800); // 8 hours
ini_set('session.cookie_lifetime', 28800); // 8 hours
ini_set('session.gc_probability', 1);
ini_set('session.gc_divisor', 100);

// Application constants
define('DCMT_APP_NAME', 'Dental Clinic Management System');
define('DCMT_APP_VERSION', '1.0.0');
define('DCMT_APP_URL', 'http://localhost/dev_dental');
define('DCMT_UPLOAD_PATH', __DIR__ . '/../uploads/');
define('DCMT_MAX_UPLOAD_SIZE', 5 * 1024 * 1024); // 5MB

// Database configuration
define('DCMT_DB_HOST', 'localhost');
define('DCMT_DB_NAME', 'u373759666_demo_dental');
define('DCMT_DB_USER', 'root');
define('DCMT_DB_PASS', '');

// Security configuration
define('DCMT_SESSION_LIFETIME', 28800); // 8 hours
define('DCMT_CSRF_TOKEN_LIFETIME', 3600); // 1 hour
define('DCMT_PASSWORD_MIN_LENGTH', 8);

// Pagination
define('DCMT_PER_PAGE', 20);

// Import limits (to prevent site overload)
define('DCMT_MAX_IMPORT_RECORDS', 500); // Maximum records per import attempt

// Date format
define('DCMT_DATE_FORMAT', 'Y-m-d');
define('DCMT_DATETIME_FORMAT', 'Y-m-d H:i:s');

// Error reporting (set to false in production)
define('DCMT_DEBUG_MODE', true);

if (DCMT_DEBUG_MODE) {
    error_reporting(E_ALL);
    ini_set('display_errors', 1);
} else {
    error_reporting(0);
    ini_set('display_errors', 0);
}

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

// Helper functions
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
    return $user && $user['dcmt_role'] === 'admin';
}

// Function to check if user is admin or doctor
function dcmt_is_admin_or_doctor() {
    $user = dcmt_get_current_user();
    return $user && ($user['dcmt_role'] === 'admin' || $user['dcmt_role'] === 'doctor');
}

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
    if (!isset($_SESSION['dcmt_csrf_token']) || 
        time() - $_SESSION['dcmt_csrf_token_time'] > DCMT_CSRF_TOKEN_LIFETIME) {
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
        $logsDir = __DIR__ . '/../logs/';
        if (!is_dir($logsDir)) {
            mkdir($logsDir, 0755, true);
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



// Create uploads directory if it doesn't exist
if (!is_dir(DCMT_UPLOAD_PATH)) {
    mkdir(DCMT_UPLOAD_PATH, 0755, true);
}

// Create logs directory if it doesn't exist
$logsDir = __DIR__ . '/../logs/';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
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
