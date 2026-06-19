<?php
/**
 * AJAX — update odontogram problem state color
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/odontogram_treatments.php';

dcmt_require_admin_or_staff();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'invalid_request')]);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'invalid_token')]);
    exit();
}

$stateKey = dcmt_sanitize_input($_POST['state_key'] ?? '');
$color = dcmt_sanitize_input($_POST['color'] ?? '');

$allowed = array_fill_keys(dcmt_odontogram_problem_state_keys(), true);
if (!isset($allowed[$stateKey])) {
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'invalid_problem_state')]);
    exit();
}

try {
    dcmt_ensure_odontogram_problem_states_table($dcmt_pdo);
    if (dcmt_odontogram_problem_state_is_in_use($dcmt_pdo, $stateKey)) {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'color_locked_in_use')]);
        exit();
    }
    if (!dcmt_update_odontogram_problem_state_color($dcmt_pdo, $stateKey, $color)) {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'database_error')]);
        exit();
    }
    $map = dcmt_odontogram_problem_states_map($dcmt_pdo);
    $entry = $map[$stateKey] ?? null;
    echo json_encode([
        'success' => true,
        'message' => trans('odontogram_treatment', 'problem_state_color_updated'),
        'state_key' => $stateKey,
        'color' => $entry['fill'] ?? dcmt_sanitize_odontogram_hex_color($color),
        'stroke' => $entry['stroke'] ?? dcmt_odontogram_derive_stroke_color($color),
    ]);
} catch (PDOException $e) {
    error_log('Odontogram problem state color update: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'database_error')]);
}
