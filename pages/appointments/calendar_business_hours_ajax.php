<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';


$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';

$doctor_ids_param = $_GET['doctor_ids'] ?? ($_GET['doctor_id'] ?? []);
$doctor_ids = [];
if (is_array($doctor_ids_param)) {
    foreach ($doctor_ids_param as $v) {
        $id = (int)$v;
        if ($id > 0) {
            $doctor_ids[] = $id;
        }
    }
} else {
    $raw = trim((string)$doctor_ids_param);
    if ($raw !== '') {
        foreach (preg_split('/[,\s]+/', $raw) as $part) {
            $id = (int)$part;
            if ($id > 0) {
                $doctor_ids[] = $id;
            }
        }
    }
}
$doctor_ids = array_values(array_unique($doctor_ids));

if ($role === 'doctor') {
    $doctor_ids = [(int)$user['dcmt_id']];
}

try {
    $hours = dcmt_fc_business_hours_for_doctor_filter($dcmt_pdo, $doctor_ids);
    echo json_encode(['success' => true, 'business_hours' => $hours]);
} catch (PDOException $e) {
    error_log('calendar_business_hours_ajax: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('appointment', 'database_error')]);
}
