<?php
/**
 * Delete Cashflow Record
 * Admin only
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/cashflow_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_require_admin();

$cashflowId = intval($_GET['id'] ?? 0);
if (!$cashflowId) {
    dcmt_show_message(trans('cashflow', 'invalid_cashflow_id') ?: 'Invalid cashflow ID', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/cashflow/index.php');
    exit();
}

try {
    $dcmt_pdo->beginTransaction();

    // Delete denominations first
    $delDenoms = $dcmt_pdo->prepare("DELETE FROM dcmt_cashflow_denominations WHERE dcmt_cashflow_id = ?");
    $delDenoms->execute([$cashflowId]);

    // Delete cashflow record
    $delCashflow = $dcmt_pdo->prepare("DELETE FROM dcmt_cashflows WHERE dcmt_id = ?");
    $delCashflow->execute([$cashflowId]);

    $dcmt_pdo->commit();

    dcmt_log_activity("Cashflow deleted (ID: {$cashflowId})", 'cashflow_delete');
    dcmt_show_message(trans('common', 'deleted_successfully') ?: 'Record deleted successfully', 'success');
} catch (PDOException $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log('Cashflow delete failed: ' . $e->getMessage());
    dcmt_show_message('Failed to delete cashflow record.', 'error');
}

dcmt_redirect(DCMT_APP_URL . '/pages/cashflow/index.php');

