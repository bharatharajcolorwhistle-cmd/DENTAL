<?php
/**
 * Cashflow Management - End Cash
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

$existingRecord = dcmt_get_cashflow_by_date($dcmt_pdo, $selectedDate);
$startingAmount = $existingRecord ? (float) $existingRecord['dcmt_starting_amount'] : dcmt_get_cashflow_starting_amount($dcmt_pdo, $selectedDate);
$cashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $selectedDate);
$cashExpenseTotal = dcmt_calculate_cash_expense_total($dcmt_pdo, $selectedDate);
$existingDenominations = [];

if ($existingRecord) {
    $existingDenominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, (int) $existingRecord['dcmt_id'], 'end');
}

$denominationSchema = dcmt_get_cash_denominations();
$cashDenominations = [];
$coinDenominations = [];
$endingAmountNumeric = 0.0;
$cashTotal = 0.0;
$coinTotal = 0.0;
$postedDenominations = $_POST['denominations'] ?? [];

// Load existing end cash denominations
$existingByValue = [];
foreach ($existingDenominations as $denomRow) {
    $valueKey = (string) (float) $denomRow['dcmt_denomination_value'];
    $existingByValue[$valueKey] = $denomRow;
}

// Prepare denomination rows for END cash
foreach ($denominationSchema as $schemaRow) {
    $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
    $quantity = 0;
    $lineTotal = 0.0;
    
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($postedDenominations[$key])) {
        $quantity = max(0, (int) $postedDenominations[$key]);
        $lineTotal = $quantity * (float) $schemaRow['value'];
    } else {
        // Load from existing (END cash denominations)
        $valueKey = (string) (float) $schemaRow['value'];
        if (isset($existingByValue[$valueKey])) {
            $quantity = (int) $existingByValue[$valueKey]['dcmt_quantity'];
            $lineTotal = (float) $existingByValue[$valueKey]['dcmt_total_amount'];
        }
    }
    
    $row = [
        'key' => $key,
        'label' => $schemaRow['label'],
        'value' => (float) $schemaRow['value'],
        'quantity' => $quantity,
        'line_total' => $lineTotal,
    ];
    
    // Separate cash (value > 10) and coins (value <= 10, including 10)
    if ($schemaRow['value'] > 10.0) {
        $cashDenominations[] = $row;
        $cashTotal += $lineTotal;
    } else {
        $coinDenominations[] = $row;
        $coinTotal += $lineTotal;
    }
    
    $endingAmountNumeric += $lineTotal;
}

$endingAmountInput = number_format($endingAmountNumeric, 2, '.', '');
$closingBalance = $startingAmount + $endingAmountNumeric;

$csrf_token = dcmt_generate_csrf_token();
$notesInput = isset($_POST['notes']) ? dcmt_sanitize_input($_POST['notes']) : ($existingRecord['dcmt_notes'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    }

    // Recalculate total ending cash from POST denominations
    $recalculatedEndingCash = 0.0;
    $postedEndDenoms = $_POST['denominations'] ?? [];
    if (!empty($postedEndDenoms)) {
        foreach ($denominationSchema as $schemaRow) {
            $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
            if (isset($postedEndDenoms[$key])) {
                $quantity = max(0, (int) $postedEndDenoms[$key]);
                $recalculatedEndingCash += $quantity * (float) $schemaRow['value'];
            }
        }
    } else {
        $recalculatedEndingCash = $endingAmountNumeric;
    }
    
    // Validate that at least one denomination is entered
    $atLeastOneDenomination = false;
    foreach ($postedEndDenoms as $quantity) {
        if ((int) $quantity > 0) {
            $atLeastOneDenomination = true;
            break;
        }
    }

    if (!$atLeastOneDenomination) {
        $errors[] = trans('cashflow', 'end_cash_denominations_required') ?: trans('cashflow', 'denomination_required');
    }

    if (empty($errors)) {
        try {
            $dcmt_pdo->beginTransaction();

            $recalculatedCashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $selectedDate);
            $recalculatedCashExpenseTotal = dcmt_calculate_cash_expense_total($dcmt_pdo, $selectedDate);
            $netCashflow = round($recalculatedCashIncomeTotal - $recalculatedCashExpenseTotal, 2);
            
            // Calculate Closing Balance = Opening Balance + Total Ending Cash
            $finalEndingAmount = round($startingAmount + $recalculatedEndingCash, 2);
            
            // Calculate Difference based on Net Cashflow sign
            // If Net Cashflow is positive: Difference = Net Cashflow - Total Ending Cash
            // If Net Cashflow is negative: Difference = Total Ending Cash - |Net Cashflow|
            if ($netCashflow >= 0) {
                $recalculatedDifference = round($netCashflow - $recalculatedEndingCash, 2);
            } else {
                $recalculatedDifference = round($recalculatedEndingCash - abs($netCashflow), 2);
            }
            
            if ($existingRecord) {
                // Update existing record
                $cashflowId = (int) $existingRecord['dcmt_id'];
                
                $stmt = $dcmt_pdo->prepare("
                    UPDATE dcmt_cashflows SET
                        dcmt_cash_income_total = ?,
                        dcmt_cash_expense_total = ?,
                        dcmt_net_cashflow = ?,
                        dcmt_ending_amount = ?,
                        dcmt_difference = ?,
                        dcmt_notes = ?,
                        dcmt_updated_by = ?,
                        dcmt_updated_at = CURRENT_TIMESTAMP
                    WHERE dcmt_id = ?
                ");
                
                $stmt->execute([
                    $recalculatedCashIncomeTotal,
                    $recalculatedCashExpenseTotal,
                    $netCashflow,
                    $finalEndingAmount,
                    $recalculatedDifference,
                    $notesInput,
                    dcmt_get_current_user()['dcmt_username'] ?? 'system',
                    $cashflowId,
                ]);
                
                // Delete existing end denominations
                $deleteStmt = $dcmt_pdo->prepare("DELETE FROM dcmt_cashflow_denominations WHERE dcmt_cashflow_id = ? AND dcmt_denomination_type = 'end'");
                $deleteStmt->execute([$cashflowId]);
            } else {
                // Insert new record (if start cash was not set, use 0)
                $stmt = $dcmt_pdo->prepare("
                    INSERT INTO dcmt_cashflows (
                        dcmt_record_date,
                        dcmt_starting_amount,
                        dcmt_cash_income_total,
                        dcmt_cash_expense_total,
                        dcmt_net_cashflow,
                        dcmt_ending_amount,
                        dcmt_difference,
                        dcmt_notes,
                        dcmt_created_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");
                
                $stmt->execute([
                    $selectedDate,
                    $startingAmount,
                    $recalculatedCashIncomeTotal,
                    $recalculatedCashExpenseTotal,
                    $netCashflow,
                    $finalEndingAmount,
                    $recalculatedDifference,
                    $notesInput,
                    dcmt_get_current_user()['dcmt_username'] ?? 'system',
                ]);
                
                $cashflowId = (int) $dcmt_pdo->lastInsertId();
            }

            // Save end cash denominations (type='end')
            $denominationStmt = $dcmt_pdo->prepare("
                INSERT INTO dcmt_cashflow_denominations (
                    dcmt_cashflow_id,
                    dcmt_denomination_type,
                    dcmt_denomination_label,
                    dcmt_denomination_value,
                    dcmt_quantity,
                    dcmt_total_amount
                ) VALUES (?, 'end', ?, ?, ?, ?)
            ");

            foreach ($denominationSchema as $schemaRow) {
                $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
                $quantity = isset($postedEndDenoms[$key]) ? max(0, (int) $postedEndDenoms[$key]) : 0;
                
                if ($quantity > 0) {
                    $lineTotal = $quantity * (float) $schemaRow['value'];
                    $denominationStmt->execute([
                        $cashflowId,
                        $schemaRow['label'],
                        $schemaRow['value'],
                        $quantity,
                        $lineTotal,
                    ]);
                }
            }

            $dcmt_pdo->commit();

            dcmt_log_activity("End cash saved for {$selectedDate}", 'cashflow_update');
            dcmt_show_message(trans('cashflow', 'record_updated') ?: 'End cash saved successfully', 'success');
            dcmt_redirect('index.php');
            exit();
        } catch (PDOException $e) {
            if ($dcmt_pdo->inTransaction()) {
                $dcmt_pdo->rollBack();
            }
            $errors[] = $e->getMessage();
            error_log('End cash save failed: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('cashflow', 'end_cash'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('cashflow', 'view_all_cashflow_records'); ?></a>
        </div>
    </div>

    <form method="POST" id="endCashForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="cash_income_total" id="cash_income_total" value="<?php echo number_format($cashIncomeTotal, 2, '.', ''); ?>" data-amount="<?php echo number_format($cashIncomeTotal, 2, '.', ''); ?>">
        <input type="hidden" name="cash_expense_total" id="cash_expense_total" value="<?php echo number_format($cashExpenseTotal, 2, '.', ''); ?>" data-amount="<?php echo number_format($cashExpenseTotal, 2, '.', ''); ?>">

        <!-- Date -->
        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="record_date" class="form-label"><?php echo trans('cashflow', 'cashflow_date'); ?></label>
                    <input type="date" class="form-control" id="record_date" name="record_date" value="<?php echo htmlspecialchars($selectedDate); ?>">
                </div>
            </div>
        </div>

        <!-- Hidden input for ending amount -->
        <input 
            type="hidden" 
            id="ending_amount" 
            name="ending_amount" 
            value="<?php echo htmlspecialchars($endingAmountInput); ?>"
        >

        <!-- Net Cashflow Display -->
        <div class="row">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0 fw-bold"><?php echo trans('cashflow', 'net_cashflow'); ?></h6>
                    </div>
                    <div class="card-body p-4">
                        <div class="row g-4">
                            <div class="col-md-4">
                                <div class="d-flex flex-column p-3 bg-light rounded">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fas fa-arrow-down text-success me-2 fs-5"></i>
                                        <span class="text-muted small"><?php echo trans('cashflow', 'cash_inflows'); ?></span>
                                    </div>
                                    <div class="ms-4">
                                        <strong class="fs-4 text-success" id="netCashIncomeDisplay"><?php echo dcmt_format_currency($cashIncomeTotal); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex flex-column p-3 bg-light rounded">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fas fa-arrow-up text-danger me-2 fs-5"></i>
                                        <span class="text-muted small"><?php echo trans('cashflow', 'cash_outflows'); ?></span>
                                    </div>
                                    <div class="ms-4">
                                        <strong class="fs-4 text-danger" id="netCashExpenseDisplay"><?php echo dcmt_format_currency($cashExpenseTotal); ?></strong>
                                    </div>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div class="d-flex flex-column p-3 <?php echo ($cashIncomeTotal - $cashExpenseTotal) >= 0 ? 'bg-success bg-opacity-10' : 'bg-danger bg-opacity-10'; ?> rounded border <?php echo ($cashIncomeTotal - $cashExpenseTotal) >= 0 ? 'border-success' : 'border-danger'; ?>">
                                    <div class="d-flex align-items-center mb-2">
                                        <i class="fas fa-balance-scale <?php echo ($cashIncomeTotal - $cashExpenseTotal) >= 0 ? 'text-success' : 'text-danger'; ?> me-2 fs-5"></i>
                                        <span class="fw-bold small"><?php echo trans('cashflow', 'net_cashflow'); ?></span>
                                    </div>
                                    <div class="ms-4">
                                        <strong class="fs-4 <?php echo ($cashIncomeTotal - $cashExpenseTotal) >= 0 ? 'text-success' : 'text-danger'; ?>" id="netCashflowDisplay">
                                            <?php echo dcmt_format_currency($cashIncomeTotal - $cashExpenseTotal); ?>
                                        </strong>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- End Cash Denominations Form -->
        <div class="row" id="endCashDenominationsSection">
            <div class="col-12">
                <div class="card mb-3">
                    <div class="card-header">
                        <h6 class="mb-0"><i class="fas fa-stop me-2"></i><?php echo trans('cashflow', 'end_cash_denominations'); ?></h6>
                    </div>
                    <div class="card-body">
                        <div class="row">
                            <!-- Cash Denominations Section -->
                            <div class="col-md-6 border-end pe-4">
                                <h6 class="mb-3 fw-bold"><?php echo trans('cashflow', 'cash_denominations'); ?></h6>
                                <?php foreach ($cashDenominations as $row): ?>
                                    <div class="row mb-2 align-items-center">
                                        <div class="col-4">
                                            <input
                                                type="number"
                                                min="0"
                                                step="1"
                                                class="form-control form-control-sm dcmt-ending-denomination-input dcmt-cash-input"
                                                name="denominations[<?php echo $row['key']; ?>]"
                                                id="end_denom_<?php echo $row['key']; ?>"
                                                value="<?php echo (int) $row['quantity']; ?>"
                                                data-denomination-value="<?php echo $row['value']; ?>"
                                                placeholder="0"
                                            >
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label mb-0">x <?php echo htmlspecialchars($row['label']); ?></label>
                                        </div>
                                        <div class="col-4 text-end">
                                            <span class="dcmt-ending-denomination-total" data-ending-denomination-total>
                                                <?php echo dcmt_format_currency($row['line_total']); ?>
                                            </span>
                                        </div>
                                    </div>
                                <?php endforeach; ?>
                            </div>

                            <!-- Coin Denominations Section -->
                            <div class="col-md-6 ps-4">
                                <h6 class="mb-3 fw-bold"><?php echo trans('cashflow', 'coin_denominations'); ?></h6>
                                <?php foreach ($coinDenominations as $row): ?>
                                    <div class="row mb-2 align-items-center">
                                        <div class="col-4">
                                            <input
                                                type="number"
                                                min="0"
                                                step="1"
                                                class="form-control form-control-sm dcmt-ending-denomination-input dcmt-coin-input"
                                                name="denominations[<?php echo $row['key']; ?>]"
                                                id="end_denom_<?php echo $row['key']; ?>"
                                                value="<?php echo (int) $row['quantity']; ?>"
                                                data-denomination-value="<?php echo $row['value']; ?>"
                                                placeholder="0"
                                            >
                                        </div>
                                        <div class="col-4">
                                            <label class="form-label mb-0">x <?php echo htmlspecialchars($row['label']); ?></label>
                                        </div>
                                        <div class="col-4 text-end">
                                            <span class="dcmt-ending-denomination-total" data-ending-denomination-total>
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
                                    <strong id="dcmtEndCashTotalDisplay"><?php echo dcmt_format_currency($cashTotal); ?></strong>
                                </div>
                            </div>
                            <div class="col-md-6">
                                <div class="d-flex justify-content-between align-items-center">
                                    <strong><?php echo trans('cashflow', 'total_coins'); ?>:</strong>
                                    <strong id="dcmtEndCoinTotalDisplay"><?php echo dcmt_format_currency($coinTotal); ?></strong>
                                </div>
                            </div>
                        </div>
                        
                        <!-- Summary Section -->
                        <div class="mt-3 pt-3 border-top">
                            <div class="d-flex justify-content-between align-items-center">
                                <strong><?php echo trans('cashflow', 'total_ending_cash'); ?>:</strong>
                                <strong class="fs-4 text-success" id="dcmtEndTotalDisplay"><?php echo dcmt_format_currency($endingAmountNumeric); ?></strong>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Notes -->
        <div class="row">
            <div class="col-12">
                <div class="mb-3">
                    <label for="notes" class="form-label"><?php echo trans('cashflow', 'notes'); ?></label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($notesInput); ?></textarea>
                </div>
            </div>
        </div>

        <!-- Form Actions -->
        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('cashflow', 'add_end_cash'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('endCashForm');
    const submitBtn = document.getElementById('submitBtn');
    const endingHiddenInput = document.getElementById('ending_amount');
    const cashIncomeField = document.getElementById('cash_income_total');
    const cashExpenseField = document.getElementById('cash_expense_total');
    const startingAmount = <?php echo number_format($startingAmount, 2, '.', ''); ?>;
    const currencyCode = <?php echo json_encode(dcmt_get_current_currency()); ?> || 'USD';

    function formatCurrency(value) {
        const numericValue = typeof value === 'number' && !isNaN(value) ? value : 0;
        try {
            return new Intl.NumberFormat(undefined, { style: 'currency', currency: currencyCode }).format(numericValue);
        } catch (error) {
            return numericValue.toFixed(2) + ' ' + currencyCode;
        }
    }

    function updateNetCashflow() {
        if (!cashIncomeField || !cashExpenseField) {
            return;
        }

        const cashIncomeTotal = parseFloat(cashIncomeField.getAttribute('data-amount')) || 0;
        const cashExpenseTotal = parseFloat(cashExpenseField.getAttribute('data-amount')) || 0;
        const netCashflow = cashIncomeTotal - cashExpenseTotal;
        
        // Update net cashflow display
        const netCashIncomeDisplay = document.getElementById('netCashIncomeDisplay');
        const netCashExpenseDisplay = document.getElementById('netCashExpenseDisplay');
        const netCashflowDisplay = document.getElementById('netCashflowDisplay');
        const netCashflowContainer = netCashflowDisplay ? netCashflowDisplay.closest('.col-md-4') : null;
        const netCashflowBox = netCashflowContainer ? netCashflowContainer.querySelector('.d-flex.flex-column') : null;
        const netCashflowIcon = netCashflowBox ? netCashflowBox.querySelector('i') : null;
        
        if (netCashIncomeDisplay) {
            netCashIncomeDisplay.textContent = formatCurrency(cashIncomeTotal);
        }
        if (netCashExpenseDisplay) {
            netCashExpenseDisplay.textContent = formatCurrency(cashExpenseTotal);
        }
        if (netCashflowDisplay) {
            netCashflowDisplay.textContent = formatCurrency(netCashflow);
            
            // Update color and styling based on positive/negative
            if (netCashflow >= 0) {
                netCashflowDisplay.classList.remove('text-danger');
                netCashflowDisplay.classList.add('text-success');
                if (netCashflowBox) {
                    netCashflowBox.classList.remove('bg-danger', 'bg-opacity-10', 'border-danger');
                    netCashflowBox.classList.add('bg-success', 'bg-opacity-10', 'border-success');
                }
                if (netCashflowIcon) {
                    netCashflowIcon.classList.remove('text-danger');
                    netCashflowIcon.classList.add('text-success');
                }
            } else {
                netCashflowDisplay.classList.remove('text-success');
                netCashflowDisplay.classList.add('text-danger');
                if (netCashflowBox) {
                    netCashflowBox.classList.remove('bg-success', 'bg-opacity-10', 'border-success');
                    netCashflowBox.classList.add('bg-danger', 'bg-opacity-10', 'border-danger');
                }
                if (netCashflowIcon) {
                    netCashflowIcon.classList.remove('text-success');
                    netCashflowIcon.classList.add('text-danger');
                }
            }
        }
    }

    // Update End Cash Totals
    function updateEndCashTotals() {
        const endingDenomInputs = document.querySelectorAll('.dcmt-ending-denomination-input');
        let total = 0;
        let cashTotal = 0;
        let coinTotal = 0;
        
        endingDenomInputs.forEach(function(input) {
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
                const totalSpan = row.querySelector('[data-ending-denomination-total]');
                if (totalSpan) {
                    totalSpan.textContent = formatCurrency(lineTotal);
                }
            }
        });
        
        const endCashTotalDisplay = document.getElementById('dcmtEndCashTotalDisplay');
        const endCoinTotalDisplay = document.getElementById('dcmtEndCoinTotalDisplay');
        const endTotalDisplay = document.getElementById('dcmtEndTotalDisplay');
        
        if (endCashTotalDisplay) endCashTotalDisplay.textContent = formatCurrency(cashTotal);
        if (endCoinTotalDisplay) endCoinTotalDisplay.textContent = formatCurrency(coinTotal);
        if (endTotalDisplay) endTotalDisplay.textContent = formatCurrency(total);
        
        // Update ending amount
        if (endingHiddenInput) {
            endingHiddenInput.value = total.toFixed(2);
        }
        
        return total;
    }

    // Update totals when ending denominations change
    const endingDenomInputs = document.querySelectorAll('.dcmt-ending-denomination-input');
    endingDenomInputs.forEach(function(input) {
        input.addEventListener('input', function() {
            updateEndCashTotals();
        });
    });

    // Initialize
    updateEndCashTotals();
    updateNetCashflow();

    // Form submission loading state
    if (form && submitBtn) {
        form.addEventListener('submit', function(e) {
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

