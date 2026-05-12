<?php
/**
 * Cashflow Management - Index
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

$errors = [];
$records = [];
$total_records = 0;
$total_cash_inflows = 0.0;
$total_cash_outflows = 0.0;
$total_net_cash = 0.0;
$total_pages = 0;
$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$per_page = DCMT_PER_PAGE;

$defaultStart = date('Y-m-01');
$defaultEnd = date('Y-m-t');
$defaultDateRange = $defaultStart . ' to ' . $defaultEnd;

$date_range = dcmt_sanitize_input($_GET['date_range'] ?? '');

// Parse date range
$startDateInput = $defaultStart;
$endDateInput = $defaultEnd;
if (!empty($date_range) && strpos($date_range, ' to ') !== false) {
    $dates = explode(' to ', $date_range);
    if (count($dates) === 2) {
        $startDateInput = trim($dates[0]);
        $endDateInput = trim($dates[1]);
    }
} elseif (empty($date_range)) {
    $date_range = $defaultDateRange;
}

$startDateError = dcmt_validate_date_field($startDateInput, 'start_date');
$endDateError = dcmt_validate_date_field($endDateInput, 'end_date');

if ($startDateError) {
    $errors[] = $startDateError;
}

if ($endDateError) {
    $errors[] = $endDateError;
}

if (empty($errors) && $startDateInput > $endDateInput) {
    $errors[] = trans('cashflow', 'invalid_date_range');
}

if (empty($errors)) {
    try {
        $count_stmt = $dcmt_pdo->prepare("
            SELECT COUNT(*) FROM dcmt_cashflows 
            WHERE dcmt_record_date BETWEEN ? AND ? 
        ");
        $count_stmt->execute([$startDateInput, $endDateInput]);
        $total_records = (int) $count_stmt->fetchColumn();
        $total_pages = $total_records > 0 ? (int) ceil($total_records / $per_page) : 0;
        if ($total_pages > 0 && $page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        $stmt = $dcmt_pdo->prepare("
            SELECT * FROM dcmt_cashflows 
            WHERE dcmt_record_date BETWEEN ? AND ? 
            ORDER BY dcmt_record_date ASC
            LIMIT " . (int) $per_page . " OFFSET " . (int) $offset . "
        ");
        $stmt->execute([$startDateInput, $endDateInput]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // Aggregate totals for the full filtered range (not just current page).
        $summary_stmt = $dcmt_pdo->prepare("
            SELECT dcmt_record_date, dcmt_starting_amount, dcmt_owner_withdraw_amount
            FROM dcmt_cashflows
            WHERE dcmt_record_date BETWEEN ? AND ?
        ");
        $summary_stmt->execute([$startDateInput, $endDateInput]);
        $summary_rows = $summary_stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($summary_rows as $summary_row) {
            $summary_date = (string)($summary_row['dcmt_record_date'] ?? '');
            if ($summary_date === '') {
                continue;
            }
            $summary_starting = (float)($summary_row['dcmt_starting_amount'] ?? 0);
            $summary_inflow = dcmt_calculate_cash_income_total($dcmt_pdo, $summary_date);
            $summary_outflow = (float)($summary_row['dcmt_owner_withdraw_amount'] ?? 0);
            $summary_net = $summary_starting + $summary_inflow - $summary_outflow;

            $total_cash_inflows += $summary_inflow;
            $total_cash_outflows += $summary_outflow;
            $total_net_cash += $summary_net;
        }
        
        // Always recalculate real-time cash totals for display
        foreach ($records as &$record) {
            $recordDate = $record['dcmt_record_date'];

            $cashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $recordDate);
            $ownerWithdrawAmount = (float) ($record['dcmt_owner_withdraw_amount'] ?? 0);

            // Net cashflow now includes start cash directly:
            // Net Cashflow = Start Cash + Cash Inflow − Cash Outflow (Owner Withdraw)
            $startingAmount = (float) ($record['dcmt_starting_amount'] ?? 0);
            $netCashflow = round($startingAmount + $cashIncomeTotal - $ownerWithdrawAmount, 2);

            $record['dcmt_cash_income_total'] = $cashIncomeTotal;
            $record['dcmt_cash_expense_total'] = $ownerWithdrawAmount;
            $record['dcmt_owner_withdraw_amount'] = $ownerWithdrawAmount;
            $record['dcmt_net_cashflow'] = $netCashflow;
            
            $endDenominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, $record['dcmt_id'], 'end');
            $totalEndingCash = 0.0;
            foreach ($endDenominations as $denom) {
                $totalEndingCash += (float) $denom['dcmt_total_amount'];
            }
            $record['total_ending_cash'] = round($totalEndingCash, 2);

            $expectedEndingCash = $netCashflow;
            $record['dcmt_difference'] = round($record['total_ending_cash'] - $expectedEndingCash, 2);
        }
        unset($record);
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
        error_log('Cashflow fetch failed: ' . $e->getMessage());
    }
}

// Check if start cash and end cash have been added for today
// Primary date source is app timezone (America/Mexico_City). Add fallbacks to avoid timezone mismatches
// when the client/server stores a record with a different "today" date.
$today = dcmt_get_current_date();
$todayRecord = null;
$todayCandidates = [$today];
$utcToday = gmdate(DCMT_DATE_FORMAT);
if ($utcToday !== $today) {
    $todayCandidates[] = $utcToday;
}
$serverToday = date(DCMT_DATE_FORMAT);
if (!in_array($serverToday, $todayCandidates, true)) {
    $todayCandidates[] = $serverToday;
}

foreach ($todayCandidates as $candidateDate) {
    $candidateRecord = dcmt_get_cashflow_by_date($dcmt_pdo, $candidateDate);
    if ($candidateRecord) {
        $today = $candidateDate;
        $todayRecord = $candidateRecord;
        break;
    }
}
$startCashAdded = false;
$endCashAdded = false;

if ($todayRecord) {
    $startCashAdded = true;
    $todayCashflowId = (int) ($todayRecord['dcmt_id'] ?? 0);
    $endDenominations = $todayCashflowId ? dcmt_fetch_cashflow_denominations($dcmt_pdo, $todayCashflowId, 'end') : [];

    $endCashAdded = !empty($endDenominations)
        || (isset($todayRecord['dcmt_status']) && $todayRecord['dcmt_status'] === 'closed')
        || !empty(trim((string) ($todayRecord['dcmt_owner_withdraw_name'] ?? '')))
        || ((float) ($todayRecord['dcmt_owner_withdraw_amount'] ?? 0) > 0)
        || !empty(trim((string) ($todayRecord['dcmt_notes'] ?? '')))
        || (isset($todayRecord['dcmt_difference']) && (float) $todayRecord['dcmt_difference'] != 0.0)
        || (isset($todayRecord['dcmt_ending_amount']) && (float) $todayRecord['dcmt_ending_amount'] != 0.0);
}

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sub_header.php';
?>

<div class="container-fluid mt-4">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <div class="card mb-4 dcmt-filter-form">
        <div class="card-body">
            <form method="GET" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="date_range" class="form-label"><?php echo trans('cashflow', 'date_range'); ?></label>
                    <input type="text" class="form-control dcmt-daterange-picker dcmt-filter-field" id="date_range" name="date_range" 
                           value="<?php echo htmlspecialchars($date_range); ?>"
                           placeholder="<?php echo trans('cashflow', 'select_date_range'); ?>"
                           readonly>
                </div>
                <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                    <button type="submit" class="dcmt-filter-btn">
                        <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                    </button>
                    <a href="index.php" class="dcmt-add-form-view-all-link text-center">
                        <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                    </a>
                </div>
                <div class="col-md-auto ms-auto d-flex flex-column gap-2 align-items-stretch">
                    <a href="start_cash.php" 
                       id="startCashBtn" 
                       class="btn btn-primary<?php echo $startCashAdded ? ' disabled' : ''; ?>" 
                       <?php if ($startCashAdded): ?>
                           onclick="return false;" 
                           style="pointer-events: none; opacity: 0.2; cursor: not-allowed;"
                           title="<?php echo trans('cashflow', 'start_cash_already_added') ?: 'Start cash has already been added for today'; ?>"
                       <?php endif; ?>>
                        <i class="fas fa-play me-1"></i><?php echo trans('cashflow', 'start_cash'); ?>
                    </a>
                    <?php
                        // Disable End Cash if already added OR start cash not added yet
                        $disableEndCash = $endCashAdded || !$startCashAdded;
                        $endCashTitle = '';
                        if ($endCashAdded) {
                            $endCashTitle = trans('cashflow', 'end_cash_already_added') ?: 'End cash has already been added for today';
                        } elseif (!$startCashAdded) {
                            $endCashTitle = trans('cashflow', 'start_cash_required') ?: 'Please add start cash first';
                        }
                    ?>
                    <a href="end_cash.php" 
                       id="endCashBtn" 
                       class="btn btn-success<?php echo $disableEndCash ? ' disabled' : ''; ?>" 
                       <?php if ($disableEndCash): ?>
                           onclick="return false;" 
                           style="pointer-events: none; opacity: 0.2; cursor: not-allowed;"
                           title="<?php echo htmlspecialchars($endCashTitle); ?>"
                       <?php endif; ?>>
                        <i class="fas fa-stop me-1"></i><?php echo trans('cashflow', 'end_cash'); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <div class="card dcmt-records-table">
        <div class="card-header dcmt-view-card-header">
            <div class="dcmt-view-card-header-content">
                <div>
                    <h5 class="mb-2 dcmt-view-card-title">
                        <?php echo trans('cashflow', 'cashflow_records'); ?>
                        <span class="ms-3 dcmt-view-card-title-total">
                            (<?php echo trans('income', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('income', 'records'); ?> | Net Cash: <span style="color: #198754; font-weight: 600;"><?php echo number_format($total_net_cash, 2); ?> <?php echo htmlspecialchars(dcmt_get_current_currency()); ?></span>)
                        </span>
                    </h5>
                </div>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($records)): ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i><?php echo trans('cashflow', 'no_records'); ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th><?php echo trans('cashflow', 'date'); ?></th>
                                <th><?php echo trans('cashflow', 'opening_balance'); ?></th>
                                <th><?php echo trans('cashflow', 'cash_inflows'); ?></th>
                                <th><?php echo trans('cashflow', 'cash_outflows'); ?></th>
                                <th><?php echo trans('cashflow', 'net_cashflow'); ?></th>
                                <th><?php echo trans('cashflow', 'end_cash'); ?></th>
                                <th><?php echo trans('cashflow', 'status'); ?></th>
                                <th><?php echo trans('common', 'actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($records as $record): ?>
                                <?php
                                    // All Cash Reconciliation Report fields are stored in database
                                    $openingBalance = (float) $record['dcmt_starting_amount']; // Opening Balance
                                    $cashInflows = (float) $record['dcmt_cash_income_total']; // Cash Inflow
                                    $cashOutflows = (float) ($record['dcmt_owner_withdraw_amount'] ?? 0); // Cash Outflow (Owner Withdraw)
                                    $netCashflow = (float) ($record['dcmt_net_cashflow'] ?? ($openingBalance + $cashInflows - $cashOutflows)); // Net Cashflow
                                    // Use Total Ending Cash calculated from denominations, fallback to stored ending_amount
                                    $endCash = (float) ($record['total_ending_cash'] ?? $record['dcmt_ending_amount'] ?? 0); // Total Ending Cash
                                    
                                    // Calculate status from stored difference
                                    $difference = (float) ($record['dcmt_difference'] ?? 0);
                                    $isBalanced = abs($difference) < 0.01;
                                ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($record['dcmt_record_date']); ?></td>
                                    <td><?php echo dcmt_format_currency($openingBalance); ?></td>
                                    <td class="text-success"><?php echo dcmt_format_currency($cashInflows); ?></td>
                                    <td class="text-danger"><?php echo dcmt_format_currency($cashOutflows); ?></td>
                                    <td class="<?php echo $netCashflow >= 0 ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo dcmt_format_currency($netCashflow); ?>
                                    </td>
                                    <td><?php echo dcmt_format_currency($endCash); ?></td>
                                    <td>
                                        <span class="<?php echo $isBalanced ? 'text-success' : 'text-warning'; ?>">
                                            <?php echo $isBalanced ? trans('cashflow', 'balanced') : trans('cashflow', 'attention_needed'); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $record['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="<?php echo trans('common', 'view'); ?>">
                                        </a>
                                        <?php if (dcmt_is_admin()): ?>
                                            <a href="edit.php?id=<?php echo $record['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="<?php echo trans('common', 'edit'); ?>">
                                        </a>
                                        <a href="delete.php?id=<?php echo $record['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                           onclick="confirmDelete(<?php echo (int)$record['dcmt_id']; ?>, 'cashflow_record'); return false;">
                                            <img src="../../assets/images/delete.svg" alt="<?php echo trans('common', 'delete'); ?>">
                                        </a>
                                        <?php endif; ?>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                        <tfoot>
                            <tr class="table-light">
                                <td colspan="8">
                                    <span class="dcmt-view-card-title-total">
                                        <?php echo trans('income', 'showing'); ?>: <span style="color: #0d6efd; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('income', 'records'); ?> | Net Cash: <span style="color: #198754; font-weight: 600;"><?php echo number_format($total_net_cash, 2); ?> <?php echo htmlspecialchars(dcmt_get_current_currency()); ?></span>
                                    </span>
                                </td>
                            </tr>
                        </tfoot>
                    </table>
                </div>
                <?php if ($total_pages > 1): ?>
                    <nav aria-label="<?php echo trans('cashflow', 'cashflow_records_pagination'); ?>">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                        <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                        <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                        <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" title="<?php echo trans('common', 'last_page'); ?>">
                                        <?php echo trans('common', 'last_page'); ?> <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<script>
// Delete modal translations for cashflow
window.translations = Object.assign({}, window.translations || {}, {
    confirm_deletion: '<?php echo trans('common', 'confirm_deletion') ?: trans('common', 'confirm_delete'); ?>',
    warning: '<?php echo trans('common', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('cashflow', 'delete_confirmation_message') ?: trans('common', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('common', 'cancel'); ?>',
    yes_delete: '<?php echo trans('common', 'yes_delete'); ?>',
    cashflow_record: '<?php echo trans('cashflow', 'cashflow'); ?>'
});

// Initialize date range picker
$(document).ready(function() {
    $('#date_range').daterangepicker({
        autoUpdateInput: false,
        autoApply: true,
        startDate: moment('<?php echo $startDateInput; ?>'),
        endDate: moment('<?php echo $endDateInput; ?>'),
        locale: {
            cancelLabel: '<?php echo trans('common', 'clear'); ?>',
            applyLabel: '<?php echo trans('common', 'apply'); ?>',
            format: 'YYYY-MM-DD',
            separator: ' to ',
            customRangeLabel: '<?php echo trans('common', 'custom_range'); ?>'
        },
        ranges: {
            '<?php echo trans('common', 'today'); ?>': [moment(), moment()],
            '<?php echo trans('common', 'yesterday'); ?>': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            '<?php echo trans('common', 'last_7_days'); ?>': [moment().subtract(6, 'days'), moment()],
            '<?php echo trans('common', 'last_30_days'); ?>': [moment().subtract(29, 'days'), moment()],
            '<?php echo trans('common', 'this_month'); ?>': [moment().startOf('month'), moment().endOf('month')],
            '<?php echo trans('common', 'last_month'); ?>': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
            '<?php echo trans('common', 'this_year'); ?>': [moment().startOf('year'), moment().endOf('year')],
            '<?php echo trans('common', 'last_year'); ?>': [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
        },
        opens: 'left',
        drops: 'down'
    });

    $('#date_range').on('apply.daterangepicker', function(ev, picker) {
        $(this).val(picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format('YYYY-MM-DD'));
    });

    $('#date_range').on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
    });

    // Set initial value if date range is already set
    <?php if (!empty($date_range)): ?>
    $('#date_range').val('<?php echo htmlspecialchars($date_range); ?>');
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
