<?php
/**
 * AJAX Delete Patient
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

header('Content-Type: application/json');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

if (!dcmt_can_delete_records()) {
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$patient_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'invalid_token')]);
    exit();
}

if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'invalid_id')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_patients WHERE dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$patient) {
        echo json_encode(['success' => false, 'message' => trans('patient', 'not_found')]);
        exit();
    }

    try {
        $notes_check = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_patient_notes WHERE dcmt_patient_id = ?");
        $notes_check->execute([$patient_id]);
        $notes_count = (int) $notes_check->fetchColumn();
        if ($notes_count > 0) {
            echo json_encode(['success' => false, 'message' => trans('patient', 'cannot_delete_has_notes')]);
            exit();
        }
    } catch (PDOException $e) {
        error_log("Error checking patient notes for deletion patient $patient_id: " . $e->getMessage());
        echo json_encode(['success' => false, 'message' => trans('patient', 'cannot_delete_has_notes')]);
        exit();
    }

    $delete_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_patients WHERE dcmt_id = ?");
    $delete_stmt->execute([$patient_id]);

    dcmt_log_activity('Patient deleted', "Patient ID: $patient_id, Name: {$patient['dcmt_patient_name']}");

    echo json_encode(['success' => true, 'message' => trans('patient', 'delete_success'), 'patient_id' => $patient_id]);
} catch (PDOException $e) {
    error_log("Error deleting patient: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient', 'delete_failed')]);
}

