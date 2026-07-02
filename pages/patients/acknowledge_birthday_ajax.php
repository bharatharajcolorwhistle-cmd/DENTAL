<?php
/**
 * AJAX acknowledge birthday wish sent for a patient.
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/birthday_functions.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$patient_id = isset($_POST['id']) ? (int) $_POST['id'] : 0;
if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'invalid_token')]);
    exit();
}

try {
    $result = dcmt_acknowledge_birthday_wish($dcmt_pdo, $patient_id, $dcmt_current_user);
    echo json_encode($result);
} catch (PDOException $e) {
    error_log('Birthday acknowledge error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient', 'database_error')]);
}
