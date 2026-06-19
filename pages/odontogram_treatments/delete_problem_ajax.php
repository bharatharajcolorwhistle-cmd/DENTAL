<?php
/**
 * AJAX delete odontogram problem
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/odontogram_treatments.php';

if (!dcmt_validate_session() || !dcmt_is_admin()) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'invalid_token')]);
    exit();
}

$id = (int) ($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'invalid_problem_id')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare('SELECT * FROM dcmt_odontogram_problem_states WHERE dcmt_id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'problem_not_found')]);
        exit();
    }
    if (($row['dcmt_created_by'] ?? '') === 'system') {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'cannot_delete_system_problem')]);
        exit();
    }
    if (dcmt_odontogram_problem_state_is_in_use($dcmt_pdo, (string) $row['dcmt_state_key'])) {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'cannot_delete_problem_in_use')]);
        exit();
    }
    $dcmt_pdo->prepare('DELETE FROM dcmt_odontogram_problem_states WHERE dcmt_id = ?')->execute([$id]);
    dcmt_log_activity('Odontogram problem deleted: ' . dcmt_odontogram_problem_display_name($row), 'odontogram_problem_deleted');
    echo json_encode(['success' => true, 'message' => trans('odontogram_treatment', 'problem_deleted_successfully')]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'database_error')]);
}
