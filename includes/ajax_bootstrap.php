<?php
/**
 * Standard bootstrap for JSON AJAX endpoints.
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/dcmt_owner_doctor.php';
require_once __DIR__ . '/role_path_restrictions.php';
require_once __DIR__ . '/patient_compliance.php';
require_once __DIR__ . '/password_policy.php';

header('Content-Type: application/json; charset=utf-8');

if (!dcmt_validate_session()) {
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit;
}

$dcmt_current_user = dcmt_get_current_user();

if (!$dcmt_current_user || ($dcmt_current_user['dcmt_status'] ?? '') !== 'active') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('login', 'account_deactivated')]);
    exit;
}

dcmt_enforce_role_path_restrictions(true);
dcmt_enforce_password_change(true);
