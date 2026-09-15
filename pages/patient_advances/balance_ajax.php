<?php
/**
 * Remaining advance balance for a patient (income forms).
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/patient_advance_functions.php';

if (!dcmt_patient_advance_can_access()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'remaining' => 0]);
    exit();
}

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$income_id = isset($_GET['income_id']) ? (int) $_GET['income_id'] : 0;
if ($patient_id <= 0) {
    echo json_encode(['success' => true, 'remaining' => 0, 'available' => 0]);
    exit();
}

dcmt_patient_advance_ensure_tables($dcmt_pdo);
$remaining = dcmt_patient_advance_remaining($dcmt_pdo, $patient_id);
$available = dcmt_patient_advance_available_for_income(
    $dcmt_pdo,
    $patient_id,
    $income_id > 0 ? $income_id : null
);

echo json_encode([
    'success' => true,
    'remaining' => $remaining,
    'available' => $available,
]);
