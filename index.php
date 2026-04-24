<?php
/**
 * Main Entry Point
 * Dental Clinic Management System
 */

require_once __DIR__ . '/config/config.php';

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
