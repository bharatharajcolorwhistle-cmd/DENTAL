<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

$m = dcmt_appointment_messages();

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';

$start = trim((string)($_GET['start'] ?? ''));
$end = trim((string)($_GET['end'] ?? ''));
$doctor_ids_param = $_GET['doctor_ids'] ?? ($_GET['doctor_id'] ?? []);
$doctor_ids = [];
if (is_array($doctor_ids_param)) {
    foreach ($doctor_ids_param as $v) {
        $id = (int)$v;
        if ($id > 0) $doctor_ids[] = $id;
    }
} else {
    $raw = trim((string)$doctor_ids_param);
    if ($raw !== '') {
        foreach (preg_split('/[,\s]+/', $raw) as $part) {
            $id = (int)$part;
            if ($id > 0) $doctor_ids[] = $id;
        }
    }
}
$doctor_ids = array_values(array_unique($doctor_ids));

if ($start === '' || $end === '') {
    echo json_encode(['success' => false, 'message' => $m['invalid_request']]);
    exit();
}

if ($role === 'doctor') {
    $doctor_ids = [(int)$user['dcmt_id']];
}

try {
    $sql = "
        SELECT a.dcmt_id, a.dcmt_start_at, a.dcmt_end_at, a.dcmt_status, a.dcmt_reason, a.dcmt_notes,
               a.dcmt_patient_id, a.dcmt_operatory_id, a.dcmt_actual_start_at, a.dcmt_actual_end_at,
               p.dcmt_patient_name, p.dcmt_phone, d.dcmt_full_name AS doctor_name, d.dcmt_color_code AS doctor_color, a.dcmt_doctor_id,
               o.dcmt_name AS operatory_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        WHERE a.dcmt_start_at < ? AND a.dcmt_end_at > ?
    ";
    $params = [$end, $start];

    if (count($doctor_ids) === 1) {
        $sql .= " AND a.dcmt_doctor_id = ? ";
        $params[] = $doctor_ids[0];
    } elseif (count($doctor_ids) > 1) {
        $placeholders = implode(',', array_fill(0, count($doctor_ids), '?'));
        $sql .= " AND a.dcmt_doctor_id IN ($placeholders) ";
        $params = array_merge($params, $doctor_ids);
    }

    $sql .= " ORDER BY a.dcmt_start_at ASC";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $events = [];
    foreach ($rows as $row) {
        $normalized_status = dcmt_normalize_appointment_status($row['dcmt_status']);
        $op_name = trim((string)($row['operatory_name'] ?? ''));
        $title = trim((string)($row['dcmt_patient_name'] ?? ''));
        $events[] = [
            'id' => (int)$row['dcmt_id'],
            'title' => $title,
            'start' => $row['dcmt_start_at'],
            'end' => $row['dcmt_end_at'],
            'patient_name' => $row['dcmt_patient_name'],
            'doctor_name' => $row['doctor_name'],
            'status' => $normalized_status,
            'doctor_id' => (int)$row['dcmt_doctor_id'],
            'patient_id' => (int)$row['dcmt_patient_id'],
            'operatory_id' => (int)$row['dcmt_operatory_id'],
            'actual_start_time' => !empty($row['dcmt_actual_start_at']) ? date('H:i', strtotime($row['dcmt_actual_start_at'])) : '',
            'actual_end_time' => !empty($row['dcmt_actual_end_at']) ? date('H:i', strtotime($row['dcmt_actual_end_at'])) : '',
            'doctor_color' => trim((string)($row['doctor_color'] ?? '')),
            'operatory_name' => $op_name,
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
