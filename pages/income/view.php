<?php
/**
 * View Income Page
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

// Get income record with related data
$stmt = $dcmt_pdo->prepare("
    SELECT i.*, 
           u_doctor.dcmt_full_name as doctor_name,
           u.dcmt_full_name as created_by_name, 
           pm.dcmt_name as payment_method_name, 
           ps.dcmt_name as payment_status_name, 
           s.dcmt_name as service_name,
           p.dcmt_first_name as patient_first_name
    FROM dcmt_income i
    LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
    LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
    LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
    LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
    LEFT JOIN dcmt_services s ON i.dcmt_service_id = s.dcmt_id
    LEFT JOIN dcmt_patients p ON i.dcmt_patient_id = p.dcmt_id
    WHERE i.dcmt_id = ?
");
$stmt->execute([$income_id]);
$income = $stmt->fetch();

if (!$income) {
    dcmt_show_message(trans('income', 'income_not_found'), 'error');
    dcmt_redirect('index.php');
    exit();
}

// Get service items from breakdown table
$service_items = [];
$stmt = $dcmt_pdo->prepare("
    SELECT ib.*, 
           s.dcmt_name as service_name, 
           u_doctor.dcmt_full_name as doctor_name
    FROM dcmt_income_breakdown ib
    LEFT JOIN dcmt_services s ON ib.dcmt_reference_id = s.dcmt_id
    LEFT JOIN dcmt_users u_doctor ON ib.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
    WHERE ib.dcmt_id = ? AND ib.dcmt_line_type = 'service'
    ORDER BY ib.dcmt_line_no ASC
");
$stmt->execute([$income_id]);
$service_items = $stmt->fetchAll();

// Get product lines from breakdown table
$product_items = [];
$stmt = $dcmt_pdo->prepare("
    SELECT ib.*, inv.dcmt_name as product_name
    FROM dcmt_income_breakdown ib
    LEFT JOIN dcmt_inventory inv ON ib.dcmt_inventory_id = inv.dcmt_id
    WHERE ib.dcmt_id = ? AND ib.dcmt_line_type = 'product'
    ORDER BY ib.dcmt_line_no ASC
");
$stmt->execute([$income_id]);
$product_items = $stmt->fetchAll();

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

$payment_history = [];
$stmt = $dcmt_pdo->prepare("
    SELECT iph.*, ru.dcmt_full_name AS recorded_by_name, pm.dcmt_name AS payment_method_name
    FROM dcmt_income_payment_history iph
    LEFT JOIN dcmt_users ru ON iph.dcmt_recorded_by COLLATE utf8mb4_general_ci = ru.dcmt_username
    LEFT JOIN dcmt_income_payment_methods pm ON iph.dcmt_payment_method_id = pm.dcmt_id
    WHERE iph.dcmt_income_id = ?
    ORDER BY iph.dcmt_paid_on DESC, iph.dcmt_id DESC
");
$stmt->execute([$income_id]);
$payment_history = $stmt->fetchAll();

// Get payment methods for lookup
$payment_methods_map = [];
$stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active'");
$stmt->execute();
$payment_methods = $stmt->fetchAll();
foreach ($payment_methods as $method) {
    $payment_methods_map[$method['dcmt_id']] = $method['dcmt_name'];
}

foreach ($payment_history as &$payment) {
    $payment_method_id = dcmt_payment_history_resolve_method_id($payment);
    $payment_method_name = trim((string) ($payment['payment_method_name'] ?? ''));
    if ($payment_method_name === '' && $payment_method_id !== null && isset($payment_methods_map[$payment_method_id])) {
        $payment_method_name = $payment_methods_map[$payment_method_id];
    }
    $payment['payment_method_id'] = $payment_method_id;
    $payment['payment_method_name'] = $payment_method_name !== '' ? $payment_method_name : null;
    $payment['notes_text'] = dcmt_payment_history_resolve_notes_text($payment);
}
unset($payment);

$payment_type_labels = [
    'consultation' => trans('income', 'service_payments'),
    'product' => trans('income', 'product_payments'),
    'general' => trans('income', 'general_payments')
];

// Get edit history from activity log
$edit_history = [];
try {
    $stmt = $dcmt_pdo->prepare("
        SELECT al.*, u.dcmt_full_name as user_name
        FROM dcmt_activity_log al
        LEFT JOIN dcmt_users u ON al.dcmt_user = u.dcmt_username
        WHERE al.dcmt_details LIKE ? OR (al.dcmt_activity LIKE ? AND al.dcmt_details LIKE ?)
        ORDER BY al.dcmt_created_at DESC
        LIMIT 20
    ");
    $search_pattern = "%Income ID: $income_id%";
    $activity_pattern = "%Income%";
    $income_pattern = "%Income ID: $income_id%";
    $stmt->execute([$search_pattern, $activity_pattern, $income_pattern]);
    $edit_history = $stmt->fetchAll();
    
    // Debug: Log the number of records found
    error_log("Edit history query found " . count($edit_history) . " records for income ID: $income_id");
} catch (PDOException $e) {
    // If query fails, continue without edit history
    error_log("Failed to fetch edit history: " . $e->getMessage());
}

$print_logo_path = dcmt_get_logo_path();
$print_logo_exists = !empty($print_logo_path) && file_exists(__DIR__ . '/../../' . $print_logo_path);
$site_name = dcmt_get_site_name();

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>


<div class="row income-print-page">
    <div class="col-12">
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-info-circle dcmt-view-card-title-icon"></i><?php echo trans('income', 'income_details'); ?>
                </h6>
                <div class="dcmt-view-header-links no-print">
                    <button type="button" class="dcmt-add-form-view-all-link me-3 border-0 bg-transparent" onclick="triggerIncomePrint()">
                        <i class="fas fa-print me-1"></i><?php echo trans('common', 'print'); ?>
                    </button>
                    <a href="edit.php?id=<?php echo $income['dcmt_id']; ?>" class="dcmt-add-form-view-all-link me-3">
                        <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                    </a>
                    <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('income', 'back_to_all_income'); ?></a>
                </div>
            </div>
            <div class="card-body">
                <div class="income-print-header print-only">
                    <div class="income-print-header-brand">
                        <?php if ($print_logo_exists): ?>
                            <img src="../../<?php echo htmlspecialchars($print_logo_path); ?>" alt="<?php echo htmlspecialchars($site_name); ?>" class="income-print-logo">
                        <?php endif; ?>
                        <h2 class="income-print-site-name"><?php echo htmlspecialchars($site_name); ?></h2>
                    </div>
                    <div class="income-print-meta">
                        <div><strong><?php echo trans('income', 'patient_name'); ?>:</strong> <?php echo htmlspecialchars($income['dcmt_patient_name'] ?? ''); ?></div>
                        <div><strong><?php echo trans('income', 'transaction_date'); ?>:</strong> <?php echo dcmt_format_date($income['dcmt_transaction_date']); ?></div>
                    </div>
                </div>

                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('income', 'transaction_date'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo dcmt_format_date($income['dcmt_transaction_date']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('income', 'patient_name'); ?>:</span>
                            <?php $display_name = $income['dcmt_patient_name'] ?? ''; ?>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($display_name); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'amount'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_amount']); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('income', 'payment_status'); ?>:</span>
                            <div class="dcmt-view-field-value dcmt-view-field-value-status-<?php echo strtolower($income['payment_status_name'] ?? $income['dcmt_payment_status'] ?? 'unknown'); ?>">
                                <?php 
                                // Translate payment status name
                                $raw_status = $income['payment_status_name'] ?? $income['dcmt_payment_status'] ?? 'unknown';
                                $translated_status = trans('income_payment_status', ucfirst($raw_status));
                                echo htmlspecialchars($translated_status !== ucfirst($raw_status) ? $translated_status : ucfirst($raw_status));
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'created_by'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($income['created_by_name']); ?></div>
                        </div>
                    </div>
                    <?php if (!empty($income['dcmt_description'])): ?>
                        <div class="col-md-4">
                            <div class="dcmt-view-field">
                                <span class="dcmt-view-field-label"><?php echo trans('common', 'description'); ?>:</span>
                                <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($income['dcmt_description'])); ?></div>
                            </div>
                        </div>
                    <?php endif; ?>
                </div>
                
                <!-- Service Items Section -->
                <?php if (!empty($service_items)): ?>
                <div class="row mt-4 print-keep">
                    <div class="col-12">
                        <h6 class="dcmt-view-table-title">
                            <i class="fas fa-stethoscope dcmt-view-table-title-icon"></i><?php echo trans('income', 'service_items'); ?>
                        </h6>
                        <div class="table-responsive">
                            <table class="table dcmt-view-table table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo trans('income', 'doctor'); ?></th>
                                        <th><?php echo trans('income', 'service'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'quantity'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'unit_price'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'total'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $service_total = 0;
                                    foreach ($service_items as $item): 
                                        $line_total = floatval($item['dcmt_line_total'] ?? 0);
                                        $service_total += $line_total;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['doctor_name'] ?? trans('common', 'not_available')); ?></td>
                                        <td><?php echo htmlspecialchars($item['service_name'] ?? $item['dcmt_label'] ?? trans('common', 'not_available')); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($item['dcmt_unit_price']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($line_total); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th colspan="4" class="text-end"><?php echo trans('income', 'service_total'); ?>:</th>
                                        <th class="text-end"><?php echo dcmt_format_currency($service_total); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Product Items Section -->
                <?php if (!empty($product_items)): ?>
                <div class="row mt-4 print-keep">
                    <div class="col-12">
                        <h6 class="dcmt-view-table-title">
                            <i class="fas fa-shopping-cart dcmt-view-table-title-icon"></i><?php echo trans('income', 'product_items'); ?>
                        </h6>
                        <div class="table-responsive">
                            <table class="table dcmt-view-table table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo trans('income', 'product'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'quantity'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'unit_price'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'total'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $product_total = 0;
                                    foreach ($product_items as $item): 
                                        $line_total = floatval($item['dcmt_line_total'] ?? 0);
                                        $product_total += $line_total;
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['product_name'] ?? $item['dcmt_label'] ?? 'N/A'); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_quantity_display($item['dcmt_quantity']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($item['dcmt_unit_price']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($line_total); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th colspan="3" class="text-end"><?php echo trans('income', 'product_total'); ?>:</th>
                                        <th class="text-end"><?php echo dcmt_format_currency($product_total); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <!-- Payment History Section -->
                <div class="row mt-4 print-keep">
                    <div class="col-12">
                        <h6 class="dcmt-view-table-title">
                            <i class="fas fa-wallet dcmt-view-table-title-icon"></i><?php echo trans('income', 'payment_history'); ?>
                        </h6>
                        <?php if (!empty($payment_history)): ?>
                        <div class="table-responsive">
                            <table class="table dcmt-view-table table-hover">
                                <thead>
                                    <tr>
                                        <th><?php echo trans('income', 'payment_date'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'payment_method'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'recorded_by'); ?></th>
                                        <th class="text-end"><?php echo trans('income', 'payment_amount'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php 
                                    $total_paid = 0;
                                    foreach ($payment_history as $payment): 
                                        $recorded_name = $payment['recorded_by_name'] ?? $payment['dcmt_recorded_by'];
                                        $payment_method_id_raw = $payment['dcmt_payment_method_id'] ?? null;
                                        $method_recorded = $payment_method_id_raw !== null
                                            && $payment_method_id_raw !== ''
                                            && (int)$payment_method_id_raw > 0;
                                        $payment_method_name = $payment['payment_method_name'] ?? '';
                                        if ($method_recorded && $payment_method_name) {
                                            $translated_method = trans('income_payment_method', $payment_method_name);
                                            $payment_method_display = ($translated_method !== $payment_method_name) ? $translated_method : $payment_method_name;
                                        } else {
                                            $payment_method_display = 'N/A';
                                        }
                                        $amount = floatval($payment['dcmt_amount'] ?? 0);
                                        $total_paid += $amount;
                                    ?>
                                    <tr>
                                        <td><?php echo dcmt_format_date($payment['dcmt_paid_on']); ?></td>
                                        <td class="text-end"><?php echo htmlspecialchars($payment_method_display); ?></td>
                                        <td class="text-end"><?php echo htmlspecialchars($recorded_name ?: 'N/A'); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($amount); ?></td>
                                    </tr>
                                    <?php endforeach; ?>
                                </tbody>
                                <tfoot>
                                    <tr class="table-light">
                                        <th colspan="3" class="text-end"><?php echo trans('income', 'total_paid'); ?>:</th>
                                        <th class="text-end"><?php echo dcmt_format_currency($total_paid); ?></th>
                                    </tr>
                                </tfoot>
                            </table>
                        </div>
                        <?php else: ?>
                        <div class="alert alert-info mb-0">
                            <i class="fas fa-info-circle me-2"></i><?php echo trans('income', 'no_payments_recorded'); ?>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Amount Details Section -->
                <div class="row mt-4 print-keep">
                    <div class="col-12">
                        <h6 class="dcmt-view-table-title">
                            <i class="fas fa-dollar-sign dcmt-view-table-title-icon"></i><?php echo trans('income', 'amount_details'); ?>
                        </h6>
                    </div>
                </div>

                <!-- Service Amount Details (view only) -->
                <?php if (($income['dcmt_service_amount'] ?? 0) > 0 || ($income['dcmt_service_paid_amount'] ?? 0) > 0 || !empty($service_items)): ?>
                <div class="row no-print">
                    <div class="col-12">
                        <div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('service', 'service_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_service_amount'] ?? 0); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'service_paid_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_service_paid_amount'] ?? 0); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'service_pending_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_service_pending_amount'] ?? 0); ?></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Product Amount Details (view only) -->
                <?php if (($income['dcmt_product_amount'] ?? 0) > 0 || ($income['dcmt_product_paid_amount'] ?? 0) > 0 || !empty($product_items)): ?>
                <div class="row no-print">
                    <div class="col-12">
                        <div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'product_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_product_amount'] ?? 0); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'product_paid_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value">
                                            <?php 
                                            $product_paid = isset($income['dcmt_product_paid_amount']) ? floatval($income['dcmt_product_paid_amount']) : 0.00;
                                            echo dcmt_format_currency($product_paid);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'product_pending_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value">
                                            <?php 
                                            $product_pending = isset($income['dcmt_product_pending_amount']) ? floatval($income['dcmt_product_pending_amount']) : 0.00;
                                            echo dcmt_format_currency($product_pending);
                                            ?>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Total Payment Summary -->
                <div class="row print-keep">
                    <div class="col-12">
                        <div>
                            <div class="row">
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'total_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_amount']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'total_paid_amount'); ?>:</span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_total_paid_amount']); ?></div>
                                    </div>
                                </div>
                                <div class="col-md-4">
                                    <div class="dcmt-view-field">
                                        <span class="dcmt-view-field-label"><?php echo trans('income', 'total_pending_amount'); ?>:</strong></span>
                                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($income['dcmt_total_pending_amount']); ?></strong></div>
                                    </div>
                                </div>
                            </div>
                            <div class="row mt-2 no-print">
                                <div class="col-12">
                                    <p class="text-muted small mb-0">
                                        <i class="fas fa-info-circle me-1"></i><?php echo trans('income', 'proportional_split_note'); ?>
                                    </p>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>

                <div class="income-print-signature print-only">
                    <p class="income-print-signature-text mb-0"><?php echo trans('income', 'print_signature_text'); ?></p>
                </div>
            </div>
        </div>
        
        <!-- Audit Trail Section -->
        <div class="card mt-4 dcmt-records-table no-print">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-history dcmt-view-card-title-icon"></i><?php echo trans('income', 'audit_trail'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($edit_history)): ?>
                <div class="timeline">
                    <?php foreach ($edit_history as $index => $entry): ?>
                    <div class="timeline-item <?php echo $index === 0 ? 'timeline-item-first' : ''; ?>">
                        <div class="timeline-marker">
                            <?php
                            $icon = 'info';
                            if (strpos($entry['dcmt_activity'], 'added') !== false) {
                                $icon = 'plus';
                            } elseif (strpos($entry['dcmt_activity'], 'updated') !== false) {
                                $icon = 'edit';
                            } elseif (strpos($entry['dcmt_activity'], 'deleted') !== false) {
                                $icon = 'trash';
                            }
                            ?>
                            <i class="fas fa-<?php echo $icon; ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($entry['dcmt_activity']); ?></h6>
                                    <?php if (!empty($entry['dcmt_details'])): ?>
                                        <?php
                                        $details = $entry['dcmt_details'];
                                        // Remove "Income ID: X" and "Patient: X" from details
                                        // Handle various separators (, | -)
                                        $details = preg_replace('/Income ID: \d+(?:,| -| \|)?\s*/', '', $details);
                                        $details = preg_replace('/Patient: [^,|]+(?:,| \|)?\s*/', '', $details);
                                        $details = trim($details, ',| ');
                                        
                                        // Format with line breaks for readability
                                        $details_html = str_replace(' | ', '<br>', htmlspecialchars($details));
                                        ?>
                                        <p class="text-muted mb-1 small"><?php echo $details_html; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($entry['user_name'] ?: $entry['dcmt_user']); ?>
                                    </small>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo dcmt_format_date($entry['dcmt_created_at'], 'M j, Y g:i A'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <p class="mb-0"><?php echo trans('income', 'no_edit_history_found'); ?></p>
                    <small><?php echo trans('income', 'edit_history_will_appear'); ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Pass translations to JavaScript
window.translations = {
    confirm_deletion: '<?php echo trans('income', 'confirm_deletion') ?: trans('common', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('income', 'warning') ?: trans('common', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('income', 'delete_confirmation_message') ?: trans('common', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('income', 'cancel') ?: trans('common', 'cancel'); ?>',
    yes_delete: '<?php echo trans('income', 'yes_delete') ?: trans('common', 'yes_delete'); ?>'
};

function triggerIncomePrint() {
    window.print();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
