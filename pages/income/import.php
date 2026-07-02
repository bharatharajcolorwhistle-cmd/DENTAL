<?php
/**
 * Import Income Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/income_import_csv.php';

// Check authentication
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Handle file upload and processing
$import_result = null;
$import_errors = [];
$import_success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_income'])) {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $import_errors[] = trans('common', 'invalid_token');
    } else {
        // Check if file was uploaded
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $import_errors[] = trans('income', 'no_file_uploaded');
        } else {
            $file = $_FILES['csv_file'];
            
            if (!dcmt_validate_csv_upload($file)) {
                $import_errors[] = trans('income', 'invalid_file_type');
            } else {
                // Process CSV file
                $import_result = processIncomeImport($file['tmp_name']);
                $import_errors = $import_result['errors'];
                $import_success = $import_result['success'];
            }
        }
    }
}

/**
 * Process the uploaded CSV file and import income records
 */
function processIncomeImport($file_path) {
    global $dcmt_pdo;
    
    $errors = [];
    $success = [];
    $imported_count = 0;
    $skipped_count = 0;
    
    // Increase execution time for import operations
    set_time_limit(300); // 5 minutes
    
    // Increase memory limit for large imports
    ini_set('memory_limit', '256M');
    
    // Open CSV file
    if (($handle = fopen($file_path, 'r')) === FALSE) {
        $errors[] = trans('income', 'cannot_read_file');
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'skipped_count' => 0];
    }

    $mapped_headers = null;
    $header_line_number = 0;
    while ($header_line_number < 40) {
        $candidate = fgetcsv($handle, 0, ',', '"', '\\');
        if ($candidate === false) {
            break;
        }
        $header_line_number++;
        if (empty(array_filter($candidate, static function ($c) {
            return trim((string) $c) !== '';
        }))) {
            continue;
        }
        $mapped = dcmt_income_import_map_header_row($candidate);
        if (dcmt_income_import_mapped_headers_are_valid($mapped)) {
            $mapped_headers = $mapped;
            break;
        }
    }

    if ($mapped_headers === null) {
        if ($header_line_number === 0) {
            $errors[] = trans('income', 'empty_file');
        } else {
            $errors[] = sprintf(
                trans('income', 'missing_required_headers'),
                implode(', ', ['patient_name', 'type', 'amount', 'transaction_date'])
            );
        }
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'skipped_count' => 0];
    }

    // Count total data rows (excluding preamble + header)
    $total_rows = 0;
    $temp_handle = fopen($file_path, 'r');
    if ($temp_handle) {
        for ($i = 0; $i < $header_line_number; $i++) {
            fgetcsv($temp_handle, 0, ',', '"', '\\');
        }
        while (($row = fgetcsv($temp_handle, 0, ',', '"', '\\')) !== false) {
            if (!empty(array_filter($row, static function ($c) {
                return trim((string) $c) !== '';
            }))) {
                $total_rows++;
            }
        }
        fclose($temp_handle);
    }

    // Check if total rows exceed the maximum limit
    $max_records = defined('DCMT_MAX_IMPORT_RECORDS') ? DCMT_MAX_IMPORT_RECORDS : 500;
    if ($total_rows > $max_records) {
        $errors[] = sprintf(trans('income', 'import_limit_exceeded'), $total_rows, $max_records);
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'skipped_count' => 0];
    }

    // Validate headers (internal keys)
    $required_headers = ['patient_name', 'type', 'amount', 'transaction_date'];

    $header_errors = validateHeaders($mapped_headers, $required_headers);
    if (!empty($header_errors)) {
        $errors = array_merge($errors, $header_errors);
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'skipped_count' => 0];
    }

    $headers = $mapped_headers;
    // Get lookup data
    $doctors = getDoctorsLookup();
    $payment_methods = getPaymentMethodsLookup();
    $payment_statuses = getPaymentStatusesLookup();
    $services = getServicesLookup();
    
    $row_number = 1; // Start from 1 since we already read headers
    
    // Process each row
    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE) {
        $row_number++;
        
        // Skip empty rows or rows with insufficient data
        if (empty(array_filter($data)) || count($data) < 4) {
            continue;
        }
        
        // Ensure we have enough columns for required fields
        if (count($data) < count($required_headers)) {
            $errors[] = sprintf("Row %d: Insufficient columns. Expected at least %d columns, got %d", $row_number, count($required_headers), count($data));
            $skipped_count++;
            continue;
        }
        
        // Validate row data
        $row_errors = validateIncomeRow($data, $headers, $row_number);
        if (!empty($row_errors)) {
            $errors = array_merge($errors, $row_errors);
            $skipped_count++;
            continue;
        }
        
        // Convert row data to associative array
        // Ensure we have matching number of headers and data columns
        $row_data = [];
        for ($i = 0; $i < count($headers) && $i < count($data); $i++) {
            $row_data[$headers[$i]] = $data[$i] ?? '';
        }
        
        // Skip rows that have ID field (already existing records)
        if (!empty($row_data['id']) && is_numeric($row_data['id'])) {
            $skipped_count++;
            continue;
        }
        
        // Process and insert income record
        $insert_result = insertIncomeRecord($row_data, $doctors, $payment_methods, $payment_statuses, $services);
        
        if ($insert_result['success']) {
            $imported_count++;
            $success[] = sprintf(trans('income', 'row_imported_successfully'), $row_number, $row_data['patient_name']);
        } else {
            $error_message = $insert_result['error'];
            $errors[] = sprintf(trans('income', 'row_import_failed'), $row_number, $error_message);
            $skipped_count++;
        }
    }
    
    fclose($handle);
    
    // Log import activity
    if ($imported_count > 0) {
        dcmt_log_activity("Income records imported from CSV - {$imported_count} records imported, {$skipped_count} skipped", "income_imported");
    }
    
    return [
        'errors' => $errors,
        'success' => $success,
        'imported_count' => $imported_count,
        'skipped_count' => $skipped_count
    ];
}

/**
 * Validate CSV headers
 */
function validateHeaders($headers, $required_headers) {
    $errors = [];
    
    // Check for missing required headers
    $missing_required = array_diff($required_headers, $headers);
    
    if (!empty($missing_required)) {
        $errors[] = sprintf(trans('income', 'missing_required_headers'), implode(', ', $missing_required));
    }
    
    return $errors;
}

/**
 * Validate income row data
 */
function validateIncomeRow($data, $headers, $row_number) {
    $errors = [];
    $row_data = [];
    $max = min(count($headers), count($data));
    for ($i = 0; $i < $max; $i++) {
        $row_data[$headers[$i]] = $data[$i] ?? '';
    }
    
    // Required fields validation
    $required_fields = ['patient_name', 'type', 'amount', 'transaction_date'];
    foreach ($required_fields as $field) {
        if (trim((string) ($row_data[$field] ?? '')) === '') {
            $errors[] = sprintf(trans('income', 'required_field_missing'), $field, $row_number);
        }
    }
    
    // Payment mode validation - handle both English and translated values
    if (!empty($row_data['payment_mode'])) {
        $mode_value = strtolower(trim($row_data['payment_mode']));
        $valid_payment_modes = ['cash', 'card', 'bank_transfer', 'online'];
        $translated_modes = [
            'efectivo' => 'cash',
            'tarjeta' => 'card',
            'transferencia bancaria' => 'bank_transfer',
            'transferencia' => 'bank_transfer',
            'en línea' => 'online',
            'online' => 'online'
        ];
        
        if (!in_array($mode_value, $valid_payment_modes)) {
            if (isset($translated_modes[$mode_value])) {
                $row_data['payment_mode'] = $translated_modes[$mode_value];
            } else {
                $errors[] = sprintf(trans('income', 'invalid_payment_mode'), $row_data['payment_mode'], $row_number);
            }
        }
    }
    
    // Payment method ID validation (optional)
    if (!empty($row_data['payment_method_id']) && !is_numeric($row_data['payment_method_id'])) {
        $errors[] = sprintf(trans('income', 'invalid_payment_method'), $row_data['payment_method_id'], $row_number);
    }
    
    // Payment status validation - handle both English and translated values
    if (!empty($row_data['payment_status'])) {
        $status_value = strtolower(trim($row_data['payment_status']));
        $valid_payment_statuses = ['completed', 'pending', 'failed'];
        $translated_statuses = [
            'completado' => 'completed',
            'pendiente' => 'pending',
            'fallido' => 'failed',
            'error' => 'failed'
        ];
        
        if (!in_array($status_value, $valid_payment_statuses)) {
            if (isset($translated_statuses[$status_value])) {
                $row_data['payment_status'] = $translated_statuses[$status_value];
            } else {
                $errors[] = sprintf(trans('income', 'invalid_payment_status'), $row_data['payment_status'], $row_number);
            }
        }
    }
    
    // Payment status ID validation (optional)
    if (!empty($row_data['payment_status_id']) && !is_numeric($row_data['payment_status_id'])) {
        $errors[] = sprintf(trans('income', 'invalid_payment_status'), $row_data['payment_status_id'], $row_number);
    }
    
    // Consultation fee validation (if provided)
    if (!empty($row_data['consultation_fee'])) {
        if (!is_numeric($row_data['consultation_fee']) || floatval($row_data['consultation_fee']) < 0) {
            $errors[] = sprintf(trans('income', 'invalid_consultation_fee'), $row_data['consultation_fee'], $row_number);
        }
    }
    
    // Type validation - handle both English and translated values
    if (!empty($row_data['type'])) {
        $type_value = strtolower(trim($row_data['type']));
        $valid_types = ['consultation', 'product_sale', 'mixed'];
        $translated_types = [
            'consulta' => 'consultation',
            'venta de producto' => 'product_sale',
            'venta producto' => 'product_sale',
            'producto' => 'product_sale',
            'mixto' => 'mixed',
            'mixed' => 'mixed'
        ];
        
        // Check if it's a direct match
        if (!in_array($type_value, $valid_types)) {
            // Check if it's a translated value
            if (isset($translated_types[$type_value])) {
                $row_data['type'] = $translated_types[$type_value];
            } else {
                $errors[] = sprintf(trans('income', 'invalid_type'), $row_data['type'], $row_number);
            }
        }
    }
    
    // Amount validation
    // Allow 0.00 for "for use" products in product_sale type
    $type_value = strtolower(trim($row_data['type'] ?? ''));
    $is_product_sale = ($type_value === 'product_sale');
    $product_amount = isset($row_data['product_amount']) && !empty(trim($row_data['product_amount'])) ? floatval($row_data['product_amount']) : 0;
    $is_for_use_product = ($is_product_sale && $product_amount == 0);
    
    // Check if amount field is provided (even if it's "0.00")
    $amount_provided = isset($row_data['amount']) && trim($row_data['amount']) !== '';
    
    if ($amount_provided) {
        $amount_value = floatval($row_data['amount']);
        // Allow 0.00 for "for use" products, otherwise amount must be > 0
        if (!is_numeric($row_data['amount']) || ($amount_value < 0 || ($amount_value == 0 && !$is_for_use_product))) {
            $errors[] = sprintf(trans('income', 'invalid_amount'), $row_data['amount'], $row_number);
        }
    } elseif (!$is_for_use_product) {
        // Amount is required unless it's a "for use" product
        $errors[] = sprintf(trans('income', 'required_field_missing'), 'amount', $row_number);
    }
    
    // Date validation
    if (!empty($row_data['transaction_date'])) {
        $raw_date = trim($row_data['transaction_date']);
        $valid_date = false;
        $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y'];
        foreach ($formats as $format) {
            $date = DateTime::createFromFormat($format, $raw_date);
            if ($date && $date->format($format) === $raw_date) {
                $valid_date = true;
                break;
            }
        }
        if (!$valid_date) {
            $errors[] = sprintf(trans('income', 'invalid_date_format'), $row_data['transaction_date'], $row_number);
        }
    }
    
    return $errors;
}

/**
 * Get doctors lookup array
 */
function getDoctorsLookup() {
    global $dcmt_pdo;
    
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_full_name, dcmt_email FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active'");
    $stmt->execute();
    $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lookup = [];
    foreach ($doctors as $doctor) {
        if (!empty($doctor['dcmt_full_name'])) {
            $lookup[strtolower($doctor['dcmt_full_name'])] = $doctor['dcmt_id'];
        }
        if (!empty($doctor['dcmt_email'])) {
            $lookup[strtolower($doctor['dcmt_email'])] = $doctor['dcmt_id'];
        }
    }
    
    return $lookup;
}

/**
 * Get payment methods lookup array
 */
function getPaymentMethodsLookup() {
    global $dcmt_pdo;
    
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active'");
    $stmt->execute();
    $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lookup = [];
    foreach ($methods as $method) {
        $lookup[strtolower($method['dcmt_name'])] = $method['dcmt_id'];
    }
    
    return $lookup;
}

/**
 * Get payment statuses lookup array
 */
function getPaymentStatusesLookup() {
    global $dcmt_pdo;
    
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_status WHERE dcmt_status = 'active'");
    $stmt->execute();
    $statuses = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lookup = [];
    foreach ($statuses as $status) {
        $lookup[strtolower($status['dcmt_name'])] = $status['dcmt_id'];
    }
    
    return $lookup;
}

/**
 * Get services lookup array
 */
function getServicesLookup() {
    global $dcmt_pdo;
    
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_services WHERE dcmt_status = 'active'");
    $stmt->execute();
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lookup = [];
    foreach ($services as $service) {
        $lookup[strtolower($service['dcmt_name'])] = $service['dcmt_id'];
    }
    
    return $lookup;
}

/**
 * Insert income record into database
 */
function insertIncomeRecord($row_data, $doctors, $payment_methods, $payment_statuses, $services) {
    global $dcmt_pdo;
    
    try {
        // Get doctor user ID
        $doctor_id = null;
        if (!empty($row_data['doctor_id']) && is_numeric($row_data['doctor_id'])) {
            $doctor_id = (int) $row_data['doctor_id'];
        } elseif (!empty($row_data['doctor_name'])) {
            $doctor_key = strtolower(trim($row_data['doctor_name']));
            $doctor_id = $doctors[$doctor_key] ?? null;
        }
        
        // Determine payment_mode from CSV or default
        $payment_mode = strtolower(trim($row_data['payment_mode'] ?? 'cash'));
        $valid_payment_modes = ['cash', 'card', 'bank_transfer', 'online'];
        if (!in_array($payment_mode, $valid_payment_modes)) {
            $payment_mode = 'cash'; // Default to cash if invalid
        }
        
        // Get payment method ID - try multiple lookup strategies
        $payment_method_id = null;
        if (!empty($row_data['payment_method_id']) && is_numeric($row_data['payment_method_id'])) {
            $payment_method_id = (int) $row_data['payment_method_id'];
        } elseif (!empty($row_data['payment_method'])) {
            $method_input = trim($row_data['payment_method']);
            $method_key = strtolower($method_input);
            $translated_methods = [
                'efectivo' => 'cash',
                'cash' => 'cash',
                'tarjeta' => 'credit card',
                'card' => 'credit card',
                'credit_card' => 'credit card',
                'credit card' => 'credit card',
                'debit_card' => 'debit card',
                'debit card' => 'debit card',
                'transferencia bancaria' => 'bank transfer',
                'transferencia' => 'bank transfer',
                'bank_transfer' => 'bank transfer',
                'bank transfer' => 'bank transfer',
                'cheque' => 'check',
                'check' => 'check',
                'en línea' => 'online payment',
                'online' => 'online payment',
                'online_payment' => 'online payment',
                'online payment' => 'online payment'
            ];
            if (isset($translated_methods[$method_key])) {
                $method_key = $translated_methods[$method_key];
                $method_input = $method_key;
            }
            
            // Try exact lowercase match first
            $payment_method_id = $payment_methods[$method_key] ?? null;
            
            // If not found, try case-insensitive lookup in database
            if (!$payment_method_id) {
                $stmt_method = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE LOWER(dcmt_name) = LOWER(?) AND dcmt_status = 'active' LIMIT 1");
                $stmt_method->execute([$method_input]);
                $method_record = $stmt_method->fetch();
                if ($method_record) {
                    $payment_method_id = $method_record['dcmt_id'];
                }
            }
            
            // If still not found, try to find by payment mode
            if (!$payment_method_id && !empty($row_data['payment_mode'])) {
                $mode_key = strtolower(trim($row_data['payment_mode']));
                $payment_method_id = $payment_methods[$mode_key] ?? null;
                
                // Try database lookup for payment mode
                if (!$payment_method_id) {
                    $stmt_method = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE LOWER(dcmt_name) = LOWER(?) AND dcmt_status = 'active' LIMIT 1");
                    $stmt_method->execute([$row_data['payment_mode']]);
                    $method_record = $stmt_method->fetch();
                    if ($method_record) {
                        $payment_method_id = $method_record['dcmt_id'];
                    }
                }
            }
        }
        
        // Determine payment_status from CSV - normalize first
        $payment_status_input = trim($row_data['payment_status'] ?? '');
        $payment_status = strtolower($payment_status_input);
        $valid_payment_statuses = ['completed', 'pending', 'failed'];
        
        // Map common variations and translations
        $status_mapping = [
            'completado' => 'completed',
            'pendiente' => 'pending',
            'fallido' => 'failed',
            'error' => 'failed',
            'cancelled' => 'failed',
            'canceled' => 'failed',
            'refunded' => 'failed',
            'paid' => 'completed',
            'unpaid' => 'pending',
            'partial' => 'pending'
        ];
        
        if (!empty($payment_status) && isset($status_mapping[$payment_status])) {
            $payment_status = $status_mapping[$payment_status];
        } elseif (!empty($payment_status) && !in_array($payment_status, $valid_payment_statuses)) {
            // Default based on pending amount if status doesn't match
            $pending_amount_check = floatval($row_data['pending_amount'] ?? 0);
            $payment_status = ($pending_amount_check > 0) ? 'pending' : 'completed';
        } elseif (empty($payment_status)) {
            // Default based on pending amount if no status provided
            $pending_amount_check = floatval($row_data['pending_amount'] ?? 0);
            $payment_status = ($pending_amount_check > 0) ? 'pending' : 'completed';
        }
        
        // Get payment status ID - try multiple lookup strategies
        $payment_status_id = null;
        if (!empty($row_data['payment_status_id']) && is_numeric($row_data['payment_status_id'])) {
            $payment_status_id = (int) $row_data['payment_status_id'];
        } elseif (!empty($payment_status_input)) {
            $status_input = trim($payment_status_input);
            $status_key = strtolower($status_input);
            
            // Try exact lowercase match first
            $payment_status_id = $payment_statuses[$status_key] ?? null;
            
            // If not found, try case-insensitive lookup in database
            if (!$payment_status_id) {
                $stmt_status = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_status WHERE LOWER(dcmt_name) = LOWER(?) AND dcmt_status = 'active' LIMIT 1");
                $stmt_status->execute([$status_input]);
                $status_record = $stmt_status->fetch();
                if ($status_record) {
                    $payment_status_id = $status_record['dcmt_id'];
                    // Update payment_status enum based on the found status name
                    $found_status_name = strtolower(trim($status_record['dcmt_name']));
                    if (in_array($found_status_name, $valid_payment_statuses)) {
                        $payment_status = $found_status_name;
                    } elseif (isset($status_mapping[$found_status_name])) {
                        $payment_status = $status_mapping[$found_status_name];
                    }
                }
            }
        }
        
        // If payment_status_id still not found, try to find by the normalized payment_status enum value
        if (!$payment_status_id && in_array($payment_status, $valid_payment_statuses)) {
            // Map enum values to common status names
            $enum_to_status_name = [
                'completed' => ['Completed', 'Paid', 'Done'],
                'pending' => ['Pending', 'In Process', 'Unpaid'],
                'failed' => ['Failed', 'Declined', 'Error', 'Cancelled', 'Canceled', 'Refunded']
            ];
            
            $possible_names = $enum_to_status_name[$payment_status] ?? [];
            foreach ($possible_names as $name) {
                $name_key = strtolower($name);
                if (isset($payment_statuses[$name_key])) {
                    $payment_status_id = $payment_statuses[$name_key];
                    break;
                }
            }
            
            // If still not found, try database lookup
            if (!$payment_status_id) {
                foreach ($possible_names as $name) {
                    $stmt_status = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_status WHERE LOWER(dcmt_name) = LOWER(?) AND dcmt_status = 'active' LIMIT 1");
                    $stmt_status->execute([$name]);
                    $status_record = $stmt_status->fetch();
                    if ($status_record) {
                        $payment_status_id = $status_record['dcmt_id'];
                        break;
                    }
                }
            }
        }
        
        if (!$payment_method_id && isset($payment_methods['cash'])) {
            $payment_method_id = $payment_methods['cash'];
        }
        
        $transaction_date = trim($row_data['transaction_date'] ?? '');
        if ($transaction_date === '') {
            $transaction_date = date('Y-m-d');
        } else {
            $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y'];
            foreach ($formats as $format) {
                $date = DateTime::createFromFormat($format, $transaction_date);
                if ($date && $date->format($format) === $transaction_date) {
                    $transaction_date = $date->format('Y-m-d');
                    break;
                }
            }
        }
        
        // Insert income record with full database structure
        $sql = "INSERT INTO dcmt_income (
            dcmt_patient_name, dcmt_type, dcmt_description, dcmt_amount,
            dcmt_paid_amount, dcmt_pending_amount, dcmt_consultation_paid_amount, dcmt_product_paid_amount,
            dcmt_total_paid_amount, dcmt_total_pending_amount, dcmt_consultation_fee, dcmt_service_id,
            dcmt_service_amount, dcmt_service_paid_amount, dcmt_service_pending_amount, dcmt_product_amount, dcmt_product_pending_amount,
            dcmt_payment_mode, dcmt_payment_status, dcmt_payment_method_id, dcmt_payment_status_id, dcmt_user_id, dcmt_transaction_date, dcmt_created_by,
            dcmt_created_at, dcmt_updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        // Calculate amounts based on imported data or defaults
        $amount = floatval($row_data['amount']);
        $paid_amount = floatval($row_data['paid_amount'] ?? $amount);
        $pending_amount = floatval($row_data['pending_amount'] ?? ($amount - $paid_amount));
        $consultation_fee = floatval($row_data['consultation_fee'] ?? 0);
        $service_id = !empty($row_data['service_id']) ? intval($row_data['service_id']) : null;
        $service_amount = floatval($row_data['service_amount'] ?? 0);
        $service_paid = floatval($row_data['service_paid_amount'] ?? 0);
        $service_pending = floatval($row_data['service_pending_amount'] ?? 0);
        $product_amount = floatval($row_data['product_amount'] ?? 0);
        $product_pending = floatval($row_data['product_pending_amount'] ?? 0);
        
        // Set consultation_paid_amount and product_paid_amount based on type
        $type = strtolower(trim($row_data['type']));
        if ($type === 'consultation') {
            // For consultation: set consultation_paid_amount to the amount if not provided
            $consultation_paid = floatval($row_data['consultation_paid_amount'] ?? $amount);
            $product_paid = floatval($row_data['product_paid_amount'] ?? 0);
        } else if ($type === 'product_sale') {
            // For product_sale: set product_paid_amount to the amount if not provided
            $consultation_paid = floatval($row_data['consultation_paid_amount'] ?? 0);
            $product_paid = floatval($row_data['product_paid_amount'] ?? $amount);
            $product_amount = floatval($row_data['product_amount'] ?? $amount);
        } else {
            // For other types: use provided values or 0
            $consultation_paid = floatval($row_data['consultation_paid_amount'] ?? 0);
            $product_paid = floatval($row_data['product_paid_amount'] ?? 0);
        }
        
        $total_paid = floatval($row_data['total_paid_amount'] ?? $paid_amount);
        $total_pending = floatval($row_data['total_pending_amount'] ?? $pending_amount);
        
        // Get created_at and updated_at from CSV or use current timestamp
        $created_at = !empty($row_data['created_at']) ? $row_data['created_at'] : date('Y-m-d H:i:s');
        $updated_at = !empty($row_data['updated_at']) ? $row_data['updated_at'] : date('Y-m-d H:i:s');
        
        $stmt = $dcmt_pdo->prepare($sql);
        $result = $stmt->execute([
            trim($row_data['patient_name']),
            strtolower(trim($row_data['type'])),
            trim($row_data['description'] ?? ''),
            $amount,
            $paid_amount,
            $pending_amount,
            $consultation_paid,
            $product_paid,
            $total_paid,
            $total_pending,
            $consultation_fee,
            $service_id,
            $service_amount,
            $service_paid,
            $service_pending,
            $product_amount,
            $product_pending,
            $payment_mode,
            $payment_status,
            $payment_method_id,
            $payment_status_id,
            $doctor_id,
            $transaction_date,
            trim($row_data['created_by'] ?? $_SESSION['dcmt_user']['username']),
            $created_at,
            $updated_at
        ]);
        
        if ($result) {
            $income_id = $dcmt_pdo->lastInsertId();
            
            // Handle service items if provided
            if (!empty($row_data['service_items'])) {
                $service_items_result = processServiceItems($income_id, $row_data['service_items'], $doctors, $services);
                if (!$service_items_result['success']) {
                    return ['success' => false, 'error' => 'Income created but failed to add service items: ' . $service_items_result['error']];
                }
            }
            
            // Handle product items if this is a product sale and items are provided
            if (!empty($row_data['product_items'])) {
                $product_items_result = processProductItems($income_id, $row_data['product_items']);
                if (!$product_items_result['success']) {
                    return ['success' => false, 'error' => 'Income created but failed to add product items: ' . $product_items_result['error']];
                }
            }
            
            // Handle payment details/history if provided
            if (!empty($row_data['payment_details'])) {
                $payment_details_result = processPaymentDetails($income_id, $row_data['payment_details'], $payment_methods);
                if (!$payment_details_result['success']) {
                    return ['success' => false, 'error' => 'Income created but failed to add payment details: ' . $payment_details_result['error']];
                }
            }
            
            return ['success' => true];
        } else {
            $error_info = $stmt->errorInfo();
            return ['success' => false, 'error' => 'Database insert failed: ' . ($error_info[2] ?? 'Unknown error')];
        }
        
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process service items from CSV data
 */
function processServiceItems($income_id, $service_items_data, $doctors, $services) {
    global $dcmt_pdo;
    
    try {
        // Parse service items string
        // Format: "Service Name (Doctor: Doctor Name) - Qty: 2 @ $50.00 = $100.00 | Another Service (Doctor: Doctor Name) - Qty: 1 @ $75.00 = $75.00"
        // Or: "Service Name - Qty: 2 @ $50.00 = $100.00" (without doctor)
        $items = explode(' | ', $service_items_data);
        
        $lineNoStmt = $dcmt_pdo->prepare("SELECT COALESCE(MAX(dcmt_line_no), 0) FROM dcmt_income_breakdown WHERE dcmt_id = ?");
        $lineNoStmt->execute([$income_id]);
        $lineNo = (int)$lineNoStmt->fetchColumn() + 1;

        $insertStmt = $dcmt_pdo->prepare("
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
            ) VALUES (?, ?, 'service', ?, ?, ?, ?, ?, ?, NULL, ?)
        ");

        foreach ($items as $item_string) {
            if (empty(trim($item_string))) continue;
            
            // Parse the item string using regex
            // Pattern with doctor: Service Name (Doctor: Doctor Name) - Qty: 2 @ $50.00 = $100.00
            // Pattern without doctor: Service Name - Qty: 2 @ $50.00 = $100.00
            $doctor_id = null;
            $service_name = '';
            $quantity = 0;
            $unit_price = 0;
            $total_price = 0;
            
            // Try pattern with doctor first
            if (preg_match('/^(.+?)\s*\(Doctor:\s*([^)]+)\)\s*-\s*Qty:\s*([0-9.]+)\s*@\s*\$\s*([0-9.]+)\s*=\s*\$\s*([0-9.]+)$/', $item_string, $matches)) {
                $service_name = trim($matches[1]);
                $doctor_name = trim($matches[2]);
                $quantity = floatval($matches[3]);
                $unit_price = floatval($matches[4]);
                $total_price = floatval($matches[5]);
                
                // Find doctor by name
                $doctor_key = strtolower($doctor_name);
                $doctor_id = $doctors[$doctor_key] ?? null;
                
                if (!$doctor_id) {
                    return ['success' => false, 'error' => "Doctor '$doctor_name' not found for service '$service_name'. Available doctors: " . implode(', ', array_keys($doctors))];
                }
            } 
            // Try pattern without doctor
            elseif (preg_match('/^(.+?)\s*-\s*Qty:\s*([0-9.]+)\s*@\s*\$\s*([0-9.]+)\s*=\s*\$\s*([0-9.]+)$/', $item_string, $matches)) {
                $service_name = trim($matches[1]);
                $quantity = floatval($matches[2]);
                $unit_price = floatval($matches[3]);
                $total_price = floatval($matches[4]);
            } else {
                return ['success' => false, 'error' => "Invalid service item format: '$item_string'. Expected format: 'Service Name (Doctor: Doctor Name) - Qty: 2 @ $50.00 = $100.00' or 'Service Name - Qty: 2 @ $50.00 = $100.00'"];
            }
            
            // Find service by name
            $service_key = strtolower($service_name);
            $service_id = $services[$service_key] ?? null;
            
            if (!$service_id) {
                // Get some example service names to help the user
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_name FROM dcmt_services WHERE dcmt_status = 'active' LIMIT 5");
                $stmt->execute();
                $example_services = $stmt->fetchAll(PDO::FETCH_ASSOC);
                
                $service_examples = [];
                foreach ($example_services as $example) {
                    $service_examples[] = $example['dcmt_name'];
                }
                
                $examples_text = !empty($service_examples) ? ' Available services include: ' . implode(', ', $service_examples) : '';
                
                return ['success' => false, 'error' => "Service '$service_name' not found in services.$examples_text"];
            }
            
            $metadata = json_encode([
                'service_id' => $service_id,
                'user_id' => $doctor_id,
                'source' => 'import'
            ], JSON_UNESCAPED_UNICODE);

            $insertStmt->execute([
                $income_id,
                $lineNo++,
                $service_id,
                $service_name,
                $quantity,
                $unit_price,
                $total_price,
                $doctor_id,
                $metadata
            ]);
        }
        
        return ['success' => true];
        
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process product items from CSV data
 */
function processProductItems($income_id, $product_items_data) {
    global $dcmt_pdo;
    
    try {
        // Parse product items string
        // Format: "Product Name (SKU: SKU-001) - Qty: 2 @ $8.50 = $17.00 | Product Name 2 (SKU: SKU-002) - Qty: 1 @ $5.99 = $5.99"
        $items = explode(' | ', $product_items_data);
        
        $lineNoStmt = $dcmt_pdo->prepare("SELECT COALESCE(MAX(dcmt_line_no), 0) FROM dcmt_income_breakdown WHERE dcmt_id = ?");
        $lineNoStmt->execute([$income_id]);
        $lineNo = (int)$lineNoStmt->fetchColumn() + 1;

        $insertStmt = $dcmt_pdo->prepare("
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
            ) VALUES (?, ?, 'product', ?, ?, ?, ?, ?, NULL, ?, ?)
        ");

        foreach ($items as $item_string) {
            if (empty(trim($item_string))) continue;
            
            // Parse the item string using regex
            // Pattern: Product Name (SKU: SKU-001) - Qty: 2 @ $8.50 = $17.00
            if (preg_match('/^(.+?)\s*\(SKU:\s*([^)]+)\)\s*-\s*Qty:\s*(\d+)\s*@\s*\$\s*([0-9.]+)\s*=\s*\$\s*([0-9.]+)$/', $item_string, $matches)) {
                $product_name = trim($matches[1]);
                $sku = trim($matches[2]);
                $quantity = intval($matches[3]);
                $unit_price = floatval($matches[4]);
                $total_price = floatval($matches[5]);
                
                // Find inventory item by SKU
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_inventory WHERE dcmt_sku = ? AND dcmt_status = 'active'");
                $stmt->execute([$sku]);
                $inventory_item = $stmt->fetch();
                
                if (!$inventory_item) {
                    // Get some example SKUs to help the user
                    $stmt = $dcmt_pdo->prepare("SELECT dcmt_sku, dcmt_name FROM dcmt_inventory WHERE dcmt_status = 'active' LIMIT 5");
                    $stmt->execute();
                    $example_skus = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    $sku_examples = [];
                    foreach ($example_skus as $example) {
                        $sku_examples[] = $example['dcmt_sku'] . ' (' . $example['dcmt_name'] . ')';
                    }
                    
                    $examples_text = !empty($sku_examples) ? ' Available SKUs include: ' . implode(', ', $sku_examples) : '';
                    
                    return ['success' => false, 'error' => "Product with SKU '$sku' not found in inventory.$examples_text"];
                }
                
                // Note: Stock validation is skipped during import as inventory is already reduced when products are used
                
                $metadata = json_encode([
                    'inventory_id' => $inventory_item['dcmt_id'],
                    'source' => 'import'
                ], JSON_UNESCAPED_UNICODE);

                $insertStmt->execute([
                    $income_id,
                    $lineNo++,
                    $inventory_item['dcmt_id'],
                    $product_name,
                    $quantity,
                    $unit_price,
                    $total_price,
                    $inventory_item['dcmt_id'],
                    $metadata
                ]);

                // Note: Inventory stock is not updated during import as it's already reduced when products are used
                
            } else {
                return ['success' => false, 'error' => "Invalid product item format: '$item_string'. Expected format: 'Product Name (SKU: SKU-001) - Qty: 2 @ $8.50 = $17.00'"];
            }
        }
        
        return ['success' => true];
        
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}

/**
 * Process payment details/history from CSV data
 */
function processPaymentDetails($income_id, $payment_details_data, $payment_methods) {
    global $dcmt_pdo;
    
    try {
        // Parse payment details string
        // Format: "type|amount|paid_on|payment_method|recorded_by|notes || type|amount|paid_on|payment_method|recorded_by|notes"
        $payment_entries = explode(' || ', $payment_details_data);
        
        $current_user = dcmt_get_current_user();
        $default_recorded_by = is_array($current_user) && !empty($current_user['dcmt_username'])
            ? $current_user['dcmt_username']
            : 'system';
        
        foreach ($payment_entries as $entry_string) {
            if (empty(trim($entry_string))) continue;
            
            // Parse the entry: type|amount|paid_on|payment_method|recorded_by|notes
            $parts = explode('|', $entry_string);
            
            if (count($parts) < 3) {
                return ['success' => false, 'error' => "Invalid payment detail format: '$entry_string'. Expected format: 'type|amount|paid_on|payment_method|recorded_by|notes'"];
            }
            
            $payment_type = strtolower(trim($parts[0]));
            $valid_payment_types = ['consultation', 'product', 'general'];
            if (!in_array($payment_type, $valid_payment_types)) {
                $payment_type = 'general'; // Default to general if invalid
            }
            
            $amount = floatval($parts[1]);
            if ($amount <= 0) {
                continue; // Skip zero or negative amounts
            }
            
            $paid_on = trim($parts[2]);
            // Validate date format
            $date = DateTime::createFromFormat('Y-m-d', $paid_on);
            if (!$date || $date->format('Y-m-d') !== $paid_on) {
                return ['success' => false, 'error' => "Invalid date format in payment detail: '$paid_on'. Expected format: YYYY-MM-DD"];
            }
            
            $payment_method_name = isset($parts[3]) ? trim($parts[3]) : '';
            $recorded_by = isset($parts[4]) && !empty(trim($parts[4])) ? trim($parts[4]) : $default_recorded_by;
            $notes_text = isset($parts[5]) ? trim($parts[5]) : '';
            
            // Get payment method ID if provided
            $payment_method_id = null;
            $notes = null;
            
            if (!empty($payment_method_name)) {
                $method_key = strtolower($payment_method_name);
                $payment_method_id = $payment_methods[$method_key] ?? null;
                
                // Try database lookup if not found
                if (!$payment_method_id) {
                    $stmt_method = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE LOWER(dcmt_name) = LOWER(?) AND dcmt_status = 'active' LIMIT 1");
                    $stmt_method->execute([$payment_method_name]);
                    $method_record = $stmt_method->fetch();
                    if ($method_record) {
                        $payment_method_id = $method_record['dcmt_id'];
                    }
                }
            }
            
            // Build notes JSON
            $notes_array = [];
            if ($payment_method_id !== null) {
                $notes_array['payment_method_id'] = $payment_method_id;
            }
            
            // Add additional notes if provided
            if (!empty($notes_text)) {
                // Try to parse as JSON first
                $parsed_notes = json_decode($notes_text, true);
                if (is_array($parsed_notes)) {
                    $notes_array = array_merge($notes_array, $parsed_notes);
                } else {
                    // If not JSON, store as text note
                    if (!isset($notes_array['note'])) {
                        $notes_array['note'] = $notes_text;
                    }
                }
            }
            
            unset($notes_array['payment_method_id']);
            $notes = null;
            if (!empty($notes_array['note'])) {
                $notes = trim((string) $notes_array['note']);
            } elseif (!empty($notes_array)) {
                $notes = json_encode($notes_array, JSON_UNESCAPED_UNICODE);
            }

            if (!function_exists('dcmt_add_payment_history_entry')) {
                require_once __DIR__ . '/../../includes/income_payment_history.php';
            }
            dcmt_add_payment_history_entry(
                $dcmt_pdo,
                (int) $income_id,
                $payment_type,
                $amount,
                $paid_on,
                $recorded_by,
                $payment_method_id ? (int) $payment_method_id : null,
                null,
                $notes
            );
        }
        
        return ['success' => true];
        
    } catch (PDOException $e) {
        return ['success' => false, 'error' => $e->getMessage()];
    }
}
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mb-2">
            <div class="dcmt-page-header">
                <h4 class="dcmt-page-title">
                    <i class="fas fa-upload me-2"></i><?php echo trans('income', 'import_income'); ?>
                </h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-csv me-2"></i><?php echo trans('income', 'upload_csv_file'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($import_errors)): ?>
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('income', 'import_errors'); ?></h6>
                            <ul class="mb-0">
                                <?php foreach ($import_errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($import_success)): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i><?php echo trans('income', 'import_success'); ?></h6>
                            <p><?php echo sprintf(trans('income', 'import_summary'), $import_result['imported_count'], $import_result['skipped_count']); ?></p>
                            <?php if (count($import_success) <= 10): ?>
                                <ul class="mb-0">
                                    <?php foreach ($import_success as $success_msg): ?>
                                        <li><?php echo htmlspecialchars($success_msg); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-4">
                            <label for="csv_file" class="form-label">
                                <?php echo trans('income', 'select_csv_file'); ?> <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="csv_file" name="csv_file" 
                                   accept=".csv" required>
                            <div class="form-text">
                                <?php echo trans('income', 'csv_file_help'); ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="import_income" class="btn btn-primary">
                                <i class="fas fa-upload me-2"></i><?php echo trans('income', 'import_data'); ?>
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i><?php echo trans('common', 'back'); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i><?php echo trans('income', 'import_instructions'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <h6><?php echo trans('income', 'required_fields'); ?>:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i>patient_name</li>
                        <li><i class="fas fa-check text-success me-2"></i>type (consultation/product_sale)</li>
                        <li><i class="fas fa-check text-success me-2"></i>amount</li>
                        <li><i class="fas fa-check text-success me-2"></i>transaction_date (YYYY-MM-DD)</li>
                    </ul>

                    <h6><?php echo trans('income', 'optional_fields'); ?>:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-info text-info me-2"></i>description</li>
                        <li><i class="fas fa-info text-info me-2"></i>paid_amount (default: amount)</li>
                        <li><i class="fas fa-info text-info me-2"></i>pending_amount (default: amount - paid_amount)</li>
                        <li><i class="fas fa-info text-info me-2"></i>consultation_fee</li>
                        <li><i class="fas fa-info text-info me-2"></i>consultation_paid_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>product_paid_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>total_paid_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>total_pending_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>service_id</li>
                        <li><i class="fas fa-info text-info me-2"></i>service_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>service_paid_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>service_pending_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>product_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>product_pending_amount</li>
                        <li><i class="fas fa-info text-info me-2"></i>payment_mode (cash/card/bank_transfer/online)</li>
                        <li><i class="fas fa-info text-info me-2"></i>payment_method</li>
                        <li><i class="fas fa-info text-info me-2"></i>payment_status (completed/pending/failed)</li>
                        <li><i class="fas fa-info text-info me-2"></i>doctor_name</li>
                        <li><i class="fas fa-info text-info me-2"></i>created_by</li>
                        <li><i class="fas fa-info text-info me-2"></i>service_items (for consultation type)</li>
                        <li><i class="fas fa-info text-info me-2"></i>product_items (for product_sale type)</li>
                        <li><i class="fas fa-info text-info me-2"></i>payment_details (payment history entries)</li>
                    </ul>

                    <div class="alert alert-warning mt-3">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong><?php echo trans('income', 'import_limit_notice'); ?>:</strong> 
                            <?php 
                            $max_records = defined('DCMT_MAX_IMPORT_RECORDS') ? DCMT_MAX_IMPORT_RECORDS : 500;
                            echo sprintf(trans('income', 'max_records_per_import'), $max_records); 
                            ?>
                        </small>
                    </div>
                    
                    <div class="alert alert-info mt-2">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo trans('income', 'template_note'); ?>
                        </small>
                    </div>

                    <div class="alert alert-warning mt-2">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong>Importing Exported Files:</strong> You can import previously exported CSV files. 
                            Records with existing IDs will be skipped automatically.
                        </small>
                    </div>

                    <div class="alert alert-info mt-2">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Service Items Format:</strong> For consultation type, use format:<br>
                            <code>Service Name (Doctor: Doctor Name) - Qty: 2 @ $50.00 = $100.00 | Another Service (Doctor: Doctor Name) - Qty: 1 @ $75.00 = $75.00</code><br>
                            Or without doctor: <code>Service Name - Qty: 2 @ $50.00 = $100.00</code><br>
                            <strong>Important:</strong> Service names and doctor names must match existing records (see lists below).
                        </small>
                    </div>

                    <div class="alert alert-info mt-2">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Product Items Format:</strong> For product_sale type, use format:<br>
                            <code>Product Name (SKU: ACTUAL-SKU-HERE) - Qty: 2 @ $8.50 = $17.00 | Another Product (SKU: ANOTHER-SKU-HERE) - Qty: 1 @ $5.99 = $5.99</code><br>
                            <strong>Important:</strong> Replace "ACTUAL-SKU-HERE" with real SKUs from your inventory (see list below).
                        </small>
                    </div>

                    <div class="alert alert-info mt-2">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <strong>Payment Details Format:</strong> Payment history entries, separated by " || ":<br>
                            <code>consultation|100.00|2024-01-15|Cash|admin| || product|50.00|2024-01-16|Card|admin|</code><br>
                            Format for each entry: <code>type|amount|paid_on|payment_method|recorded_by|notes</code><br>
                            <strong>Types:</strong> consultation, product, or general<br>
                            <strong>Notes:</strong> Optional, can be JSON or plain text
                        </small>
                    </div>

                    <?php
                    // Get available services for reference
                    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_services WHERE dcmt_status = 'active' ORDER BY dcmt_name LIMIT 10");
                    $stmt->execute();
                    $available_services = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get available doctors for reference
                    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_full_name FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name LIMIT 10");
                    $stmt->execute();
                    $available_doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    
                    // Get available inventory items for reference
                    $stmt = $dcmt_pdo->prepare("SELECT dcmt_sku, dcmt_name, dcmt_price, dcmt_quantity FROM dcmt_inventory WHERE dcmt_status = 'active' ORDER BY dcmt_name LIMIT 10");
                    $stmt->execute();
                    $available_products = $stmt->fetchAll(PDO::FETCH_ASSOC);
                    ?>
                    
                    <?php if (!empty($available_services)): ?>
                    <div class="alert alert-light mt-2">
                        <small>
                            <i class="fas fa-list me-1"></i>
                            <strong>Available Services (for reference):</strong><br>
                            <?php foreach ($available_services as $service): ?>
                                <span class="badge bg-light text-dark me-1 mb-1">
                                    <?php echo htmlspecialchars($service['dcmt_name']); ?>
                                </span>
                            <?php endforeach; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($available_doctors)): ?>
                    <div class="alert alert-light mt-2">
                        <small>
                            <i class="fas fa-list me-1"></i>
                            <strong>Available Doctors (for reference):</strong><br>
                            <?php foreach ($available_doctors as $doctor): ?>
                                <span class="badge bg-light text-dark me-1 mb-1">
                                    <?php echo htmlspecialchars($doctor['dcmt_full_name']); ?>
                                </span>
                            <?php endforeach; ?>
                        </small>
                    </div>
                    <?php endif; ?>
                    
                    <?php if (!empty($available_products)): ?>
                    <div class="alert alert-light mt-2">
                        <small>
                            <i class="fas fa-list me-1"></i>
                            <strong>Available Products (for reference):</strong><br>
                            <?php foreach ($available_products as $product): ?>
                                <span class="badge bg-light text-dark me-1 mb-1">
                                    <?php echo htmlspecialchars($product['dcmt_sku']); ?> 
                                    (<?php echo htmlspecialchars($product['dcmt_name']); ?>)
                                    - Stock: <?php echo $product['dcmt_quantity']; ?>
                                    - Price: $<?php echo number_format($product['dcmt_price'], 2); ?>
                                </span>
                            <?php endforeach; ?>
                        </small>
                    </div>
                    <?php endif; ?>

                    <div class="mt-3">
                        <a href="download_template.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-download me-2"></i><?php echo trans('income', 'download_template'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Form validation
(function() {
    'use strict';
    window.addEventListener('load', function() {
        var forms = document.getElementsByClassName('needs-validation');
        var validation = Array.prototype.filter.call(forms, function(form) {
            form.addEventListener('submit', function(event) {
                if (form.checkValidity() === false) {
                    event.preventDefault();
                    event.stopPropagation();
                }
                form.classList.add('was-validated');
            }, false);
        });
    }, false);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
