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
$search_date = $_GET['search_date'] ?? '';

// If a search date is provided, try to find the record ID for that date
if ($search_date) {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_cashflows WHERE dcmt_record_date = ? LIMIT 1");
    $stmt->execute([$search_date]);
    $search_result = $stmt->fetch(PDO::FETCH_ASSOC);

    if ($search_result) {
        $cashflow_id = $search_result['dcmt_id'];
    } else {
        dcmt_show_message(trans('cashflow', 'no_records_found') ?: (trans('cashflow', 'no_record_found_for_date') ?: 'No cashflow record found for this date'), 'warning');
        // If we don't have an ID yet, we must redirect or handle the error
        if (!$cashflow_id) {
            dcmt_redirect('index.php');
            exit();
        }
    }
}

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

// Use stored values from database - following the index page logic
$startingAmount = (float) $cashflow['dcmt_starting_amount'];
$cashIncomeTotal = (float) $cashflow['dcmt_cash_income_total'];
$ownerWithdrawName = $cashflow['dcmt_owner_withdraw_name'] ?? '';
$ownerWithdrawAmount = (float) ($cashflow['dcmt_owner_withdraw_amount'] ?? 0);

// Net Cashflow = Start Cash + Cash Inflow − Cash Outflow (Owner Withdraw)
$netCashflow = (float) ($cashflow['dcmt_net_cashflow'] ?? ($startingAmount + $cashIncomeTotal - $ownerWithdrawAmount));

// Calculate Total Ending Cash from denominations (sum of all end cash denominations)
$totalEndingCash = round($cashTotal + $coinTotal, 2);

// Expected ending cash should match Net Cashflow when counted denominations are correct
$expectedEndingCash = round($netCashflow, 2);

// Net Cashflow display value (matches UI formula)
$netCashflowDisplay = $netCashflow;

// Difference = Total Ending Cash (Actual) - Expected Ending Cash
$difference = round($totalEndingCash - $expectedEndingCash, 2);
$isBalanced = abs($difference) < 0.01;

// Check if start cash and end cash have been added for today
$today = dcmt_get_current_date();
$todayRecord = dcmt_get_cashflow_by_date($dcmt_pdo, $today);
$startCashAddedToday = false;
$endCashAddedToday = false;

if ($todayRecord) {
    $startCashAddedToday = (float) ($todayRecord['dcmt_starting_amount'] ?? 0) > 0;
    $endCashAddedToday = (float) ($todayRecord['dcmt_ending_amount'] ?? 0) > 0;
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sub_header.php';
?>

<div class="container-fluid mt-4">
    <div class="card mb-4 dcmt-filter-form">
        <div class="card-body">
            <form action="view.php" method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="search_date" class="form-label"><?php echo trans('cashflow', 'date'); ?></label>
                    <input type="date" name="search_date" id="search_date" class="form-control dcmt-filter-field"
                        value="<?php echo htmlspecialchars($cashflow['dcmt_record_date']); ?>" required>
                </div>
                <div class="col-md-auto">
                    <button type="submit" class="dcmt-filter-btn">
                        <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <div class="card dcmt-records-table">
        <div class="card-header dcmt-view-card-header">
            <h6 class="dcmt-view-card-title">
                <?php echo trans('cashflow', 'cashflow_details'); ?>
            </h6>
            <div class="dcmt-view-header-links">
                <?php if (dcmt_is_admin()): ?>
                    <a href="edit.php?id=<?php echo $cashflow['dcmt_id']; ?>" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-edit me-1"></i>
                        <?php echo trans('common', 'edit'); ?>
                    </a>
                <?php endif; ?>
                <a href="index.php" class="dcmt-add-form-view-all-link ms-3">
                    <i class="fas fa-list me-1"></i>
                    <?php echo trans('cashflow', 'back_to_list') ?: (trans('cashflow', 'back_to_list') ?: 'Back to all records'); ?>
                </a>
            </div>
        </div>
        <div class="card-body">
            <!-- Cashflow Details -->
            <div class="row">
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'cashflow_date'); ?>:</span>
                        <div class="dcmt-view-field-value">
                            <?php echo htmlspecialchars(dcmt_format_date($cashflow['dcmt_record_date'], 'M d, Y')); ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'start_cash'); ?>:</span>
                        <div class="dcmt-view-field-value dcmt-view-field-value-amount">
                            <?php echo dcmt_format_currency($startingAmount); ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span
                            class="dcmt-view-field-label"><?php echo trans('cashflow', 'cash_inflows') ?: 'Cash Inflows'; ?>:</span>
                        <div class="dcmt-view-field-value dcmt-view-field-value-amount">
                            <?php echo dcmt_format_currency($cashIncomeTotal); ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span
                            class="dcmt-view-field-label"><?php echo trans('cashflow', 'end_cash'); ?>:</span>
                        <div class="dcmt-view-field-value dcmt-view-field-value-amount">
                            <?php echo dcmt_format_currency($totalEndingCash); ?>
                        </div>
                    </div>
                </div>
            </div>

            <div class="row mt-3">
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'net_cashflow'); ?>:</span>
                        <div class="dcmt-view-field-value dcmt-view-field-value-amount">
                            <?php echo dcmt_format_currency($netCashflowDisplay); ?>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'status'); ?>:</span>
                        <div class="dcmt-view-field-value">
                            <span class="<?php echo $isBalanced ? 'text-success' : 'text-warning'; ?> fw-bold">
                                <?php echo $isBalanced ? trans('cashflow', 'balanced') : trans('cashflow', 'attention_needed'); ?>
                            </span>
                        </div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'created_by'); ?>:</span>
                        <div class="dcmt-view-field-value">
                            <?php echo htmlspecialchars($cashflow['dcmt_created_by']); ?>
                        </div>
                    </div>
                </div>
            </div>

            <?php if (!empty($cashflow['dcmt_notes'])): ?>
                <div class="row mt-3">
                    <div class="col-12">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'notes'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo nl2br(htmlspecialchars($cashflow['dcmt_notes'])); ?>
                            </div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>

            <div class="row mt-4">
                <div class="col-12">
                    <h6 class="dcmt-view-table-title">
                        <?php echo trans('cashflow', 'cash_withdrawal'); ?>
                    </h6>
                    <div class="row mt-3">
                        <div class="col-md-3">
                            <div class="dcmt-view-field">
                                <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'doctor_name'); ?>:</span>
                                <div class="dcmt-view-field-value">
                                    <?php echo trim($ownerWithdrawName) !== '' ? htmlspecialchars($ownerWithdrawName) : '-'; ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-3">
                            <div class="dcmt-view-field">
                                <span class="dcmt-view-field-label"><?php echo trans('cashflow', 'amount'); ?>:</span>
                                <div class="dcmt-view-field-value dcmt-view-field-value-amount">
                                    <?php echo dcmt_format_currency($ownerWithdrawAmount); ?>
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
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'quantity'); ?>
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'total_amount'); ?>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($startCashDenominations)): ?>
                                                        <tr>
                                                            <td colspan="3" class="text-center text-muted">
                                                                <?php echo trans('cashflow', 'no_cash_denominations'); ?>
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($startCashDenominations as $denom): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo (int) $denom['dcmt_quantity']; ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-light">
                                                        <th class="text-end" colspan="2">
                                                            <?php echo trans('cashflow', 'cash_total'); ?>:
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo dcmt_format_currency($startCashTotal); ?>
                                                        </th>
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
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'quantity'); ?>
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'total_amount'); ?>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($startCoinDenominations)): ?>
                                                        <tr>
                                                            <td colspan="3" class="text-center text-muted">
                                                                <?php echo trans('cashflow', 'no_coin_denominations'); ?>
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($startCoinDenominations as $denom): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo (int) $denom['dcmt_quantity']; ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-light">
                                                        <th class="text-end" colspan="2">
                                                            <?php echo trans('cashflow', 'coin_total'); ?>:
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo dcmt_format_currency($startCoinTotal); ?>
                                                        </th>
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
                                    <strong><?php echo trans('cashflow', 'denominations_total'); ?>:
                                        <?php echo dcmt_format_currency($startCashTotal + $startCoinTotal); ?></strong>
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
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'quantity'); ?>
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'total_amount'); ?>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($cashDenominations)): ?>
                                                        <tr>
                                                            <td colspan="3" class="text-center text-muted">
                                                                <?php echo trans('cashflow', 'no_cash_denominations'); ?>
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($cashDenominations as $denom): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo (int) $denom['dcmt_quantity']; ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-light">
                                                        <th class="text-end" colspan="2">
                                                            <?php echo trans('cashflow', 'cash_total'); ?>:
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo dcmt_format_currency($cashTotal); ?>
                                                        </th>
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
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'quantity'); ?>
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo trans('cashflow', 'total_amount'); ?>
                                                        </th>
                                                    </tr>
                                                </thead>
                                                <tbody>
                                                    <?php if (empty($coinDenominations)): ?>
                                                        <tr>
                                                            <td colspan="3" class="text-center text-muted">
                                                                <?php echo trans('cashflow', 'no_coin_denominations'); ?>
                                                            </td>
                                                        </tr>
                                                    <?php else: ?>
                                                        <?php foreach ($coinDenominations as $denom): ?>
                                                            <tr>
                                                                <td><?php echo htmlspecialchars($denom['dcmt_denomination_label']); ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo (int) $denom['dcmt_quantity']; ?>
                                                                </td>
                                                                <td class="text-end">
                                                                    <?php echo dcmt_format_currency($denom['dcmt_total_amount']); ?>
                                                                </td>
                                                            </tr>
                                                        <?php endforeach; ?>
                                                    <?php endif; ?>
                                                </tbody>
                                                <tfoot>
                                                    <tr class="table-light">
                                                        <th class="text-end" colspan="2">
                                                            <?php echo trans('cashflow', 'coin_total'); ?>:
                                                        </th>
                                                        <th class="text-end">
                                                            <?php echo dcmt_format_currency($coinTotal); ?>
                                                        </th>
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
                                    <strong><?php echo trans('cashflow', 'denominations_total'); ?>:
                                        <?php echo dcmt_format_currency($cashTotal + $coinTotal); ?></strong>
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
