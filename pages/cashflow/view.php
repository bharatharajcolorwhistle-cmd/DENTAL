<?php
/**
 * View Cashflow Page
 * Dental Clinic Management System
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

// Allow admins and staff
$dcmt_current_user = dcmt_get_current_user();
$dcmt_is_staff = $dcmt_current_user && (($dcmt_current_user['dcmt_role'] ?? '') === 'staff');
if (!dcmt_is_admin() && !$dcmt_is_staff) {
    dcmt_require_admin(); // will redirect with access denied
}

$cashflow_id = intval($_GET['id'] ?? 0);
if (!$cashflow_id) {
    dcmt_show_message(trans('cashflow', 'invalid_cashflow_id') ?: 'Invalid cashflow ID', 'error');
    dcmt_redirect('index.php');
    exit();
}

// Get cashflow record
$stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_cashflows WHERE dcmt_id = ?");
$stmt->execute([$cashflow_id]);
$cashflow = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$cashflow) {
    dcmt_show_message(trans('cashflow', 'cashflow_not_found') ?: 'Cashflow record not found', 'error');
    dcmt_redirect('index.php');
    exit();
}

// Get denominations (end cash denominations)
$denominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, $cashflow_id, 'end');
$startDenominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, $cashflow_id, 'start');

// Separate cash and coin denominations (end cash)
$cashDenominations = [];
$coinDenominations = [];
$cashTotal = 0.0;
$coinTotal = 0.0;

foreach ($denominations as $denom) {
    $value = (float) $denom['dcmt_denomination_value'];
    if ($value > 10.0) {
        $cashDenominations[] = $denom;
        $cashTotal += (float) $denom['dcmt_total_amount'];
    } else {
        $coinDenominations[] = $denom;
        $coinTotal += (float) $denom['dcmt_total_amount'];
    }
}

// Separate start cash denominations
$startCashDenominations = [];
$startCoinDenominations = [];
$startCashTotal = 0.0;
$startCoinTotal = 0.0;

foreach ($startDenominations as $denom) {
    $value = (float) $denom['dcmt_denomination_value'];
    if ($value > 10.0) {
        $startCashDenominations[] = $denom;
        $startCashTotal += (float) $denom['dcmt_total_amount'];
    } else {
        $startCoinDenominations[] = $denom;
        $startCoinTotal += (float) $denom['dcmt_total_amount'];
    }
}

// Recalculate cash income total to ensure accuracy (including all multiple payments)
$recordDate = $cashflow['dcmt_record_date'];
$cashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $recordDate);
$cashExpenseTotal = dcmt_calculate_cash_expense_total($dcmt_pdo, $recordDate);

// Calculate Net Cashflow = Cash Income - Cash Expense
$netCashflow = round($cashIncomeTotal - $cashExpenseTotal, 2);

// Calculate Total Ending Cash from denominations (sum of all end cash denominations)
$totalEndingCash = round($cashTotal + $coinTotal, 2);

// Calculate Difference based on Net Cashflow sign
// If Net Cashflow is positive: Difference = Net Cashflow - Total Ending Cash
// If Net Cashflow is negative: Difference = Total Ending Cash - |Net Cashflow|
if ($netCashflow >= 0) {
    $difference = round($netCashflow - $totalEndingCash, 2);
} else {
    $difference = round($totalEndingCash - abs($netCashflow), 2);
}
$isBalanced = abs($difference) < 0.01;

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <?php echo trans('cashflow', 'cashflow_details'); ?>
                </h6>
                <div class="dcmt-view-header-links">
                    <?php if (dcmt_is_admin()): ?>
                    <a href="edit.php?id=<?php echo $cashflow['dcmt_id']; ?>" class="dcmt-add-form-view-all-link me-3">
                        <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                    </a>
                    <?php endif; ?>
                    <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('cashflow', 'back_to_list') ?: trans('common', 'back'); ?></a>
                </div>
            </div>
            <div class="card-body">
                <!-- Cashflow Details -->
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'cash_income_total'); ?>:</span>
                            <div class="dcmt-view-field-value dcmt-view-field-value-amount"><?php echo dcmt_format_currency($cashflow['dcmt_cash_income_total']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'expenses_in_cash'); ?>:</span>
                            <div class="dcmt-view-field-value dcmt-view-field-value-amount"><?php echo dcmt_format_currency($cashflow['dcmt_cash_expense_total'] ?? 0); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'net_cashflow'); ?>:</span>
                            <div class="dcmt-view-field-value dcmt-view-field-value-amount"><?php echo dcmt_format_currency($cashflow['dcmt_net_cashflow'] ?? 0); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="row mt-3">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'total_ending_cash'); ?>:</span>
                            <div class="dcmt-view-field-value dcmt-view-field-value-amount"><?php echo dcmt_format_currency($totalEndingCash); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'difference'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php
                                    // Positive/zero differences (surplus) appear in green, negatives in red
                                    $differenceClass = ($difference >= 0) ? 'text-success' : 'text-danger';
                                ?>
                                <span class="<?php echo $differenceClass; ?> fw-bold">
                                    <?php echo dcmt_format_currency($difference); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'status'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span class="<?php echo $isBalanced ? 'text-success' : 'text-warning'; ?>">
                                    <?php echo $isBalanced ? trans('cashflow', 'balanced') : trans('cashflow', 'attention_needed'); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'created_by'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($cashflow['dcmt_created_by']); ?></div>
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($cashflow['dcmt_notes'])): ?>
                    <div class="row mt-3">
                        <div class="col-12">
                            <div class="dcmt-view-field">
                                <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'notes'); ?>:</span>
                                <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($cashflow['dcmt_notes'])); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- Cash Summary Section -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h6 class="dcmt-view-table-title">
                            <?php echo trans('cashflow', 'cash_summary'); ?>
                        </h6>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-12">
                        <div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'cashflow_date'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars(dcmt_format_date($cashflow['dcmt_record_date'], 'M d, Y')); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'opening_balance'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($cashflow['dcmt_starting_amount']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'closing_balance'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($cashflow['dcmt_ending_amount']); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <!-- Cash Breakdown Section -->
                <div class="row mt-4">
                    <div class="col-12">
                        <h6 class="dcmt-view-table-title">
                            <?php echo trans('cashflow', 'cash_breakdown'); ?>
                        </h6>
                        
                        <!-- Start Cash Denominations -->
                        <div class="mb-4">
                            <h6 class="mb-3 fw-bold text-primary">
                                <i class="fas fa-play me-2"></i><?php echo trans('cashflow', 'start_cash_denominations'); ?>
                            </h6>
                            <div class="row g-3">
                                <!-- Start Cash Denominations (Left Side) -->
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header dcmt-cashflow-card-header bg-light">
                                            <h6 class="mb-0"><?php echo trans('cashflow', 'cash_denominations'); ?></h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table dcmt-view-table table-hover mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th><?php echo trans('cashflow', 'denomination'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'quantity'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'total_amount'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($startCashDenominations)): ?>
                                                            <tr>
                                                                <td colspan="3" class="text-center text-muted"><?php echo trans('cashflow', 'no_cash_denominations'); ?></td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php foreach ($startCashDenominations as $denom): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?></td>
                                                                    <td class="text-end"><?php echo (int) $denom['dcmt_quantity']; ?></td>
                                                                    <td class="text-end"><?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="table-light">
                                                            <th class="text-end" colspan="2"><?php echo trans('cashflow', 'cash_total'); ?>:</th>
                                                            <th class="text-end"><?php echo dcmt_format_currency($startCashTotal); ?></th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- Start Coin Denominations (Right Side) -->
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header dcmt-cashflow-card-header bg-light">
                                            <h6 class="mb-0"><?php echo trans('cashflow', 'coin_denominations'); ?></h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table dcmt-view-table table-hover mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th><?php echo trans('cashflow', 'denomination'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'quantity'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'total_amount'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($startCoinDenominations)): ?>
                                                            <tr>
                                                                <td colspan="3" class="text-center text-muted"><?php echo trans('cashflow', 'no_coin_denominations'); ?></td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php foreach ($startCoinDenominations as $denom): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?></td>
                                                                    <td class="text-end"><?php echo (int) $denom['dcmt_quantity']; ?></td>
                                                                    <td class="text-end"><?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="table-light">
                                                            <th class="text-end" colspan="2"><?php echo trans('cashflow', 'coin_total'); ?>:</th>
                                                            <th class="text-end"><?php echo dcmt_format_currency($startCoinTotal); ?></th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($startCashTotal > 0 || $startCoinTotal > 0): ?>
                                <div class="row mt-2">
                                    <div class="col-12 text-end">
                                        <strong><?php echo trans('cashflow', 'denominations_total'); ?>: <?php echo dcmt_format_currency($startCashTotal + $startCoinTotal); ?></strong>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>

                        <!-- End Cash Denominations -->
                        <div>
                            <h6 class="mb-3 fw-bold text-success">
                                <i class="fas fa-stop me-2"></i><?php echo trans('cashflow', 'end_cash_denominations'); ?>
                            </h6>
                            <div class="row g-3">
                                <!-- End Cash Denominations (Left Side) -->
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header dcmt-cashflow-card-header bg-light">
                                            <h6 class="mb-0"><?php echo trans('cashflow', 'cash_denominations'); ?></h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table dcmt-view-table table-hover mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th><?php echo trans('cashflow', 'denomination'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'quantity'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'total_amount'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($cashDenominations)): ?>
                                                            <tr>
                                                                <td colspan="3" class="text-center text-muted"><?php echo trans('cashflow', 'no_cash_denominations'); ?></td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php foreach ($cashDenominations as $denom): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?></td>
                                                                    <td class="text-end"><?php echo (int) $denom['dcmt_quantity']; ?></td>
                                                                    <td class="text-end"><?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="table-light">
                                                            <th class="text-end" colspan="2"><?php echo trans('cashflow', 'cash_total'); ?>:</th>
                                                            <th class="text-end"><?php echo dcmt_format_currency($cashTotal); ?></th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>

                                <!-- End Coin Denominations (Right Side) -->
                                <div class="col-md-6">
                                    <div class="card">
                                        <div class="card-header dcmt-cashflow-card-header bg-light">
                                            <h6 class="mb-0"><?php echo trans('cashflow', 'coin_denominations'); ?></h6>
                                        </div>
                                        <div class="card-body p-0">
                                            <div class="table-responsive">
                                                <table class="table dcmt-view-table table-hover mb-0">
                                                    <thead>
                                                        <tr>
                                                            <th><?php echo trans('cashflow', 'denomination'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'quantity'); ?></th>
                                                            <th class="text-end"><?php echo trans('cashflow', 'total_amount'); ?></th>
                                                        </tr>
                                                    </thead>
                                                    <tbody>
                                                        <?php if (empty($coinDenominations)): ?>
                                                            <tr>
                                                                <td colspan="3" class="text-center text-muted"><?php echo trans('cashflow', 'no_coin_denominations'); ?></td>
                                                            </tr>
                                                        <?php else: ?>
                                                            <?php foreach ($coinDenominations as $denom): ?>
                                                                <tr>
                                                                    <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?></td>
                                                                    <td class="text-end"><?php echo (int) $denom['dcmt_quantity']; ?></td>
                                                                    <td class="text-end"><?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?></td>
                                                                </tr>
                                                            <?php endforeach; ?>
                                                        <?php endif; ?>
                                                    </tbody>
                                                    <tfoot>
                                                        <tr class="table-light">
                                                            <th class="text-end" colspan="2"><?php echo trans('cashflow', 'coin_total'); ?>:</th>
                                                            <th class="text-end"><?php echo dcmt_format_currency($coinTotal); ?></th>
                                                        </tr>
                                                    </tfoot>
                                                </table>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                            <?php if ($cashTotal > 0 || $coinTotal > 0): ?>
                                <div class="row mt-2">
                                    <div class="col-12 text-end">
                                        <strong><?php echo trans('cashflow', 'denominations_total'); ?>: <?php echo dcmt_format_currency($cashTotal + $coinTotal); ?></strong>
                                    </div>
                                </div>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
