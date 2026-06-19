<?php
/**
 * Shared role-based path restrictions (HTML redirect or JSON response).
 */

if (!function_exists('dcmt_enforce_role_path_restrictions')) {
    function dcmt_enforce_role_path_restrictions(bool $json_response = false): void
    {
        $user = dcmt_get_current_user();
        if (!$user) {
            return;
        }

        $request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';

        if (($user['dcmt_role'] ?? '') === 'staff') {
            if (strpos($request_path, '/pages/users/') !== false) {
                if ($json_response) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Access denied. Staff cannot access user management.']);
                    exit;
                }
                dcmt_show_message('Access denied. Staff cannot access user management.', 'danger');
                dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment');
                exit;
            }
        }

        if (($user['dcmt_role'] ?? '') === 'assistant') {
            $allowed_prefixes = [
                '/pages/dashboard/',
                '/pages/patients/',
                '/pages/patient_notes/',
                '/pages/patient_odontogram/',
                '/pages/reminders/',
                '/pages/messaging/',
                '/pages/appointments/',
                '/pages/operatories/',
            ];
            $has_allowed = false;
            foreach ($allowed_prefixes as $prefix) {
                if (strpos($request_path, $prefix) !== false) {
                    $has_allowed = true;
                    break;
                }
            }
            if (!$has_allowed) {
                if ($json_response) {
                    http_response_code(403);
                    header('Content-Type: application/json; charset=utf-8');
                    echo json_encode(['success' => false, 'message' => 'Access denied.']);
                    exit;
                }
                dcmt_show_message('Access denied. Assistant can only access the Appointments Dashboard, Patients, and Appointments.', 'danger');
                dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment');
                exit;
            }
        }

        if (($user['dcmt_role'] ?? '') === 'doctor' && !dcmt_is_admin()) {
            $blocked_prefixes = [
                '/pages/expenses/',
                '/pages/expense_categories/',
                '/pages/expense_payment_methods/',
                '/pages/inventory/',
                '/pages/inventory_categories/',
            ];
            foreach ($blocked_prefixes as $prefix) {
                if (strpos($request_path, $prefix) !== false) {
                    if ($json_response) {
                        http_response_code(403);
                        header('Content-Type: application/json; charset=utf-8');
                        echo json_encode(['success' => false, 'message' => 'Access denied. Doctors cannot access this module.']);
                        exit;
                    }
                    dcmt_show_message('Access denied. Doctors cannot access this module.', 'danger');
                    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
                    exit;
                }
            }
        }
    }
}
