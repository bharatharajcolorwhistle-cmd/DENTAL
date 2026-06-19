<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

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
               d.dcmt_full_name AS doctor_name,
               o.dcmt_name AS operatory_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
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

    $normalized_status = dcmt_normalize_appointment_status((string)$a['dcmt_status']);
    $status_class = 'text-primary';
    if ($normalized_status === 'completed') {
        $status_class = 'text-success';
    } elseif ($normalized_status === 'cancelled') {
        $status_class = 'text-danger';
    }

    echo json_encode([
        'success' => true,
        'appointment' => [
            'id' => (int)$a['dcmt_id'],
            'doctor_id' => (int)$a['dcmt_doctor_id'],
            'operatory_id' => (int)$a['dcmt_operatory_id'],
            'patient_id' => (int)$a['dcmt_patient_id'],
            'date' => date('Y-m-d', strtotime($a['dcmt_start_at'])),
            'start_time' => date('H:i', strtotime($a['dcmt_start_at'])),
            'end_time' => date('H:i', strtotime($a['dcmt_end_at'])),
            'actual_start_time' => !empty($a['dcmt_actual_start_at']) ? date('H:i', strtotime($a['dcmt_actual_start_at'])) : '',
            'actual_end_time' => !empty($a['dcmt_actual_end_at']) ? date('H:i', strtotime($a['dcmt_actual_end_at'])) : '',
            'actual_start_display' => !empty($a['dcmt_actual_start_at'])
                ? date('h:i A', strtotime((string)$a['dcmt_actual_start_at']))
                : '',
            'actual_end_display' => !empty($a['dcmt_actual_end_at'])
                ? date('h:i A', strtotime((string)$a['dcmt_actual_end_at']))
                : '',
            'has_actual_start' => !empty($a['dcmt_actual_start_at']),
            'has_actual_end' => !empty($a['dcmt_actual_end_at']),
            'status' => $normalized_status,
            'status_label' => trans('appointment', $normalized_status),
            'status_class' => $status_class,
            'reason' => $a['dcmt_reason'],
            'notes' => $a['dcmt_notes'],
            'patient_name' => $a['dcmt_patient_name'],
            'doctor_name' => $a['doctor_name'],
            'operatory_name' => $a['operatory_name'],
        ]
    ]);
} catch (PDOException $e) {
    error_log('Appointment get error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
