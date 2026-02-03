<?php
/**
 * Authentication Check
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message('Your session has expired. Please log in again.', 'warning');
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
    dcmt_show_message('Your account has been deactivated. Please contact administrator.', 'danger');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Log page access
dcmt_log_activity('Page accessed', $_SERVER['REQUEST_URI']);

// Function to check if user has admin access
function dcmt_require_admin() {
    if (!dcmt_is_admin()) {
        dcmt_show_message('Access denied. Admin privileges required.', 'danger');
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
