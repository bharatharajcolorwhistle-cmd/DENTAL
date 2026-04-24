<?php
/**
 * Legacy URL: appointment "today" board now lives on the main dashboard (Appointment tab).
 */

require_once __DIR__ . '/../../config/config.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment');
exit();
