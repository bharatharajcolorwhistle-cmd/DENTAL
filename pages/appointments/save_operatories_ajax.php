<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

header('Content-Type: application/json');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

$m = dcmt_appointment_messages();
$user = dcmt_get_current_user();
if (!dcmt_is_admin()) {
    echo json_encode(['success' => false, 'message' => $m['unauthorized']]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

$raw = $_POST['operatories_json'] ?? '[]';
$rows = json_decode((string)$raw, true);

if (!is_array($rows)) {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

try {
    $dcmt_pdo->beginTransaction();
    $result = dcmt_save_operatories_global($dcmt_pdo, $rows, $m);
    if (!$result['ok']) {
        $dcmt_pdo->rollBack();
        echo json_encode(['success' => false, 'message' => $result['message']]);
        exit();
    }
    $dcmt_pdo->commit();
    echo json_encode(['success' => true, 'message' => $m['operatories_saved']]);
} catch (PDOException $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log('save_operatories_ajax: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
