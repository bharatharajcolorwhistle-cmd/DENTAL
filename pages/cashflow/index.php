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
$summary = [
    'days_recorded' => 0,
    'starting_total' => 0.0,
    'cash_income_total' => 0.0,
    'ending_total' => 0.0,
    'difference_total' => 0.0,
];

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
        $stmt = $dcmt_pdo->prepare("
            SELECT * FROM dcmt_cashflows 
            WHERE dcmt_record_date BETWEEN ? AND ? 
            ORDER BY dcmt_record_date ASC
        ");
        $stmt->execute([$startDateInput, $endDateInput]);
        $records = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        // Use stored values from database - all Cash Reconciliation Report fields are stored
        // Backward compatibility: recalculate and update if any fields are missing
        foreach ($records as &$record) {
            $needsUpdate = false;
            $updateFields = [];
            $updateValues = [];
            
            // Check and recalculate cash income total if needed
            if (!isset($record['dcmt_cash_income_total']) || $record['dcmt_cash_income_total'] == 0) {
                $record['dcmt_cash_income_total'] = dcmt_calculate_cash_income_total($dcmt_pdo, $record['dcmt_record_date']);
                $updateFields[] = 'dcmt_cash_income_total = ?';
                $updateValues[] = $record['dcmt_cash_income_total'];
                $needsUpdate = true;
            }
            
            // Check and recalculate cash expense total if needed
            if (!isset($record['dcmt_cash_expense_total']) || $record['dcmt_cash_expense_total'] == 0) {
                $record['dcmt_cash_expense_total'] = dcmt_calculate_cash_expense_total($dcmt_pdo, $record['dcmt_record_date']);
                $updateFields[] = 'dcmt_cash_expense_total = ?';
                $updateValues[] = $record['dcmt_cash_expense_total'];
                $needsUpdate = true;
            }
            
            // Calculate net cashflow if missing
            if (!isset($record['dcmt_net_cashflow']) || $record['dcmt_net_cashflow'] == 0) {
                $record['dcmt_net_cashflow'] = round((float) $record['dcmt_cash_income_total'] - (float) $record['dcmt_cash_expense_total'], 2);
                $updateFields[] = 'dcmt_net_cashflow = ?';
                $updateValues[] = $record['dcmt_net_cashflow'];
                $needsUpdate = true;
            }
            
            // Calculate Total Ending Cash from denominations
            $endDenominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, $record['dcmt_id'], 'end');
            $totalEndingCash = 0.0;
            foreach ($endDenominations as $denom) {
                $totalEndingCash += (float) $denom['dcmt_total_amount'];
            }
            $record['total_ending_cash'] = round($totalEndingCash, 2);
            
            // Update database if any fields were missing
            if ($needsUpdate) {
                $updateValues[] = $record['dcmt_id'];
                $updateStmt = $dcmt_pdo->prepare("
                    UPDATE dcmt_cashflows 
                    SET " . implode(', ', $updateFields) . "
                    WHERE dcmt_id = ?
                ");
                $updateStmt->execute($updateValues);
            }
        }
        unset($record);
        
        $summary = dcmt_build_cashflow_summary($records);
    } catch (PDOException $e) {
        $errors[] = $e->getMessage();
        error_log('Cashflow fetch failed: ' . $e->getMessage());
    }
}

// Check if start cash and end cash have been added for today (using Mexican timezone)
// The timezone is already set to 'America/Mexico_City' in config.php
$today = dcmt_get_current_date(); // This uses the Mexican timezone set in config.php
$todayRecord = dcmt_get_cashflow_by_date($dcmt_pdo, $today);
$startCashAdded = false;
$endCashAdded = false;

if ($todayRecord) {
    $startCashAdded = (float) ($todayRecord['dcmt_starting_amount'] ?? 0) > 0;
    $endCashAdded = (float) ($todayRecord['dcmt_ending_amount'] ?? 0) > 0;
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
                    <h5 class="mb-2"><?php echo trans('cashflow', 'cashflow_records'); ?></h5>
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
                                    $cashOutflows = (float) ($record['dcmt_cash_expense_total'] ?? 0); // Cash Outflow
                                    $netCashflow = (float) ($record['dcmt_net_cashflow'] ?? ($cashInflows - $cashOutflows)); // Net Cashflow
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
                    </table>
                </div>
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

