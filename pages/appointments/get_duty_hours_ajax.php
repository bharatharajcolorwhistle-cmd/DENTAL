<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

$m = dcmt_appointment_messages();

$user = dcmt_get_current_user();
if (!(dcmt_is_admin() || in_array($user['dcmt_role'] ?? '', ['staff', 'assistant'], true))) {
    echo json_encode(['success' => false, 'message' => $m['unauthorized']]);
    exit();
}

$doctor_id = (int)($_GET['doctor_id'] ?? 0);
if ($doctor_id <= 0) {
    echo json_encode(['success' => false, 'message' => $m['doctor_required']]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT dcmt_weekday, dcmt_start_time, dcmt_end_time, dcmt_is_active
        FROM dcmt_doctor_duty_hours
        WHERE dcmt_doctor_id = ?
    ");
    $stmt->execute([$doctor_id]);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $clinic_rows = array_values(dcmt_load_clinic_hours_map($dcmt_pdo));

    echo json_encode(['success' => true, 'duty_hours' => $rows, 'clinic_hours' => $clinic_rows]);
} catch (PDOException $e) {
    error_log('Duty hours fetch error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
