<?php
/**
 * Print a patient payment receipt (ticket).
 *
 * Query params:
 *   payment_id (int)  receipt for one collected payment (preferred)
 *   income_id  (int)  receipt for an income record and its payments
 *   format     a4|thermal
 *   auto       1 to open the print dialog on load
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/receipt_functions.php';

$payment_id = isset($_GET['payment_id']) ? (int) $_GET['payment_id'] : 0;
$income_id = isset($_GET['income_id']) ? (int) $_GET['income_id'] : 0;
$format = (isset($_GET['format']) && $_GET['format'] === 'thermal') ? 'thermal' : 'a4';
$auto_print = isset($_GET['auto']) && (string) $_GET['auto'] === '1';

$receipt = null;
if ($payment_id > 0) {
    $receipt = dcmt_receipt_payment_data($dcmt_pdo, $payment_id);
} elseif ($income_id > 0) {
    $receipt = dcmt_receipt_income_data($dcmt_pdo, $income_id);
}

if (!$receipt) {
    dcmt_show_message(trans('income', 'income_not_found'), 'error');
    dcmt_redirect('index.php');
    exit();
}

$receipt['clinic'] = dcmt_receipt_clinic();
$receipt['back_url'] = dcmt_receipt_back_url($receipt);

dcmt_audit('print', 'income_receipt', $payment_id > 0 ? $payment_id : $income_id);

$baseParams = [];
if ($payment_id > 0) {
    $baseParams['payment_id'] = $payment_id;
} else {
    $baseParams['income_id'] = $income_id;
}
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
