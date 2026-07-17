<?php
/**
 * Main Entry Point
 * Dental Clinic Management System
 */

require_once __DIR__ . '/config/config.php';

// API router: server catch-all rewrites can land extensionless API URLs here.
// Dispatch them to their handler BEFORE any auth redirect, otherwise external
// callers (e.g. the lab webhook) receive the login page instead of JSON.
$dcmt_request_path = (string) parse_url((string) ($_SERVER['REQUEST_URI'] ?? ''), PHP_URL_PATH);
if (preg_match('#/api/integration/notifications/?$#', $dcmt_request_path)) {
    require __DIR__ . '/api/integration/notifications.php';
    exit();
}

// Redirect to appropriate page based on authentication status
if (dcmt_is_logged_in()) {
    $dcmt_user = dcmt_get_current_user();
    $dcmt_role = (string)($dcmt_user['dcmt_role'] ?? '');
    if (in_array($dcmt_role, ['staff', 'assistant'], true)) {
        dcmt_redirect('pages/dashboard/index.php?tab=appointment');
    } else {
        dcmt_redirect('pages/dashboard/');
    }
} else {
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
}
?>
