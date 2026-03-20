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

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT a.*,
               p.dcmt_patient_name,
               d.dcmt_full_name AS doctor_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        WHERE a.dcmt_id = ?
        LIMIT 1
    ");
    $stmt->execute([$id]);
    $a = $stmt->fetch(PDO::FETCH_ASSOC);

    if (!$a) {
        echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
        exit();
    }

    if ($role === 'doctor' && (int)$a['dcmt_doctor_id'] !== (int)$user['dcmt_id']) {
        echo json_encode(['success' => false, 'message' => $m['unauthorized']]);
        exit();
    }

    echo json_encode([
        'success' => true,
        'appointment' => [
            'id' => (int)$a['dcmt_id'],
            'doctor_id' => (int)$a['dcmt_doctor_id'],
            'patient_id' => (int)$a['dcmt_patient_id'],
            'date' => date('Y-m-d', strtotime($a['dcmt_start_at'])),
            'start_time' => date('H:i', strtotime($a['dcmt_start_at'])),
            'end_time' => date('H:i', strtotime($a['dcmt_end_at'])),
            'status' => $a['dcmt_status'],
            'reason' => $a['dcmt_reason'],
            'notes' => $a['dcmt_notes'],
            'patient_name' => $a['dcmt_patient_name'],
            'doctor_name' => $a['doctor_name'],
        ]
    ]);
} catch (PDOException $e) {
    error_log('Appointment get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
