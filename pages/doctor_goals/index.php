<?php
/**
 * Doctor Goals Management
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/doctor_goal_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_require_admin();

$errors = [];
$success_message = '';

$goal_month_input = $_POST['goal_month'] ?? $_GET['goal_month'] ?? date('Y-m');
$goal_month = dcmt_goal_normalize_month($goal_month_input);
$doctor_search = dcmt_sanitize_input($_GET['doctor_search'] ?? '');

try {
    $doctor_stmt = $dcmt_pdo->prepare("
        SELECT dcmt_id, dcmt_full_name, dcmt_email, dcmt_status
        FROM dcmt_users
        WHERE dcmt_role = 'doctor'
        ORDER BY dcmt_full_name
    ");
    $doctor_stmt->execute();
    $all_doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Filter doctors based on search term
    if (!empty($doctor_search)) {
        $search_lower = mb_strtolower($doctor_search, 'UTF-8');
        $doctors = array_filter($all_doctors, function($doctor) use ($search_lower) {
            $full_name_lower = mb_strtolower($doctor['dcmt_full_name'] ?? '', 'UTF-8');
            $email_lower = mb_strtolower($doctor['dcmt_email'] ?? '', 'UTF-8');
            return strpos($full_name_lower, $search_lower) !== false || 
                   strpos($email_lower, $search_lower) !== false;
        });
        $doctors = array_values($doctors); // Re-index array
    } else {
        $doctors = $all_doctors;
    }
} catch (PDOException $e) {
    $all_doctors = [];
    $doctors = [];
    $errors[] = trans('user', 'database_error');
    error_log('Doctor goal fetch error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_goals') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        try {
            $dcmt_pdo->beginTransaction();
            $submitted_goals = $_POST['goals'] ?? [];
            $current_username = dcmt_get_current_user()['dcmt_username'] ?? 'system';

            // Use filtered doctors for saving (only process doctors that are visible in the form)
            foreach ($doctors as $doctor) {
                $doctor_id = (int) $doctor['dcmt_id'];
                $goal_amount_input = $submitted_goals[$doctor_id]['amount'] ?? '';
                $goal_amount = trim($goal_amount_input) !== '' ? (float) $goal_amount_input : null;

                if ($goal_amount !== null && $goal_amount > 0) {
                    dcmt_upsert_doctor_goal($dcmt_pdo, $doctor_id, $goal_month, $goal_amount, $current_username);
                } else {
                    dcmt_delete_doctor_goal($dcmt_pdo, $doctor_id, $goal_month);
                }
            }

            $dcmt_pdo->commit();
            $success_message = trans('user', 'goal_updated_success');
            dcmt_log_activity("Doctor goals updated for {$goal_month}", 'doctor_goals_updated');
        } catch (PDOException $e) {
            if ($dcmt_pdo->inTransaction()) {
                $dcmt_pdo->rollBack();
            }
            $errors[] = trans('user', 'goal_updated_error');
            error_log('Doctor goal save error: ' . $e->getMessage());
        }
    }
}

$doctor_ids = array_map(fn($doc) => (int) $doc['dcmt_id'], $doctors);
$goal_map = !empty($doctor_ids) ? dcmt_fetch_doctor_goals_map($dcmt_pdo, $goal_month, $doctor_ids) : [];
$actual_map = !empty($doctor_ids) ? dcmt_fetch_doctor_goal_actuals($dcmt_pdo, $goal_month, $doctor_ids) : [];
$goal_month_value = date('Y-m', strtotime($goal_month));
[, $goal_month_end] = dcmt_goal_month_bounds($goal_month);
$goal_month_is_ended = date('Y-m-d') >= $goal_month_end;

require_once __DIR__ . '/../../includes/header.php';
require_once __DIR__ . '/../../includes/sub_header.php';
?>

<div class="container-fluid mt-4">
    <?php if (!empty($errors)): ?>
        <div class="alert alert-danger">
            <ul class="mb-0">
                <?php foreach ($errors as $error): ?>
                    <li><?php echo htmlspecialchars($error); ?></li>
                <?php endforeach; ?>
            </ul>
        </div>
    <?php endif; ?>

    <?php if (!empty($success_message)): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <?php echo htmlspecialchars($success_message); ?>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="card mb-4 dcmt-filter-form">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end" id="goalMonthForm">
                <div class="col-md-2">
                    <label for="goal_month" class="form-label"><?php echo trans('user', 'goal_month'); ?></label>
                    <div class="dcmt-month-input-wrapper">
                        <input type="month" class="form-control dcmt-filter-field" id="goal_month" name="goal_month" value="<?php echo htmlspecialchars($goal_month_value); ?>">
                        <i class="fas fa-calendar-alt dcmt-calendar-icon"></i>
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="doctor_search" class="form-label"><?php echo trans('common', 'search'); ?> <?php echo trans('common', 'doctor'); ?></label>
                    <input type="text" class="form-control dcmt-filter-field" id="doctor_search" name="doctor_search" 
                           value="<?php echo htmlspecialchars($doctor_search); ?>" 
                           placeholder="<?php echo trans('user', 'search_placeholder'); ?>">
                </div>
                <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                    <button type="submit" class="dcmt-filter-btn">
                        <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                    </button>
                    <a href="index.php" class="dcmt-add-form-view-all-link text-center">
                        <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                    </a>
                </div>
            </form>
        </div>
    </div>

    <?php if (empty($doctors)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i><?php echo trans('user', 'no_doctor_users'); ?>
        </div>
    <?php else: ?>
        <?php
            $total_doctors = count($doctors);
            $total_goal_doctors = count($goal_map);
            $total_goal_amount = array_reduce($goal_map, fn($carry, $row) => $carry + (float)$row['dcmt_goal_amount'], 0.0);
            $total_actual_amount = array_reduce($actual_map, fn($carry, $value) => $carry + (float)$value, 0.0);
        ?>
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <div class="dcmt-view-card-header-content">
                    <div>
                        <h6 class="dcmt-view-card-title mb-0">
                            <?php echo trans('user', 'doctor_goals'); ?>
                            <span class="ms-3 dcmt-view-card-title-total">
                                (<?php echo trans('user', 'goal_month'); ?>:
                                <span class="text-primary fw-semibold"><?php echo date('F Y', strtotime($goal_month)); ?></span>
                                | <?php echo trans('user', 'goal_amount'); ?>:
                                <span class="text-success fw-semibold"><?php echo dcmt_format_currency($total_goal_amount); ?></span>
                                | <?php echo trans('user', 'actual_amount'); ?>:
                                <span class="text-info fw-semibold"><?php echo dcmt_format_currency($total_actual_amount); ?></span>)
                            </span>
                        </h6>
                    </div>
                    <div class="ms-3 d-flex gap-2">
                        <a href="../users/index.php" class="dcmt-add-form-view-all-link">
                            <i class="fas fa-users me-1"></i><?php echo trans('common', 'users'); ?>
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <form method="post" class="mb-0">
                    <input type="hidden" name="csrf_token" value="<?php echo dcmt_generate_csrf_token(); ?>">
                    <input type="hidden" name="action" value="save_goals">
                    <input type="hidden" name="goal_month" value="<?php echo htmlspecialchars($goal_month_value); ?>">

                    <?php if (empty($doctors)): ?>
                        <div class="text-center py-4">
                            <i class="fas fa-user-md fa-3x text-muted mb-3"></i>
                            <h5 class="text-muted"><?php echo trans('user', 'no_doctor_users'); ?></h5>
                        </div>
                    <?php else: ?>
                        <div class="table-responsive">
                            <table class="table table-hover align-middle">
                                <thead>
                                    <tr>
                                        <th><?php echo trans('common', 'doctor'); ?></th>
                                        <th style="width: 160px;"><?php echo trans('user', 'goal_amount'); ?></th>
                                        <th><?php echo trans('user', 'actual_amount'); ?></th>
                                        <th><?php echo trans('user', 'goal_remaining'); ?></th>
                                        <th><?php echo trans('user', 'goal_progress'); ?></th>
                                        <th><?php echo trans('user', 'goal_status'); ?></th>
                                    </tr>
                                </thead>
                                <tbody>
                                    <?php foreach ($doctors as $doctor): ?>
                                        <?php
                                        $doctor_id = (int) $doctor['dcmt_id'];
                                        $goal_row = $goal_map[$doctor_id] ?? null;
                                        $goal_amount = $goal_row ? (float) $goal_row['dcmt_goal_amount'] : 0.0;
                                        $actual_income = $actual_map[$doctor_id] ?? 0.0;
                                        $remaining = max($goal_amount - $actual_income, 0);
                                        $progress_percent_raw = $goal_amount > 0 ? (($actual_income / $goal_amount) * 100) : 0;
                                        $progress_bar_width = min(100, $progress_percent_raw);
                                        
                                        if ($goal_amount <= 0) {
                                            $status_label = trans('user', 'doctor_goal_not_set');
                                            $status_class = 'text-muted';
                                        } elseif ($goal_month_is_ended) {
                                            $status_label = trans('user', 'doctor_goal_ended');
                                            $status_class = 'text-secondary';
                                        } elseif ($actual_income >= $goal_amount) {
                                            $status_label = trans('user', 'doctor_goal_met');
                                            $status_class = 'text-success';
                                        } elseif ($actual_income > 0) {
                                            $status_label = trans('user', 'doctor_goal_in_progress');
                                            $status_class = 'text-warning';
                                        } else {
                                            $status_label = trans('user', 'doctor_goal_shortfall');
                                            $status_class = 'text-danger';
                                        }
                                    ?>
                                        <tr>
                                            <td>
                                                <strong><?php echo htmlspecialchars($doctor['dcmt_full_name']); ?></strong><br>
                                                <small class="text-muted"><?php echo htmlspecialchars($doctor['dcmt_email']); ?></small>
                                            </td>
                                            <td>
                                            <div class="input-group input-group-sm dcmt-goal-amount-input">
                                                    <span class="input-group-text"><?php echo dcmt_get_current_currency(); ?></span>
                                                    <input type="number"
                                                           class="form-control text-end"
                                                           step="0.01"
                                                           min="0"
                                                           name="goals[<?php echo $doctor_id; ?>][amount]"
                                                           value="<?php echo $goal_amount > 0 ? number_format($goal_amount, 2, '.', '') : ''; ?>"
                                                           placeholder="<?php echo htmlspecialchars(trans('common', 'amount')); ?>">
                                                </div>
                                            </td>
                                            <td><?php echo dcmt_format_currency($actual_income); ?></td>
                                            <td><?php echo $goal_amount > 0 ? dcmt_format_currency($remaining) : '—'; ?></td>
                                            <td style="width: 220px;">
                                                <?php if ($goal_amount > 0): ?>
                                                    <div class="progress" style="height: 18px;">
                                                        <div class="progress-bar <?php echo ($progress_percent_raw >= 100 ? 'bg-success' : 'bg-info'); ?>"
                                                             role="progressbar"
                                                             style="width: <?php echo $progress_bar_width; ?>%;"
                                                             aria-valuemin="0"
                                                             aria-valuemax="100"
                                                             aria-valuenow="<?php echo (int) round($progress_bar_width); ?>">
                                                            <?php echo number_format($progress_percent_raw, 0); ?>%
                                                        </div>
                                                    </div>
                                                <?php else: ?>
                                                    <span class="text-muted">—</span>
                                                <?php endif; ?>
                                            </td>
                                            <td><span class="<?php echo $status_class; ?>"><?php echo htmlspecialchars($status_label); ?></span></td>
                                        </tr>
                                    <?php endforeach; ?>
                                </tbody>
                            </table>
                        </div>
                        <div class="d-flex justify-content-end mt-3">
                            <button type="submit" class="btn btn-primary">
                                <i class="fas fa-save me-1"></i><?php echo trans('user', 'save_goals'); ?>
                            </button>
                        </div>
                    <?php endif; ?>
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<script>
document.getElementById('goal_month').addEventListener('change', function() {
    document.getElementById('goalMonthForm').submit();
});
</script>

<style>
/* Month input wrapper with calendar icon - ensures icon displays on all devices including MacBook and iPhone */
.dcmt-month-input-wrapper {
    position: relative;
    display: flex;
    align-items: center;
}

.dcmt-month-input-wrapper .form-control {
    padding-right: 45px;
}

.dcmt-calendar-icon {
    position: absolute;
    right: 16px;
    color: #6c757d;
    font-size: 16px;
    pointer-events: none;
    z-index: 1;
}

/* Ensure calendar icon is visible on Safari (MacBook and iPhone) */
.dcmt-month-input-wrapper input[type="month"]::-webkit-calendar-picker-indicator {
    opacity: 0;
    position: absolute;
    right: 0;
    width: 100%;
    height: 100%;
    cursor: pointer;
    z-index: 2;
}

/* Fallback for browsers that don't support webkit calendar picker */
.dcmt-month-input-wrapper input[type="month"] {
    -webkit-appearance: none;
    -moz-appearance: textfield;
}

/* Ensure the custom icon is always visible */
.dcmt-month-input-wrapper .dcmt-calendar-icon {
    display: block !important;
    visibility: visible !important;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

