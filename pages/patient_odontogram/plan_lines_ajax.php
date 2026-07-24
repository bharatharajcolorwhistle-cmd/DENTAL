<?php
/**
 * AJAX: refresh treatment plan lines and service catalog for a doctor.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';
require_once __DIR__ . '/../../includes/patient_treatment_plan.php';

header('Content-Type: application/json');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$doctor_id = isset($_GET['doctor_id']) ? (int) $_GET['doctor_id'] : 0;

if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'invalid_id')]);
    exit();
}

$dcmt_db = new Dcmt_Database();
$dcmt_db->migratePatientTreatmentPlanSchema();

global $dcmt_current_user;
$sync_result = dcmt_sync_treatment_plan_from_odontogram(
    $dcmt_pdo,
    $patient_id,
    $doctor_id,
    false,
    $dcmt_current_user['dcmt_username'] ?? null
);

$odontogram_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
$document = dcmt_patient_odontogram_decode_document($odontogram_json);
$solution = $document['solution'] ?? dcmt_patient_odontogram_empty_chart();

$saved = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
if (is_array($sync_result) && (!empty($sync_result['synced']) || !empty($sync_result['rebuilt']))) {
    $lines = $sync_result['lines'] ?? [];
} elseif ($saved && !empty($saved['lines'])) {
    $lines = dcmt_normalize_treatment_plan_lines($saved['lines'], $dcmt_pdo, $doctor_id);
} else {
    $lines = dcmt_build_treatment_plan_lines($dcmt_pdo, $solution, $doctor_id);
}

$lines = dcmt_filter_treatment_plan_lines_by_show_flag($dcmt_pdo, $lines);

// Apply doctor prices per line when both doctor and service are set.
foreach ($lines as &$line) {
    $lineDoctorId = (int) ($line['doctor_id'] ?? 0);
    if ($lineDoctorId <= 0) {
        $lineDoctorId = $doctor_id;
    }
    $sid = (int) ($line['service_id'] ?? 0);
    if ($sid > 0 && $lineDoctorId > 0) {
        $line['doctor_id'] = $lineDoctorId;
        $line['unit_price'] = dcmt_get_service_price_for_doctor($dcmt_pdo, $lineDoctorId, $sid);
        $line['subtotal'] = round(((float) $line['unit_price']) * (int) ($line['quantity'] ?? 1), 2);
    } else {
        $line['doctor_id'] = $lineDoctorId > 0 ? $lineDoctorId : 0;
        $line['service_id'] = $sid > 0 ? $sid : 0;
        if ($sid <= 0) {
            $line['service_id'] = 0;
            $line['service_name'] = '';
            $line['unit_price'] = 0.0;
            $line['subtotal'] = 0.0;
        }
    }
}
unset($line);

$services_by_doctor = [];
foreach ($lines as &$line) {
    $lineDoctorId = (int) ($line['doctor_id'] ?? 0);
    if ($lineDoctorId <= 0) {
        continue;
    }
    if (!isset($services_by_doctor[$lineDoctorId])) {
        $services_by_doctor[$lineDoctorId] = dcmt_fetch_active_services_catalog($dcmt_pdo, $lineDoctorId);
    }
    $serviceIds = [];
    foreach ($services_by_doctor[$lineDoctorId] as $svc) {
        $serviceIds[(int) $svc['id']] = true;
    }
    $sid = (int) ($line['service_id'] ?? 0);
    if ($sid > 0 && !isset($serviceIds[$sid])) {
        $line['service_id'] = 0;
        $line['service_name'] = '';
        $line['unit_price'] = 0.0;
        $line['subtotal'] = 0.0;
    }
}
unset($line);

echo json_encode([
    'success' => true,
    'lines' => $lines,
    'services_by_doctor' => $services_by_doctor,
    'total' => dcmt_treatment_plan_calculate_total($lines),
]);
