<?php
/**
 * Monthly Goals Management (doctors: income; staff: completed appointments;
 * assistant: interim appointment counts until Google reviews goal is implemented)
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
$user_search = dcmt_sanitize_input($_GET['doctor_search'] ?? '');

try {
    $user_stmt = $dcmt_pdo->prepare("
        SELECT dcmt_id, dcmt_full_name, dcmt_email, dcmt_status, dcmt_role
        FROM dcmt_users
        WHERE dcmt_role IN ('doctor', 'staff', 'assistant')
        ORDER BY FIELD(dcmt_role, 'doctor', 'staff', 'assistant'), dcmt_full_name
    ");
    $user_stmt->execute();
    $all_goal_users = $user_stmt->fetchAll(PDO::FETCH_ASSOC);

    if (!empty($user_search)) {
        $search_lower = mb_strtolower($user_search, 'UTF-8');
        $goal_users = array_filter($all_goal_users, function ($row) use ($search_lower) {
            $full_name_lower = mb_strtolower($row['dcmt_full_name'] ?? '', 'UTF-8');
            $email_lower = mb_strtolower($row['dcmt_email'] ?? '', 'UTF-8');
            return strpos($full_name_lower, $search_lower) !== false
                || strpos($email_lower, $search_lower) !== false;
        });
        $goal_users = array_values($goal_users);
    } else {
        $goal_users = $all_goal_users;
    }
} catch (PDOException $e) {
    $all_goal_users = [];
    $goal_users = [];
    $errors[] = trans('user', 'database_error');
    error_log('Monthly goal user fetch error: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'save_goals') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        try {
            $dcmt_pdo->beginTransaction();
            $submitted_goals = $_POST['goals'] ?? [];
            $current_username = dcmt_get_current_user()['dcmt_username'] ?? 'system';

            foreach ($goal_users as $row) {
                $user_id = (int) $row['dcmt_id'];
                $role = $row['dcmt_role'] ?? '';
                $goal_amount_input = $submitted_goals[$user_id]['amount'] ?? '';
                $goal_amount = trim($goal_amount_input) !== '' ? (float) $goal_amount_input : null;
                $metric = in_array($role, ['staff', 'assistant'], true) ? 'appointments' : 'income';

                if ($goal_amount !== null) {
                    dcmt_upsert_doctor_goal($dcmt_pdo, $user_id, $goal_month, $goal_amount, $current_username, null, $metric);
                } else {
                    dcmt_delete_doctor_goal($dcmt_pdo, $user_id, $goal_month);
                }
            }

            $dcmt_pdo->commit();
            $success_message = trans('user', 'goal_updated_success');
            dcmt_log_activity("Monthly goals updated for {$goal_month}", 'doctor_goals_updated');
        } catch (PDOException $e) {
            if ($dcmt_pdo->inTransaction()) {
                $dcmt_pdo->rollBack();
            }
            $errors[] = trans('user', 'goal_updated_error');
            error_log('Monthly goal save error: ' . $e->getMessage());
        }
    }
}

$user_ids = array_map(fn ($row) => (int) $row['dcmt_id'], $goal_users);
$user_roles = [];
foreach ($goal_users as $row) {
    $rid = (int) $row['dcmt_id'];
    $rname = (string) ($row['dcmt_role'] ?? 'doctor');
    if ($rname === 'staff') {
        $user_roles[$rid] = 'staff';
    } elseif ($rname === 'assistant') {
        $user_roles[$rid] = 'assistant';
    } else {
        $user_roles[$rid] = 'doctor';
    }
}

$goal_map = !empty($user_ids) ? dcmt_fetch_doctor_goals_map($dcmt_pdo, $goal_month, $user_ids) : [];
$actual_map = !empty($user_ids) ? dcmt_fetch_mixed_goal_actuals($dcmt_pdo, $goal_month, $user_roles) : [];
$goal_month_value = date('Y-m', strtotime($goal_month));
[, $goal_month_end] = dcmt_goal_month_bounds($goal_month);
$goal_month_is_ended = date('Y-m-d') >= $goal_month_end;

$total_goal_income = 0.0;
$total_actual_income = 0.0;
$total_goal_appts = 0.0;
$total_actual_appts = 0.0;
foreach ($goal_users as $row) {
    $uid = (int) $row['dcmt_id'];
    $is_staff = in_array(($row['dcmt_role'] ?? ''), ['staff', 'assistant'], true);
    $g = $goal_map[$uid] ?? null;
    if (!$g) {
        continue;
    }
    $amt = (float) $g['dcmt_goal_amount'];
    $metric = $g['dcmt_goal_metric'] ?? 'income';
    if ($is_staff || $metric === 'appointments') {
        $total_goal_appts += $amt;
        $total_actual_appts += (float) ($actual_map[$uid] ?? 0);
    } else {
        $total_goal_income += $amt;
        $total_actual_income += (float) ($actual_map[$uid] ?? 0);
    }
}

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

    <div class="dcmt-information-panel mb-4">
        <div class="dcmt-information-panel-title">
            <i class="fas fa-info-circle me-2" aria-hidden="true"></i><?php echo trans('user', 'monthly_goals_information'); ?>
        </div>
        <ul class="dcmt-information-panel-list small mb-0">
            <li><?php echo trans('user', 'monthly_goal_help_doctor'); ?></li>
            <li><?php echo trans('user', 'monthly_goal_help_staff'); ?></li>
            <li><?php echo trans('user', 'monthly_goal_help_assistant'); ?></li>
        </ul>
    </div>

    <div class="card mb-4 dcmt-filter-form">
        <div class="card-body">
            <form method="get" class="row g-3 align-items-end" id="goalMonthForm">
                <div class="col-md-2">
                    <label for="goal_month" class="form-label"><?php echo trans('user', 'goal_month'); ?></label>
                    <div class="dcmt-month-input-wrapper">
                        <input type="month" class="form-control dcmt-filter-field" id="goal_month" name="goal_month" value="<?php echo htmlspecialchars($goal_month_value); ?>">
                        <input type="text" class="form-control dcmt-filter-field dcmt-goal-month-display" id="goal_month_display" value="<?php echo htmlspecialchars(date('F, Y', strtotime($goal_month))); ?>" readonly>
                        <i class="fas fa-calendar-alt dcmt-calendar-icon"></i>
                    </div>
                </div>
                <div class="col-md-4">
                    <label for="doctor_search" class="form-label"><?php echo trans('user', 'goal_user_search_label'); ?></label>
                    <input type="text" class="form-control dcmt-filter-field" id="doctor_search" name="doctor_search"
                           value="<?php echo htmlspecialchars($user_search); ?>"
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

    <?php if (empty($goal_users)): ?>
        <div class="alert alert-info">
            <i class="fas fa-info-circle me-2"></i><?php echo trans('user', 'no_goal_users'); ?>
        </div>
    <?php else: ?>
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <div class="dcmt-view-card-header-content">
                    <div>
                        <h6 class="dcmt-view-card-title mb-0">
                            <?php echo trans('user', 'doctor_goals'); ?>
                        </h6>
                        <div class="dcmt-view-card-title-total">
                            (<?php echo trans('user', 'goal_month'); ?>:
                            <span style="color: #007bff; font-weight: 600;"><?php echo date('F Y', strtotime($goal_month)); ?></span>
                            | <?php echo trans('user', 'goal_summary_doctors'); ?>:
                            <span style="color: #28a745; font-weight: 600;"><?php echo dcmt_format_currency($total_goal_income); ?></span>
                            / <?php echo trans('user', 'actual_amount'); ?>:
                            <span style="color: #0d6efd; font-weight: 600;"><?php echo dcmt_format_currency($total_actual_income); ?></span>
                            | <?php echo trans('user', 'goal_summary_staff'); ?>:
                            <span style="color: #28a745; font-weight: 600;"><?php echo number_format($total_goal_appts, 0); ?></span>
                            | <?php echo trans('user', 'actual_appointments_count'); ?>:
                            <span style="color: #0d6efd; font-weight: 600;"><?php echo number_format($total_actual_appts, 0); ?></span>)
                        </div>
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

                    <div class="table-responsive">
                        <table class="table table-hover align-middle">
                            <thead>
                                <tr>
                                    <th><?php echo trans('user', 'role'); ?></th>
                                    <th><?php echo trans('user', 'full_name'); ?></th>
                                    <th style="width: 180px;"><?php echo trans('user', 'goal_target_column'); ?></th>
                                    <th><?php echo trans('user', 'goal_actual_column'); ?></th>
                                    <th><?php echo trans('user', 'goal_remaining'); ?></th>
                                    <th><?php echo trans('user', 'goal_progress'); ?></th>
                                    <th><?php echo trans('user', 'goal_status'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($goal_users as $u): ?>
                                    <?php
                                    $user_id = (int) $u['dcmt_id'];
                                    $is_staff = in_array(($u['dcmt_role'] ?? ''), ['staff', 'assistant'], true);
                                    $goal_row = $goal_map[$user_id] ?? null;
                                    $metric = $goal_row['dcmt_goal_metric'] ?? ($is_staff ? 'appointments' : 'income');
                                    if ($is_staff) {
                                        $metric = 'appointments';
                                    }
                                    $goal_amount = $goal_row ? (float) $goal_row['dcmt_goal_amount'] : 0.0;
                                    $goal_defined = $goal_row !== null;
                                    $actual_val = (float) ($actual_map[$user_id] ?? 0.0);
                                    $remaining = max($goal_amount - $actual_val, 0);
                                    $progress_percent_raw = ($goal_defined && (float) $goal_amount != 0.0)
                                        ? (($actual_val / $goal_amount) * 100)
                                        : 0;
                                    $progress_bar_width = min(100, max(0, $progress_percent_raw));

                                    if (!$goal_defined) {
                                        $status_label = trans('user', 'doctor_goal_not_set');
                                        $status_class = 'text-muted';
                                    } elseif ($goal_month_is_ended) {
                                        $status_label = trans('user', 'doctor_goal_ended');
                                        $status_class = 'text-secondary';
                                    } elseif ((float) $goal_amount != 0.0 && $actual_val >= $goal_amount) {
                                        $status_label = trans('user', 'doctor_goal_met');
                                        $status_class = 'text-success';
                                    } elseif ($actual_val > 0) {
                                        $status_label = trans('user', 'doctor_goal_in_progress');
                                        $status_class = 'text-warning';
                                    } else {
                                        $status_label = trans('user', 'doctor_goal_shortfall');
                                        $status_class = 'text-danger';
                                    }

                                    $role_label = trans('user', $u['dcmt_role'] ?? '');
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($role_label); ?></td>
                                        <td>
                                            <strong><?php echo htmlspecialchars($u['dcmt_full_name']); ?></strong><br>
                                            <small class="text-muted"><?php echo htmlspecialchars($u['dcmt_email']); ?></small>
                                        </td>
                                        <td>
                                            <?php if ($metric === 'appointments'): ?>
                                                <?php
                                                $goal_row_role = (string) ($u['dcmt_role'] ?? '');
                                                $goal_count_placeholder = $goal_row_role === 'assistant'
                                                    ? trans('user', 'goal_reviews_placeholder')
                                                    : trans('user', 'goal_appointments_placeholder');
                                                ?>
                                                <div class="input-group input-group-sm dcmt-goal-amount-input">
                                                    <span class="input-group-text" title="<?php echo htmlspecialchars($goal_row_role === 'assistant' ? trans('user', 'goal_input_hash_reviews_title') : trans('user', 'goal_input_hash_appointments_title')); ?>">#</span>
                                                    <input type="number"
                                                           class="form-control text-end dcmt-skip-numeric-validation"
                                                           step="1"
                                                           name="goals[<?php echo $user_id; ?>][amount]"
                                                           value="<?php echo $goal_defined ? (int) $goal_amount : ''; ?>"
                                                           placeholder="<?php echo htmlspecialchars($goal_count_placeholder); ?>"
                                                           aria-label="<?php echo htmlspecialchars($goal_count_placeholder); ?>">
                                                </div>
                                            <?php else: ?>
                                                <div class="input-group input-group-sm dcmt-goal-amount-input">
                                                    <span class="input-group-text"><?php echo dcmt_get_current_currency(); ?></span>
                                                    <input type="number"
                                                           class="form-control text-end dcmt-skip-numeric-validation"
                                                           step="0.01"
                                                           name="goals[<?php echo $user_id; ?>][amount]"
                                                           value="<?php echo $goal_defined ? number_format($goal_amount, 2, '.', '') : ''; ?>"
                                                           placeholder="<?php echo htmlspecialchars(trans('common', 'amount')); ?>">
                                                </div>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($metric === 'appointments'): ?>
                                                <?php echo number_format($actual_val, 0); ?>
                                            <?php else: ?>
                                                <?php echo dcmt_format_currency($actual_val); ?>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($goal_defined): ?>
                                                <?php if ($metric === 'appointments'): ?>
                                                    <?php echo number_format(max($remaining, 0), 0); ?>
                                                <?php else: ?>
                                                    <?php echo dcmt_format_currency($remaining); ?>
                                                <?php endif; ?>
                                            <?php else: ?>
                                                —
                                            <?php endif; ?>
                                        </td>
                                        <td style="width: 220px;">
                                            <?php if ($goal_defined && (float) $goal_amount != 0.0): ?>
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
                </form>
            </div>
        </div>
    <?php endif; ?>
</div>

<link href="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/css/bootstrap-datepicker.min.css" rel="stylesheet">
<script src="https://cdnjs.cloudflare.com/ajax/libs/bootstrap-datepicker/1.9.0/js/bootstrap-datepicker.min.js"></script>

<script>
(function() {
    const monthInput = document.getElementById('goal_month');
    const monthDisplayInput = document.getElementById('goal_month_display');
    const form = document.getElementById('goalMonthForm');
    const icon = document.querySelector('.dcmt-month-input-wrapper .dcmt-calendar-icon');

    if (!monthInput || !form) {
        return;
    }

    const ua = navigator.userAgent || '';
    const isSafariLike = /safari/i.test(ua) && !/chrome|crios|android|edg/i.test(ua);
    const useFallbackPicker = (monthInput.type !== 'month') || (isSafariLike && typeof monthInput.showPicker !== 'function');

    monthInput.addEventListener('change', function() {
        form.submit();
    });

    if (useFallbackPicker && monthDisplayInput) {
        monthInput.style.display = 'none';
        monthDisplayInput.style.display = 'block';
    }

    if (icon) {
        icon.addEventListener('click', function() {
            if (useFallbackPicker && monthDisplayInput && window.jQuery && typeof jQuery.fn.datepicker === 'function') {
                jQuery(monthDisplayInput).datepicker('show');
                return;
            }

            if (typeof monthInput.showPicker === 'function') {
                monthInput.showPicker();
            } else {
                monthInput.focus();
                monthInput.click();
            }
        });
    }

    if (useFallbackPicker && monthDisplayInput && window.jQuery && typeof jQuery.fn.datepicker === 'function') {
        const initialDate = monthInput.value ? new Date(monthInput.value + '-01T12:00:00') : new Date();

        jQuery(monthDisplayInput).datepicker({
            format: "MM, yyyy",
            minViewMode: 1, // months
            maxViewMode: 2, // years
            autoclose: true,
            orientation: "bottom left",
            clearBtn: true,
            todayBtn: "linked",
            todayHighlight: true
        });
        
        jQuery(monthDisplayInput).datepicker('setDate', initialDate);

        jQuery(monthDisplayInput).on('changeDate', function(e) {
            if (e.date) {
                const m = String(e.date.getMonth() + 1).padStart(2, '0');
                const y = e.date.getFullYear();
                monthInput.value = `${y}-${m}`;
                form.submit();
            }
        });

        jQuery(monthDisplayInput).on('clearDate', function() {
            const today = new Date();
            const m = String(today.getMonth() + 1).padStart(2, '0');
            const y = today.getFullYear();
            monthInput.value = `${y}-${m}`;
            form.submit();
        });
    }
})();
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
    width: 100%;
    flex: 1 1 auto;
}

.dcmt-month-input-wrapper .dcmt-goal-month-display {
    display: none;
}

.dcmt-calendar-icon {
    position: absolute;
    right: 16px;
    color: #6c757d;
    font-size: 16px;
    pointer-events: auto;
    cursor: pointer;
    z-index: 1;
}

.dcmt-month-input-wrapper input[type="month"]::-webkit-calendar-picker-indicator {
    opacity: 0;
}

/* Ensure the custom icon is always visible */
.dcmt-month-input-wrapper .dcmt-calendar-icon {
    display: block !important;
    visibility: visible !important;
}
</style>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
