<?php
/**
 * Print a patient advance/deposit receipt.
 *
 * Query params:
 *   advance_id (int)  required
 *   format     a4|thermal
 *   auto       1 to open the print dialog on load
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_advance_functions.php';
require_once __DIR__ . '/../../includes/receipt_functions.php';

dcmt_patient_advance_require_access();
dcmt_patient_advance_ensure_tables($dcmt_pdo);

$advance_id = isset($_GET['advance_id']) ? (int) $_GET['advance_id'] : 0;
$format = (isset($_GET['format']) && $_GET['format'] === 'thermal') ? 'thermal' : 'a4';
$auto_print = isset($_GET['auto']) && (string) $_GET['auto'] === '1';

$receipt = dcmt_receipt_advance_data($dcmt_pdo, $advance_id);
if (!$receipt) {
    dcmt_show_message(trans('patient_advance', 'advance_not_found'), 'error');
    dcmt_redirect('index.php');
    exit();
}

$receipt['clinic'] = dcmt_receipt_clinic();
$receipt['back_url'] = dcmt_receipt_back_url($receipt);

dcmt_audit('print', 'patient_advance_receipt', $advance_id);

$baseParams = ['advance_id' => $advance_id];
$formatUrls = [
    'a4' => 'print_receipt.php?' . http_build_query($baseParams + ['format' => 'a4']),
    'thermal' => 'print_receipt.php?' . http_build_query($baseParams + ['format' => 'thermal']),
];

dcmt_receipt_render($receipt, [
    'format' => $format,
    'auto_print' => $auto_print,
    'asset_base' => '../../',
    'format_urls' => $formatUrls,
]);
