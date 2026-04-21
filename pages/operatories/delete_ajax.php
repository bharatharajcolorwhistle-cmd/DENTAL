<?php
/**
 * AJAX Delete Operatory
 */
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

header('Content-Type: application/json');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

$user = dcmt_get_current_user();
if (!in_array($user['dcmt_role'] ?? '', ['admin', 'staff', 'assistant'], true)) {
    echo json_encode(['success' => false, 'message' => trans('appointment', 'unauthorized')]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => trans('appointment', 'invalid_request')]);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('appointment', 'invalid_request')]);
    exit();
}

$id = (int)($_POST['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('appointment', 'invalid_request')]);
    exit();
}

try {
    $cnt = $dcmt_pdo->prepare("
        SELECT COUNT(*) FROM dcmt_appointments
        WHERE dcmt_operatory_id = ? AND dcmt_status <> 'cancelled'
    ");
    $cnt->execute([$id]);
    if ((int)$cnt->fetchColumn() > 0) {
        echo json_encode(['success' => false, 'message' => trans('appointment', 'operatory_delete_blocked')]);
        exit();
    }

    $stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_operatories WHERE dcmt_id = ?");
    $stmt->execute([$id]);
    if ($stmt->rowCount() === 0) {
        echo json_encode(['success' => false, 'message' => trans('appointment', 'invalid_request')]);
        exit();
    }

    echo json_encode(['success' => true, 'message' => trans('common', 'deleted_successfully')]);
} catch (PDOException $e) {
    error_log('Operatory delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('appointment', 'database_error')]);
}

