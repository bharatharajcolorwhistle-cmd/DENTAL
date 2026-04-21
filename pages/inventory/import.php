<?php
/**
 * Import Inventory Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin_or_staff();

require_once __DIR__ . '/../../includes/header.php';

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Handle file upload and processing
$import_result = null;
$import_errors = [];
$import_success = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_inventory'])) {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $import_errors[] = trans('common', 'invalid_token');
    } else {
        // Check if file was uploaded
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $import_errors[] = trans('inventory', 'no_file_uploaded');
        } else {
            $file = $_FILES['csv_file'];
            
            // Validate file type
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file_extension !== 'csv') {
                $import_errors[] = trans('inventory', 'invalid_file_type');
            } else {
                // Process CSV file
                $import_result = processInventoryImport($file['tmp_name']);
                $import_errors = $import_result['errors'];
                $import_success = $import_result['success'];
            }
        }
    }
}

/**
 * Process the uploaded CSV file and import inventory records
 */
function processInventoryImport($file_path) {
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
        $errors[] = trans('inventory', 'cannot_read_file');
        return ['errors' => $errors, 'success' => $success];
    }
    
    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        $errors[] = trans('inventory', 'empty_file');
        fclose($handle);
        return ['errors' => $errors, 'success' => $success];
    }
    
    // Count total rows in CSV file (excluding header) - only count non-empty rows
    $total_rows = 0;
    $temp_handle = fopen($file_path, 'r');
    if ($temp_handle) {
        // Skip header
        fgetcsv($temp_handle);
        // Count data rows (non-empty rows only)
        while (($row = fgetcsv($temp_handle)) !== FALSE) {
            // Count rows that have at least some data (not completely empty)
            if (!empty(array_filter($row))) {
                $total_rows++;
            }
        }
        fclose($temp_handle);
    }
    
    // Check if total rows exceed the maximum limit
    $max_records = defined('DCMT_MAX_IMPORT_RECORDS') ? DCMT_MAX_IMPORT_RECORDS : 500;
    if ($total_rows > $max_records) {
        $errors[] = sprintf(trans('inventory', 'import_limit_exceeded'), $total_rows, $max_records);
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'skipped_count' => 0];
    }
    
    // Clean headers (remove any whitespace and BOM)
    $headers = array_map('trim', $headers);
    
    // Remove BOM from first header if present
    if (!empty($headers[0])) {
        // Check for UTF-8 BOM
        if (substr($headers[0], 0, 3) === "\xEF\xBB\xBF") {
            $headers[0] = substr($headers[0], 3);
        }
        // Check for UTF-16 BOM
        if (substr($headers[0], 0, 2) === "\xFF\xFE" || substr($headers[0], 0, 2) === "\xFE\xFF") {
            $headers[0] = substr($headers[0], 2);
        }
        // Remove any invisible characters
        $headers[0] = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $headers[0]);
    }
    
    // Validate headers - Check for required fields, others are optional
    $required_headers = ['name', 'sku', 'price'];
    $optional_headers = ['brand', 'description', 'category_name', 'quantity', 'min_quantity', 'price', 'status', 'supplier', 'expiry_date', 'created_by', 'id', 'created_at', 'updated_at'];
    
    $header_errors = validateHeaders($headers, $required_headers);
    if (!empty($header_errors)) {
        $errors = array_merge($errors, $header_errors);
        fclose($handle);
        return ['errors' => $errors, 'success' => $success];
    }
    
    // Get lookup data
    $categories = getInventoryCategoriesLookup();
    
    $row_number = 1; // Start from 1 since we already read headers
    
    // Process each row
    while (($data = fgetcsv($handle)) !== FALSE) {
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
        $row_errors = validateInventoryRow($data, $headers, $row_number);
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
        
        // Process and insert inventory record
        $insert_result = insertInventoryRecord($row_data, $categories);
        
        if ($insert_result['success']) {
            $imported_count++;
            $success[] = sprintf(trans('inventory', 'row_imported_successfully'), $row_number, $row_data['name']);
        } else {
            $error_message = $insert_result['error'];
            $errors[] = sprintf(trans('inventory', 'row_import_failed'), $row_number, $error_message);
            $skipped_count++;
        }
    }
    
    fclose($handle);
    
    // Log import activity
    if ($imported_count > 0) {
        dcmt_log_activity("Inventory Imported", "{$imported_count} records imported, {$skipped_count} skipped");
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
        $errors[] = sprintf(trans('inventory', 'missing_required_headers'), implode(', ', $missing_required));
    }
    
    return $errors;
}

/**
 * Validate inventory row data
 */
function validateInventoryRow($data, $headers, $row_number) {
    $errors = [];
    $row_data = array_combine($headers, $data);
    
    // Required fields validation
    $required_fields = ['name', 'sku', 'price'];
    foreach ($required_fields as $field) {
        if (empty(trim($row_data[$field] ?? ''))) {
            $errors[] = sprintf(trans('inventory', 'required_field_missing'), $field, $row_number);
        }
    }
    
    // Status validation - handle both English and translated values
    if (!empty($row_data['status'])) {
        $status_value = strtolower(trim($row_data['status']));
        $valid_statuses = ['active', 'inactive'];
        $translated_statuses = [
            'activo' => 'active',
            'inactivo' => 'inactive'
        ];
        
        if (!in_array($status_value, $valid_statuses)) {
            if (isset($translated_statuses[$status_value])) {
                $row_data['status'] = $translated_statuses[$status_value];
            } else {
                // Default to active if invalid status provided
                $row_data['status'] = 'active';
            }
        }
    }
    
    // Price validation
    if (!empty($row_data['price'])) {
        if (!is_numeric($row_data['price']) || floatval($row_data['price']) < 0) {
            $errors[] = sprintf(trans('inventory', 'invalid_price'), $row_data['price'], $row_number);
        }
    }
    
    // Quantity validation (if provided)
    if (!empty($row_data['quantity'])) {
        if (!is_numeric($row_data['quantity']) || intval($row_data['quantity']) < 0) {
            $errors[] = sprintf(trans('inventory', 'invalid_quantity'), $row_data['quantity'], $row_number);
        }
    }
    
    // Min quantity validation (if provided)
    if (!empty($row_data['min_quantity'])) {
        if (!is_numeric($row_data['min_quantity']) || intval($row_data['min_quantity']) < 0) {
            $errors[] = sprintf(trans('inventory', 'invalid_min_quantity'), $row_data['min_quantity'], $row_number);
        }
    }
    
    // Expiry date validation (if provided)
    if (!empty($row_data['expiry_date'])) {
        $expiry_date = trim($row_data['expiry_date']);
        if (!empty($expiry_date)) {
            // Validate date format (YYYY-MM-DD)
            if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $expiry_date)) {
                $errors[] = sprintf(trans('inventory', 'invalid_date_format'), $expiry_date, $row_number);
            } else {
                // Validate that it's a valid date
                $date_parts = explode('-', $expiry_date);
                if (!checkdate($date_parts[1], $date_parts[2], $date_parts[0])) {
                    $errors[] = sprintf(trans('inventory', 'invalid_date_format'), $expiry_date, $row_number);
                }
            }
        }
    }
    
    // SKU validation - check for duplicates
    if (!empty($row_data['sku'])) {
        global $dcmt_pdo;
        $sku = trim($row_data['sku']);
        $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_inventory WHERE dcmt_sku = ?");
        $stmt->execute([$sku]);
        if ($stmt->fetchColumn() > 0) {
            $errors[] = sprintf(trans('inventory', 'sku_already_exists'), $sku, $row_number);
        }
    }
    
    return $errors;
}

/**
 * Get inventory categories lookup array
 */
function getInventoryCategoriesLookup() {
    global $dcmt_pdo;
    
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_inventory_categories WHERE dcmt_status = 'active'");
    $stmt->execute();
    $categories = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $lookup = [];
    foreach ($categories as $category) {
        $lookup[strtolower($category['dcmt_name'])] = $category['dcmt_id'];
    }
    
    return $lookup;
}

/**
 * Insert inventory record into database
 */
function insertInventoryRecord($row_data, $categories) {
    global $dcmt_pdo;
    
    try {
        // Get category ID
        $category_id = null;
        if (!empty($row_data['category_name'])) {
            $category_key = strtolower(trim($row_data['category_name']));
            $category_id = $categories[$category_key] ?? null;
        }
        
        // Determine status from CSV
        $status = strtolower(trim($row_data['status'] ?? 'active'));
        $valid_statuses = ['active', 'inactive'];
        
        // If status from CSV doesn't match valid values, try to map it
        if (!in_array($status, $valid_statuses)) {
            $translated_statuses = [
                'activo' => 'active',
                'inactivo' => 'inactive'
            ];
            if (isset($translated_statuses[$status])) {
                $status = $translated_statuses[$status];
            } else {
                $status = 'active'; // Default to active if invalid
            }
        }
        
        // Insert inventory record
        $sql = "INSERT INTO dcmt_inventory (
            dcmt_name, dcmt_brand, dcmt_sku, dcmt_description, dcmt_category_id, 
            dcmt_quantity, dcmt_min_quantity, dcmt_price, dcmt_status, 
            dcmt_supplier, dcmt_expiry_date, dcmt_created_by,
            dcmt_created_at, dcmt_updated_at
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
        
        // Handle expiry date - convert empty string to null
        $expiry_date = null;
        if (!empty($row_data['expiry_date'])) {
            $expiry_date = trim($row_data['expiry_date']);
            if (empty($expiry_date)) {
                $expiry_date = null;
            }
        }
        
        // Get created_at and updated_at from CSV or use current timestamp
        $created_at = !empty($row_data['created_at']) ? $row_data['created_at'] : date('Y-m-d H:i:s');
        $updated_at = !empty($row_data['updated_at']) ? $row_data['updated_at'] : date('Y-m-d H:i:s');
        
        $stmt = $dcmt_pdo->prepare($sql);
        $result = $stmt->execute([
            trim($row_data['name']),
            trim($row_data['brand'] ?? ''),
            trim($row_data['sku']),
            trim($row_data['description'] ?? ''),
            $category_id,
            intval($row_data['quantity'] ?? 0),
            intval($row_data['min_quantity'] ?? 10),
            floatval($row_data['price']),
            $status,
            trim($row_data['supplier'] ?? ''),
            $expiry_date,
            trim($row_data['created_by'] ?? $_SESSION['dcmt_user']['username']),
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
                    <i class="fas fa-upload me-2"></i><?php echo trans('inventory', 'import_inventory'); ?>
                </h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-csv me-2"></i><?php echo trans('inventory', 'upload_csv_file'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($import_errors)): ?>
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('inventory', 'import_errors'); ?></h6>
                            <ul class="mb-0">
                                <?php foreach ($import_errors as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($import_success)): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i><?php echo trans('inventory', 'import_success'); ?></h6>
                            <p><?php echo sprintf(trans('inventory', 'import_summary'), $import_result['imported_count'], $import_result['skipped_count']); ?></p>
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
                                <?php echo trans('inventory', 'select_csv_file'); ?> <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="csv_file" name="csv_file" 
                                   accept=".csv" required>
                            <div class="form-text">
                                <?php echo trans('inventory', 'csv_file_help'); ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="import_inventory" class="btn btn-primary">
                                <i class="fas fa-upload me-2"></i><?php echo trans('inventory', 'import_data'); ?>
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
                        <i class="fas fa-info-circle me-2"></i><?php echo trans('inventory', 'import_instructions'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <h6><?php echo trans('inventory', 'required_fields'); ?>:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-check text-success me-2"></i>name</li>
                        <li><i class="fas fa-check text-success me-2"></i>sku (must be unique)</li>
                        <li><i class="fas fa-check text-success me-2"></i>price</li>
                    </ul>

                    <h6><?php echo trans('inventory', 'optional_fields'); ?>:</h6>
                    <ul class="list-unstyled">
                        <li><i class="fas fa-info text-info me-2"></i>description</li>
                        <li><i class="fas fa-info text-info me-2"></i>category_name</li>
                        <li><i class="fas fa-info text-info me-2"></i>quantity (default: 0)</li>
                        <li><i class="fas fa-info text-info me-2"></i>min_quantity (default: 10)</li>
                        <li><i class="fas fa-info text-info me-2"></i>status (active/inactive)</li>
                        <li><i class="fas fa-info text-info me-2"></i>supplier</li>
                        <li><i class="fas fa-info text-info me-2"></i>expiry_date (YYYY-MM-DD format)</li>
                        <li><i class="fas fa-info text-info me-2"></i>created_by</li>
                    </ul>

                    <div class="alert alert-warning mt-3">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong><?php echo trans('inventory', 'import_limit_notice'); ?>:</strong> 
                            <?php 
                            $max_records = defined('DCMT_MAX_IMPORT_RECORDS') ? DCMT_MAX_IMPORT_RECORDS : 500;
                            echo sprintf(trans('inventory', 'max_records_per_import'), $max_records); 
                            ?>
                        </small>
                    </div>
                    
                    <div class="alert alert-info mt-2">
                        <small>
                            <i class="fas fa-info-circle me-1"></i>
                            <?php echo trans('inventory', 'template_note'); ?>
                        </small>
                    </div>

                    <div class="alert alert-warning mt-2">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <strong>Importing Exported Files:</strong> You can import previously exported CSV files. 
                            Records with existing IDs will be skipped automatically.
                        </small>
                    </div>

                    <div class="mt-3">
                        <a href="download_template.php" class="btn btn-outline-primary btn-sm">
                            <i class="fas fa-download me-2"></i><?php echo trans('inventory', 'download_template'); ?>
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
