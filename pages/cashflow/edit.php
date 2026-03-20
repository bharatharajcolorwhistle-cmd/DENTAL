<?php
/**
 * Edit Cashflow Page
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

dcmt_require_admin();

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

$errors = [];
$selectedDate = $cashflow['dcmt_record_date'];
$startingAmount = (float) $cashflow['dcmt_starting_amount'];
$prevDayClosing = dcmt_get_cashflow_starting_amount($dcmt_pdo, $selectedDate);
$storedCashIncomeTotal = (float) ($cashflow['dcmt_cash_income_total'] ?? 0);
$storedCashExpenseTotal = (float) ($cashflow['dcmt_cash_expense_total'] ?? 0);
$storedOwnerWithdrawName = $cashflow['dcmt_owner_withdraw_name'] ?? '';
$storedOwnerWithdrawAmount = (float) ($cashflow['dcmt_owner_withdraw_amount'] ?? 0);
$default_doctor_name = '';
$doctors = [];
try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'default_doctor_user_id'");
    $stmt->execute();
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($row && !empty($row['dcmt_setting_value'])) {
        $uid = (int) $row['dcmt_setting_value'];
        $name_stmt = $dcmt_pdo->prepare("SELECT dcmt_full_name FROM dcmt_users WHERE dcmt_id = ? AND dcmt_role = 'doctor' LIMIT 1");
        $name_stmt->execute([$uid]);
        $user_row = $name_stmt->fetch(PDO::FETCH_ASSOC);
        if ($user_row) {
            $default_doctor_name = trim($user_row['dcmt_full_name'] ?? '');
        }
    }

    $doctor_stmt = $dcmt_pdo->prepare("SELECT dcmt_full_name AS dcmt_name FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name");
    $doctor_stmt->execute();
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Cashflow edit default doctor: " . $e->getMessage());
}
$displayOwnerWithdrawName = ($storedOwnerWithdrawName !== '') ? $storedOwnerWithdrawName : $default_doctor_name;

$cashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $selectedDate);
// For display, treat cash expense as the Owner Withdraw amount
$cashExpenseTotal = $storedOwnerWithdrawAmount;
$existingDenominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, $cashflow_id, 'end');
$existingStartDenominations = dcmt_fetch_cashflow_denominations($dcmt_pdo, $cashflow_id, 'start');

$denominationSchema = dcmt_get_cash_denominations();
$denominationRows = [];
$startCashDenominations = [];
$startCoinDenominations = [];
$cashDenominations = [];
$coinDenominations = [];
// Calculate ending amount from existing denominations (not from closing balance)
$endingAmountNumeric = 0.0;
foreach ($existingDenominations as $denomRow) {
    $endingAmountNumeric += (float) $denomRow['dcmt_total_amount'];
}
$cashTotal = 0.0;
$coinTotal = 0.0;
$startCashTotal = 0.0;
$startCoinTotal = 0.0;
$postedDenominations = $_POST['denominations'] ?? [];
$postedStartDenominations = $_POST['start_denominations'] ?? [];

// Reset totals if loading from POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $startCashTotal = 0.0;
    $startCoinTotal = 0.0;
}

// Load existing end cash denominations
$existingByValue = [];
foreach ($existingDenominations as $denomRow) {
    $valueKey = (string) (float) $denomRow['dcmt_denomination_value'];
    $existingByValue[$valueKey] = $denomRow;
}

// Load existing start cash denominations
$startDenomByValue = [];
foreach ($existingStartDenominations as $denomRow) {
    $valueKey = (string) (float) $denomRow['dcmt_denomination_value'];
    $startDenomByValue[$valueKey] = $denomRow;
}

// Prepare start cash denominations (for Start Cash section)
foreach ($denominationSchema as $schemaRow) {
    $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
    $startQuantity = 0;
    $startLineTotal = 0.0;

    // Load start cash denominations from POST data (form submission) or existing record
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($postedStartDenominations[$key])) {
        $startQuantity = max(0, (int) $postedStartDenominations[$key]);
        $startLineTotal = $startQuantity * (float) $schemaRow['value'];
    } else {
        // Load from existing start cash denominations
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

// Prepare denomination rows for END cash (stored denominations)
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
    $denominationRows[] = $row;

    // Separate cash (value > 10) and coins (value <= 10, including 10)
    if ($schemaRow['value'] > 10.0) {
        $cashDenominations[] = $row;
        $cashTotal += $lineTotal;
    } else {
        $coinDenominations[] = $row;
        $coinTotal += $lineTotal;
    }
}

// Recalculate ending amount from denominations if POST
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $endingAmountNumeric = 0.0;
    $cashTotal = 0.0;
    $coinTotal = 0.0;
    foreach ($denominationRows as $row) {
        $endingAmountNumeric += $row['line_total'];
        if ($row['value'] > 10.0) {
            $cashTotal += $row['line_total'];
        } else {
            $coinTotal += $row['line_total'];
        }
    }
}

$endingAmountInput = number_format($endingAmountNumeric, 2, '.', '');

$notesInput = isset($_POST['notes']) ? dcmt_sanitize_input($_POST['notes']) : ($cashflow['dcmt_notes'] ?? '');
$csrf_token = dcmt_generate_csrf_token();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    }

    // Recalculate starting amount from start denominations using same core logic as Start Cash form:
    // If denominations are entered (any quantity > 0), they ARE the start cash.
    // Otherwise, use the current starting amount value from the form or existing record.
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

    if ($hasPostedDenoms) {
        $finalStartingAmount = round($recalculatedStartAmount, 2);
    } else {
        $finalStartingAmount = isset($_POST['starting_amount']) ? (float) $_POST['starting_amount'] : $startingAmount;
    }

    // Validate that starting cash is set
    if ($finalStartingAmount < 0) {
        $errors[] = trans('cashflow', 'start_cash_required');
    }

    // Recalculate total ending cash from POST denominations
    $recalculatedEndingCash = 0.0;
    $postedEndDenoms = $_POST['denominations'] ?? [];
    if (!empty($postedEndDenoms)) {
        // Calculate from end denominations in POST
        foreach ($denominationSchema as $schemaRow) {
            $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
            if (isset($postedEndDenoms[$key])) {
                $quantity = max(0, (int) $postedEndDenoms[$key]);
                $recalculatedEndingCash += $quantity * (float) $schemaRow['value'];
            }
        }
    } else {
        // Use existing denominations total
        $recalculatedEndingCash = $endingAmountNumeric;
    }

    // Get actual end cash from denominations (for difference calculation)
    $actualEndCashFromDenominations = isset($_POST['ending_amount']) ? (float) $_POST['ending_amount'] : $recalculatedEndingCash;

    $atLeastOneDenomination = false;
    foreach ($denominationRows as $row) {
        if ($row['quantity'] > 0) {
            $atLeastOneDenomination = true;
            break;
        }
    }

    /*
    if (!$atLeastOneDenomination) {
        $errors[] = trans('cashflow', 'denomination_required');
    }
    */

    // Server-side validation: Owner Withdraw must not exceed Start Cash + Cash Inflows
    if (empty($errors)) {
        $recalculatedCashIncomeTotalCheck = dcmt_calculate_cash_income_total($dcmt_pdo, $selectedDate);
        $ownerWithdrawAmountInputCheck = isset($_POST['owner_withdraw_amount']) ? (float) $_POST['owner_withdraw_amount'] : $storedOwnerWithdrawAmount;
        $ownerWithdrawAmountInputCheck = max(0.0, $ownerWithdrawAmountInputCheck);
        $availableTotalCheck = $finalStartingAmount + $recalculatedCashIncomeTotalCheck;

        if ($ownerWithdrawAmountInputCheck > $availableTotalCheck) {
            $errors[] = trans('cashflow', 'withdraw_amount_exceeds_total');
        }
    }

    if (empty($errors)) {
        try {
            $dcmt_pdo->beginTransaction();

            // Recalculate all Cash Reconciliation Report fields
            $recalculatedCashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $selectedDate);

            // Owner Withdraw is now the only cash outflow for cashflow calculations
            $ownerWithdrawNameInput = dcmt_sanitize_input($_POST['owner_withdraw_name'] ?? $storedOwnerWithdrawName);
            $ownerWithdrawAmountInput = isset($_POST['owner_withdraw_amount']) ? (float) $_POST['owner_withdraw_amount'] : $storedOwnerWithdrawAmount;
            $ownerWithdrawAmountInput = max(0.0, $ownerWithdrawAmountInput);
            $recalculatedCashExpenseTotal = $ownerWithdrawAmountInput;

            // Net cashflow now includes start cash directly:
            // Net Cashflow = Start Cash + Cash Inflow − Cash Outflow (Owner Withdraw)
            $netCashflow = round($finalStartingAmount + $recalculatedCashIncomeTotal - $recalculatedCashExpenseTotal, 2);

            // Calculate Total Ending Cash from denominations (sum of all end cash denominations)
            // Recalculate from POST denominations to ensure accuracy
            $totalEndingCash = 0.0;
            $postedEndDenoms = $_POST['denominations'] ?? [];
            if (!empty($postedEndDenoms)) {
                foreach ($denominationSchema as $schemaRow) {
                    $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
                    if (isset($postedEndDenoms[$key])) {
                        $quantity = max(0, (int) $postedEndDenoms[$key]);
                        $totalEndingCash += $quantity * (float) $schemaRow['value'];
                    }
                }
            } else {
                // If no POST data, use the recalculated value from validation
                $totalEndingCash = $actualEndCashFromDenominations;
            }

            // Expected ending cash should match Net Cashflow when counted denominations are correct
            $expectedEndingCash = $netCashflow;

            // Actual counted cash at end of day from denominations
            $finalEndingAmount = round($totalEndingCash, 2);

            // Difference = Expected Ending Cash - Actual Counted Cash
            $recalculatedDifference = round($expectedEndingCash - $finalEndingAmount, 2);

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
                    dcmt_notes = ?,
                    dcmt_updated_by = ?,
                    dcmt_updated_at = CURRENT_TIMESTAMP
                WHERE dcmt_id = ?
            ");

            $stmt->execute([
                $finalStartingAmount,
                $recalculatedCashIncomeTotal,
                $recalculatedCashExpenseTotal,
                $ownerWithdrawNameInput,
                $ownerWithdrawAmountInput,
                $netCashflow,
                $finalEndingAmount,
                $recalculatedDifference,
                $notesInput,
                dcmt_get_current_user()['dcmt_username'] ?? 'system',
                $cashflow_id,
            ]);

            // Delete existing denominations
            $deleteStmt = $dcmt_pdo->prepare("DELETE FROM dcmt_cashflow_denominations WHERE dcmt_cashflow_id = ?");
            $deleteStmt->execute([$cashflow_id]);

            // Insert new denominations (END cash)
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

            foreach ($denominationRows as $row) {
                if ($row['quantity'] <= 0) {
                    continue;
                }
                $denominationStmt->execute([
                    $cashflow_id,
                    $row['label'],
                    $row['value'],
                    $row['quantity'],
                    $row['line_total'],
                ]);
            }

            // Insert new start cash denominations (type='start')
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

            // Prepare start denominations from POST data for saving
            $startDenomsToSave = array_merge($startCashDenominations, $startCoinDenominations);
            foreach ($startDenomsToSave as $row) {
                if ($row['quantity'] > 0) {
                    $startDenominationStmt->execute([
                        $cashflow_id,
                        $row['label'],
                        $row['value'],
                        $row['quantity'],
                        $row['line_total'],
                    ]);
                }
            }

            $dcmt_pdo->commit();

            dcmt_log_activity("Cashflow updated for {$selectedDate}", 'cashflow_update');
            dcmt_show_message(trans('cashflow', 'record_updated') ?: 'Cashflow record updated successfully', 'success');
            dcmt_redirect('view.php?id=' . $cashflow_id);
            exit();
        } catch (PDOException $e) {
            if ($dcmt_pdo->inTransaction()) {
                $dcmt_pdo->rollBack();
            }
            $errors[] = $e->getMessage();
            error_log('Cashflow update failed: ' . $e->getMessage());
        }
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

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

    <div class="dcmt-add-form-container">
        <div class="dcmt-add-form-header">
            <div class="dcmt-add-form-header-content">
                <h1 class="dcmt-add-form-page-title"><?php echo trans('cashflow', 'edit_cashflow'); ?></h1>
                <a href="view.php?id=<?php echo $cashflow_id; ?>"
                    class="dcmt-add-form-view-all-link"><?php echo trans('common', 'view'); ?></a>
            </div>
        </div>

        <form method="POST" id="cashflowForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" name="cash_income_total" id="cash_income_total"
                value="<?php echo number_format($cashIncomeTotal, 2, '.', ''); ?>"
                data-amount="<?php echo number_format($cashIncomeTotal, 2, '.', ''); ?>"
                data-stored="<?php echo number_format($storedCashIncomeTotal, 2, '.', ''); ?>">
            <input type="hidden" name="cash_expense_total" id="cash_expense_total"
                value="<?php echo number_format($cashExpenseTotal, 2, '.', ''); ?>"
                data-amount="<?php echo number_format($cashExpenseTotal, 2, '.', ''); ?>"
                data-stored="<?php echo number_format($storedCashExpenseTotal, 2, '.', ''); ?>">

            <!-- Cash Summary -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-3 dcmt-card-wrap">
                        <div class="card-body p-4 dcmt-cash-summary-sec">
                            <div class="row">
                                <div class="col-md-2">
                                    <h5 class="mb-0 fw-bold">
                                        <?php echo trans('cashflow', 'cash_summary'); ?>
                                    </h5>
                                </div>
                                <div class="col-md">
                                    <div class="row">
                                        <div class="col-md">
                                            <div class="p-3 pt-0 pb-0">
                                                <small
                                                class="text-muted d-block mb-1"><?php echo trans('cashflow', 'cashflow_date'); ?></small>
                                                <div class="fw-bold">
                                                    <?php echo htmlspecialchars(dcmt_format_date($selectedDate, 'M d, Y')); ?>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md">
                                            <div class="p-3 pt-0 pb-0">
                                                <small
                                                    class="text-muted d-block mb-1"><?php echo trans('cashflow', 'opening_balance'); ?></small>
                                                <div class="fw-bold" id="starting_amount_display">
                                                    <?php echo dcmt_format_currency($startingAmount); ?>
                                                </div>
                                                <input type="hidden" id="starting_amount" name="starting_amount"
                                                    value="<?php echo number_format($startingAmount, 2, '.', ''); ?>">
                                            </div>
                                        </div>
                                        <div class="col-md ps-4">
                                            <div class="p-3 pt-0 pb-0">
                                                <small
                                                    class="text-muted d-block mb-1"><?php echo trans('cashflow', 'end_cash'); ?></small>
                                                <div class="fw-bold" id="total_ending_cash_display">
                                                    <?php echo dcmt_format_currency($endingAmountNumeric); ?>
                                                </div>
                                                <input type="hidden" id="ending_amount" name="ending_amount"
                                                    value="<?php echo number_format($endingAmountNumeric, 2, '.', ''); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="row g-4 mt-3">
                                        <div class="col-md">
                                            <div class="d-flex flex-column p-3 pt-0 pb-0">
                                                <div class="d-flex align-items-center mb-2">
                                                    <i class="fas fa-wallet text-primary me-2 fs-5"></i>
                                                    <span class="text-muted small"><?php echo trans('cashflow', 'start_cash'); ?></span>
                                                </div>
                                                <div class="ms-4">
                                                    <strong class="fs-4 text-primary"
                                                        id="startCashSummaryDisplay"><?php echo dcmt_format_currency($startingAmount); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md">
                                            <div class="d-flex flex-column p-2 pt-0 pb-0">
                                                <div class="d-flex align-items-center mb-2">
                                                    <i class="fas fa-arrow-down text-success me-2 fs-5"></i>
                                                    <span class="text-muted small"><?php echo trans('cashflow', 'cash_inflows'); ?></span>
                                                </div>
                                                <div class="ms-4">
                                                    <strong class="fs-4 text-success"
                                                        id="cashInflowsSummaryDisplay"><?php echo dcmt_format_currency($cashIncomeTotal); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                        <div class="col-md">
                                            <?php $startPlusInflows = $startingAmount + $cashIncomeTotal; ?>
                                            <div class="d-flex flex-column p-3 pt-0 pb-0">
                                                <div class="d-flex align-items-center mb-2">
                                                    <i class="fas fa-calculator dcmt-info-color me-2 fs-5"></i>
                                                    <span class="text-muted small"><?php echo trans('cashflow', 'total'); ?></span>
                                                </div>
                                                <div class="ms-4">
                                                    <strong class="fs-4 dcmt-info-color"
                                                        id="startInflowsTotalSummaryDisplay"><?php echo dcmt_format_currency($startPlusInflows); ?></strong>
                                                </div>
                                            </div>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>

            <!-- Owner Withdraw Section -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-3 dcmt-card-wrap">
                        <div class="card-body p-4 dcmt-cash-withdraw-sec">
                            <div class="row g-3">
                                <div class="col-md-2">
                                    <h5 class="mb-0 fw-bold"><?php echo trans('cashflow', 'cash_withdrawal'); ?></h5>
                                </div>
                                <div class="col-md">
                                    <label for="owner_withdraw_name" class="form-label"><?php echo trans('cashflow', 'doctor_name'); ?></label>
                                    <select class="form-select" id="owner_withdraw_name" name="owner_withdraw_name">
                                        <option value=""><?php echo trans('cashflow', 'select_doctor'); ?></option>
                                        <?php
                                        $selectedDoctorName = trim(isset($_POST['owner_withdraw_name']) ? dcmt_sanitize_input($_POST['owner_withdraw_name']) : $displayOwnerWithdrawName);
                                        $hasSelectedDoctorInList = false;
                                        foreach ($doctors as $doctor):
                                            $doctorName = trim($doctor['dcmt_name'] ?? '');
                                            if ($doctorName === '') {
                                                continue;
                                            }
                                            $isSelected = ($selectedDoctorName !== '' && $selectedDoctorName === $doctorName);
                                            if ($isSelected) {
                                                $hasSelectedDoctorInList = true;
                                            }
                                        ?>
                                            <option value="<?php echo htmlspecialchars($doctorName); ?>" <?php echo $isSelected ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($doctorName); ?>
                                            </option>
                                        <?php endforeach; ?>
                                        <?php if ($selectedDoctorName !== '' && !$hasSelectedDoctorInList): ?>
                                            <option value="<?php echo htmlspecialchars($selectedDoctorName); ?>" selected>
                                                <?php echo htmlspecialchars($selectedDoctorName); ?>
                                            </option>
                                        <?php endif; ?>
                                    </select>
                                </div>
                                <div class="col-md">
                                    <label for="owner_withdraw_amount" class="form-label"><?php echo trans('cashflow', 'amount'); ?></label>
                                    <div class="dcmt-amount-input-wrapper">
                                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                        <input type="number" min="0" step="0.01" class="form-control dcmt-amount-input" id="owner_withdraw_amount" name="owner_withdraw_amount"
                                            value="<?php echo $storedOwnerWithdrawAmount > 0 ? number_format($storedOwnerWithdrawAmount, 2, '.', '') : ''; ?>"
                                            placeholder="<?php echo trans('common', 'amount'); ?>">
                                    </div>
                                    <div class="invalid-feedback" id="ownerWithdrawAmountError">
                                        <?php echo trans('cashflow', 'withdraw_amount_exceeds_total'); ?>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            <!-- Net Cashflow Display -->
            <div class="row">
                <div class="col-12">
                    <div class="card mb-3 dcmt-card-wrap">
                        <div class="card-body p-4 dcmt-netcash-sec">
                            <div class="row g-4">
                                <div class="col-md-2">
                                    <h5 class="mb-0 fw-bold"><?php echo trans('cashflow', 'net_cashflow'); ?></h5>
                                </div>
                                <div class="col-md">
                                    <div class="d-flex flex-column p-3 pt-0 pb-0">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-wallet text-primary me-2 fs-5"></i>
                                            <span class="text-muted small"><?php echo trans('cashflow', 'start_cash'); ?></span>
                                        </div>
                                        <div class="ms-4">
                                            <strong class="fs-4 text-primary"
                                                id="netStartCashDisplay"><?php echo dcmt_format_currency($startingAmount); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md">
                                    <div class="d-flex flex-column p-3 pt-0 pb-0">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-arrow-down text-success me-2 fs-5"></i>
                                            <span class="text-muted small"><?php echo trans('cashflow', 'cash_inflows'); ?></span>
                                        </div>
                                        <div class="ms-4">
                                            <strong class="fs-4 text-success"
                                                id="netCashIncomeDisplay"><?php echo dcmt_format_currency($cashIncomeTotal); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md">
                                    <div class="d-flex flex-column p-3 pt-0 pb-0">
                                        <div class="d-flex align-items-center mb-2">
                                            <i class="fas fa-arrow-up text-danger me-2 fs-5"></i>
                                            <span class="text-muted small"><?php echo trans('cashflow', 'cash_outflows'); ?></span>
                                        </div>
                                        <div class="ms-4">
                                            <strong class="fs-4 text-danger"
                                                id="netCashExpenseDisplay"><?php echo dcmt_format_currency($cashExpenseTotal); ?></strong>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md dcmt-netcash-display-col">
                                    <?php $netCashflowDynamic = $startingAmount + $cashIncomeTotal - $cashExpenseTotal; ?>
                                    <?php $netCashflowStored = (float) ($cashflow['dcmt_net_cashflow'] ?? $netCashflowDynamic); ?>
                                    <div
                                        class="d-flex flex-column p-3 pt-0 pb-0 dcmt-netcash-display">
                                        <div class="d-flex align-items-center mb-2">
                                            <i
                                                class="fas fa-balance-scale <?php echo ($netCashflowDynamic) >= 0 ? 'text-success' : 'text-danger'; ?> me-2 fs-5"></i>
                                            <span class="fw-bold small"><?php echo trans('cashflow', 'net_cashflow'); ?></span>
                                        </div>
                                        <div class="ms-4">
                                            <strong
                                                class="fs-4 <?php echo ($netCashflowDynamic) >= 0 ? 'text-success' : 'text-danger'; ?>"
                                                id="netCashflowDisplay">
                                                <?php echo dcmt_format_currency($netCashflowDynamic); ?>
                                            </strong>
                                        </div>
                                    </div>
                                </div>  
                            </div>
                        </div>
                    </div>
                </div>
            </div>
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
                                                <span class="dcmt-starting-denomination-total"
                                                    data-starting-denomination-total>
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
                                                <span class="dcmt-starting-denomination-total"
                                                    data-starting-denomination-total>
                                                    <?php echo dcmt_format_currency($row['line_total']); ?>
                                                </span>
                                            </div>
                                        </div>
                                    <?php endforeach; ?>
                                </div>
                            </div>

                            <!-- Totals Row - Both on Same Line -->
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
                                        <small class="text-muted d-block">
                                            <?php echo trans('cashflow', 'start_cash'); ?>
                                        </small>
                                        <div class="fw-bold text-primary fs-5" id="dcmtFinalStartCashDisplay">
                                            <?php echo dcmt_format_currency($startingAmount); ?>
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
                            <h6 class="mb-0"><i
                                    class="fas fa-stop me-2"></i><?php echo trans('cashflow', 'end_cash_denominations'); ?>
                            </h6>
                        </div>
                        <div class="card-body">
                            <div class="row">
                                <!-- Cash Denominations Section -->
                                <div class="col-md-6 border-end pe-4">
                                    <h6 class="mb-3 fw-bold"><?php echo trans('cashflow', 'cash_denominations'); ?></h6>
                                    <?php foreach ($cashDenominations as $row): ?>
                                        <div class="row mb-2 align-items-center">
                                            <div class="col-4">
                                                <input type="number" min="0" step="1"
                                                    class="form-control form-control-sm dcmt-ending-denomination-input dcmt-cash-input"
                                                    name="denominations[<?php echo $row['key']; ?>]"
                                                    id="end_denom_<?php echo $row['key']; ?>"
                                                    value="<?php echo (int) $row['quantity']; ?>"
                                                    data-denomination-value="<?php echo $row['value']; ?>" placeholder="0">
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label mb-0">x
                                                    <?php echo htmlspecialchars($row['label']); ?></label>
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
                                                <input type="number" min="0" step="1"
                                                    class="form-control form-control-sm dcmt-ending-denomination-input dcmt-coin-input"
                                                    name="denominations[<?php echo $row['key']; ?>]"
                                                    id="end_denom_<?php echo $row['key']; ?>"
                                                    value="<?php echo (int) $row['quantity']; ?>"
                                                    data-denomination-value="<?php echo $row['value']; ?>" placeholder="0">
                                            </div>
                                            <div class="col-4">
                                                <label class="form-label mb-0">x
                                                    <?php echo htmlspecialchars($row['label']); ?></label>
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

                            <!-- Totals Row - Both on Same Line -->
                            <div class="row mt-3 pt-3 border-top">
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong><?php echo trans('cashflow', 'total_bills'); ?>:</strong>
                                        <strong
                                            id="dcmtEndCashTotalDisplay"><?php echo dcmt_format_currency($cashTotal); ?></strong>
                                    </div>
                                </div>
                                <div class="col-md-6">
                                    <div class="d-flex justify-content-between align-items-center">
                                        <strong><?php echo trans('cashflow', 'total_coins'); ?>:</strong>
                                        <strong
                                            id="dcmtEndCoinTotalDisplay"><?php echo dcmt_format_currency($coinTotal); ?></strong>
                                    </div>
                                </div>
                            </div>

                            <!-- Summary Section -->
                            <div class="mt-3 pt-3 border-top">
                                <div class="row align-items-center">
                                    <div class="col-md-12 text-end">
                                        <small class="text-muted d-block">
                                            <?php echo trans('cashflow', 'end_cash'); ?>
                                        </small>
                                        <div class="fw-bold text-primary fs-5" id="dcmtEndTotalDisplay">
                                            <?php echo dcmt_format_currency($endingAmountNumeric); ?>
                                        </div>
                                    </div>
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
                        <textarea class="form-control" id="notes" name="notes"
                            rows="3"><?php echo htmlspecialchars($notesInput ?? ''); ?></textarea>
                    </div>
                </div>
            </div>

            <!-- Form Actions -->
            <div class="dcmt-form-actions">
                <a href="view.php?id=<?php echo $cashflow_id; ?>" class="btn dcmt-btn-cancel">
                    <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
                </a>
                <button type="submit" class="btn dcmt-btn-submit" id="dcmtSaveBtn">
                    <i class="fas fa-save"></i><?php echo trans('cashflow', 'update_cashflow_record'); ?>
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    document.addEventListener('DOMContentLoaded', function () {
        const form = document.getElementById('cashflowForm');
        const endingHiddenInput = document.getElementById('ending_amount');
        const startingAmountInput = document.getElementById('starting_amount');
        const cashIncomeField = document.getElementById('cash_income_total');
        const cashExpenseField = document.getElementById('cash_expense_total');
        const ownerWithdrawInput = document.getElementById('owner_withdraw_amount');
        const ownerWithdrawError = document.getElementById('ownerWithdrawAmountError');
        const startingDisplay = document.getElementById('starting_amount_display');
        const totalEndingCashDisplay = document.getElementById('total_ending_cash_display');
        const startCashSummaryDisplay = document.getElementById('startCashSummaryDisplay');
        const cashInflowsSummaryDisplay = document.getElementById('cashInflowsSummaryDisplay');
        const startInflowsTotalSummaryDisplay = document.getElementById('startInflowsTotalSummaryDisplay');
        const closingBalanceDisplay = document.getElementById('closingBalanceDisplay');

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

            if (startCashTotalDisplay) startCashTotalDisplay.textContent = formatCurrency(cashTotal);
            if (startCoinTotalDisplay) startCoinTotalDisplay.textContent = formatCurrency(coinTotal);

            return total;
        }

        // Update End Cash Totals
        function updateEndCashTotals() {
            const endingDenomInputs = document.querySelectorAll('.dcmt-ending-denomination-input');
            let total = 0;
            let cashTotal = 0;
            let coinTotal = 0;

            endingDenomInputs.forEach(function (input) {
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

            return total;
        }

        function validateOwnerWithdrawAmount(availableTotal) {
            if (!ownerWithdrawInput || !ownerWithdrawError) {
                return true;
            }

            const rawValue = ownerWithdrawInput.value || '0';
            const amount = parseFloat(rawValue) || 0;

            if (amount > availableTotal) {
                ownerWithdrawInput.classList.add('is-invalid');
                ownerWithdrawError.style.display = 'block';
                return false;
            }

            ownerWithdrawInput.classList.remove('is-invalid');
            ownerWithdrawError.style.display = 'none';
            return true;
        }

        function updateNetCashflow() {
            if (!cashIncomeField || !startingAmountInput) {
                return;
            }

            const startVal = parseFloat(startingAmountInput.value || '0') || 0;
            const cashIncomeTotal = parseFloat(cashIncomeField.getAttribute('data-amount')) || 0;
            const cashExpenseTotal = cashExpenseField ? (parseFloat(cashExpenseField.getAttribute('data-amount')) || 0) : 0;
            const netCashflow = startVal + cashIncomeTotal - cashExpenseTotal;

            const netCashIncomeDisplay = document.getElementById('netCashIncomeDisplay');
            const netCashExpenseDisplay = document.getElementById('netCashExpenseDisplay');
            const netDoctorCashDisplay = document.getElementById('netDoctorCashDisplay');
            const netCashflowDisplay = document.getElementById('netCashflowDisplay');
            const netCashflowContainer = netCashflowDisplay ? netCashflowDisplay.closest('.col-md') : null;
            const netCashflowBox = netCashflowContainer ? netCashflowContainer.querySelector('.d-flex.flex-column') : null;
            const netCashflowIcon = netCashflowBox ? netCashflowBox.querySelector('i') : null;

            if (netCashIncomeDisplay) {
                netCashIncomeDisplay.textContent = formatCurrency(cashIncomeTotal);
            }
            if (netCashExpenseDisplay) {
                netCashExpenseDisplay.textContent = formatCurrency(cashExpenseTotal);
            }
            if (netDoctorCashDisplay) {
                netDoctorCashDisplay.textContent = formatCurrency(0);
            }
            if (startCashSummaryDisplay) {
                startCashSummaryDisplay.textContent = formatCurrency(startVal);
            }
            if (cashInflowsSummaryDisplay) {
                cashInflowsSummaryDisplay.textContent = formatCurrency(cashIncomeTotal);
            }
            if (startInflowsTotalSummaryDisplay) {
                startInflowsTotalSummaryDisplay.textContent = formatCurrency(startVal + cashIncomeTotal);
            }
            if (netCashflowDisplay) {
                netCashflowDisplay.textContent = formatCurrency(netCashflow);

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

        // Owner Withdraw input sync with hidden expense field
        if (ownerWithdrawInput && cashExpenseField) {
            ownerWithdrawInput.addEventListener('input', function () {
                const parsed = parseFloat(ownerWithdrawInput.value);
                let value = isNaN(parsed) ? 0 : parsed;
                if (value < 0) value = 0;

                const formatted = value.toFixed(2);
                cashExpenseField.value = formatted;
                cashExpenseField.setAttribute('data-amount', formatted);

                const startVal = startingAmountInput ? (parseFloat(startingAmountInput.value || '0') || 0) : 0;
                const cashIncomeTotal = cashIncomeField ? (parseFloat(cashIncomeField.getAttribute('data-amount')) || 0) : 0;
                const availableTotal = startVal + cashIncomeTotal;
                validateOwnerWithdrawAmount(availableTotal);

                updateNetCashflow();
            });
        }

        // Update starting amount when denominations change
        function updateStartingAmount() {
            const denomTotal = updateStartCashTotals();
            const finalStartCash = denomTotal > 0 ? denomTotal : prevDayClosing;
            if (startingAmountInput) {
                startingAmountInput.value = finalStartCash.toFixed(2);
            }
            if (startingDisplay) {
                startingDisplay.textContent = formatCurrency(finalStartCash);
            }
            const finalStartCashDisplay = document.getElementById('dcmtFinalStartCashDisplay');
            if (finalStartCashDisplay) {
                finalStartCashDisplay.textContent = formatCurrency(finalStartCash);
            }
            const netStartCashDisplay = document.getElementById('netStartCashDisplay');
            if (netStartCashDisplay) {
                netStartCashDisplay.textContent = formatCurrency(finalStartCash);
            }
            updateClosingBalance();
            updateNetCashflow();
        }

        // Update ending amount when denominations change
        function updateEndingAmount() {
            const total = updateEndCashTotals();
            if (endingHiddenInput) {
                endingHiddenInput.value = total.toFixed(2);
            }
            updateClosingBalance();
        }

        function updateClosingBalance() {
            const startVal = parseFloat(startingAmountInput?.value || 0);
            const endVal = parseFloat(endingHiddenInput?.value || 0);
            if (totalEndingCashDisplay) {
                totalEndingCashDisplay.textContent = formatCurrency(endVal);
            }
            if (closingBalanceDisplay) {
                closingBalanceDisplay.textContent = formatCurrency(startVal + endVal);
            }
        }

        // Update totals when starting denominations change
        const startingDenomInputs = document.querySelectorAll('.dcmt-starting-denomination-input');
        startingDenomInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                updateStartingAmount();
            });
        });

        // Update totals when ending denominations change
        const endingDenomInputs = document.querySelectorAll('.dcmt-ending-denomination-input');
        endingDenomInputs.forEach(function (input) {
            input.addEventListener('input', function () {
                updateEndingAmount();
            });
        });

        // Initialize
        updateStartingAmount();
        updateEndingAmount();
        updateNetCashflow();

        // Form submission loading state
        const submitBtn = document.getElementById('dcmtSaveBtn');

        if (form && submitBtn) {
            form.addEventListener('submit', function (e) {
                const startVal = startingAmountInput ? (parseFloat(startingAmountInput.value || '0') || 0) : 0;
                const cashIncomeTotal = cashIncomeField ? (parseFloat(cashIncomeField.getAttribute('data-amount')) || 0) : 0;
                const availableTotal = startVal + cashIncomeTotal;

                const isValid = validateOwnerWithdrawAmount(availableTotal);
                if (!isValid) {
                    e.preventDefault();
                    e.stopPropagation();
                    return;
                }

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
