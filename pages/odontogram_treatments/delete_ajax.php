<?php
/**
 * AJAX delete odontogram treatment
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/odontogram_treatments.php';

header('Content-Type: application/json');

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
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'invalid_treatment_id')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare('SELECT * FROM dcmt_odontogram_treatments WHERE dcmt_id = ?');
    $stmt->execute([$id]);
    $row = $stmt->fetch();
    if (!$row) {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'category_not_found')]);
        exit();
    }
    if ($row['dcmt_created_by'] === 'system') {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'cannot_delete_system')]);
        exit();
    }
    if (dcmt_odontogram_treatment_is_in_use($dcmt_pdo, (string) $row['dcmt_name'])) {
        echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'cannot_delete_in_use')]);
        exit();
    }
    $dcmt_pdo->prepare('DELETE FROM dcmt_odontogram_treatments WHERE dcmt_id = ?')->execute([$id]);
    dcmt_log_activity('Odontogram treatment deleted: ' . $row['dcmt_name'], 'odontogram_treatment_deleted');
    echo json_encode(['success' => true, 'message' => trans('odontogram_treatment', 'treatment_deleted_successfully')]);
} catch (PDOException $e) {
    echo json_encode(['success' => false, 'message' => trans('odontogram_treatment', 'database_error')]);
}
