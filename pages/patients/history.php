<?php
/**
 * Patient History Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

// Get patient details
try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_patients WHERE dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching patient: " . $e->getMessage());
    dcmt_show_message(trans('patient', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$patient_full_name = $patient['dcmt_patient_name'] ?? '';

// Get patient notes from dcmt_patient_notes table only
// Note: Income notes are now saved directly to dcmt_patient_notes table, so we only need to fetch from this table
$patient_notes = [];
try {
    $stmt = $dcmt_pdo->prepare("
        SELECT pn.*, u.dcmt_full_name as created_by_name
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_users u ON pn.dcmt_created_by = u.dcmt_username
        WHERE pn.dcmt_patient_id = ?
        ORDER BY pn.dcmt_note_date DESC, pn.dcmt_created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $notes_result = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format notes for display
    foreach ($notes_result as $note) {
        $patient_notes[] = [
            'dcmt_id' => $note['dcmt_id'],
            'dcmt_topic' => $note['dcmt_topic'] ?? '',
            'dcmt_note_text' => $note['dcmt_note_text'] ?? '',
            'dcmt_note_date' => $note['dcmt_note_date'] ?? $note['dcmt_created_at'],
            'dcmt_created_at' => $note['dcmt_created_at'],
            'dcmt_created_by' => $note['dcmt_created_by'] ?? '',
            'created_by_name' => $note['created_by_name'] ?? $note['dcmt_created_by'] ?? ''
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching patient notes: " . $e->getMessage());
}

// Pagination settings for patient notes
$notes_per_page = 10;
$notes_page = isset($_GET['notes_page']) ? max(1, (int) $_GET['notes_page']) : 1;
$notes_total = count($patient_notes);
$notes_total_pages = max(1, (int) ceil($notes_total / $notes_per_page));
if ($notes_page > $notes_total_pages) {
    $notes_page = $notes_total_pages;
}
$notes_offset = ($notes_page - 1) * $notes_per_page;
$patient_notes_paginated = array_slice($patient_notes, $notes_offset, $notes_per_page);

// Get income records for this patient (by patient_id first, with name fallback for old records)
$income_records = [];
try {
    $stmt = $dcmt_pdo->prepare("
        SELECT i.*, 
               u.dcmt_full_name as doctor_name,
               pm.dcmt_name as payment_method_name,
               ps.dcmt_name as payment_status_name
        FROM dcmt_income i
        LEFT JOIN dcmt_users u ON i.dcmt_user_id = u.dcmt_id
        LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
        WHERE (i.dcmt_patient_id = ? OR (i.dcmt_patient_id IS NULL AND i.dcmt_patient_name = ?))
        ORDER BY i.dcmt_transaction_date DESC, i.dcmt_created_at DESC
    ");
    $stmt->execute([$patient_id, $patient_full_name]);
    $income_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Get payment methods for lookup
    $payment_methods_map = [];
    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active'");
        $stmt->execute();
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payment_methods as $method) {
            $payment_methods_map[$method['dcmt_id']] = $method['dcmt_name'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching payment methods: " . $e->getMessage());
    }
    
    // Get breakdown items and payment history for each income record
    foreach ($income_records as &$income) {
        $income_id = $income['dcmt_id'];
        try {
            $breakdown_stmt = $dcmt_pdo->prepare("
                SELECT 
                    ib.*,
                    s.dcmt_name AS service_name,
                    inv.dcmt_name AS product_name,
                    u.dcmt_full_name AS doctor_name,
                    c.dcmt_product_type AS inventory_product_type
                FROM dcmt_income_breakdown ib
                LEFT JOIN dcmt_services s ON (ib.dcmt_line_type = 'service' AND ib.dcmt_reference_id = s.dcmt_id)
                LEFT JOIN dcmt_inventory inv ON (ib.dcmt_line_type = 'product' AND ib.dcmt_inventory_id = inv.dcmt_id)
                LEFT JOIN dcmt_users u ON (ib.dcmt_line_type = 'service' AND ib.dcmt_user_id = u.dcmt_id)
                LEFT JOIN dcmt_inventory_categories c ON inv.dcmt_category_id = c.dcmt_id
                WHERE ib.dcmt_id = ?
                ORDER BY ib.dcmt_line_no ASC
            ");
            $breakdown_stmt->execute([$income_id]);
            $income['breakdown_items'] = $breakdown_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching breakdown for income $income_id: " . $e->getMessage());
            $income['breakdown_items'] = [];
        }
        
        // Get payment history for this income record
        try {
            $payment_stmt = $dcmt_pdo->prepare("
                SELECT iph.*, ru.dcmt_full_name AS recorded_by_name
                FROM dcmt_income_payment_history iph
                LEFT JOIN dcmt_users ru ON iph.dcmt_recorded_by COLLATE utf8mb4_general_ci = ru.dcmt_username
                WHERE iph.dcmt_income_id = ?
                ORDER BY iph.dcmt_paid_on DESC, iph.dcmt_id DESC
            ");
            $payment_stmt->execute([$income_id]);
            $payment_history = $payment_stmt->fetchAll(PDO::FETCH_ASSOC);
            
            // Extract payment method from notes (stored as JSON)
            foreach ($payment_history as &$payment) {
                $payment_method_id = null;
                $payment_method_name = null;
                
                if (!empty($payment['dcmt_notes'])) {
                    $notes_data = json_decode($payment['dcmt_notes'], true);
                    if (is_array($notes_data) && isset($notes_data['payment_method_id'])) {
                        $payment_method_id = (int)$notes_data['payment_method_id'];
                        if (isset($payment_methods_map[$payment_method_id])) {
                            $payment_method_name = $payment_methods_map[$payment_method_id];
                        }
                    }
                }
                
                $payment['payment_method_id'] = $payment_method_id;
                $payment['payment_method_name'] = $payment_method_name;
            }
            unset($payment);
            
            $income['payment_history'] = $payment_history;
        } catch (PDOException $e) {
            error_log("Error fetching payment history for income $income_id: " . $e->getMessage());
            $income['payment_history'] = [];
        }
    }
    unset($income);
} catch (PDOException $e) {
    error_log("Error fetching income records: " . $e->getMessage());
}

$patient_total_income = 0;
$patient_total_visits = count($income_records);
foreach ($income_records as $income) {
    $patient_total_income += (float) ($income['dcmt_total_paid_amount'] ?? $income['dcmt_paid_amount'] ?? 0);
}

// Pagination settings for treatment history (income records)
$treatment_per_page = 10;
$treatment_page = isset($_GET['treatment_page']) ? max(1, (int) $_GET['treatment_page']) : 1;
$treatment_total = count($income_records);
$treatment_total_pages = max(1, (int) ceil($treatment_total / $treatment_per_page));
if ($treatment_page > $treatment_total_pages) {
    $treatment_page = $treatment_total_pages;
}
$treatment_offset = ($treatment_page - 1) * $treatment_per_page;
$income_records_paginated = array_slice($income_records, $treatment_offset, $treatment_per_page);

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-history dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('patient', 'history'); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($patient_full_name); ?></small>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <a href="view.php?id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-user-injured me-1"></i><?php echo trans('patient', 'view_profile'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('patient', 'back_to_patients'); ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <!-- Patient Details Section -->
        <div class="mb-4">
            <h5 class="mb-3">
                <i class="fas fa-user me-2"></i><?php echo trans('patient', 'patient_details'); ?>
            </h5>
            <div class="row">
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'patient_name'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'email'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_email'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'phone'); ?>:</span>
                        <div class="dcmt-view-field-value">
                            <?php
                            $phone = $patient['dcmt_phone'] ?? '';
                            if ($phone) {
                                $digits = preg_replace('/\D+/', '', $phone);
                                if ($digits !== '') {
                                    $wa_link = 'https://wa.me/' . $digits;
                                    echo '<a href="' . htmlspecialchars($wa_link) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($phone) . '</a>';
                                } else {
                                    echo htmlspecialchars($phone);
                                }
                            } else {
                                echo '-';
                            }
                            ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'gender'); ?>:</span>
                        <div class="dcmt-view-field-value text-capitalize"><?php echo htmlspecialchars($patient['dcmt_gender'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'date_of_birth'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo !empty($patient['dcmt_date_of_birth']) ? dcmt_format_date($patient['dcmt_date_of_birth']) : '-'; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'age'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo isset($patient['dcmt_age']) && $patient['dcmt_age'] !== null ? htmlspecialchars($patient['dcmt_age']) : '-'; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'height'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo isset($patient['dcmt_height_cm']) && $patient['dcmt_height_cm'] !== null ? htmlspecialchars($patient['dcmt_height_cm']) . ' cm' : '-'; ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'weight'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo isset($patient['dcmt_weight_kg']) && $patient['dcmt_weight_kg'] !== null ? htmlspecialchars($patient['dcmt_weight_kg']) . ' kg' : '-'; ?></div>
                    </div>
                </div>
            </div>
        </div>
        
        <div class="mb-4">
            <h5 class="mb-3">
                <i class="fas fa-chart-line me-2"></i><?php echo trans('patient', 'patient_statistics'); ?>
            </h5>
            <div class="row">
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'total_income'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($patient_total_income); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'total_visits'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo (int) $patient_total_visits; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Patient Notes Section -->
        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-sticky-note me-2"></i><?php echo trans('patient_note', 'patient_notes'); ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($patient_notes); ?></span>
                </h5>
                <a href="../patient_notes/index.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-list me-1"></i><?php echo trans('patient_note', 'view_all_notes'); ?>
                </a>
            </div>
            <?php if (empty($patient_notes_paginated)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i><?php echo trans('patient_note', 'no_notes_found'); ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th><?php echo trans('patient_note', 'note_date'); ?></th>
                                <th><?php echo trans('patient_note', 'topic'); ?></th>
                                <th><?php echo trans('common', 'created_by'); ?></th>
                                <th><?php echo trans('common', 'actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patient_notes_paginated as $note): ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($note['dcmt_note_date']); ?></td>
                                    <td>
                                        <?php if (!empty($note['dcmt_topic'])): ?>
                                            <strong><?php echo htmlspecialchars($note['dcmt_topic']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($note['created_by_name'] ?? $note['dcmt_created_by'] ?? '-'); ?></td>
                                    <td>
                                        <a href="../patient_notes/view.php?id=<?php echo $note['dcmt_id']; ?>" 
                                           class="dcmt-add-form-view-all-link" 
                                           title="<?php echo trans('common', 'view'); ?>">
                                            <i class="fas fa-eye me-1"></i><?php echo trans('common', 'view'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($notes_total_pages > 1): ?>
                    <nav aria-label="Patient notes pagination">
                        <ul class="pagination pagination-sm mb-0 mt-2 justify-content-center">
                            <!-- First Page -->
                            <?php if ($notes_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=1&treatment_page=<?php echo $treatment_page; ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                        <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Previous Page -->
                            <?php if ($notes_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page - 1; ?>&treatment_page=<?php echo $treatment_page; ?>" title="<?php echo trans('common', 'previous'); ?>">
                                        <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <?php for ($p = max(1, $notes_page - 2); $p <= min($notes_total_pages, $notes_page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $notes_page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $p; ?>&treatment_page=<?php echo $treatment_page; ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <?php if ($notes_page < $notes_total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page + 1; ?>&treatment_page=<?php echo $treatment_page; ?>" title="<?php echo trans('common', 'next'); ?>">
                                        <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Last Page -->
                            <?php if ($notes_page < $notes_total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_total_pages; ?>&treatment_page=<?php echo $treatment_page; ?>" title="<?php echo trans('common', 'last_page'); ?>">
                                        <?php echo trans('common', 'last_page'); ?> <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>

        <!-- Treatment History Section -->
        <div class="mb-4">
            <h5 class="mb-3">
                <i class="fas fa-file-medical me-2"></i><?php echo trans('patient', 'treatment_history'); ?>
                <span class="badge bg-secondary ms-2"><?php echo count($income_records); ?></span>
            </h5>
            <?php if (empty($income_records_paginated)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i><?php echo trans('patient', 'no_treatment_history'); ?>
                </div>
            <?php else: ?>
                <?php foreach ($income_records_paginated as $income): ?>
                    <div class="card mb-3">
                        <div class="card-header bg-light">
                            <div class="d-flex justify-content-between align-items-center">
                                <div>
                                    <strong><?php echo trans('common', 'date'); ?>: <?php echo dcmt_format_date($income['dcmt_transaction_date']); ?></strong>
                                </div>
                                <div>
                                    <a href="../income/view.php?id=<?php echo $income['dcmt_id']; ?>" class="dcmt-add-form-view-all-link">
                                        <i class="fas fa-eye me-1"></i><?php echo trans('common', 'view_details'); ?>
                                    </a>
                                </div>
                            </div>
                        </div>
                        <div class="card-body">
                            <?php if (!empty($income['doctor_name'])): ?>
                                <p class="mb-2"><strong><?php echo trans('income', 'doctor'); ?>:</strong> <?php echo htmlspecialchars($income['doctor_name']); ?></p>
                            <?php endif; ?>
                            
                            <?php if (!empty($income['breakdown_items'])): ?>
                                <div class="mt-3">
                                    <strong><?php echo trans('income', 'services_products'); ?>:</strong>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th><?php echo trans('common', 'type'); ?></th>
                                                    <th><?php echo trans('common', 'name'); ?></th>
                                                    <th><?php echo trans('income', 'quantity'); ?></th>
                                                    <th><?php echo trans('income', 'unit_price'); ?></th>
                                                    <th><?php echo trans('income', 'total'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_services_products = 0;
                                                foreach ($income['breakdown_items'] as $item): 
                                                    $line_total = floatval($item['dcmt_line_total'] ?? 0);
                                                    $total_services_products += $line_total;
                                                ?>
                                                <tr>
                                                    <td>
                                                        <?php echo trans('income', $item['dcmt_line_type']); ?>
                                                    </td>
                                                    <td>
                                                        <?php 
                                                        if ($item['dcmt_line_type'] === 'service') {
                                                            echo htmlspecialchars($item['service_name'] ?? $item['dcmt_label'] ?? '-');
                                                            if (!empty($item['doctor_name'])) {
                                                                echo ' <small class="text-muted">(' . htmlspecialchars($item['doctor_name']) . ')</small>';
                                                            }
                                                        } else {
                                                            echo htmlspecialchars($item['product_name'] ?? $item['dcmt_label'] ?? '-');
                                                        }
                                                        ?>
                                                    </td>
                                                    <td><?php echo htmlspecialchars($item['dcmt_quantity'] ?? '0'); ?></td>
                                                    <td><?php echo dcmt_format_currency($item['dcmt_unit_price'] ?? 0); ?></td>
                                                    <td><?php echo dcmt_format_currency($line_total); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="4" class="text-end"><strong><?php echo trans('income', 'total'); ?>:</strong></td>
                                                    <td><strong><?php echo dcmt_format_currency($total_services_products); ?></strong></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <?php if (!empty($income['payment_history'])): ?>
                                <div class="mt-3">
                                    <strong><?php echo trans('income', 'payment_history'); ?>:</strong>
                                    <div class="table-responsive mt-2">
                                        <table class="table table-sm table-bordered">
                                            <thead>
                                                <tr>
                                                    <th><?php echo trans('income', 'payment_date'); ?></th>
                                                    <th><?php echo trans('income', 'payment_method'); ?></th>
                                                    <th><?php echo trans('income', 'recorded_by'); ?></th>
                                                    <th><?php echo trans('income', 'payment_amount'); ?></th>
                                                </tr>
                                            </thead>
                                            <tbody>
                                                <?php 
                                                $total_paid = 0;
                                                foreach ($income['payment_history'] as $payment): 
                                                    $recorded_name = $payment['recorded_by_name'] ?? $payment['dcmt_recorded_by'] ?? 'N/A';
                                                    $payment_method_name = $payment['payment_method_name'] ?? '';
                                                    if ($payment_method_name) {
                                                        $payment_method_display = $payment_method_name;
                                                    } else {
                                                        $payment_method_display = 'N/A';
                                                    }
                                                    $amount = floatval($payment['dcmt_amount'] ?? 0);
                                                    $total_paid += $amount;
                                                ?>
                                                <tr>
                                                    <td><?php echo dcmt_format_date($payment['dcmt_paid_on']); ?></td>
                                                    <td><?php echo htmlspecialchars($payment_method_display); ?></td>
                                                    <td><?php echo htmlspecialchars($recorded_name); ?></td>
                                                    <td><?php echo dcmt_format_currency($amount); ?></td>
                                                </tr>
                                                <?php endforeach; ?>
                                            </tbody>
                                            <tfoot>
                                                <tr>
                                                    <td colspan="3" class="text-end"><strong><?php echo trans('income', 'total'); ?>:</strong></td>
                                                    <td><strong><?php echo dcmt_format_currency($total_paid); ?></strong></td>
                                                </tr>
                                            </tfoot>
                                        </table>
                                    </div>
                                </div>
                            <?php endif; ?>
                            
                            <div class="mt-2">
                                <?php 
                                $total_paid = $income['dcmt_total_paid_amount'] ?? $income['dcmt_paid_amount'] ?? 0;
                                $total_pending = $income['dcmt_total_pending_amount'] ?? $income['dcmt_pending_amount'] ?? 0;
                                $payment_status = htmlspecialchars($income['payment_status_name'] ?? '-');
                                $pending_color = (floatval($total_pending) == 0) ? 'text-success' : 'text-danger';
                                $status_color = (strtolower($payment_status) == 'pending') ? 'text-danger' : (strtolower($payment_status) == 'completed' ? 'text-success' : '');
                                ?>
                                <small class="text-muted">
                                    <strong><?php echo trans('income', 'total_paid_amount'); ?>:</strong> 
                                    <?php echo dcmt_format_currency($total_paid); ?>
                                    | <strong><?php echo trans('income', 'total_pending_amount'); ?>:</strong> 
                                    <span class="<?php echo $pending_color; ?>"><?php echo dcmt_format_currency($total_pending); ?></span>
                                    | <strong><?php echo trans('income', 'payment_status'); ?>:</strong> 
                                    <span class="<?php echo $status_color; ?>"><?php echo $payment_status; ?></span>
                                </small>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
                <?php if ($treatment_total_pages > 1): ?>
                    <nav aria-label="Treatment history pagination">
                        <ul class="pagination pagination-sm mb-0 justify-content-center">
                            <!-- First Page -->
                            <?php if ($treatment_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page; ?>&treatment_page=1" title="<?php echo trans('common', 'first_page'); ?>">
                                        <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Previous Page -->
                            <?php if ($treatment_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page; ?>&treatment_page=<?php echo $treatment_page - 1; ?>" title="<?php echo trans('common', 'previous'); ?>">
                                        <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Page Numbers -->
                            <?php for ($p = max(1, $treatment_page - 2); $p <= min($treatment_total_pages, $treatment_page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $treatment_page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page; ?>&treatment_page=<?php echo $p; ?>"><?php echo $p; ?></a>
                                </li>
                            <?php endfor; ?>
                            
                            <!-- Next Page -->
                            <?php if ($treatment_page < $treatment_total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page; ?>&treatment_page=<?php echo $treatment_page + 1; ?>" title="<?php echo trans('common', 'next'); ?>">
                                        <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                            
                            <!-- Last Page -->
                            <?php if ($treatment_page < $treatment_total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page; ?>&treatment_page=<?php echo $treatment_total_pages; ?>" title="<?php echo trans('common', 'last_page'); ?>">
                                        <?php echo trans('common', 'last_page'); ?> <i class="fas fa-angle-double-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </nav>
                <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

