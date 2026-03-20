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
$role = $user['dcmt_role'] ?? '';

$start = trim((string)($_GET['start'] ?? ''));
$end = trim((string)($_GET['end'] ?? ''));
$doctor_id = (int)($_GET['doctor_id'] ?? 0);

if ($start === '' || $end === '') {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

if ($role === 'doctor') {
    $doctor_id = (int)$user['dcmt_id'];
}

try {
    $sql = "
        SELECT a.dcmt_id, a.dcmt_start_at, a.dcmt_end_at, a.dcmt_status, a.dcmt_reason, a.dcmt_notes,
               p.dcmt_patient_name, p.dcmt_phone, d.dcmt_full_name AS doctor_name, a.dcmt_doctor_id
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        WHERE a.dcmt_start_at < ? AND a.dcmt_end_at > ?
    ";
    $params = [$end, $start];

    if ($doctor_id > 0) {
        $sql .= " AND a.dcmt_doctor_id = ? ";
        $params[] = $doctor_id;
    }

    $sql .= " ORDER BY a.dcmt_start_at ASC";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    foreach ($rows as $row) {
        $events[] = [
            'id' => (int)$row['dcmt_id'],
            'title' => $row['dcmt_patient_name'] . ' - ' . $row['doctor_name'],
            'start' => $row['dcmt_start_at'],
            'end' => $row['dcmt_end_at'],
            'status' => $row['dcmt_status'],
            'doctor_id' => (int)$row['dcmt_doctor_id'],
            'reason' => $row['dcmt_reason'],
            'notes' => $row['dcmt_notes'],
            'patient_phone' => $row['dcmt_phone'],
        ];
    }

    echo json_encode(['success' => true, 'events' => $events]);
} catch (PDOException $e) {
    error_log('Appointment list error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
