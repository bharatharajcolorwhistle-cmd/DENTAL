<?php
/**
 * Import Expenses Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/expense_import_csv.php';

dcmt_require_admin_or_staff();

require_once __DIR__ . '/../../includes/header.php';

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Handle file upload and processing
$import_result = null;
$import_errors = [];
$import_success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_expenses'])) {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $import_errors[] = trans('common', 'invalid_token');
    } else {
        // Check if file was uploaded
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $import_errors[] = trans('expense', 'no_file_uploaded');
        } else {
            $file = $_FILES['csv_file'];

            if (!dcmt_validate_csv_upload($file)) {
                $import_errors[] = trans('expense', 'invalid_file_type');
            } else {
                // Process CSV file
                $import_result = processExpenseImport($file['tmp_name']);
                $import_errors = $import_result['errors'];
                $import_success = $import_result['success'];
            }
        }
    }
}

/**
 * Process the uploaded CSV file and import expense records
 */
function processExpenseImport($file_path) {
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
        $errors[] = trans('expense', 'cannot_read_file');
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
        $mapped = dcmt_expense_import_map_header_row($candidate);
        if (dcmt_expense_import_mapped_headers_are_valid($mapped)) {
            $mapped_headers = $mapped;
            break;
        }
    }

    if ($mapped_headers === null) {
        if ($header_line_number === 0) {
            $errors[] = trans('expense', 'empty_file');
        } else {
            $errors[] = sprintf(
                trans('expense', 'missing_required_headers'),
                implode(', ', ['title', 'amount', 'expense_date'])
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
        $errors[] = sprintf(trans('expense', 'import_limit_exceeded'), $total_rows, $max_records);
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'skipped_count' => 0];
    }

    // Validate headers (internal keys)
    $required_headers = ['title', 'amount', 'expense_date'];

    $header_errors = validateHeaders($mapped_headers, $required_headers);
    if (!empty($header_errors)) {
        $errors = array_merge($errors, $header_errors);
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'skipped_count' => 0];
    }

    $headers = $mapped_headers;
    // Get lookup data
    $categories = getExpenseCategoriesLookup();
    $payment_methods = getExpensePaymentMethodsLookup();
    
    $row_number = 1; // Start from 1 since we already read headers
    
    // Process each row
    while (($data = fgetcsv($handle, 0, ',', '"', '\\')) !== FALSE) {
        $row_number++;
        
        // Skip empty rows or rows with insufficient data
        if (empty(array_filter($data)) || count($data) < 3) {
            continue;
        }
        
        // Ensure we have enough columns for required fields
        if (count($data) < count($required_headers)) {
            $errors[] = sprintf("Row %d: Insufficient columns. Expected at least %d columns, got %d", $row_number, count($required_headers), count($data));
            $skipped_count++;
            continue;
        }
        
        // Validate row data
        $row_errors = validateExpenseRow($data, $headers, $row_number);
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
        
        // Process and insert expense record
        $insert_result = insertExpenseRecord($row_data, $categories, $payment_methods);
        
        if ($insert_result['success']) {
            $imported_count++;
            $success[] = sprintf(trans('expense', 'row_imported_successfully'), $row_number, $row_data['title']);
        } else {
            $error_message = $insert_result['error'];
            $errors[] = sprintf(trans('expense', 'row_import_failed'), $row_number, $error_message);
            $skipped_count++;
        }
    }
    
    fclose($handle);
    
    // Log import activity
    if ($imported_count > 0) {
        dcmt_log_activity("Expense records imported from CSV - {$imported_count} records imported, {$skipped_count} skipped", "expense_imported");
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
        $errors[] = sprintf(trans('expense', 'missing_required_headers'), implode(', ', $missing_required));
    }
    
    return $errors;
}

/**
 * Validate expense row data
 */
function validateExpenseRow($data, $headers, $row_number) {
    $errors = [];
    $row_data = [];
    $max = min(count($headers), count($data));
    for ($i = 0; $i < $max; $i++) {
        $row_data[$headers[$i]] = $data[$i] ?? '';
    }

    // Required fields validation
    $required_fields = ['title', 'amount', 'expense_date'];
    foreach ($required_fields as $field) {
        if (trim((string) ($row_data[$field] ?? '')) === '') {
            $errors[] = sprintf(trans('expense', 'required_field_missing'), $field, $row_number);
        }
    }
    
    // Payment status validation - handle both English and translated values
    if (!empty($row_data['payment_status'])) {
        $status_value = strtolower(trim($row_data['payment_status']));
        $valid_payment_statuses = ['paid', 'pending', 'overdue'];
        $translated_statuses = [
            'pagado' => 'paid',
            'pendiente' => 'pending',
            'vencido' => 'overdue',
            'atrasado' => 'overdue'
        ];
        
        if (!in_array($status_value, $valid_payment_statuses)) {
            if (isset($translated_statuses[$status_value])) {
                $row_data['payment_status'] = $translated_statuses[$status_value];
            } else {
                $errors[] = sprintf(trans('expense', 'invalid_payment_status'), $row_data['payment_status'], $row_number);
            }
        }
    }
    
    // Amount validation
    if (!empty($row_data['amount'])) {
        if (!is_numeric($row_data['amount']) || floatval($row_data['amount']) <= 0) {
            $errors[] = sprintf(trans('expense', 'invalid_amount'), $row_data['amount'], $row_number);
        }
    }
    
    // Date validation
    if (!empty($row_data['expense_date'])) {
        $raw_date = trim($row_data['expense_date']);
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
            $errors[] = sprintf(trans('expense', 'invalid_date_format'), $row_data['expense_date'], $row_number);
        }
    }
    
    return $errors;
}

/**
 * Get expense categories lookup array
 */
function getExpenseCategoriesLookup() {
    global $dcmt_pdo;
    
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_expense_categories WHERE dcmt_status = 'active'");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lookup = [];
    foreach ($categories as $category) {
        $lookup[strtolower($category['dcmt_name'])] = $category['dcmt_id'];
    }
    
    return $lookup;
}

/**
 * Get expense payment methods lookup array
 */
function getExpensePaymentMethodsLookup() {
    global $dcmt_pdo;
    
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_expense_payment_methods WHERE dcmt_status = 'active'");
    $stmt->execute();
    $methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lookup = [];
    foreach ($methods as $method) {
        $lookup[strtolower($method['dcmt_name'])] = $method['dcmt_id'];
    }
    
    return $lookup;
}

/**
 * Insert expense record into database
 */
function insertExpenseRecord($row_data, $categories, $payment_methods) {
    global $dcmt_pdo;
    
    try {
        // Get category ID
        $category_id = null;
        if (!empty($row_data['category_id']) && is_numeric($row_data['category_id'])) {
            $category_id = (int) $row_data['category_id'];
        } elseif (!empty($row_data['category_name'])) {
            $category_key = strtolower(trim($row_data['category_name']));
            $category_id = $categories[$category_key] ?? null;
        }
        
        // Get payment method ID
        $payment_method_id = null;
        if (!empty($row_data['payment_method_id']) && is_numeric($row_data['payment_method_id'])) {
            $payment_method_id = (int) $row_data['payment_method_id'];
        } elseif (!empty($row_data['payment_method'])) {
            $method_key = strtolower(trim($row_data['payment_method']));
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
            }
            $payment_method_id = $payment_methods[$method_key] ?? null;
        }
        
        if (!$payment_method_id && isset($payment_methods['cash'])) {
            $payment_method_id = $payment_methods['cash'];
        }
        
        $resolved_payment_method_name = trim((string)($row_data['payment_method'] ?? ''));
        if ($payment_method_id) {
            $stmt_method = $dcmt_pdo->prepare("SELECT dcmt_name FROM dcmt_expense_payment_methods WHERE dcmt_id = ?");
            $stmt_method->execute([$payment_method_id]);
            $method_record = $stmt_method->fetch();
            if ($method_record && isset($method_record['dcmt_name']) && $method_record['dcmt_name'] !== '') {
                $resolved_payment_method_name = $method_record['dcmt_name'];
            } else {
                $payment_method_id = null;
            }
        }
        
        $payment_method = $resolved_payment_method_name !== ''
            ? substr(strtolower(trim($resolved_payment_method_name)), 0, 50)
            : 'cash';
        
        // Determine payment_status from CSV
        $payment_status = strtolower(trim($row_data['payment_status'] ?? 'paid'));
        $valid_payment_statuses = ['paid', 'pending', 'overdue'];
        
        // If payment_status from CSV doesn't match valid values, try to map it
        if (!in_array($payment_status, $valid_payment_statuses)) {
            $translated_statuses = [
                'pagado' => 'paid',
                'pendiente' => 'pending',
                'vencido' => 'overdue',
                'atrasado' => 'overdue'
            ];
            if (isset($translated_statuses[$payment_status])) {
                $payment_status = $translated_statuses[$payment_status];
            } else {
                $payment_status = 'paid'; // Default to paid if invalid
            }
        }
        
        $expense_date = trim($row_data['expense_date'] ?? '');
        if ($expense_date === '') {
            $expense_date = date('Y-m-d');
        } else {
            $formats = ['Y-m-d', 'd-m-Y', 'd/m/Y'];
            foreach ($formats as $format) {
                $date = DateTime::createFromFormat($format, $expense_date);
                if ($date && $date->format($format) === $expense_date) {
                    $expense_date = $date->format('Y-m-d');
                    break;
                }
            }
        }
        
        // Insert expense record
        $sql = "INSERT INTO dcmt_expenses (
            dcmt_title, dcmt_description, dcmt_category_id, dcmt_amount, 
            dcmt_payment_method, dcmt_payment_status, dcmt_expense_date, 
            dcmt_notes, dcmt_created_by, dcmt_payment_method_id,
            dcmt_created_at, dcmt_updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        // Get created_at and updated_at from CSV or use current timestamp
        $created_at = !empty($row_data['created_at']) ? $row_data['created_at'] : date('Y-m-d H:i:s');
        $updated_at = !empty($row_data['updated_at']) ? $row_data['updated_at'] : date('Y-m-d H:i:s');
        
        $stmt = $dcmt_pdo->prepare($sql);
        $result = $stmt->execute([
            trim($row_data['title']),
            trim($row_data['description'] ?? ''),
            $category_id,
            floatval($row_data['amount']),
            $payment_method,
            $payment_status,
            $expense_date,
            trim($row_data['notes'] ?? ''),
            trim($row_data['created_by'] ?? $_SESSION['dcmt_user']['username']),
            $payment_method_id,
            $created_at,
            $updated_at
        ]);
        
        if ($result) {
            return ['success' => true];
        } else {
            $error_info = $stmt->errorInfo();
            return ['success' => false, 'error' => 'Database insert failed: ' . ($error_info[2] ?? 'Unknown error')];
        }
        
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
                    <i class="fas fa-upload me-2"></i><?php echo trans('expense', 'import_expenses'); ?>
                </h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-csv me-2"></i><?php echo trans('expense', 'upload_csv_file'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($import_errors)): ?>
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('expense', 'import_errors'); ?></h6>
                            <ul class="mb-0">
                                <?php foreach ($import_errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($import_success)): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i><?php echo trans('expense', 'import_success'); ?></h6>
                            <p><?php echo sprintf(trans('expense', 'import_summary'), $import_result['imported_count'], $import_result['skipped_count']); ?></p>
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
                                <?php echo trans('expense', 'select_csv_file'); ?> <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="csv_file" name="csv_file" 
                                   accept=".csv" required>
                            <div class="form-text">
                                <?php echo trans('expense', 'csv_file_help'); ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="import_expenses" class="btn btn-primary">
                                <i class="fas fa-upload me-2"></i><?php echo trans('expense', 'import_data'); ?>
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
                        <i class="fas fa-info-circle me-2"></i><?php echo trans('expense', 'import_instructions'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <h6><?php echo trans('expense', 'required_fields'); ?>:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_title')); ?></li>
                        <li><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_amount')); ?></li>
                        <li><i class="fas fa-check text-success me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_expense_date')); ?> (YYYY-MM-DD)</li>
                    </ul>

                    <h6><?php echo trans('expense', 'optional_fields'); ?>:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_description')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_category_id')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_category_name')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_payment_method_id')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_payment_method')); ?> (cash/card/bank_transfer/online)</li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_payment_status')); ?> (paid/pending/overdue)</li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_notes')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_created_by')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_id')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_created_at')); ?></li>
                        <li><i class="fas fa-info text-info me-2"></i><?php echo htmlspecialchars(trans('expense', 'import_csv_hdr_updated_at')); ?></li>
                    </ul>

                    <div class="alert alert-warning mt-3">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong><?php echo trans('expense', 'import_limit_notice'); ?>:</strong> 
                            <?php 
                            $max_records = defined('DCMT_MAX_IMPORT_RECORDS') ? DCMT_MAX_IMPORT_RECORDS : 500;
                            echo sprintf(trans('expense', 'max_records_per_import'), $max_records); 
                            ?>
                        </small>
                    </div>
                    
                    <div class="alert alert-info mt-2">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo trans('expense', 'template_note'); ?>
                        </small>
                    </div>

                    <div class="alert alert-warning mt-2">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <?php echo htmlspecialchars(trans('expense', 'import_csv_note_reexport')); ?>
                        </small>
                    </div>

                    <div class="mt-3">
                        <a href="download_template.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-download me-2"></i><?php echo trans('expense', 'download_template'); ?>
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
