<?php
/**
 * Export single patient data bundle (JSON) — ARCO access right.
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_compliance.php';

dcmt_require_admin();

$patient_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit;
}

$bundle = dcmt_build_patient_export_bundle($dcmt_pdo, $patient_id);
if ($bundle === null) {
    dcmt_show_message(trans('patient', 'not_found'), 'danger');
    dcmt_redirect('index.php');
    exit;
}

dcmt_audit('export', 'patient', $patient_id);
dcmt_log_activity('Patient data export (JSON)', "Patient ID: {$patient_id}");

$filename = 'patient_' . $patient_id . '_export_' . date('Y-m-d_His') . '.json';
header('Content-Type: application/json; charset=utf-8');
header('Content-Disposition: attachment; filename="' . $filename . '"');
header('Cache-Control: no-store');
echo json_encode($bundle, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
exit;
