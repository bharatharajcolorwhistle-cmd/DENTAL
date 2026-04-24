<?php
/**
 * Authentication Check
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    // Use absolute URL for more reliable redirects
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Get current user data
$dcmt_current_user = dcmt_get_current_user();

// Check if user account is active
if ($dcmt_current_user['dcmt_status'] !== 'active') {
    session_destroy();
    dcmt_show_message(trans('login', 'account_deactivated'), 'danger');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Log page access
dcmt_log_activity('Page accessed', $_SERVER['REQUEST_URI']);

// Restrict assistant users to Patients and Appointments modules
if (($dcmt_current_user['dcmt_role'] ?? '') === 'assistant') {
    $dcmt_request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $dcmt_allowed_prefixes = [
        '/pages/patients/',
        '/pages/patient_notes/',
        '/pages/appointments/',
        '/pages/operatories/',
    ];
    $dcmt_has_allowed_access = false;
    foreach ($dcmt_allowed_prefixes as $dcmt_prefix) {
        if (strpos($dcmt_request_path, $dcmt_prefix) !== false) {
            $dcmt_has_allowed_access = true;
            break;
        }
    }

    if (!$dcmt_has_allowed_access) {
        dcmt_show_message('Access denied. Assistant can only access Patients and Appointments.', 'danger');
        dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment');
        exit();
    }
}

// Restrict doctor users from Inventory modules
if (($dcmt_current_user['dcmt_role'] ?? '') === 'doctor') {
    $dcmt_request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $dcmt_blocked_prefixes = [
        '/pages/expenses/',
        '/pages/expense_categories/',
        '/pages/expense_payment_methods/',
        '/pages/inventory/',
        '/pages/inventory_categories/',
    ];
    foreach ($dcmt_blocked_prefixes as $dcmt_prefix) {
        if (strpos($dcmt_request_path, $dcmt_prefix) !== false) {
            dcmt_show_message('Access denied. Doctors cannot access this module.', 'danger');
            $dashboard_url = DCMT_APP_URL . '/pages/dashboard/';
            dcmt_redirect($dashboard_url);
            exit();
        }
    }
}

// Function to check if user has admin access
function dcmt_require_admin() {
    if (!dcmt_is_admin()) {
        dcmt_show_message('Access denied. Admin privileges required.', 'danger');
        $dashboard_url = DCMT_APP_URL . '/pages/dashboard/';
        dcmt_redirect($dashboard_url);
        exit();
    }
}

function dcmt_require_admin_or_staff() {
    $user = dcmt_get_current_user();
    $role = $user['dcmt_role'] ?? '';
    if (!in_array($role, ['admin', 'staff'], true)) {
        dcmt_show_message('Access denied. Admin or Staff privileges required.', 'danger');
        $dashboard_url = DCMT_APP_URL . '/pages/dashboard/';
        dcmt_redirect($dashboard_url);
        exit();
    }
}

// Function to require admin or doctor access
function dcmt_require_admin_or_doctor() {
    if (!dcmt_is_admin_or_doctor()) {
        dcmt_show_message('Access denied. Admin or Doctor privileges required.', 'danger');
        $dashboard_url = DCMT_APP_URL . '/pages/dashboard/';
        dcmt_redirect($dashboard_url);
        exit();
    }
}

// Function to check if user can access specific resource
function dcmt_can_access_resource($resource_type, $resource_id = null) {
    $user = dcmt_get_current_user();
    
    // Admin can access everything
    if (dcmt_is_admin()) {
        return true;
    }
    
    // Staff can access resources they created
    if ($resource_id && $resource_type) {
        global $dcmt_pdo;
        
        try {
            $table_map = [
                'income' => 'dcmt_income',
                'expense' => 'dcmt_expenses',
                'inventory' => 'dcmt_inventory'
            ];
            
            if (isset($table_map[$resource_type])) {
                $table = $table_map[$resource_type];
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_created_by FROM $table WHERE dcmt_" . $resource_type . "_id = ?");
                $stmt->execute([$resource_id]);
                $result = $stmt->fetch();
                
                if ($result && $result['dcmt_created_by'] == $user['dcmt_username']) {
                    return true;
                }
            }
        } catch (PDOException $e) {
            error_log("Resource access check failed: " . $e->getMessage());
        }
    }
    
    return false;
}
?>
