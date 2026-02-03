<?php
/**
 * Get Pending Amount - AJAX Endpoint for Income Page
 * Dental Clinic Management System
 */

// Suppress any output before JSON response
ob_start();

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Clear any output buffer and set JSON header
ob_clean();
header('Content-Type: application/json');

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get and validate input
$income_id = isset($_POST['income_id']) ? (int)$_POST['income_id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

// Validate income ID
if ($income_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid income ID.']);
    exit();
}

if (!function_exists('dcmt_get_payment_status_id_by_keyword')) {
    function dcmt_get_payment_status_id_by_keyword(PDO $pdo, string $keyword): ?int {
        $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_status WHERE LOWER(dcmt_name) LIKE ? LIMIT 1");
        $stmt->execute(['%' . strtolower($keyword) . '%']);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ? (int)$row['dcmt_id'] : null;
    }
}

try {
    // Get income record to verify it exists and is pending
    $stmt = $dcmt_pdo->prepare("
        SELECT i.dcmt_id, i.dcmt_patient_name, i.dcmt_payment_status_id, i.dcmt_pending_amount, i.dcmt_paid_amount, 
               i.dcmt_type, i.dcmt_service_pending_amount, i.dcmt_service_paid_amount, 
               i.dcmt_product_pending_amount, i.dcmt_product_paid_amount,
               i.dcmt_total_pending_amount, i.dcmt_total_paid_amount, i.dcmt_amount,
               i.dcmt_payment_method_id,
               ps.dcmt_name as payment_status_name 
        FROM dcmt_income i 
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id 
        WHERE i.dcmt_id = ?
    ");
    $stmt->execute([$income_id]);
    $income = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$income) {
        echo json_encode(['success' => false, 'message' => 'Income record not found.']);
        exit();
    }
    
    $pending_status_id = dcmt_get_payment_status_id_by_keyword($dcmt_pdo, 'pending');
    $payment_status_name = strtolower($income['payment_status_name'] ?? '');
    $is_pending = true;
    
    if ($pending_status_id) {
        $is_pending = ((int)$income['dcmt_payment_status_id'] === $pending_status_id);
    } elseif (!empty($payment_status_name)) {
        $is_pending = (
            strpos($payment_status_name, 'pending') !== false ||
            strpos($payment_status_name, 'pendiente') !== false
        );
    }
    
    if (!$is_pending) {
        echo json_encode(['success' => false, 'message' => 'This payment is not pending.']);
        exit();
    }
    
    $service_pending = max(0, floatval($income['dcmt_service_pending_amount'] ?? 0));
    $product_pending = max(0, floatval($income['dcmt_product_pending_amount'] ?? 0));
    $general_pending = max(0, floatval($income['dcmt_pending_amount'] ?? 0));
    $total_pending = $income['dcmt_total_pending_amount'] !== null
        ? max(0, floatval($income['dcmt_total_pending_amount']))
        : 0;
    
    if ($total_pending <= 0) {
        $total_pending = $service_pending + $product_pending;
    }
    if ($total_pending <= 0) {
        $total_pending = $general_pending;
    }
    
    if ($service_pending > 0 && $product_pending > 0) {
        $amount_type = 'mixed';
    } elseif ($service_pending > 0) {
        $amount_type = 'service';
    } elseif ($product_pending > 0) {
        $amount_type = 'product';
    } else {
        $amount_type = 'general';
    }
    
    $default_payment_method_id = $income['dcmt_payment_method_id'] ?? null;
    $default_payment_date = dcmt_get_current_date('Y-m-d');
    
    if ($total_pending <= 0) {
        echo json_encode([
            'success' => true, 
            'income_id' => $income_id,
            'patient_name' => $income['dcmt_patient_name'],
            'income_type' => $income['dcmt_type'],
            'pending_amount' => 0,
            'amount_type' => $amount_type,
            'service_pending_amount' => $service_pending,
            'product_pending_amount' => $product_pending,
            'formatted_pending_amount' => dcmt_format_currency(0),
            'no_pending' => true,
            'default_payment_method_id' => $default_payment_method_id,
            'default_payment_date' => $default_payment_date
        ]);
        exit();
    }
    
    echo json_encode([
        'success' => true, 
        'income_id' => $income_id,
        'patient_name' => $income['dcmt_patient_name'],
        'income_type' => $income['dcmt_type'],
        'pending_amount' => $total_pending,
        'amount_type' => $amount_type,
        'service_pending_amount' => $service_pending,
        'product_pending_amount' => $product_pending,
        'formatted_pending_amount' => dcmt_format_currency($total_pending),
        'default_payment_method_id' => $default_payment_method_id,
        'default_payment_date' => $default_payment_date
    ]);
    
} catch (PDOException $e) {
    error_log("Error getting pending amount: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'Database error occurred.']);
} catch (Exception $e) {
    error_log("General error getting pending amount: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => 'An error occurred while processing the request.']);
}
