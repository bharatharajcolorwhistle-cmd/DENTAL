<?php
/**
 * AJAX endpoint to save start cash immediately
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/cashflow_functions.php';

// Ensure database connection is available
if (!isset($dcmt_pdo) || !$dcmt_pdo) {
    $dcmt_db = new Dcmt_Database();
    $dcmt_pdo = $dcmt_db->getConnection();
} else {
    // Ensure we have the database object to run migrations
    $dcmt_db = new Dcmt_Database();
}

// Ensure required columns exist (run migration if needed)
try {
    $dcmt_db->addCashflowExpenseFields();
} catch (Exception $e) {
    error_log('Migration check failed: ' . $e->getMessage());
}

if (!function_exists('dcmt_cashflow_make_denom_key')) {
    function dcmt_cashflow_make_denom_key(float $value): string
    {
        return str_replace('.', '_', rtrim(rtrim(sprintf('%.2f', $value), '0'), '.'));
    }
}

header('Content-Type: application/json');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

// Allow admins and staff
$dcmt_current_user = dcmt_get_current_user();
$dcmt_is_staff = $dcmt_current_user && (($dcmt_current_user['dcmt_role'] ?? '') === 'staff');
if (!dcmt_is_admin() && !$dcmt_is_staff) {
    echo json_encode(['success' => false, 'message' => 'Access denied.']);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method']);
    exit();
}

// Get JSON input
$input = json_decode(file_get_contents('php://input'), true);

if (!isset($input['date']) || !isset($input['starting_amount']) || !isset($input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

if (!dcmt_verify_csrf_token($input['csrf_token'])) {
    echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
    exit();
}

$selectedDate = dcmt_sanitize_input($input['date']);
$startingAmount = (float) $input['starting_amount'];
$denominations = $input['denominations'] ?? [];

// Validate date
$dateError = dcmt_validate_date_field($selectedDate, 'cashflow_date');
if ($dateError) {
    echo json_encode(['success' => false, 'message' => trans('cashflow', 'invalid_date')]);
    exit();
}

// Validate starting amount
if ($startingAmount <= 0) {
    echo json_encode(['success' => false, 'message' => trans('cashflow', 'start_cash_required')]);
    exit();
}

try {
    $dcmt_pdo->beginTransaction();
    
    // Check if record exists
    $existingRecord = dcmt_get_cashflow_by_date($dcmt_pdo, $selectedDate);
    
    // Get current cash income and expense totals
    $cashIncomeTotal = dcmt_calculate_cash_income_total($dcmt_pdo, $selectedDate);
    $cashExpenseTotal = dcmt_calculate_cash_expense_total($dcmt_pdo, $selectedDate);
    $netCashflow = round($cashIncomeTotal - $cashExpenseTotal, 2);
    
    // Get existing ending amount if record exists
    $endingAmount = $existingRecord ? (float) $existingRecord['dcmt_ending_amount'] : 0.0;
    // Calculate Difference based on Net Cashflow sign
    // If Net Cashflow is positive: Difference = Net Cashflow - Total Ending Cash
    // If Net Cashflow is negative: Difference = Total Ending Cash - |Net Cashflow|
    if ($netCashflow >= 0) {
        $difference = round($netCashflow - $endingAmount, 2);
    } else {
        $difference = round($endingAmount - abs($netCashflow), 2);
    }
    
    if ($existingRecord) {
        // Update existing record
        $stmt = $dcmt_pdo->prepare("
            UPDATE dcmt_cashflows SET
                dcmt_starting_amount = ?,
                dcmt_cash_income_total = ?,
                dcmt_cash_expense_total = ?,
                dcmt_net_cashflow = ?,
                dcmt_ending_amount = ?,
                dcmt_difference = ?,
                dcmt_updated_by = ?,
                dcmt_updated_at = CURRENT_TIMESTAMP
            WHERE dcmt_id = ?
        ");
        
        $stmt->execute([
            $startingAmount,
            $cashIncomeTotal,
            $cashExpenseTotal,
            $netCashflow,
            $endingAmount,
            $difference,
            dcmt_get_current_user()['dcmt_username'] ?? 'system',
            $existingRecord['dcmt_id'],
        ]);
        
        $cashflowId = (int) $existingRecord['dcmt_id'];
        
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
                dcmt_net_cashflow,
                dcmt_ending_amount,
                dcmt_difference,
                dcmt_created_by
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");
        
        $stmt->execute([
            $selectedDate,
            $startingAmount,
            $cashIncomeTotal,
            $cashExpenseTotal,
            $netCashflow,
            $endingAmount,
            $difference,
            dcmt_get_current_user()['dcmt_username'] ?? 'system',
        ]);
        
        $cashflowId = (int) $dcmt_pdo->lastInsertId();
    }
    
    // Save start cash denominations if provided
    if (!empty($denominations)) {
        $denominationSchema = dcmt_get_cash_denominations();
        $denominationStmt = $dcmt_pdo->prepare("
            INSERT INTO dcmt_cashflow_denominations (
                dcmt_cashflow_id,
                dcmt_denomination_type,
                dcmt_denomination_label,
                dcmt_denomination_value,
                dcmt_quantity,
                dcmt_total_amount
            ) VALUES (?, 'start', ?, ?, ?, ?)
        ");
        
        foreach ($denominationSchema as $schemaRow) {
            $key = dcmt_cashflow_make_denom_key((float) $schemaRow['value']);
            $quantity = isset($denominations[$key]) ? max(0, (int) $denominations[$key]) : 0;
            
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
    }
    
    $dcmt_pdo->commit();
    
    dcmt_log_activity("Start cash saved for {$selectedDate}", 'cashflow_update');
    
    echo json_encode([
        'success' => true,
        'message' => trans('cashflow', 'start_cash_saved'),
        'cashflow_id' => $cashflowId
    ]);
    
} catch (PDOException $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log('Save start cash failed: ' . $e->getMessage());
    error_log('SQL Error Info: ' . print_r($e->errorInfo ?? [], true));
    
    // Return more detailed error message for debugging (in development)
    $errorMessage = 'Database error occurred';
    if (defined('DCMT_DEBUG_MODE') && DCMT_DEBUG_MODE) {
        $errorMessage = 'Database error: ' . $e->getMessage();
    }
    
    echo json_encode([
        'success' => false, 
        'message' => $errorMessage, 
        'error' => $e->getMessage(),
        'error_info' => $e->errorInfo ?? []
    ]);
} catch (Exception $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    error_log('Save start cash failed (general): ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred: ' . $e->getMessage()]);
}

