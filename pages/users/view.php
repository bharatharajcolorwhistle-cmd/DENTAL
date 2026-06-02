<?php
/**
 * View User Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/doctor_goal_functions.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check admin access
dcmt_require_admin_or_doctor();
$current_viewer = dcmt_get_current_user();
$viewer_is_admin = dcmt_is_admin();

// Get user ID from URL
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    dcmt_show_message(trans('user', 'invalid_user_id'), "error");
    dcmt_redirect("index.php");
    exit();
}

if (!$viewer_is_admin && (int) ($current_viewer['dcmt_id'] ?? 0) !== (int) $user_id) {
    dcmt_show_message('Access denied. You can only view your own account.', "error");
    dcmt_redirect("index.php");
    exit();
}

// Get user details with specialization
try {
    $sql = "SELECT u.*, s.dcmt_name as specialization_name 
            FROM dcmt_users u 
            LEFT JOIN dcmt_doctor_specializations s ON u.dcmt_specialization_id = s.dcmt_id 
            WHERE u.dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        dcmt_show_message(trans('user', 'user_not_found'), "error");
        dcmt_redirect("index.php");
        exit();
    }
    
    $is_doctor_user = $user['dcmt_role'] === 'doctor';
    $is_staff_user = $user['dcmt_role'] === 'staff';
    $is_assistant_user = $user['dcmt_role'] === 'assistant';
    $can_view_doctor_sections = $viewer_is_admin || ($current_viewer && (int)$current_viewer['dcmt_id'] === (int)$user_id);
    $show_monthly_goal_card = ($is_doctor_user && $can_view_doctor_sections) || (($is_staff_user || $is_assistant_user) && $viewer_is_admin);
    $doctor_goal_month = dcmt_goal_normalize_month(date('Y-m-01'));
    $doctor_goal_details = null;
    $doctor_goal_amount = 0.0;
    $doctor_goal_actual = 0.0;
    $doctor_goal_remaining = 0.0;
    $doctor_goal_progress_percent = 0;
    $doctor_goal_progress_bar_width = 0;
    $doctor_goal_status_label = trans('user', 'doctor_goal_not_set');
    $doctor_goal_status_class = 'text-muted';
    $doctor_goal_is_appointments_metric = false;
    
    // Get user activity statistics
    $activity_stats = [];
    
    
    // Count income records created by this user
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_income WHERE dcmt_created_by = ?");
    $stmt->execute([$user['dcmt_username']]);
    $activity_stats['income_records'] = $stmt->fetchColumn();
    
    // Count expense records created by this user
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_expenses WHERE dcmt_created_by = ?");
    $stmt->execute([$user['dcmt_username']]);
    $activity_stats['expense_records'] = $stmt->fetchColumn();
    
    // Count inventory items created by this user
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_inventory WHERE dcmt_created_by = ?");
    $stmt->execute([$user['dcmt_username']]);
    $activity_stats['inventory_items'] = $stmt->fetchColumn();
    
    // Count doctor role users created by this user
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_created_by = ? AND dcmt_role = 'doctor'");
    $stmt->execute([$user['dcmt_username']]);
    $activity_stats['doctor_records'] = $stmt->fetchColumn();
    
    // If user is a doctor, get consultation statistics
    $consultation_count = 0;
    $total_earnings = 0;
    $doctor_services = [];
    
    if ($is_doctor_user) {
        try {
            // Count service items from breakdown table where this user is assigned
            $consultation_count_sql = "
                SELECT COUNT(*) as consultation_count 
                FROM dcmt_income_breakdown ib
                INNER JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
                WHERE ib.dcmt_line_type = 'service' 
                AND ib.dcmt_user_id = ?
                AND (i.dcmt_type = 'consultation' OR i.dcmt_type = 'mixed')
            ";
            $consultation_count_stmt = $dcmt_pdo->prepare($consultation_count_sql);
            $consultation_count_stmt->execute([$user_id]);
            $consultation_count = $consultation_count_stmt->fetchColumn();
            
            // Sum earnings from service items in breakdown table
            $total_earnings_sql = "
                SELECT COALESCE(SUM(
                    CASE 
                        WHEN COALESCE(i.dcmt_service_amount, 0) > 0 
                            THEN ib.dcmt_line_total * (COALESCE(i.dcmt_service_paid_amount, 0) / NULLIF(i.dcmt_service_amount, 0))
                        ELSE 0
                    END
                ), 0) as total_earnings 
                FROM dcmt_income_breakdown ib
                INNER JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
                WHERE ib.dcmt_line_type = 'service' 
                AND ib.dcmt_user_id = ?
                AND (i.dcmt_type = 'consultation' OR i.dcmt_type = 'mixed')
            ";
            $total_earnings_stmt = $dcmt_pdo->prepare($total_earnings_sql);
            $total_earnings_stmt->execute([$user_id]);
            $total_earnings = $total_earnings_stmt->fetchColumn() ?: 0;
            
            // Get assigned services for this user (doctor role)
            $services_sql = "
                SELECT s.dcmt_id, s.dcmt_name, s.dcmt_description, ds.dcmt_price, ds.dcmt_status,
                       COUNT(ib.dcmt_id) as usage_count,
                       COALESCE(SUM(
                           CASE 
                               WHEN COALESCE(i.dcmt_service_amount, 0) > 0 
                                    THEN ib.dcmt_line_total * (COALESCE(i.dcmt_service_paid_amount, 0) / NULLIF(i.dcmt_service_amount, 0))
                               ELSE 0
                           END
                       ), 0) as total_paid
                FROM dcmt_doctor_services ds
                INNER JOIN dcmt_services s ON ds.dcmt_service_id = s.dcmt_id
                LEFT JOIN dcmt_income_breakdown ib ON (
                    ib.dcmt_reference_id = s.dcmt_id 
                    AND ib.dcmt_line_type = 'service'
                    AND ib.dcmt_user_id = ?
                )
                LEFT JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id AND (i.dcmt_type = 'consultation' OR i.dcmt_type = 'mixed')
                WHERE ds.dcmt_user_id = ? AND ds.dcmt_status = 'active'
                GROUP BY s.dcmt_id, s.dcmt_name, s.dcmt_description, ds.dcmt_price, ds.dcmt_status
                ORDER BY s.dcmt_name
            ";
            $services_stmt = $dcmt_pdo->prepare($services_sql);
            $services_stmt->execute([$user_id, $user_id]);
            $doctor_services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log("Error fetching doctor consultation info: " . $e->getMessage());
        }
    }

    if ($show_monthly_goal_card) {
        $doctor_goal_details = dcmt_get_doctor_goal_details($dcmt_pdo, $user_id, $doctor_goal_month);
        $doctor_goal_amount = $doctor_goal_details ? (float) $doctor_goal_details['dcmt_goal_amount'] : 0.0;
        $doctor_goal_is_appointments_metric = $is_staff_user || $is_assistant_user || (($doctor_goal_details['dcmt_goal_metric'] ?? 'income') === 'appointments');

        if ($doctor_goal_is_appointments_metric) {
            $doctor_goal_appt_role = $is_staff_user ? 'staff' : 'assistant';
            $doctor_goal_actual_map = dcmt_fetch_staff_goal_appointment_counts(
                $dcmt_pdo,
                $doctor_goal_month,
                [$user_id => $doctor_goal_appt_role]
            );
        } else {
            $doctor_goal_actual_map = dcmt_fetch_doctor_goal_actuals($dcmt_pdo, $doctor_goal_month, [$user_id]);
        }
        $doctor_goal_actual = $doctor_goal_actual_map[$user_id] ?? 0.0;
        $doctor_goal_remaining = max($doctor_goal_amount - $doctor_goal_actual, 0);
        if ($doctor_goal_amount > 0) {
            [, $doctor_goal_month_end] = dcmt_goal_month_bounds($doctor_goal_month);
            $doctor_goal_month_is_ended = date('Y-m-d') >= $doctor_goal_month_end;
            $doctor_goal_progress_percent = ($doctor_goal_actual / $doctor_goal_amount) * 100;
            $doctor_goal_progress_bar_width = min(100, $doctor_goal_progress_percent);
            if ($doctor_goal_month_is_ended) {
                $doctor_goal_status_label = trans('user', 'doctor_goal_ended');
                $doctor_goal_status_class = 'text-secondary';
            } elseif ($doctor_goal_actual >= $doctor_goal_amount) {
                $doctor_goal_status_label = trans('user', 'doctor_goal_met');
                $doctor_goal_status_class = 'text-success';
            } elseif ($doctor_goal_actual > 0) {
                $doctor_goal_status_label = trans('user', 'doctor_goal_in_progress');
                $doctor_goal_status_class = 'text-warning';
            } else {
                $doctor_goal_status_label = trans('user', 'doctor_goal_shortfall');
                $doctor_goal_status_class = 'text-danger';
            }
        } else {
            $doctor_goal_progress_percent = 0;
            $doctor_goal_progress_bar_width = 0;
            $doctor_goal_status_label = trans('user', 'doctor_goal_not_set');
            $doctor_goal_status_class = 'text-muted';
        }
    }
    
    
} catch (PDOException $e) {
    dcmt_show_message(trans('user', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-user dcmt-view-card-title-icon"></i><?php echo trans('user', 'user_information'); ?>
                </h6>
                <div class="dcmt-view-header-links">
                    <a href="edit.php?id=<?php echo $user['dcmt_id']; ?>" class="dcmt-add-form-view-all-link me-3">
                        <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                    </a>
                    <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('user', 'back_to_users'); ?></a>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('user', 'username'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($user['dcmt_username']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('user', 'full_name'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($user['dcmt_full_name']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('user', 'email_address'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <a href="mailto:<?php echo htmlspecialchars($user['dcmt_email']); ?>" style="color: #007bff; text-decoration: none;">
                                    <?php echo htmlspecialchars($user['dcmt_email']); ?>
                                </a>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('user', 'phone'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php if ($user['dcmt_phone']): ?>
                                    <a href="tel:<?php echo htmlspecialchars($user['dcmt_phone']); ?>" style="color: #007bff; text-decoration: none;">
                                        <?php echo htmlspecialchars($user['dcmt_phone']); ?>
                                    </a>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo trans('user', 'not_provided'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('user', 'role'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span>
                                    <?php echo trans('user', $user['dcmt_role']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'status'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php
                                $status_class = match($user['dcmt_status']) {
                                    'active' => 'dcmt-view-field-value-status-completed',
                                    'inactive' => 'dcmt-view-field-value',
                                    default => 'dcmt-view-field-value'
                                };
                                ?>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo ucfirst($user['dcmt_status']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <?php if ($is_doctor_user): ?>
                <!-- Doctor-specific fields -->
                <div class="row">
                    <?php if (!empty($user['dcmt_qualification'])): ?>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('doctor', 'qualification'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo htmlspecialchars($user['dcmt_qualification']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if (!empty($user['specialization_name'])): ?>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('doctor', 'specialization'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo htmlspecialchars($user['specialization_name']); ?>
                            </div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('user', 'last_login'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php if ($user['dcmt_last_login']): ?>
                                    <span title="<?php echo $user['dcmt_last_login']; ?>">
                                        <?php echo dcmt_format_date($user['dcmt_last_login'], DCMT_DATETIME_FORMAT); ?>
                                    </span>
                                <?php else: ?>
                                    <span class="text-muted"><?php echo trans('user', 'never'); ?></span>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'created_at'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span title="<?php echo $user['dcmt_created_at']; ?>">
                                    <?php echo dcmt_format_date($user['dcmt_created_at']); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <!-- Empty column for balance -->
                    </div>
                </div>
                
                <?php if ($user['dcmt_address']): ?>
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('user', 'address'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($user['dcmt_address'])); ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if ($user['dcmt_notes']): ?>
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('user', 'notes'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($user['dcmt_notes'])); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <?php if ($show_monthly_goal_card): ?>
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <div class="dcmt-view-card-header-content">
                    <div>
                        <h6 class="dcmt-view-card-title mb-0">
                            <?php echo trans('user', 'doctor_goal_summary'); ?>
                        </h6>
                    </div>
                    <div class="ms-3 d-flex gap-2">
                        <?php if (dcmt_is_admin()): ?>
                            <a href="../doctor_goals/index.php?goal_month=<?php echo date('Y-m', strtotime($doctor_goal_month)); ?>" class="dcmt-add-form-view-all-link">
                                <i class="fas fa-edit me-1"></i><?php echo trans('user', 'manage_doctor_goals'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="border py-2 px-3 small mb-3">
                    <div class="d-flex gap-2 mb-0">
                        <?php if ($is_assistant_user): ?>
                            <i class="fas fa-star text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                            <div><?php echo trans('user', 'monthly_goal_help_assistant'); ?></div>
                        <?php elseif ($is_staff_user): ?>
                            <i class="fas fa-calendar-check text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                            <div><?php echo trans('user', 'monthly_goal_help_staff'); ?></div>
                        <?php else: ?>
                            <i class="fas fa-stethoscope text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                            <div><?php echo trans('user', 'monthly_goal_help_doctor'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                <?php if ($doctor_goal_amount <= 0): ?>
                    <div class="text-center py-3">
                        <p class="text-muted mb-2"><?php echo trans('user', 'doctor_goal_set_cta'); ?></p>
                        <?php if (dcmt_is_admin()): ?>
                            <a href="../doctor_goals/index.php" class="btn btn-primary btn-sm">
                                <i class="fas fa-plus me-1"></i><?php echo trans('user', 'doctor_goal_link'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <div class="row g-3 align-items-center">
                        <div class="col-md-2">
                            <div class="dcmt-view-field mb-0">
                                <span class="dcmt-view-field-label"><?php
                                    if (!$doctor_goal_is_appointments_metric) {
                                        echo trans('user', 'goal_amount');
                                    } elseif ($is_assistant_user) {
                                        echo trans('user', 'goal_appointments_target');
                                    } else {
                                        echo trans('user', 'goal_staff_completed_appointments_target');
                                    }
                                ?></span>
                                <div class="dcmt-view-field-value">
                                    <?php echo $doctor_goal_is_appointments_metric ? number_format($doctor_goal_amount, 0) : dcmt_format_currency($doctor_goal_amount); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="dcmt-view-field mb-0">
                                <span class="dcmt-view-field-label"><?php
                                    if (!$doctor_goal_is_appointments_metric) {
                                        echo trans('user', 'actual_amount');
                                    } elseif ($is_assistant_user) {
                                        echo trans('user', 'actual_appointments_count');
                                    } else {
                                        echo trans('user', 'actual_completed_appointments_count');
                                    }
                                ?></span>
                                <div class="dcmt-view-field-value text-success">
                                    <?php echo $doctor_goal_is_appointments_metric ? number_format($doctor_goal_actual, 0) : dcmt_format_currency($doctor_goal_actual); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="dcmt-view-field mb-0">
                                <span class="dcmt-view-field-label"><?php echo trans('user', 'goal_remaining'); ?></span>
                                <div class="dcmt-view-field-value">
                                    <?php echo $doctor_goal_is_appointments_metric ? number_format(max($doctor_goal_remaining, 0), 0) : dcmt_format_currency($doctor_goal_remaining); ?>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-2">
                            <div class="dcmt-view-field mb-0">
                                <span class="dcmt-view-field-label"><?php echo trans('user', 'goal_status'); ?></span>
                                <div class="dcmt-view-field-value">
                                    <span class="<?php echo $doctor_goal_status_class; ?>">
                                        <?php echo htmlspecialchars($doctor_goal_status_label); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="dcmt-view-field mb-0">
                                <span class="dcmt-view-field-label d-flex justify-content-between align-items-center">
                                    <span><?php echo trans('user', 'goal_progress'); ?></span>
                                    <span><?php echo number_format($doctor_goal_progress_percent, 0); ?>%</span>
                                </span>
                                <div class="progress" style="height: 18px;">
                                    <div class="progress-bar <?php echo $doctor_goal_progress_percent >= 100 ? 'bg-success' : 'bg-info'; ?>"
                                         role="progressbar"
                                         style="width: <?php echo $doctor_goal_progress_bar_width; ?>%;"
                                         aria-valuenow="<?php echo (int) round($doctor_goal_progress_bar_width); ?>"
                                         aria-valuemin="0"
                                         aria-valuemax="100">
                                         <?php echo number_format($doctor_goal_progress_percent, 0); ?>%
                                    </div>
                                </div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_doctor_user && $can_view_doctor_sections): ?>
        <!-- Services Information Card -->
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                   <?php echo trans('service', 'services_assigned'); ?>
                </h6>
                <a href="manage_services.php?id=<?php echo $user_id; ?>" class="dcmt-add-form-view-all-link">
                    <?php echo trans('service', 'manage_services'); ?>
                </a>
            </div>
            <div class="card-body">
                <?php if (empty($doctor_services)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-tooth fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted"><?php echo trans('service', 'no_services_assigned'); ?></h5>
                        <p class="text-muted"><?php echo trans('service', 'assign_service_help'); ?></p>
                        <a href="manage_services.php?id=<?php echo $user_id; ?>" class="btn btn-primary mt-2">
                            <i class="fas fa-plus me-1"></i><?php echo trans('service', 'assign_services'); ?>
                        </a>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo trans('service', 'service_name'); ?></th>
                                    <th><?php echo trans('common', 'description'); ?></th>
                                    <th><?php echo trans('service', 'service_price'); ?></th>
                                    <th><?php echo trans('service', 'times_used'); ?></th>
                                    <th><?php echo trans('income', 'total_paid'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($doctor_services as $service): ?>
                                    <tr>
                                        <td>
                                            <?php echo htmlspecialchars($service['dcmt_name']); ?>
                                        </td>
                                        <td>
                                            <?php 
                                            $description = $service['dcmt_description'] ?? '';
                                            echo !empty($description) ? htmlspecialchars($description) : '<span class="text-muted">-</span>';
                                            ?>
                                        </td>
                                        <td>
                                            <span>
                                                <?php echo dcmt_format_currency($service['dcmt_price']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-info"><?php echo $service['usage_count']; ?> <?php echo trans('service', 'times'); ?></span>
                                        </td>
                                        <td>
                                            <span><?php echo dcmt_format_currency($service['total_paid'] ?? 0); ?></span>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($is_doctor_user && $can_view_doctor_sections): ?>
        <!-- Consultation Information Card -->
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-stethoscope dcmt-view-card-title-icon"></i><?php echo trans('doctor', 'consultation_information'); ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('doctor', 'total_consultations'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo number_format($consultation_count); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('doctor', 'total_earnings'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo dcmt_format_currency($total_earnings); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('doctor', 'average_earnings_per_consultation'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php 
                                if ($consultation_count > 0) {
                                    echo dcmt_format_currency($total_earnings / $consultation_count);
                                } else {
                                    echo '<span class="text-muted">' . trans('doctor', 'no_consultations') . '</span>';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <?php if ($viewer_is_admin): ?>
            <!-- Activity Statistics Card -->
            <div class="card mt-4 dcmt-records-table">
                <div class="card-header dcmt-view-card-header">
                    <h6 class="dcmt-view-card-title">
                        <i class="fas fa-chart-bar dcmt-view-card-title-icon"></i><?php echo trans('user', 'activity_statistics'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-4">
                            <div class="text-center">
                                <h3 class="text-success" style="font-size: 24px; font-weight: 700;"><?php echo $activity_stats['income_records']; ?></h3>
                                <p class="text-muted mb-0"><?php echo trans('user', 'income_records'); ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <h3 class="text-warning" style="font-size: 24px; font-weight: 700;"><?php echo $activity_stats['expense_records']; ?></h3>
                                <p class="text-muted mb-0"><?php echo trans('user', 'expense_records'); ?></p>
                            </div>
                        </div>
                        <div class="col-md-4">
                            <div class="text-center">
                                <h3 class="text-info" style="font-size: 24px; font-weight: 700;"><?php echo $activity_stats['inventory_items']; ?></h3>
                                <p class="text-muted mb-0"><?php echo trans('user', 'inventory_items'); ?></p>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        <?php endif; ?>
        
    </div>
</div>

<script>
function confirmDelete(userId) {
    return confirm('<?php echo trans('user', 'confirm_delete_user'); ?>');
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
