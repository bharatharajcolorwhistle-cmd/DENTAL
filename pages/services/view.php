<?php
/**
 * View Service Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Get service ID from URL
$service_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($service_id <= 0) {
    dcmt_show_message(trans('service', 'invalid_service_id'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Fetch service details
try {
    $sql = "SELECT * FROM dcmt_services WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$service_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        dcmt_show_message(trans('service', 'service_not_found'), "error");
        dcmt_redirect("index.php");
    }
} catch (PDOException $e) {
    dcmt_show_message(trans('service', 'database_error'), "error");
    dcmt_redirect("index.php");
}

// Get service usage statistics
try {
    $usage_count_sql = "SELECT COUNT(*) as usage_count FROM dcmt_income WHERE dcmt_service_id = ?";
    $usage_count_stmt = $dcmt_pdo->prepare($usage_count_sql);
    $usage_count_stmt->execute([$service_id]);
    $usage_count = $usage_count_stmt->fetchColumn();
    
    $total_revenue_sql = "SELECT SUM(dcmt_paid_amount) as total_revenue FROM dcmt_income WHERE dcmt_service_id = ?";
    $total_revenue_stmt = $dcmt_pdo->prepare($total_revenue_sql);
    $total_revenue_stmt->execute([$service_id]);
    $total_revenue = $total_revenue_stmt->fetchColumn() ?: 0;
    
    $average_price_sql = "SELECT AVG(dcmt_amount) as average_price FROM dcmt_income WHERE dcmt_service_id = ?";
    $average_price_stmt = $dcmt_pdo->prepare($average_price_sql);
    $average_price_stmt->execute([$service_id]);
    $average_price = $average_price_stmt->fetchColumn() ?: 0;
} catch (PDOException $e) {
    $usage_count = 0;
    $total_revenue = 0;
    $average_price = 0;
}

// Get doctor role users assigned to this service
try {
    $doctors_sql = "
        SELECT u.dcmt_id as user_id, 
               u.dcmt_full_name as dcmt_name, 
               u.dcmt_status, 
               ds.dcmt_price, 
               ds.dcmt_status as assignment_status,
               COUNT(DISTINCT i.dcmt_id) as usage_count
        FROM dcmt_doctor_services ds
        INNER JOIN dcmt_users u ON ds.dcmt_user_id = u.dcmt_id AND u.dcmt_role = 'doctor'
        LEFT JOIN dcmt_income i ON i.dcmt_service_id = ds.dcmt_service_id
            AND i.dcmt_user_id = u.dcmt_id
        WHERE ds.dcmt_service_id = ? AND ds.dcmt_status = 'active'
        GROUP BY u.dcmt_id, u.dcmt_full_name, u.dcmt_status, ds.dcmt_price, ds.dcmt_status
        ORDER BY u.dcmt_full_name
    ";
    $doctors_stmt = $dcmt_pdo->prepare($doctors_sql);
    $doctors_stmt->execute([$service_id]);
    $assigned_doctors = $doctors_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $assigned_doctors = [];
    error_log("Error fetching assigned doctors: " . $e->getMessage());
}

// Get recent income records for this service (with matching user_id if available)
try {
    $recent_income_sql = "
        SELECT i.*, 
               u_doctor.dcmt_full_name as doctor_name,
               u_doctor.dcmt_id as user_id,
               p.dcmt_name as patient_name
        FROM dcmt_income i
        LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        LEFT JOIN dcmt_patients p ON i.dcmt_patient_id = p.dcmt_id
        WHERE i.dcmt_service_id = ?
        ORDER BY i.dcmt_created_at DESC
        LIMIT 10
    ";
    $recent_income_stmt = $dcmt_pdo->prepare($recent_income_sql);
    $recent_income_stmt->execute([$service_id]);
    $recent_income_records = $recent_income_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $recent_income_records = [];
    error_log("Error fetching recent income records: " . $e->getMessage());
}

// Log activity
dcmt_log_activity("Viewed service: " . $service['dcmt_name'], "service_viewed");

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-concierge-bell dcmt-view-card-title-icon"></i><?php echo trans('service', 'service_details'); ?>
                </h6>
                <div class="dcmt-view-header-links">
                    <a href="edit.php?id=<?php echo $service['dcmt_id']; ?>" class="dcmt-add-form-view-all-link me-3">
                        <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                    </a>
                    <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('service', 'back_to_services'); ?></a>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('service', 'service_name'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($service['dcmt_name']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'status'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php
                                $status_class = match($service['dcmt_status']) {
                                    'active' => 'dcmt-view-field-value-status-completed',
                                    'inactive' => 'dcmt-view-field-value',
                                    default => 'dcmt-view-field-value'
                                };
                                ?>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo ucfirst(htmlspecialchars($service['dcmt_status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('service', 'base_price'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span class="text-success fw-bold">
                                    <?php echo dcmt_format_currency($service['dcmt_base_price']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <?php if (!empty($service['dcmt_description'])): ?>
                            <div class="dcmt-view-field">
                                <span class="dcmt-view-field-label"><?php echo trans('common', 'description'); ?>:</span>
                                <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($service['dcmt_description'])); ?></div>
                            </div>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>
        
        <!-- Service Statistics Card -->
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-chart-bar dcmt-view-card-title-icon"></i><?php echo trans('service', 'service_statistics'); ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('service', 'times_used'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span class="text-info fw-bold"><?php echo number_format($usage_count); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('service', 'total_revenue'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span class="text-success fw-bold"><?php echo dcmt_format_currency($total_revenue); ?></span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('service', 'average_price'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php 
                                if ($usage_count > 0) {
                                    echo '<span class="text-warning fw-bold">' . dcmt_format_currency($average_price) . '</span>';
                                } else {
                                    echo '<span class="text-muted">' . trans('service', 'no_usage_data') . '</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <!-- Assigned Doctors Card -->
        <div class="card mt-4 dcmt-records-table" style="display: none;">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-user-md dcmt-view-card-title-icon"></i><?php echo trans('service', 'assigned_doctors'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($assigned_doctors)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-user-md fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted"><?php echo trans('service', 'no_doctors_assigned'); ?></h5>
                        <p class="text-muted"><?php echo trans('service', 'no_doctors_assigned_message'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo trans('doctor', 'doctor_name'); ?></th>
                                    <th><?php echo trans('common', 'status'); ?></th>
                                    <th><?php echo trans('service', 'custom_price'); ?></th>
                                    <th><?php echo trans('service', 'times_used'); ?></th>
                                    <th><?php echo trans('common', 'actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($assigned_doctors as $doctor): ?>
                                    <tr>
                                        <td>
                                            <a href="../users/view.php?id=<?php echo $doctor['user_id']; ?>" class="text-decoration-none">
                                                <?php echo htmlspecialchars($doctor['dcmt_name']); ?>
                                            </a>
                                        </td>
                                        <td>
                                            <span class="text-<?php echo $doctor['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo ucfirst(htmlspecialchars($doctor['dcmt_status'])); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-success fw-bold">
                                                <?php echo dcmt_format_currency($doctor['dcmt_price']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-info"><?php echo $doctor['usage_count']; ?> <?php echo trans('service', 'times'); ?></span>
                                        </td>
                                        <td>
                                            <a href="../users/view.php?id=<?php echo $doctor['user_id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php echo trans('common', 'view'); ?>">
                                                <i class="fas fa-eye"></i>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Recent Usage Card -->
        <?php if (!empty($recent_income_records)): ?>
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-history dcmt-view-card-title-icon"></i><?php echo trans('service', 'recent_usage'); ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="table-responsive">
                    <table class="table table-hover">
                        <thead>
                            <tr>
                                <th><?php echo trans('common', 'date'); ?></th>
                                <th><?php echo trans('doctor', 'doctor_name'); ?></th>
                                <th><?php echo trans('income', 'patient_name'); ?></th>
                                <th><?php echo trans('common', 'amount'); ?></th>
                                <th><?php echo trans('income', 'payment_status'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($recent_income_records as $record): ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($record['dcmt_created_at']); ?></td>
                                    <td>
                                        <?php if (!empty($record['doctor_name'])): ?>
                                            <?php if (!empty($record['user_id'])): ?>
                                                <a href="../users/view.php?id=<?php echo $record['user_id']; ?>" class="text-decoration-none">
                                                    <?php echo htmlspecialchars($record['doctor_name']); ?>
                                                </a>
                                            <?php else: ?>
                                                <?php echo htmlspecialchars($record['doctor_name']); ?>
                                            <?php endif; ?>
                                        <?php else: ?>
                                            <span class="text-muted"><?php echo trans('common', 'not_specified'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <?php if (!empty($record['patient_name'])): ?>
                                            <?php echo htmlspecialchars($record['patient_name']); ?>
                                        <?php else: ?>
                                            <span class="text-muted"><?php echo trans('common', 'not_specified'); ?></span>
                                        <?php endif; ?>
                                    </td>
                                    <td>
                                        <span class="text-success fw-bold">
                                            <?php echo dcmt_format_currency($record['dcmt_amount']); ?>
                                        </span>
                                    </td>
                                    <td>
                                        <span class="text-<?php echo $record['dcmt_payment_status'] === 'paid' ? 'success' : ($record['dcmt_payment_status'] === 'pending' ? 'warning' : 'secondary'); ?>">
                                            <?php echo ucfirst(htmlspecialchars($record['dcmt_payment_status'])); ?>
                                        </span>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <?php endif; ?>
    </div>
</div>

<script>
function confirmDelete(serviceId) {
    if (confirm('<?php echo trans('service', 'confirm_delete'); ?>')) {
        window.location.href = 'delete.php?id=' + serviceId;
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
