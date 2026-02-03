<?php
/**
 * Main Entry Point
 * Dental Clinic Management System
 */

require_once __DIR__ . '/config/config.php';

// Redirect to appropriate page based on authentication status
if (dcmt_is_logged_in()) {
    dcmt_redirect('pages/dashboard/');
} else {
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
}
?>
