<?php
/**
 * Add Income Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_search') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $term = trim((string) ($_GET['term'] ?? ''));
        $term = dcmt_sanitize_input($term);
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $whereSql = "WHERE dcmt_status = 'active'";
        $params = [];
        if ($term !== '') {
            $whereSql .= " AND (dcmt_patient_name LIKE ? OR dcmt_phone LIKE ?)";
            $likeTerm = '%' . $term . '%';
            $params[] = $likeTerm;
            $params[] = $likeTerm;
        }

        $stmt = $dcmt_pdo->prepare("
            SELECT dcmt_id, dcmt_patient_name, dcmt_phone
            FROM dcmt_patients
            {$whereSql}
            ORDER BY dcmt_patient_name ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($patients as $patient) {
            $name = (string) ($patient['dcmt_patient_name'] ?? '');
            $phone = (string) ($patient['dcmt_phone'] ?? '');
            $displayText = $name;
            if ($phone !== '') {
                $displayText .= ' - ' . $phone;
            }
            $results[] = [
                'id' => (int) ($patient['dcmt_id'] ?? 0),
                'text' => $displayText,
                'name' => $name
            ];
        }

        echo json_encode([
            'results' => $results,
            'pagination' => ['more' => false]
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'results' => [],
            'pagination' => ['more' => false]
        ]);
    }
    exit();
}

$errors = [];
$success = false;

$doctors = [];
$all_patients = [];
$inventory_items = [];
$income_payment_methods = [];
$doctors_for_js = [];
$initial_service_items_for_js = [];
$initial_product_items_for_js = [];
$initial_note_items_for_js = [];
$initial_income_payments_for_js = [];
$posted_service_items = [];
$first_posted_service_item = [];
$posted_product_items = [];
$posted_note_items = [];
$posted_income_payments = [];
$default_cash_method_id = null;

// Get default doctor user setting (check both old and new setting keys for backward compatibility)
$default_doctor_user_id = null;
$default_doctor_id = null;
try {
    // First check for new user-based setting
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'default_doctor_user_id'");
    $stmt->execute();
    $default_doctor_user_setting = $stmt->fetch();
    if ($default_doctor_user_setting) {
        $default_doctor_user_id = $default_doctor_user_setting['dcmt_setting_value'];
    }
    
    // Fallback to old doctor_id setting for backward compatibility
    if (!$default_doctor_user_id) {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'default_doctor_id'");
        $stmt->execute();
        $default_doctor_setting = $stmt->fetch();
        if ($default_doctor_setting) {
            $default_doctor_id = $default_doctor_setting['dcmt_setting_value'];
            // Treat stored value as a doctor user_id if it matches an active doctor
            $match_stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_users WHERE dcmt_id = ? AND dcmt_role = 'doctor' LIMIT 1");
            $match_stmt->execute([$default_doctor_id]);
            $matched_user = $match_stmt->fetch(PDO::FETCH_ASSOC);
            if ($matched_user) {
                $default_doctor_user_id = $matched_user['dcmt_id'];
            }
        }
    }
} catch (PDOException $e) {
    error_log("Error fetching default doctor setting: " . $e->getMessage());
}

if (!function_exists('dcmt_format_quantity_display')) {
    function dcmt_format_quantity_display($quantity) {
        if ($quantity === null || $quantity === '') {
            return '';
        }
        if (!is_numeric($quantity)) {
            return $quantity;
        }
        $formatted = rtrim(rtrim(number_format((float)$quantity, 2, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}

if (!function_exists('dcmt_add_payment_history_entry')) {
    function dcmt_add_payment_history_entry(PDO $pdo, int $incomeId, string $paymentType, float $amount, string $paidOn, string $recordedBy, ?int $paymentMethodId = null): void {
        if ($amount <= 0) {
            return;
        }
        $notes = null;
        if ($paymentMethodId !== null) {
            $notes = json_encode(['payment_method_id' => $paymentMethodId]);
        }
        $stmt = $pdo->prepare("
            INSERT INTO dcmt_income_payment_history (
                dcmt_income_id,
                dcmt_payment_type,
                dcmt_amount,
                dcmt_paid_on,
                dcmt_notes,
                dcmt_recorded_by
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $stmt->execute([
            $incomeId,
            $paymentType,
            $amount,
            $paidOn,
            $notes,
            $recordedBy
        ]);
    }
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE dcmt_status = 'active' AND LOWER(dcmt_name) = 'cash' LIMIT 1");
        $stmt->execute();
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row && isset($row['dcmt_id'])) {
            $default_cash_method_id = (int) $row['dcmt_id'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching default cash payment method: " . $e->getMessage());
    }

    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!dcmt_verify_csrf_token($csrf_token)) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Get form data
        $patient_id = !empty($_POST['patient_id']) ? (int) $_POST['patient_id'] : null;
        $patient_name = dcmt_sanitize_input($_POST['patient_name'] ?? '');
        $type = dcmt_sanitize_input($_POST['type'] ?? '');
        $description = dcmt_sanitize_input($_POST['description'] ?? '');
        $note = dcmt_sanitize_input($_POST['note'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        $service_paid_amount = floatval($_POST['service_paid_amount'] ?? 0);
        $service_pending_amount = floatval($_POST['service_pending_amount'] ?? 0);
        $product_amount = floatval($_POST['product_amount'] ?? 0);
        $product_paid_amount = floatval($_POST['product_paid_amount'] ?? 0);
        $product_pending_amount = floatval($_POST['product_pending_amount'] ?? 0);
        $total_paid_amount = floatval($_POST['total_paid_amount'] ?? 0);
        $total_pending_amount = floatval($_POST['total_pending_amount'] ?? 0);
        $transaction_date = dcmt_sanitize_input($_POST['transaction_date'] ?? '');
        // Get user_id from form (now using doctor role users directly)
        $doctor_user_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null;
        $product_sale_doctor_user_id = !empty($_POST['product_sale_doctor_id']) ? intval($_POST['product_sale_doctor_id']) : null;
        $income_payments_posted = $_POST['income_payments'] ?? [];
        $normalized_payments = [];
        if (is_array($income_payments_posted)) {
            foreach ($income_payments_posted as $paymentRow) {
                if (!is_array($paymentRow)) {
                    continue;
                }
                $amountValue = isset($paymentRow['amount']) ? floatval($paymentRow['amount']) : 0.0;
                if ($amountValue <= 0) {
                    continue;
                }
                $paidOnValue = isset($paymentRow['paid_on']) ? trim((string) $paymentRow['paid_on']) : '';
                if ($paidOnValue === '') {
                    $paidOnValue = $transaction_date !== '' ? $transaction_date : date('Y-m-d');
                }
                $methodValue = isset($paymentRow['payment_method_id']) && $paymentRow['payment_method_id'] !== '' ? intval($paymentRow['payment_method_id']) : null;
                $normalized_payments[] = [
                    'amount' => $amountValue,
                    'paid_on' => $paidOnValue,
                    'payment_method_id' => $methodValue
                ];
            }
        }
        
        if (!empty($normalized_payments)) {
            foreach ($normalized_payments as &$paymentRow) {
                if ($paymentRow['payment_method_id'] === null && !empty($default_cash_method_id)) {
                    $paymentRow['payment_method_id'] = (int) $default_cash_method_id;
                }
            }
            unset($paymentRow);
        }
        
        if (!empty($normalized_payments)) {
            $initial_income_payments_for_js = array_map(function ($paymentRow) {
                return [
                    'paid_on' => $paymentRow['paid_on'],
                    'payment_method_id' => $paymentRow['payment_method_id'],
                    'amount' => number_format($paymentRow['amount'], 2, '.', '')
                ];
            }, $normalized_payments);
        } else {
            $initial_income_payments_for_js = [];
        }

        if ($patient_id !== null && $patient_id > 0) {
            try {
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_patient_name FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1");
                $stmt->execute([$patient_id]);
                $patientRow = $stmt->fetch(PDO::FETCH_ASSOC);
                if (!$patientRow) {
                    $errors[] = trans('patient_note', 'patient_required');
                } else {
                    $patient_name = (string) ($patientRow['dcmt_patient_name'] ?? $patient_name);
                }
            } catch (PDOException $e) {
                error_log("Error fetching patient for income creation: " . $e->getMessage());
                $errors[] = trans('patient_note', 'patient_required');
            }
        } else {
            if ($patient_name === '') {
                $errors[] = trans('patient_note', 'patient_required');
            }
        }
        
        $primary_payment_method_id = null;
        if (!empty($normalized_payments)) {
            foreach ($normalized_payments as $paymentRow) {
                if (!empty($paymentRow['payment_method_id'])) {
                    $primary_payment_method_id = (int) $paymentRow['payment_method_id'];
                    break;
                }
            }
        }
        if ($primary_payment_method_id === null && !empty($default_cash_method_id)) {
            $primary_payment_method_id = (int) $default_cash_method_id;
        }
        
        // Process service items (optional)
        $service_items = $_POST['service_items'] ?? [];
        $service_id = null; // Will be set to first service_id for backward compatibility
        $doctor_id = $doctor_user_id; // Backward compatibility (single doctor field / logging)
        $service_amount = 0.00;
        $valid_service_items = [];
        
        if (!empty($service_items) && is_array($service_items)) {
            foreach ($service_items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                
                $all_empty = (empty($item['doctor_id']) || $item['doctor_id'] === '') &&
                             (empty($item['service_id']) || $item['service_id'] === '') && 
                             (empty($item['quantity']) || $item['quantity'] === '') && 
                             (empty($item['amount']) || $item['amount'] === '');
                
                if ($all_empty) {
                    continue;
                }
                
                $has_any_field = !empty($item['doctor_id']) || !empty($item['service_id']) || !empty($item['quantity']) || !empty($item['amount']);
                
                if ($has_any_field) {
                    if (empty($item['service_id']) || $item['service_id'] === '') {
                        $errors[] = trans('income', 'service_required') . ' (Item ' . ($index + 1) . ')';
                        break;
                    }
                    if (empty($item['quantity']) || $item['quantity'] === '' || floatval($item['quantity']) <= 0) {
                        $errors[] = trans('income', 'service_quantity_required') . ' (Item ' . ($index + 1) . ')';
                        break;
                    }
                    if (empty($item['amount']) || $item['amount'] === '' || floatval($item['amount']) < 0) {
                        $errors[] = trans('income', 'service_amount_required') . ' (Item ' . ($index + 1) . ')';
                        break;
                    }
                    
                    $normalizedQuantity = (int) round(floatval(str_replace(',', '', $item['quantity'])));
                    if ($normalizedQuantity <= 0) {
                        $normalizedQuantity = 1;
                    }
                    $normalizedDoctorId = intval($item['doctor_id']);
                    $normalizedServiceId = intval($item['service_id']);
                    $item['quantity'] = $normalizedQuantity;
                    $item['doctor_id'] = $normalizedDoctorId;
                    $item['service_id'] = $normalizedServiceId;
                    $valid_service_items[] = $item;
                    
                    if ($doctor_id === null && $normalizedDoctorId > 0) {
                        $doctor_id = $normalizedDoctorId;
                    }
                }
            }
            
            // Calculate total service amount from all service items
            foreach ($valid_service_items as $item) {
                $quantity = (int) $item['quantity'];
                $item_amount = floatval($item['amount']);
                $service_amount += $quantity * $item_amount;
                
                if ($service_id === null) {
                    $service_id = intval($item['service_id']);
                }
            }
        }
        
        if (empty($valid_service_items)) {
            // Fallback to old service_id field if service_items not provided
            $service_id = !empty($_POST['service_id']) ? intval($_POST['service_id']) : null;
            $service_amount = floatval($_POST['service_amount'] ?? 0);
        }
        
        // Process product items (optional)
        $product_items = $_POST['product_items'] ?? [];
        $valid_product_items = [];
        $calculated_product_amount = 0.00;
        $all_products_for_use = true;
        $inventory_lookup_map = [];
        
        if (!empty($product_items) && is_array($product_items)) {
            $requested_qty_by_inventory = [];
            $normalized_product_rows = [];
            foreach ($product_items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }

                $has_any_field = (!empty($item['inventory_id']) || !empty($item['quantity']) || !empty($item['unit_price']));
                if (!$has_any_field) {
                    continue;
                }

                if (empty($item['inventory_id']) || $item['inventory_id'] === '') {
                    $errors[] = trans('income', 'select_product') . ' (Item ' . ($index + 1) . ')';
                    break;
                }

                if (empty($item['quantity']) || floatval($item['quantity']) <= 0) {
                    $errors[] = trans('income', 'product_quantity_required') . ' (Item ' . ($index + 1) . ')';
                    break;
                }

                $inventoryId = intval($item['inventory_id']);
                $quantityValue = floatval($item['quantity']);
                $unitPriceValue = isset($item['unit_price']) && $item['unit_price'] !== '' ? floatval($item['unit_price']) : 0.00;
                $product_type = $item['product_type'] ?? 'for_sale';

                $normalized_product_rows[] = [
                    'inventory_id' => $inventoryId,
                    'quantity' => $quantityValue,
                    'unit_price' => $unitPriceValue,
                    'product_type' => $product_type,
                ];

                if (!isset($requested_qty_by_inventory[$inventoryId])) {
                    $requested_qty_by_inventory[$inventoryId] = 0.0;
                }
                $requested_qty_by_inventory[$inventoryId] += $quantityValue;
            }

            if (empty($errors) && !empty($requested_qty_by_inventory)) {
                $inventory_ids = array_keys($requested_qty_by_inventory);
                $placeholders = implode(',', array_fill(0, count($inventory_ids), '?'));
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_quantity, dcmt_name FROM dcmt_inventory WHERE dcmt_id IN ($placeholders)");
                $stmt->execute($inventory_ids);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $inventory_lookup_map[(int) $row['dcmt_id']] = [
                        'quantity' => isset($row['dcmt_quantity']) ? (float) $row['dcmt_quantity'] : 0.0,
                        'name' => (string) ($row['dcmt_name'] ?? ''),
                    ];
                }

                foreach ($requested_qty_by_inventory as $inventoryId => $requestedQty) {
                    $available_stock = $inventory_lookup_map[$inventoryId]['quantity'] ?? 0.0;
                    if ($requestedQty > $available_stock) {
                        $errors[] = trans('income', 'insufficient_stock') . ': ' . $available_stock;
                        break;
                    }
                }
            }

            if (empty($errors)) {
                foreach ($normalized_product_rows as $row) {
                    if (($row['product_type'] ?? 'for_sale') !== 'for_use') {
                        $all_products_for_use = false;
                    }

                    $valid_product_items[] = [
                        'inventory_id' => $row['inventory_id'],
                        'quantity' => $row['quantity'],
                        'unit_price' => $row['unit_price'],
                        'product_type' => $row['product_type'],
                    ];

                    $calculated_product_amount += $row['quantity'] * $row['unit_price'];
                }
            }
        }

        if (empty($errors) && !empty($inventory_lookup_map)) {
            $inventoryInfoMap = [];
            foreach ($inventory_lookup_map as $inventoryId => $info) {
                $inventoryInfoMap[(int) $inventoryId] = [
                    'name' => (string) ($info['name'] ?? ''),
                ];
            }
        } else {
            $inventoryInfoMap = [];
        }
        
        $product_amount = $calculated_product_amount;
        
        // Process note items (optional)
        $note_items = $_POST['note_items'] ?? [];
        $valid_note_items = [];
        $combined_notes = [];
        
        if (!empty($note_items) && is_array($note_items)) {
            foreach ($note_items as $index => $item) {
                if (!is_array($item)) {
                    continue;
                }
                
                $note_topic = isset($item['topic']) ? trim($item['topic']) : '';
                $note_text = isset($item['note_text']) ? trim($item['note_text']) : '';
                
                // Skip if both fields are empty
                if (empty($note_topic) && empty($note_text)) {
                    continue;
                }
                
                // Note text is required if topic is provided or vice versa
                if (!empty($note_topic) && empty($note_text)) {
                    $errors[] = trans('patient_note', 'note_text_required') . ' (Note ' . ($index + 1) . ')';
                    break;
                }
                if (empty($note_topic) && !empty($note_text)) {
                    // Allow note without topic
                }
                
                $valid_note_items[] = [
                    'topic' => $note_topic,
                    'note_text' => $note_text
                ];
                
                // Combine notes for income record
                if (!empty($note_topic)) {
                    $combined_notes[] = $note_topic . ': ' . $note_text;
                } else {
                    $combined_notes[] = $note_text;
                }
            }
        }
        
        // Combine all notes into a single string for income record
        if (!empty($combined_notes)) {
            $note = implode("\n\n", $combined_notes);
        } else {
            $note = isset($_POST['note']) ? trim($_POST['note']) : '';
        }
        
        // Calculate totals based on captured items
        $payments_total = 0.0;
        foreach ($normalized_payments as $paymentRow) {
            $payments_total += $paymentRow['amount'];
        }
        
        $amount = $service_amount + $product_amount;
        $total_paid_amount = round($payments_total, 2);
        
        // Proportional split logic: Split payment based on ratio of service_amount to product_amount
        if ($amount > 0) {
            // Calculate proportions
            $service_ratio = $service_amount / $amount;
            $product_ratio = $product_amount / $amount;
            
            // Split payment proportionally
            $service_paid_amount = min($total_paid_amount * $service_ratio, $service_amount);
            $product_paid_amount = min($total_paid_amount * $product_ratio, $product_amount);
            
            // If one component is fully paid, distribute remainder to the other
            $actual_service_paid = min($service_paid_amount, $service_amount);
            $actual_product_paid = min($product_paid_amount, $product_amount);
            $total_distributed = $actual_service_paid + $actual_product_paid;
            
            if ($total_distributed < $total_paid_amount) {
                $remaining = $total_paid_amount - $total_distributed;
                if ($actual_service_paid < $service_amount) {
                    // Service can take more
                    $actual_service_paid = min($actual_service_paid + $remaining, $service_amount);
                    // Recalculate product if service took all
                    if ($actual_service_paid >= $service_amount && $actual_service_paid < $total_paid_amount) {
                        $remaining_after_service = $total_paid_amount - $actual_service_paid;
                        $actual_product_paid = min($actual_product_paid + $remaining_after_service, $product_amount);
                    }
                } else {
                    // Product can take more
                    $actual_product_paid = min($actual_product_paid + $remaining, $product_amount);
                }
            }
            
            $service_paid_amount = round($actual_service_paid, 2);
            $product_paid_amount = round($actual_product_paid, 2);
            // Rounding each component can make sum != total paid; normalize so totals match and caps hold
            $target_paid = min($total_paid_amount, $amount);
            $service_paid_amount = min($service_paid_amount, $service_amount);
            $product_paid_amount = min($product_paid_amount, $product_amount);
            $sum_paid = $service_paid_amount + $product_paid_amount;
            if ($sum_paid > $target_paid + 0.00001) {
                $excess = round($sum_paid - $target_paid, 2);
                if ($product_paid_amount + 1e-9 >= $excess) {
                    $product_paid_amount = round($product_paid_amount - $excess, 2);
                } else {
                    $excess -= $product_paid_amount;
                    $product_paid_amount = 0.0;
                    $service_paid_amount = round($service_paid_amount - $excess, 2);
                }
            } elseif ($sum_paid < $target_paid - 0.00001) {
                $deficit = round($target_paid - $sum_paid, 2);
                $room_service = max(0.0, $service_amount - $service_paid_amount);
                $add_s = min($deficit, $room_service);
                $service_paid_amount = round($service_paid_amount + $add_s, 2);
                $deficit = round($deficit - $add_s, 2);
                if ($deficit > 0.00001) {
                    $room_product = max(0.0, $product_amount - $product_paid_amount);
                    $product_paid_amount = round($product_paid_amount + min($deficit, $room_product), 2);
                }
            }
        } else {
            // Fallback if amount is zero
            $service_paid_amount = 0;
            $product_paid_amount = 0;
        }
        
        $service_pending_amount = max($service_amount - $service_paid_amount, 0);
        $product_pending_amount = max($product_amount - $product_paid_amount, 0);
        $total_pending_amount = max($amount - $total_paid_amount, 0);
        
        $_POST['service_amount'] = number_format($service_amount, 2, '.', '');
        $_POST['product_amount'] = number_format($product_amount, 2, '.', '');
        $_POST['service_paid_amount'] = number_format($service_paid_amount, 2, '.', '');
        $_POST['product_paid_amount'] = number_format($product_paid_amount, 2, '.', '');
        $_POST['service_pending_amount'] = number_format($service_pending_amount, 2, '.', '');
        $_POST['product_pending_amount'] = number_format($product_pending_amount, 2, '.', '');
        $_POST['total_paid_amount'] = number_format($total_paid_amount, 2, '.', '');
        $_POST['total_pending_amount'] = number_format($total_pending_amount, 2, '.', '');
        $_POST['amount'] = number_format($amount, 2, '.', '');
        
        $required_fields = ['patient_name', 'transaction_date'];
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if (!$validation_result['valid']) {
            $errors = array_merge($errors, $validation_result['errors']);
        }
        
        $has_service_items = !empty($valid_service_items);
        $has_product_items = !empty($valid_product_items);
        
        if (!$has_service_items && !$has_product_items) {
            $errors[] = trans('income', 'service_or_product_required');
        }
        
        // Validate product paid amount (should never be negative)
        if ($product_paid_amount < 0) {
            $errors[] = trans('income', 'product_paid_amount_negative');
        }
        
        // Validate total paid amount
        $calculated_total_paid = $service_paid_amount + $product_paid_amount;
        if (abs($total_paid_amount - $calculated_total_paid) > 0.01) {
            $errors[] = trans('income', 'total_paid_amount_mismatch');
        }
        
        // Validate total pending amount
        $calculated_total_pending = $amount - $total_paid_amount;
        if (abs($total_pending_amount - $calculated_total_pending) > 0.02) {
            error_log("Total pending amount mismatch - Amount: $amount, Total Paid: $total_paid_amount, Service Amount: $service_amount, Product Amount: $product_amount");
            $errors[] = trans('income', 'total_pending_amount_mismatch') . " (Expected: " . number_format($calculated_total_pending, 2) . ", Got: " . number_format($total_pending_amount, 2) . ")";
        }
        
        if ($total_paid_amount > $amount) {
            $errors[] = trans('income', 'total_paid_exceeds_total_amount');
        }
        
        // Validate date
        $date_error = dcmt_validate_date_field($transaction_date, 'transaction_date');
        if ($date_error) {
            $errors[] = $date_error;
        }
        
        // If no validation errors, proceed with saving
        if (empty($errors)) {
            // Debug: Log the values being submitted
            error_log("Income submission - Patient: $patient_name, Type: $type, Amount: $amount, Doctor ID: " . ($doctor_id ?? 'null'));
            
            try {
                $current_user = dcmt_get_current_user();
                $recorded_by_username = is_array($current_user) && !empty($current_user['dcmt_username'])
                    ? $current_user['dcmt_username']
                    : 'system';
                
                $dcmt_pdo->beginTransaction();
                
                // Payment status is now manually selected by user
                
                $has_service_items = !empty($valid_service_items);
                $has_product_items = !empty($valid_product_items);
                
                if ($has_service_items) {
                    $type = 'consultation';
                } elseif ($has_product_items) {
                    $type = 'product_sale';
                    } else {
                    $type = 'consultation';
                }
                
                // Determine final doctor
                $final_user_id = $doctor_user_id ?? $product_sale_doctor_user_id ?? null;
                
                // Determine payment status
                $stmt_status_completed = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_status WHERE LOWER(dcmt_name) LIKE '%completed%' OR LOWER(dcmt_name) LIKE '%paid%' LIMIT 1");
                $stmt_status_pending = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_status WHERE LOWER(dcmt_name) LIKE '%pending%' LIMIT 1");
                
                $final_payment_status_id = null;
                $is_fully_paid = ($amount > 0) ? ($total_pending_amount <= 0.01) : false;
                
                if (!$has_service_items && $has_product_items && $all_products_for_use) {
                    $stmt_status_completed->execute();
                    $completed_status = $stmt_status_completed->fetch();
                            $final_payment_status_id = $completed_status ? $completed_status['dcmt_id'] : null;
                } elseif ($is_fully_paid) {
                    $stmt_status_completed->execute();
                    $completed_status = $stmt_status_completed->fetch();
                            $final_payment_status_id = $completed_status ? $completed_status['dcmt_id'] : null;
                        } else {
                    $stmt_status_pending->execute();
                    $pending_status = $stmt_status_pending->fetch();
                        $final_payment_status_id = $pending_status ? $pending_status['dcmt_id'] : null;
                    }
                    
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_income (
                            dcmt_patient_name, dcmt_patient_id, dcmt_type, dcmt_description, dcmt_note, dcmt_amount,
                            dcmt_paid_amount, dcmt_pending_amount, dcmt_consultation_paid_amount, dcmt_product_paid_amount,
                            dcmt_total_paid_amount, dcmt_total_pending_amount, dcmt_consultation_fee, dcmt_service_id,
                            dcmt_service_amount, dcmt_service_paid_amount, dcmt_service_pending_amount, dcmt_product_amount, dcmt_product_pending_amount,
                            dcmt_payment_method_id, dcmt_payment_status_id, dcmt_user_id, dcmt_transaction_date, dcmt_created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                    $patient_name,
                    $patient_id,
                    $type,
                    $description,
                    !empty($note) ? $note : null,
                    $amount,
                    $total_paid_amount,
                    $total_pending_amount,
                    $service_paid_amount,
                    $product_paid_amount,
                    $total_paid_amount,
                    $total_pending_amount,
                    null,
                    $service_id,
                    $service_amount,
                    $service_paid_amount,
                    $service_pending_amount,
                    $product_amount,
                    $product_pending_amount,
                    $primary_payment_method_id,
                    $final_payment_status_id,
                    $final_user_id,
                    $transaction_date,
                    $recorded_by_username
                    ]);
                    
                    $income_id = $dcmt_pdo->lastInsertId();

                // Save each note to patient_notes table if patient_id exists
                if (!empty($patient_id) && !empty($valid_note_items)) {
                    $current_user = dcmt_get_current_user();
                    $created_by = $current_user['dcmt_username'] ?? 'system';
                    
                    foreach ($valid_note_items as $note_item) {
                        if (empty($note_item['note_text'])) {
                            continue;
                        }
                        
                        try {
                            $note_stmt = $dcmt_pdo->prepare("
                                INSERT INTO dcmt_patient_notes (dcmt_patient_id, dcmt_note_date, dcmt_topic, dcmt_note_text, dcmt_created_by, dcmt_created_at) 
                                VALUES (?, ?, ?, ?, ?, NOW())
                            ");
                            $note_stmt->execute([
                                $patient_id,
                                $transaction_date ?: date('Y-m-d'),
                                !empty($note_item['topic']) ? $note_item['topic'] : null,
                                $note_item['note_text'],
                                $created_by
                            ]);
                        } catch (PDOException $e) {
                            error_log("Error saving patient note from income form: " . $e->getMessage());
                            // Don't fail the entire transaction if note saving fails
                        }
                    }
                }

                $payment_history_type = 'general';
                if ($has_service_items && !$has_product_items) {
                    $payment_history_type = 'consultation';
                } elseif (!$has_service_items && $has_product_items) {
                    $payment_history_type = 'product';
                    }

                    if (!empty($normalized_payments)) {
                        foreach ($normalized_payments as $paymentRow) {
                            dcmt_add_payment_history_entry(
                                $dcmt_pdo,
                                (int) $income_id,
                            $payment_history_type,
                                $paymentRow['amount'],
                                $paymentRow['paid_on'],
                                $recorded_by_username,
                                $paymentRow['payment_method_id']
                            );
                        }
                    }
                    
                $breakdownInsertStmt = $dcmt_pdo->prepare("
                    INSERT INTO dcmt_income_breakdown (
                        dcmt_id,
                        dcmt_line_no,
                        dcmt_line_type,
                        dcmt_reference_id,
                        dcmt_label,
                        dcmt_quantity,
                        dcmt_unit_price,
                        dcmt_line_total,
                        dcmt_user_id,
                        dcmt_inventory_id,
                        dcmt_metadata
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                ");

                $lineNo = 1;

                $serviceNameMap = [];
                if (!empty($valid_service_items)) {
                    $serviceIds = array_unique(array_map(function ($item) {
                        return (int) $item['service_id'];
                    }, $valid_service_items));

                    if (!empty($serviceIds)) {
                        $servicePlaceholders = implode(',', array_fill(0, count($serviceIds), '?'));
                        $serviceStmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_services WHERE dcmt_id IN ($servicePlaceholders)");
                        $serviceStmt->execute($serviceIds);
                        foreach ($serviceStmt->fetchAll() as $serviceRow) {
                            $serviceNameMap[(int) $serviceRow['dcmt_id']] = $serviceRow['dcmt_name'];
                        }
                    }
                }

                foreach ($valid_service_items as $item) {
                    $quantity = (int) $item['quantity'];
                    $item_price = floatval($item['amount']);
                    $total_price = $quantity * $item_price;
                    $serviceId = (int) $item['service_id'];
                    $label = $serviceNameMap[$serviceId] ?? 'Service Item';
                    $metadata = json_encode([
                        'service_id' => $serviceId,
                        'doctor_id' => $item['doctor_id']
                    ], JSON_UNESCAPED_UNICODE);

                    $breakdownInsertStmt->execute([
                        $income_id,
                        $lineNo++,
                        'service',
                        $serviceId,
                        $label,
                        $quantity,
                        $item_price,
                        $total_price,
                        $item['doctor_id'],
                        null,
                        $metadata
                    ]);
                }

                $inventoryNameMap = [];
                if (!empty($inventoryInfoMap) && is_array($inventoryInfoMap)) {
                    foreach ($inventoryInfoMap as $inventoryId => $info) {
                        $inventoryNameMap[(int) $inventoryId] = $info['name'] ?? '';
                    }
                }

                if (!empty($valid_product_items)) {
                    $updateInventoryStmt = $dcmt_pdo->prepare("
                        UPDATE dcmt_inventory 
                        SET dcmt_quantity = dcmt_quantity - ? 
                        WHERE dcmt_id = ?
                    ");

                    // Determine default doctor for products (use final_user_id if available, otherwise default_doctor_user_id)
                    $product_doctor_user_id = $final_user_id ?? $default_doctor_user_id ?? null;

                    foreach ($valid_product_items as $item) {
                        $quantity = floatval($item['quantity']);
                        $unit_price = floatval($item['unit_price']);
                        $total_price = $quantity * $unit_price;
                        $inventoryId = (int) $item['inventory_id'];
                        $label = $inventoryNameMap[$inventoryId] ?? 'Product Item';
                        $metadata = json_encode([
                            'inventory_id' => $inventoryId,
                            'inventory_name' => $label,
                            'product_type' => $item['product_type'] ?? 'for_sale'
                        ], JSON_UNESCAPED_UNICODE);

                        $breakdownInsertStmt->execute([
                            $income_id,
                            $lineNo++,
                            'product',
                            $inventoryId,
                            $label,
                            $quantity,
                            $unit_price,
                            $total_price,
                            $product_doctor_user_id,
                            $inventoryId,
                            $metadata
                        ]);

                        $updateInventoryStmt->execute([$quantity, $inventoryId]);
                    }
                }
                    
                    $log_total_amount = dcmt_format_currency($amount);
                    $log_total_paid = dcmt_format_currency($total_paid_amount);
                    $log_details = sprintf(
                        'Income ID: %d | Total payment: %s | Total income: %s',
                        $income_id,
                        $log_total_amount,
                        $log_total_paid
                    );
                    dcmt_log_activity('Income added', $log_details);
                
                $dcmt_pdo->commit();
                
                dcmt_show_message(trans('income', 'add_success'), 'success');
                
                // Redirect to income list
                dcmt_redirect('index.php');
                
            } catch (PDOException $e) {
                $dcmt_pdo->rollBack();
                error_log("Income creation failed: " . $e->getMessage());
                $errors[] = trans('income', 'create_failed');
            }
        }
    }
}

$needs_form_data = ($_SERVER['REQUEST_METHOD'] !== 'POST') || !empty($errors);
if ($needs_form_data) {
    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_full_name as dcmt_name, dcmt_email, dcmt_qualification, dcmt_specialization_id FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name");
        $stmt->execute();
        $doctors = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching doctors for income form: " . $e->getMessage());
        $doctors = [];
    }

    $all_patients = [];
    $selected_patient_id_for_form = '';
    $selected_patient_name_for_form = '';
    $selected_patient_text_for_form = '';
    $posted_selected_patient_id = $_POST['patient_id'] ?? '';
    if ($posted_selected_patient_id !== '') {
        $selected_patient_id_for_form = (string) (int) $posted_selected_patient_id;
        try {
            $stmt = $dcmt_pdo->prepare("SELECT dcmt_patient_name, dcmt_phone, dcmt_status FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1");
            $stmt->execute([(int) $selected_patient_id_for_form]);
            $patientRow = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($patientRow) {
                $name = (string) ($patientRow['dcmt_patient_name'] ?? '');
                $phone = (string) ($patientRow['dcmt_phone'] ?? '');
                $status = (string) ($patientRow['dcmt_status'] ?? 'active');
                $displayText = $name;
                if ($phone !== '') {
                    $displayText .= ' - ' . $phone;
                }
                if ($status !== 'active') {
                    $displayText .= ' (' . trans('common', 'inactive') . ')';
                }
                $selected_patient_name_for_form = $name;
                $selected_patient_text_for_form = $displayText;
            }
        } catch (PDOException $e) {
            error_log("Error fetching selected patient for income form: " . $e->getMessage());
        }
    }

    try {
        $stmt = $dcmt_pdo->prepare("SELECT i.dcmt_id, i.dcmt_name, i.dcmt_brand, i.dcmt_quantity, i.dcmt_price, c.dcmt_name as category_name, c.dcmt_product_type 
                                    FROM dcmt_inventory i 
                                    LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id 
                                    WHERE i.dcmt_status = 'active' AND i.dcmt_quantity > 0 
                                    ORDER BY i.dcmt_name");
        $stmt->execute();
        $inventory_items = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching inventory for income form: " . $e->getMessage());
        $inventory_items = [];
    }

    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active' ORDER BY dcmt_name");
        $stmt->execute();
        $income_payment_methods = $stmt->fetchAll();
    } catch (PDOException $e) {
        error_log("Error fetching income payment methods: " . $e->getMessage());
        $income_payment_methods = [];
    }

    if ($default_cash_method_id === null && !empty($income_payment_methods)) {
        foreach ($income_payment_methods as $method) {
            if (strtolower($method['dcmt_name']) === 'cash') {
                $default_cash_method_id = (int) $method['dcmt_id'];
                break;
            }
        }
    }

    $posted_service_items = $_POST['service_items'] ?? [];
    $first_posted_service_item = $posted_service_items[0] ?? [];

    $posted_product_items = $_POST['product_items'] ?? [];
    $posted_note_items = $_POST['note_items'] ?? [];
    $posted_income_payments = $_POST['income_payments'] ?? [];

    $doctors_for_js = array_map(function ($doctor) {
        return [
            'id' => (int) ($doctor['dcmt_id'] ?? 0),
            'name' => $doctor['dcmt_name'] ?? ''
        ];
    }, $doctors);

    $initial_service_items_for_js = [];
    if (is_array($posted_service_items)) {
        foreach ($posted_service_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $allEmpty = (empty($item['doctor_id']) && empty($item['service_id']) && empty($item['quantity']) && empty($item['amount']));
            if ($allEmpty) {
                continue;
            }
            $initial_service_items_for_js[] = [
                'doctor_id' => isset($item['doctor_id']) && $item['doctor_id'] !== '' ? (int) $item['doctor_id'] : null,
                'service_id' => isset($item['service_id']) && $item['service_id'] !== '' ? (int) $item['service_id'] : null,
                'quantity' => isset($item['quantity']) ? (string) $item['quantity'] : '1',
                'amount' => isset($item['amount']) ? (string) $item['amount'] : ''
            ];
        }
    }

    $initial_product_items_for_js = [];
    if (is_array($posted_product_items)) {
        foreach ($posted_product_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hasAnyField = (
                (isset($item['inventory_id']) && $item['inventory_id'] !== '') ||
                (isset($item['quantity']) && $item['quantity'] !== '') ||
                (isset($item['unit_price']) && $item['unit_price'] !== '')
            );
            if (!$hasAnyField) {
                continue;
            }
            $initial_product_items_for_js[] = [
                'inventory_id' => isset($item['inventory_id']) && $item['inventory_id'] !== '' ? (int) $item['inventory_id'] : null,
                'quantity' => isset($item['quantity']) ? (string) $item['quantity'] : '',
                'unit_price' => isset($item['unit_price']) ? (string) $item['unit_price'] : '',
                'product_type' => isset($item['product_type']) ? (string) $item['product_type'] : ''
            ];
        }
    }

    $initial_note_items_for_js = [];
    if (is_array($posted_note_items)) {
        foreach ($posted_note_items as $item) {
            if (!is_array($item)) {
                continue;
            }
            $hasAnyField = (
                (isset($item['topic']) && trim($item['topic']) !== '') ||
                (isset($item['note_text']) && trim($item['note_text']) !== '')
            );
            if (!$hasAnyField) {
                continue;
            }
            $initial_note_items_for_js[] = [
                'topic' => isset($item['topic']) ? trim($item['topic']) : '',
                'note_text' => isset($item['note_text']) ? trim($item['note_text']) : ''
            ];
        }
    }

    $initial_income_payments_for_js = [];
    if (is_array($posted_income_payments)) {
        foreach ($posted_income_payments as $paymentRow) {
            if (!is_array($paymentRow)) {
                continue;
            }
            $initial_income_payments_for_js[] = [
                'paid_on' => isset($paymentRow['paid_on']) ? (string) $paymentRow['paid_on'] : '',
                'payment_method_id' => isset($paymentRow['payment_method_id']) && $paymentRow['payment_method_id'] !== '' ? (int) $paymentRow['payment_method_id'] : null,
                'amount' => isset($paymentRow['amount']) ? (string) $paymentRow['amount'] : ''
            ];
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

$service_payment_form_values = [];

$product_payment_form_values = [];

$dcmt_currency_symbol = dcmt_get_current_currency();

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>

<link href="../../assets/css/add-income.css" rel="stylesheet">
<link href="../../assets/css/select2.min.css" rel="stylesheet">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('income', 'add_income'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('income', 'view_all_income'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="incomeForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <input type="hidden" id="type" name="type" value="<?php echo htmlspecialchars($_POST['type'] ?? 'consultation'); ?>">
        
        <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="transaction_date" class="form-label"><?php echo trans('income', 'transaction_date'); ?> *</label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" 
                               value="<?php echo htmlspecialchars($_POST['transaction_date'] ?? dcmt_get_current_date()); ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <label for="patient_name" class="form-label mb-0"><?php echo trans('income', 'patient_name'); ?> *</label>
                            <a href="#" class="dcmt-add-form-view-all-link" id="quickAddPatientBtn" title="<?php echo trans('income', 'quick_add_patient'); ?>">
                                <i class="fas fa-plus me-1"></i><?php echo trans('income', 'quick_add_patient'); ?>
                            </a>
                        </div>
                        <select class="form-select" id="patient_name" name="patient_id" required>
                            <option value=""><?php echo trans('income', 'patient_name_placeholder'); ?></option>
                            <?php if (!empty($selected_patient_id_for_form) && !empty($selected_patient_text_for_form)): ?>
                                <option value="<?php echo htmlspecialchars($selected_patient_id_for_form); ?>" data-name="<?php echo htmlspecialchars($selected_patient_name_for_form ?? ''); ?>" selected>
                                    <?php echo htmlspecialchars($selected_patient_text_for_form); ?>
                                </option>
                            <?php endif; ?>
                        </select>
                        <input type="hidden" id="patient_name_text" name="patient_name" value="<?php echo htmlspecialchars($selected_patient_name_for_form ?? ''); ?>">
                    </div>
                </div>
            </div>
            
            <!-- Description Field - Second row above buttons -->
            <div class="row">
                <div class="col-12">
                    <div class="mb-3">
                        <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="<?php echo trans('income', 'description_placeholder'); ?>"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
                    </div>
                </div>
            </div>
            
            <!-- Service Items Buttons - Always visible above service items section -->
            <div id="serviceButtonsSection" class="mb-3">
                <div class="d-flex flex-wrap gap-2">
                    <button type="button" class="btn btn-outline-primary btn-sm d-flex align-items-center" id="addServiceBtn" onclick="toggleServiceItems()" aria-label="<?php echo trans('income', 'add_service'); ?>">
                        <i class="fas fa-plus"></i>
                        <span id="addServiceBtnText" class="ms-1"><?php echo trans('income', 'add_service'); ?></span>
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm d-flex align-items-center" id="addProductBtn" onclick="toggleProductItems()" aria-label="<?php echo trans('income', 'add_product'); ?>">
                        <i class="fas fa-plus"></i>
                        <span id="addProductBtnText" class="ms-1"><?php echo trans('income', 'add_product'); ?></span>
                    </button>
                    <button type="button" class="btn btn-outline-primary btn-sm d-flex align-items-center" id="addNoteBtn" onclick="toggleNoteItems()" aria-label="<?php echo trans('income', 'add_note'); ?>">
                        <i class="fas fa-plus"></i>
                        <span id="addNoteBtnText" class="ms-1"><?php echo trans('income', 'add_note'); ?></span>
                    </button>
                </div>
            </div>
            <?php
                $items_prompt_text = trans('income', 'add_services_or_products_prompt');
                if ($items_prompt_text === 'add_services_or_products_prompt') {
                    $items_prompt_text = 'Add services or products using the buttons above.';
                }
                $show_items_prompt = empty($posted_service_items) && empty($posted_product_items);
            ?>
            <p id="itemsEmptyMessage" class="text-muted small mb-3" style="<?php echo $show_items_prompt ? '' : 'display:none;'; ?>">
                <?php echo htmlspecialchars($items_prompt_text); ?>
            </p>
            
            <!-- Service Items Section -->
            <div id="serviceItems" class="dcmt-items-panel" style="<?php echo !empty($posted_service_items) ? 'display: block;' : 'display: none;'; ?>">
                <h6 class="mb-3 d-flex align-items-center dcmt-items-header">
                    <?php echo trans('income', 'service_items'); ?>
                </h6>
                <div class="service-item row mb-2">
                            <div class="col-md-3">
                                <select class="form-select service-doctor-select" name="service_items[0][doctor_id]">
                                    <option value=""><?php echo trans('income', 'select_doctor'); ?></option>
                                    <?php 
                                        $defaultRowDoctor = $first_posted_service_item['doctor_id'] ?? ($_POST['doctor_id'] ?? $default_doctor_user_id ?? '');
                                        $defaultRowServiceId = $first_posted_service_item['service_id'] ?? '';
                                        $defaultRowQuantity = $first_posted_service_item['quantity'] ?? '1';
                                        $defaultRowAmount = $first_posted_service_item['amount'] ?? '';
                                        $defaultRowTotal = '';
                                        if ($defaultRowQuantity !== '' && $defaultRowAmount !== '') {
                                            $defaultRowTotal = number_format((float)$defaultRowQuantity * (float)$defaultRowAmount, 2, '.', '');
                                        }
                                    ?>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <?php $isSelectedDoctor = (string)$defaultRowDoctor === (string)$doctor['dcmt_id']; ?>
                                        <option value="<?php echo $doctor['dcmt_id']; ?>" <?php echo $isSelectedDoctor ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($doctor['dcmt_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select service-select" name="service_items[0][service_id]" <?php echo empty($defaultRowServiceId) ? 'disabled' : ''; ?>>
                                    <option value=""><?php echo trans('service', 'select_service'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <input type="number" class="form-control service-quantity" name="service_items[0][quantity]" 
                                       placeholder="<?php echo trans('income', 'qty'); ?>" min="1" value="<?php echo htmlspecialchars($defaultRowQuantity); ?>">
                            </div>
                            <div class="col-md-2">
                                <div class="dcmt-amount-input-wrapper">
                                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                    <input type="number" class="form-control dcmt-amount-input service-amount" name="service_items[0][amount]" 
                                           placeholder="<?php echo trans('common', 'amount'); ?>" step="0.01" min="0" 
                                           value="<?php echo htmlspecialchars($defaultRowAmount); ?>">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="dcmt-amount-input-wrapper">
                                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                    <input type="text" class="form-control dcmt-amount-input service-total" placeholder="<?php echo trans('income', 'total'); ?>" value="<?php echo htmlspecialchars($defaultRowTotal); ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-1 dcmt-delete-cell">
                                <button type="button" class="btn btn-outline-danger btn-sm remove-service-btn">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
            </div>

            <!-- Product Items Section - Displayed under serviceItems -->
            <div id="productItems" class="dcmt-items-panel" style="<?php echo !empty($posted_product_items) ? 'display: block;' : 'display: none;'; ?>">
                <h6 class="mb-3 d-flex align-items-center dcmt-items-header">
                    <?php echo trans('income', 'product_items'); ?>
                </h6>
                <div class="product-item row mb-2">
                    <div class="col-md-4">
                        <select class="form-select product-inventory" name="product_items[0][inventory_id]" onchange="updateProductPrice(this, 0); checkAndShowProductPaidAmount();">
                            <option value=""><?php echo trans('income', 'select_product'); ?></option>
                            <?php foreach ($inventory_items as $item): ?>
                                <?php $dcmt_inventory_brand = trim((string)($item['dcmt_brand'] ?? '')); ?>
                                <option value="<?php echo $item['dcmt_id']; ?>" 
                                        data-price="<?php echo $item['dcmt_price']; ?>"
                                        data-stock="<?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>"
                                        data-product-type="<?php echo $item['dcmt_product_type'] ?? 'for_sale'; ?>">
                                    <?php echo htmlspecialchars($item['dcmt_name']); ?> 
                                    (<?php echo trans('income', 'stock'); ?>: <?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>)
                                    <?php echo $dcmt_inventory_brand !== '' ? ' - ' . htmlspecialchars($dcmt_inventory_brand) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control product-quantity" name="product_items[0][quantity]" 
                               placeholder="<?php echo trans('income', 'qty'); ?>" min="1" value="" onchange="updateProductTotal(0)">
                    </div>
                    <div class="col-md-2" style="display: none;">
                        <div class="dcmt-amount-input-wrapper">
                            <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                            <input type="number" class="form-control dcmt-amount-input product-price" name="product_items[0][unit_price]" 
                                   placeholder="<?php echo trans('income', 'price'); ?>" onchange="updateProductTotal(0)">
                        </div>
                    </div>
                    <div class="col-md-2" style="display: none;">
                        <div class="dcmt-amount-input-wrapper">
                            <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                            <input type="text" class="form-control dcmt-amount-input product-total" placeholder="<?php echo trans('income', 'total'); ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-2 dcmt-delete-cell">
                        <input type="hidden" class="product-type" name="product_items[0][product_type]" value="">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-product-btn">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Note Items Section -->
            <div id="noteItems" class="dcmt-items-panel" style="<?php echo !empty($posted_note_items) ? 'display: block;' : 'display: none;'; ?>">
                <h6 class="mb-3 d-flex align-items-center dcmt-items-header">
                    <?php echo trans('income', 'note_items'); ?>
                </h6>
                <div class="note-item row mb-2">
                    <div class="col-md-4">
                        <input type="text" class="form-control note-topic" name="note_items[0][topic]" 
                               placeholder="<?php echo trans('patient_note', 'topic_placeholder'); ?>" maxlength="255"
                               value="<?php echo htmlspecialchars($posted_note_items[0]['topic'] ?? ''); ?>">
                    </div>
                    <div class="col-md-7">
                        <textarea class="form-control note-text" name="note_items[0][note_text]" rows="2" 
                                  placeholder="<?php echo trans('patient_note', 'note_placeholder'); ?>"><?php echo htmlspecialchars($posted_note_items[0]['note_text'] ?? ''); ?></textarea>
                    </div>
                    <div class="col-md-1 dcmt-delete-cell">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-note-btn">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
            </div>
            
            <!-- Payment Amounts Section -->
            <div id="paymentAmountsSection" style="display: block;">
                <input type="hidden" id="service_amount" name="service_amount" value="<?php echo htmlspecialchars($_POST['service_amount'] ?? '0.00'); ?>">
                <input type="hidden" id="service_paid_amount" name="service_paid_amount" value="<?php echo htmlspecialchars($_POST['service_paid_amount'] ?? '0.00'); ?>">
                <input type="hidden" id="service_pending_amount" name="service_pending_amount" value="<?php echo htmlspecialchars($_POST['service_pending_amount'] ?? '0.00'); ?>">
                <input type="hidden" id="product_amount" name="product_amount" value="<?php echo htmlspecialchars($_POST['product_amount'] ?? '0.00'); ?>">
                <input type="hidden" id="product_paid_amount" name="product_paid_amount" value="<?php echo htmlspecialchars($_POST['product_paid_amount'] ?? '0.00'); ?>">
                <input type="hidden" id="product_pending_amount" name="product_pending_amount" value="<?php echo htmlspecialchars($_POST['product_pending_amount'] ?? '0.00'); ?>">
                <input type="hidden" id="amount" name="amount" value="<?php echo htmlspecialchars($_POST['amount'] ?? '0.00'); ?>">
                <input type="hidden" id="total_paid_amount" name="total_paid_amount" value="<?php echo htmlspecialchars($_POST['total_paid_amount'] ?? '0.00'); ?>">
                <input type="hidden" id="total_pending_amount" name="total_pending_amount" value="<?php echo htmlspecialchars($_POST['total_pending_amount'] ?? '0.00'); ?>">

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'service_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="serviceTotalDisplay">0.00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'product_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="productTotalDisplay">0.00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'total_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="grandTotalDisplay">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="mt-3">
                    <div class="border rounded p-3 bg-light" id="totalPaymentsSection">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button" class="btn btn-outline-primary btn-sm d-flex align-items-center" id="addTotalPaymentBtn" onclick="dcmtAddPaymentRow('total')" aria-label="<?php echo trans('income', 'add_payment'); ?>">
                                <i class="fas fa-plus"></i>
                                <span class="ms-1"><?php echo trans('income', 'add_payment'); ?></span>
                            </button>
                        </div>
                        <div id="totalPaymentsContainer" class="dcmt-partial-payments-container"></div>
                        <p class="text-muted small mb-0 d-none" id="totalPaymentsEmpty"><?php echo trans('income', 'no_payments_recorded'); ?></p>
                    </div>
                </div>

                <div class="row g-3 mt-3">
                    <div class="col-md-6 mt-0">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'total_paid_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="totalPaidDisplay">0.00</span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mt-0">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'total_pending_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="totalPendingDisplay">0.00</span>
                            </div>
                        </div>
                    </div>
                </div>

                <div id="service_paid_warning" class="text-danger mt-1 small" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo trans('income', 'service_paid_exceeds_service_amount'); ?>
                </div>
                <div id="product_paid_warning" class="text-danger mt-1 small" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo trans('income', 'product_paid_exceeds_product_amount'); ?>
                </div>
                <div id="paid_amount_warning" class="text-danger mt-1 small" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo trans('income', 'paid_amount_exceeds_total'); ?>
                </div>
                <div id="pending_amount_warning" class="text-danger mt-1 small" style="display: none;">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo trans('income', 'pending_amount_negative'); ?>
                </div>
            </div>

            
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                <i class="fas fa-undo"></i><?php echo trans('income', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('income', 'add_income_record'); ?>
            </button>
        </div>
        </form>
</div>


<script>
// Translation variables for JavaScript
const translations = {
    selectProduct: '<?php echo addslashes(trans('income', 'select_product')); ?>',
    qty: '<?php echo addslashes(trans('income', 'qty')); ?>',
    price: '<?php echo addslashes(trans('income', 'price')); ?>',
    total: '<?php echo addslashes(trans('income', 'total')); ?>',
    addProduct: '<?php echo addslashes(trans('income', 'add_product')); ?>',
    addAnotherProduct: '<?php echo addslashes(trans('income', 'add_another_product')); ?>',
    addService: '<?php echo addslashes(trans('income', 'add_service')); ?>',
    addAnotherService: '<?php echo addslashes(trans('income', 'add_another_service')); ?>',
    confirmDeleteService: '<?php echo addslashes(trans('income', 'confirm_delete_service')); ?>',
    confirmDeleteProduct: '<?php echo addslashes(trans('income', 'confirm_delete_product')); ?>',
    // Provide localized unsaved changes message used by main.js
    unsaved_changes_message: <?php echo json_encode(trans('common', 'unsaved_changes_message')); ?>,
};

const serviceItemsHeaderHtml = `<h6 class="mb-3 d-flex align-items-center dcmt-items-header">
    <?php echo addslashes(trans('income', 'service_items')); ?>
</h6>`;

const productItemsHeaderHtml = `<h6 class="mb-3 d-flex align-items-center dcmt-items-header">
    <?php echo addslashes(trans('income', 'product_items')); ?>
</h6>`;

const noteItemsHeaderHtml = `<h6 class="mb-3 d-flex align-items-center dcmt-items-header">
    <?php echo addslashes(trans('income', 'note_items')); ?>
</h6>`;

function dcmtEnsureItemsHeader(container, headerHtml) {
    if (!container) {
        return;
    }
    if (!container.querySelector('.dcmt-items-header')) {
        container.insertAdjacentHTML('afterbegin', headerHtml);
    }
}

function updateItemsEmptyMessage() {
    const message = document.getElementById('itemsEmptyMessage');
    if (!message) {
        return;
    }
    const serviceCount = document.querySelectorAll('#serviceItems .service-item').length;
    const productCount = document.querySelectorAll('#productItems .product-item').length;
    const noteCount = document.querySelectorAll('#noteItems .note-item').length;
    message.style.display = (serviceCount === 0 && productCount === 0 && noteCount === 0) ? '' : 'none';
}

const addProductLabelDefault = translations.addProduct || '<?php echo addslashes(trans('income', 'add_product')); ?>';
const addProductLabelAdditional = translations.addAnotherProduct || '<?php echo addslashes(trans('income', 'add_another_product')); ?>';

const productInventoryOptionsHTML = `<option value=""><?php echo addslashes(trans('income', 'select_product')); ?></option>
<?php foreach ($inventory_items as $item): ?>
<?php $dcmt_inventory_brand = trim((string)($item['dcmt_brand'] ?? '')); ?>
<option value="<?php echo $item['dcmt_id']; ?>"
        data-price="<?php echo $item['dcmt_price']; ?>"
        data-stock="<?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>"
        data-product-type="<?php echo $item['dcmt_product_type'] ?? 'for_sale'; ?>">
    <?php echo htmlspecialchars($item['dcmt_name']); ?>
    (<?php echo addslashes(trans('income', 'stock')); ?>: <?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>)
    <?php echo $dcmt_inventory_brand !== '' ? ' - ' . htmlspecialchars($dcmt_inventory_brand) : ''; ?>
</option>
<?php endforeach; ?>`.trim();

window.inventoryOptionsHTML = window.inventoryOptionsHTML || productInventoryOptionsHTML;

function dcmtGetProductInventoryOptionsHTML() {
    if (window.inventoryOptionsHTML && window.inventoryOptionsHTML.trim() !== '') {
        return window.inventoryOptionsHTML;
    }
    const firstSelect = document.querySelector('#productItems .product-inventory');
    if (firstSelect) {
        window.inventoryOptionsHTML = firstSelect.innerHTML;
        return window.inventoryOptionsHTML;
    }
    return productInventoryOptionsHTML;
}

function determineIncomeMode() {
    const serviceCount = document.querySelectorAll('#serviceItems .service-item').length;
    const productCount = document.querySelectorAll('#productItems .product-item').length;
    if (serviceCount > 0) {
        return 'consultation';
    }
    if (productCount > 0) {
        return 'product_sale';
    }
    return 'consultation';
}


function syncIncomeType() {
    const form = document.getElementById('incomeForm');
    const mode = determineIncomeMode();
    if (form) {
        form.dataset.incomeMode = mode;
    }
    if (typeof updateProductFieldsHelp === 'function') {
        updateProductFieldsHelp(mode);
    }
    updateItemsEmptyMessage();
}

function ensureProductItemsVisible() {
    const productItems = document.getElementById('productItems');
    if (productItems) {
        productItems.style.display = 'block';
        dcmtEnsureItemsHeader(productItems, productItemsHeaderHtml);
    }
}

function setAddProductButtonLabel(text) {
    const addProductBtnText = document.getElementById('addProductBtnText');
    if (addProductBtnText) {
        addProductBtnText.textContent = text;
    }
}

function updateAddProductButtonLabel() {
    const productItemsContainer = document.getElementById('productItems');
    if (!productItemsContainer) {
        return;
    }
    const productItemCount = productItemsContainer.querySelectorAll('.product-item').length;
    if (productItemCount >= 1) {
        setAddProductButtonLabel(addProductLabelAdditional);
    } else {
        setAddProductButtonLabel(addProductLabelDefault);
    }
}

const paymentMethodsData = <?php echo json_encode(array_map(function ($method) {
    $methodName = $method['dcmt_name'] ?? '';
    $translatedName = trans('income_payment_method', $methodName);
    $displayName = ($translatedName !== $methodName) ? $translatedName : $methodName;
    return [
        'id' => (int) ($method['dcmt_id'] ?? 0),
        'name' => $displayName
    ];
}, $income_payment_methods), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const initialIncomePayments = <?php echo json_encode($initial_income_payments_for_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

const dcmtPartialPaymentTranslations = {
    paymentDate: '<?php echo addslashes(trans('income', 'payment_date')); ?>',
    paymentAmount: '<?php echo addslashes(trans('income', 'payment_amount')); ?>',
    paymentMethod: '<?php echo addslashes(trans('income', 'payment_method')); ?>',
    selectPaymentMethod: '<?php echo addslashes(trans('income', 'select_payment_method')); ?>'
};

const dcmtInitialPayments = {
    total: initialIncomePayments
};

const dcmtCurrencySymbolClient = <?php echo json_encode($dcmt_currency_symbol, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
const dcmtCurrentDate = <?php echo json_encode(dcmt_get_current_date('Y-m-d'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
const dcmtPaymentConfig = {
    total: {
        containerId: 'totalPaymentsContainer',
        emptyStateId: 'totalPaymentsEmpty',
        amountFieldId: 'total_paid_amount',
        inputName: 'income_payments'
    }
};

// Make translations available globally for main.js (merge, do not overwrite)
window.translations = Object.assign({}, window.translations || {}, translations);

// Default doctor ID from PHP
const defaultDoctorId = <?php echo $default_doctor_user_id ? json_encode($default_doctor_user_id) : 'null'; ?>;
const defaultCashMethodId = <?php echo $default_cash_method_id ? json_encode($default_cash_method_id) : 'null'; ?>;
const doctorsData = <?php echo json_encode($doctors_for_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const initialServiceItems = <?php echo json_encode($initial_service_items_for_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const initialProductItems = <?php echo json_encode($initial_product_items_for_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
const initialNoteItems = <?php echo json_encode($initial_note_items_for_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function getNumericFieldValue(fieldId) {
    const field = document.getElementById(fieldId);
    if (!field) {
        return 0;
    }
    const value = parseFloat(field.value);
    return isNaN(value) ? 0 : value;
}

function updateIncomeAmountDisplays() {
    const serviceAmount = getNumericFieldValue('service_amount');
    const productAmount = getNumericFieldValue('product_amount');
    const totalAmount = serviceAmount + productAmount;
    
    const serviceDisplay = document.getElementById('serviceTotalDisplay');
    if (serviceDisplay) {
        serviceDisplay.textContent = serviceAmount.toFixed(2);
    }
    
    const productDisplay = document.getElementById('productTotalDisplay');
    if (productDisplay) {
        productDisplay.textContent = productAmount.toFixed(2);
    }
    
    const grandDisplay = document.getElementById('grandTotalDisplay');
    if (grandDisplay) {
        grandDisplay.textContent = totalAmount.toFixed(2);
    }
    
    const amountField = document.getElementById('amount');
    if (amountField) {
        amountField.value = totalAmount.toFixed(2);
    }
    
    return { serviceAmount, productAmount, totalAmount };
}

function applyPaymentDistribution(totalPaid) {
    const { serviceAmount, productAmount, totalAmount } = updateIncomeAmountDisplays();
    
    // Proportional split logic: Split payment based on ratio of serviceAmount to productAmount
    let servicePaid = 0;
    let productPaid = 0;
    
    if (totalAmount > 0) {
        // Calculate proportions
        const serviceRatio = serviceAmount / totalAmount;
        const productRatio = productAmount / totalAmount;
        
        // Split payment proportionally
        servicePaid = Math.min(totalPaid * serviceRatio, serviceAmount);
        productPaid = Math.min(totalPaid * productRatio, productAmount);
        
        // If one component is fully paid, distribute remainder to the other
        let actualServicePaid = Math.min(servicePaid, serviceAmount);
        let actualProductPaid = Math.min(productPaid, productAmount);
        let totalDistributed = actualServicePaid + actualProductPaid;
        
        if (totalDistributed < totalPaid) {
            const remaining = totalPaid - totalDistributed;
            if (actualServicePaid < serviceAmount) {
                // Service can take more
                actualServicePaid = Math.min(actualServicePaid + remaining, serviceAmount);
                // Recalculate product if service took all
                if (actualServicePaid >= serviceAmount && actualServicePaid < totalPaid) {
                    const remainingAfterService = totalPaid - actualServicePaid;
                    actualProductPaid = Math.min(actualProductPaid + remainingAfterService, productAmount);
                }
            } else {
                // Product can take more
                actualProductPaid = Math.min(actualProductPaid + remaining, productAmount);
            }
        }
        
        servicePaid = Math.round(actualServicePaid * 100) / 100; // Round to 2 decimals
        productPaid = Math.round(actualProductPaid * 100) / 100;
        const targetPaid = Math.min(totalPaid, totalAmount);
        servicePaid = Math.min(servicePaid, serviceAmount);
        productPaid = Math.min(productPaid, productAmount);
        let sumPaid = servicePaid + productPaid;
        if (sumPaid > targetPaid + 1e-9) {
            let excess = Math.round((sumPaid - targetPaid) * 100) / 100;
            if (productPaid + 1e-9 >= excess) {
                productPaid = Math.round((productPaid - excess) * 100) / 100;
            } else {
                excess -= productPaid;
                productPaid = 0;
                servicePaid = Math.round((servicePaid - excess) * 100) / 100;
            }
        } else if (sumPaid < targetPaid - 1e-9) {
            let deficit = Math.round((targetPaid - sumPaid) * 100) / 100;
            const roomService = Math.max(0, serviceAmount - servicePaid);
            const addS = Math.min(deficit, roomService);
            servicePaid = Math.round((servicePaid + addS) * 100) / 100;
            deficit = Math.round((deficit - addS) * 100) / 100;
            if (deficit > 1e-9) {
                const roomProduct = Math.max(0, productAmount - productPaid);
                productPaid = Math.round((productPaid + Math.min(deficit, roomProduct)) * 100) / 100;
            }
        }
    }
    
    const constrainedTotalPaid = servicePaid + productPaid;
    const totalPending = Math.max(totalAmount - constrainedTotalPaid, 0);
    
    const servicePaidField = document.getElementById('service_paid_amount');
    if (servicePaidField) {
        servicePaidField.value = servicePaid.toFixed(2);
    }
    
    const productPaidField = document.getElementById('product_paid_amount');
    if (productPaidField) {
        productPaidField.value = productPaid.toFixed(2);
    }
    
    const servicePendingField = document.getElementById('service_pending_amount');
    if (servicePendingField) {
        servicePendingField.value = Math.max(serviceAmount - servicePaid, 0).toFixed(2);
    }
    
    const productPendingField = document.getElementById('product_pending_amount');
    if (productPendingField) {
        productPendingField.value = Math.max(productAmount - productPaid, 0).toFixed(2);
    }
    
    const totalPaidField = document.getElementById('total_paid_amount');
    if (totalPaidField) {
        totalPaidField.value = constrainedTotalPaid.toFixed(2);
    }
    
    const totalPendingField = document.getElementById('total_pending_amount');
    if (totalPendingField) {
        totalPendingField.value = totalPending.toFixed(2);
    }
    
    const totalPaidDisplay = document.getElementById('totalPaidDisplay');
    if (totalPaidDisplay) {
        totalPaidDisplay.textContent = constrainedTotalPaid.toFixed(2);
    }
    
    const totalPendingDisplay = document.getElementById('totalPendingDisplay');
    if (totalPendingDisplay) {
        totalPendingDisplay.textContent = totalPending.toFixed(2);
    }
    
    if (typeof updatePaymentCalculations === 'function') {
        updatePaymentCalculations();
    }
}

function refreshPaymentSummaries() {
    const totalPaid = getNumericFieldValue('total_paid_amount');
    applyPaymentDistribution(totalPaid);
}

function getDefaultIncomePaymentMethodId() {
    if (defaultCashMethodId) {
        return String(defaultCashMethodId);
    }
    if (Array.isArray(paymentMethodsData) && paymentMethodsData.length > 0) {
        return String(paymentMethodsData[0].id);
    }
    return '';
}

function buildPaymentMethodOptions(selectedId) {
    if (!Array.isArray(paymentMethodsData)) {
        return '';
    }
    return paymentMethodsData.map(method => {
        const isSelected = selectedId !== null && selectedId !== undefined && String(selectedId) === String(method.id);
        return '<option value="' + method.id + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(method.name) + '</option>';
    }).join('');
}

// Function to get current date in Mexico timezone
function getMexicoDate() {
    const now = new Date();
    // Mexico is UTC-6, so we need to adjust for the timezone
    const mexicoTime = new Date(now.getTime() - (6 * 60 * 60 * 1000));
    return mexicoTime.toISOString().split('T')[0];
}

// Debug: Log current language and translations
// Payment calculation functions are now centralized in main.js

// Function to reset inventory options HTML to prevent repeated selections
function resetInventoryOptionsHTML() {
    
    // Get fresh inventory options from the first product item (template)
    const firstProductItem = document.querySelector('.product-item');
    if (firstProductItem) {
        const inventorySelect = firstProductItem.querySelector('.product-inventory');
        if (inventorySelect) {
            // Store fresh options without any selections
            if (typeof inventoryOptionsHTML !== 'undefined') {
                inventoryOptionsHTML = inventorySelect.innerHTML;
            }
        }
    }
}

// Function to reset consultation-related items when switching to product sale
function resetConsultationItems() {
    
    // Reset service items
    const serviceItemsContainer = document.getElementById('serviceItems');
    if (serviceItemsContainer) {
        const serviceItems = Array.from(serviceItemsContainer.querySelectorAll('.service-item'));
        serviceItems.forEach(row => {
            const doctorSelect = row.querySelector('.service-doctor-select');
            const serviceSelect = row.querySelector('.service-select');
            if (typeof $ !== 'undefined') {
                if (doctorSelect && $(doctorSelect).hasClass('select2-hidden-accessible')) {
                    $(doctorSelect).select2('destroy');
                }
                if (serviceSelect && $(serviceSelect).hasClass('select2-hidden-accessible')) {
                    $(serviceSelect).select2('destroy');
                }
            }
            row.remove();
        });
        const addServiceBtnText = document.getElementById('addServiceBtnText');
        if (addServiceBtnText) {
            addServiceBtnText.textContent = '<?php echo trans('income', 'add_service'); ?>';
        }
        const addServiceBtn = document.getElementById('addServiceBtn');
        if (addServiceBtn) {
            addServiceBtn.onclick = toggleServiceItems;
        }
        serviceItemsContainer.style.display = 'none';
    }
    
    // Reset service amount
    const serviceAmountFieldReset = document.getElementById('service_amount');
    if (serviceAmountFieldReset) {
        serviceAmountFieldReset.value = '0.00';
    }
    
    // Hide service amounts row
    const serviceAmountsRow = document.getElementById('serviceAmountsRow');
    if (serviceAmountsRow) {
        serviceAmountsRow.style.display = 'none';
    }
    
    // Update totals
    if (typeof updateServiceTotal === 'function') {
        updateServiceTotal();
    }
    
    // Remove all product items (these are consultation-specific products)
    const productItemsContainer = document.getElementById('productItems');
    if (productItemsContainer) {
        // Destroy Select2 instances before removing
        productItemsContainer.querySelectorAll('.product-inventory').forEach(select => {
            if (typeof $ !== 'undefined' && $(select).hasClass('select2-hidden-accessible')) {
                $(select).select2('destroy');
            }
        });
        productItemsContainer.innerHTML = '';
        productItemsContainer.style.display = 'none';
        const addProductBtn = document.getElementById('addProductBtn');
        if (addProductBtn) {
            addProductBtn.onclick = toggleProductItems;
            setAddProductButtonLabel(addProductLabelDefault);
        }
    }
    
    // Reset consultation fee
    const consultationFeeField = document.getElementById('consultation_fee');
    if (consultationFeeField) {
        consultationFeeField.value = '';
    }
    
    // Service amount already reset above, no need to reset again
    
    // Reset payment amounts
    const consultationPaidField = document.getElementById('consultation_paid_amount');
    if (consultationPaidField) {
        consultationPaidField.value = '0.00';
    }
    
    const productPaidField = document.getElementById('product_paid_amount');
    if (productPaidField) {
        productPaidField.value = '0.00';
    }
    
    // Reset inventory options HTML to prevent repeated selections
    resetInventoryOptionsHTML();
    
    // Recalculate totals
    if (typeof updatePaymentCalculations === 'function') {
        updatePaymentCalculations();
    }
    refreshPaymentSummaries();
    
    setAddProductButtonLabel(addProductLabelDefault);

    syncIncomeType();
}

// Function to reset product sale-related items when switching to consultation
function resetProductSaleItems() {
    // Remove all product items (these are product sale-specific products)
    const productItemsContainer = document.getElementById('productItems');
    if (productItemsContainer) {
        // Destroy Select2 instances before removing
        productItemsContainer.querySelectorAll('.product-inventory').forEach(select => {
            if (typeof $ !== 'undefined' && $(select).hasClass('select2-hidden-accessible')) {
                $(select).select2('destroy');
            }
        });
        
        productItemsContainer.innerHTML = '';
        productItemsContainer.style.display = 'none';
        const addProductBtn = document.getElementById('addProductBtn');
        if (addProductBtn) {
            addProductBtn.onclick = toggleProductItems;
            setAddProductButtonLabel(addProductLabelDefault);
        }
    }
    
    // Reset product amounts
    const productAmountField = document.getElementById('product_amount');
    if (productAmountField) {
        productAmountField.value = '0.00';
    }
    
    const productPaidField = document.getElementById('product_paid_amount');
    if (productPaidField) {
        productPaidField.value = '0.00';
    }
    
    // Reset inventory options HTML to prevent repeated selections
    resetInventoryOptionsHTML();
    
    // Recalculate totals
    if (typeof updatePaymentCalculations === 'function') {
        updatePaymentCalculations();
    }
    refreshPaymentSummaries();
    
    syncIncomeType();
}

// Function to toggle field visibility now that income type is automatic
function toggleAddIncomeTypeFields() {
    const paymentAmountsSection = document.getElementById('paymentAmountsSection');
    const consultationPaidField = document.getElementById('consultationPaidField');
    const productPaidField = document.getElementById('productPaidField');
    const totalPaidField = document.getElementById('totalPaidField');
    const totalPendingField = document.getElementById('totalPendingField');
        if (paymentAmountsSection) {
        paymentAmountsSection.style.display = 'block';
        }
        if (consultationPaidField) {
        consultationPaidField.style.display = 'block';
    }
    if (productPaidField) {
        productPaidField.style.display = 'block';
        }
        if (totalPaidField) {
        totalPaidField.style.display = 'block';
        }
        if (totalPendingField) {
        totalPendingField.style.display = 'block';
    }
    
    checkAndShowProductPaidAmount();
    updatePaymentCalculations();
    refreshPaymentSummaries();
}

// Test function to manually trigger field visibility (for debugging)
function testToggleTypeFields() {

    toggleTypeFields();
}

// Function to check if there are product items and show product amounts row accordingly
function checkAndShowProductPaidAmount() {
    const productAmountsRow = document.getElementById('productAmountsRow');
    if (!productAmountsRow) {
        return;
    }
    
    let hasProductItems = false;
    let hasForUseOnly = true;
    
    document.querySelectorAll('#productItems .product-inventory').forEach(select => {
        if (select.value && select.value !== '') {
            hasProductItems = true;
            
            let selectedOption;
            if (typeof $ !== 'undefined' && $(select).hasClass('select2-hidden-accessible')) {
                selectedOption = $(select).find('option:selected');
            } else {
                const selectedIndex = select.selectedIndex;
                if (selectedIndex > 0) {
                    selectedOption = select.options[selectedIndex];
                }
            }
            
            if (selectedOption && (selectedOption.length || selectedOption)) {
                const productType = selectedOption.attr ? selectedOption.attr('data-product-type') : selectedOption.getAttribute('data-product-type');
                if (productType !== 'for_use') {
                    hasForUseOnly = false;
                }
            }
        }
    });
    
        productAmountsRow.style.display = (hasProductItems && !hasForUseOnly) ? 'block' : 'none';
}

// Define reset function with project prefix to avoid collisions
function dcmt_resetIncomeForm() {
    // Reset form immediately
    const form = document.getElementById('incomeForm');
    if (form) {
        form.reset();
    }
    
    // Manually clear and set specific fields to ensure proper reset
    const fields = {
        'patient_name': '',
        'description': '',
        'amount': '0.00',
        'service_amount': '0.00',
        'service_paid_amount': '0.00',
        'service_pending_amount': '0.00',
        'product_amount': '0.00',
        'product_paid_amount': '0.00',
        'product_pending_amount': '0.00',
        'total_paid_amount': '0.00',
        'total_pending_amount': '0.00',
        'type': 'consultation',
        'transaction_date': getMexicoDate()
    };
    
    // Clear each field individually
    Object.keys(fields).forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.value = fields[fieldId];
            // Trigger change for Select2 fields
            if (typeof $ !== 'undefined' && $(field).hasClass('select2-hidden-accessible')) {
                $(field).trigger('change');
            }
        }
    });
    
    // Remove any visible server-side error alert
    const errorAlert = document.querySelector('.alert.alert-danger');
    if (errorAlert && errorAlert.parentNode) {
        errorAlert.parentNode.removeChild(errorAlert);
    }
    
    // Clear validation/UI states and warnings
    document.querySelectorAll('.is-invalid').forEach(function(el){ el.classList.remove('is-invalid'); });
    document.querySelectorAll('.invalid-feedback').forEach(function(el){ el.textContent = ''; });
    const warningIds = ['paid_amount_warning', 'pending_amount_warning', 'service_paid_warning', 'product_paid_warning'];
    warningIds.forEach(function(id){ const el = document.getElementById(id); if (el) el.style.display = 'none'; });
    
    // Re-enable and restore submit button text
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('income', 'add_income_record'); ?>';
    }
    
    // Focus first field
    const firstField = document.getElementById('patient_name');
    if (firstField) {
        firstField.focus();
    }
    
    // Set income type to consultation and update dependent UI
    const typeSelectEl = document.getElementById('type');
    if (typeSelectEl) {
        typeSelectEl.value = 'consultation';
        typeSelectEl.dispatchEvent(new Event('change', { bubbles: true }));
    }

    if (typeof resetConsultationItems === 'function') {
        resetConsultationItems();
    }
    if (typeof resetProductSaleItems === 'function') {
        resetProductSaleItems();
    }
    if (typeof toggleAddIncomeTypeFields === 'function') {
        toggleAddIncomeTypeFields();
    }
    
    // Apply default doctor to the first service row if available
    if (typeof defaultDoctorId !== 'undefined' && defaultDoctorId) {
        const serviceItemsContainer = document.getElementById('serviceItems');
        if (serviceItemsContainer) {
            const firstDoctorSelect = serviceItemsContainer.querySelector('.service-item .service-doctor-select');
            if (firstDoctorSelect) {
                firstDoctorSelect.value = String(defaultDoctorId);
                handleServiceDoctorChange(firstDoctorSelect, 0);
            }
        }
    }
    
    // Partial payments are managed later from the edit screen.
    
    // Load services for the selected doctor
    if (typeof loadDoctorServices === 'function') {
        setTimeout(() => { loadDoctorServices(); }, 150);
    }
    if (typeof updatePaymentCalculations === 'function') {
        updatePaymentCalculations();
    }

    setAddProductButtonLabel(addProductLabelDefault);
}

const selectDoctorText = '<?php echo addslashes(trans('income', 'select_doctor')); ?>';
const selectServiceText = '<?php echo addslashes(trans('service', 'select_service')); ?>';
const noServicesAssignedText = '<?php echo addslashes(trans('service', 'no_services_assigned')); ?>';
const loadingText = '<?php echo addslashes(trans('common', 'loading')); ?>';

const doctorPlaceholderOption = '<option value="">' + selectDoctorText + '</option>';
const servicePlaceholderOption = '<option value="">' + selectServiceText + '</option>';
const noServicesAssignedOption = '<option value="">' + noServicesAssignedText + '</option>';
const loadingOption = '<option value="">' + loadingText + '...</option>';

const doctorServiceCache = {};

function escapeHtml(value) {
    if (value === null || value === undefined) {
        return '';
    }
    return String(value)
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;')
        .replace(/"/g, '&quot;')
        .replace(/'/g, '&#039;');
}

function ensureSelect2Initialized(select, placeholder) {
    if (typeof $ === 'undefined' || !select) {
        return;
    }
    const $select = $(select);
    if ($select.hasClass('select2-hidden-accessible')) {
        $select.select2('destroy');
    }
    $select.select2({
        placeholder,
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
}

function getDoctorOptionsHTML(selectedDoctorId) {
    let options = doctorPlaceholderOption;
    if (Array.isArray(doctorsData)) {
        doctorsData.forEach(doctor => {
            const value = String(doctor.id);
            const isSelected = selectedDoctorId !== null && selectedDoctorId !== undefined && String(selectedDoctorId) === value;
            options += '<option value="' + value + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(doctor.name) + '</option>';
        });
    }
    return options;
}

function getServiceOptionsHTML(services, selectedServiceId) {
    if (!Array.isArray(services) || services.length === 0) {
        return noServicesAssignedOption;
    }
    
    let options = servicePlaceholderOption;
    services.forEach(service => {
        const value = String(service.id);
        const isSelected = selectedServiceId !== null && selectedServiceId !== undefined && String(selectedServiceId) === value;
        const price = service.price !== undefined && service.price !== null ? String(service.price) : '';
        const label = escapeHtml(service.name) + ' (' + escapeHtml(service.formatted_price) + ')';
        options += '<option value="' + value + '" data-price="' + escapeHtml(price) + '"' + (isSelected ? ' selected' : '') + '>' + label + '</option>';
    });
    return options;
}

function clearServiceSelect(serviceSelect) {
    if (!serviceSelect) {
        return;
    }
    delete serviceSelect.dataset.selectedServiceId;
    serviceSelect.innerHTML = servicePlaceholderOption;
    serviceSelect.value = '';
    serviceSelect.disabled = true;
    ensureSelect2Initialized(serviceSelect, selectServiceText);
}

function loadServicesForDoctor(doctorId) {
    if (!doctorId) {
        return Promise.resolve([]);
    }
    const cacheKey = String(doctorId);
    if (doctorServiceCache.hasOwnProperty(cacheKey)) {
        return Promise.resolve(doctorServiceCache[cacheKey]);
    }
    
    return fetch('get_doctor_services.php?doctor_id=' + encodeURIComponent(doctorId))
        .then(response => response.json())
        .then(data => {
            const services = (data && data.success && Array.isArray(data.services)) ? data.services : [];
            doctorServiceCache[cacheKey] = services;
            return services;
        })
        .catch(() => {
            doctorServiceCache[cacheKey] = [];
            return [];
        });
}

function populateServiceSelectForRow(row, doctorId, selectedServiceId = null) {
    const serviceSelect = row ? row.querySelector('.service-select') : null;
    if (!serviceSelect) {
        return Promise.resolve();
    }
    
    if (!doctorId) {
        clearServiceSelect(serviceSelect);
        return Promise.resolve();
    }
    
    serviceSelect.disabled = true;
    serviceSelect.innerHTML = loadingOption;
    ensureSelect2Initialized(serviceSelect, selectServiceText);
    
    return loadServicesForDoctor(doctorId).then(services => {
        serviceSelect.innerHTML = getServiceOptionsHTML(services, selectedServiceId);
        serviceSelect.disabled = services.length === 0;
        
        if (selectedServiceId) {
            serviceSelect.value = String(selectedServiceId);
        } else {
            serviceSelect.value = '';
        }
        
        ensureSelect2Initialized(serviceSelect, selectServiceText);
        
        if (typeof $ !== 'undefined') {
            const $select = $(serviceSelect);
            if (selectedServiceId) {
                $select.val(String(selectedServiceId)).trigger('change.select2');
            } else {
                $select.val('').trigger('change.select2');
            }
        }
    }).catch(() => {
        clearServiceSelect(serviceSelect);
    });
}

function handleServiceDoctorChange(select, index, preselectedServiceId = null, options = {}) {
    const row = select ? select.closest('.service-item') : null;
    if (!row) {
        return;
    }
    const serviceSelect = row.querySelector('.service-select');
    const quantityInput = row.querySelector('.service-quantity');
    const amountInput = row.querySelector('.service-amount');
    const totalInput = row.querySelector('.service-total');
    const preserveAmount = options.preserveAmount === true;
    
    if (serviceSelect) {
        if (preselectedServiceId !== null && preselectedServiceId !== undefined) {
            serviceSelect.dataset.selectedServiceId = String(preselectedServiceId);
        } else {
            delete serviceSelect.dataset.selectedServiceId;
        }
    }
    
    if (!select.value) {
        clearServiceSelect(serviceSelect);
        if (!preserveAmount) {
            if (amountInput) {
                amountInput.value = '';
            }
            if (totalInput) {
                totalInput.value = '';
            }
        }
        updateServiceTotal();
        return;
    }
    
    populateServiceSelectForRow(row, select.value, preselectedServiceId).then(() => {
        const serviceItems = Array.from(document.querySelectorAll('#serviceItems .service-item'));
        const resolvedIndex = typeof index === 'number' ? index : serviceItems.indexOf(row);
        if (!preserveAmount) {
            if (serviceSelect && serviceSelect.value) {
                updateServiceItemAmount(serviceSelect, resolvedIndex);
            } else {
                if (amountInput) amountInput.value = '';
                if (totalInput) totalInput.value = '';
            }
        } else {
            updateServiceItemTotal(resolvedIndex);
        }
        updateServiceTotal();
    });
}

function initializeServiceItemRow(row, index, data = {}) {
    if (!row) {
        return;
    }
    row.dataset.index = index;
    
    const doctorSelect = row.querySelector('.service-doctor-select');
    const serviceSelect = row.querySelector('.service-select');
    const quantityInput = row.querySelector('.service-quantity');
    const amountInput = row.querySelector('.service-amount');
    const totalInput = row.querySelector('.service-total');
    
    const preselectedDoctorId = data.doctor_id ?? (doctorSelect ? doctorSelect.value : null);
    const preselectedServiceId = data.service_id ?? (serviceSelect ? (serviceSelect.dataset.selectedServiceId || serviceSelect.value || null) : null);
    const preselectedQuantity = data.quantity ?? (quantityInput ? quantityInput.value : '1');
    const preselectedAmount = data.amount ?? (amountInput ? amountInput.value : '');
    
    if (doctorSelect) {
        doctorSelect.name = 'service_items[' + index + '][doctor_id]';
        doctorSelect.innerHTML = getDoctorOptionsHTML(preselectedDoctorId);
        const typeField = document.getElementById('type');
        const shouldApplyDefaultDoctor = !typeField || typeField.value === 'consultation';
        if (preselectedDoctorId) {
            doctorSelect.value = String(preselectedDoctorId);
        } else if (shouldApplyDefaultDoctor && typeof defaultDoctorId !== 'undefined' && defaultDoctorId && index === 0) {
            doctorSelect.value = String(defaultDoctorId);
        }
        ensureSelect2Initialized(doctorSelect, selectDoctorText);
    }
    
    if (serviceSelect) {
        serviceSelect.name = 'service_items[' + index + '][service_id]';
        if (preselectedServiceId) {
            serviceSelect.dataset.selectedServiceId = String(preselectedServiceId);
        } else {
            delete serviceSelect.dataset.selectedServiceId;
        }
        ensureSelect2Initialized(serviceSelect, selectServiceText);
    }
    
    if (quantityInput) {
        quantityInput.name = 'service_items[' + index + '][quantity]';
        quantityInput.value = preselectedQuantity || '1';
    }
    
    if (amountInput) {
        amountInput.name = 'service_items[' + index + '][amount]';
        amountInput.value = preselectedAmount;
    }
    
    if (totalInput) {
        const qty = parseFloat(quantityInput ? quantityInput.value : 0) || 0;
        const amt = parseFloat(amountInput ? amountInput.value : 0) || 0;
        totalInput.value = qty && amt ? (qty * amt).toFixed(2) : '';
    }
    
    if (doctorSelect) {
        const doctorValue = doctorSelect.value;
        const preserveAmount = preselectedAmount !== undefined && preselectedAmount !== '';
        handleServiceDoctorChange(doctorSelect, index, preselectedServiceId, { preserveAmount });
    } else if (serviceSelect) {
        clearServiceSelect(serviceSelect);
    }
}

function loadDoctorServices(targetDoctorSelect = null, preselectedServiceId = null, options = {}) {
    if (targetDoctorSelect) {
        handleServiceDoctorChange(targetDoctorSelect, undefined, preselectedServiceId, options);
        return;
    }
    
    const container = document.getElementById('serviceItems');
    if (!container) {
        return;
    }
    
    const serviceItems = Array.from(container.querySelectorAll('.service-item'));
    serviceItems.forEach((row, index) => {
        const doctorSelect = row.querySelector('.service-doctor-select');
        const serviceSelect = row.querySelector('.service-select');
        const selectedServiceId = serviceSelect ? (serviceSelect.dataset.selectedServiceId || serviceSelect.value || null) : null;
        if (doctorSelect && doctorSelect.value) {
            handleServiceDoctorChange(doctorSelect, index, selectedServiceId, { preserveAmount: !!(serviceSelect && serviceSelect.value) });
        } else {
            clearServiceSelect(serviceSelect);
        }
    });
}

function addServiceItem(data = {}) {
    const container = document.getElementById('serviceItems');
    if (!container) {
        return null;
    }
    
    dcmtEnsureItemsHeader(container, serviceItemsHeaderHtml);
    container.style.display = 'block';
    
    const existingItems = container.querySelectorAll('.service-item');
    const currentIndex = existingItems.length;
    
    const row = document.createElement('div');
    row.className = 'service-item row mb-2';
    
    const quantityValue = data.quantity !== undefined ? escapeHtml(data.quantity) : '1';
    const amountValue = data.amount !== undefined ? escapeHtml(data.amount) : '';
    
    row.innerHTML = `
        <div class="col-md-3">
            <select class="form-select service-doctor-select" name="service_items[${currentIndex}][doctor_id]">
                ${getDoctorOptionsHTML(data.doctor_id ?? null)}
            </select>
        </div>
        <div class="col-md-3">
            <select class="form-select service-select" name="service_items[${currentIndex}][service_id]" disabled>
                ${servicePlaceholderOption}
            </select>
        </div>
        <div class="col-md-1">
            <input type="number" class="form-control service-quantity" name="service_items[${currentIndex}][quantity]"
                   placeholder="${escapeHtml(translations.qty)}" min="1" value="${quantityValue}">
        </div>
        <div class="col-md-2">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="number" class="form-control dcmt-amount-input service-amount" name="service_items[${currentIndex}][amount]"
                       placeholder="<?php echo addslashes(trans('common', 'amount')); ?>" step="0.01" min="0" value="${amountValue}">
            </div>
        </div>
        <div class="col-md-2">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="text" class="form-control dcmt-amount-input service-total" placeholder="<?php echo addslashes(trans('income', 'total')); ?>" readonly>
            </div>
        </div>
        <div class="col-md-1 dcmt-delete-cell">
            <button type="button" class="btn btn-outline-danger btn-sm remove-service-btn">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(row);
    initializeServiceItemRow(row, currentIndex, data);
    
    const addServiceBtnText = document.getElementById('addServiceBtnText');
    if (addServiceBtnText && currentIndex >= 0) {
        addServiceBtnText.textContent = '<?php echo trans('income', 'add_another_service'); ?>';
    }
    
    syncIncomeType();
    
    return row;
}

function removeServiceItem(button) {
    // Ensure button is a DOM element
    if (!button) {
        console.warn('removeServiceItem: button parameter is missing');
        return;
    }
    
    const confirmationMessage = translations.confirmDeleteService || '<?php echo addslashes(trans('income', 'confirm_delete_service')); ?>';
    if (!confirm(confirmationMessage)) {
        return;
    }
    const serviceItem = button.closest('.service-item');
    if (serviceItem) {
        // Destroy Select2 before removing the element if jQuery is available and Select2 is initialized
        if (typeof $ !== 'undefined') {
            const serviceSelect = serviceItem.querySelector('.service-select');
            const doctorSelect = serviceItem.querySelector('.service-doctor-select');
            
            // Safely destroy service select
            if (serviceSelect) {
                const $serviceSelect = $(serviceSelect);
                if ($serviceSelect.hasClass('select2-hidden-accessible')) {
                    try {
                        const select2Data = $serviceSelect.data('select2');
                        if (select2Data) {
                            $serviceSelect.select2('destroy');
                        }
                    } catch (e) {
                        console.warn('Error destroying service Select2:', e);
                    }
                }
            }
            
            // Safely destroy doctor select
            if (doctorSelect) {
                const $doctorSelect = $(doctorSelect);
                if ($doctorSelect.hasClass('select2-hidden-accessible')) {
                    try {
                        const select2Data = $doctorSelect.data('select2');
                        if (select2Data) {
                            $doctorSelect.select2('destroy');
                        }
                    } catch (e) {
                        console.warn('Error destroying doctor Select2:', e);
                    }
                }
            }
        }
        serviceItem.remove();
        
        updateServiceTotal();
        
        const container = document.getElementById('serviceItems');
        if (container) {
            const remainingItems = container.querySelectorAll('.service-item');
            const addServiceBtnText = document.getElementById('addServiceBtnText');
            const addServiceBtn = document.getElementById('addServiceBtn');
            if (remainingItems.length === 0) {
                container.style.display = 'none';
                if (addServiceBtn) {
                    addServiceBtn.onclick = toggleServiceItems;
                }
                if (addServiceBtnText) {
                addServiceBtnText.textContent = '<?php echo trans('income', 'add_service'); ?>';
            }
            } else if (addServiceBtnText && remainingItems.length === 1) {
                addServiceBtnText.textContent = '<?php echo trans('income', 'add_another_service'); ?>';
        }
        }
        syncIncomeType();
    }
}

// Make removeServiceItem globally accessible
window.removeServiceItem = removeServiceItem;

// Function to update service item amount when service is selected
function updateServiceItemAmount(select, index) {
    const selectedOption = select.options[select.selectedIndex];
    const serviceItem = select.closest('.service-item');
    const amountInput = serviceItem.querySelector('.service-amount');
    
    if (selectedOption && selectedOption.value && amountInput) {
        const servicePrice = selectedOption.getAttribute('data-price');
        if (servicePrice) {
            amountInput.value = parseFloat(servicePrice).toFixed(2);
            updateServiceItemTotal(index);
        }
    } else if (amountInput) {
        amountInput.value = '';
        updateServiceItemTotal(index);
    }
}

// Function to update individual service item total
function updateServiceItemTotal(index) {
    const container = document.getElementById('serviceItems');
    if (!container) return;
    
    const serviceItems = container.querySelectorAll('.service-item');
    if (serviceItems[index]) {
        const serviceItem = serviceItems[index];
        const quantityInput = serviceItem.querySelector('.service-quantity');
        const amountInput = serviceItem.querySelector('.service-amount');
        const totalInput = serviceItem.querySelector('.service-total');
        
        if (quantityInput && amountInput && totalInput) {
            const quantity = parseFloat(quantityInput.value) || 0;
            const amount = parseFloat(amountInput.value) || 0;
            const total = quantity * amount;
            totalInput.value = total.toFixed(2);
        }
    }
}

// Function to update total service amount
let isUpdatingServiceTotal = false; // Flag to prevent infinite recursion
// Make it accessible globally for form submission
window.isUpdatingServiceTotal = false;
function updateServiceTotal() {
    if (isUpdatingServiceTotal || window.isUpdatingServiceTotal) return; // Prevent recursive calls
    isUpdatingServiceTotal = true;
    window.isUpdatingServiceTotal = true;
    
    const container = document.getElementById('serviceItems');
    if (!container) {
        isUpdatingServiceTotal = false;
        window.isUpdatingServiceTotal = false;
        return;
    }
    
    let totalServiceAmount = 0;
    const serviceItems = container.querySelectorAll('.service-item');
    
    serviceItems.forEach((item, index) => {
        updateServiceItemTotal(index);
        const totalInput = item.querySelector('.service-total');
        if (totalInput && totalInput.value) {
            totalServiceAmount += parseFloat(totalInput.value) || 0;
        }
    });
    
    // Update service_amount field without triggering events
    const serviceAmountInput = document.getElementById('service_amount');
    if (serviceAmountInput) {
        // Remove event listeners temporarily to prevent recursion
        const currentValue = serviceAmountInput.value;
        serviceAmountInput.value = totalServiceAmount.toFixed(2);
        
        // Only trigger update if value actually changed
        if (currentValue !== serviceAmountInput.value) {
            // Show service amounts row if there's a total
            const serviceAmountsRow = document.getElementById('serviceAmountsRow');
            if (serviceAmountsRow) {
                if (totalServiceAmount > 0) {
                    serviceAmountsRow.style.display = 'block';
                } else {
                    serviceAmountsRow.style.display = 'none';
                }
            }
            
            // Immediately update consultation total and payment calculations
            // Don't use setTimeout here to ensure synchronous updates
            if (typeof updateConsultationTotal === 'function') {
                updateConsultationTotal();
            } else if (typeof updatePaymentCalculations === 'function') {
                // Fallback if updateConsultationTotal doesn't exist
                updatePaymentCalculations();
            }
        } else {
            // Even if value didn't change, ensure calculations are up to date
            if (typeof updateConsultationTotal === 'function') {
                updateConsultationTotal();
            }
        }
    }
    
    refreshPaymentSummaries();
    
    isUpdatingServiceTotal = false;
    window.isUpdatingServiceTotal = false;
}

// updateServiceAmount function is now centralized in main.js

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize form change detection
    dcmtInitFormChangeDetection('incomeForm');
    
    const serviceItemsContainer = document.getElementById('serviceItems');
    if (serviceItemsContainer) {
        serviceItemsContainer.innerHTML = '';
        dcmtEnsureItemsHeader(serviceItemsContainer, serviceItemsHeaderHtml);
        if (Array.isArray(initialServiceItems) && initialServiceItems.length > 0) {
            serviceItemsContainer.style.display = 'block';
            const addServiceBtn = document.getElementById('addServiceBtn');
            if (addServiceBtn) {
                addServiceBtn.onclick = addServiceItem;
            }
            initialServiceItems.forEach(item => addServiceItem(item));
        } else {
            serviceItemsContainer.style.display = 'none';
        }
    }
    
    const productItemsContainer = document.getElementById('productItems');
    if (productItemsContainer) {
        productItemsContainer.innerHTML = '';
        dcmtEnsureItemsHeader(productItemsContainer, productItemsHeaderHtml);
        if (Array.isArray(initialProductItems) && initialProductItems.length > 0) {
            productItemsContainer.style.display = 'block';
            const addProductBtn = document.getElementById('addProductBtn');
            if (addProductBtn) {
                addProductBtn.onclick = addProductItem;
            }
            initialProductItems.forEach(item => addProductItem(item));
        } else {
            productItemsContainer.style.display = 'none';
        }
    }
    
    const noteItemsContainer = document.getElementById('noteItems');
    if (noteItemsContainer) {
        noteItemsContainer.innerHTML = '';
        dcmtEnsureItemsHeader(noteItemsContainer, noteItemsHeaderHtml);
        if (Array.isArray(initialNoteItems) && initialNoteItems.length > 0) {
            noteItemsContainer.style.display = 'block';
            const addNoteBtn = document.getElementById('addNoteBtn');
            if (addNoteBtn) {
                addNoteBtn.onclick = addNoteItem;
            }
            initialNoteItems.forEach(item => addNoteItem(item));
        } else {
            noteItemsContainer.style.display = 'none';
        }
    }
    
    syncIncomeType();
    if (typeof checkAndShowProductPaidAmount === 'function') {
        checkAndShowProductPaidAmount();
    }
    
    // Initialize payment calculations
    updatePaymentCalculations();
    refreshPaymentSummaries();
    
    // Monitor payment amount changes to clear warnings
    const totalPendingAmountField = document.getElementById('total_pending_amount');
    const totalPaidAmountField = document.getElementById('total_paid_amount');
    const totalAmountField = document.getElementById('amount');
    
    if (totalPendingAmountField) {
        totalPendingAmountField.addEventListener('input', function() {
            const pendingWarningDiv = document.getElementById('pending_amount_warning');
            const pendingAmount = parseFloat(this.value) || 0;
            
            // Clear pending amount warning if valid
            if (pendingAmount >= 0 && pendingWarningDiv) {
                pendingWarningDiv.style.display = 'none';
                this.classList.remove('is-invalid');
            }
        });
    }
    
    if (totalPaidAmountField && totalAmountField) {
        totalPaidAmountField.addEventListener('input', function() {
            const paidWarningDiv = document.getElementById('paid_amount_warning');
            if (paidWarningDiv) {
                const totalPaidAmount = parseFloat(this.value) || 0;
                const totalAmount = parseFloat(totalAmountField.value) || 0;
                
                // Clear paid amount warning if valid
                if (totalPaidAmount <= totalAmount) {
                    paidWarningDiv.style.display = 'none';
                    this.classList.remove('is-invalid');
                }
            }
        });
    }
    
    // Monitor service paid amount changes to clear warning
    const servicePaidAmountField = document.getElementById('service_paid_amount');
    const serviceAmountField = document.getElementById('service_amount');
    
    if (servicePaidAmountField && serviceAmountField) {
        servicePaidAmountField.addEventListener('input', function() {
            const servicePaidWarningDiv = document.getElementById('service_paid_warning');
            if (servicePaidWarningDiv) {
                const servicePaidAmount = parseFloat(this.value) || 0;
                const serviceAmount = parseFloat(serviceAmountField.value) || 0;
                
                // Clear service paid warning if valid
                if (servicePaidAmount <= serviceAmount) {
                    servicePaidWarningDiv.style.display = 'none';
                    this.classList.remove('is-invalid');
                }
            }
        });
    }
    
    // Monitor product paid amount changes to clear warning
    const productPaidAmountField = document.getElementById('product_paid_amount');
    const productAmountField = document.getElementById('product_amount');
    
    if (productPaidAmountField && productAmountField) {
        productPaidAmountField.addEventListener('input', function() {
            const productPaidWarningDiv = document.getElementById('product_paid_warning');
            if (productPaidWarningDiv) {
                const productPaidAmount = parseFloat(this.value) || 0;
                const productAmount = parseFloat(productAmountField.value) || 0;
                
                // Clear product paid warning if valid
                if (productPaidAmount <= productAmount) {
                    productPaidWarningDiv.style.display = 'none';
                    this.classList.remove('is-invalid');
                }
            }
        });
    }
    
    // Initialize field visibility based on current type selection
    // Add a small delay to ensure all DOM elements are ready
    setTimeout(function() {

        toggleAddIncomeTypeFields();
        
        // Initialize product type handling for existing selections
        initializeProductTypeHandling();
        
        // Setup product item event delegation
        // Check if main.js version exists and remove its remove button handler first
        if (typeof setupProductItemEventDelegation === 'function') {
            // Call the local version which will prevent duplicates
            setupProductItemEventDelegation();
        }
        
        // Setup service item event delegation
        setupServiceItemEventDelegation();
        
        loadDoctorServices();
    }, 100);
    
    // Get form elements
    const form = document.getElementById('incomeForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    const typeSelectEl = document.getElementById('type');
    
    if (!form || !resetBtn) {
        return;
    }
    
    // Wire up change listeners instead of inline handlers
    if (typeSelectEl) {
        typeSelectEl.addEventListener('change', function() {
            setTimeout(function() {
                if (typeof toggleAddIncomeTypeFields === 'function') {
                    toggleAddIncomeTypeFields();
                }
            }, 10);
        });
    }

    // Add form submission loading state
    form.addEventListener('submit', function(e) {
        e.preventDefault(); // Always prevent default first
        
        // Ensure all calculations are up to date before submission
        const typeSelect = document.getElementById('type');
        if (typeSelect && typeSelect.value === 'consultation') {
            // Disable empty service item fields before submission so they don't get submitted
            const serviceItemsContainer = document.getElementById('serviceItems');
            if (serviceItemsContainer) {
                const serviceItems = serviceItemsContainer.querySelectorAll('.service-item');
                let validIndex = 0;
                
                serviceItems.forEach(function(item, index) {
                    const serviceSelect = item.querySelector('.service-select');
                    const doctorSelect = item.querySelector('.service-doctor-select');
                    const quantityInput = item.querySelector('.service-quantity');
                    const amountInput = item.querySelector('.service-amount');
                    
                    // Check if this item is completely empty
                    const isEmpty = (!doctorSelect || !doctorSelect.value || doctorSelect.value === '') &&
                                   (!serviceSelect || !serviceSelect.value || serviceSelect.value === '') &&
                                   (!quantityInput || !quantityInput.value || quantityInput.value === '') &&
                                   (!amountInput || !amountInput.value || amountInput.value === '');
                    
                    if (isEmpty) {
                        // Disable empty fields so they don't get submitted
                        if (doctorSelect) {
                            doctorSelect.disabled = true;
                            doctorSelect.name = '';
                        }
                        if (serviceSelect) {
                            serviceSelect.disabled = true;
                            serviceSelect.name = ''; // Remove name so it doesn't get submitted
                        }
                        if (quantityInput) {
                            quantityInput.disabled = true;
                            quantityInput.name = '';
                        }
                        if (amountInput) {
                            amountInput.disabled = true;
                            amountInput.name = '';
                        }
                    } else {
                        // Reindex valid items to have sequential indices
                        if (doctorSelect) {
                            doctorSelect.disabled = false;
                            doctorSelect.name = 'service_items[' + validIndex + '][doctor_id]';
                        }
                        if (serviceSelect) {
                            serviceSelect.disabled = false;
                            serviceSelect.name = 'service_items[' + validIndex + '][service_id]';
                        }
                        if (quantityInput) {
                            quantityInput.disabled = false;
                            quantityInput.name = 'service_items[' + validIndex + '][quantity]';
                        }
                        if (amountInput) {
                            amountInput.disabled = false;
                            amountInput.name = 'service_items[' + validIndex + '][amount]';
                        }
                        validIndex++;
                    }
                });
            }
            
            // Force recalculation in the correct order, synchronously
            // Step 1: Recalculate service total from all service items
            if (typeof updateServiceTotal === 'function') {
                const wasUpdating = window.isUpdatingServiceTotal;
                window.isUpdatingServiceTotal = false;
                updateServiceTotal();
                window.isUpdatingServiceTotal = wasUpdating;
            }
            
            // Step 2: Ensure service_amount is read correctly, then update amount field
            // Calculate amount directly from service items + product items to ensure accuracy
            // Reuse serviceItemsContainer from above (already declared)
            const serviceAmountInput = document.getElementById('service_amount');
            const totalAmountInput = document.getElementById('amount');
            
            if (serviceAmountInput && totalAmountInput && serviceItemsContainer) {
                // Recalculate service amount directly from service items
                let calculatedServiceAmount = 0;
                const serviceItems = serviceItemsContainer.querySelectorAll('.service-item');
                serviceItems.forEach(function(item) {
                    const quantityInput = item.querySelector('.service-quantity');
                    const serviceAmountField = item.querySelector('.service-amount');
                    if (quantityInput && serviceAmountField && quantityInput.value && serviceAmountField.value) {
                        const qty = parseFloat(quantityInput.value) || 0;
                        const amt = parseFloat(serviceAmountField.value) || 0;
                        calculatedServiceAmount += qty * amt;
                    }
                });
                
                // Update service_amount field
                serviceAmountInput.value = calculatedServiceAmount.toFixed(2);
                
                // Calculate total amount (service + products)
                let totalAmount = calculatedServiceAmount;
                const productTotals = document.querySelectorAll('.product-total');
                productTotals.forEach(function(input) {
                    if (input && input.value) {
                        totalAmount += parseFloat(input.value) || 0;
                    }
                });
                
                // Update amount field
                totalAmountInput.value = totalAmount.toFixed(2);
                
                // Update product amount
                const productAmountInput = document.getElementById('product_amount');
                if (productAmountInput) {
                    let productTotal = 0;
                    productTotals.forEach(function(input) {
                        if (input && input.value) {
                            productTotal += parseFloat(input.value) || 0;
                        }
                    });
                    productAmountInput.value = productTotal.toFixed(2);
                }
            }
            
            // Step 3: Update payment calculations with the correct amounts
            if (typeof updatePaymentCalculations === 'function') {
                const wasUpdating = window.isUpdatingPaymentCalculations;
                window.isUpdatingPaymentCalculations = false;
                updatePaymentCalculations();
                window.isUpdatingPaymentCalculations = wasUpdating;
            }
            
            // Step 4: Validate and submit (use small delay to ensure DOM updates are complete)
            setTimeout(function() {
                validateAndSubmitForm(form);
            }, 50);
        } else {
            // For non-consultation types, validate and submit immediately
            validateAndSubmitForm(form);
        }
    });
    
    // Function to validate and submit the form
    function validateAndSubmitForm(form) {
        // Validate pending amount is not negative
        const totalPendingAmount = parseFloat(document.getElementById('total_pending_amount').value) || 0;
        if (totalPendingAmount < 0) {
            // Show warning message
            const pendingWarningDiv = document.getElementById('pending_amount_warning');
            if (pendingWarningDiv) {
                pendingWarningDiv.style.display = 'block';
            }
            
            // Add error styling to the field
            const totalPendingAmountField = document.getElementById('total_pending_amount');
            if (totalPendingAmountField) {
                totalPendingAmountField.classList.add('is-invalid');
                totalPendingAmountField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        } else {
            // Hide warning if valid
            const pendingWarningDiv = document.getElementById('pending_amount_warning');
            if (pendingWarningDiv) {
                pendingWarningDiv.style.display = 'none';
            }
        }
        
        // Validate paid amount does not exceed total amount
        const totalPaidAmount = parseFloat(document.getElementById('total_paid_amount').value) || 0;
        const totalAmount = parseFloat(document.getElementById('amount').value) || 0;
        if (totalPaidAmount > totalAmount + 0.01) {
            // Show warning message
            const paidWarningDiv = document.getElementById('paid_amount_warning');
            if (paidWarningDiv) {
                paidWarningDiv.style.display = 'block';
            }
            
            // Add error styling to the field
            const totalPaidAmountField = document.getElementById('total_paid_amount');
            if (totalPaidAmountField) {
                totalPaidAmountField.classList.add('is-invalid');
                totalPaidAmountField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        } else {
            // Hide warning if valid
            const paidWarningDiv = document.getElementById('paid_amount_warning');
            if (paidWarningDiv) {
                paidWarningDiv.style.display = 'none';
            }
        }
        
        // Validate service paid amount does not exceed service amount (for consultation type)
        const incomeType = document.getElementById('type').value;
        if (incomeType === 'consultation') {
            const servicePaidAmount = parseFloat(document.getElementById('service_paid_amount').value) || 0;
            const serviceAmount = parseFloat(document.getElementById('service_amount').value) || 0;
            
            if (servicePaidAmount > serviceAmount + 0.01) {
                // Show warning message
                const servicePaidWarningDiv = document.getElementById('service_paid_warning');
                if (servicePaidWarningDiv) {
                    servicePaidWarningDiv.style.display = 'block';
                }
                
                // Add error styling to the field
                const servicePaidAmountField = document.getElementById('service_paid_amount');
                if (servicePaidAmountField) {
                    servicePaidAmountField.classList.add('is-invalid');
                    servicePaidAmountField.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            } else {
                // Hide warning if valid
                const servicePaidWarningDiv = document.getElementById('service_paid_warning');
                if (servicePaidWarningDiv) {
                    servicePaidWarningDiv.style.display = 'none';
                }
            }
        }
        
        // Validate product paid amount does not exceed product amount
        const productPaidAmount = parseFloat(document.getElementById('product_paid_amount').value) || 0;
        const productAmount = parseFloat(document.getElementById('product_amount').value) || 0;
        
        if (productPaidAmount > productAmount + 0.01) {
            // Show warning message
            const productPaidWarningDiv = document.getElementById('product_paid_warning');
            if (productPaidWarningDiv) {
                productPaidWarningDiv.style.display = 'block';
            }
            
            // Add error styling to the field
            const productPaidAmountField = document.getElementById('product_paid_amount');
            if (productPaidAmountField) {
                productPaidAmountField.classList.add('is-invalid');
                productPaidAmountField.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        } else {
            // Hide warning if valid
            const productPaidWarningDiv = document.getElementById('product_paid_warning');
            if (productPaidWarningDiv) {
                productPaidWarningDiv.style.display = 'none';
            }
        }
        
        // All validations passed, clear form changed flag and submit
        if (typeof dcmtClearFormChanged === 'function') {
        dcmtClearFormChanged();
        }
        
        // Show loading state on submit button
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
        submitBtn.setAttribute('data-original-text', originalText);
        }
        
        // Submit the form
        form.submit();
    }
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('<?php echo addslashes(trans('income', 'confirm_reset')); ?>')) {
            dcmt_resetIncomeForm();
        }
    });
});
</script>

<script src="../../assets/js/select2.min.js"></script>
<script>
// Initialize Select2 on product inventory select fields
function initializeSelect2() {
    // Initialize Select2 on existing product inventory selects
    $('.product-inventory').select2({
        placeholder: '<?php echo trans('income', 'select_product'); ?>',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
    
    // Initialize Select2 on service field
    $('#service_id').select2({
        placeholder: '<?php echo trans('service', 'select_service'); ?>',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
    
    // Initialize Select2 on patient name field
    $('#patient_name').select2({
        placeholder: '<?php echo trans('income', 'patient_name_placeholder'); ?>',
        allowClear: true,
        width: '100%',
        minimumInputLength: 0,
        ajax: {
            url: 'add.php?ajax=patient_search',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return {
                    term: params.term || '',
                    limit: 20
                };
            },
            processResults: function (data) {
                return data;
            },
            cache: true
        }
    });
    
    // Update hidden patient_name field when patient is selected
    $('#patient_name').on('change', function() {
        const selectedData = $(this).select2('data');
        if (selectedData && selectedData.length > 0) {
            const selectedName = selectedData[0].name || (selectedData[0].element ? selectedData[0].element.getAttribute('data-name') : '') || '';
            $('#patient_name_text').val(selectedName);
        } else {
            $('#patient_name_text').val('');
        }
    });
    
    // Trigger change on page load if patient is already selected
    if ($('#patient_name').val()) {
        $('#patient_name').trigger('change');
    }
    
    // Re-initialize Select2 when new product items are added
    $(document).on('DOMNodeInserted', '.product-inventory', function() {
        if (!$(this).hasClass('select2-hidden-accessible')) {
            $(this).select2({
                placeholder: '<?php echo trans('income', 'select_product'); ?>',
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: 0
            });
        }
    });
}

// Initialize Select2 when document is ready
$(document).ready(function() {
    initializeSelect2();
    
    // Initialize product type handling for existing selections
    initializeProductTypeHandling();

    updateAddProductButtonLabel();
    
    // Ensure search input is focused when any Select2 opens
    $(document).on('select2:open', function() {
        const searchInput = document.querySelector('.select2-container--open .select2-search__field');
        if (searchInput) {
            searchInput.focus();
        }
    });
});

// Function to initialize product type handling on page load
function initializeProductTypeHandling() {
    // Check all existing product inventory selects
    $('.product-inventory').each(function() {
        const select = this;
        const selectedOption = $(select).find('option:selected');
        const productItem = $(select).closest('.product-item');
        
        if (selectedOption.length && selectedOption.val()) {
            const productType = selectedOption.attr('data-product-type');
            
            if (productType === 'for_use') {
                // Hide price and total fields for "for use" items
                productItem.find('.product-price').closest('.col-md-2').hide();
                productItem.find('.product-total').closest('.col-md-2').hide();
                // Clear price and total values
                productItem.find('.product-price').val('');
                productItem.find('.product-total').val('');
                // Remove validation attributes to prevent form validation errors
                productItem.find('.product-price').removeAttr('required min max step');
                // Set product type in hidden field
                productItem.find('.product-type').val('for_use');
            } else {
                // Show price and total fields for "for sale" items
                productItem.find('.product-price').closest('.col-md-2').show();
                productItem.find('.product-total').closest('.col-md-2').show();
                // Restore validation attributes for "for sale" items
                productItem.find('.product-price').attr('step', '0.01').attr('min', '0.01');
                // Set product type in hidden field
                productItem.find('.product-type').val('for_sale');
            }
        } else {
            // No product selected - keep price and total fields hidden
            productItem.find('.product-price').closest('.col-md-2').hide();
            productItem.find('.product-total').closest('.col-md-2').hide();
        }
    });
}


function toggleServiceItems() {
    const serviceItems = document.getElementById('serviceItems');
    const addServiceBtn = document.getElementById('addServiceBtn');
    if (!serviceItems || !addServiceBtn) {
        return;
    }
    
    if (serviceItems.style.display === 'none' || serviceItems.style.display === '') {
        dcmtEnsureItemsHeader(serviceItems, serviceItemsHeaderHtml);
        serviceItems.style.display = 'block';
        const addServiceText = translations.addService || '<?php echo addslashes(trans('income', 'add_service')); ?>';
        addServiceBtn.setAttribute('aria-label', addServiceText);
        addServiceBtn.onclick = addServiceItem;
        if (serviceItems.querySelectorAll('.service-item').length === 0) {
            addServiceItem();
        } else {
            syncIncomeType();
        }
        return;
    }
    
    addServiceItem();
}

// Function to toggle product items visibility and update button aria label
function toggleProductItems() {
    const productItems = document.getElementById('productItems');
    const addProductBtn = document.getElementById('addProductBtn');

    if (productItems && addProductBtn) {
        if (productItems.style.display === 'none' || productItems.style.display === '') {
            dcmtEnsureItemsHeader(productItems, productItemsHeaderHtml);
            productItems.style.display = 'block';
        }
        addProductBtn.setAttribute('aria-label', translations.addProduct);
        addProductBtn.onclick = addProductItem;
        setAddProductButtonLabel(addProductLabelDefault);
        addProductItem();
    }
}

// Function to toggle note items visibility and update button aria label
function toggleNoteItems() {
    const noteItems = document.getElementById('noteItems');
    const addNoteBtn = document.getElementById('addNoteBtn');

    if (noteItems && addNoteBtn) {
        if (noteItems.style.display === 'none' || noteItems.style.display === '') {
            dcmtEnsureItemsHeader(noteItems, noteItemsHeaderHtml);
            noteItems.style.display = 'block';
        }
        addNoteBtn.setAttribute('aria-label', '<?php echo addslashes(trans('income', 'add_note')); ?>');
        addNoteBtn.onclick = addNoteItem;
        addNoteItem();
    }
}

// Function to add new note item row
function addNoteItem(data = {}) {
    const container = document.getElementById('noteItems');
    if (!container) {
        return;
    }
    
    dcmtEnsureItemsHeader(container, noteItemsHeaderHtml);
    container.style.display = 'block';
    
    const existingItems = container.querySelectorAll('.note-item');
    const currentIndex = existingItems.length;
    
    const newItem = document.createElement('div');
    newItem.className = 'note-item row mb-2';
    
    const topicValue = data.topic !== undefined ? escapeHtml(data.topic) : '';
    const noteTextValue = data.note_text !== undefined ? escapeHtml(data.note_text) : '';
    
    newItem.innerHTML = `
        <div class="col-md-4">
            <input type="text" class="form-control note-topic" name="note_items[${currentIndex}][topic]" 
                   placeholder="<?php echo addslashes(trans('patient_note', 'topic_placeholder')); ?>" maxlength="255"
                   value="${topicValue}">
        </div>
        <div class="col-md-7">
            <textarea class="form-control note-text" name="note_items[${currentIndex}][note_text]" rows="2" 
                      placeholder="<?php echo addslashes(trans('patient_note', 'note_placeholder')); ?>">${noteTextValue}</textarea>
        </div>
        <div class="col-md-1 dcmt-delete-cell">
            <button type="button" class="btn btn-outline-danger btn-sm remove-note-btn">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(newItem);
    
    // Attach remove button handler
    const removeBtn = newItem.querySelector('.remove-note-btn');
    if (removeBtn) {
        removeBtn.addEventListener('click', function() {
            newItem.remove();
            updateItemsEmptyMessage();
            // Renumber remaining items
            const remainingItems = container.querySelectorAll('.note-item');
            remainingItems.forEach((item, index) => {
                const topicInput = item.querySelector('.note-topic');
                const noteTextArea = item.querySelector('.note-text');
                if (topicInput) topicInput.name = `note_items[${index}][topic]`;
                if (noteTextArea) noteTextArea.name = `note_items[${index}][note_text]`;
            });
        });
    }
    
    updateItemsEmptyMessage();
}

// Function to add new product item row (updated for Select2)
function addProductItem(data = {}) {
    ensureProductItemsVisible();
    const container = document.getElementById('productItems');
    dcmtEnsureItemsHeader(container, productItemsHeaderHtml);
    const newItem = document.createElement('div');
    newItem.className = 'product-item row mb-2';
    
    // Get the current number of existing items to use as index
    const existingItems = container.querySelectorAll('.product-item');
    const currentIndex = existingItems.length;
    
    // Debug: Log translations before using them





    const optionsHTML = dcmtGetProductInventoryOptionsHTML();

    newItem.innerHTML = `
        <div class="col-md-4">
            <select class="form-select product-inventory" name="product_items[${currentIndex}][inventory_id]" onchange="updateProductPrice(this, ${currentIndex}); checkAndShowProductPaidAmount();">
                ${optionsHTML}
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control product-quantity" name="product_items[${currentIndex}][quantity]" 
                   placeholder="${translations.qty}" min="1" value="${data.quantity !== undefined ? escapeHtml(data.quantity) : ''}" onchange="updateProductTotal(${currentIndex})" oninput="updateProductTotal(${currentIndex})">
        </div>
        <div class="col-md-2" style="display: none;">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="number" class="form-control dcmt-amount-input product-price" name="product_items[${currentIndex}][unit_price]" 
                       placeholder="${translations.price}" onchange="updateProductTotal(${currentIndex})" oninput="updateProductTotal(${currentIndex})">
            </div>
        </div>
        <div class="col-md-2" style="display: none;">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="text" class="form-control dcmt-amount-input product-total" placeholder="${translations.total}" readonly>
            </div>
        </div>
        <div class="col-md-2 dcmt-delete-cell">
            <input type="hidden" class="product-type" name="product_items[${currentIndex}][product_type]" value="">
            <button type="button" class="btn btn-outline-danger btn-sm remove-product-btn">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(newItem);
    updateAddProductButtonLabel();
    
    // Initialize Select2 on the new select element
    const inventorySelect = $(newItem).find('.product-inventory').select2({
        placeholder: translations.selectProduct,
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
    
    if (data.inventory_id) {
        inventorySelect.val(String(data.inventory_id)).trigger('change');
    } else {
        inventorySelect.val('').trigger('change');
    }
    
    const quantityInput = newItem.querySelector('.product-quantity');
    if (quantityInput && data.quantity !== undefined && data.quantity !== null && data.quantity !== '') {
        quantityInput.value = data.quantity;
    }
    
    const priceInput = newItem.querySelector('.product-price');
    if (priceInput && data.unit_price !== undefined && data.unit_price !== null && data.unit_price !== '') {
        priceInput.value = data.unit_price;
    }
    
    const productTypeInput = newItem.querySelector('.product-type');
    if (productTypeInput && data.product_type) {
        productTypeInput.value = data.product_type;
    }
    
    // Initialize product type handling for the new item
    initializeProductTypeHandling();
    
    // Set required attributes based on current income type
    const typeSelect = document.getElementById('type');
    if (typeSelect.value === 'product_sale') {
        const newFields = newItem.querySelectorAll('.product-inventory, .product-quantity');
        newFields.forEach(field => field.setAttribute('required', 'required'));
        // Don't set required on price field to avoid validation issues
    }
    
    // Check if product paid amount field should be shown for consultation type
    if (typeof checkAndShowProductPaidAmount === 'function') {
        checkAndShowProductPaidAmount();
    }

    syncIncomeType();
    updateProductTotal(currentIndex);
}

// Function to remove product item row (updated for Select2)
function removeProductItem(button) {
    if (confirm(translations.confirmDeleteProduct)) {
        const productItem = $(button).closest('.product-item');
        if (productItem.length) {
            // Destroy Select2 before removing the element
            productItem.find('.product-inventory').select2('destroy');
            productItem.remove();
            
            // Always recalculate the total amount
            recalculateTotalAmount();
            
            // Check if product paid amount field should be shown for consultation type
            if (typeof checkAndShowProductPaidAmount === 'function') {
                checkAndShowProductPaidAmount();
            }

            updateAddProductButtonLabel();

            const productItemsContainer = document.getElementById('productItems');
            if (productItemsContainer) {
                const remainingItems = productItemsContainer.querySelectorAll('.product-item').length;
                if (remainingItems === 0) {
                    productItemsContainer.style.display = 'none';
                    const addProductBtn = document.getElementById('addProductBtn');
                    if (addProductBtn) {
                        addProductBtn.onclick = toggleProductItems;
                        setAddProductButtonLabel(addProductLabelDefault);
                    }
                }
            }

            syncIncomeType();
        }
    }
}

// Override setupProductItemEventDelegation to prevent duplicate handlers from main.js
// Store reference to prevent main.js version from running
const originalSetupProductItemEventDelegation = window.setupProductItemEventDelegation;
let productItemRemoveHandler = null;
let productItemEventDelegationSetup = false;

function setupProductItemEventDelegation() {
    const productItemsContainer = document.getElementById('productItems');
    if (productItemsContainer && !productItemEventDelegationSetup) {
        productItemEventDelegationSetup = true;
        
        // Handle inventory selection changes (using Select2 events)
        $(productItemsContainer).off('change', '.product-inventory').on('change', '.product-inventory', function(event) {
            const productItems = $(this).closest('.product-item');
            const index = productItems.index();
            updateProductPrice(this, index);
            checkAndShowProductPaidAmount();
        });
        
        // Handle quantity and price changes
        productItemsContainer.addEventListener('input', function(event) {
            if (event.target.classList.contains('product-quantity') || event.target.classList.contains('product-price')) {
                updateProductTotalByEvent(event);
            }
        });
        
        // Mark container to prevent main.js from adding duplicate handler
        productItemsContainer.dataset.removeHandlerSetup = 'true';
        
        // Handle remove button clicks - use a flag to prevent duplicate calls
        let isHandlingClick = false;
        productItemRemoveHandler = function(event) {
            const removeBtn = event.target.closest('.remove-product-btn');
            if (removeBtn) {
                if (isHandlingClick) {
                    event.preventDefault();
                    event.stopPropagation();
                    event.stopImmediatePropagation();
                    return false;
                }
                isHandlingClick = true;
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                removeProductItem(removeBtn);
                // Reset flag after a short delay
                setTimeout(function() {
                    isHandlingClick = false;
                }, 100);
                return false;
            }
        };
        productItemsContainer.addEventListener('click', productItemRemoveHandler, true); // Use capture phase
    }
}

// Override the global function to use our version
window.setupProductItemEventDelegation = setupProductItemEventDelegation;

// Setup service item event delegation
function setupServiceItemEventDelegation() {
    const serviceItemsContainer = document.getElementById('serviceItems');
    if (serviceItemsContainer) {
        // Handle doctor selection changes (using Select2 events)
        $(serviceItemsContainer).on('change', '.service-doctor-select', function() {
            const serviceItems = serviceItemsContainer.querySelectorAll('.service-item');
            const index = Array.from(serviceItems).indexOf(this.closest('.service-item'));
            handleServiceDoctorChange(this, index);
        });
        
        // Handle service selection changes (using Select2 events)
        $(serviceItemsContainer).on('change', '.service-select', function(event) {
            const serviceItems = serviceItemsContainer.querySelectorAll('.service-item');
            const index = Array.from(serviceItems).indexOf($(this).closest('.service-item')[0]);
            if (index >= 0) {
                updateServiceItemAmount(this, index);
                updateServiceTotal();
            }
        });
        
        // Handle quantity and amount changes
        serviceItemsContainer.addEventListener('input', function(event) {
            if (event.target.classList.contains('service-quantity') || event.target.classList.contains('service-amount')) {
                const serviceItems = serviceItemsContainer.querySelectorAll('.service-item');
                const index = Array.from(serviceItems).indexOf(event.target.closest('.service-item'));
                if (index >= 0) {
                    updateServiceItemTotal(index);
                    updateServiceTotal();
                }
            }
        });
        
        // Handle remove button clicks
        serviceItemsContainer.addEventListener('click', function(event) {
            if (event.target.closest('.remove-service-btn')) {
                removeServiceItem(event.target.closest('.remove-service-btn'));
            }
        });
    }
}

function dcmtGetPaymentConfig(type) {
    return dcmtPaymentConfig[type] || null;
}

function dcmtTogglePaymentEmptyState(type) {
    const config = dcmtGetPaymentConfig(type);
    if (!config) {
        return;
    }
    const container = document.getElementById(config.containerId);
    const emptyState = document.getElementById(config.emptyStateId);
    if (!container || !emptyState) {
        return;
    }
    const hasRows = container.querySelectorAll('.dcmt-payment-row').length > 0;
    emptyState.classList.toggle('d-none', hasRows);
}

function dcmtReindexPaymentRows(type) {
    const config = dcmtGetPaymentConfig(type);
    if (!config) {
        return;
    }
    const container = document.getElementById(config.containerId);
    if (!container) {
        return;
    }
    container.querySelectorAll('.dcmt-payment-row').forEach((row, index) => {
        // Update input names
        row.querySelectorAll('[name]').forEach(input => {
            const originalName = input.getAttribute('name');
            if (!originalName) {
                return;
            }
            const updatedName = originalName.replace(new RegExp(`^${config.inputName}\\[\\d+\\]`), `${config.inputName}[${index}]`);
            input.setAttribute('name', updatedName);
        });
        
        // Show labels only for the first row (index === 0)
        const showLabels = index === 0;
        const dateCol = row.querySelector('.col-md-4:first-child');
        const methodCol = row.querySelectorAll('.col-md-4')[1];
        const amountCol = row.querySelector('.col-md-3');
        
        // Update date label
        if (dateCol) {
            let dateLabel = dateCol.querySelector('label.form-label');
            if (showLabels && !dateLabel) {
                dateLabel = document.createElement('label');
                dateLabel.className = 'form-label';
                dateLabel.textContent = dcmtPartialPaymentTranslations.paymentDate;
                dateCol.insertBefore(dateLabel, dateCol.firstChild);
            } else if (!showLabels && dateLabel) {
                dateLabel.remove();
            }
        }
        
        // Update method label
        if (methodCol) {
            let methodLabel = methodCol.querySelector('label.form-label');
            if (showLabels && !methodLabel) {
                methodLabel = document.createElement('label');
                methodLabel.className = 'form-label';
                methodLabel.textContent = dcmtPartialPaymentTranslations.paymentMethod;
                methodCol.insertBefore(methodLabel, methodCol.firstChild);
            } else if (!showLabels && methodLabel) {
                methodLabel.remove();
            }
        }
        
        // Update amount label
        if (amountCol) {
            let amountLabel = amountCol.querySelector('label.form-label');
            if (showLabels && !amountLabel) {
                amountLabel = document.createElement('label');
                amountLabel.className = 'form-label';
                amountLabel.textContent = dcmtPartialPaymentTranslations.paymentAmount;
                amountCol.insertBefore(amountLabel, amountCol.firstChild);
            } else if (!showLabels && amountLabel) {
                amountLabel.remove();
            }
        }
    });
}

function dcmtRecalculatePartialPayments(type) {
    const config = dcmtGetPaymentConfig(type);
    if (!config) {
        return;
    }
    const container = document.getElementById(config.containerId);
    if (!container) {
        return;
    }
    let total = 0;
    container.querySelectorAll('.dcmt-payment-amount').forEach(input => {
        const amount = parseFloat(input.value);
        if (!isNaN(amount)) {
            total += amount;
        }
    });
    const amountField = document.getElementById(config.amountFieldId);
    if (amountField) {
        amountField.value = total.toFixed(2);
    }
    applyPaymentDistribution(total);
    dcmtTogglePaymentEmptyState(type);
    if (typeof dcmtReindexPaymentRows === 'function') {
        dcmtReindexPaymentRows(type);
    }
    if (typeof dcmtSyncPaymentStatusWithPending === 'function') {
        dcmtSyncPaymentStatusWithPending();
    }
}

function dcmtAddPaymentRow(type, data = {}, options = {}) {
    const config = dcmtGetPaymentConfig(type);
    if (!config) {
        return;
    }
    const container = document.getElementById(config.containerId);
    if (!container) {
        return;
    }
    const triggerRecalc = options.triggerRecalc !== false;
    const rowCount = container.querySelectorAll('.dcmt-payment-row').length;
    // Use provided date if exists (for existing payments), otherwise use current date from PHP (Mexican timezone)
    const paymentDate = data.paid_on ? String(data.paid_on) : (typeof dcmtCurrentDate !== 'undefined' ? dcmtCurrentDate : '');
    const paymentMethodId = data.payment_method_id !== undefined && data.payment_method_id !== null && data.payment_method_id !== '' ? String(data.payment_method_id) : getDefaultIncomePaymentMethodId();
    const paymentAmountRaw = data.amount !== undefined && data.amount !== null && data.amount !== '' ? String(data.amount) : '';
    const paymentAmount = parseFloat(paymentAmountRaw);
    
    const row = document.createElement('div');
    row.className = 'row g-2 dcmt-payment-row align-items-end mb-2';
    // Show labels only for the first row (rowCount === 0)
    const showLabels = rowCount === 0;
    row.innerHTML = `
        <div class="col-md-4">
            ${showLabels ? `<label class="form-label">${dcmtPartialPaymentTranslations.paymentDate}</label>` : ''}
            <input type="date" class="form-control dcmt-payment-date" name="${config.inputName}[${rowCount}][paid_on]" value="${paymentDate || ''}">
        </div>
        <div class="col-md-4">
            ${showLabels ? `<label class="form-label">${dcmtPartialPaymentTranslations.paymentMethod}</label>` : ''}
            <select class="form-select dcmt-payment-method" name="${config.inputName}[${rowCount}][payment_method_id]" required>
                <option value="">${dcmtPartialPaymentTranslations.selectPaymentMethod}</option>
                ${buildPaymentMethodOptions(paymentMethodId)}
            </select>
        </div>
        <div class="col-md-3">
            ${showLabels ? `<label class="form-label">${dcmtPartialPaymentTranslations.paymentAmount}</label>` : ''}
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol">${dcmtCurrencySymbolClient}</span>
                <input type="number" step="0.01" min="0" class="form-control dcmt-amount-input dcmt-payment-amount" name="${config.inputName}[${rowCount}][amount]" value="${paymentAmountRaw === '' || isNaN(paymentAmount) ? '' : paymentAmount.toFixed(2)}" placeholder="<?php echo addslashes(trans('common', 'amount')); ?>">
            </div>
        </div>
        <div class="col-md-1 dcmt-delete-cell">
            <button type="button" class="btn btn-outline-danger dcmt-remove-payment" aria-label="${translations.delete || 'Delete'}">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(row);
    
    const dateInput = row.querySelector('.dcmt-payment-date');
    const amountInput = row.querySelector('.dcmt-payment-amount');
    const methodSelect = row.querySelector('.dcmt-payment-method');
    const removeButton = row.querySelector('.dcmt-remove-payment');
    
    if (dateInput) {
        dateInput.addEventListener('change', () => {
            if (typeof dcmtMarkFormAsChanged === 'function') {
                dcmtMarkFormAsChanged();
            }
        });
    }
    
    if (methodSelect) {
        if (methodSelect.value === '') {
            methodSelect.value = getDefaultIncomePaymentMethodId();
        }
        methodSelect.addEventListener('change', () => {
            if (typeof dcmtMarkFormAsChanged === 'function') {
                dcmtMarkFormAsChanged();
            }
        });
    }
    
    if (amountInput) {
        amountInput.addEventListener('input', () => {
            dcmtRecalculatePartialPayments(type);
            if (typeof dcmtMarkFormAsChanged === 'function') {
                dcmtMarkFormAsChanged();
            }
        });
    }
    
    if (removeButton) {
        removeButton.addEventListener('click', (event) => {
            event.preventDefault();
            dcmtRemovePaymentRow(type, row);
        });
    }
    
    dcmtReindexPaymentRows(type);
    dcmtTogglePaymentEmptyState(type);
    
    if (triggerRecalc) {
        dcmtRecalculatePartialPayments(type);
    } else {
        refreshPaymentSummaries();
    }
}

function dcmtRemovePaymentRow(type, rowElement) {
    const config = dcmtGetPaymentConfig(type);
    if (!config) {
        return;
    }
    const confirmationMessage = translations.confirmDeletePayment || '<?php echo addslashes(trans('income', 'confirm_delete_payment')); ?>';
    if (!confirm(confirmationMessage)) {
        return;
    }
    const container = document.getElementById(config.containerId);
    if (!container || !rowElement) {
        return;
    }
    container.removeChild(rowElement);
    dcmtReindexPaymentRows(type);
    dcmtTogglePaymentEmptyState(type);
    dcmtRecalculatePartialPayments(type);
    if (typeof dcmtMarkFormAsChanged === 'function') {
        dcmtMarkFormAsChanged();
    }
}

function dcmtRenderPaymentRows(type, rows) {
    const config = dcmtGetPaymentConfig(type);
    if (!config) {
        return;
    }
    const container = document.getElementById(config.containerId);
    if (!container) {
        return;
    }
    container.innerHTML = '';
    if (Array.isArray(rows) && rows.length) {
        rows.forEach(rowData => {
            dcmtAddPaymentRow(type, rowData, { triggerRecalc: false, markChanged: false });
        });
    }
    dcmtTogglePaymentEmptyState(type);
    dcmtRecalculatePartialPayments(type);
}

dcmtRenderPaymentRows('total', dcmtInitialPayments.total || []);

</script>

<!-- Quick Add Patient Modal -->
<div class="modal fade" id="quickAddPatientModal" tabindex="-1" aria-labelledby="quickAddPatientModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title" id="quickAddPatientModalLabel">
                    <i class="fas fa-user-plus me-2"></i><?php echo trans('income', 'quick_add_patient'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <form id="quickAddPatientForm">
                <div class="modal-body">
                    <div id="quickAddPatientAlert" class="alert d-none" role="alert"></div>
                    <input type="hidden" name="csrf_token" value="<?php echo dcmt_generate_csrf_token(); ?>">
                    
                    <div class="mb-3">
                        <label for="quick_first_name" class="form-label"><?php echo trans('patient', 'first_name'); ?> *</label>
                        <input type="text" class="form-control" id="quick_first_name" name="first_name" 
                               placeholder="<?php echo trans('patient', 'first_name_placeholder'); ?>" required>
                    </div>
                    
                    <div class="mb-3">
                        <label for="quick_fathers_last_name" class="form-label"><?php echo trans('patient', 'fathers_last_name'); ?></label>
                        <input type="text" class="form-control" id="quick_fathers_last_name" name="fathers_last_name" 
                               placeholder="<?php echo trans('patient', 'fathers_last_name_placeholder'); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="quick_mothers_last_name" class="form-label"><?php echo trans('patient', 'mothers_last_name'); ?></label>
                        <input type="text" class="form-control" id="quick_mothers_last_name" name="mothers_last_name" 
                               placeholder="<?php echo trans('patient', 'mothers_last_name_placeholder'); ?>">
                    </div>
                    
                    <div class="mb-3">
                        <label for="quick_emergency_contact_name" class="form-label"><?php echo trans('patient', 'emergency_guardian_name'); ?></label>
                        <input type="text" class="form-control" id="quick_emergency_contact_name" name="emergency_contact_name"
                               placeholder="<?php echo trans('patient', 'emergency_guardian_name'); ?>">
                    </div>

                    <div class="row g-2">
                        <div class="col-md-7">
                            <div class="mb-3">
                                <label for="quick_patient_phone" class="form-label"><?php echo trans('patient', 'phone'); ?> *</label>
                                <div class="input-group has-validation">
                                    <span class="input-group-text">+52</span>
                                    <input type="text" class="form-control" id="quick_patient_phone" name="phone"
                                           placeholder="<?php echo trans('patient', 'phone_placeholder'); ?>"
                                           required maxlength="25" inputmode="numeric">
                                    <div id="quick_patient_phone_error" class="invalid-feedback"></div>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-5">
                            <div class="mb-3">
                                <label for="quick_gender" class="form-label"><?php echo trans('patient', 'gender'); ?></label>
                                <select class="form-select" id="quick_gender" name="gender" style="height: 38px;">
                                    <option value="male" selected><?php echo trans('patient', 'male'); ?></option>
                                    <option value="female"><?php echo trans('patient', 'female'); ?></option>
                                    <option value="other"><?php echo trans('patient', 'other'); ?></option>
                                </select>
                            </div>
                        </div>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                        <i class="fas fa-times"></i> <?php echo trans('common', 'cancel'); ?>
                    </button>
                    <button type="submit" class="btn btn-primary" id="quickAddPatientSubmitBtn">
                        <i class="fas fa-plus"></i> <?php echo trans('patient', 'add_patient'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
// Quick Add Patient functionality
document.addEventListener('DOMContentLoaded', function() {
    const quickAddBtn = document.getElementById('quickAddPatientBtn');
    const quickAddModal = new bootstrap.Modal(document.getElementById('quickAddPatientModal'));
    const quickAddForm = document.getElementById('quickAddPatientForm');
    const quickAddAlert = document.getElementById('quickAddPatientAlert');
    const quickAddSubmitBtn = document.getElementById('quickAddPatientSubmitBtn');
    const quickPhoneInput = document.getElementById('quick_patient_phone');
    const quickPhoneError = document.getElementById('quick_patient_phone_error');

    function dcmtClearQuickPhoneError() {
        if (quickPhoneInput) {
            quickPhoneInput.classList.remove('is-invalid');
        }
        if (quickPhoneError) {
            quickPhoneError.textContent = '';
        }
    }

    function dcmtSetQuickPhoneError(message) {
        if (quickPhoneInput) {
            quickPhoneInput.classList.add('is-invalid');
        }
        if (quickPhoneError) {
            quickPhoneError.textContent = message || '';
        }
    }

    function dcmtQuickPhoneDigits(value) {
        return String(value || '').replace(/\D+/g, '');
    }
    
    // Open modal when button is clicked
    if (quickAddBtn) {
        quickAddBtn.addEventListener('click', function(e) {
            e.preventDefault(); // Prevent default link behavior
            // Reset form
            quickAddForm.reset();
            quickAddAlert.classList.add('d-none');
            quickAddAlert.textContent = '';
            dcmtClearQuickPhoneError();
            quickAddModal.show();
        });
    }
    
    if (quickPhoneInput) {
        quickPhoneInput.addEventListener('input', function() {
            if (quickPhoneInput.classList.contains('is-invalid')) {
                dcmtClearQuickPhoneError();
            }
        });
    }

    // Handle form submission
    if (quickAddForm) {
        quickAddForm.addEventListener('submit', function(e) {
            e.preventDefault();
            dcmtClearQuickPhoneError();
            
            const localDigits = dcmtQuickPhoneDigits(quickPhoneInput ? quickPhoneInput.value : '');
            if (!localDigits || localDigits.length < 7) {
                dcmtSetQuickPhoneError('<?php echo addslashes(trans('patient', 'phone')); ?> is invalid.');
                return;
            }
            
            // Disable submit button
            quickAddSubmitBtn.disabled = true;
            quickAddSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin"></i> <?php echo addslashes(trans('common', 'saving')); ?>...';
            
            // Hide previous alerts
            quickAddAlert.classList.add('d-none');
            quickAddAlert.textContent = '';
            
            // Get form data
            const formData = new FormData(quickAddForm);
            
            // Make AJAX request
            fetch('../patients/quick_add_ajax.php', {
                method: 'POST',
                body: formData
            })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    // Show success message
                    quickAddAlert.classList.remove('d-none', 'alert-danger');
                    quickAddAlert.classList.add('alert-success');
                    quickAddAlert.textContent = data.message;
                    
                    // Add new patient to Select2 dropdown
                    const patientSelect = $('#patient_name');
                    const patientId = data.patient.id;
                    const patientName = data.patient.name;
                    const patientPhone = data.patient.phone || '';
                    
                    // Create display text
                    let displayText = patientName;
                    if (patientPhone) {
                        displayText += ' - ' + patientPhone;
                    }
                    
                    const existingOption = patientSelect.find(`option[value="${patientId}"]`);
                    if (existingOption.length === 0) {
                        const newOption = new Option(displayText, patientId, true, true);
                        newOption.setAttribute('data-name', patientName);
                        patientSelect.append(newOption);
                    }
                    patientSelect.val(String(patientId)).trigger('change');
                    $('#patient_name_text').val(patientName);

                    if (data.already_exists) {
                        dcmtSetQuickPhoneError(data.message || '<?php echo addslashes(trans('patient', 'patient_already_exists')); ?>');
                        quickAddSubmitBtn.disabled = false;
                        quickAddSubmitBtn.innerHTML = '<i class="fas fa-plus"></i> <?php echo addslashes(trans('patient', 'add_patient')); ?>';
                        return;
                    }
                    
                    setTimeout(function() {
                        quickAddModal.hide();
                        quickAddForm.reset();
                        dcmtClearQuickPhoneError();
                    }, 1000);
                } else {
                    if (data.field === 'phone') {
                        dcmtSetQuickPhoneError(data.message || '<?php echo addslashes(trans('common', 'error_occurred')); ?>');
                        quickAddAlert.classList.add('d-none');
                        quickAddAlert.textContent = '';
                    } else {
                        quickAddAlert.classList.remove('d-none', 'alert-success');
                        quickAddAlert.classList.add('alert-danger');
                        quickAddAlert.textContent = data.message || '<?php echo addslashes(trans('common', 'error_occurred')); ?>';
                    }
                    
                    // Re-enable submit button
                    quickAddSubmitBtn.disabled = false;
                    quickAddSubmitBtn.innerHTML = '<i class="fas fa-plus"></i> <?php echo addslashes(trans('patient', 'add_patient')); ?>';
                }
            })
            .catch(error => {
                console.error('Error:', error);
                quickAddAlert.classList.remove('d-none', 'alert-success');
                quickAddAlert.classList.add('alert-danger');
                quickAddAlert.textContent = '<?php echo addslashes(trans('common', 'error_occurred')); ?>';
                
                // Re-enable submit button
                quickAddSubmitBtn.disabled = false;
                quickAddSubmitBtn.innerHTML = '<i class="fas fa-plus"></i> <?php echo addslashes(trans('patient', 'add_patient')); ?>';
            });
        });
    }
    
    // Reset form when modal is hidden
    const quickAddModalElement = document.getElementById('quickAddPatientModal');
    if (quickAddModalElement) {
        quickAddModalElement.addEventListener('hidden.bs.modal', function() {
            quickAddForm.reset();
            quickAddAlert.classList.add('d-none');
            quickAddAlert.textContent = '';
            dcmtClearQuickPhoneError();
            quickAddSubmitBtn.disabled = false;
            quickAddSubmitBtn.innerHTML = '<i class="fas fa-plus"></i> <?php echo addslashes(trans('patient', 'add_patient')); ?>';
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
