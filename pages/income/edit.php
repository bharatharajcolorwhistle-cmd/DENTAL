<?php
/**
 * Edit Income Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/income_payment_history.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$income_id = intval($_GET['id'] ?? 0);
if (!$income_id) {
    dcmt_show_message(trans('income', 'invalid_income_id'), 'error');
    dcmt_redirect('index.php');
    exit();
}

// Get income record
$stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_income WHERE dcmt_id = ?");
$stmt->execute([$income_id]);
$income = $stmt->fetch();

if (!$income) {
    dcmt_show_message(trans('income', 'income_not_found'), 'error');
    dcmt_redirect('index.php');
    exit();
}

// Get doctor role users for consultation
$stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_full_name as dcmt_name, dcmt_email, dcmt_qualification, dcmt_specialization_id FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name");
$stmt->execute();
$doctors = $stmt->fetchAll();

// Get all patients for patient name dropdown
$all_patients = [];
try {
    $table_check = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_patients'");
    if ($table_check->rowCount() > 0) {
        $stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_patient_name, dcmt_first_name, dcmt_phone, dcmt_status FROM dcmt_patients ORDER BY dcmt_patient_name");
        $all_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Error fetching patients for income edit form: " . $e->getMessage());
}
$doctor_name_map = [];
foreach ($doctors as $doctor) {
    $doctor_name_map[(int) ($doctor['dcmt_id'] ?? 0)] = $doctor['dcmt_name'] ?? '';
}

$doctors_for_js = array_map(function ($doctor) {
    return [
        'id' => (int) ($doctor['dcmt_id'] ?? 0),
        'name' => $doctor['dcmt_name'] ?? ''
    ];
}, $doctors);

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

// Determine doctor (user) for this income record
$current_doctor_user_id = !empty($income['dcmt_user_id']) ? (int)$income['dcmt_user_id'] : null;

// Get services for the current doctor (if editing a consultation record)
$doctor_services = [];
if ($current_doctor_user_id) {
    $stmt = $dcmt_pdo->prepare("
        SELECT s.dcmt_id, s.dcmt_name, ds.dcmt_price 
        FROM dcmt_services s 
        INNER JOIN dcmt_doctor_services ds ON s.dcmt_id = ds.dcmt_service_id 
        WHERE ds.dcmt_user_id = ? AND s.dcmt_status = 'active' 
        ORDER BY s.dcmt_name
    ");
    $stmt->execute([$current_doctor_user_id]);
    $doctor_services = $stmt->fetchAll();
}

// Get inventory items for product sales
$stmt = $dcmt_pdo->prepare("SELECT i.dcmt_id, i.dcmt_name, i.dcmt_brand, i.dcmt_quantity, i.dcmt_price, c.dcmt_name as category_name, c.dcmt_product_type 
                            FROM dcmt_inventory i 
                            LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id 
                            WHERE i.dcmt_status = 'active' 
                            ORDER BY i.dcmt_name");
$stmt->execute();
$inventory_items = $stmt->fetchAll();
$inventory_name_map = [];
foreach ($inventory_items as $inventoryItem) {
    $inventory_name_map[(int) ($inventoryItem['dcmt_id'] ?? 0)] = $inventoryItem['dcmt_name'] ?? 'Product Item';
}

// Get income payment methods
$stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active' ORDER BY dcmt_name");
$stmt->execute();
$income_payment_methods = $stmt->fetchAll();

// Get default Cash payment method ID
$default_cash_method_id = null;
foreach ($income_payment_methods as $method) {
    if (strtolower($method['dcmt_name']) === 'cash') {
        $default_cash_method_id = $method['dcmt_id'];
        break;
    }
}

// Get income payment statuses
$stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_status WHERE dcmt_status = 'active' ORDER BY dcmt_name");
$stmt->execute();
$income_payment_statuses = $stmt->fetchAll();

// Find completed and pending status IDs
$completed_status_id = null;
$pending_status_id = null;
foreach ($income_payment_statuses as $status) {
    $name_lower = strtolower($status['dcmt_name']);
    if (strpos($name_lower, 'completed') !== false || strpos($name_lower, 'paid') !== false || strpos($name_lower, 'completado') !== false || strpos($name_lower, 'pagado') !== false) {
        $completed_status_id = $status['dcmt_id'];
    }
    if (strpos($name_lower, 'pending') !== false || strpos($name_lower, 'pendiente') !== false) {
        $pending_status_id = $status['dcmt_id'];
    }
}

// Load unified breakdown items
$stmt = $dcmt_pdo->prepare("
    SELECT 
        ib.*,
        s.dcmt_name AS service_name,
        inv.dcmt_name AS product_name,
        c.dcmt_product_type AS inventory_product_type
    FROM dcmt_income_breakdown ib
    LEFT JOIN dcmt_services s ON (ib.dcmt_line_type = 'service' AND ib.dcmt_reference_id = s.dcmt_id)
    LEFT JOIN dcmt_inventory inv ON (ib.dcmt_line_type = 'product' AND ib.dcmt_inventory_id = inv.dcmt_id)
    LEFT JOIN dcmt_inventory_categories c ON inv.dcmt_category_id = c.dcmt_id
    WHERE ib.dcmt_id = ?
    ORDER BY ib.dcmt_line_no ASC
");
$stmt->execute([$income_id]);
$existing_breakdown_items = $stmt->fetchAll();

$existing_service_items = array_values(array_filter($existing_breakdown_items, function ($row) {
    return ($row['dcmt_line_type'] ?? '') === 'service';
}));

$existing_product_items = array_values(array_filter($existing_breakdown_items, function ($row) {
    return ($row['dcmt_line_type'] ?? '') === 'product';
}));

// Ensure quantity formatter is available
if (!function_exists('dcmt_format_quantity_display')) {
    function dcmt_format_quantity_display($quantity) {
        if ($quantity === null || $quantity === '') {
            return '';
        }
        if (!is_numeric($quantity)) {
            return $quantity;
        }
        $formatted = rtrim(rtrim(number_format((float) $quantity, 2, '.', ''), '0'), '.');
        return $formatted === '' ? '0' : $formatted;
    }
}

if (!function_exists('dcmt_build_income_service_summary')) {
    function dcmt_build_income_service_summary(array $rows, array $doctorNameMap = [], array $serviceNameOverrides = []): array {
        $summary = [];
        foreach ($rows as $row) {
            if (($row['dcmt_line_type'] ?? '') !== 'service') {
                continue;
            }
            $serviceId = (int) ($row['dcmt_reference_id'] ?? 0);
            $doctorId = (int) ($row['dcmt_user_id'] ?? 0);
            $key = $serviceId . ':' . $doctorId;
            if (!isset($summary[$key])) {
                $doctorName = $doctorNameMap[$doctorId] ?? ($row['doctor_name'] ?? trans('common', 'not_available'));
                $serviceName = $serviceNameOverrides[$serviceId] ?? ($row['service_name'] ?? $row['dcmt_label'] ?? trans('service', 'service'));
                $summary[$key] = [
                    'service_id' => $serviceId,
                    'doctor_id' => $doctorId,
                    'doctor_name' => $doctorName ?: trans('common', 'not_available'),
                    'service_name' => $serviceName,
                    'quantity' => 0,
                    'total' => 0.0,
                    'unit_price' => 0.0,
                ];
            }
            $summary[$key]['quantity'] += (int) ($row['dcmt_quantity'] ?? 0);
            $summary[$key]['total'] += floatval($row['dcmt_line_total'] ?? 0);
            $summary[$key]['unit_price'] = floatval($row['dcmt_unit_price'] ?? $summary[$key]['unit_price']);
        }
        return $summary;
    }
}

if (!function_exists('dcmt_build_income_product_summary')) {
    function dcmt_build_income_product_summary(array $rows, array $inventoryNameMap = []): array {
        $summary = [];
        foreach ($rows as $row) {
            if (($row['dcmt_line_type'] ?? '') !== 'product') {
                continue;
            }
            $inventoryId = (int) ($row['dcmt_inventory_id'] ?? $row['dcmt_reference_id'] ?? 0);
            $unitPrice = floatval($row['dcmt_unit_price'] ?? 0);
            if ($inventoryId !== 0) {
                $key = (string) $inventoryId;
            } else {
                $labelSeed = $row['product_name'] ?? $row['dcmt_label'] ?? 'custom_product';
                $key = 'custom:' . md5($labelSeed);
            }
            if (!isset($summary[$key])) {
                $productName = $inventoryNameMap[$inventoryId] ?? ($row['product_name'] ?? $row['dcmt_label'] ?? trans('income', 'product'));
                $summary[$key] = [
                    'inventory_id' => $inventoryId,
                    'product_name' => $productName,
                    'quantity' => 0,
                    'total' => 0.0,
                    'unit_price' => 0.0,
                ];
            }
            $summary[$key]['quantity'] += floatval($row['dcmt_quantity'] ?? 0);
            $summary[$key]['total'] += floatval($row['dcmt_line_total'] ?? 0);
            $summary[$key]['unit_price'] = floatval($row['dcmt_unit_price'] ?? $summary[$key]['unit_price']);
        }
        return $summary;
    }
}

if (!function_exists('dcmt_compare_income_service_summary')) {
    function dcmt_compare_income_service_summary(array $previous, array $current, float $previousServiceTotal, float $newServiceTotal, float $previousTotalIncome, float $newTotalIncome, bool $compactSingleAdded = false): array {
        $entries = [];
        $added = [];
        $updated = [];
        $deleted = [];
        
        // Calculate previous and new totals
        $prevTotal = array_reduce($previous, function ($carry, $row) {
            return $carry + (float) ($row['total'] ?? 0);
        }, 0.0);
        $newTotal = array_reduce($current, function ($carry, $row) {
            return $carry + (float) ($row['total'] ?? 0);
        }, 0.0);
        
        $allKeys = array_unique(array_merge(array_keys($previous), array_keys($current)));
        foreach ($allKeys as $key) {
            $old = $previous[$key] ?? null;
            $new = $current[$key] ?? null;
            
            if (!$old && $new) {
                // Service added
                $added[] = [
                    'doctor_name' => $new['doctor_name'] ?? trans('common', 'not_available'),
                    'service_name' => $new['service_name'] ?? trans('service', 'service'),
                    'quantity' => $new['quantity'] ?? 0,
                    'amount' => $new['unit_price'] ?? 0,
                    'total' => $new['total'] ?? 0
                ];
            } elseif ($old && !$new) {
                // Service deleted
                $deleted[] = [
                    'doctor_name' => $old['doctor_name'] ?? trans('common', 'not_available'),
                    'service_name' => $old['service_name'] ?? trans('service', 'service'),
                    'quantity' => $old['quantity'] ?? 0,
                    'unit_price' => $old['unit_price'] ?? 0,
                    'amount' => $old['unit_price'] ?? 0,
                    'total' => $old['total'] ?? 0
                ];
            } elseif ($old && $new) {
                // Check if service was updated
                $serviceNameChanged = ($old['service_name'] ?? '') !== ($new['service_name'] ?? '');
                $doctorChanged = ($old['doctor_name'] ?? '') !== ($new['doctor_name'] ?? '');
                $quantityChanged = ($old['quantity'] ?? 0) !== ($new['quantity'] ?? 0);
                $amountChanged = abs(($old['unit_price'] ?? 0) - ($new['unit_price'] ?? 0)) > 0.01;
                
                if ($serviceNameChanged || $doctorChanged || $quantityChanged || $amountChanged) {
                    $updated[] = [
                        'old' => $old,
                        'new' => $new,
                        'service_name_changed' => $serviceNameChanged,
                        'doctor_changed' => $doctorChanged,
                        'quantity_changed' => $quantityChanged,
                        'amount_changed' => $amountChanged
                    ];
                }
            }
        }
        
        // Check if we have a replacement scenario (1 deleted + 1 added = 1 updated)
        // This happens when a service is replaced with a different service
        if (count($deleted) === 1 && count($added) === 1 && count($updated) === 0) {
            // Treat as a single service update
            $deleted_item = $deleted[0];
            $added_item = $added[0];
            
            $parts = [];
            
            // Service name (always show change since it's a replacement)
            $parts[] = sprintf('%s -> %s', 
                $deleted_item['service_name'] ?? trans('service', 'service'),
                $added_item['service_name'] ?? trans('service', 'service')
            );
            
            // Doctor name (get from deleted item if available, otherwise show change)
            $old_doctor = $deleted_item['doctor_name'] ?? trans('common', 'not_available');
            $new_doctor = $added_item['doctor_name'] ?? trans('common', 'not_available');
            if ($old_doctor !== $new_doctor) {
                $parts[] = sprintf('%s -> %s', $old_doctor, $new_doctor);
            } else {
                $parts[] = $old_doctor;
            }
            
            // Quantity (always show change since it's a replacement)
            $old_qty = $deleted_item['quantity'] ?? 0;
            $new_qty = $added_item['quantity'] ?? 0;
            $parts[] = sprintf('Qty: %s -> %s', 
                dcmt_format_quantity_display($old_qty),
                dcmt_format_quantity_display($new_qty)
            );
            
            // Amount (always show change since it's a replacement)
            $old_amount = $deleted_item['amount'] ?? $deleted_item['unit_price'] ?? 0;
            $new_amount = $added_item['amount'] ?? $added_item['unit_price'] ?? 0;
            $parts[] = sprintf('Amount: %s -> %s', 
                dcmt_format_currency($old_amount),
                dcmt_format_currency($new_amount)
            );
            
            $entry = 'Service Updated. ' . implode(' | ', $parts);
            $entry .= sprintf(' | Service Total: %s -> %s', 
                dcmt_format_currency($previousServiceTotal),
                dcmt_format_currency($newServiceTotal)
            );
            $entries[] = $entry;
        } else {
            // Format entries based on counts
            // 1. Single service added
            if (count($added) === 1 && count($deleted) === 0) {
                $item = $added[0];
                $parts = [];
                if ($compactSingleAdded) {
                    $parts[] = sprintf('Service Added. %s | %s | Amount: %s',
                        $item['doctor_name'],
                        $item['service_name'],
                        dcmt_format_currency($item['amount'])
                    );
                } else {
                    $parts[] = sprintf('Service Added. %s | %s | Qty: %s | Amount: %s',
                        $item['doctor_name'],
                        $item['service_name'],
                        dcmt_format_quantity_display($item['quantity']),
                        dcmt_format_currency($item['amount'])
                    );
                    $parts[] = sprintf('Service Total: %s -> %s',
                        dcmt_format_currency($previousServiceTotal),
                        dcmt_format_currency($newServiceTotal)
                    );
                    $parts[] = sprintf('Total Income: %s -> %s',
                        dcmt_format_currency($previousTotalIncome),
                        dcmt_format_currency($newTotalIncome)
                    );
                }
                $entries[] = implode(' | ', $parts);
            }
            // 2. Multiple services added
            elseif (count($added) > 1) {
                $parts = [];
                $parts[] = sprintf('Services Added: Service Total: %s -> %s', 
                    dcmt_format_currency($previousServiceTotal),
                    dcmt_format_currency($newServiceTotal)
                );
                $parts[] = sprintf('Total Income: %s -> %s', 
                    dcmt_format_currency($previousTotalIncome),
                    dcmt_format_currency($newTotalIncome)
                );
                $entries[] = implode(' | ', $parts);
            }
            
            // 5. Single service deleted
            if (count($deleted) === 1 && count($added) === 0 && count($updated) === 0) {
                $item = $deleted[0];
                $parts = [];
                $parts[] = sprintf('Service Deleted. %s', $item['service_name']);
                $parts[] = sprintf('Service Total: %s -> %s', 
                    dcmt_format_currency($previousServiceTotal),
                    dcmt_format_currency($newServiceTotal)
                );
                $parts[] = sprintf('Total Income: %s -> %s', 
                    dcmt_format_currency($previousTotalIncome),
                    dcmt_format_currency($newTotalIncome)
                );
                $entries[] = implode(' | ', $parts);
            }
            // 6. Multiple services deleted
            elseif (count($deleted) > 1 && count($updated) === 0) {
                $serviceNames = array_map(function($item) {
                    return $item['service_name'];
                }, $deleted);
                $parts = [];
                $parts[] = sprintf('Services Deleted. %s', implode(', ', $serviceNames));
                $parts[] = sprintf('Service Total: %s -> %s', 
                    dcmt_format_currency($previousServiceTotal),
                    dcmt_format_currency($newServiceTotal)
                );
                $parts[] = sprintf('Total Income: %s -> %s', 
                    dcmt_format_currency($previousTotalIncome),
                    dcmt_format_currency($newTotalIncome)
                );
                $entries[] = implode(' | ', $parts);
            }
        }
        
        // 3. Single service updated
        if (count($updated) === 1) {
            $item = $updated[0];
            $old = $item['old'];
            $new = $item['new'];
            $parts = [];
            
            // Always include service name (show same name if not changed, show change if changed)
            $service_name_display = $item['service_name_changed'] 
                ? sprintf('%s -> %s', $old['service_name'], $new['service_name'])
                : $old['service_name'];
            $parts[] = $service_name_display;
            
            // Always include doctor name (show same name if not changed, show change if changed)
            $doctor_name_display = $item['doctor_changed']
                ? sprintf('%s -> %s', $old['doctor_name'], $new['doctor_name'])
                : $old['doctor_name'];
            $parts[] = $doctor_name_display;
            
            // Only include quantity if changed
            if ($item['quantity_changed']) {
                $parts[] = sprintf('Qty: %s -> %s', 
                    dcmt_format_quantity_display($old['quantity']),
                    dcmt_format_quantity_display($new['quantity'])
                );
            }
            
            // Only include amount if changed
            if ($item['amount_changed']) {
                $parts[] = sprintf('Amount: %s -> %s', 
                    dcmt_format_currency($old['unit_price']),
                    dcmt_format_currency($new['unit_price'])
                );
            }
            
            $entry = 'Service Updated. ' . implode(' | ', $parts);
            $entry .= sprintf(' | Service Total: %s -> %s', 
                dcmt_format_currency($previousServiceTotal),
                dcmt_format_currency($newServiceTotal)
            );
            $entry .= sprintf(' | Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
            $entries[] = $entry;
        }
        // 4. Multiple services updated
        elseif (count($updated) > 1) {
            $parts = [];
            $parts[] = sprintf('Services Updated. Service Total: %s -> %s', 
                dcmt_format_currency($previousServiceTotal),
                dcmt_format_currency($newServiceTotal)
            );
            $parts[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
            $entries[] = implode(' | ', $parts);
        }
        
        return $entries;
    }
}

if (!function_exists('dcmt_compare_income_product_summary')) {
    function dcmt_compare_income_product_summary(array $previous, array $current, float $previousProductTotal, float $newProductTotal, float $previousTotalIncome, float $newTotalIncome): array {
        $entries = [];
        $added = [];
        $updated = [];
        $deleted = [];
        
        $allKeys = array_unique(array_merge(array_keys($previous), array_keys($current)));
        foreach ($allKeys as $key) {
            $old = $previous[$key] ?? null;
            $new = $current[$key] ?? null;
            
            if (!$old && $new) {
                // Product added
                $added[] = [
                    'product_name' => $new['product_name'] ?? trans('income', 'product'),
                    'quantity' => $new['quantity'] ?? 0,
                    'amount' => $new['unit_price'] ?? 0,
                    'total' => $new['total'] ?? 0
                ];
            } elseif ($old && !$new) {
                // Product deleted
                $deleted[] = [
                    'product_name' => $old['product_name'] ?? trans('income', 'product'),
                    'quantity' => $old['quantity'] ?? 0,
                    'unit_price' => $old['unit_price'] ?? 0,
                    'amount' => $old['unit_price'] ?? 0,
                    'total' => $old['total'] ?? 0
                ];
            } elseif ($old && $new) {
                // Check if product was updated
                $productNameChanged = ($old['product_name'] ?? '') !== ($new['product_name'] ?? '');
                $quantityChanged = abs(($old['quantity'] ?? 0) - ($new['quantity'] ?? 0)) > 0.0001;
                $amountChanged = abs(($old['unit_price'] ?? 0) - ($new['unit_price'] ?? 0)) > 0.01;
                
                if ($productNameChanged || $quantityChanged || $amountChanged) {
                    $updated[] = [
                        'old' => $old,
                        'new' => $new,
                        'product_name_changed' => $productNameChanged,
                        'quantity_changed' => $quantityChanged,
                        'amount_changed' => $amountChanged
                    ];
                }
            }
        }
        
        // Check if we have a replacement scenario (1 deleted + 1 added = 1 updated)
        // This happens when a product is replaced with a different product
        if (count($deleted) === 1 && count($added) === 1 && count($updated) === 0) {
            // Treat as a single product update
            $deleted_item = $deleted[0];
            $added_item = $added[0];
            
            $parts = [];
            
            // Product name (always show change since it's a replacement)
            $parts[] = sprintf('%s -> %s', 
                $deleted_item['product_name'] ?? trans('income', 'product'),
                $added_item['product_name'] ?? trans('income', 'product')
            );
            
            // Quantity (always show change since it's a replacement)
            $old_qty = $deleted_item['quantity'] ?? 0;
            $new_qty = $added_item['quantity'] ?? 0;
            $parts[] = sprintf('Qty: %s -> %s', 
                dcmt_format_quantity_display($old_qty),
                dcmt_format_quantity_display($new_qty)
            );
            
            // Amount (always show change since it's a replacement)
            $old_amount = $deleted_item['amount'] ?? $deleted_item['unit_price'] ?? 0;
            $new_amount = $added_item['amount'] ?? $added_item['unit_price'] ?? 0;
            $parts[] = sprintf('Amount: %s -> %s', 
                dcmt_format_currency($old_amount),
                dcmt_format_currency($new_amount)
            );
            
            $entry = 'Product Updated. ' . implode(' | ', $parts);
            $entry .= sprintf(' | Product Total: %s -> %s', 
                dcmt_format_currency($previousProductTotal),
                dcmt_format_currency($newProductTotal)
            );
            $entries[] = $entry;
            
            // Add Total Income on a separate line
            $entries[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
        } else {
            // Format entries based on counts
            // 1. Single product added
            if (count($added) === 1 && count($deleted) === 0) {
            $item = $added[0];
            $parts = [];
            $parts[] = sprintf('Product Added. %s | Qty: %s | Amount: %s', 
                $item['product_name'],
                dcmt_format_quantity_display($item['quantity']),
                dcmt_format_currency($item['amount'])
            );
            $parts[] = sprintf('Product Total: %s -> %s', 
                dcmt_format_currency($previousProductTotal),
                dcmt_format_currency($newProductTotal)
            );
            $parts[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
            $entries[] = implode(' | ', $parts);
        }
        // 2. Multiple products added
        elseif (count($added) > 1) {
            $parts = [];
            $parts[] = sprintf('Products Added. Product Total: %s -> %s', 
                dcmt_format_currency($previousProductTotal),
                dcmt_format_currency($newProductTotal)
            );
            $parts[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
            $entries[] = implode(' | ', $parts);
        }
        
        // 3. Single product updated
        if (count($updated) === 1) {
            $item = $updated[0];
            $old = $item['old'];
            $new = $item['new'];
            $parts = [];
            
            // Always include product name (show same name if not changed, show change if changed)
            $product_name_display = $item['product_name_changed'] 
                ? sprintf('%s -> %s', $old['product_name'], $new['product_name'])
                : $old['product_name'];
            $parts[] = $product_name_display;
            
            // Only include quantity if changed
            if ($item['quantity_changed']) {
                $parts[] = sprintf('Qty: %s -> %s', 
                    dcmt_format_quantity_display($old['quantity']),
                    dcmt_format_quantity_display($new['quantity'])
                );
            }
            
            // Only include amount if changed
            if ($item['amount_changed']) {
                $parts[] = sprintf('Amount: %s -> %s', 
                    dcmt_format_currency($old['unit_price']),
                    dcmt_format_currency($new['unit_price'])
                );
            }
            
            $entry = 'Product Updated. ' . implode(' | ', $parts);
            $entry .= sprintf(' | Product Total: %s -> %s', 
                dcmt_format_currency($previousProductTotal),
                dcmt_format_currency($newProductTotal)
            );
            $entries[] = $entry;
            
            // Add Total Income on a separate line
            $entries[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
        }
        // 4. Multiple products updated
        elseif (count($updated) > 1) {
            $parts = [];
            $parts[] = sprintf('Products Updated. Product Total: %s -> %s', 
                dcmt_format_currency($previousProductTotal),
                dcmt_format_currency($newProductTotal)
            );
            $parts[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
            $entries[] = implode(' | ', $parts);
        }
        
        // 5. Single product deleted
        if (count($deleted) === 1 && count($added) === 0 && count($updated) === 0) {
            $item = $deleted[0];
            $parts = [];
            $parts[] = sprintf('Product Deleted. %s', $item['product_name']);
            $parts[] = sprintf('Product Total: %s -> %s', 
                dcmt_format_currency($previousProductTotal),
                dcmt_format_currency($newProductTotal)
            );
            $parts[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
            $entries[] = implode(' | ', $parts);
        }
        // 6. Multiple products deleted
        elseif (count($deleted) > 1 && count($updated) === 0) {
            $productNames = array_map(function($item) {
                return $item['product_name'];
            }, $deleted);
            $parts = [];
            $parts[] = sprintf('Products Deleted. %s', implode(', ', $productNames));
            $parts[] = sprintf('Product Total: %s -> %s', 
                dcmt_format_currency($previousProductTotal),
                dcmt_format_currency($newProductTotal)
            );
            $parts[] = sprintf('Total Income: %s -> %s', 
                dcmt_format_currency($previousTotalIncome),
                dcmt_format_currency($newTotalIncome)
            );
            $entries[] = implode(' | ', $parts);
        }
        }
        
        return $entries;
    }
}

if (!function_exists('dcmt_compare_income_payment_summary')) {
    function dcmt_compare_income_payment_summary(array $previous, array $current, array $paymentMethodMap, float $previousPaymentTotal, float $newPaymentTotal, float $previousTotalIncome, float $newTotalIncome): array {
        $entries = [];
        $added = [];
        $updated = [];
        $deleted = [];
        
        // Build normalized payment arrays for comparison
        $prevNormalized = [];
        foreach ($previous as $payment) {
            $methodId = null;
            if (!empty($payment['dcmt_notes'])) {
                $notesData = json_decode($payment['dcmt_notes'], true);
                if (is_array($notesData) && isset($notesData['payment_method_id'])) {
                    $methodId = (int)$notesData['payment_method_id'];
                }
            }
            $key = $payment['dcmt_id'] ?? uniqid('prev_', true);
            $prevNormalized[$key] = [
                'id' => $payment['dcmt_id'] ?? null,
                'method_id' => $methodId,
                'method_name' => $paymentMethodMap[$methodId] ?? 'N/A',
                'amount' => floatval($payment['dcmt_amount'] ?? 0),
                'paid_on' => $payment['dcmt_paid_on'] ?? '',
            ];
        }
        
        $currNormalized = [];
        foreach ($current as $index => $payment) {
            $methodId = isset($payment['payment_method_id']) ? (int)$payment['payment_method_id'] : null;
            $key = 'new_' . $index;
            $currNormalized[$key] = [
                'id' => null,
                'method_id' => $methodId,
                'method_name' => $paymentMethodMap[$methodId] ?? 'N/A',
                'amount' => floatval($payment['amount'] ?? 0),
                'paid_on' => $payment['paid_on'] ?? '',
            ];
        }
        
        // Simple comparison: match by amount and method (approximate matching)
        // Since payments are deleted and re-inserted, we'll compare totals and individual entries
        $matchedPrev = [];
        $matchedCurr = [];
        
        // First pass: try to match exact matches (same amount and method)
        foreach ($prevNormalized as $prevKey => $prevPayment) {
            $found = false;
            foreach ($currNormalized as $currKey => $currPayment) {
                if (in_array($currKey, $matchedCurr)) {
                    continue;
                }
                // Match by amount and method (within 0.01 tolerance)
                if (abs($prevPayment['amount'] - $currPayment['amount']) < 0.01 && 
                    $prevPayment['method_id'] === $currPayment['method_id']) {
                    $matchedPrev[] = $prevKey;
                    $matchedCurr[] = $currKey;
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $deleted[] = $prevPayment;
            }
        }
        
        // Second pass: match remaining current payments to previous (for updates)
        foreach ($currNormalized as $currKey => $currPayment) {
            if (in_array($currKey, $matchedCurr)) {
                continue;
            }
            $found = false;
            foreach ($prevNormalized as $prevKey => $prevPayment) {
                if (in_array($prevKey, $matchedPrev)) {
                    continue;
                }
                // Match by method only (amount changed) - this is an update
                if ($prevPayment['method_id'] === $currPayment['method_id'] && 
                    abs($prevPayment['amount'] - $currPayment['amount']) > 0.01) {
                    $updated[] = [
                        'old' => $prevPayment,
                        'new' => $currPayment
                    ];
                    $matchedPrev[] = $prevKey;
                    $matchedCurr[] = $currKey;
                    
                    // Remove from deleted array if it was added there (match by method and similar amount)
                    foreach ($deleted as $delIndex => $delItem) {
                        if ($delItem['method_id'] === $prevPayment['method_id'] && 
                            abs($delItem['amount'] - $prevPayment['amount']) < 0.01) {
                            unset($deleted[$delIndex]);
                            break;
                        }
                    }
                    $deleted = array_values($deleted); // Re-index array
                    
                    $found = true;
                    break;
                }
            }
            if (!$found) {
                $added[] = $currPayment;
            }
        }
        
        // Check if we have a replacement scenario (1 deleted + 1 added = 1 updated)
        // This happens when a payment is replaced with a different payment
        if (count($deleted) === 1 && count($added) === 1 && count($updated) === 0) {
            // Treat as a single payment update
            $deleted_item = $deleted[0];
            $added_item = $added[0];
            
            $parts = [];
            
            // Method (always show change since it's a replacement)
            $old_method = $deleted_item['method_name'] ?? 'N/A';
            $new_method = $added_item['method_name'] ?? 'N/A';
            if ($old_method !== $new_method) {
                $parts[] = sprintf('Method: %s -> %s', $old_method, $new_method);
            } else {
                $parts[] = sprintf('Method: %s', $old_method);
            }
            
            // Amount (always show change since it's a replacement)
            $old_amount = $deleted_item['amount'] ?? 0;
            $new_amount = $added_item['amount'] ?? 0;
            $parts[] = sprintf('Amount: %s -> %s', 
                dcmt_format_currency($old_amount),
                dcmt_format_currency($new_amount)
            );
            
            $entry = 'Payment Updated. ' . implode(' | ', $parts);
            $entry .= sprintf(' | Payment Total: %s -> %s', 
                dcmt_format_currency($previousPaymentTotal),
                dcmt_format_currency($newPaymentTotal)
            );
            $entries[] = $entry;
        } else {
            // Format entries based on counts
            // 1. Single payment added
            if (count($added) === 1 && count($deleted) === 0) {
                $item = $added[0];
                $parts = [];
                $parts[] = sprintf('Payment Added. Method: %s | Amount: %s', 
                    $item['method_name'],
                    dcmt_format_currency($item['amount'])
                );
                $parts[] = sprintf('Payment Total: %s -> %s', 
                    dcmt_format_currency($previousPaymentTotal),
                    dcmt_format_currency($newPaymentTotal)
                );
                $parts[] = sprintf('Total Income: %s -> %s', 
                    dcmt_format_currency($previousTotalIncome),
                    dcmt_format_currency($newTotalIncome)
                );
                $entries[] = implode(' | ', $parts);
            }
        // 2. Multiple payments added
        elseif (count($added) > 1) {
            $parts = [];
            $parts[] = sprintf('Payments Added. Payment Total: %s -> %s', 
                dcmt_format_currency($previousPaymentTotal),
                dcmt_format_currency($newPaymentTotal)
            );
            $entries[] = implode(' | ', $parts);
        }
        
        // 3. Single payment updated
        if (count($updated) === 1) {
            $item = $updated[0];
            $old = $item['old'];
            $new = $item['new'];
            $parts = [];
            $parts[] = sprintf('Payment Updated. Method: %s | Amount: %s -> %s', 
                $new['method_name'],
                dcmt_format_currency($old['amount']),
                dcmt_format_currency($new['amount'])
            );
            $parts[] = sprintf('Payment Total: %s -> %s', 
                dcmt_format_currency($previousPaymentTotal),
                dcmt_format_currency($newPaymentTotal)
            );
            $entries[] = implode(' | ', $parts);
        }
        // 4. Multiple payments updated
        elseif (count($updated) > 1) {
            $parts = [];
            $parts[] = sprintf('Payments Updated. Payment Total: %s -> %s', 
                dcmt_format_currency($previousPaymentTotal),
                dcmt_format_currency($newPaymentTotal)
            );
            $entries[] = implode(' | ', $parts);
        }
        
            // 5. Single payment deleted
            if (count($deleted) === 1 && count($added) === 0 && count($updated) === 0) {
                $item = $deleted[0];
                $parts = [];
                $parts[] = sprintf('Payment Deleted. Amount: %s', 
                    dcmt_format_currency($item['amount'])
                );
                $parts[] = sprintf('Payment Total: %s -> %s', 
                    dcmt_format_currency($previousPaymentTotal),
                    dcmt_format_currency($newPaymentTotal)
                );
                $entries[] = implode(' | ', $parts);
            }
            // 6. Multiple payments deleted
            elseif (count($deleted) > 1) {
                $parts = [];
                $parts[] = sprintf('Payments Deleted. Payment Total: %s -> %s', 
                    dcmt_format_currency($previousPaymentTotal),
                    dcmt_format_currency($newPaymentTotal)
                );
                $entries[] = implode(' | ', $parts);
            }
        }
        
        return $entries;
    }
}

$posted_service_items = $_POST['service_items'] ?? null;
$service_items_view = [];

if (is_array($posted_service_items)) {
    foreach ($posted_service_items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $quantityValue = isset($item['quantity']) ? (string)$item['quantity'] : '1';
        $amountValue = isset($item['amount']) ? (string)$item['amount'] : '';
        $totalValue = '';
        if ($quantityValue !== '' && $amountValue !== '') {
            $totalValue = number_format((float)$quantityValue * (float)$amountValue, 2, '.', '');
        }
        $service_items_view[] = [
            'doctor_id' => isset($item['doctor_id']) && $item['doctor_id'] !== '' ? (int)$item['doctor_id'] : null,
            'service_id' => isset($item['service_id']) && $item['service_id'] !== '' ? (int)$item['service_id'] : null,
            'quantity' => $quantityValue,
            'amount' => $amountValue,
            'total' => $totalValue,
            'service_name' => $item['service_name'] ?? null,
        ];
    }
} elseif (!empty($existing_service_items)) {
    foreach ($existing_service_items as $item) {
        $quantityValue = dcmt_format_quantity_display($item['dcmt_quantity'] ?? 1);
        $amountValue = isset($item['dcmt_unit_price']) ? number_format((float)$item['dcmt_unit_price'], 2, '.', '') : '';
        $totalValue = isset($item['dcmt_line_total']) ? number_format((float)$item['dcmt_line_total'], 2, '.', '') : '';
        // Use stored user_id for display
        $item_user_id = isset($item['dcmt_user_id']) ? (int)$item['dcmt_user_id'] : null;
        
        $service_items_view[] = [
            'doctor_id' => $item_user_id, // This is actually user_id for form display
            'service_id' => isset($item['dcmt_reference_id']) ? (int)$item['dcmt_reference_id'] : null,
            'quantity' => (string)$quantityValue,
            'amount' => $amountValue,
            'total' => $totalValue,
            'service_name' => $item['service_name'] ?? null,
        ];
    }
} elseif ($income['dcmt_type'] === 'consultation' && !empty($income['dcmt_service_id'])) {
    $amountValue = isset($income['dcmt_service_amount']) ? number_format((float)$income['dcmt_service_amount'], 2, '.', '') : '';
    // Get user_id for display
    $legacy_user_id = !empty($income['dcmt_user_id']) ? (int)$income['dcmt_user_id'] : null;
    
    $service_items_view[] = [
        'doctor_id' => $legacy_user_id, // This is actually user_id for form display
        'service_id' => (int)$income['dcmt_service_id'],
        'quantity' => '1',
        'amount' => $amountValue,
        'total' => $amountValue,
        'service_name' => $income['service_name'] ?? null,
    ];
}

// Don't create empty placeholder - only show service items if they exist in stored records
// Removed: if (empty($service_items_view)) { ... }

// Check if there are any stored service records (with actual service_id)
$has_stored_service_records = false;
foreach ($service_items_view as $item) {
    if (!empty($item['service_id'])) {
        $has_stored_service_records = true;
        break;
    }
}

// service_items_view already has user_id in doctor_id field (from above conversion)

$first_service_item_view = $service_items_view[0] ?? [];

$service_items_for_js = [];
foreach ($service_items_view as $item) {
    $service_items_for_js[] = [
        'doctor_id' => $item['doctor_id'],
        'service_id' => $item['service_id'],
        'quantity' => $item['quantity'] ?? '1',
        'amount' => $item['amount'] ?? '',
        'service_name' => $item['service_name'] ?? null,
    ];
}
$initial_service_items_for_js = $service_items_for_js;

$posted_product_items = $_POST['product_items'] ?? null;
$product_items_view = [];
$initial_product_items_for_js = [];

if (is_array($posted_product_items)) {
    foreach ($posted_product_items as $item) {
        if (!is_array($item)) {
            continue;
        }
        $quantityValue = isset($item['quantity']) ? (string) $item['quantity'] : '';
        $unitPriceValue = isset($item['unit_price']) ? (string) $item['unit_price'] : '';
        $totalValue = '';
        if ($quantityValue !== '' && $unitPriceValue !== '') {
            $totalValue = number_format((float)str_replace(',', '', $quantityValue) * (float)$unitPriceValue, 2, '.', '');
        }
        $product_items_view[] = [
            'inventory_id' => isset($item['inventory_id']) && $item['inventory_id'] !== '' ? (int) $item['inventory_id'] : null,
            'quantity' => $quantityValue,
            'unit_price' => $unitPriceValue,
            'total' => $totalValue,
            'product_type' => isset($item['product_type']) ? (string) $item['product_type'] : '',
            'inventory_name' => null,
            'inventory_missing' => false,
            'inventory_stock' => null,
        ];
        $initial_product_items_for_js[] = [
            'inventory_id' => isset($item['inventory_id']) && $item['inventory_id'] !== '' ? (int) $item['inventory_id'] : null,
            'quantity' => $quantityValue,
            'unit_price' => $unitPriceValue,
            'product_type' => isset($item['product_type']) ? (string) $item['product_type'] : '',
            'inventory_name' => null,
            'inventory_missing' => false,
            'inventory_stock' => null,
        ];
    }
} elseif (!empty($existing_product_items)) {
    foreach ($existing_product_items as $item) {
        $metadata = [];
        if (!empty($item['dcmt_metadata'])) {
            $decodedMetadata = json_decode($item['dcmt_metadata'], true);
            if (is_array($decodedMetadata)) {
                $metadata = $decodedMetadata;
            }
        }

        $inventoryName = $item['product_name']
            ?? ($metadata['inventory_name'] ?? ($item['dcmt_label'] ?? ''));

        $inventoryMissing = empty($item['product_name']);
        $quantityValue = isset($item['dcmt_quantity']) ? dcmt_format_quantity_display($item['dcmt_quantity']) : '';
        $unitPriceValue = isset($item['dcmt_unit_price']) ? number_format((float) $item['dcmt_unit_price'], 2, '.', '') : '';
        $totalValue = isset($item['dcmt_line_total']) ? number_format((float) $item['dcmt_line_total'], 2, '.', '') : '';

        $product_items_view[] = [
            'inventory_id' => isset($item['dcmt_inventory_id']) ? (int) $item['dcmt_inventory_id'] : null,
            'quantity' => (string) $quantityValue,
            'unit_price' => $unitPriceValue,
            'total' => $totalValue,
            'product_type' => $metadata['product_type'] ?? $item['inventory_product_type'] ?? 'for_sale',
            'inventory_name' => $inventoryName !== '' ? $inventoryName : null,
            'inventory_missing' => $inventoryMissing,
            'inventory_stock' => $metadata['inventory_stock'] ?? null,
        ];
        $initial_product_items_for_js[] = [
            'inventory_id' => isset($item['dcmt_inventory_id']) ? (int) $item['dcmt_inventory_id'] : null,
            'quantity' => (string) $quantityValue,
            'unit_price' => $unitPriceValue,
            'product_type' => $metadata['product_type'] ?? $item['inventory_product_type'] ?? 'for_sale',
            'inventory_name' => $inventoryName !== '' ? $inventoryName : null,
            'inventory_missing' => $inventoryMissing,
            'inventory_stock' => $metadata['inventory_stock'] ?? null,
        ];
    }
}

$first_product_item_view = $product_items_view[0] ?? [];
$has_initial_product_items = false;
foreach ($initial_product_items_for_js as $productItem) {
    if (!empty($productItem['inventory_id']) || ($productItem['quantity'] ?? '') !== '' || ($productItem['unit_price'] ?? '') !== '') {
        $has_initial_product_items = true;
        break;
    }
}

// Initialize note items
$posted_note_items = $_POST['note_items'] ?? [];
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
} else {
    // Try to parse existing note from income record
    $existing_note = isset($_POST['note']) ? trim($_POST['note']) : ($income['dcmt_note'] ?? '');
    if (!empty($existing_note)) {
        // Split by double newline (as we save them)
        $note_parts = explode("\n\n", $existing_note);
        foreach ($note_parts as $note_part) {
            $note_part = trim($note_part);
            if (empty($note_part)) {
                continue;
            }
            // Try to parse "Topic: Note text" format
            if (strpos($note_part, ': ') !== false) {
                $parts = explode(': ', $note_part, 2);
                if (count($parts) === 2) {
                    $initial_note_items_for_js[] = [
                        'topic' => trim($parts[0]),
                        'note_text' => trim($parts[1])
                    ];
                } else {
                    $initial_note_items_for_js[] = [
                        'topic' => '',
                        'note_text' => $note_part
                    ];
                }
            } else {
                $initial_note_items_for_js[] = [
                    'topic' => '',
                    'note_text' => $note_part
                ];
            }
        }
    }
}

$existing_payment_entries = [];
$stmt = $dcmt_pdo->prepare("
    SELECT iph.*, ru.dcmt_full_name as recorded_by_name
    FROM dcmt_income_payment_history iph
    LEFT JOIN dcmt_users ru ON iph.dcmt_recorded_by COLLATE utf8mb4_general_ci = ru.dcmt_username
    WHERE iph.dcmt_income_id = ?
    ORDER BY iph.dcmt_paid_on ASC, iph.dcmt_id ASC
");
$stmt->execute([$income_id]);
$existing_payment_entries = $stmt->fetchAll();

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

if (!function_exists('dcmt_insert_income_payment_entries')) {
    function dcmt_insert_income_payment_entries(PDO $pdo, int $incomeId, string $paymentType, array $entries, string $recordedBy): void {
        if (empty($entries)) {
            return;
        }

        foreach ($entries as $entry) {
            $amount = isset($entry['amount']) ? floatval($entry['amount']) : 0.00;
            $paidOn = isset($entry['paid_on']) ? dcmt_sanitize_input($entry['paid_on']) : '';
            $methodId = isset($entry['payment_method_id']) && $entry['payment_method_id'] !== null ? (int) $entry['payment_method_id'] : null;
            $entryRecordedBy = isset($entry['recorded_by']) && trim((string) $entry['recorded_by']) !== ''
                ? trim((string) $entry['recorded_by'])
                : $recordedBy;
            $entryCreatedAt = isset($entry['created_at']) && trim((string) $entry['created_at']) !== ''
                ? trim((string) $entry['created_at'])
                : null;
            dcmt_add_payment_history_entry($pdo, $incomeId, $paymentType, $amount, $paidOn, $entryRecordedBy, $methodId, $entryCreatedAt);
        }
    }
}

if (!function_exists('dcmt_extract_payment_method_from_notes')) {
    function dcmt_extract_payment_method_from_notes($notes): ?int {
        if ($notes === null || $notes === '') {
            return null;
        }
        $decoded = json_decode((string) $notes, true);
        if (!is_array($decoded) || !isset($decoded['payment_method_id']) || $decoded['payment_method_id'] === '') {
            return null;
        }
        return (int) $decoded['payment_method_id'];
    }
}

if (!function_exists('dcmt_build_payment_signature')) {
    function dcmt_build_payment_signature(float $amount, string $paidOn, ?int $methodId): string {
        $dateKey = trim(substr($paidOn, 0, 10));
        $methodKey = $methodId === null ? 'null' : (string) $methodId;
        return number_format(round($amount, 2), 2, '.', '') . '|' . $dateKey . '|' . $methodKey;
    }
}

if (!function_exists('dcmt_assign_recorded_by_for_payment_entries')) {
    function dcmt_assign_recorded_by_for_payment_entries(array $submittedEntries, array $existingEntries, string $fallbackRecordedBy): array {
        if (empty($submittedEntries)) {
            return $submittedEntries;
        }

        $existingBuckets = [];
        foreach ($existingEntries as $existingRow) {
            $amount = isset($existingRow['dcmt_amount']) ? floatval($existingRow['dcmt_amount']) : 0.0;
            $paidOn = isset($existingRow['dcmt_paid_on']) ? (string) $existingRow['dcmt_paid_on'] : '';
            $methodId = dcmt_extract_payment_method_from_notes($existingRow['dcmt_notes'] ?? null);
            $signature = dcmt_build_payment_signature($amount, $paidOn, $methodId);
            if (!isset($existingBuckets[$signature])) {
                $existingBuckets[$signature] = [];
            }
            $existingBuckets[$signature][] = [
                'recorded_by' => isset($existingRow['dcmt_recorded_by']) && trim((string) $existingRow['dcmt_recorded_by']) !== ''
                    ? trim((string) $existingRow['dcmt_recorded_by'])
                    : $fallbackRecordedBy,
                'created_at' => isset($existingRow['dcmt_created_at']) && trim((string) $existingRow['dcmt_created_at']) !== ''
                    ? trim((string) $existingRow['dcmt_created_at'])
                    : null,
            ];
        }

        foreach ($submittedEntries as &$entry) {
            $amount = isset($entry['amount']) ? floatval($entry['amount']) : 0.0;
            $paidOn = isset($entry['paid_on']) ? (string) $entry['paid_on'] : '';
            $methodId = isset($entry['payment_method_id']) && $entry['payment_method_id'] !== null ? (int) $entry['payment_method_id'] : null;
            $signature = dcmt_build_payment_signature($amount, $paidOn, $methodId);

            if (isset($existingBuckets[$signature]) && !empty($existingBuckets[$signature])) {
                $matched = array_shift($existingBuckets[$signature]);
                $entry['recorded_by'] = $matched['recorded_by'] ?? $fallbackRecordedBy;
                $entry['created_at'] = $matched['created_at'] ?? null;
            } else {
                $entry['recorded_by'] = $fallbackRecordedBy;
                $entry['created_at'] = null;
            }
        }
        unset($entry);

        return $submittedEntries;
    }
}

// Get stored service amount for consultation type
$service_amount = 0;
if ($income['dcmt_type'] === 'consultation') {
    // Always use the stored service amount from the database if it exists
    // This ensures we display the actual stored value, not a calculated one
    if ($income['dcmt_service_amount'] !== null) {
        $service_amount = $income['dcmt_service_amount'];
    } else {
        // Only calculate from doctor's service price if no stored service amount exists (for very old records)
        $service_amount = 0;
        if (!empty($income['dcmt_service_id'])) {
            $service_user_id = !empty($income['dcmt_user_id']) ? $income['dcmt_user_id'] : $current_doctor_user_id;
            if ($service_user_id) {
            $stmt = $dcmt_pdo->prepare("
                SELECT ds.dcmt_price 
                FROM dcmt_doctor_services ds 
                    WHERE ds.dcmt_user_id = ? AND ds.dcmt_service_id = ?
            ");
                $stmt->execute([$service_user_id, $income['dcmt_service_id']]);
            $service_data = $stmt->fetch();
            } else {
                $service_data = false;
            }
            if ($service_data) {
                $service_amount = $service_data['dcmt_price'];
            }
        }
    }
}

// Default paid/pending amounts when loading existing record (before POST overrides)
$service_paid_amount = (float) ($income['dcmt_service_paid_amount'] ?? 0);
$service_pending_amount = (float) ($income['dcmt_service_pending_amount'] ?? max($service_amount - $service_paid_amount, 0));

$product_amount = (float) ($income['dcmt_product_amount'] ?? (($income['dcmt_type'] ?? '') === 'product_sale' ? ($income['dcmt_amount'] ?? 0) : 0));
$product_paid_amount = (float) ($income['dcmt_product_paid_amount'] ?? (
    (($income['dcmt_type'] ?? '') === 'product_sale') ? ($income['dcmt_total_paid_amount'] ?? $income['dcmt_paid_amount'] ?? 0) : 0
));
$product_pending_amount = (float) ($income['dcmt_product_pending_amount'] ?? max($product_amount - $product_paid_amount, 0));

$errors = [];
$success = false;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!dcmt_verify_csrf_token($csrf_token)) {
        $errors[] = 'Invalid security token. Please try again.';
    } else {
        // Get form data
        $patient_name = dcmt_sanitize_input($_POST['patient_name'] ?? '');
        $patient_id = !empty($_POST['patient_id']) ? (int)$_POST['patient_id'] : null;
        $type = dcmt_sanitize_input($_POST['type'] ?? '');
        $description = dcmt_sanitize_input($_POST['description'] ?? '');
        $note = dcmt_sanitize_input($_POST['note'] ?? '');
        $amount = floatval($_POST['amount'] ?? 0);
        
        // Get payment amounts based on income type
        $service_amount = floatval($_POST['service_amount'] ?? 0);
        $service_paid_amount = floatval($_POST['service_paid_amount'] ?? 0);
        $service_pending_amount = floatval($_POST['service_pending_amount'] ?? 0);
        $product_amount = ($type === 'product_sale') ? floatval($_POST['product_amount'] ?? 0) : 0.00;
        // Product paid amount will be calculated later via proportional split logic
        // For now, get initial value from POST (will be recalculated later)
        $product_paid_amount = floatval($_POST['product_paid_amount'] ?? 0);
        $product_pending_amount = floatval($_POST['product_pending_amount'] ?? 0);
        $total_paid_amount = floatval($_POST['total_paid_amount'] ?? 0);
        $total_pending_amount = floatval($_POST['total_pending_amount'] ?? 0);
        
        $payment_method_id = !empty($_POST['payment_method_id']) ? intval($_POST['payment_method_id']) : null;
        $payment_status_id = !empty($_POST['payment_status_id']) ? intval($_POST['payment_status_id']) : null;
        $transaction_date = dcmt_sanitize_input($_POST['transaction_date'] ?? '');
        // Get user_id from form (now using doctor role users directly)
        $doctor_user_id = !empty($_POST['doctor_id']) ? intval($_POST['doctor_id']) : null;
        $doctor_user_id_from_service_items = false;

        if ($payment_method_id === null && isset($income['dcmt_payment_method_id']) && $income['dcmt_payment_method_id'] !== '') {
            $payment_method_id = (int) $income['dcmt_payment_method_id'];
        }
        if ($payment_status_id === null && isset($income['dcmt_payment_status_id']) && $income['dcmt_payment_status_id'] !== '') {
            $payment_status_id = (int) $income['dcmt_payment_status_id'];
        }
        if ($doctor_user_id === null && !empty($income['dcmt_user_id'])) {
            $doctor_user_id = (int) $income['dcmt_user_id'];
        }
        $current_user = dcmt_get_current_user();
        $recorded_by_username = is_array($current_user) && !empty($current_user['dcmt_username'])
            ? $current_user['dcmt_username']
            : 'system';
        $income_payment_entries = [];
        $income_payments_posted = $_POST['income_payments'] ?? [];
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
                $income_payment_entries[] = [
                    'amount' => $amountValue,
                    'paid_on' => $paidOnValue,
                    'payment_method_id' => $methodValue
                ];
            }
        }
        
        if (!empty($income_payment_entries)) {
            foreach ($income_payment_entries as &$paymentRow) {
                if ($paymentRow['payment_method_id'] === null && !empty($payment_method_id)) {
                    $paymentRow['payment_method_id'] = (int) $payment_method_id;
                }
            }
            unset($paymentRow);
            
            $original_payment_method_id = isset($income['dcmt_payment_method_id']) ? (int) $income['dcmt_payment_method_id'] : null;
            if ($original_payment_method_id !== null && !empty($payment_method_id) && (int) $payment_method_id !== $original_payment_method_id) {
                $allRowsUseOriginalMethod = true;
                foreach ($income_payment_entries as $entry) {
                    if (!isset($entry['payment_method_id']) || (int) $entry['payment_method_id'] !== $original_payment_method_id) {
                        $allRowsUseOriginalMethod = false;
                        break;
                    }
                }
                if ($allRowsUseOriginalMethod) {
                    foreach ($income_payment_entries as &$entry) {
                        $entry['payment_method_id'] = (int) $payment_method_id;
                    }
                    unset($entry);
                }
            }
        }

        if (!empty($income_payment_entries)) {
            $income_payment_entries = dcmt_assign_recorded_by_for_payment_entries(
                $income_payment_entries,
                $existing_payment_entries,
                $recorded_by_username
            );
        }
        
        // Handle service items for consultation type
        $service_items = $_POST['service_items'] ?? [];
        $service_id = null; // Will be set to first service_id for backward compatibility
        $service_amount = 0.00;
        $valid_service_items = [];
        
        if ($type === 'consultation' && !empty($service_items)) {
            // Filter out empty service items
            foreach ($service_items as $item) {
                // Check if item has all required fields and they are not empty
                $has_doctor_id = isset($item['doctor_id']) && $item['doctor_id'] !== '' && $item['doctor_id'] !== null;
                $has_service_id = isset($item['service_id']) && $item['service_id'] !== '' && $item['service_id'] !== null;
                $has_quantity = isset($item['quantity']) && $item['quantity'] !== '' && floatval($item['quantity']) > 0;
                $has_amount = isset($item['amount']) && $item['amount'] !== '' && floatval($item['amount']) >= 0;
                
                if ($has_doctor_id && $has_service_id && $has_quantity && $has_amount) {
                    $normalizedQuantity = (int) round(floatval(str_replace(',', '', $item['quantity'])));
                    if ($normalizedQuantity <= 0) {
                        $normalizedQuantity = 1;
                    }
                    $item['quantity'] = $normalizedQuantity;
                    $normalizedDoctorUserId = intval($item['doctor_id']); // This is a user_id
                    $item['service_id'] = intval($item['service_id']);
                    
                    $item['doctor_id'] = $normalizedDoctorUserId; // Store user_id directly
                    $valid_service_items[] = $item;
                    
                    if ($doctor_user_id === null) {
                        $doctor_user_id = $normalizedDoctorUserId;
                        $doctor_user_id_from_service_items = true;
                    }
                }
            }
            
            // Calculate total service amount from all service items
            foreach ($valid_service_items as $item) {
                $quantity = (int) $item['quantity'];
                $item_amount = floatval($item['amount']);
                $service_amount += $quantity * $item_amount;
                
                // Set service_id to first service for backward compatibility
                if ($service_id === null) {
                    $service_id = intval($item['service_id']);
                }
            }
        } else {
            // Fallback to old service_id field if service_items not provided
            $service_id = !empty($_POST['service_id']) ? intval($_POST['service_id']) : null;
            $service_amount = floatval($_POST['service_amount'] ?? 0);
        }
        
        // Validate required fields based on income type
        // Note: payment_method_id is not required at top level as each payment entry has its own payment_method_id
        $required_fields = ['patient_name', 'type', 'amount', 'payment_status_id', 'transaction_date'];
        
        // Validate that at least one payment entry has a payment method if payments exist
        if (!empty($income_payment_entries)) {
            $has_payment_method = false;
            foreach ($income_payment_entries as $paymentRow) {
                if (!empty($paymentRow['payment_method_id'])) {
                    $has_payment_method = true;
                    break;
                }
            }
            if (!$has_payment_method && empty($payment_method_id)) {
                $errors[] = trans('income', 'payment_method_required') ?: 'At least one payment entry must have a payment method.';
            }
        }
        
        // Add type-specific required fields
        if ($type === 'consultation') {
            // Validate service items are provided
            if (empty($service_items) || empty($valid_service_items)) {
                $errors[] = trans('income', 'service_items_required');
            }
        } elseif ($type === 'product_sale') {
            $required_fields[] = 'product_paid_amount';
        }
        
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if (!$validation_result['valid']) {
            $errors = array_merge($errors, $validation_result['errors']);
        }
        
        // Calculate product amount from product items if any (before validation)
        $product_items = $_POST['product_items'] ?? [];
        $calculated_product_amount = 0.00;
        if (!empty($product_items)) {
            foreach ($product_items as $item) {
                // Only include items that have all fields filled
                if (!empty($item['inventory_id']) && !empty($item['quantity']) && !empty($item['unit_price'])) {
                    $calculated_product_amount += floatval($item['quantity']) * floatval($item['unit_price']);
                }
            }
        }
        
        // For consultation, always recalculate amount from service_amount + product_amount to ensure accuracy
        // This ensures the amount matches what was calculated from the items
        if ($type === 'consultation') {
            $calculated_total_amount = $service_amount + $calculated_product_amount;
            // Always use the calculated amount (more accurate than submitted value)
            $amount = $calculated_total_amount;
            // Also update product_amount if it was calculated
            if ($calculated_product_amount > 0) {
                $product_amount = $calculated_product_amount;
            }
            // Recalculate total_pending_amount to match the recalculated amount
            $total_pending_amount = $amount - $total_paid_amount;
        } else {
            // For product_sale, use the submitted amount or calculate from product items
            if ($amount == 0 && $calculated_product_amount > 0) {
                $amount = $calculated_product_amount;
                $product_amount = $calculated_product_amount;
            }
        }

        $payments_total = 0.0;
        foreach ($income_payment_entries as $paymentRow) {
            $payments_total += isset($paymentRow['amount']) ? floatval($paymentRow['amount']) : 0.0;
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
                
                // Note text is required if topic is provided
                if (!empty($note_topic) && empty($note_text)) {
                    $errors[] = trans('patient_note', 'note_text_required') . ' (Note ' . ($index + 1) . ')';
                    break;
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
            // Fallback to existing note field if no note items
            $note = isset($_POST['note']) ? trim($_POST['note']) : '';
        }
        
        $_POST['service_amount'] = number_format($service_amount, 2, '.', '');
        $_POST['product_amount'] = number_format($product_amount, 2, '.', '');
        $_POST['service_paid_amount'] = number_format($service_paid_amount, 2, '.', '');
        $_POST['product_paid_amount'] = number_format($product_paid_amount, 2, '.', '');
        $_POST['service_pending_amount'] = number_format($service_pending_amount, 2, '.', '');
        $_POST['product_pending_amount'] = number_format($product_pending_amount, 2, '.', '');
        $_POST['total_paid_amount'] = number_format($total_paid_amount, 2, '.', '');
        $_POST['total_pending_amount'] = number_format($total_pending_amount, 2, '.', '');
        $_POST['amount'] = number_format($amount, 2, '.', '');
        
        // Validate amount - REMOVED: Amount must be greater than zero validation
        
        // Validate product paid amount (only for product_sale type)
        if ($type === 'product_sale' && $product_paid_amount < 0) {
            $errors[] = 'Product paid amount cannot be negative.';
        }
        
        // Validate total paid amount
        $calculated_total_paid = $service_paid_amount + $product_paid_amount;
        if (abs($total_paid_amount - $calculated_total_paid) > 0.01) {
            $errors[] = 'Total paid amount calculation mismatch.';
        }
        
        // Validate total pending amount (with increased tolerance for floating point precision)
        $calculated_total_pending = $amount - $total_paid_amount;
        if (abs($total_pending_amount - $calculated_total_pending) > 0.02) {
            error_log("Total pending amount mismatch - Submitted: $total_pending_amount, Calculated: $calculated_total_pending, Amount: $amount, Total Paid: $total_paid_amount");
            $errors[] = 'Total pending amount calculation mismatch.';
        }
        
        // Validate that total paid doesn't exceed total amount
        if ($total_paid_amount > $amount) {
            $errors[] = 'Total paid amount cannot be greater than total amount.';
        }
        
        // Validate date
        $date_error = dcmt_validate_date_field($transaction_date, 'transaction_date');
        if ($date_error) {
            $errors[] = $date_error;
        }

        // Enforce: cannot save Pending status when total pending is zero
        if ($payment_status_id !== null && isset($pending_status_id) && $payment_status_id == $pending_status_id) {
            // Check total_pending_amount instead of component amounts
            // This handles all cases: consultation, product_sale, and mixed
            if (floatval($total_pending_amount) <= 0.0) {
                $errors[] = trans('income', 'cannot_change_completed_to_pending_zero');
            }
        }
        
        // Type-specific validation
        if ($type === 'consultation') {
            // Validate service items
            if (!empty($service_items)) {
                foreach ($service_items as $index => $item) {
                    // Skip completely empty items (all fields empty)
                    $all_empty = (empty($item['doctor_id']) || $item['doctor_id'] === '') &&
                                 (empty($item['service_id']) || $item['service_id'] === '') && 
                                 (empty($item['quantity']) || $item['quantity'] === '') && 
                                 (empty($item['amount']) || $item['amount'] === '');
                    
                    if ($all_empty) {
                        continue; // Skip empty items
                    }
                    
                    // If any field is filled, all must be filled and valid
                    if (empty($item['service_id']) || $item['service_id'] === '') {
                        $errors[] = trans('income', 'service_required') . ' (Item ' . ($index + 1) . ')';
                        break;
                    }
                    
                    if (empty($item['quantity']) || floatval($item['quantity']) <= 0) {
                        $errors[] = trans('income', 'service_quantity_required') . ' (Item ' . ($index + 1) . ')';
                        break;
                    }
                    
                    if (empty($item['amount']) || floatval($item['amount']) < 0) {
                        $errors[] = trans('income', 'service_amount_required') . ' (Item ' . ($index + 1) . ')';
                        break;
                    }
                }
            }
            
            // Validate service amount is not negative
            if ($service_amount < 0) {
                $errors[] = 'Service amount cannot be negative.';
            }
            
            // For consultation, product items are optional
            
            // Calculate total from product items if any
            $product_total = 0;
            if (!empty($product_items)) {
                // Filter out empty product items (where all fields are empty)
                $valid_product_items = [];
                foreach ($product_items as $item) {
                    // Only include items that have at least one field filled
                    if (!empty($item['inventory_id']) || !empty($item['quantity']) || !empty($item['unit_price'])) {
                        $valid_product_items[] = $item;
                    }
                }
                
                // Only validate if there are valid product items
                if (!empty($valid_product_items)) {
                    foreach ($valid_product_items as $item) {
                        // REMOVED: All product item fields are required validation
                        
                        if (floatval($item['quantity']) <= 0) {
                            $errors[] = trans('income', 'product_quantity_required');
                            break;
                        }
                        
                        // REMOVED: Product unit price must be greater than zero validation
                        
                        // Check stock availability (considering current quantities will be restored)
                        $stmt = $dcmt_pdo->prepare("SELECT dcmt_quantity FROM dcmt_inventory WHERE dcmt_id = ?");
                        $stmt->execute([$item['inventory_id']]);
                        $available_stock = $stmt->fetch()['dcmt_quantity'] ?? 0;
                        
                        // Get current quantity from existing income items for this inventory item
                        $stmt = $dcmt_pdo->prepare("
                            SELECT COALESCE(SUM(dcmt_quantity), 0) as current_quantity 
                            FROM dcmt_income_breakdown 
                            WHERE dcmt_id = ? AND dcmt_line_type = 'product' AND dcmt_inventory_id = ?
                        ");
                        $stmt->execute([$income_id, $item['inventory_id']]);
                        $current_quantity = $stmt->fetch()['current_quantity'] ?? 0;
                        
                        // Available stock after restoring current quantities
                        $effective_stock = $available_stock + $current_quantity;
                        
                        if (floatval($item['quantity']) > $effective_stock) {
                            $errors[] = trans('income', 'insufficient_stock') . ': ' . $effective_stock;
                            break;
                        }
                        
                        $product_total += floatval($item['quantity']) * floatval($item['unit_price']);
                    }
                }
            }
            
            // For consultation, amount validation is skipped as user enters it manually
        } elseif ($type === 'product_sale') {
            // Validate product sale items - these are required
            $product_items = $_POST['product_items'] ?? [];
            if (empty($product_items)) {
                $errors[] = trans('income', 'product_items_required');
            } else {
                // Validate each product item
                foreach ($product_items as $item) {
                    // REMOVED: All product item fields are required validation
                    
                    if ($item['quantity'] <= 0) {
                        $errors[] = trans('income', 'product_quantity_required');
                        break;
                    }
                    
                    // REMOVED: Product unit price must be greater than zero validation
                    
                    // Check stock availability (considering current quantities will be restored)
                    $stmt = $dcmt_pdo->prepare("SELECT dcmt_quantity FROM dcmt_inventory WHERE dcmt_id = ?");
                    $stmt->execute([$item['inventory_id']]);
                    $available_stock = $stmt->fetch()['dcmt_quantity'] ?? 0;
                    
                    // Get current quantity from existing income items for this inventory item
                    $stmt = $dcmt_pdo->prepare("
                        SELECT COALESCE(SUM(dcmt_quantity), 0) as current_quantity 
                        FROM dcmt_income_breakdown 
                        WHERE dcmt_id = ? AND dcmt_line_type = 'product' AND dcmt_inventory_id = ?
                    ");
                    $stmt->execute([$income_id, $item['inventory_id']]);
                    $current_quantity = $stmt->fetch()['current_quantity'] ?? 0;
                    
                    // Available stock after restoring current quantities
                    $effective_stock = $available_stock + $current_quantity;
                    
                    if (floatval($item['quantity']) > $effective_stock) {
                        $errors[] = trans('income', 'insufficient_stock') . ': ' . $effective_stock;
                        break;
                    }
                }
            }
        }
        
        // If no validation errors, proceed with updating
        if (empty($errors)) {
            // Debug logging for form submission
            error_log("Income update submission - ID: $income_id, Type: $type, Amount: $amount");
            
            try {
                $current_user = dcmt_get_current_user();
                $recorded_by_username = is_array($current_user) && !empty($current_user['dcmt_username'])
                    ? $current_user['dcmt_username']
                    : 'system';
                
                $previousBreakdownStmt = $dcmt_pdo->prepare("
                    SELECT 
                        ib.*,
                        s.dcmt_name AS service_name,
                        inv.dcmt_name AS product_name,
                        u.dcmt_full_name AS doctor_name
                    FROM dcmt_income_breakdown ib
                    LEFT JOIN dcmt_services s ON (ib.dcmt_line_type = 'service' AND ib.dcmt_reference_id = s.dcmt_id)
                    LEFT JOIN dcmt_inventory inv ON (ib.dcmt_line_type = 'product' AND ib.dcmt_inventory_id = inv.dcmt_id)
                    LEFT JOIN dcmt_users u ON (ib.dcmt_line_type = 'service' AND ib.dcmt_user_id = u.dcmt_id)
                    WHERE ib.dcmt_id = ?
                ");
                $previousBreakdownStmt->execute([$income_id]);
                $previous_breakdown_rows = $previousBreakdownStmt->fetchAll();
                
                $previous_service_summary = dcmt_build_income_service_summary($previous_breakdown_rows, $doctor_name_map);
                $previous_service_total = array_reduce($previous_service_summary, function ($carry, $row) {
                    return $carry + (float) ($row['total'] ?? 0);
                }, 0.0);
                $previous_product_summary = dcmt_build_income_product_summary($previous_breakdown_rows, $inventory_name_map);
                $previous_product_total = array_reduce($previous_product_summary, function ($carry, $row) {
                    return $carry + (float) ($row['total'] ?? 0);
                }, 0.0);
                
                // Calculate previous payment total
                $previous_payment_total = 0.0;
                foreach ($existing_payment_entries as $payment) {
                    $previous_payment_total += floatval($payment['dcmt_amount'] ?? 0);
                }
                
                // Build payment method map
                $payment_method_map = [];
                foreach ($income_payment_methods as $method) {
                    $payment_method_map[(int)$method['dcmt_id']] = $method['dcmt_name'];
                }
                
                $dcmt_pdo->beginTransaction();
                
                // Use the payment status selected by the user from the form
                $final_payment_status_id = $payment_status_id;
                
                // If there is still a pending amount but the status is set to completed,
                // automatically switch the status back to pending so that no extra
                // payment is created to force the record to completed.
                if ($total_pending_amount > 0 && $completed_status_id !== null && $pending_status_id !== null && $final_payment_status_id == $completed_status_id) {
                    $final_payment_status_id = $pending_status_id;
                }
                
                $previous_payment_status_id = (int) ($income['dcmt_payment_status_id'] ?? 0);
                $status_name_map = [];
                foreach ($income_payment_statuses as $status) {
                    $status_name_map[(int) ($status['dcmt_id'] ?? 0)] = strtolower(trim((string) ($status['dcmt_name'] ?? '')));
                }

                $final_status_name = $status_name_map[(int) $final_payment_status_id] ?? '';
                $previous_status_name = $status_name_map[$previous_payment_status_id] ?? '';

                $isCompletedStatusName = function (string $name): bool {
                    $value = strtolower(trim($name));
                    return $value !== '' && (
                        strpos($value, 'completed') !== false ||
                        strpos($value, 'paid') !== false ||
                        strpos($value, 'completado') !== false ||
                        strpos($value, 'pagado') !== false
                    );
                };

                $final_is_completed = $completed_status_id !== null
                    ? ((int) $final_payment_status_id === (int) $completed_status_id)
                    : $isCompletedStatusName($final_status_name);

                $previous_is_completed = $completed_status_id !== null
                    ? ($previous_payment_status_id === (int) $completed_status_id)
                    : $isCompletedStatusName($previous_status_name);

                $is_changing_to_completed = !$previous_is_completed && $final_is_completed;

                if ($is_changing_to_completed) {
                    $currentPaymentDate = dcmt_get_current_date('Y-m-d');
                    
                    if ($type === 'consultation') {
                        $service_pending_amount = floatval($service_pending_amount);
                        if ($service_pending_amount > 0) {
                            $remainingPending = $service_pending_amount;
                            $service_paid_amount = floatval($service_paid_amount) + $remainingPending;
                            $service_pending_amount = 0.00;
                            $total_paid_amount = floatval($amount);
                            $total_pending_amount = 0.00;
                            
                            $income_payment_entries[] = [
                                'amount' => round($remainingPending, 2),
                                'paid_on' => $currentPaymentDate,
                                'payment_method_id' => !empty($payment_method_id) ? (int) $payment_method_id : null
                            ];
                        }
                    } elseif ($type === 'product_sale') {
                        $product_pending_amount = floatval($product_pending_amount);
                        if ($product_pending_amount > 0) {
                            $remainingPending = $product_pending_amount;
                            $product_paid_amount = floatval($product_paid_amount) + $remainingPending;
                            $product_pending_amount = 0.00;
                            $total_paid_amount = floatval($amount);
                            $total_pending_amount = 0.00;
                            
                            $income_payment_entries[] = [
                                'amount' => round($remainingPending, 2),
                                'paid_on' => $currentPaymentDate,
                                'payment_method_id' => !empty($payment_method_id) ? (int) $payment_method_id : null
                            ];
                        }
                    } else {
                        $pending_amount = max(0.00, floatval($amount) - floatval($total_paid_amount));
                        if ($pending_amount > 0) {
                            $total_paid_amount = floatval($amount);
                            $total_pending_amount = 0.00;
                            
                            $income_payment_entries[] = [
                                'amount' => round($pending_amount, 2),
                                'paid_on' => $currentPaymentDate,
                                'payment_method_id' => !empty($payment_method_id) ? (int) $payment_method_id : null
                            ];
                        }
                    }
                }
                
                // Track all field changes for detailed logging
                $income_changes = [];
                
                // Check patient name change
                if ($income['dcmt_patient_name'] !== $patient_name) {
                    $income_changes[] = "Patient Name: " . ($income['dcmt_patient_name'] ?: 'Empty') . " → " . $patient_name;
                }
                
                // Check type change
                if ($income['dcmt_type'] !== $type) {
                    $income_changes[] = "Type: " . trans('income', $income['dcmt_type']) . " → " . trans('income', $type);
                }
                
                // Check transaction date change
                if ($income['dcmt_transaction_date'] !== $transaction_date) {
                    $income_changes[] = "Transaction Date: " . dcmt_format_date($income['dcmt_transaction_date']) . " → " . dcmt_format_date($transaction_date);
                }

                $previous_description = trim((string) ($income['dcmt_description'] ?? ''));
                $updated_description = trim((string) ($description ?? ''));
                $description_audit_entry = null;
                $description_changed = $previous_description !== $updated_description;
                if ($description_changed) {
                    if ($previous_description === '' && $updated_description !== '') {
                        $description_audit_entry = "Description: Added";
                    } else {
                        $description_audit_entry = "Description: Updated";
                    }
                    $income_changes[] = $description_audit_entry;
                }
                
                // Check note change
                $previous_note = trim((string) ($income['dcmt_note'] ?? ''));
                $updated_note = trim((string) ($note ?? ''));
                if ($previous_note !== $updated_note) {
                    $old_note = $previous_note !== '' ? $previous_note : 'Empty';
                    $new_note = $updated_note !== '' ? $updated_note : 'Empty';
                    $income_changes[] = "Note: " . $old_note . " → " . $new_note;
                }
                
                // Check doctor change
                $previous_doctor_user_id = !empty($income['dcmt_user_id']) ? (int) $income['dcmt_user_id'] : 0;
                $current_doctor_user_id = $doctor_user_id !== null ? (int) $doctor_user_id : 0;
                if ($previous_doctor_user_id > 0 && $current_doctor_user_id > 0 && $previous_doctor_user_id !== $current_doctor_user_id) {
                    if (!$description_changed && !($doctor_user_id_from_service_items ?? false)) {
                        $old_doctor_name = $doctor_name_map[$previous_doctor_user_id] ?? 'Unknown';
                        $new_doctor_name = $doctor_name_map[$current_doctor_user_id] ?? 'Unknown';
                        $income_changes[] = "Doctor: " . $old_doctor_name . " → " . $new_doctor_name;
                    }
                }

                // Track payment field changes for detailed logging
                $payment_changes = [];
                $status_changed = false;
                
                // Check payment status change
                if ($income['dcmt_payment_status_id'] != $final_payment_status_id) {
                    $old_status_name = '';
                    $new_status_name = '';
                    foreach ($income_payment_statuses as $status) {
                        if ($status['dcmt_id'] == $income['dcmt_payment_status_id']) {
                            $old_status_name = $status['dcmt_name'];
                        }
                        if ($status['dcmt_id'] == $final_payment_status_id) {
                            $new_status_name = $status['dcmt_name'];
                        }
                    }
                    $payment_changes[] = "Payment Status: {$old_status_name} → {$new_status_name}";
                    $status_changed = true;
                }
                
                // Check payment amount changes for consultation (only if status didn't change)
                if (!$status_changed && $type === 'consultation') {
                    if ($income['dcmt_service_paid_amount'] != $service_paid_amount) {
                        $payment_changes[] = "Service Paid: " . dcmt_format_currency($income['dcmt_service_paid_amount']) . " → " . dcmt_format_currency($service_paid_amount);
                    }
                    if ($income['dcmt_service_pending_amount'] != $service_pending_amount) {
                        $payment_changes[] = "Service Pending: " . dcmt_format_currency($income['dcmt_service_pending_amount']) . " → " . dcmt_format_currency($service_pending_amount);
                    }
                }
                
                // Check payment amount changes for product sale (only if status didn't change)
                if (!$status_changed && $type === 'product_sale') {
                    if ($income['dcmt_product_paid_amount'] != $product_paid_amount) {
                        $payment_changes[] = "Product Paid: " . dcmt_format_currency($income['dcmt_product_paid_amount']) . " → " . dcmt_format_currency($product_paid_amount);
                    }
                    if ($income['dcmt_product_pending_amount'] != $product_pending_amount) {
                        $payment_changes[] = "Product Pending: " . dcmt_format_currency($income['dcmt_product_pending_amount']) . " → " . dcmt_format_currency($product_pending_amount);
                    }
                }
                
                // Check total amount changes (only if status didn't change)
                if (!$status_changed) {
                    if (abs($income['dcmt_total_paid_amount'] - $total_paid_amount) > 0.01) {
                        $payment_changes[] = "Total Paid: " . dcmt_format_currency($income['dcmt_total_paid_amount']) . " → " . dcmt_format_currency($total_paid_amount);
                    }
                    if (abs($income['dcmt_total_pending_amount'] - $total_pending_amount) > 0.01) {
                        $payment_changes[] = "Total Pending: " . dcmt_format_currency($income['dcmt_total_pending_amount']) . " → " . dcmt_format_currency($total_pending_amount);
                    }
                }
                
                // Check payment method change
                $previous_payment_method_id = !empty($income['dcmt_payment_method_id']) ? (int) $income['dcmt_payment_method_id'] : 0;
                $current_payment_method_id = $payment_method_id !== null ? (int) $payment_method_id : 0;
                if ($previous_payment_method_id !== $current_payment_method_id && $current_payment_method_id > 0) {
                    $old_method_name = '';
                    $new_method_name = '';
                    foreach ($income_payment_methods as $method) {
                        if ((int) ($method['dcmt_id'] ?? 0) === $previous_payment_method_id) {
                            $old_method_name = (string) ($method['dcmt_name'] ?? '');
                        }
                        if ((int) ($method['dcmt_id'] ?? 0) === $current_payment_method_id) {
                            $new_method_name = (string) ($method['dcmt_name'] ?? '');
                        }
                    }
                    if ($new_method_name !== '') {
                        $payment_changes[] = "Payment Method: {$old_method_name} → {$new_method_name}";
                    }
                }
                
                // Log activity if payment status was changed to completed
                if ($is_changing_to_completed) {
                    $amount_type = $type === 'consultation' ? 'service' : ($type === 'product_sale' ? 'product' : 'general');
                    $changes_text = !empty($payment_changes) ? implode(' | ', $payment_changes) : "Type: $amount_type";
                    dcmt_log_activity('Payment marked as complete', "Income ID: $income_id | Patient: $patient_name | $changes_text");
                }
                
                // Update income record
                $stmt = $dcmt_pdo->prepare("
                    UPDATE dcmt_income SET
                        dcmt_patient_name = ?, dcmt_patient_id = ?, dcmt_type = ?, dcmt_description = ?, dcmt_note = ?, dcmt_amount = ?,
                        dcmt_paid_amount = ?, dcmt_pending_amount = ?, dcmt_consultation_paid_amount = ?, dcmt_product_paid_amount = ?,
                        dcmt_total_paid_amount = ?, dcmt_total_pending_amount = ?, dcmt_consultation_fee = ?, dcmt_service_id = ?,
                        dcmt_service_amount = ?, dcmt_service_paid_amount = ?, dcmt_service_pending_amount = ?, dcmt_product_amount = ?, dcmt_product_pending_amount = ?,
                        dcmt_payment_method_id = ?, dcmt_payment_status_id = ?, 
                        dcmt_user_id = ?, dcmt_transaction_date = ?, dcmt_updated_at = CURRENT_TIMESTAMP
                    WHERE dcmt_id = ?
                ");
                
                // Use the user ID from service items or fallback to doctor_user_id
                $final_user_id = $doctor_user_id;
                
                // Determine which paid amount to use based on type (legacy field)
                $record_paid_amount = ($type === 'consultation') ? $service_paid_amount : $product_paid_amount;
                $record_pending_amount = $amount - $record_paid_amount;
                
                // Service ID only for consultation type
                $final_service_id = ($type === 'consultation') ? $service_id : null;
                
                // Set consultation and product paid amounts
                // With proportional split logic, product_paid_amount can exist even for consultation/mixed types
                $consultation_paid = 0.00;
                $product_paid = $product_paid_amount; // Use calculated product_paid_amount (already calculated via proportional split)
                
                $stmt->execute([
                    $patient_name, $patient_id, $type, $description, !empty($note) ? $note : null, $amount, $record_paid_amount, $record_pending_amount,
                    $consultation_paid, $product_paid, $total_paid_amount, $total_pending_amount,
                    null, $final_service_id, $service_amount, $service_paid_amount, $service_pending_amount, $product_amount, $product_pending_amount,
                    $payment_method_id, $final_payment_status_id, $final_user_id, $transaction_date, $income_id
                ]);

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
                            error_log("Error saving patient note from income edit form: " . $e->getMessage());
                            // Don't fail the entire transaction if note saving fails
                        }
                    }
                }

                $deletePaymentsStmt = $dcmt_pdo->prepare("DELETE FROM dcmt_income_payment_history WHERE dcmt_income_id = ?");
                $deletePaymentsStmt->execute([$income_id]);

                $has_service_items = !empty($valid_service_items);
                $has_product_items = !empty($product_items);
                $payment_history_type = 'general';
                if ($has_service_items && !$has_product_items) {
                    $payment_history_type = 'consultation';
                } elseif (!$has_service_items && $has_product_items) {
                    $payment_history_type = 'product';
                }
                
                dcmt_insert_income_payment_entries(
                    $dcmt_pdo,
                    (int) $income_id,
                    $payment_history_type,
                    $income_payment_entries,
                    $recorded_by_username
                );
                
                // Restore inventory from previous product lines
                $existingProductLinesStmt = $dcmt_pdo->prepare("
                    SELECT dcmt_inventory_id, dcmt_quantity 
                    FROM dcmt_income_breakdown 
                    WHERE dcmt_id = ? AND dcmt_line_type = 'product'
                ");
                $existingProductLinesStmt->execute([$income_id]);
                $previousProductLines = $existingProductLinesStmt->fetchAll();

                foreach ($previousProductLines as $previousLine) {
                    if (empty($previousLine['dcmt_inventory_id'])) {
                        continue;
                    }
                    $restore_stmt = $dcmt_pdo->prepare("
                        UPDATE dcmt_inventory 
                        SET dcmt_quantity = dcmt_quantity + ? 
                        WHERE dcmt_id = ?
                    ");
                    $restore_stmt->execute([$previousLine['dcmt_quantity'], $previousLine['dcmt_inventory_id']]);
                }

                // Clear existing breakdown entries
                $dcmt_pdo->prepare("DELETE FROM dcmt_income_breakdown WHERE dcmt_id = ?")->execute([$income_id]);

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

                // Reinsert service lines
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
                            $serviceNameMap[(int)$serviceRow['dcmt_id']] = $serviceRow['dcmt_name'];
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
                        'user_id' => $item['doctor_id'] // This is actually user_id
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

                // Prepare product list respecting consultation filtering
                $normalized_product_items = [];
                if (!empty($product_items)) {
                    if ($type === 'consultation') {
                        foreach ($product_items as $item) {
                            if (!empty($item['inventory_id']) || !empty($item['quantity']) || !empty($item['unit_price'])) {
                                $normalized_product_items[] = $item;
                            }
                        }
                    } else {
                        $normalized_product_items = $product_items;
                    }
                }

                $inventoryNameMap = [];
                foreach ($inventory_items as $inventoryItem) {
                    $inventoryNameMap[(int)$inventoryItem['dcmt_id']] = $inventoryItem['dcmt_name'];
                }

                $new_service_rows = [];
                if (!empty($valid_service_items)) {
                    foreach ($valid_service_items as $item) {
                        $line_total = (int) $item['quantity'] * floatval($item['amount']);
                        $new_service_rows[] = [
                            'dcmt_line_type' => 'service',
                            'dcmt_reference_id' => $item['service_id'],
                            'dcmt_user_id' => $item['doctor_id'],
                            'dcmt_quantity' => $item['quantity'],
                            'dcmt_unit_price' => $item['amount'],
                            'dcmt_line_total' => $line_total,
                            'service_name' => $serviceNameMap[(int) $item['service_id']] ?? null
                        ];
                    }
                }
                
                if (!empty($normalized_product_items)) {
                    foreach ($normalized_product_items as $item) {
                        $quantity = floatval($item['quantity']);
                        $stmt = $dcmt_pdo->prepare("SELECT dcmt_quantity FROM dcmt_inventory WHERE dcmt_id = ?");
                        $stmt->execute([$item['inventory_id']]);
                        $available_stock = $stmt->fetch()['dcmt_quantity'] ?? 0;

                        if ($quantity > $available_stock) {
                            throw new Exception(trans('income', 'insufficient_stock') . ': ' . $available_stock);
                        }
                    }

                    // Product lines always use the configured default doctor (not the income / service doctor)
                    $product_doctor_user_id = !empty($default_doctor_user_id) ? (int) $default_doctor_user_id : null;

                    foreach ($normalized_product_items as $item) {
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

                        $update_stmt = $dcmt_pdo->prepare("
                            UPDATE dcmt_inventory 
                            SET dcmt_quantity = dcmt_quantity - ? 
                            WHERE dcmt_id = ?
                        ");
                        $update_stmt->execute([$quantity, $inventoryId]);
                    }
                }
                
                $new_product_rows = [];
                foreach ($normalized_product_items as $item) {
                    $quantity = floatval($item['quantity'] ?? 0);
                    $unit_price = floatval($item['unit_price'] ?? 0);
                    $inventoryId = isset($item['inventory_id']) ? (int) $item['inventory_id'] : 0;
                    $new_product_rows[] = [
                        'dcmt_line_type' => 'product',
                        'dcmt_inventory_id' => $inventoryId,
                        'dcmt_quantity' => $quantity,
                        'dcmt_unit_price' => $unit_price,
                        'dcmt_line_total' => $quantity * $unit_price,
                        'product_name' => $inventory_name_map[$inventoryId] ?? null
                    ];
                }
                
                $new_service_summary = dcmt_build_income_service_summary($new_service_rows, $doctor_name_map, $serviceNameMap);
                $new_service_total = array_reduce($new_service_summary, function ($carry, $row) {
                    return $carry + (float) ($row['total'] ?? 0);
                }, 0.0);
                $new_product_summary = dcmt_build_income_product_summary($new_product_rows, $inventory_name_map);
                $new_product_total = array_reduce($new_product_summary, function ($carry, $row) {
                    return $carry + (float) ($row['total'] ?? 0);
                }, 0.0);
                
                $previous_total_income = floatval($income['dcmt_amount'] ?? 0);
                $new_total_income = floatval($amount);
                
                // Calculate new payment total
                $new_payment_total = 0.0;
                foreach ($income_payment_entries as $payment) {
                    $new_payment_total += floatval($payment['amount'] ?? 0);
                }
                
                // Compare payments first so service logs can adapt when payment changes exist.
                $payment_audit_entries = dcmt_compare_income_payment_summary($existing_payment_entries, $income_payment_entries, $payment_method_map, $previous_payment_total, $new_payment_total, $previous_total_income, $new_total_income);
                $has_payment_changes = !empty($payment_audit_entries);
                $service_audit_entries = dcmt_compare_income_service_summary($previous_service_summary, $new_service_summary, $previous_service_total, $new_service_total, $previous_total_income, $new_total_income, $has_payment_changes);
                $product_audit_entries = dcmt_compare_income_product_summary($previous_product_summary, $new_product_summary, $previous_product_total, $new_product_total, $previous_total_income, $new_total_income);
                
                // Check if we have changes in multiple categories (services, products, payments)
                $has_service_changes = !empty($service_audit_entries);
                $has_product_changes = !empty($product_audit_entries);
                
                // Keep logs focused:
                // - If row-level payment entries exist, use those instead of aggregate payment fields.
                // - If service/product row-level entries exist, skip aggregate payment fields
                //   (Payment Status/Service Pending/Total Pending, etc.) to avoid noisy duplicates.
                if ($has_payment_changes) {
                    $audit_entries = array_merge($income_changes, $service_audit_entries, $product_audit_entries, $payment_audit_entries);
                } elseif ($has_service_changes || $has_product_changes) {
                    $audit_entries = array_merge($income_changes, $service_audit_entries, $product_audit_entries);
                } else {
                    $audit_entries = array_merge($income_changes, $payment_changes);
                }

                if (!empty($description_audit_entry)) {
                    $audit_entries = [$description_audit_entry];
                }
                
                $dcmt_pdo->commit();
                
                if (!empty($audit_entries)) {
                    $details = sprintf(
                        'Income ID: %d | Patient: %s | %s',
                        $income_id,
                        $patient_name,
                        implode(' | ', $audit_entries)
                    );
                    dcmt_log_activity('Income updated', $details);
                } elseif (!$is_changing_to_completed) {
                    // Fallback if no specific changes detected but update happened
                    $log_total_amount = dcmt_format_currency($amount);
                    $log_total_paid = dcmt_format_currency($total_paid_amount);
                    dcmt_log_activity('Income updated', "Income ID: $income_id | Type: $type | Total payment: $log_total_amount | Total income: $log_total_paid");
                }
                
                dcmt_show_message(trans('income', 'update_success'), 'success');
                
                // Redirect to income view
                dcmt_redirect("view.php?id=$income_id");
                
            } catch (PDOException $e) {
                $dcmt_pdo->rollBack();
                error_log("Income update failed: " . $e->getMessage());
                $errors[] = trans('income', 'update_failed');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

$initial_income_payments_for_js = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!empty($income_payment_entries)) {
        foreach ($income_payment_entries as $paymentRow) {
            $initial_income_payments_for_js[] = [
                'paid_on' => $paymentRow['paid_on'] ?? '',
                'amount' => isset($paymentRow['amount']) ? number_format((float) $paymentRow['amount'], 2, '.', '') : '',
                'payment_method_id' => isset($paymentRow['payment_method_id']) && $paymentRow['payment_method_id'] !== null
                    ? (int) $paymentRow['payment_method_id']
                    : null,
            ];
        }
    }
} else {
    foreach ($existing_payment_entries as $payment_entry) {
        $methodId = null;
        if (!empty($payment_entry['dcmt_notes'])) {
            $decodedNotes = json_decode($payment_entry['dcmt_notes'], true);
            if (is_array($decodedNotes) && isset($decodedNotes['payment_method_id'])) {
                $methodId = (int) $decodedNotes['payment_method_id'];
            }
        }
        
        $initial_income_payments_for_js[] = [
            'paid_on' => $payment_entry['dcmt_paid_on'] ?? '',
            'amount' => isset($payment_entry['dcmt_amount']) ? number_format((float) $payment_entry['dcmt_amount'], 2, '.', '') : '',
            'payment_method_id' => $methodId
        ];
    }
}

$dcmt_currency_symbol = dcmt_get_current_currency();

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('income', 'edit_income_record'); ?></h1>
            <a href="view.php?id=<?php echo $income['dcmt_id']; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('common', 'view'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="incomeForm" data-edit-mode="true">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            <input type="hidden" id="income_id" value="<?php echo $income_id; ?>">
            <input type="hidden" id="original_payment_status_id" value="<?php echo $income['dcmt_payment_status_id']; ?>">
            <input type="hidden" id="completed_status_id" value="<?php echo $completed_status_id; ?>">
            <input type="hidden" id="pending_status_id" value="<?php echo $pending_status_id; ?>">
            
        <input type="hidden" id="type" name="type" value="<?php echo htmlspecialchars($_POST['type'] ?? $income['dcmt_type']); ?>">
        <input type="hidden" id="doctor_id" name="doctor_id" value="<?php echo htmlspecialchars($_POST['doctor_id'] ?? ($current_doctor_user_id ?? '')); ?>">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="transaction_date" class="form-label"><?php echo trans('income', 'transaction_date'); ?> *</label>
                        <input type="date" class="form-control" id="transaction_date" name="transaction_date" 
                               value="<?php echo htmlspecialchars($_POST['transaction_date'] ?? $income['dcmt_transaction_date']); ?>" required>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <div class="mb-2">
                            <label for="patient_name" class="form-label mb-0"><?php echo trans('income', 'patient_name'); ?> *</label>
                        </div>
                        <select class="form-select" id="patient_name" name="patient_name" required>
                            <option value=""><?php echo trans('income', 'patient_name_placeholder'); ?></option>
                            <?php 
                            $selected_patient_id = $_POST['patient_id'] ?? $income['dcmt_patient_id'] ?? null;
                            foreach ($all_patients as $pat): 
                                $full_name = $pat['dcmt_patient_name'] ?? '';
                                $display_text = $full_name;
                                if (!empty($pat['dcmt_phone'])) {
                                    $display_text .= ' - ' . htmlspecialchars($pat['dcmt_phone']);
                                }
                                if (($pat['dcmt_status'] ?? 'active') !== 'active') {
                                    $display_text .= ' (' . trans('common', 'inactive') . ')';
                                }
                                $is_selected = ($selected_patient_id !== null && (int)$selected_patient_id === (int)$pat['dcmt_id']);
                            ?>
                                <option value="<?php echo htmlspecialchars($full_name); ?>" data-patient-id="<?php echo $pat['dcmt_id']; ?>" <?php echo $is_selected ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($display_text); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <input type="hidden" id="patient_id" name="patient_id" value="<?php echo htmlspecialchars($selected_patient_id ?? ''); ?>">
                    </div>
                </div>
            </div>
            
            <!-- Description Field - Second row above buttons -->
            <div class="row">
                <div class="col-12">
                    <div class="mb-3">
                        <label for="description" class="form-label"><?php echo trans('income', 'description'); ?></label>
                        <textarea class="form-control" id="description" name="description" rows="3" placeholder="<?php echo trans('income', 'description_placeholder'); ?>"><?php echo htmlspecialchars($_POST['description'] ?? $income['dcmt_description']); ?></textarea>
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
            
            <!-- Service Items Section - Only display if stored records exist -->
            <div id="serviceItems" class="dcmt-items-panel" style="<?php echo $has_stored_service_records ? 'display: block;' : 'display: none;'; ?>">
                <h6 class="mb-3 d-flex align-items-center dcmt-items-header">
                    <?php echo trans('income', 'service_items'); ?>
                </h6>
                <?php if ($has_stored_service_records): ?>
                    <?php foreach ($service_items_view as $index => $service_item_view): ?>
                        <?php if (!empty($service_item_view['service_id'])): ?>
                        <div class="service-item row mb-2" data-index="<?php echo $index; ?>">
                            <div class="col-md-3">
                                <select class="form-select service-doctor-select" name="service_items[<?php echo $index; ?>][doctor_id]">
                                    <?php 
                                        $defaultRowDoctor = $service_item_view['doctor_id'] ?? ($current_doctor_user_id ?? $default_doctor_user_id ?? '');
                                        $defaultRowServiceId = $service_item_view['service_id'] ?? '';
                                        $defaultRowQuantity = $service_item_view['quantity'] ?? '1';
                                        $defaultRowAmount = $service_item_view['amount'] ?? '';
                                        $defaultRowTotal = $service_item_view['total'] ?? '';
                                    ?>
                                    <option value=""><?php echo trans('income', 'select_doctor'); ?></option>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <?php $isSelectedDoctor = (string)$defaultRowDoctor === (string)$doctor['dcmt_id']; ?>
                                        <option value="<?php echo $doctor['dcmt_id']; ?>" <?php echo $isSelectedDoctor ? 'selected' : ''; ?>>
                                            <?php echo htmlspecialchars($doctor['dcmt_name']); ?>
                                        </option>
                                    <?php endforeach; ?>
                                </select>
                            </div>
                            <div class="col-md-3">
                                <select class="form-select service-select" name="service_items[<?php echo $index; ?>][service_id]" <?php echo empty($defaultRowServiceId) ? 'disabled' : ''; ?>>
                                    <option value=""><?php echo trans('service', 'select_service'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-1">
                                <input type="number" class="form-control service-quantity" name="service_items[<?php echo $index; ?>][quantity]" 
                                       placeholder="<?php echo trans('income', 'qty'); ?>" min="1" value="<?php echo htmlspecialchars($defaultRowQuantity); ?>"
                                       onchange="updateServiceItemTotalByIndex(<?php echo $index; ?>); updateServiceTotal();" 
                                       oninput="updateServiceItemTotalByIndex(<?php echo $index; ?>); updateServiceTotal();">
                            </div>
                            <div class="col-md-2">
                                <div class="dcmt-amount-input-wrapper">
                                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                    <input type="number" class="form-control dcmt-amount-input service-amount" name="service_items[<?php echo $index; ?>][amount]" 
                                           placeholder="<?php echo trans('common', 'amount'); ?>" step="0.01" min="0" 
                                           value="<?php echo htmlspecialchars($defaultRowAmount); ?>"
                                           <?php echo !empty($defaultRowAmount) ? 'data-preserve-initial="true"' : ''; ?>
                                           onchange="updateServiceItemTotalByIndex(<?php echo $index; ?>); updateServiceTotal();" 
                                           oninput="updateServiceItemTotalByIndex(<?php echo $index; ?>); updateServiceTotal();">
                                </div>
                            </div>
                            <div class="col-md-2">
                                <div class="dcmt-amount-input-wrapper">
                                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                    <input type="text" class="form-control dcmt-amount-input service-total" placeholder="<?php echo trans('income', 'total'); ?>" value="<?php echo htmlspecialchars($defaultRowTotal); ?>" readonly>
                                </div>
                            </div>
                            <div class="col-md-1 dcmt-delete-cell">
                                <button type="button" class="btn btn-outline-danger btn-sm remove-service-btn" onclick="if(typeof window.removeServiceItem === 'function') { window.removeServiceItem(this); } else if(typeof removeServiceItem === 'function') { removeServiceItem(this); } return false;">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                        <?php endif; ?>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>

            <!-- Product Items Section - Displayed under serviceItems -->
            <div id="productItems" class="dcmt-items-panel" style="<?php echo !empty($product_items_view) ? 'display: block;' : 'display: none;'; ?>">
                <h6 class="mb-3 d-flex align-items-center dcmt-items-header">
                    <?php echo trans('income', 'product_items'); ?>
                </h6>
                <?php foreach ($product_items_view as $index => $product_item_view): ?>
                <div class="product-item row mb-2">
                    <div class="col-md-4">
                        <select class="form-select product-inventory" name="product_items[<?php echo $index; ?>][inventory_id]" onchange="updateProductPrice(this, <?php echo $index; ?>); checkAndShowProductPaidAmount();">
                            <option value=""><?php echo trans('income', 'select_product'); ?></option>
                            <?php foreach ($inventory_items as $item): ?>
                                <?php $dcmt_inventory_brand = trim((string)($item['dcmt_brand'] ?? '')); ?>
                                <option value="<?php echo $item['dcmt_id']; ?>" 
                                        data-price="<?php echo $item['dcmt_price']; ?>"
                                        data-stock="<?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>"
                                        data-product-type="<?php echo $item['dcmt_product_type'] ?? 'for_sale'; ?>"
                                        <?php echo (!empty($product_item_view['inventory_id']) && (int)$product_item_view['inventory_id'] === (int)$item['dcmt_id']) ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($item['dcmt_name']); ?> 
                                    (<?php echo trans('income', 'stock'); ?>: <?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>)
                                    <?php echo $dcmt_inventory_brand !== '' ? ' - ' . htmlspecialchars($dcmt_inventory_brand) : ''; ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-2">
                        <input type="number" class="form-control product-quantity" name="product_items[<?php echo $index; ?>][quantity]" 
                               placeholder="<?php echo trans('income', 'qty'); ?>" min="1" value="<?php echo htmlspecialchars($product_item_view['quantity'] ?? ''); ?>" onchange="updateProductTotal(<?php echo $index; ?>)">
                    </div>
                    <div class="col-md-2" style="display: none;">
                        <div class="dcmt-amount-input-wrapper">
                            <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                            <input type="number" class="form-control dcmt-amount-input product-price" name="product_items[<?php echo $index; ?>][unit_price]" 
                                   placeholder="<?php echo trans('income', 'price'); ?>" onchange="updateProductTotal(<?php echo $index; ?>)" value="<?php echo htmlspecialchars($product_item_view['unit_price'] ?? ''); ?>">
                        </div>
                    </div>
                    <div class="col-md-2" style="display: none;">
                        <div class="dcmt-amount-input-wrapper">
                            <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                            <input type="text" class="form-control dcmt-amount-input product-total" placeholder="<?php echo trans('income', 'total'); ?>" value="<?php echo htmlspecialchars($product_item_view['total'] ?? ''); ?>" readonly>
                        </div>
                    </div>
                    <div class="col-md-2 dcmt-delete-cell">
                        <input type="hidden" class="product-type" name="product_items[<?php echo $index; ?>][product_type]" value="<?php echo htmlspecialchars($product_item_view['product_type'] ?? ''); ?>">
                        <button type="button" class="btn btn-outline-danger btn-sm remove-product-btn">
                            <i class="fas fa-trash"></i>
                        </button>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
            
            <!-- Note Items Section -->
            <div id="noteItems" class="dcmt-items-panel" style="<?php echo !empty($initial_note_items_for_js) ? 'display: block;' : 'display: none;'; ?>">
                <h6 class="mb-3 d-flex align-items-center dcmt-items-header">
                    <?php echo trans('income', 'note_items'); ?>
                </h6>
                <?php if (!empty($initial_note_items_for_js)): ?>
                    <?php foreach ($initial_note_items_for_js as $index => $note_item): ?>
                        <div class="note-item row mb-2">
                            <div class="col-md-4">
                                <input type="text" class="form-control note-topic" name="note_items[<?php echo $index; ?>][topic]" 
                                       placeholder="<?php echo trans('patient_note', 'topic_placeholder'); ?>" maxlength="255"
                                       value="<?php echo htmlspecialchars($note_item['topic'] ?? ''); ?>">
                            </div>
                            <div class="col-md-7">
                                <textarea class="form-control note-text" name="note_items[<?php echo $index; ?>][note_text]" rows="2" 
                                          placeholder="<?php echo trans('patient_note', 'note_placeholder'); ?>"><?php echo htmlspecialchars($note_item['note_text'] ?? ''); ?></textarea>
                            </div>
                            <div class="col-md-1 dcmt-delete-cell">
                                <button type="button" class="btn btn-outline-danger btn-sm remove-note-btn">
                                    <i class="fas fa-trash"></i>
                                </button>
                            </div>
                        </div>
                    <?php endforeach; ?>
                <?php else: ?>
                    <div class="note-item row mb-2">
                        <div class="col-md-4">
                            <input type="text" class="form-control note-topic" name="note_items[0][topic]" 
                                   placeholder="<?php echo trans('patient_note', 'topic_placeholder'); ?>" maxlength="255"
                                   value="">
                        </div>
                        <div class="col-md-7">
                            <textarea class="form-control note-text" name="note_items[0][note_text]" rows="2" 
                                      placeholder="<?php echo trans('patient_note', 'note_placeholder'); ?>"></textarea>
                        </div>
                        <div class="col-md-1 dcmt-delete-cell">
                            <button type="button" class="btn btn-outline-danger btn-sm remove-note-btn">
                                <i class="fas fa-trash"></i>
                            </button>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
            
<?php
$serviceAmountValue = isset($_POST['service_amount']) ? (float) $_POST['service_amount'] : (float) ($service_amount ?? 0);
$servicePaidValue = isset($_POST['service_paid_amount']) ? (float) $_POST['service_paid_amount'] : (float) ($service_paid_amount ?? 0);
$servicePendingValue = isset($_POST['service_pending_amount']) ? (float) $_POST['service_pending_amount'] : (float) ($service_pending_amount ?? 0);
$productAmountValue = isset($_POST['product_amount']) ? (float) $_POST['product_amount'] : (float) ($product_amount ?? 0);
$productPaidValue = isset($_POST['product_paid_amount']) ? (float) $_POST['product_paid_amount'] : (float) ($product_paid_amount ?? 0);
$productPendingValue = isset($_POST['product_pending_amount']) ? (float) $_POST['product_pending_amount'] : (float) ($product_pending_amount ?? 0);
$totalAmountValue = isset($_POST['amount']) ? (float) $_POST['amount'] : (float) ($income['dcmt_amount'] ?? ($serviceAmountValue + $productAmountValue));
$totalPaidValue = isset($_POST['total_paid_amount']) ? (float) $_POST['total_paid_amount'] : (float) ($total_paid_amount ?? $income['dcmt_total_paid_amount'] ?? 0);
$totalPendingValue = isset($_POST['total_pending_amount']) ? (float) $_POST['total_pending_amount'] : (float) ($total_pending_amount ?? max($totalAmountValue - $totalPaidValue, 0));
$serviceAmountFormatted = number_format($serviceAmountValue, 2, '.', '');
$productAmountFormatted = number_format($productAmountValue, 2, '.', '');
$totalAmountFormatted = number_format($totalAmountValue, 2, '.', '');
$totalPaidFormatted = number_format($totalPaidValue, 2, '.', '');
$totalPendingFormatted = number_format($totalPendingValue, 2, '.', '');
$add_payment_label = htmlspecialchars(trans('income', 'add_payment'), ENT_QUOTES, 'UTF-8');
?>

            <!-- Payment Amounts Section -->
            <div id="paymentAmountsSection" style="display: block;">
                <input type="hidden" id="service_amount" name="service_amount" value="<?php echo htmlspecialchars($serviceAmountFormatted); ?>">
                <input type="hidden" id="service_paid_amount" name="service_paid_amount" value="<?php echo htmlspecialchars(number_format($servicePaidValue, 2, '.', '')); ?>">
                <input type="hidden" id="service_pending_amount" name="service_pending_amount" value="<?php echo htmlspecialchars(number_format($servicePendingValue, 2, '.', '')); ?>">
                <input type="hidden" id="product_amount" name="product_amount" value="<?php echo htmlspecialchars($productAmountFormatted); ?>">
                <input type="hidden" id="product_paid_amount" name="product_paid_amount" value="<?php echo htmlspecialchars(number_format($productPaidValue, 2, '.', '')); ?>">
                <input type="hidden" id="product_pending_amount" name="product_pending_amount" value="<?php echo htmlspecialchars(number_format($productPendingValue, 2, '.', '')); ?>">
                <input type="hidden" id="amount" name="amount" value="<?php echo htmlspecialchars($totalAmountFormatted); ?>">
                <input type="hidden" id="total_paid_amount" name="total_paid_amount" value="<?php echo htmlspecialchars($totalPaidFormatted); ?>">
                <input type="hidden" id="total_pending_amount" name="total_pending_amount" value="<?php echo htmlspecialchars($totalPendingFormatted); ?>">

                <div class="row g-3 mt-1">
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'service_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="serviceTotalDisplay"><?php echo $serviceAmountFormatted; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'product_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="productTotalDisplay"><?php echo $productAmountFormatted; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'total_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="grandTotalDisplay"><?php echo $totalAmountFormatted; ?></span>
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

                <div class="mt-3">
                    <div class="border rounded p-3 bg-light" id="totalPaymentsSection">
                        <div class="d-flex flex-wrap gap-2 mb-2">
                            <button type="button"
                                    class="btn btn-outline-primary btn-sm d-flex align-items-center"
                                    id="addTotalPaymentBtn"
                                    data-payment-target="total"
                                    aria-label="<?php echo $add_payment_label; ?>">
                                <i class="fas fa-plus"></i>
                                <span class="ms-1"><?php echo $add_payment_label; ?></span>
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
                                <span class="fw-semibold fs-5" id="totalPaidDisplay"><?php echo $totalPaidFormatted; ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6 mt-0">
                        <div class="border rounded p-3 bg-light h-100">
                            <p class="text-muted mb-1"><?php echo trans('income', 'total_pending_amount'); ?></p>
                            <div class="d-flex align-items-baseline gap-2">
                                <span class="text-secondary"><?php echo $dcmt_currency_symbol; ?></span>
                                <span class="fw-semibold fs-5" id="totalPendingDisplay"><?php echo $totalPendingFormatted; ?></span>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div id="pricingSummarySection" class="mt-4">
                <div class="row g-3">
                    <div class="col-md-12">
                        <div class="mb-3">
                            <label for="payment_status_id" class="form-label"><?php echo trans('income', 'payment_status'); ?> *</label>
                            <?php
                            $current_user = dcmt_get_current_user();
                            $is_staff_role = ($current_user && ($current_user['dcmt_role'] ?? '') === 'staff');
                            $current_status_id = $_POST['payment_status_id'] ?? $income['dcmt_payment_status_id'];
                            ?>
                            <select class="form-select" id="payment_status_id" name="payment_status_id" required onchange="handlePaymentStatusChange()">
                                <option value=""><?php echo trans('income', 'select_payment_status'); ?></option>
                                <?php foreach ($income_payment_statuses as $status): ?>
                                    <?php 
                                    $status_name = $status['dcmt_name'];
                                    $translated_status = trans('income_payment_status', $status_name);
                                    $display_status = ($translated_status !== $status_name) ? $translated_status : $status_name;
                                    ?>
                                    <option value="<?php echo $status['dcmt_id']; ?>" 
                                            <?php echo ($current_status_id == $status['dcmt_id']) ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($display_status); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div id="payment_status_warning" class="invalid-feedback" style="display: none;">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo trans('income', 'no_pending_amount') ?: trans('income', 'cannot_change_completed_to_pending_zero'); ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="dcmt-form-actions">
                <a href="view.php?id=<?php echo $income['dcmt_id']; ?>" class="btn dcmt-btn-cancel"><?php echo trans('common', 'cancel'); ?></a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i> <?php echo trans('income', 'update_income_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Translation variables for JavaScript
const translations = {
    selectProduct: <?php echo json_encode(trans('income', 'select_product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    qty: <?php echo json_encode(trans('income', 'qty'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    price: <?php echo json_encode(trans('income', 'price'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    total: <?php echo json_encode(trans('income', 'total'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    addProduct: <?php echo json_encode(trans('income', 'add_product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    addAnotherProduct: <?php echo json_encode(trans('income', 'add_another_product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    addService: <?php echo json_encode(trans('income', 'add_service'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    serviceAmount: <?php echo json_encode(trans('common', 'amount'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    confirmDeleteService: <?php echo json_encode(trans('income', 'confirm_delete_service'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    confirmDeleteProduct: <?php echo json_encode(trans('income', 'confirm_delete_product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    confirmDeletePayment: <?php echo json_encode(trans('income', 'confirm_delete_payment'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    pendingAmountNegative: <?php echo json_encode(trans('income', 'pending_amount_negative'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    paidAmountExceedsTotal: <?php echo json_encode(trans('income', 'paid_amount_exceeds_total'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    missingProductPlaceholder: <?php echo json_encode(trans('income', 'missing_product_placeholder'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
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

<?php
// Build product inventory options HTML in PHP to avoid JavaScript escape sequence issues
$product_inventory_options_html = '<option value="">' . htmlspecialchars(trans('income', 'select_product'), ENT_QUOTES, 'UTF-8') . '</option>';
foreach ($inventory_items as $item) {
    $brand = trim((string)($item['dcmt_brand'] ?? ''));
    $product_inventory_options_html .= '<option value="' . htmlspecialchars($item['dcmt_id'], ENT_QUOTES, 'UTF-8') . '"';
    $product_inventory_options_html .= ' data-price="' . htmlspecialchars($item['dcmt_price'], ENT_QUOTES, 'UTF-8') . '"';
    $product_inventory_options_html .= ' data-stock="' . htmlspecialchars(dcmt_format_quantity_display($item['dcmt_quantity']), ENT_QUOTES, 'UTF-8') . '"';
    $product_inventory_options_html .= ' data-product-type="' . htmlspecialchars($item['dcmt_product_type'] ?? 'for_sale', ENT_QUOTES, 'UTF-8') . '">';
    $product_inventory_options_html .= htmlspecialchars($item['dcmt_name'], ENT_QUOTES, 'UTF-8');
    $product_inventory_options_html .= ' (' . htmlspecialchars(trans('income', 'stock'), ENT_QUOTES, 'UTF-8') . ': ' . htmlspecialchars(dcmt_format_quantity_display($item['dcmt_quantity']), ENT_QUOTES, 'UTF-8') . ')';
    if ($brand !== '') {
        $product_inventory_options_html .= ' - ' . htmlspecialchars($brand, ENT_QUOTES, 'UTF-8');
    }
    $product_inventory_options_html .= '</option>';
}
?>
const productInventoryOptionsHTML = <?php echo json_encode($product_inventory_options_html, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

window.inventoryOptionsHTML = window.inventoryOptionsHTML || productInventoryOptionsHTML;

const addProductLabelDefault = translations.addProduct || <?php echo json_encode(trans('income', 'add_product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const addProductLabelAdditional = translations.addAnotherProduct || <?php echo json_encode(trans('income', 'add_another_product'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const defaultDoctorId = <?php echo $default_doctor_user_id ? json_encode($default_doctor_user_id) : 'null'; ?>;
const doctorsData = <?php echo json_encode($doctors_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const initialServiceItems = <?php echo json_encode($initial_service_items_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const initialProductItems = <?php echo json_encode($initial_product_items_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const initialNoteItems = <?php echo json_encode($initial_note_items_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const selectDoctorText = <?php echo json_encode(trans('income', 'select_doctor'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const selectServiceText = <?php echo json_encode(trans('service', 'select_service'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const noServicesAssignedText = <?php echo json_encode(trans('service', 'no_services_assigned'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const loadingText = <?php echo json_encode(trans('common', 'loading'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const doctorPlaceholderOption = '<option value="">' + selectDoctorText + '</option>';
const servicePlaceholderOption = '<option value="">' + selectServiceText + '</option>';
const noServicesAssignedOption = '<option value="">' + noServicesAssignedText + '</option>';
const loadingOption = '<option value="">' + loadingText + '...</option>';

const doctorServiceCache = {};

function fetchServicesForDoctor(doctorId) {
    if (!doctorId) {
        return Promise.resolve([]);
    }
    
    if (doctorServiceCache[doctorId]) {
        return Promise.resolve(doctorServiceCache[doctorId]);
    }
    
    return fetch('get_doctor_services.php?doctor_id=' + doctorId)
        .then(response => response.json())
        .then(data => {
            doctorServiceCache[doctorId] = Array.isArray(data.services) ? data.services : [];
            return doctorServiceCache[doctorId];
        })
        .catch(() => {
            return [];
        });
}

function handleServiceDoctorChange(select, index, preselectedServiceId = null, options = {}) {
    if (!select) {
        return;
    }
    
    const doctorId = select.value;
    const serviceItem = select.closest('.service-item');
    const serviceSelect = serviceItem ? serviceItem.querySelector('.service-select') : null;
    const amountInput = serviceItem ? serviceItem.querySelector('.service-amount') : null;
    
    if (!serviceSelect) {
        return;
    }
    
    if (!doctorId) {
        clearServiceSelect(serviceSelect);
        return;
    }
    
    serviceSelect.disabled = true;
    serviceSelect.innerHTML = loadingOption;
    
    fetchServicesForDoctor(doctorId).then(services => {
        const serviceList = Array.isArray(services) ? services : [];
        const fallbackServiceName = options.preselectedServiceName || '';
        const hasServices = serviceList.length > 0;

        const appendFallbackOption = () => {
            if (!preselectedServiceId || !fallbackServiceName) {
                return false;
            }
            serviceSelect.innerHTML = servicePlaceholderOption;
            const fallbackOption = document.createElement('option');
            fallbackOption.value = String(preselectedServiceId);
            fallbackOption.textContent = fallbackServiceName;
            fallbackOption.selected = true;
            serviceSelect.appendChild(fallbackOption);
            serviceSelect.disabled = false;
            serviceSelect.dataset.selectedServiceId = String(preselectedServiceId);
            if (typeof $ !== 'undefined') {
                ensureSelect2Initialized(serviceSelect, selectServiceText);
                $(serviceSelect).val(String(preselectedServiceId)).trigger('change');
            }
            if (amountInput && !options.preserveAmount) {
                updateServiceItemAmount(serviceSelect, index);
            }
            return true;
        };

        if (!hasServices) {
            if (appendFallbackOption()) {
                return;
            }
            serviceSelect.innerHTML = noServicesAssignedOption;
            serviceSelect.disabled = true;
            if (amountInput && !options.preserveAmount) {
                amountInput.value = '';
                updateServiceItemTotal(index);
            }
            return;
        }
        
        serviceSelect.disabled = false;
        serviceSelect.innerHTML = getServiceOptionsHTML(serviceList, preselectedServiceId);
        
        let hasPreselected = false;
        if (preselectedServiceId) {
            hasPreselected = serviceList.some(service => String(service.id) === String(preselectedServiceId));
        }
        
        if (preselectedServiceId) {
            if (!hasPreselected && fallbackServiceName) {
                const fallbackOption = document.createElement('option');
                fallbackOption.value = String(preselectedServiceId);
                fallbackOption.textContent = fallbackServiceName;
                serviceSelect.appendChild(fallbackOption);
            }
            serviceSelect.value = String(preselectedServiceId);
            serviceSelect.dataset.selectedServiceId = String(preselectedServiceId);
        } else {
            delete serviceSelect.dataset.selectedServiceId;
            serviceSelect.value = '';
        }
        
        // Ensure Select2 is initialized after setting innerHTML and value
        if (typeof $ !== 'undefined') {
            // Destroy existing Select2 if it exists
            if ($(serviceSelect).hasClass('select2-hidden-accessible')) {
                $(serviceSelect).select2('destroy');
            }
            // Initialize Select2
            ensureSelect2Initialized(serviceSelect, selectServiceText);
            
            // Set the value in Select2
            if (preselectedServiceId) {
                $(serviceSelect).val(String(preselectedServiceId)).trigger('change');
            } else {
                $(serviceSelect).val('').trigger('change');
            }
            
            // Always ensure event handlers are properly attached for Select2
            const $serviceSelect = $(serviceSelect);
            // Remove any existing handlers to avoid duplicates
            $serviceSelect.off('change.select2-service');
            $serviceSelect.off('select2:select.select2-service');
            
            // Attach handlers directly to this select element for immediate updates
            $serviceSelect.on('change.select2-service select2:select.select2-service', function() {
                const serviceItems = document.querySelectorAll('#serviceItems .service-item');
                const currentIndex = Array.from(serviceItems).indexOf(serviceSelect.closest('.service-item'));
                if (currentIndex >= 0) {
                    updateServiceItemAmount(this, currentIndex);
                    updateServiceTotal();
                }
            });
        }
        
        if (preselectedServiceId) {
            if (!options.preserveAmount) {
                updateServiceItemAmount(serviceSelect, index);
            } else {
                updateServiceItemTotal(index);
                updateServiceTotal();
            }
        }
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
    const preselectedServiceName = data.service_name ?? null;
    const preselectedQuantity = data.quantity ?? (quantityInput ? quantityInput.value : '1');
    const preselectedAmount = data.amount ?? (amountInput ? amountInput.value : '');
    
    if (doctorSelect) {
        doctorSelect.name = 'service_items[' + index + '][doctor_id]';
        doctorSelect.innerHTML = getDoctorOptionsHTML(preselectedDoctorId);
        const shouldApplyDefaultDoctor = !preselectedDoctorId && typeof defaultDoctorId !== 'undefined' && defaultDoctorId && index === 0;
        if (preselectedDoctorId) {
            doctorSelect.value = String(preselectedDoctorId);
        } else if (shouldApplyDefaultDoctor) {
            doctorSelect.value = String(defaultDoctorId);
        }
        // Initialize Select2 after setting value
        ensureSelect2Initialized(doctorSelect, selectDoctorText);
        // Ensure Select2 reflects the value
        if (typeof $ !== 'undefined' && doctorSelect.value) {
            $(doctorSelect).val(doctorSelect.value).trigger('change');
        }
    }
    
    if (serviceSelect) {
        serviceSelect.name = 'service_items[' + index + '][service_id]';
        if (preselectedServiceId) {
            serviceSelect.dataset.selectedServiceId = String(preselectedServiceId);
        } else {
            delete serviceSelect.dataset.selectedServiceId;
        }
        // Don't initialize Select2 here - wait for services to be loaded
        // ensureSelect2Initialized(serviceSelect, selectServiceText);
    }
    
    if (quantityInput) {
        quantityInput.name = 'service_items[' + index + '][quantity]';
        quantityInput.value = preselectedQuantity || '1';
    }
    
    if (amountInput) {
        amountInput.name = 'service_items[' + index + '][amount]';
        amountInput.value = preselectedAmount;
        if (preselectedAmount !== undefined && preselectedAmount !== '') {
            amountInput.dataset.preserveInitial = 'true';
        } else {
            delete amountInput.dataset.preserveInitial;
        }
    }
    
    if (totalInput) {
        const qty = parseFloat(quantityInput ? quantityInput.value : 0) || 0;
        const amt = parseFloat(amountInput ? amountInput.value : 0) || 0;
        totalInput.value = qty && amt ? (qty * amt).toFixed(2) : '';
    }
    
    // Always check if doctor has a value and load services, even if not preselected
    if (doctorSelect) {
        const doctorValue = doctorSelect.value;
        if (doctorValue) {
            const preserveAmount = preselectedAmount !== undefined && preselectedAmount !== '';
            // Use setTimeout to ensure Select2 is fully initialized before loading services
            setTimeout(function() {
                handleServiceDoctorChange(doctorSelect, index, preselectedServiceId, { preserveAmount, preselectedServiceName });
            }, 50);
        } else if (serviceSelect) {
            clearServiceSelect(serviceSelect);
            // Initialize Select2 for empty service select
            ensureSelect2Initialized(serviceSelect, selectServiceText);
        }
        
        // Set up change handler for when doctor is manually selected after initialization
        // Use setTimeout to ensure Select2 is fully initialized
        setTimeout(function() {
            if (typeof $ !== 'undefined') {
                const $doctorSelect = $(doctorSelect);
                // Remove any existing handlers to avoid duplicates
                $doctorSelect.off('change.select2-doctor');
                $doctorSelect.off('select2:select.select2-doctor');
                
                // Handle both native change and Select2 select events
                $doctorSelect.on('change.select2-doctor select2:select.select2-doctor', function() {
                    const serviceItems = document.querySelectorAll('#serviceItems .service-item');
                    const currentIndex = Array.from(serviceItems).indexOf(row);
                    handleServiceDoctorChange(this, currentIndex);
                    syncPrimaryDoctorFromServices();
                });
                
                // Also ensure service select has event handlers attached
                if (serviceSelect) {
                    const $serviceSelect = $(serviceSelect);
                    // Remove any existing handlers to avoid duplicates
                    $serviceSelect.off('change.select2-service');
                    $serviceSelect.off('select2:select.select2-service');
                    
                    // Attach handlers directly to this select element for immediate updates
                    $serviceSelect.on('change.select2-service select2:select.select2-service', function() {
                        const serviceItems = document.querySelectorAll('#serviceItems .service-item');
                        const currentIndex = Array.from(serviceItems).indexOf(row);
                        if (currentIndex >= 0) {
                            updateServiceItemAmount(this, currentIndex);
                            updateServiceTotal();
                        }
                    });
                }
            }
        }, 100);
    } else if (serviceSelect) {
        // If no doctor select, initialize service select with placeholder
        clearServiceSelect(serviceSelect);
        ensureSelect2Initialized(serviceSelect, selectServiceText);
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

function syncPrimaryDoctorFromServices() {
    const doctorField = document.getElementById('doctor_id');
    if (!doctorField) {
        return;
    }
    const doctorSelects = document.querySelectorAll('#serviceItems .service-doctor-select');
    let syncedValue = '';
    doctorSelects.forEach(select => {
        if (!syncedValue && select && select.value) {
            syncedValue = select.value;
        }
    });
    doctorField.value = syncedValue;
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
    const preserveAmountAttr = amountValue !== '' ? ' data-preserve-initial="true"' : '';
    
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
                   placeholder="${escapeHtml(translations.qty)}" min="1" value="${quantityValue}" 
                   onchange="updateServiceItemTotalByIndex(${currentIndex}); updateServiceTotal();" 
                   oninput="updateServiceItemTotalByIndex(${currentIndex}); updateServiceTotal();">
        </div>
        <div class="col-md-2">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="number" class="form-control dcmt-amount-input service-amount" name="service_items[${currentIndex}][amount]"
                       placeholder="${translations.serviceAmount}" step="0.01" min="0" value="${amountValue}"${preserveAmountAttr}
                       onchange="updateServiceItemTotalByIndex(${currentIndex}); updateServiceTotal();" 
                       oninput="updateServiceItemTotalByIndex(${currentIndex}); updateServiceTotal();">
            </div>
        </div>
        <div class="col-md-2">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="text" class="form-control dcmt-amount-input service-total" placeholder="${translations.total}" readonly>
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
    syncPrimaryDoctorFromServices();
    
    const addServiceBtnText = document.getElementById('addServiceBtnText');
    if (addServiceBtnText && currentIndex >= 0) {
        addServiceBtnText.textContent = '<?php echo trans('income', 'add_another_service'); ?>';
    }
    
    // Update service total after adding a new service item
    // Use setTimeout to ensure the row is fully initialized
    setTimeout(function() {
        if (typeof updateServiceTotal === 'function') {
            updateServiceTotal();
        }
    }, 50);
    
    syncIncomeType();
    
    return row;
}

function removeServiceItem(button) {
    // Ensure button is a DOM element
    if (!button) {
        console.warn('removeServiceItem: button parameter is missing');
        return;
    }
    
    // Get confirmation message
    const translations = window.translations || {};
    const confirmationMessage = translations.confirmDeleteService || <?php echo json_encode(trans('income', 'confirm_delete_service'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
    
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
        syncPrimaryDoctorFromServices();
    }
}

// Make removeServiceItem globally accessible immediately
window.removeServiceItem = removeServiceItem;

// Also ensure it's available on DOMContentLoaded
if (document.readyState === 'loading') {
    document.addEventListener('DOMContentLoaded', function() {
        window.removeServiceItem = removeServiceItem;
    });
} else {
    // DOM already loaded
    window.removeServiceItem = removeServiceItem;
}

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
        options += '<option value="' + value + '" data-price="' + (service.price ?? '') + '"' + (isSelected ? ' selected' : '') + '>' + escapeHtml(service.name) + '</option>';
    });
    return options;
}

function clearServiceSelect(serviceSelect) {
    if (!serviceSelect) {
        return;
    }
    serviceSelect.innerHTML = servicePlaceholderOption;
    serviceSelect.disabled = true;
    delete serviceSelect.dataset.selectedServiceId;
    if (typeof $ !== 'undefined' && $(serviceSelect).hasClass('select2-hidden-accessible')) {
        $(serviceSelect).val('').trigger('change');
    } else {
        serviceSelect.value = '';
    }
}

function dcmtResolveInventoryOptionsHTML(selectProductText) {
    if (typeof window.inventoryOptionsHTML !== 'undefined' && window.inventoryOptionsHTML && window.inventoryOptionsHTML.trim() !== '') {
        return window.inventoryOptionsHTML;
    }
    if (typeof window.productInventoryOptionsHTML !== 'undefined' && window.productInventoryOptionsHTML.trim() !== '') {
        window.inventoryOptionsHTML = window.productInventoryOptionsHTML;
        return window.inventoryOptionsHTML;
    }
    const firstSelect = document.querySelector('#productItems .product-inventory');
    if (firstSelect) {
        const html = firstSelect.innerHTML;
        window.inventoryOptionsHTML = html;
        return html;
    }
    return `<option value="">${selectProductText}</option>`;
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

// Removed updateProductDoctorVisibility() - Doctor field for product sales has been removed

function syncIncomeType() {
    const form = document.getElementById('incomeForm');
    const mode = determineIncomeMode();
    if (form) {
        form.dataset.incomeMode = mode;
    }
    const typeField = document.getElementById('type');
    if (typeField && typeField.value !== mode) {
        typeField.value = mode;
    }
    // Removed updateProductDoctorVisibility() call - Doctor field has been removed
    if (typeof updateProductFieldsHelp === 'function') {
        updateProductFieldsHelp(mode);
    }
    if (typeof checkAndShowProductPaidAmount === 'function') {
        checkAndShowProductPaidAmount();
    }
}

function ensureProductItemsVisible() {
    const productItems = document.getElementById('productItems');
    if (productItems) {
        productItems.style.display = 'block';
        dcmtEnsureItemsHeader(productItems, productItemsHeaderHtml);
    }
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
        const addServiceText = translations.addService || <?php echo json_encode(trans('income', 'add_service'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
        addServiceBtn.setAttribute('aria-label', addServiceText);
        addServiceBtn.onclick = addServiceItem;
        if (serviceItems.querySelectorAll('.service-item').length === 0) {
            addServiceItem({});
        } else {
            syncIncomeType();
        }
        return;
    }
    
    addServiceItem({});
}

// Make toggleServiceItems globally accessible for inline onclick handlers
window.toggleServiceItems = toggleServiceItems;

function toggleProductItems() {
    const productItems = document.getElementById('productItems');
    const addProductBtn = document.getElementById('addProductBtn');
    
    if (productItems && addProductBtn) {
        // Ensure productItems are visible BEFORE adding item
        ensureProductItemsVisible();
        addProductBtn.setAttribute('aria-label', translations.addProduct);
        addProductBtn.onclick = addProductItem;
        setAddProductButtonLabel(addProductLabelDefault);
        addProductItem();
        
        // Ensure visibility AFTER adding item (in case syncIncomeType or other functions hide it)
        setTimeout(function() {
            ensureProductItemsVisible();
        }, 100);
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
            // Only add a new item if there are no existing items
            const existingItems = noteItems.querySelectorAll('.note-item');
            if (existingItems.length === 0) {
                addNoteItem();
            }
        } else {
            // Section is already visible, just add a new item
            addNoteItem();
        }
        addNoteBtn.setAttribute('aria-label', <?php echo json_encode(trans('income', 'add_note'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>);
        addNoteBtn.onclick = addNoteItem;
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
}

// Make toggleNoteItems globally accessible for inline onclick handlers
window.toggleNoteItems = toggleNoteItems;

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
    const productItemCountCurrent = productItemsContainer.querySelectorAll('.product-item').length;
    // Show "Add Another Product" when there's at least 1 product item (similar to services)
    if (productItemCountCurrent > 0) {
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
}, $income_payment_methods), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const initialIncomePayments = <?php echo json_encode($initial_income_payments_for_js, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;

const dcmtPartialPaymentTranslations = {
    paymentDate: <?php echo json_encode(trans('income', 'payment_date'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    paymentAmount: <?php echo json_encode(trans('income', 'payment_amount'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    paymentMethod: <?php echo json_encode(trans('income', 'payment_method'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    selectPaymentMethod: <?php echo json_encode(trans('income', 'select_payment_method'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    addPayment: <?php echo json_encode(trans('income', 'add_payment'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    noPaymentsRecorded: <?php echo json_encode(trans('income', 'no_payments_recorded'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
    removePayment: <?php echo json_encode(trans('common', 'delete'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
};

const editIncomeId = <?php echo (int) $income_id; ?>;
const markCompleteCsrfToken = <?php echo json_encode($csrf_token, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
const paymentMethodSelectPlaceholder = <?php echo json_encode(trans('income', 'select_payment_method'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
let editPaymentStatusLastValue = null;
let editMarkCompletePreviousStatusId = null;
let editMarkCompleteConfirmed = false;
let editMarkCompleteData = null;

const dcmtInitialPayments = {
    total: initialIncomePayments
};

const dcmtCurrencySymbolClient = <?php echo json_encode($dcmt_currency_symbol, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
const dcmtCurrentDate = <?php echo json_encode(dcmt_get_current_date('Y-m-d'), JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE); ?>;
const defaultCashMethodId = <?php echo $default_cash_method_id ? json_encode($default_cash_method_id) : 'null'; ?>;
const dcmtPaymentConfig = {
    total: {
        containerId: 'totalPaymentsContainer',
        emptyStateId: 'totalPaymentsEmpty',
        amountFieldId: 'total_paid_amount',
        inputName: 'income_payments'
    }
};

function dcmtSyncAmountDisplays() {
    const mappings = [
        { input: 'service_amount', display: 'serviceTotalDisplay' },
        { input: 'product_amount', display: 'productTotalDisplay' },
        { input: 'amount', display: 'grandTotalDisplay' },
        { input: 'total_paid_amount', display: 'totalPaidDisplay' },
        { input: 'total_pending_amount', display: 'totalPendingDisplay' }
    ];
    
    mappings.forEach(({ input, display }) => {
        const inputEl = document.getElementById(input);
        const displayEl = document.getElementById(display);
        if (!inputEl || !displayEl) {
            return;
        }
        const value = parseFloat(inputEl.value) || 0;
        displayEl.textContent = value.toFixed(2);
    });
}

function dcmtAttachAmountSync() {
    const functionsToWrap = ['updateConsultationTotal', 'calculateTotalAmount', 'updatePaymentCalculations'];
    functionsToWrap.forEach(fnName => {
        const originalFn = window[fnName];
        if (typeof originalFn !== 'function' || originalFn.__dcmtWrapped) {
            return;
        }
        const wrappedFn = function(...args) {
            const result = originalFn.apply(this, args);
            dcmtSyncAmountDisplays();
            return result;
        };
        wrappedFn.__dcmtWrapped = true;
        window[fnName] = wrappedFn;
    });
    
    dcmtSyncAmountDisplays();
}

// Make translations available globally for main.js
window.translations = Object.assign({}, window.translations || {}, translations);

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

// Use the global productItemCount from main.js, just set its value
productItemCount = <?php echo count($existing_product_items) ?: 1; ?>;

// Flag to track if user has manually edited consultation fee
let consultationFeeManuallyEdited = false;

// Define resetForm function first
function resetForm() {
    // Get form element
    const form = document.getElementById('incomeForm');
    if (!form) {
        return;
    }
    
    // Disallow saving Pending status when total pending is zero (regardless of original status)
    {
        const totalPendingAmountNow = parseFloat(document.getElementById('total_pending_amount').value) || 0;
        if (currentPaymentStatusId === pendingStatusId && totalPendingAmountNow === 0) {
            e.preventDefault();
            const warningDiv = document.getElementById('payment_status_warning');
            if (warningDiv) warningDiv.style.display = 'block';
            const paymentStatusSelect = document.getElementById('payment_status_id');
            if (paymentStatusSelect) {
                paymentStatusSelect.classList.add('is-invalid');
                paymentStatusSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            return false;
        }
    }

    // Disallow saving Pending for consultation when service pending is zero
    {
        const typeSelect = document.getElementById('type');
        if (typeSelect && typeSelect.value === 'consultation') {
            const servicePending = parseFloat(document.getElementById('service_pending_amount').value) || 0;
            if (currentPaymentStatusId === pendingStatusId && servicePending === 0) {
                e.preventDefault();
                const warningDiv = document.getElementById('payment_status_warning');
                if (warningDiv) warningDiv.style.display = 'block';
                const paymentStatusSelect = document.getElementById('payment_status_id');
                if (paymentStatusSelect) {
                    paymentStatusSelect.classList.add('is-invalid');
                    paymentStatusSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }
        }
    }

    // Disallow saving Pending for product sale when product pending is zero
    {
        const typeSelect = document.getElementById('type');
        if (typeSelect && typeSelect.value === 'product_sale') {
            const productPending = parseFloat(document.getElementById('product_pending_amount').value) || 0;
            if (currentPaymentStatusId === pendingStatusId && productPending === 0) {
                e.preventDefault();
                const warningDiv = document.getElementById('payment_status_warning');
                if (warningDiv) warningDiv.style.display = 'block';
                const paymentStatusSelect = document.getElementById('payment_status_id');
                if (paymentStatusSelect) {
                    paymentStatusSelect.classList.add('is-invalid');
                    paymentStatusSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
                }
                return false;
            }
        }
    }

    // Reset form immediately
    form.reset();
    
    // Reset manual edit flag
    consultationFeeManuallyEdited = false;
    
    // Use setTimeout to ensure form.reset() completes before manual field setting
    setTimeout(() => {
        // Manually clear and set specific fields to ensure proper reset
        const fields = {
            'patient_name': <?php echo json_encode($income['dcmt_patient_name'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            'type': <?php echo json_encode($income['dcmt_type'] ?? 'consultation', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            'description': <?php echo json_encode($income['dcmt_description'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            'doctor_id': '<?php echo isset($income['dcmt_user_id']) ? (int) $income['dcmt_user_id'] : ''; ?>',
            'amount': '<?php echo isset($income['dcmt_amount']) ? number_format((float)$income['dcmt_amount'], 2, '.', '') : '0.00'; ?>',
            'product_paid_amount': '<?php echo ($income['dcmt_type'] === 'product_sale' ? number_format((float)($income['dcmt_product_paid_amount'] ?? $income['dcmt_paid_amount'] ?? 0), 2, '.', '') : '0.00'); ?>',
            'total_paid_amount': '<?php echo number_format((float)($income['dcmt_total_paid_amount'] ?? $income['dcmt_paid_amount'] ?? 0), 2, '.', ''); ?>',
            'total_pending_amount': '<?php echo number_format((float)($income['dcmt_total_pending_amount'] ?? (($income['dcmt_amount'] ?? 0) - ($income['dcmt_paid_amount'] ?? 0))), 2, '.', ''); ?>',
            'payment_mode': <?php echo json_encode($income['dcmt_payment_mode'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            'payment_status': <?php echo json_encode($income['dcmt_payment_status'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>,
            'transaction_date': <?php echo json_encode($income['dcmt_transaction_date'] ?? '', JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>
        };
        
        // Clear each field individually
        Object.keys(fields).forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = fields[fieldId];
                // Trigger change event to ensure any listeners are notified
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        
        // Reset product items to original state
        resetProductItems();
        
        // Update form based on type
        toggleTypeFields();
    }, 10);
}

function resetProductItems() {
    const productItems = document.getElementById('productItems');
    if (!productItems) return;
    
    // Clear existing product items
    productItems.innerHTML = '';
    dcmtEnsureItemsHeader(productItems, productItemsHeaderHtml);
    
        // Add back original product items
        <?php if (!empty($existing_product_items)): ?>
        <?php foreach ($existing_product_items as $index => $item): ?>
        <?php $inventory_missing = empty($item['product_name']); ?>
        const newItem<?php echo $index; ?> = document.createElement('div');
        newItem<?php echo $index; ?>.className = 'product-item row mb-2';
        
        // Build inventory options HTML with proper translations
        let optionsHTML<?php echo $index; ?> = `<option value="">${translations.selectProduct}</option>`;
        <?php foreach ($inventory_items as $inv_item): ?>
        optionsHTML<?php echo $index; ?> += `<option value="<?php echo $inv_item['dcmt_id']; ?>" 
                data-price="<?php echo $inv_item['dcmt_price']; ?>"
                data-stock="<?php echo dcmt_format_quantity_display($inv_item['dcmt_quantity']); ?>"
                data-product-type="<?php echo $inv_item['dcmt_product_type'] ?? 'for_sale'; ?>"
                <?php echo $item['dcmt_inventory_id'] == $inv_item['dcmt_id'] ? 'selected' : ''; ?>>
            <?php echo htmlspecialchars($inv_item['dcmt_name']); ?> 
            (<?php echo trans('income', 'stock'); ?>: <?php echo dcmt_format_quantity_display($inv_item['dcmt_quantity']); ?>)
        </option>`;
        <?php endforeach; ?>
        
        newItem<?php echo $index; ?>.innerHTML = `
            <div class="col-md-4">
                <select class="form-select product-inventory" name="product_items[<?php echo $index; ?>][inventory_id]" onchange="updateProductPrice(this, <?php echo $index; ?>)">
                    ${optionsHTML<?php echo $index; ?>}
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control product-quantity" name="product_items[<?php echo $index; ?>][quantity]" 
                       placeholder="${translations.qty}" min="1" onchange="updateProductTotal(<?php echo $index; ?>)" 
                       value="<?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>">
            </div>
            <div class="col-md-2">
                <div class="dcmt-amount-input-wrapper">
                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                    <input type="number" class="form-control dcmt-amount-input product-price" name="product_items[<?php echo $index; ?>][unit_price]" 
                           placeholder="${translations.price}" onchange="updateProductTotal(<?php echo $index; ?>)" 
                           value="<?php echo isset($item['dcmt_unit_price']) ? htmlspecialchars(number_format((float)$item['dcmt_unit_price'], 2, '.', ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
            </div>
            <div class="col-md-2">
                <div class="dcmt-amount-input-wrapper">
                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                    <input type="text" class="form-control dcmt-amount-input product-total" placeholder="${translations.total}" readonly 
                           value="<?php echo isset($item['dcmt_line_total']) ? htmlspecialchars(number_format((float)$item['dcmt_line_total'], 2, '.', ''), ENT_QUOTES, 'UTF-8') : ''; ?>">
                </div>
            </div>
            <div class="col-md-2 dcmt-delete-cell">
                <button type="button" class="btn btn-outline-danger btn-sm remove-product-btn">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        productItems.appendChild(newItem<?php echo $index; ?>);
        
        // Initialize Select2 on the new select element
        const inventorySelectEl<?php echo $index; ?> = newItem<?php echo $index; ?>.querySelector('.product-inventory');
        const selectInstance<?php echo $index; ?> = $(inventorySelectEl<?php echo $index; ?>).select2({
            placeholder: translations.selectProduct,
            allowClear: true,
            width: '100%'
        });
        dcmtEnsureInventoryOption(inventorySelectEl<?php echo $index; ?>, {
            inventory_id: <?php echo json_encode($item['dcmt_inventory_id']); ?>,
            inventory_missing: <?php echo $inventory_missing ? 'true' : 'false'; ?>,
            inventory_name: <?php echo json_encode($item['product_name'] ?? null); ?>,
            unit_price: <?php echo json_encode(number_format((float)($item['dcmt_unit_price'] ?? 0), 2, '.', '')); ?>,
            product_type: <?php echo json_encode($item['dcmt_product_type'] ?? $item['inventory_product_type'] ?? 'for_sale'); ?>
        });
        selectInstance<?php echo $index; ?>.val('<?php echo $item['dcmt_inventory_id']; ?>').trigger('change');
        <?php endforeach; ?>
        
        // Initialize product type handling after reset
        initializeProductTypeHandling();
        
        // Update product count and button state
        productItemCount = <?php echo count($existing_product_items); ?>;
    const addProductBtn = document.getElementById('addProductBtn');
    if (addProductBtn) {
        updateAddProductButtonLabel();
        addProductBtn.setAttribute('aria-label', translations.addProduct);
        const typeSelect = document.getElementById('type');
        addProductBtn.hidden = !(typeSelect && typeSelect.value === 'product_sale');
    }
    <?php else: ?>
        // Add one empty product item
        const newItem = document.createElement('div');
        newItem.className = 'product-item row mb-2';
        
        // Build inventory options HTML with proper translations
        let optionsHTML = `<option value="">${translations.selectProduct}</option>`;
        <?php foreach ($inventory_items as $item): ?>
        optionsHTML += `<option value="<?php echo $item['dcmt_id']; ?>" 
                data-price="<?php echo $item['dcmt_price']; ?>"
                data-stock="<?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>"
                data-product-type="<?php echo $item['dcmt_product_type'] ?? 'for_sale'; ?>">
            <?php echo htmlspecialchars($item['dcmt_name']); ?> 
            (<?php echo trans('income', 'stock'); ?>: <?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?>)
        </option>`;
        <?php endforeach; ?>
        
        newItem.innerHTML = `
            <div class="col-md-4">
                <select class="form-select product-inventory" name="product_items[0][inventory_id]" onchange="updateProductPrice(this, 0)">
                    ${optionsHTML}
                </select>
            </div>
            <div class="col-md-2">
                <input type="number" class="form-control product-quantity" name="product_items[0][quantity]" 
                       placeholder="${translations.qty}" min="1" value="1" onchange="updateProductTotal(0)">
            </div>
            <div class="col-md-2">
                <div class="dcmt-amount-input-wrapper">
                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                    <input type="number" class="form-control dcmt-amount-input product-price" name="product_items[0][unit_price]" 
                           placeholder="${translations.price}" step="0.01" min="0.01" onchange="updateProductTotal(0)">
                </div>
            </div>
            <div class="col-md-2">
                <div class="dcmt-amount-input-wrapper">
                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                    <input type="text" class="form-control dcmt-amount-input product-total" placeholder="${translations.total}" readonly>
                </div>
            </div>
            <div class="col-md-2 dcmt-delete-cell">
                <button type="button" class="btn btn-outline-danger btn-sm remove-product-btn">
                    <i class="fas fa-trash"></i>
                </button>
            </div>
        `;
        productItems.appendChild(newItem);
        
        // Initialize Select2 on the new select element
        $(newItem.querySelector('.product-inventory')).select2({
            placeholder: translations.selectProduct,
            allowClear: true,
            width: '100%'
        }).val('').trigger('change');  // Explicitly clear any value to ensure it starts empty
        
        // Initialize product type handling after reset
        initializeProductTypeHandling();
        
        // Reset product count and button state
        productItemCount = 1;
        addProductBtn = document.getElementById('addProductBtn');
        if (addProductBtn) {
            updateAddProductButtonLabel();
            addProductBtn.setAttribute('aria-label', translations.addProduct);
            const typeSelect = document.getElementById('type');
            addProductBtn.hidden = !(typeSelect && typeSelect.value === 'product_sale');
        }
    <?php endif; ?>
}

// Initialize payment status on page load - removed to not pre-select payment status

function toggleTypeFields() {
    const typeSelect = document.getElementById('type');
    const addProductBtn = document.getElementById('addProductBtn');
    const paymentAmountsSection = document.getElementById('paymentAmountsSection');
    const productPaidField = document.getElementById('productPaidField');
    const totalPaidField = document.getElementById('totalPaidField');
    const totalPendingField = document.getElementById('totalPendingField');
    const amountInput = document.getElementById('amount');
    
    if (typeSelect.value === 'consultation') {
        // Check if there are existing product items - show product fields if they exist
        const productItems = document.getElementById('productItems');
        const hasProductItems = productItems && productItems.querySelectorAll('.product-item').length > 0;
        
        if (addProductBtn) {
            // Show button if there are existing product items, even for consultation type
            addProductBtn.hidden = !hasProductItems;
            // Update label based on existing items
            updateAddProductButtonLabel();
        }
        
        // Show payment amounts section for consultation
        if (paymentAmountsSection) paymentAmountsSection.style.display = 'block';
        
        if (totalPaidField) totalPaidField.style.display = 'block';
        if (totalPendingField) totalPendingField.style.display = 'block';
        
        // Show product items if they exist
        if (productItems) {
            productItems.style.display = hasProductItems ? 'block' : 'none';
        }
        
        // Check if there are any product items added to show product paid amount
        checkAndShowProductPaidAmount();
        
        // Set service field as required for consultation
        // Service items are now handled via service_items array, no single service_id field
        
        amountInput.readOnly = true;
        removeRequiredFromProductFields();
        updateConsultationTotal();
        
    } else if (typeSelect.value === 'product_sale') {
        if (addProductBtn) {
            addProductBtn.hidden = false;
            updateAddProductButtonLabel();
        }
        
        // Show payment amounts section for product sale
        if (paymentAmountsSection) paymentAmountsSection.style.display = 'block';
        
        if (totalPaidField) totalPaidField.style.display = 'block';
        if (totalPendingField) totalPendingField.style.display = 'block';
        
        amountInput.readOnly = true;
        addRequiredToProductFields();
        calculateTotalAmount();
        
        // Remove required attribute from service field for product sale
        // Service items are now handled via service_items array, no single service_id field
        
    } else {
        // No income type selected - hide all fields
        if (addProductBtn) {
            addProductBtn.hidden = true;
            setAddProductButtonLabel(addProductLabelDefault);
        }
        if (paymentAmountsSection) paymentAmountsSection.style.display = 'none';
        if (productPaidField) productPaidField.style.display = 'none';
        if (totalPaidField) totalPaidField.style.display = 'none';
        if (totalPendingField) totalPendingField.style.display = 'none';
        
        amountInput.readOnly = false;
        removeRequiredFromProductFields();
        
        // Remove required attribute from service field when no type is selected
        // Service items are now handled via service_items array, no single service_id field
    }
    
    // Update payment calculations when fields change
    updatePaymentCalculations();
}

// Function to check if there are product items and show product amounts row accordingly
function checkAndShowProductPaidAmount() {
    const productAmountsRow = document.getElementById('productAmountsRow');
    const typeSelect = document.getElementById('type');
    
    // Only check for consultation type
    if (typeSelect.value !== 'consultation') {
        return;
    }
    
    // Check if there are any valid product items
    let hasProductItems = false;
    document.querySelectorAll('.product-inventory').forEach(select => {
        if (select.value && select.value !== '') {
            hasProductItems = true;
        }
    });
    
    // Show or hide product amounts row based on whether there are product items
    if (productAmountsRow) {
        productAmountsRow.style.display = hasProductItems ? 'block' : 'none';
    }
}

function updateConsultationFee() {
    // Consultation fee field removed - this function is kept for compatibility
    updateConsultationTotal();
}

// updateConsultationTotal is now centralized in main.js

// Payment calculation functions (calculateProductTotal, updatePaymentCalculations, updatePaymentStatusFromAmounts)
// are now centralized in main.js to avoid code duplication

function updatePaymentStatus() {
    const paymentMode = document.getElementById('payment_mode').value;
    const paymentStatus = document.getElementById('payment_status');
    
    if (paymentMode === 'cash') {
        paymentStatus.value = 'completed';
    } else if (paymentMode === 'online') {
        paymentStatus.value = 'pending';
    }
}


function addProductItem(data = {}) {
    ensureProductItemsVisible();
    addProductItemWithSelect2(data);
}

function removeProductItem(button) {
    // Use the Select2-compatible version
    removeProductItemWithSelect2(button);
}

function updateProductPrice(select, index) {
    // Use the Select2-compatible version
    updateProductPriceWithSelect2(select, index);
}

function updateProductTotal(index) {
    const item = document.querySelectorAll('.product-item')[index];
    if (!item) return;
    
    const quantityInput = item.querySelector('.product-quantity');
    const priceInput = item.querySelector('.product-price');
    const totalInput = item.querySelector('.product-total');
    const inventorySelect = item.querySelector('.product-inventory');
    
    // Check if this is a "for use" item
    let isForUseItem = false;
    if (inventorySelect) {
        let selectedOption;
        if (typeof $ !== 'undefined' && $(inventorySelect).hasClass('select2-hidden-accessible')) {
            selectedOption = $(inventorySelect).find('option:selected');
        } else {
            const selectedIndex = inventorySelect.selectedIndex;
            if (selectedIndex > 0) {
                selectedOption = inventorySelect.options[selectedIndex];
            }
        }
        
        if (selectedOption && selectedOption.length) {
            const productType = selectedOption.attr('data-product-type') || selectedOption.getAttribute('data-product-type');
            isForUseItem = (productType === 'for_use');
        }
    }
    
    if (isForUseItem) {
        // For "for use" items, set total to 0
        if (totalInput) {
            totalInput.value = '0.00';
        }
    } else {
        // For "for sale" items, calculate normally
        const quantity = parseFloat(quantityInput.value) || 0;
        const price = parseFloat(priceInput.value) || 0;
        const total = quantity * price;
        
        if (totalInput) {
            totalInput.value = total.toFixed(2);
        }
    }
    
    // Validate quantity against stock
    if (quantityInput) {
        validateProductQuantity(quantityInput);
    }
    
    const typeSelect = document.getElementById('type');
    if (typeSelect.value === 'consultation') {
        updateConsultationTotal();
    } else if (typeSelect.value === 'product_sale') {
        calculateTotalAmount();
    }
}

function calculateTotalAmount() {
    let total = 0;
    document.querySelectorAll('.product-total').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    
    document.getElementById('amount').value = total.toFixed(2);
    
    // Update Product Amount field with the total
    const productAmountInput = document.getElementById('product_amount');
    if (productAmountInput) {
        productAmountInput.value = total.toFixed(2);
        // Trigger payment calculations to update pending amount
        updatePaymentCalculations();
    }
}

// Validate product quantity against available stock
function validateProductQuantity(quantityInput) {
    const productItem = quantityInput.closest('.product-item');
    const inventorySelect = productItem.querySelector('.product-inventory');
    const quantity = parseFloat(quantityInput.value) || 0;
    
    if (inventorySelect && quantity > 0) {
        let selectedOption;
        let inventoryId;
        
        // Check if using Select2
        if (typeof $ !== 'undefined' && $(inventorySelect).hasClass('select2-hidden-accessible')) {
            selectedOption = $(inventorySelect).find('option:selected');
            if (selectedOption.length) {
                inventoryId = selectedOption.val();
                const stock = parseInt(selectedOption.attr('data-stock')) || 0;
                
                // Calculate effective stock (current stock + current quantity from this income)
                const effectiveStock = calculateEffectiveStockForContext(inventoryId, stock);
                
                if (quantity > effectiveStock) {
                    showStockValidationError(quantityInput, effectiveStock);
                    return false;
                } else {
                    clearStockValidationError(quantityInput);
                    return true;
                }
            }
        } else {
            // Regular select element
            const selectedIndex = inventorySelect.selectedIndex;
            if (selectedIndex > 0) {
                const selectedOption = inventorySelect.options[selectedIndex];
                inventoryId = selectedOption.value;
                const stock = parseInt(selectedOption.getAttribute('data-stock')) || 0;
                
                // Calculate effective stock (current stock + current quantity from this income)
                const effectiveStock = calculateEffectiveStockForContext(inventoryId, stock);
                
                if (quantity > effectiveStock) {
                    showStockValidationError(quantityInput, effectiveStock);
                    return false;
                } else {
                    clearStockValidationError(quantityInput);
                    return true;
                }
            }
        }
    }
    
    clearStockValidationError(quantityInput);
    return true;
}

// Show stock validation error
function showStockValidationError(quantityInput, availableStock) {
    const productItem = quantityInput.closest('.product-item');
    let errorDiv = productItem.querySelector('.stock-validation-error');
    
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'stock-validation-error text-danger small mt-1';
        quantityInput.parentNode.appendChild(errorDiv);
    }
    
    errorDiv.textContent = `Available stock: ${availableStock}`;
    quantityInput.classList.add('is-invalid');
}

// Clear stock validation error
function clearStockValidationError(quantityInput) {
    const productItem = quantityInput.closest('.product-item');
    const errorDiv = productItem.querySelector('.stock-validation-error');
    
    if (errorDiv) {
        errorDiv.remove();
    }
    
    quantityInput.classList.remove('is-invalid');
}

function removeRequiredFromProductFields() {
    document.querySelectorAll('.product-inventory, .product-quantity, .product-price').forEach(field => {
        field.removeAttribute('required');
    });
    // Update help text for consultation
    const productItemsHelp = document.getElementById('productItemsHelp');
    const productItemsOptional = document.getElementById('productItemsOptional');
    
    if (productItemsHelp) productItemsHelp.style.display = 'none';
    if (productItemsOptional) productItemsOptional.style.display = 'block';
}

function addRequiredToProductFields() {
    // Only set required on inventory and quantity fields, not price fields
    document.querySelectorAll('.product-inventory, .product-quantity').forEach(field => {
        field.setAttribute('required', 'required');
    });
    // Update help text for product sale
    const productItemsHelp = document.getElementById('productItemsHelp');
    const productItemsOptional = document.getElementById('productItemsOptional');
    
    if (productItemsHelp) productItemsHelp.style.display = 'block';
    if (productItemsOptional) productItemsOptional.style.display = 'none';
}

// Flag to prevent consultation fee modification during product loading
let isLoadingProducts = false;

// Function to update service item amount when service is selected
function updateServiceItemAmount(select, index) {
    const serviceItem = select.closest('.service-item');
    const amountInput = serviceItem ? serviceItem.querySelector('.service-amount') : null;
    
    if (!amountInput) {
        return;
    }

    // If preserveInitial is set, keep the existing value (stored amount from database)
    if (amountInput.dataset && amountInput.dataset.preserveInitial === 'true') {
        // Don't delete preserveInitial - keep it so the stored amount is always preserved
        updateServiceItemTotal(index);
        updateServiceTotal();
        return;
    }
    
    // If amount field already has a value (manually entered or previously set), preserve it
    const currentAmount = amountInput.value.trim();
    if (currentAmount !== '' && parseFloat(currentAmount) > 0) {
        // Amount already exists, preserve it
        updateServiceItemTotal(index);
        updateServiceTotal();
        return;
    }
    
    // Only update amount if field is empty - use default service price
    // Handle Select2 dropdowns
    let selectedOption = null;
    let servicePrice = null;
    
    if (typeof $ !== 'undefined' && $(select).hasClass('select2-hidden-accessible')) {
        // Use Select2 method to get selected option
        const $selected = $(select).find('option:selected');
        if ($selected.length > 0 && $selected.val()) {
            selectedOption = $selected[0];
            servicePrice = $selected.attr('data-price');
        }
    } else {
        // Regular select element
        selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value) {
            servicePrice = selectedOption.getAttribute('data-price');
        }
    }
    
    if (selectedOption && selectedOption.value && servicePrice) {
        amountInput.value = parseFloat(servicePrice).toFixed(2);
        // Trigger change event on amount input to ensure any listeners are notified
        amountInput.dispatchEvent(new Event('input', { bubbles: true }));
        amountInput.dispatchEvent(new Event('change', { bubbles: true }));
        updateServiceItemTotal(index);
        updateServiceTotal(); // Update overall service amount
    } else {
        amountInput.value = '';
        updateServiceItemTotal(index);
        updateServiceTotal(); // Update overall service amount
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
            // Parse values, removing any formatting (commas, etc.)
            const quantity = parseFloat(quantityInput.value.toString().replace(/,/g, '')) || 0;
            const amount = parseFloat(amountInput.value.toString().replace(/,/g, '')) || 0;
            const total = quantity * amount;
            totalInput.value = total.toFixed(2);
        }
    }
}

// Function to update individual service item total (can be called from inline handlers)
function updateServiceItemTotalByIndex(index) {
    updateServiceItemTotal(index);
}

// Make updateServiceItemTotalByIndex globally accessible for inline handlers
window.updateServiceItemTotalByIndex = updateServiceItemTotalByIndex;

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
        // First update the individual item total
        updateServiceItemTotal(index);
        
        // Calculate from quantity and amount directly (more reliable than reading total field)
        const quantityInput = item.querySelector('.service-quantity');
        const amountInput = item.querySelector('.service-amount');
        const totalInput = item.querySelector('.service-total');
        
        if (quantityInput && amountInput) {
            const quantity = parseFloat(quantityInput.value.toString().replace(/,/g, '')) || 0;
            const amount = parseFloat(amountInput.value.toString().replace(/,/g, '')) || 0;
            const itemTotal = quantity * amount;
            
            // Update the total field if it exists
            if (totalInput) {
                totalInput.value = itemTotal.toFixed(2);
            }
            
            totalServiceAmount += itemTotal;
        } else if (totalInput && totalInput.value) {
            // Fallback: use total field if quantity/amount inputs not found
            const totalValue = parseFloat(totalInput.value.toString().replace(/,/g, '')) || 0;
            totalServiceAmount += totalValue;
        }
    });
    
    // Update service_amount field without triggering events
    const serviceAmountInput = document.getElementById('service_amount');
    if (serviceAmountInput) {
        // Remove event listeners temporarily to prevent recursion
        const currentValue = serviceAmountInput.value;
        serviceAmountInput.value = totalServiceAmount.toFixed(2);
        
        // Update service amount display field directly
        const serviceDisplay = document.getElementById('serviceTotalDisplay');
        if (serviceDisplay) {
            serviceDisplay.textContent = totalServiceAmount.toFixed(2);
        }
        
        // Also sync all amount displays using the sync function
        if (typeof dcmtSyncAmountDisplays === 'function') {
            dcmtSyncAmountDisplays();
        } else if (typeof updateIncomeAmountDisplays === 'function') {
            updateIncomeAmountDisplays();
        }
        
        // Only trigger update if value actually changed
        if (currentValue !== serviceAmountInput.value) {
            // Show service amounts row if there's a total
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
    } else {
        // If service_amount input doesn't exist, still update display
        const serviceDisplay = document.getElementById('serviceTotalDisplay');
        if (serviceDisplay) {
            serviceDisplay.textContent = totalServiceAmount.toFixed(2);
        }
    }
    
    isUpdatingServiceTotal = false;
    window.isUpdatingServiceTotal = false;
}

// Make updateServiceTotal globally accessible
window.updateServiceTotal = updateServiceTotal;

// Setup service item event delegation
function setupServiceItemEventDelegation() {
    const serviceItemsContainer = document.getElementById('serviceItems');
    if (serviceItemsContainer) {
        // Handle doctor selection changes - listen for both native change and Select2 select events
        $(serviceItemsContainer).on('change.select2-doctor select2:select.select2-doctor', '.service-doctor-select', function() {
            const serviceItems = serviceItemsContainer.querySelectorAll('.service-item');
            const index = Array.from(serviceItems).indexOf(this.closest('.service-item'));
            handleServiceDoctorChange(this, index);
            syncPrimaryDoctorFromServices();
        });
        
        // Handle service selection changes (using Select2 events and native change)
        // Listen for both native change and Select2 select events
        $(serviceItemsContainer).on('change.select2-service select2:select.select2-service', '.service-select', function() {
            const index = Array.from(serviceItemsContainer.querySelectorAll('.service-item')).indexOf(this.closest('.service-item'));
            updateServiceItemAmount(this, index);
            updateServiceTotal();
        });
        
        // Handle quantity and amount changes for auto-calculation
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
        
        // Also handle change events for quantity and amount (for when fields lose focus)
        serviceItemsContainer.addEventListener('change', function(event) {
            if (event.target.classList.contains('service-quantity') || event.target.classList.contains('service-amount')) {
                const serviceItems = serviceItemsContainer.querySelectorAll('.service-item');
                const index = Array.from(serviceItems).indexOf(event.target.closest('.service-item'));
                if (index >= 0) {
                    updateServiceItemTotal(index);
                    updateServiceTotal();
                }
            }
        });
        
        // Handle remove button clicks - use event delegation
        // Handle clicks on button or icon inside button
        serviceItemsContainer.addEventListener('click', function(event) {
            // Check if click is on button or icon inside button
            const removeBtn = event.target.closest('.remove-service-btn');
            if (removeBtn) {
                event.preventDefault();
                event.stopPropagation();
                event.stopImmediatePropagation();
                
                // Ensure removeServiceItem function is available
                if (typeof window.removeServiceItem === 'function') {
                    window.removeServiceItem(removeBtn);
                } else if (typeof removeServiceItem === 'function') {
                    removeServiceItem(removeBtn);
                } else {
                    console.error('removeServiceItem function is not defined');
                }
                return false;
            }
        }, true); // Use capture phase to catch events earlier
        
        // Also use jQuery event delegation as backup
        if (typeof $ !== 'undefined') {
            $(serviceItemsContainer).off('click', '.remove-service-btn').on('click', '.remove-service-btn', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                const button = this;
                if (typeof window.removeServiceItem === 'function') {
                    window.removeServiceItem(button);
                } else if (typeof removeServiceItem === 'function') {
                    removeServiceItem(button);
                }
                return false;
            });
            
            // Handle clicks on the icon inside the button
            $(serviceItemsContainer).off('click', '.remove-service-btn i, .remove-service-btn svg').on('click', '.remove-service-btn i, .remove-service-btn svg', function(e) {
                e.preventDefault();
                e.stopPropagation();
                e.stopImmediatePropagation();
                const removeBtn = $(this).closest('.remove-service-btn')[0];
                if (removeBtn) {
                    if (typeof window.removeServiceItem === 'function') {
                        window.removeServiceItem(removeBtn);
                    } else if (typeof removeServiceItem === 'function') {
                        removeServiceItem(removeBtn);
                    }
                }
                return false;
            });
        }
    }
}

// updateServiceAmount function is now centralized in main.js

// Initialize on page load
document.addEventListener('DOMContentLoaded', function() {
    // Initialize form change detection (check if function exists from main.js)
    if (typeof dcmtInitFormChangeDetection === 'function') {
        dcmtInitFormChangeDetection('incomeForm');
    }
    
    const addPaymentBtn = document.getElementById('addTotalPaymentBtn');
    if (addPaymentBtn && typeof dcmtAddPaymentRow === 'function') {
        addPaymentBtn.addEventListener('click', function() {
            const target = this.getAttribute('data-payment-target') || 'total';
            dcmtAddPaymentRow(target);
        });
    }
    
    // Initialize payment calculations
    updatePaymentCalculations();
    // Do not auto-change payment status on initial load; keep stored value

    const serviceItemsContainer = document.getElementById('serviceItems');
    if (serviceItemsContainer) {
        // Setup event delegation BEFORE clearing/rendering items
        setupServiceItemEventDelegation();
        
        serviceItemsContainer.innerHTML = '';
        dcmtEnsureItemsHeader(serviceItemsContainer, serviceItemsHeaderHtml);
        const serviceItemsToRender = Array.isArray(initialServiceItems) ? initialServiceItems : [];
        // Filter to only include stored records with service_id
        const storedServiceItems = serviceItemsToRender.filter(item => item && item.service_id);
        if (storedServiceItems.length > 0) {
            serviceItemsContainer.style.display = 'block';
            storedServiceItems.forEach(item => addServiceItem(item));
            syncPrimaryDoctorFromServices();
            // Update service total after all services are loaded
            setTimeout(function() {
                if (typeof updateServiceTotal === 'function') {
                    updateServiceTotal();
                }
            }, 200);
        } else {
            serviceItemsContainer.style.display = 'none';
            syncPrimaryDoctorFromServices();
        }
        
        // Re-setup event delegation after items are rendered to ensure handlers are attached
        setTimeout(function() {
            setupServiceItemEventDelegation();
        }, 100);
    }
    
    const productItemsContainer = document.getElementById('productItems');
    if (productItemsContainer) {
        dcmtEnsureItemsHeader(productItemsContainer, productItemsHeaderHtml);
        const existingProductItems = productItemsContainer.querySelectorAll('.product-item');
        if (existingProductItems.length > 0) {
            // Initialize existing PHP-rendered product items (Select2, event handlers, etc.)
            ensureProductItemsVisible();
            productItemsContainer.style.display = 'block';
            existingProductItems.forEach((item, index) => {
                const inventorySelect = item.querySelector('.product-inventory');
                if (inventorySelect) {
                    // Initialize Select2 if not already initialized
                    if (!$(inventorySelect).hasClass('select2-hidden-accessible')) {
                        $(inventorySelect).select2({
                            placeholder: translations.selectProduct,
                            allowClear: true,
                            width: '100%'
                        });
                    }
                    
                    // Handle missing inventory items (similar to resetProductItems function)
                    const selectedValue = inventorySelect.value;
                    if (selectedValue && typeof dcmtEnsureInventoryOption === 'function') {
                        const productItemData = <?php echo json_encode(array_map(function($item) {
                            return [
                                'inventory_id' => $item['inventory_id'],
                                'inventory_missing' => $item['inventory_missing'] ?? false,
                                'inventory_name' => $item['inventory_name'],
                                'unit_price' => $item['unit_price'] ?? '',
                                'product_type' => $item['product_type'] ?? 'for_sale'
                            ];
                        }, $product_items_view)); ?>;
                        if (productItemData[index]) {
                            dcmtEnsureInventoryOption(inventorySelect, productItemData[index]);
                        }
                    }
                }
            });
            // Update product item count
            productItemCount = existingProductItems.length;
            updateAddProductButtonLabel();
        } else {
            // No existing items, render from JavaScript data if available
            const productItemsToRender = Array.isArray(initialProductItems)
                ? initialProductItems.filter(item => (
                    (item.inventory_id !== null && item.inventory_id !== undefined && item.inventory_id !== '') ||
                    (item.quantity !== undefined && item.quantity !== '') ||
                    (item.unit_price !== undefined && item.unit_price !== '')
                ))
                : [];
            
            if (productItemsToRender.length > 0) {
                ensureProductItemsVisible();
                productItemsContainer.style.display = 'block';
                productItemsToRender.forEach(item => addProductItem(item));
            } else {
                productItemsContainer.style.display = 'none';
            }
        }
    } else if (Array.isArray(initialProductItems) && initialProductItems.length) {
        ensureProductItemsVisible();
        initialProductItems.forEach(item => addProductItem(item));
        const productItems = document.getElementById('productItems');
        if (productItems) {
            productItems.style.display = 'block';
        }
    }
    
    // Initialize note items
    const noteItemsContainer = document.getElementById('noteItems');
    if (noteItemsContainer) {
        if (Array.isArray(initialNoteItems) && initialNoteItems.length > 0) {
            noteItemsContainer.style.display = 'block';
            dcmtEnsureItemsHeader(noteItemsContainer, noteItemsHeaderHtml);
            const addNoteBtn = document.getElementById('addNoteBtn');
            if (addNoteBtn) {
                addNoteBtn.onclick = addNoteItem;
            }
            initialNoteItems.forEach(item => addNoteItem(item));
        } else {
            noteItemsContainer.style.display = 'none';
        }
    }
    
    // Attach remove handlers to existing note items
    if (noteItemsContainer) {
        const existingNoteItems = noteItemsContainer.querySelectorAll('.remove-note-btn');
        existingNoteItems.forEach(btn => {
            btn.addEventListener('click', function() {
                const noteItem = this.closest('.note-item');
                if (noteItem) {
                    noteItem.remove();
                    // Renumber remaining items
                    const remainingItems = noteItemsContainer.querySelectorAll('.note-item');
                    remainingItems.forEach((item, index) => {
                        const topicInput = item.querySelector('.note-topic');
                        const noteTextArea = item.querySelector('.note-text');
                        if (topicInput) topicInput.name = `note_items[${index}][topic]`;
                        if (noteTextArea) noteTextArea.name = `note_items[${index}][note_text]`;
                    });
                }
            });
        });
    }
    
    initializeProductTypeHandling();
    syncIncomeType();
    dcmtAttachAmountSync();
    
    // Monitor payment amount changes to clear warnings
    const totalPendingAmountField = document.getElementById('total_pending_amount');
    const totalPaidAmountField = document.getElementById('total_paid_amount');
    const totalAmountField = document.getElementById('amount');
    
    if (totalPendingAmountField) {
        totalPendingAmountField.addEventListener('input', function() {
            const pendingWarningDiv = document.getElementById('pending_amount_warning');
            const paymentStatusWarningDiv = document.getElementById('payment_status_warning');
            const pendingAmount = parseFloat(this.value) || 0;
            
            // Clear pending amount warning if valid
            if (pendingAmount >= 0 && pendingWarningDiv) {
                pendingWarningDiv.style.display = 'none';
                this.classList.remove('is-invalid');
            }
            
            // If pending is zero, auto-set status to completed
            if (pendingAmount === 0) {
                if (typeof dcmtSyncPaymentStatusWithPending === 'function') {
                    dcmtSyncPaymentStatusWithPending();
                }
            }

            // If pending amount becomes greater than zero, clear payment status warning
            if (pendingAmount > 0 && paymentStatusWarningDiv) {
                paymentStatusWarningDiv.style.display = 'none';
                paymentStatusWarningDiv.classList.remove('d-block');
                const paymentStatusSelect = document.getElementById('payment_status_id');
                if (paymentStatusSelect) {
                    paymentStatusSelect.classList.remove('is-invalid');
                }
            }
        });
    }
    
    // Also monitor service_paid_amount and product_paid_amount changes to clear warning
    const servicePaidAmountField = document.getElementById('service_paid_amount');
    const productPaidAmountField = document.getElementById('product_paid_amount');
    
    function checkAndClearPaymentStatusWarning() {
        const paymentStatusWarningDiv = document.getElementById('payment_status_warning');
        const totalPendingAmountField = document.getElementById('total_pending_amount');
        const paymentStatusSelect = document.getElementById('payment_status_id');
        const pendingStatusId = document.getElementById('pending_status_id') ? document.getElementById('pending_status_id').value : null;
        
        if (paymentStatusWarningDiv && totalPendingAmountField && paymentStatusSelect) {
            const totalPendingAmount = parseFloat(totalPendingAmountField.value) || 0;
            const currentPaymentStatusId = paymentStatusSelect.value;
            
            // If pending amount has a value (> 0) and status is pending, clear the warning
            if (totalPendingAmount > 0 && currentPaymentStatusId === pendingStatusId) {
                paymentStatusWarningDiv.style.display = 'none';
                paymentStatusWarningDiv.classList.remove('d-block');
                paymentStatusSelect.classList.remove('is-invalid');
            }
        }
    }
    
    if (servicePaidAmountField) {
        servicePaidAmountField.addEventListener('change', checkAndClearPaymentStatusWarning);
        servicePaidAmountField.addEventListener('input', checkAndClearPaymentStatusWarning);
    }
    
    if (productPaidAmountField) {
        productPaidAmountField.addEventListener('change', checkAndClearPaymentStatusWarning);
        productPaidAmountField.addEventListener('input', checkAndClearPaymentStatusWarning);
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
    
    // Get form elements
    const form = document.getElementById('incomeForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !resetBtn) {
        return;
    }
    
    toggleTypeFields();
    
    // Initialize field visibility based on current type selection
    // Add a small delay to ensure all DOM elements are ready
    setTimeout(function() {
        toggleTypeFields();
        
        // Initialize product type handling for existing selections
        if (typeof initializeProductTypeHandling === 'function') {
            initializeProductTypeHandling();
        }
        
        // Setup product item event delegation
        if (typeof setupProductItemEventDelegation === 'function') {
            setupProductItemEventDelegation();
        }
        
        // Setup service item event delegation
        setupServiceItemEventDelegation();
        
        syncPrimaryDoctorFromServices();
    }, 100);
    
    // Add form submission handler
    if (form) {
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
                        const quantityInput = item.querySelector('.service-quantity');
                        const amountInput = item.querySelector('.service-amount');
                        
                        // Check if this item is completely empty
                        const isEmpty = (!serviceSelect || !serviceSelect.value || serviceSelect.value === '') &&
                                       (!quantityInput || !quantityInput.value || quantityInput.value === '') &&
                                       (!amountInput || !amountInput.value || amountInput.value === '');
                        
                        if (isEmpty) {
                            // Disable empty fields so they don't get submitted
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
                            if (serviceSelect) {
                                serviceSelect.name = 'service_items[' + validIndex + '][service_id]';
                            }
                            if (quantityInput) {
                                quantityInput.name = 'service_items[' + validIndex + '][quantity]';
                            }
                            if (amountInput) {
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
                
                // Step 4: Submit form (use small delay to ensure DOM updates are complete)
                setTimeout(function() {
                    // Clear form changed flag and submit
                    if (typeof dcmtClearFormChanged === 'function') {
                        dcmtClearFormChanged();
                    }
                    
                    // Show loading state on submit button
                    if (submitBtn) {
                        const originalText = submitBtn.innerHTML;
                        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
                        submitBtn.disabled = true;
                        submitBtn.setAttribute('data-original-text', originalText);
                    }
                    
                    // Submit the form
                    form.submit();
                }, 50);
            } else {
                // For non-consultation types, submit immediately
                // Clear form changed flag and submit
                if (typeof dcmtClearFormChanged === 'function') {
                    dcmtClearFormChanged();
                }
                
                // Show loading state on submit button
                if (submitBtn) {
                    const originalText = submitBtn.innerHTML;
                    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
                    submitBtn.disabled = true;
                    submitBtn.setAttribute('data-original-text', originalText);
                }
                
                // Submit the form
                form.submit();
            }
        });
    }
    
    // Mark service amount as manually edited if it has a non-zero value from database
    // Update consultation total to ensure amount field is correct
    updateConsultationTotal();
    
    // Product items visibility - show if type is product_sale or if there are stored product items
    // This check runs after toggleTypeFields to ensure visibility is correct
    setTimeout(function() {
        const productItems = document.getElementById('productItems');
        
        if (productItems) {
            const hasProductItems = productItems.querySelectorAll('.product-item').length > 0;
            const shouldShow = (typeSelect.value === 'product_sale' || typeSelect.value === 'mixed' || hasProductItems);
            
            if (shouldShow) {
                productItems.style.display = 'block';
            }
        }
    }, 150);
    
    // Initialize "Add Product" button text based on existing products
    // Use setTimeout to ensure this runs after toggleTypeFields completes
    setTimeout(function() {
        const addProductBtn = document.getElementById('addProductBtn');
        if (addProductBtn) {
            // Check if there are existing product items - update label accordingly
            const productItems = document.getElementById('productItems');
            const hasProductItems = productItems && productItems.querySelectorAll('.product-item').length > 0;
            
            if (typeSelect.value === 'product_sale') {
                updateAddProductButtonLabel();
            } else if (hasProductItems) {
                // For consultation/mixed type with product items, show "Add Another Product"
                updateAddProductButtonLabel();
            } else {
                setAddProductButtonLabel(addProductLabelDefault);
            }
            addProductBtn.setAttribute('aria-label', translations.addProduct);
            addProductBtn.hidden = (typeSelect.value !== 'product_sale' && !hasProductItems);
        }
    }, 100);
    
    // Ensure required attributes are set correctly on initial load
    if (typeSelect.value === 'product_sale') {
        addRequiredToProductFields();
    } else if (typeSelect.value === 'consultation') {
        removeRequiredFromProductFields();
    }
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        resetForm();
    });
});

// Add form submission loading state
document.getElementById('incomeForm').addEventListener('submit', function(e) {
    // Validate all product quantities before submission
    const productItems = document.querySelectorAll('.product-item');
    let hasValidationErrors = false;
    
    productItems.forEach(item => {
        const quantityInput = item.querySelector('.product-quantity');
        if (quantityInput && !validateProductQuantity(quantityInput)) {
            hasValidationErrors = true;
        }
    });
    
    if (hasValidationErrors) {
        e.preventDefault();
        alert('Please fix stock validation errors before submitting the form.');
        return false;
    }
    
    // Validate payment status change from completed to pending
    const originalPaymentStatusId = document.getElementById('original_payment_status_id').value;
    const currentPaymentStatusId = document.getElementById('payment_status_id').value;
    const completedStatusId = document.getElementById('completed_status_id').value;
    const pendingStatusId = document.getElementById('pending_status_id').value;
    
    // Check if status is being changed from completed to pending
    if (originalPaymentStatusId === completedStatusId && currentPaymentStatusId === pendingStatusId) {
        // Get total pending amount
        const totalPendingAmount = parseFloat(document.getElementById('total_pending_amount').value) || 0;
        
        // If pending amount is not greater than zero, prevent submission
        if (totalPendingAmount <= 0) {
            e.preventDefault();
            
            // Show warning message
            const warningDiv = document.getElementById('payment_status_warning');
            if (warningDiv) { warningDiv.style.display = 'block'; warningDiv.classList.add('d-block'); }
            
            // Add error styling to the payment status field
            const paymentStatusSelect = document.getElementById('payment_status_id');
            if (paymentStatusSelect) {
                paymentStatusSelect.classList.add('is-invalid');
                // Scroll to the field
                paymentStatusSelect.scrollIntoView({ behavior: 'smooth', block: 'center' });
            }
            
            return false;
        }
    }
    
    // Validate pending amount is not negative
    const totalPendingAmount = parseFloat(document.getElementById('total_pending_amount').value) || 0;
    if (totalPendingAmount < 0) {
        e.preventDefault();
        
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
        e.preventDefault();
        
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
    
    // Clear form changed flag since we're submitting
    dcmtClearFormChanged();
    
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.innerHTML;
    
    // Show loading state
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
    submitBtn.disabled = true;
    
    // Store original text for potential restoration
    submitBtn.setAttribute('data-original-text', originalText);
});

// Restore button state if form validation fails
document.addEventListener('DOMContentLoaded', function() {
    const submitBtn = document.getElementById('submitBtn');
    const originalText = submitBtn.getAttribute('data-original-text');
    
    if (originalText) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});

function handlePaymentStatusChange() {
    const paymentStatusSelect = document.getElementById('payment_status_id');
    if (!paymentStatusSelect) {
        return;
    }
    const warningDiv = document.getElementById('payment_status_warning');
    if (warningDiv) {
        warningDiv.style.display = 'none';
        warningDiv.classList.remove('d-block');
    }
    const completedStatusIdEl = document.getElementById('completed_status_id');
    const originalPaymentStatusIdEl = document.getElementById('original_payment_status_id');
    const pendingStatusIdEl = document.getElementById('pending_status_id');
    if (!completedStatusIdEl || !originalPaymentStatusIdEl || !pendingStatusIdEl) {
        return;
    }
    const completedStatusId = completedStatusIdEl.value;
    const originalPaymentStatusId = originalPaymentStatusIdEl.value;
    const pendingStatusId = pendingStatusIdEl.value;
    if (editPaymentStatusLastValue === null) {
        editPaymentStatusLastValue = originalPaymentStatusId;
    }
    const previousStatusId = editPaymentStatusLastValue;
    const currentPaymentStatusId = paymentStatusSelect.value;
    if (currentPaymentStatusId === completedStatusId) {
        const totalPendingAmount = parseFloat(document.getElementById('total_pending_amount').value) || 0;
        if (totalPendingAmount <= 0) {
            if (warningDiv) {
                warningDiv.style.display = 'none';
                warningDiv.classList.remove('d-block');
            }
            paymentStatusSelect.classList.remove('is-invalid');
            editPaymentStatusLastValue = currentPaymentStatusId;
            return;
        }
        editMarkCompletePreviousStatusId = previousStatusId;
        editMarkCompleteConfirmed = false;
        markPaymentCompleteFromEdit();
        return;
    }
    if (originalPaymentStatusId === pendingStatusId && currentPaymentStatusId !== pendingStatusId) {
        if (warningDiv) {
            warningDiv.style.display = 'none';
            warningDiv.classList.remove('d-block');
        }
        paymentStatusSelect.classList.remove('is-invalid');
        editPaymentStatusLastValue = currentPaymentStatusId;
        return;
    }
    if (previousStatusId === completedStatusId && currentPaymentStatusId === pendingStatusId) {
        const totalPendingAmount = parseFloat(document.getElementById('total_pending_amount').value) || 0;
        if (totalPendingAmount <= 0 && warningDiv) {
            warningDiv.style.display = 'block';
            warningDiv.classList.add('d-block');
            paymentStatusSelect.classList.add('is-invalid');
        } else if (warningDiv) {
            warningDiv.style.display = 'none';
            warningDiv.classList.remove('d-block');
            paymentStatusSelect.classList.remove('is-invalid');
        }
    } else {
        paymentStatusSelect.classList.remove('is-invalid');
        if (warningDiv) {
            warningDiv.style.display = 'none';
            warningDiv.classList.remove('d-block');
        }
    }
    editPaymentStatusLastValue = currentPaymentStatusId;
}

// Update paid amounts when payment is marked as complete
function updatePaidAmountsToComplete() {
    const totalAmount = parseFloat(document.getElementById('amount').value) || 0;
    const incomeType = document.getElementById('type').value;
    
    if (incomeType === 'consultation') {
        // For consultation, add pending amount to paid amount
        const currentServicePaidAmount = parseFloat(document.getElementById('service_paid_amount').value) || 0;
        const servicePendingAmount = parseFloat(document.getElementById('service_pending_amount').value) || 0;
        const newServicePaidAmount = currentServicePaidAmount + servicePendingAmount;
        
        document.getElementById('service_paid_amount').value = newServicePaidAmount.toFixed(2);
        document.getElementById('service_pending_amount').value = '0.00';
        
        // For consultation, set total paid to full amount and total pending to 0
        document.getElementById('total_paid_amount').value = totalAmount.toFixed(2);
        document.getElementById('total_pending_amount').value = '0.00';
        
    } else if (incomeType === 'product_sale') {
        // For product sale, add pending amount to paid amount
        const currentProductPaidAmount = parseFloat(document.getElementById('product_paid_amount').value) || 0;
        const productPendingAmount = parseFloat(document.getElementById('product_pending_amount').value) || 0;
        const newProductPaidAmount = currentProductPaidAmount + productPendingAmount;
        
        document.getElementById('product_paid_amount').value = newProductPaidAmount.toFixed(2);
        document.getElementById('product_pending_amount').value = '0.00';
        
        // Update total paid amount (service paid + product paid)
        const servicePaidAmount = parseFloat(document.getElementById('service_paid_amount').value) || 0;
        const newTotalPaidAmount = servicePaidAmount + newProductPaidAmount;
        const newTotalPendingAmount = totalAmount - newTotalPaidAmount;
        
        document.getElementById('total_paid_amount').value = newTotalPaidAmount.toFixed(2);
        document.getElementById('total_pending_amount').value = newTotalPendingAmount.toFixed(2);
        
    } else {
        // For other types, add pending amount to paid amount
        const currentPaidAmount = parseFloat(document.getElementById('paid_amount').value) || 0;
        const pendingAmount = parseFloat(document.getElementById('pending_amount').value) || 0;
        const newPaidAmount = currentPaidAmount + pendingAmount;
        
        document.getElementById('paid_amount').value = newPaidAmount.toFixed(2);
        document.getElementById('pending_amount').value = '0.00';
        document.getElementById('total_paid_amount').value = newPaidAmount.toFixed(2);
        document.getElementById('total_pending_amount').value = '0.00';
    }
    
    // Trigger payment calculations update
    if (typeof updatePaymentCalculations === 'function') {
        updatePaymentCalculations();
    }
}

function markPaymentCompleteFromEdit() {
    const formData = new FormData();
    formData.append('income_id', editIncomeId);
    formData.append('csrf_token', markCompleteCsrfToken);
    fetch('get_pending_amount.php', {
        method: 'POST',
        body: formData
    }).then(response => response.json()).then(data => {
        if (data && data.success) {
            showMarkCompleteModalFromEdit(data);
        } else if (data && data.message) {
            showEditIncomeAlert('danger', data.message);
        } else {
            showEditIncomeAlert('danger', '<?php echo trans('common', 'error_occurred'); ?>');
        }
    }).catch(() => {
        showEditIncomeAlert('danger', '<?php echo trans('common', 'error_occurred'); ?>');
    });
}

function showMarkCompleteModalFromEdit(data) {
    editMarkCompleteData = data;
    const existingModal = document.getElementById('markCompleteModal');
    if (existingModal) {
        existingModal.remove();
    }
    const paymentDateValue = data.default_payment_date || moment().format('YYYY-MM-DD');
    const selectedPaymentMethodId = data.default_payment_method_id || '';
    const paymentMethodOptions = buildPaymentMethodOptions(selectedPaymentMethodId);
    const modalHTML = `
        <div class="modal fade" id="markCompleteModal" tabindex="-1" aria-labelledby="markCompleteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-success">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="markCompleteModalLabel">
                            <i class="fas fa-check-circle"></i> <?php echo trans('income', 'mark_payment_complete'); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        ${data.no_pending ? `
                        <div class="alert alert-warning mb-3">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo trans('income', 'no_pending_amount'); ?>
                            </h6>
                            <p class="mb-0"><?php echo trans('income', 'no_pending_amount_message'); ?></p>
                        </div>
                        ` : `
                        <div class="alert alert-info mb-3">
                            <h6 class="alert-heading">
                                <i class="fas fa-info-circle"></i> <?php echo trans('income', 'payment_completion_info'); ?>
                            </h6>
                            <p class="mb-0"><?php echo trans('income', 'payment_completion_message'); ?></p>
                        </div>
                        `}
                        
                        <div class="row">
                            <div class="col-md-6">
                                <strong><?php echo trans('income', 'patient_name'); ?>:</strong>
                                <p class="mb-0">${data.patient_name}</p>
                            </div>
                            <div class="col-md-6">
                                <strong>${data.amount_type === 'service' ? '<?php echo trans('income', 'service_pending_amount'); ?>' : (data.amount_type === 'product' ? '<?php echo trans('income', 'product_pending_amount'); ?>' : '<?php echo trans('income', 'pending_amount'); ?>')}:</strong>
                                <p class="mb-0 ${data.no_pending ? 'text-muted' : 'text-danger'} fs-5 fw-bold">${data.formatted_pending_amount}</p>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label for="markCompletePaymentDate" class="form-label"><?php echo trans('income', 'payment_date'); ?> <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="markCompletePaymentDate" value="${paymentDateValue}">
                                <div class="invalid-feedback" id="markCompletePaymentDateError"><?php echo trans('income', 'payment_date_required_simple'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <label for="markCompletePaymentMethod" class="form-label"><?php echo trans('income', 'payment_method'); ?> <span class="text-danger">*</span></label>
                                <select class="form-select" id="markCompletePaymentMethod">
                                    <option value="">${paymentMethodSelectPlaceholder}</option>
                                    ${paymentMethodOptions}
                                </select>
                                <div class="invalid-feedback" id="markCompletePaymentMethodError"><?php echo trans('income', 'payment_method_required_simple'); ?></div>
                            </div>
                        </div>
                        
                        <div class="mt-3 p-3 bg-light rounded">
                            <h6 class="mb-2"><?php echo trans('income', 'what_will_happen'); ?>:</h6>
                            <ul class="mb-0">
                                ${data.no_pending ? `
                                <li>Payment status will be updated to completed</li>
                                <li>No amount changes will be made</li>
                                ` : `
                                <li>${data.amount_type === 'service' ? '<?php echo trans('income', 'service_pending_to_paid'); ?>' : (data.amount_type === 'product' ? '<?php echo trans('income', 'product_pending_to_paid'); ?>' : '<?php echo trans('income', 'pending_to_paid'); ?>')}</li>
                                <li><?php echo trans('income', 'status_to_completed'); ?></li>
                                `}
                                <li><?php echo trans('income', 'action_cannot_undo'); ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> <?php echo trans('common', 'cancel'); ?>
                        </button>
                        <button type="button" class="btn btn-success" onclick="proceedWithMarkCompleteFromEdit(${data.income_id})">
                            <i class="fas fa-check"></i> <?php echo trans('income', 'yes_mark_complete'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    const modalEl = document.getElementById('markCompleteModal');
    const modal = new bootstrap.Modal(modalEl);
    modal.show();
    modalEl.addEventListener('hidden.bs.modal', function() {
        if (!editMarkCompleteConfirmed && editMarkCompletePreviousStatusId !== null) {
            const paymentStatusSelect = document.getElementById('payment_status_id');
            if (paymentStatusSelect) {
                paymentStatusSelect.value = editMarkCompletePreviousStatusId;
                editPaymentStatusLastValue = editMarkCompletePreviousStatusId;
            }
        }
        this.remove();
    });
}

function proceedWithMarkCompleteFromEdit(incomeId) {
    const paymentDateInput = document.getElementById('markCompletePaymentDate');
    const paymentMethodSelect = document.getElementById('markCompletePaymentMethod');
    const paymentDateError = document.getElementById('markCompletePaymentDateError');
    const paymentMethodError = document.getElementById('markCompletePaymentMethodError');
    let hasError = false;
    if (paymentDateInput) {
        paymentDateInput.classList.remove('is-invalid');
    }
    if (paymentMethodSelect) {
        paymentMethodSelect.classList.remove('is-invalid');
    }
    if (paymentDateError) {
        paymentDateError.style.display = '';
    }
    if (paymentMethodError) {
        paymentMethodError.style.display = '';
    }
    const paymentDateValue = paymentDateInput ? paymentDateInput.value.trim() : '';
    const paymentMethodValue = paymentMethodSelect ? paymentMethodSelect.value.trim() : '';
    if (!paymentDateValue) {
        hasError = true;
        if (paymentDateInput) {
            paymentDateInput.classList.add('is-invalid');
        }
    }
    if (!paymentMethodValue) {
        hasError = true;
        if (paymentMethodSelect) {
            paymentMethodSelect.classList.add('is-invalid');
        }
    }
    if (hasError) {
        showEditIncomeAlert('danger', '<?php echo trans('income', 'fill_required_fields'); ?>');
        return;
    }
    const modalEl = document.getElementById('markCompleteModal');
    const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
    editMarkCompleteConfirmed = true;
    if (modal) {
        modal.hide();
    }
    const pendingAmount = editMarkCompleteData && typeof editMarkCompleteData.pending_amount !== 'undefined'
        ? parseFloat(editMarkCompleteData.pending_amount)
        : 0;
    if (pendingAmount > 0 && typeof dcmtAddPaymentRow === 'function') {
        dcmtAddPaymentRow('total', {
            paid_on: paymentDateValue,
            payment_method_id: paymentMethodValue,
            amount: pendingAmount
        });
    }
    const paymentStatusSelect = document.getElementById('payment_status_id');
    const completedStatusIdEl = document.getElementById('completed_status_id');
    if (paymentStatusSelect && completedStatusIdEl) {
        const completedStatusId = completedStatusIdEl.value;
        paymentStatusSelect.value = completedStatusId;
        editPaymentStatusLastValue = completedStatusId;
    }
}

function showEditIncomeAlert(type, message) {
    const existingAlerts = document.querySelectorAll('.alert-dismissible');
    existingAlerts.forEach(alert => alert.remove());
    const alertDiv = document.createElement('div');
    alertDiv.className = 'alert alert-' + type + ' alert-dismissible fade show';
    alertDiv.innerHTML = message + '<button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>';
    const mainContent = document.querySelector('.main-content') || document.querySelector('.dcmt-add-form-container');
    if (mainContent) {
        const firstChild = mainContent.firstElementChild;
        if (firstChild) {
            mainContent.insertBefore(alertDiv, firstChild);
        } else {
            mainContent.appendChild(alertDiv);
        }
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
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
    const rows = container.querySelectorAll('.dcmt-payment-row');
    rows.forEach((row, index) => {
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
    const confirmationMessage = translations.confirmDeletePayment || <?php echo json_encode(trans('income', 'confirm_delete_payment'), JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES); ?>;
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

dcmtRenderPaymentRows('total', Array.isArray(dcmtInitialPayments.total) ? dcmtInitialPayments.total : []);
</script>

<script src="../../assets/js/select2.min.js"></script>
<script>
// Auto-sync payment status based on pending amount
function dcmtSyncPaymentStatusWithPending() {
    const totalPendingAmountField = document.getElementById('total_pending_amount');
    const paymentStatusSelect = document.getElementById('payment_status_id');
    const completedStatusIdEl = document.getElementById('completed_status_id');
    const pendingStatusIdEl = document.getElementById('pending_status_id');
    if (!totalPendingAmountField || !paymentStatusSelect || !completedStatusIdEl || !pendingStatusIdEl) {
        return;
    }
    const pending = parseFloat(totalPendingAmountField.value) || 0;
    const completedStatusId = completedStatusIdEl.value;
    const pendingStatusId = pendingStatusIdEl.value;
    
    if (pending === 0) {
        if (paymentStatusSelect.value !== completedStatusId) {
            paymentStatusSelect.value = completedStatusId;
        }
    } else if (paymentStatusSelect.value === completedStatusId) {
        paymentStatusSelect.value = pendingStatusId;
    }
}

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
    // Service selects are now initialized via setupServiceItemEventDelegation()
    
    // Initialize Select2 on patient name field
    $('#patient_name').select2({
        placeholder: '<?php echo trans('income', 'patient_name_placeholder'); ?>',
        allowClear: true,
        width: '100%',
        minimumResultsForSearch: 0
    });
    
    // Update hidden patient_id field when patient is selected
    $('#patient_name').on('change', function() {
        const selectedOption = $(this).find('option:selected');
        const patientId = selectedOption.data('patient-id') || '';
        $('#patient_id').val(patientId);
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
    
    // Initialize Quick Add Patient functionality
    initializeQuickAddPatient();
    
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
        
        if (selectedOption.length && selectedOption.val()) {
            const productType = selectedOption.attr('data-product-type');
            const productItem = $(select).closest('.product-item');
            
            if (productType === 'for_use') {
                // Hide price and total fields for "for use" items
                productItem.find('.product-price').closest('.col-md-2').hide();
                productItem.find('.product-total').closest('.col-md-2').hide();
                // Clear price and total values
                productItem.find('.product-price').val('');
                productItem.find('.product-total').val('');
                // Remove validation attributes to prevent form validation errors
                productItem.find('.product-price').removeAttr('required min max step');
            } else {
                // Show price and total fields for "for sale" items
                productItem.find('.product-price').closest('.col-md-2').show();
                productItem.find('.product-total').closest('.col-md-2').show();
                // Restore validation attributes for "for sale" items
                productItem.find('.product-price').attr('step', '0.01').attr('min', '0.01');
            }
        }
    });
}

// Override the existing addProductItem function to work with Select2
function dcmtEnsureInventoryOption(selectElement, data = {}) {
    if (!selectElement || !data || !data.inventory_id) {
        return;
    }
    const value = String(data.inventory_id);
    const options = Array.from(selectElement.options || []);
    const optionExists = options.some(option => String(option.value) === value);
    if (optionExists) {
        return;
    }
    const template = translations.missingProductPlaceholder || 'Previously selected product (ID: {id})';
    const label = (data.inventory_name && data.inventory_name.trim() !== '')
        ? data.inventory_name
        : template.replace('{id}', value);
    const fallbackPrice = (data.unit_price !== undefined && data.unit_price !== null) ? data.unit_price : '';
    const option = new Option(label, value, false, false);
    option.setAttribute('data-price', fallbackPrice);
    option.setAttribute('data-stock', data.inventory_stock !== undefined && data.inventory_stock !== null ? data.inventory_stock : 0);
    option.setAttribute('data-product-type', data.product_type || 'for_sale');
    selectElement.appendChild(option);
}

function addProductItemWithSelect2(data = {}) {
    const container = document.getElementById('productItems');
    dcmtEnsureItemsHeader(container, productItemsHeaderHtml);
    const newItem = document.createElement('div');
    newItem.className = 'product-item row mb-2';
    
    // Get the current number of existing items to use as index
    const existingItems = container.querySelectorAll('.product-item');
    const currentIndex = existingItems.length;
    
    const optionsHTML = dcmtResolveInventoryOptionsHTML(translations.selectProduct);
    
    newItem.innerHTML = `
        <div class="col-md-4">
            <select class="form-select product-inventory" name="product_items[${currentIndex}][inventory_id]" onchange="updateProductPrice(this, ${currentIndex}); checkAndShowProductPaidAmount();">
                ${optionsHTML}
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control product-quantity" name="product_items[${currentIndex}][quantity]" 
                   placeholder="${translations.qty}" min="1" value="${data.quantity !== undefined ? escapeHtml(data.quantity) : ''}" onchange="updateProductTotal(${currentIndex})">
        </div>
        <div class="col-md-2" style="display: none;">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="number" class="form-control dcmt-amount-input product-price" name="product_items[${currentIndex}][unit_price]" 
                       placeholder="${translations.price}" onchange="updateProductTotal(${currentIndex})" value="${data.unit_price !== undefined ? escapeHtml(data.unit_price) : ''}">
            </div>
        </div>
        <div class="col-md-2" style="display: none;">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                <input type="text" class="form-control dcmt-amount-input product-total" placeholder="${translations.total}" readonly>
            </div>
        </div>
        <div class="col-md-2 dcmt-delete-cell">
            <input type="hidden" class="product-type" name="product_items[${currentIndex}][product_type]" value="${data.product_type ? escapeHtml(data.product_type) : ''}">
            <button type="button" class="btn btn-outline-danger btn-sm remove-product-btn">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    const inventorySelect = newItem.querySelector('.product-inventory');
    if (inventorySelect && data.inventory_id) {
        if (data.inventory_missing) {
            dcmtEnsureInventoryOption(inventorySelect, data);
        }
        inventorySelect.value = String(data.inventory_id);
    }
    
    const totalInput = newItem.querySelector('.product-total');
    if (totalInput) {
        const qty = parseFloat(data.quantity) || 0;
        const price = parseFloat(data.unit_price) || 0;
        totalInput.value = qty && price ? (qty * price).toFixed(2) : '';
    }
    
    container.appendChild(newItem);
    productItemCount++;
    
    // Ensure productItems are visible after adding item
    ensureProductItemsVisible();
    
    // Ensure add product button remains icon-only and visible for product sales
    const addProductBtn = document.getElementById('addProductBtn');
    if (addProductBtn) {
        updateAddProductButtonLabel();
        addProductBtn.setAttribute('aria-label', translations.addProduct);
        const typeSelect = document.getElementById('type');
        addProductBtn.hidden = !(typeSelect && typeSelect.value === 'product_sale');
    }
    
    // Initialize Select2 on the new select element
    const selectInstance = $(newItem.querySelector('.product-inventory')).select2({
        placeholder: translations.selectProduct,
        allowClear: true,
        width: '100%'
    });
    if (data.inventory_id) {
        selectInstance.val(String(data.inventory_id)).trigger('change');
    } else {
        selectInstance.val('').trigger('change');
    }
    
    // Initialize product type handling for the new item
    initializeProductTypeHandling();
    
    // Check if product paid amount field should be shown for consultation type
    if (typeof checkAndShowProductPaidAmount === 'function') {
        checkAndShowProductPaidAmount();
    }
}

// Override the existing removeProductItem function to work with Select2
function removeProductItemWithSelect2(button) {
    if (confirm(translations.confirmDeleteProduct)) {
        const productItem = button.closest('.product-item');
        if (productItem) {
            // Destroy Select2 before removing the element if jQuery is available and Select2 is initialized
            if (typeof $ !== 'undefined') {
                const selectElement = productItem.querySelector('.product-inventory');
                if (selectElement) {
                    const $select = $(selectElement);
                    // Check if Select2 is initialized by checking for the class and data
                    if ($select.hasClass('select2-hidden-accessible')) {
                        try {
                            // Check if Select2 data exists before destroying
                            const select2Data = $select.data('select2');
                            if (select2Data) {
                                $select.select2('destroy');
                            }
                        } catch (e) {
                            // If destroy fails, just continue - the element will be removed anyway
                            console.warn('Error destroying Select2:', e);
                        }
                    }
                }
            }
            
            productItem.remove();
            
            // Decrement product count
            productItemCount--;
            
            // Ensure add product button remains consistent
            const addProductBtn = document.getElementById('addProductBtn');
            if (addProductBtn) {
                updateAddProductButtonLabel();
                addProductBtn.setAttribute('aria-label', translations.addProduct);
                
                // Check remaining product items
                const productItemsContainer = document.getElementById('productItems');
                const remainingItems = productItemsContainer ? productItemsContainer.querySelectorAll('.product-item') : [];
                const typeSelect = document.getElementById('type');
                
                // Show button if type is product_sale OR if there are still product items
                const shouldShow = (typeSelect && typeSelect.value === 'product_sale') || remainingItems.length > 0;
                addProductBtn.hidden = !shouldShow;
            }
            
            // Always recalculate the total amount
            const typeSelect = document.getElementById('type');
            if (typeSelect.value === 'consultation') {
                updateConsultationTotal();
            } else if (typeSelect.value === 'product_sale') {
                calculateTotalAmount();
            }
            
            // Check if product paid amount field should be shown for consultation type
            if (typeof checkAndShowProductPaidAmount === 'function') {
                checkAndShowProductPaidAmount();
            }
        }
    }
}

// Override the existing updateProductPrice function to work with Select2
function updateProductPriceWithSelect2(select, index) {
    const selectedOption = $(select).find('option:selected');
    if (selectedOption && selectedOption.val()) {
        const price = selectedOption.attr('data-price');
        const productType = selectedOption.attr('data-product-type');
        const productItem = $(select).closest('.product-item');
        
        if (productType === 'for_use') {
            // Hide price and total fields for "for use" items
            productItem.find('.product-price').closest('.col-md-2').hide();
            productItem.find('.product-total').closest('.col-md-2').hide();
            // Clear price and total values
            productItem.find('.product-price').val('');
            productItem.find('.product-total').val('');
            // Remove validation attributes to prevent form validation errors
            productItem.find('.product-price').removeAttr('required min max step');
        } else {
            // Show price and total fields for "for sale" items
            productItem.find('.product-price').closest('.col-md-2').show();
            productItem.find('.product-total').closest('.col-md-2').show();
            
            if (price) {
                const priceInput = productItem.find('.product-price');
                if (priceInput.length) {
                    priceInput.val(price);
                    updateProductTotal(index);
                }
            }
        }
        
        // Update quantity field max attribute and validate current value
        const stock = selectedOption.attr('data-stock');
        const quantityInput = productItem.find('.product-quantity');
        if (quantityInput.length && stock) {
            quantityInput.attr('max', stock);
            validateProductQuantity(quantityInput[0]);
        }
    } else {
        // Reset to default state when no product is selected
        const productItem = $(select).closest('.product-item');
        productItem.find('.product-price').closest('.col-md-2').show();
        productItem.find('.product-total').closest('.col-md-2').show();
        productItem.find('.product-price').val('');
        productItem.find('.product-total').val('');
    }
}
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
                        <label for="quick_patient_phone" class="form-label"><?php echo trans('patient', 'phone'); ?> *</label>
                        <input type="text" class="form-control" id="quick_patient_phone" name="phone" 
                               placeholder="<?php echo trans('patient', 'phone_placeholder'); ?>" required>
                        <div id="quick_patient_phone_error" class="invalid-feedback"></div>
                    </div>
                    
                    <div class="mb-3">
                        <label for="quick_patient_email" class="form-label"><?php echo trans('patient', 'email'); ?></label>
                        <input type="email" class="form-control" id="quick_patient_email" name="email" 
                               placeholder="<?php echo trans('patient', 'email_placeholder'); ?>">
                        <div class="form-text"><?php echo trans('income', 'quick_add_patient_email_help'); ?></div>
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
function initializeQuickAddPatient() {
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
                    
                    // Check if option already exists
                    const existingOption = patientSelect.find(`option[data-patient-id="${patientId}"]`);
                    if (existingOption.length === 0) {
                        // Add new option
                        const newOption = new Option(displayText, patientName, false, false);
                        $(newOption).attr('data-patient-id', patientId);
                        patientSelect.append(newOption);
                    }
                    
                    // Select the new patient
                    patientSelect.val(patientName).trigger('change');

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
                dcmtClearQuickPhoneError();
                
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
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
