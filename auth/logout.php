<?php
/**
 * Logout Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../config/config.php';

// Log the logout activity
if (dcmt_is_logged_in()) {
    $user = dcmt_get_current_user();
            dcmt_log_activity('User logged out', "User ID: {$user['dcmt_id']}");
}

// Destroy session
session_destroy();

// Clear all session variables
$_SESSION = array();

// Delete session cookie
if (ini_get("session.use_cookies")) {
    $params = session_get_cookie_params();
    setcookie(session_name(), '', time() - 42000,
        $params["path"], $params["domain"],
        $params["secure"], $params["httponly"]
    );
}

// Redirect to login page
dcmt_show_message('You have been successfully logged out', 'success');
dcmt_redirect('login.php');
?>
