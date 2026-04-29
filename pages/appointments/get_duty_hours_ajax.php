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

    $clinic_stmt = $dcmt_pdo->query("
        SELECT dcmt_setting_key, dcmt_setting_value
        FROM dcmt_settings
        WHERE dcmt_setting_key LIKE 'clinic_working_hours_%'
    ");
    $clinic_settings = $clinic_stmt->fetchAll(PDO::FETCH_ASSOC);
    $clinic_map = [];
    foreach ($clinic_settings as $setting_row) {
        $key = (string)($setting_row['dcmt_setting_key'] ?? '');
        $value = (string)($setting_row['dcmt_setting_value'] ?? '');
        if (preg_match('/^clinic_working_hours_(\d+)_(start|end|active)$/', $key, $matches)) {
            $day = (int)$matches[1];
            if ($day < 0 || $day > 6) {
                continue;
            }
            if (!isset($clinic_map[$day])) {
                $clinic_map[$day] = [
                    'dcmt_weekday' => $day,
                    'dcmt_start_time' => '09:00:00',
                    'dcmt_end_time' => '17:00:00',
                    'dcmt_is_active' => 1
                ];
            }
            if ($matches[2] === 'start') {
                $clinic_map[$day]['dcmt_start_time'] = strlen($value) === 5 ? ($value . ':00') : $value;
            } elseif ($matches[2] === 'end') {
                $clinic_map[$day]['dcmt_end_time'] = strlen($value) === 5 ? ($value . ':00') : $value;
            } else {
                $clinic_map[$day]['dcmt_is_active'] = ($value === '1') ? 1 : 0;
            }
        }
    }
    $clinic_rows = [];
    for ($day = 0; $day <= 6; $day++) {
        $clinic_rows[] = $clinic_map[$day] ?? [
            'dcmt_weekday' => $day,
            'dcmt_start_time' => '09:00:00',
            'dcmt_end_time' => '17:00:00',
            'dcmt_is_active' => 1
        ];
    }

    echo json_encode(['success' => true, 'duty_hours' => $rows, 'clinic_hours' => $clinic_rows]);
} catch (PDOException $e) {
    error_log('Duty hours fetch error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
?>
