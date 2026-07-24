<?php
/**
 * Cashflow Management - Start Cash
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/cashflow_functions.php';

if (!function_exists('dcmt_cashflow_make_denom_key')) {
    function dcmt_cashflow_make_denom_key(float $value): string
    {
        return str_replace('.', '_', rtrim(rtrim(sprintf('%.2f', $value), '0'), '.'));
    }
}

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
$today = date('Y-m-d');
$selectedDate = $today;

if (isset($_GET['date'])) {
    $selectedDate = dcmt_sanitize_input($_GET['date']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $selectedDate = dcmt_sanitize_input($_POST['record_date'] ?? $today);
}

$dateError = dcmt_validate_date_field($selectedDate, 'cashflow_date');
if ($dateError) {
    $errors[] = trans('cashflow', 'invalid_date');
    $selectedDate = $today;
}

if ($selectedDate !== $today) {
    $errors[] = trans('cashflow', 'start_cash_date_today_only');
    $selectedDate = $today;
}

$prevDayClosing = dcmt_get_cashflow_starting_amount($dcmt_pdo, $selectedDate);
$existingRecord = dcmt_get_cashflow_by_date($dcmt_pdo, $selectedDate);
$existingStartDenominations = [];

// Check if there's an existing record for this date (for loading start denominations)
if ($existingRecord) {
    $existingStartDenominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, (int) $existingRecord['dcmt_id'], 'start');
}

// If there are no start denominations for today, preload from the previous day's END cash only
if (empty($existingStartDenominations)) {
    $prevId = dcmt_get_previous_cashflow_id($dcmt_pdo, $selectedDate);
    if ($prevId) {
        $prevEndDenoms = dcmt_fetch_cashflow_denominations($dcmt_pdo, $prevId, 'end');
        foreach ($prevEndDenoms as $row) {
            $existingStartDenominations[] = [
                'dcmt_denomination_value' => (float) $row['dcmt_denomination_value'],
                'dcmt_quantity' => (int) $row['dcmt_quantity'],
                'dcmt_total_amount' => (float) $row['dcmt_total_amount'],
            ];
        }
    }
}

$denominationSchema = dcmt_get_cash_denominations();
$startCashDenominations = [];
$startCoinDenominations = [];
$startCashTotal = 0.0;
$startCoinTotal = 0.0;
$postedStartDenominations = $_POST['start_denominations'] ?? [];

// Prepare start cash denominations
$startDenomByValue = [];
foreach ($existingStartDenominations as $denomRow) {
    $valueKey = (string) (float) $denomRow['dcmt_denomination_value'];
    $startDenomByValue[$valueKey] = $denomRow;
}

foreach ($denominationSchema as $schemaRow) {
    $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
    $startQuantity = 0;
    $startLineTotal = 0.0;

    // Load start cash denominations from POST data or existing record
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($postedStartDenominations[$key])) {
        $startQuantity = max(0, (int) $postedStartDenominations[$key]);
        $startLineTotal = $startQuantity * (float) $schemaRow['value'];
    } else {
        // Load from existing record
        $valueKey = (string) (float) $schemaRow['value'];
        if (isset($startDenomByValue[$valueKey])) {
            $startQuantity = (int) $startDenomByValue[$valueKey]['dcmt_quantity'];
            $startLineTotal = (float) $startDenomByValue[$valueKey]['dcmt_total_amount'];
        }
    }

    $startRow = [
        'key' => $key,
        'label' => $schemaRow['label'],
        'value' => (float) $schemaRow['value'],
        'quantity' => $startQuantity,
        'line_total' => $startLineTotal,
    ];

    if ($schemaRow['value'] > 10.0) {
        $startCashDenominations[] = $startRow;
        $startCashTotal += $startLineTotal;
    } else {
        $startCoinDenominations[] = $startRow;
        $startCoinTotal += $startLineTotal;
    }
}

$startingAmount = ($startCashTotal + $startCoinTotal > 0) ? ($startCashTotal + $startCoinTotal) : $prevDayClosing;

$csrf_token = dcmt_generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    }

    // Recalculate starting amount from start denominations
    $recalculatedStartAmount = 0.0;
    $postedStartDenoms = $_POST['start_denominations'] ?? [];
    $hasPostedDenoms = false;
    if (!empty($postedStartDenoms)) {
        foreach ($denominationSchema as $schemaRow) {
            $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
            if (isset($postedStartDenoms[$key]) && (int) $postedStartDenoms[$key] > 0) {
                $quantity = (int) $postedStartDenoms[$key];
                $recalculatedStartAmount += $quantity * (float) $schemaRow['value'];
                $hasPostedDenoms = true;
            }
        }
    }

    // Logic: If denominations are entered, they ARE the start cash. 
    // Otherwise, use previous day closing.
    $finalStartingAmount = ($hasPostedDenoms) ? round($recalculatedStartAmount, 2) : $prevDayClosing;

    /*
    // Validate that starting cash is set
    if ($finalStartingAmount <= 0) {
        $errors[] = trans('cashflow', 'start_cash_required');
    }
    */

    if (empty($errors)) {
        try {
            $dcmt_pdo->beginTransaction();

            $cashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $selectedDate);

            // At start cash stage, owner withdraw is not known yet.
            // Store zero for both cash_expense_total and owner withdraw fields.
            $cashExpenseTotal = 0.0;
            $ownerWithdrawName = '';
            $ownerWithdrawAmount = 0.0;

            // Net cashflow will be fully calculated at End Cash stage; keep zero here.
            $netCashflow = 0.0;

            // Get existing ending amount if record exists
            $endingAmount = $existingRecord ? (float) $existingRecord['dcmt_ending_amount'] : 0.0;

            // Difference will be calculated at End Cash stage; keep zero here.
            $difference = 0.0;

            if ($existingRecord) {
                // Update existing record
                $cashflowId = (int) $existingRecord['dcmt_id'];

                $stmt = $dcmt_pdo->prepare("
                    UPDATE dcmt_cashflows SET
                        dcmt_starting_amount = ?,
                        dcmt_cash_income_total = ?,
                        dcmt_cash_expense_total = ?,
                        dcmt_owner_withdraw_name = ?,
                        dcmt_owner_withdraw_amount = ?,
                        dcmt_net_cashflow = ?,
                        dcmt_ending_amount = ?,
                        dcmt_difference = ?,
                        dcmt_updated_by = ?,
                        dcmt_updated_at = CURRENT_TIMESTAMP
                    WHERE dcmt_id = ?
                ");

                $stmt->execute([
                    $finalStartingAmount,
                    $cashIncomeTotal,
                    $cashExpenseTotal,
                    $ownerWithdrawName,
                    $ownerWithdrawAmount,
                    $netCashflow,
                    $endingAmount,
                    $difference,
                    dcmt_get_current_user()['dcmt_username'] ?? 'system',
                    $cashflowId,
                ]);

                // Delete existing start denominations
                $deleteStmt = $dcmt_pdo->prepare("DELETE FROM dcmt_cashflow_denominations WHERE dcmt_cashflow_id = ? AND dcmt_denomination_type = 'start'");
                $deleteStmt->execute([$cashflowId]);
            } else {
                // Insert new record
                $stmt = $dcmt_pdo->prepare("
                    INSERT INTO dcmt_cashflows (
                        dcmt_record_date,
                        dcmt_starting_amount,
                        dcmt_cash_income_total,
                        dcmt_cash_expense_total,
                        dcmt_owner_withdraw_name,
                        dcmt_owner_withdraw_amount,
                        dcmt_net_cashflow,
                        dcmt_ending_amount,
                        dcmt_difference,
                        dcmt_created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $stmt->execute([
                    $selectedDate,
                    $finalStartingAmount,
                    $cashIncomeTotal,
                    $cashExpenseTotal,
                    $ownerWithdrawName,
                    $ownerWithdrawAmount,
                    $netCashflow,
                    $endingAmount,
                    $difference,
                    dcmt_get_current_user()['dcmt_username'] ?? 'system',
                ]);

                $cashflowId = (int) $dcmt_pdo->lastInsertId();
            }

            // Save start cash denominations
            $startDenominationStmt = $dcmt_pdo->prepare("
                INSERT INTO dcmt_cashflow_denominations (
                    dcmt_cashflow_id,
                    dcmt_denomination_type,
                    dcmt_denomination_label,
                    dcmt_denomination_value,
                    dcmt_quantity,
                    dcmt_total_amount
                ) VALUES (?, 'start', ?, ?, ?, ?)
            ");

            $startDenomsToSave = array_merge($startCashDenominations, $startCoinDenominations);
            foreach ($startDenomsToSave as $row) {
                if ($row['quantity'] > 0) {
                    $startDenominationStmt->execute([
                        $cashflowId,
                        $row['label'],
                        $row['value'],
                        $row['quantity'],
                        $row['line_total'],
                    ]);
                }
            }

            $dcmt_pdo->commit();

            dcmt_log_activity("Start cash saved for {$selectedDate}", 'cashflow_update');
            dcmt_show_message(trans('cashflow', 'start_cash_saved'), 'success');
            dcmt_redirect('index.php');
            exit();
        } catch (PDOException $e) {
            if ($dcmt_pdo->inTransaction()) {
                $dcmt_pdo->rollBack();
            }
            $errors[] = $e->getMessage();
            error_log('Start cash save failed: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('cashflow', 'start_cash'); ?></h1>
            <a href="index.php"
                class="dcmt-add-form-view-all-link"><?php echo trans('cashflow', 'view_all_cashflow_records'); ?></a>
        </div>
    </div>

    <div class="border py-2 px-3 small mb-3">
        <div class="d-flex gap-2 mb-2">
            <i class="fas fa-sun text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div><?php echo trans('cashflow', 'start_cash_help_opening'); ?></div>
        </div>
        <div class="d-flex gap-2 mb-0">
            <i class="fas fa-arrow-right text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
            <div><?php echo trans('cashflow', 'start_cash_help_after_save'); ?></div>
        </div>
    </div>

    <form method="POST" id="startCashForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <!-- Date -->
        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="record_date"
                        class="form-label"><?php echo trans('cashflow', 'cashflow_date'); ?></label>
                    <input type="date" class="form-control" id="record_date" name="record_date"
                        value="<?php echo htmlspecialchars($selectedDate); ?>"
                        min="<?php echo $today; ?>"
                        max="<?php echo $today; ?>" readonly>
                </div>
            </div>
        </div>

        <!-- Hidden input for starting amount -->
        <input type="hidden" id="starting_amount" name="starting_amount"
            value="<?php echo number_format($startingAmount, 2, '.', ''); ?>">

        <!-- Start Cash Denominations Form -->
        <div class="row" id="startCashDenominationsSection">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i
                                class="fas fa-play me-2"></i><?php echo trans('cashflow', 'start_cash_denominations'); ?>
                        </h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Cash Denominations Section -->
                            <div class="col-md-6 border-end pe-4">
                                <h6 class="mb-3 fw-bold"><?php echo trans('cashflow', 'cash_denominations'); ?></h6>
                                <?php foreach ($startCashDenominations as $row): ?>
                                    <div class="row mb-2 align-items-center">
                                        <div class="col-4">
                                            <input type="number" min="0" step="1"
                                                class="form-control form-control-sm dcmt-starting-denomination-input dcmt-cash-input"
                                                id="start_denom_<?php echo $row['key']; ?>"
                                                name="start_denominations[<?php echo $row['key']; ?>]"
                                                data-denomination-value="<?php echo $row['value']; ?>"
                                                value="<?php echo (int) $row['quantity']; ?>" placeholder="0">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label mb-0">x
                                                <?php echo htmlspecialchars($row['label']); ?></label>
                                        </div>
                                        <div class="col-4 text-end">
                                            <span class="dcmt-starting-denomination-total" data-starting-denomination-total>
                                                <?php echo dcmt_format_currency($row['line_total']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Coin Denominations Section -->
                            <div class="col-md-6 ps-4">
                                <h6 class="mb-3 fw-bold"><?php echo trans('cashflow', 'coin_denominations'); ?></h6>
                                <?php foreach ($startCoinDenominations as $row): ?>
                                    <div class="row mb-2 align-items-center">
                                        <div class="col-4">
                                            <input type="number" min="0" step="1"
                                                class="form-control form-control-sm dcmt-starting-denomination-input dcmt-coin-input"
                                                id="start_denom_<?php echo $row['key']; ?>"
                                                name="start_denominations[<?php echo $row['key']; ?>]"
                                                data-denomination-value="<?php echo $row['value']; ?>"
                                                value="<?php echo (int) $row['quantity']; ?>" placeholder="0">
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label mb-0">x
                                                <?php echo htmlspecialchars($row['label']); ?></label>
                                        </div>
                                        <div class="col-4 text-end">
                                            <span class="dcmt-starting-denomination-total" data-starting-denomination-total>
                                                <?php echo dcmt_format_currency($row['line_total']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>
                        </div>

                        <!-- Totals Row -->
                        <div class="row mt-3 pt-3 border-top">
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong><?php echo trans('cashflow', 'total_bills'); ?>:</strong>
                                    <strong
                                        id="dcmtStartCashTotalDisplay"><?php echo dcmt_format_currency($startCashTotal); ?></strong>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong><?php echo trans('cashflow', 'total_coins'); ?>:</strong>
                                    <strong
                                        id="dcmtStartCoinTotalDisplay"><?php echo dcmt_format_currency($startCoinTotal); ?></strong>
                                </div>
                            </div>
                        </div>

                        <!-- Summary Section -->
                        <div class="mt-3 pt-3 border-top">
                            <div class="row align-items-center">
                                <div class="col-md-12 text-end">
                                    <small
                                        class="text-muted d-block"><?php echo trans('cashflow', 'cash_start'); ?></small>
                                    <div class="fw-bold text-primary fs-5" id="dcmtFinalStartCashDisplay">
                                        <?php echo dcmt_format_currency($startingAmount); ?>
                                    </div>
                                    <div class="text-muted small" id="dcmtCalculationModeNote">
                                        <?php echo ($startCashTotal + $startCoinTotal > 0) ? 'Using total from denominations' : 'Using carry-over balance'; ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('cashflow', 'add_start_cash'); ?>
            </button>
        </div>
    </form>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('startCashForm');
        const submitBtn = document.getElementById('submitBtn');
        const startingAmountInput = document.getElementById('starting_amount');
        const currencyCode = <?php echo json_encode(dcmt_get_current_currency()); ?> || 'USD';
        const prevDayClosing = <?php echo number_format($prevDayClosing, 2, '.', ''); ?>;

        function formatCurrency(value) {
            const numericValue = typeof value === 'number' && !isNaN(value) ? value : 0;
            try {
                return new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode }).format(numericValue);
            } catch (error) {
                return numericValue.toFixed(2) + ' ' + currencyCode;
            }
        }

        // Update Start Cash Totals
        function updateStartCashTotals() {
            const startingDenomInputs = document.querySelectorAll('.dcmt-starting-denomination-input');
            let total = 0;
            let cashTotal = 0;
            let coinTotal = 0;

            startingDenomInputs.forEach(function (input) {
                const denominationValue = parseFloat(input.getAttribute('data-denomination-value')) || 0;
                const quantity = Math.max(0, parseInt(input.value, 10) || 0);
                const lineTotal = denominationValue * quantity;
                total += lineTotal;

                if (denominationValue > 10.0) {
                    cashTotal += lineTotal;
                } else {
                    coinTotal += lineTotal;
                }

                const row = input.closest('.row');
                if (row) {
                    const totalSpan = row.querySelector('[data-starting-denomination-total]');
                    if (totalSpan) {
                        totalSpan.textContent = formatCurrency(lineTotal);
                    }
                }
            });

            const startCashTotalDisplay = document.getElementById('dcmtStartCashTotalDisplay');
            const startCoinTotalDisplay = document.getElementById('dcmtStartCoinTotalDisplay');
            const startDenomTotalDisplay = document.getElementById('dcmtStartDenomTotalDisplay');
            const finalStartCashDisplay = document.getElementById('dcmtFinalStartCashDisplay');

            if (startCashTotalDisplay) startCashTotalDisplay.textContent = formatCurrency(cashTotal);
            if (startCoinTotalDisplay) startCoinTotalDisplay.textContent = formatCurrency(coinTotal);

            const finalStartCash = (total > 0) ? total : prevDayClosing;
            if (finalStartCashDisplay) {
                finalStartCashDisplay.textContent = formatCurrency(finalStartCash);
            }
            if (startingAmountInput) {
                startingAmountInput.value = finalStartCash.toFixed(2);
            }

            const modeNote = document.getElementById('dcmtCalculationModeNote');
            if (modeNote) {
                modeNote.textContent = (total > 0) ? 'Using total from denominations' : 'Using carry-over balance';
            }

            return total;
        }

        // Update totals when starting denominations change
        const startingDenomInputs = document.querySelectorAll('.dcmt-starting-denomination-input');
        startingDenomInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                updateStartCashTotals();
            });
        });

        // Initialize
        updateStartCashTotals();

        // Form submission loading state
        if (form && submitBtn) {
            form.addEventListener('submit', function (e) {
                const originalText = submitBtn.innerHTML;

                // Show loading state
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo addslashes(trans('common', 'processing')); ?>...';
                submitBtn.disabled = true;

                // Store original text for potential restoration
                submitBtn.setAttribute('data-original-text', originalText);
            });
        }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
