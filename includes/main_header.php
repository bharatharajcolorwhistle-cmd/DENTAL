<?php
/**
 * Main Header Component
 * Dental Clinic Management System
 */

$current_user = dcmt_get_current_user();
$dcmt_is_assistant_user = $current_user && ($current_user['dcmt_role'] ?? '') === 'assistant';
$dcmt_is_staff_user = $current_user && ($current_user['dcmt_role'] ?? '') === 'staff';
$dcmt_header_role = (string)($current_user['dcmt_role'] ?? '');
$dcmt_header_can_manage_appointments = in_array($dcmt_header_role, ['admin', 'staff', 'assistant', 'doctor'], true);

$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['REQUEST_URI'];

$logo_path = dcmt_get_logo_path();

if (!function_exists('get_base_path')) {
    function get_base_path() {
        $current_dir = dirname($_SERVER['PHP_SELF']);
        if (strpos($current_dir, '/pages/') !== false) {
            return '../../';
        } elseif (strpos($current_dir, '/auth/') !== false) {
            return '../';
        } else {
            return './';
        }
    }
}

$base_path = get_base_path();
$dcmt_home_path = (($dcmt_is_assistant_user ?? false) || ($dcmt_is_staff_user ?? false))
    ? $base_path . 'pages/dashboard/index.php?tab=appointment'
    : $base_path . 'pages/dashboard/index.php';

if (!function_exists('is_active_path')) {
    function is_active_path($path) {
        global $current_path;
        return strpos($current_path, $path) !== false;
    }
}

$dcmt_show_start_cash_notice = false;
if (!($dcmt_is_assistant_user ?? false) && isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
    if (!function_exists('dcmt_get_cashflow_by_date')) {
        require_once __DIR__ . '/cashflow_functions.php';
    }
    $dcmt_header_today = dcmt_get_current_date();
    $dcmt_header_today_record = dcmt_get_cashflow_by_date($dcmt_pdo, $dcmt_header_today);
    $dcmt_header_start_cash_added_today = false;
    if ($dcmt_header_today_record) {
        $dcmt_header_start_cash_added_today = true;
    }
    $dcmt_show_start_cash_notice = !$dcmt_header_start_cash_added_today;
}

$dcmt_today_birthday_count = 0;
$dcmt_show_birthday_notice = false;
if (isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
    require_once __DIR__ . '/birthday_functions.php';
    try {
        $dcmt_today_birthday_count = dcmt_get_pending_birthday_count($dcmt_pdo);
        $dcmt_show_birthday_notice = $dcmt_today_birthday_count > 0;
    } catch (PDOException $e) {
        $dcmt_show_birthday_notice = false;
    }
}

$dcmt_doctor_goal_amount = 0.0;
$dcmt_doctor_goal_actual = 0.0;
$dcmt_doctor_goal_percent = 0.0;
$dcmt_doctor_goal_is_doctor = $current_user && ($current_user['dcmt_role'] ?? '') === 'doctor';
$dcmt_staff_goal_is_staff = $current_user && in_array(($current_user['dcmt_role'] ?? ''), ['staff', 'assistant'], true);
$dcmt_doctor_goal_is_set = false;
$dcmt_show_doctor_goals_notice = false;
$dcmt_doctor_goals_notice_link = '';

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    if (file_exists(__DIR__ . '/../config/database.php')) {
        require_once __DIR__ . '/../config/database.php';
    }
}

if (($dcmt_doctor_goal_is_doctor || $dcmt_staff_goal_is_staff) && isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
    if (!function_exists('dcmt_goal_normalize_month')) {
        require_once __DIR__ . '/doctor_goal_functions.php';
    }

    $dcmt_goal_month = dcmt_goal_normalize_month(date('Y-m'));
    $dcmt_goal_user_id = (int) ($current_user['dcmt_id'] ?? 0);

    if ($dcmt_goal_user_id > 0) {
        $dcmt_goal_row = dcmt_get_doctor_goal_details($dcmt_pdo, $dcmt_goal_user_id, $dcmt_goal_month);
        $dcmt_doctor_goal_amount = $dcmt_goal_row ? (float) ($dcmt_goal_row['dcmt_goal_amount'] ?? 0) : 0.0;
        $dcmt_doctor_goal_is_set = $dcmt_goal_row !== null;

        if ($dcmt_staff_goal_is_staff) {
            $dcmt_goal_staff_role = (string) ($current_user['dcmt_role'] ?? 'staff');
            $dcmt_actual_map = dcmt_fetch_staff_goal_appointment_counts(
                $dcmt_pdo,
                $dcmt_goal_month,
                [$dcmt_goal_user_id => $dcmt_goal_staff_role]
            );
            $dcmt_doctor_goal_actual = (float) ($dcmt_actual_map[$dcmt_goal_user_id] ?? 0.0);
        } else {
            $dcmt_actual_map = dcmt_fetch_doctor_goal_actuals($dcmt_pdo, $dcmt_goal_month, [$dcmt_goal_user_id]);
            $dcmt_doctor_goal_actual = (float) ($dcmt_actual_map[$dcmt_goal_user_id] ?? 0.0);
        }

        if ($dcmt_doctor_goal_is_set && (float) $dcmt_doctor_goal_amount != 0.0) {
            $dcmt_doctor_goal_percent = min(100, ($dcmt_doctor_goal_actual / $dcmt_doctor_goal_amount) * 100);
        } else {
            $dcmt_doctor_goal_percent = 0.0;
        }
    }
}

$dcmt_is_admin_user = $current_user && dcmt_is_admin();
$dcmt_day_of_month = (int) date('j');
$dcmt_first_week_of_month = $dcmt_day_of_month >= 1 && $dcmt_day_of_month <= 7;

if (!($dcmt_is_assistant_user ?? false) && $dcmt_is_admin_user && $dcmt_first_week_of_month && isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
    if (!function_exists('dcmt_goal_normalize_month')) {
        require_once __DIR__ . '/doctor_goal_functions.php';
    }

    try {
        $dcmt_goal_notice_month = dcmt_goal_normalize_month(date('Y-m'));
        $dcmt_doctor_count_stmt = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active'");
        $dcmt_active_doctor_count = (int) ($dcmt_doctor_count_stmt ? $dcmt_doctor_count_stmt->fetchColumn() : 0);

        $dcmt_staff_count_stmt = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role IN ('staff', 'assistant') AND dcmt_status = 'active'");
        $dcmt_active_staff_count = (int) ($dcmt_staff_count_stmt ? $dcmt_staff_count_stmt->fetchColumn() : 0);

        $dcmt_goals_set_stmt = $dcmt_pdo->prepare("
            SELECT COUNT(DISTINCT g.dcmt_user_id)
            FROM dcmt_doctor_goals g
            INNER JOIN dcmt_users u ON u.dcmt_id = g.dcmt_user_id
            WHERE g.dcmt_goal_month = ? AND u.dcmt_role IN ('doctor', 'staff', 'assistant') AND u.dcmt_status = 'active'
        ");
        $dcmt_goals_set_stmt->execute([$dcmt_goal_notice_month]);
        $dcmt_goals_set_count = (int) $dcmt_goals_set_stmt->fetchColumn();

        $dcmt_goal_users_expected = $dcmt_active_doctor_count + $dcmt_active_staff_count;

        $dcmt_is_doctor_goals_page = strpos($current_path, '/pages/doctor_goals/') !== false;
        if (!$dcmt_is_doctor_goals_page && $dcmt_goal_users_expected > 0 && $dcmt_goals_set_count < $dcmt_goal_users_expected) {
            $dcmt_show_doctor_goals_notice = true;
            $dcmt_doctor_goals_notice_link = $base_path . 'pages/doctor_goals/index.php?goal_month=' . urlencode(date('Y-m'));
        }
    } catch (PDOException $e) {
        $dcmt_show_doctor_goals_notice = false;
    }
}
?>

<!-- Main Header -->
<div class="main-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="header-content">
                    <div class="site-branding">
                        <div class="site-logo">
                            <a href="<?php echo $dcmt_home_path; ?>" class="logo-link">
                                <?php if (!empty($logo_path) && file_exists(__DIR__ . '/../' . $logo_path)): ?>
                                    <img src="<?php echo $base_path . $logo_path; ?>" alt="<?php echo dcmt_get_site_name(); ?>" class="logo-image">
                                <?php else: ?>
                                    <i class="fas fa-tooth fa-3x"></i>
                                <?php endif; ?>
                            </a>
                        </div>
                        <div class="site-info">
                            <h1 class="site-title">
                                <a href="<?php echo $dcmt_home_path; ?>" class="site-title-link"><?php echo dcmt_get_site_name(); ?></a>
                            </h1>
                            <?php
                            $dcmt_site_description = dcmt_get_site_description();
                            if ($dcmt_site_description !== ''):
                            ?>
                                <p class="site-description"><?php echo htmlspecialchars($dcmt_site_description); ?></p>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="header-actions">
                    <div class="current-date">
                        <i class="fas fa-calendar-alt me-2"></i>
                        <span class="date-text" id="currentDateTime" data-locale="<?php echo trans('common', 'date_format', 'en-US'); ?>" data-timezone="America/Mexico_City"><?php echo dcmt_get_current_datetime('D, j M Y - H:i A'); ?></span>
                    </div>
                    <?php if ($dcmt_header_can_manage_appointments): ?>
                        <div id="dcmtOngoingAppointmentHeader" class="dcmt-header-appt-panel d-none" aria-live="polite">
                            <div class="dcmt-header-appt-panel-head">
                                <span class="dcmt-header-appt-panel-title">
                                    <i class="fas fa-user-clock me-1" aria-hidden="true"></i>
                                    <?php echo htmlspecialchars(trans('appointment', 'header_ongoing_title')); ?>
                                </span>
                                <span id="dcmtOngoingAppointmentBadge" class="dcmt-header-appt-badge"></span>
                            </div>
                            <div id="dcmtOngoingAppointmentList" class="dcmt-header-appt-list" role="list"></div>
                        </div>
                        <script>
                        window.dcmtHeaderOngoingAppt = window.dcmtHeaderOngoingAppt || {};
                        window.dcmtHeaderOngoingAppt.basePath = <?php echo json_encode($base_path); ?>;
                        window.dcmtHeaderOngoingAppt.csrfToken = <?php echo json_encode(dcmt_generate_csrf_token()); ?>;
                        window.dcmtHeaderOngoingAppt.labels = {
                            end: <?php echo json_encode(trans('appointment', 'appointment_end')); ?>,
                            activeCount: <?php echo json_encode(trans('appointment', 'header_ongoing_active_count')); ?>,
                            more: <?php echo json_encode(trans('appointment', 'header_ongoing_more')); ?>,
                            viewBoard: <?php echo json_encode(trans('appointment', 'header_ongoing_view_board')); ?>,
                            failUpdate: <?php echo json_encode(trans('appointment', 'header_ongoing_update_failed')); ?>,
                            invalidToken: <?php echo json_encode(trans('common', 'invalid_token')); ?>
                        };
                        </script>
                    <?php endif; ?>
                    <?php if ($dcmt_doctor_goal_is_doctor || $dcmt_staff_goal_is_staff): ?>
                        <div class="dcmt-doctor-goal-header" title="<?php echo htmlspecialchars(trans('user', 'doctor_goal_progress_label')); ?>">
                            <div class="dcmt-doctor-goal-header-top">
                                <span class="dcmt-doctor-goal-header-label"><?php echo $dcmt_staff_goal_is_staff ? trans('user', 'staff_goal_header_label') : trans('user', 'doctor_goal_current_month'); ?></span>
                                <?php if ($dcmt_doctor_goal_is_set): ?>
                                    <span class="dcmt-doctor-goal-header-amount">
                                        <?php if ($dcmt_staff_goal_is_staff): ?>
                                            <?php echo number_format($dcmt_doctor_goal_actual, 0); ?> / <?php echo number_format($dcmt_doctor_goal_amount, 0); ?>
                                            <span class="text-muted small ms-1"><?php echo trans('user', 'goal_appointments_short'); ?></span>
                                        <?php else: ?>
                                            <?php echo dcmt_format_currency($dcmt_doctor_goal_actual); ?> / <?php echo dcmt_format_currency($dcmt_doctor_goal_amount); ?>
                                        <?php endif; ?>
                                    </span>
                                <?php else: ?>
                                    <span class="dcmt-doctor-goal-header-amount"><?php echo trans('user', 'doctor_goal_not_set'); ?></span>
                                <?php endif; ?>
                            </div>
                            <div class="progress dcmt-doctor-goal-header-progress" role="progressbar" aria-valuemin="0" aria-valuemax="100" aria-valuenow="<?php echo (int) round($dcmt_doctor_goal_percent); ?>">
                                <div class="progress-bar <?php echo $dcmt_doctor_goal_percent >= 100 ? 'bg-success' : 'bg-info'; ?>" style="width: <?php echo $dcmt_doctor_goal_percent; ?>%"></div>
                            </div>
                        </div>
                    <?php endif; ?>
                    <?php
                    $dcmt_quick_reminder_assignable_users = [];
                    $dcmt_quick_reminder_default_user_id = (int) ($current_user['dcmt_id'] ?? 0);
                    $dcmt_quick_reminder_default_date = dcmt_get_current_date();
                    $dcmt_quick_reminder_default_time = date('H:i');
                    if (isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
                        if (!function_exists('dcmt_reminder_get_assignable_users')) {
                            require_once __DIR__ . '/reminder_functions.php';
                        }
                        $dcmt_quick_reminder_assignable_users = dcmt_reminder_get_assignable_users($dcmt_pdo);
                    }
                    ?>
                    <div class="header-controls">
                        <div class="dropdown dcmt-reminder-notifications-dropdown" id="dcmtReminderNotificationsWrap">
                            <button class="btn btn-outline-secondary btn-sm position-relative dcmt-reminder-bell-btn"
                                    type="button"
                                    id="dcmtReminderBellBtn"
                                    data-bs-toggle="dropdown"
                                    aria-expanded="false"
                                    title="<?php echo htmlspecialchars(trans('reminder', 'notification_bell_title')); ?>">
                                <i class="fas fa-bell"></i>
                                <span class="position-absolute top-0 start-100 translate-middle badge rounded-pill bg-danger d-none"
                                      id="dcmtReminderBellBadge">0</span>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end dcmt-reminder-notifications-menu" aria-labelledby="dcmtReminderBellBtn">
                                <li class="dropdown-header d-flex justify-content-between align-items-center">
                                    <span><?php echo trans('reminder', 'notifications'); ?></span>
                                    <button type="button"
                                            id="dcmtQuickReminderBtn"
                                            class="btn btn-sm btn-link p-0 text-decoration-none"
                                            title="<?php echo htmlspecialchars(trans('reminder', 'add_reminder')); ?>">
                                        <i class="fas fa-plus-circle"></i>
                                    </button>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li id="dcmtReminderNotificationsList">
                                    <span class="dropdown-item-text text-muted small"><?php echo trans('reminder', 'notification_empty'); ?></span>
                                </li>
                                <li><hr class="dropdown-divider"></li>
                                <li>
                                    <a class="dropdown-item text-center" href="<?php echo $base_path; ?>pages/reminders/index.php">
                                        <?php echo trans('reminder', 'view_all_reminders'); ?>
                                    </a>
                                </li>
                            </ul>
                        </div>
                        <div class="modal fade" id="dcmtQuickReminderModal" tabindex="-1" aria-hidden="true">
                            <div class="modal-dialog modal-dialog-centered">
                                <div class="modal-content">
                                    <div class="modal-header">
                                        <h5 class="modal-title"><i class="fas fa-bell me-2"></i><?php echo trans('reminder', 'add_reminder'); ?></h5>
                                        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                                    </div>
                                    <div class="modal-body">
                                        <div id="dcmtQuickReminderError" class="alert alert-danger d-none"></div>
                                        <div class="mb-3">
                                            <label for="dcmtQuickReminderTitle" class="form-label"><?php echo trans('reminder', 'title'); ?> <span class="text-danger">*</span></label>
                                            <input type="text" class="form-control" id="dcmtQuickReminderTitle" maxlength="255" placeholder="<?php echo htmlspecialchars(trans('reminder', 'title_placeholder')); ?>">
                                        </div>
                                        <div class="mb-3">
                                            <label for="dcmtQuickReminderAssignedTo" class="form-label"><?php echo trans('reminder', 'assigned_to'); ?> <span class="text-danger">*</span></label>
                                            <select class="form-select" id="dcmtQuickReminderAssignedTo" required>
                                                <option value=""><?php echo trans('reminder', 'select_assignee'); ?></option>
                                                <?php foreach ($dcmt_quick_reminder_assignable_users as $u): ?>
                                                    <option value="<?php echo (int) $u['dcmt_id']; ?>" <?php echo $dcmt_quick_reminder_default_user_id === (int) $u['dcmt_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($u['dcmt_full_name'] ?: $u['dcmt_username']); ?>
                                                        (<?php echo htmlspecialchars($u['dcmt_role'] ?? ''); ?>)
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </div>
                                        <div class="row g-2">
                                            <div class="col-md-6">
                                                <label for="dcmtQuickReminderDate" class="form-label"><?php echo trans('reminder', 'reminder_date'); ?> <span class="text-danger">*</span></label>
                                                <input type="date" class="form-control" id="dcmtQuickReminderDate"
                                                       value="<?php echo htmlspecialchars($dcmt_quick_reminder_default_date); ?>">
                                            </div>
                                            <div class="col-md-6">
                                                <label for="dcmtQuickReminderTime" class="form-label"><?php echo trans('reminder', 'reminder_time'); ?> <span class="text-danger">*</span></label>
                                                <input type="time" class="form-control" id="dcmtQuickReminderTime"
                                                       value="<?php echo htmlspecialchars($dcmt_quick_reminder_default_time); ?>">
                                            </div>
                                        </div>
                                    </div>
                                    <div class="modal-footer">
                                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                                        <button type="button" class="btn btn-primary" id="dcmtQuickReminderSaveBtn">
                                            <i class="fas fa-save me-1"></i><?php echo trans('common', 'save'); ?>
                                        </button>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <script>
                        window.dcmtReminderNotifications = {
                            pollUrl: <?php echo json_encode(DCMT_APP_URL . '/pages/reminders/poll_notifications_ajax.php'); ?>,
                            basePath: <?php echo json_encode($base_path); ?>,
                            csrfToken: <?php echo json_encode(dcmt_generate_csrf_token()); ?>,
                            defaultAssignedUserId: <?php echo json_encode($dcmt_quick_reminder_default_user_id); ?>,
                            defaultReminderDate: <?php echo json_encode($dcmt_quick_reminder_default_date); ?>,
                            defaultReminderTime: <?php echo json_encode($dcmt_quick_reminder_default_time); ?>,
                            labels: {
                                empty: <?php echo json_encode(trans('reminder', 'notification_empty')); ?>,
                                dismiss: <?php echo json_encode(trans('reminder', 'dismiss')); ?>,
                                advance: <?php echo json_encode(trans('reminder', 'notification_advance_message')); ?>,
                                view: <?php echo json_encode(trans('common', 'view')); ?>,
                                complete: <?php echo json_encode(trans('reminder', 'mark_complete')); ?>,
                                quickValidation: <?php echo json_encode(trans('reminder', 'quick_add_validation')); ?>,
                                quickFailed: <?php echo json_encode(trans('reminder', 'quick_add_failed')); ?>,
                                selectAssignee: <?php echo json_encode(trans('reminder', 'select_assignee')); ?>
                            }
                        };
                        </script>
                        <div class="user-profile-dropdown <?php echo is_active_path('/users/view.php') || is_active_path('/users/edit.php') || is_active_path('/settings/') ? 'active' : ''; ?>">
                            <button class="btn btn-user-profile" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="user-avatar">
                                    <?php echo isset($current_user['dcmt_username']) ? strtoupper(substr($current_user['dcmt_username'], 0, 1)) : 'U'; ?>
                                </div>
                                <span class="user-name"><?php echo isset($current_user['dcmt_username']) ? htmlspecialchars($current_user['dcmt_username']) : 'User'; ?></span>
                                <i class="fas fa-chevron-down ms-2"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end user-menu">
                                <li><a class="dropdown-item <?php echo is_active_path('/users/view.php') ? 'active' : ''; ?>" href="../users/view.php?id=<?php echo isset($current_user['dcmt_id']) ? $current_user['dcmt_id'] : ''; ?>"><i class="fas fa-user me-2"></i><?php echo trans('common', 'profile'); ?></a></li>
                                <li><a class="dropdown-item <?php echo is_active_path('/users/edit.php') ? 'active' : ''; ?>" href="../users/edit.php?id=<?php echo isset($current_user['dcmt_id']) ? $current_user['dcmt_id'] : ''; ?>"><i class="fas fa-edit me-2"></i><?php echo trans('common', 'edit_profile'); ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item <?php echo is_active_path('/settings/') ? 'active' : ''; ?>" href="../settings/general.php"><i class="fas fa-sliders-h me-2"></i><?php echo trans('common', 'general_settings'); ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i><?php echo trans('common', 'logout'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php if ($dcmt_show_start_cash_notice): ?>
    <div class="container-fluid dentl-alert">
        <div id="dcmtStartCashHeaderAlert" class="alert alert-warning alert-dismissible fade show d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-0 mt-2" data-persistent="true" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <strong class="me-1"><?php echo trans('cashflow', 'start_cash'); ?>:</strong>
                <span>
                    <?php echo htmlspecialchars(trans('cashflow', 'start_cash_required') ?: 'Please set the Start Cash for today before adding income or expenses.'); ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-2 ms-md-auto">
                <a href="<?php echo $base_path; ?>pages/cashflow/start_cash.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-play me-1"></i><?php echo trans('cashflow', 'start_cash'); ?>
                </a>
                <button type="button" class="btn-close position-static" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<?php if ($dcmt_show_birthday_notice): ?>
    <div class="container-fluid dentl-alert">
        <div id="dcmtBirthdayHeaderAlert" class="alert alert-info alert-dismissible fade show d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-0 mt-2" data-persistent="true" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-birthday-cake me-2"></i>
                <strong class="me-1"><?php echo trans('patient', 'birthday_header_title'); ?>:</strong>
                <span data-birthday-count-text>
                    <?php
                    $dcmt_birthday_alert_key = $dcmt_today_birthday_count === 1 ? 'birthday_header_alert_one' : 'birthday_header_alert_many';
                    echo htmlspecialchars(str_replace('{count}', (string) $dcmt_today_birthday_count, trans('patient', $dcmt_birthday_alert_key)));
                    ?>
                </span>
            </div>
            <div class="d-flex align-items-center gap-2 ms-md-auto">
                <a href="<?php echo $base_path; ?>pages/patients/index.php" class="btn btn-sm btn-primary">
                    <i class="fas fa-users me-1"></i><?php echo trans('patient', 'view_patient'); ?>
                </a>
                <button type="button" class="btn-close position-static" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
window.dcmtUpdateBirthdayHeaderAlert = function(remainingCount, alertMessage) {
    const alert = document.getElementById('dcmtBirthdayHeaderAlert');
    if (!alert) {
        return;
    }

    const count = Number.parseInt(String(remainingCount), 10) || 0;
    if (count <= 0) {
        const wrapper = alert.closest('.dentl-alert');
        if (wrapper) {
            wrapper.remove();
        } else {
            alert.remove();
        }
        return;
    }

    const textEl = alert.querySelector('[data-birthday-count-text]');
    if (textEl && alertMessage) {
        textEl.textContent = alertMessage;
    }
};
</script>

<?php if ($dcmt_show_doctor_goals_notice): ?>
    <div class="container-fluid dentl-alert">
        <div id="dcmtDoctorGoalsHeaderAlert" class="alert alert-info alert-dismissible fade show d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-0 mt-2" data-persistent="true" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-bullseye me-2"></i>
                <span><?php echo htmlspecialchars(trans('user', 'doctor_goals_first_week_reminder')); ?></span>
            </div>
            <div class="d-flex align-items-center gap-2 ms-md-auto">
                <a href="<?php echo htmlspecialchars($dcmt_doctor_goals_notice_link); ?>" class="btn btn-sm btn-primary">
                    <i class="fas fa-bullseye me-1"></i><?php echo trans('user', 'manage_doctor_goals'); ?>
                </a>
                <button type="button" class="btn-close position-static" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        </div>
    </div>
<?php endif; ?>

<script>
// Real-time clock functionality
function updateDateTime() {
    const now = new Date();
    const dateTimeElement = document.getElementById('currentDateTime');
    
    if (!dateTimeElement) return;
    
    // Get locale from data attribute or default to en-US
    const locale = dateTimeElement.getAttribute('data-locale') || 'en-US';
    const timezone = dateTimeElement.getAttribute('data-timezone') || 'America/Mexico_City';
    
    // Use Intl.DateTimeFormat with the specified locale and timezone
    const formatter = new Intl.DateTimeFormat(locale, {
        timeZone: timezone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
    
    // Format the date
    const formattedDateTime = formatter.format(now);
    
    dateTimeElement.textContent = formattedDateTime;
}

// Update immediately and then every second
updateDateTime();
setInterval(updateDateTime, 1000);
</script>

<?php if ($dcmt_header_can_manage_appointments): ?>
<script>
(function() {
    const cfg = window.dcmtHeaderOngoingAppt || {};
    const basePath = String(cfg.basePath || '');
    const csrfToken = String(cfg.csrfToken || '');
    const labels = cfg.labels || {};
    const stateUrl = basePath + 'pages/dashboard/appointment_board_state_ajax.php';
    const legacyStorageKey = 'dcmt_ongoing_appointment';
    const pollIntervalMs = (window.dcmtAppointmentSync && window.dcmtAppointmentSync.POLL_MS) || 5000;

    const wrap = document.getElementById('dcmtOngoingAppointmentHeader');
    const listEl = document.getElementById('dcmtOngoingAppointmentList');
    const badgeEl = document.getElementById('dcmtOngoingAppointmentBadge');

    const endingIds = new Set();
    let pollBusy = false;
    let pollQueued = false;
    let lastRenderKey = '';

    function escapeHtml(text) {
        const div = document.createElement('div');
        div.textContent = String(text || '');
        return div.innerHTML;
    }

    function normalizeOngoingItem(raw) {
        if (!raw || typeof raw !== 'object') return null;
        const id = String(raw.id || '').trim();
        if (!id) return null;
        return {
            id,
            patient_name: String(raw.patient_name || '').trim(),
            doctor_name: String(raw.doctor_name || '').trim(),
            operatory_name: String(raw.operatory_name || '').trim()
        };
    }

    function buildItemLabel(item) {
        const patient = String(item.patient_name || '').trim();
        const meta = [];
        if (item.operatory_name) meta.push(item.operatory_name);
        if (item.doctor_name) meta.push(item.doctor_name);
        if (patient && meta.length) {
            return patient + ' · ' + meta.join(' · ');
        }
        if (patient) return patient;
        if (meta.length) return meta.join(' · ');
        return '#' + item.id;
    }

    function buildRenderKey(list, totalCount) {
        const rows = list.map(function(item) {
            return [
                item.id,
                item.patient_name,
                item.doctor_name,
                item.operatory_name,
                endingIds.has(String(item.id)) ? '1' : '0'
            ].join(':');
        });
        return String(totalCount) + '|' + rows.join(';');
    }

    function formatActiveCount(count) {
        const template = String(labels.activeCount || '{count} active');
        return template.replace(/\{count\}/g, String(count));
    }

    function renderOngoingList(ongoingList, ongoingCount) {
        if (!wrap || !listEl) return;

        const list = (Array.isArray(ongoingList) ? ongoingList : [])
            .map(normalizeOngoingItem)
            .filter(Boolean);
        const totalCount = Number.parseInt(ongoingCount || 0, 10) || 0;
        const renderKey = buildRenderKey(list, totalCount);

        if (renderKey === lastRenderKey) {
            return;
        }
        lastRenderKey = renderKey;

        if (totalCount <= 0 || !list.length) {
            wrap.classList.add('d-none');
            listEl.innerHTML = '';
            if (badgeEl) badgeEl.textContent = '';
            return;
        }

        wrap.classList.remove('d-none');
        if (badgeEl) {
            badgeEl.textContent = formatActiveCount(totalCount);
        }

        let html = '';
        list.forEach(function(item) {
            const id = item.id;
            const isEnding = endingIds.has(id);
            const viewUrl = basePath + 'pages/appointments/view.php?id=' + encodeURIComponent(id);
            html += '<div class="dcmt-header-appt-item" role="listitem" data-appointment-id="' + escapeHtml(id) + '">';
            html += '<a class="dcmt-header-appt-item-link" href="' + escapeHtml(viewUrl) + '" title="' + escapeHtml(buildItemLabel(item)) + '">';
            html += '<span class="dcmt-header-appt-item-name">' + escapeHtml(item.patient_name || buildItemLabel(item)) + '</span>';
            if (item.operatory_name || item.doctor_name) {
                html += '<span class="dcmt-header-appt-item-meta">';
                html += escapeHtml([item.operatory_name, item.doctor_name].filter(Boolean).join(' · '));
                html += '</span>';
            }
            html += '</a>';
            html += '<button type="button" class="dcmt-pill-btn dcmt-pill-btn-end dcmt-header-appt-end-btn" data-appointment-id="' + escapeHtml(id) + '"';
            if (isEnding) {
                html += ' disabled';
            }
            html += '>' + escapeHtml(labels.end || 'End') + '</button>';
            html += '</div>';
        });

        const hiddenCount = totalCount - list.length;
        if (hiddenCount > 0) {
            const moreLabel = String(labels.more || '+{count} more').replace(/\{count\}/g, String(hiddenCount));
            const boardUrl = basePath + 'pages/dashboard/index.php?tab=appointment';
            html += '<a class="dcmt-header-appt-more" href="' + escapeHtml(boardUrl) + '">' + escapeHtml(moreLabel) + '</a>';
        }

        listEl.innerHTML = html;
    }

    function purgeLegacyStorage() {
        if (!window.localStorage) return;
        try {
            window.localStorage.removeItem(legacyStorageKey);
        } catch (e) {
            // Ignore storage access errors.
        }
    }

    function getDoctorIdFromUrl() {
        try {
            const url = new URL(window.location.href);
            const raw = String(url.searchParams.get('doctor_id') || '').trim();
            const num = Number.parseInt(raw, 10);
            return Number.isFinite(num) && num > 0 ? num : 0;
        } catch (e) {
            return 0;
        }
    }

    async function fetchServerState() {
        const params = new URLSearchParams();
        const doctorId = getDoctorIdFromUrl();
        if (doctorId > 0) {
            params.set('doctor_id', String(doctorId));
        }
        const url = params.toString() ? (stateUrl + '?' + params.toString()) : stateUrl;
        try {
            const res = await fetch(url, { method: 'GET', cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.success) return null;
            return data;
        } catch (e) {
            return null;
        }
    }

    async function pollOngoingFromServer() {
        if (pollBusy) {
            pollQueued = true;
            return;
        }
        pollBusy = true;
        try {
            const server = await fetchServerState();
            if (!server) return;

            const ongoingCount = Number.parseInt(server.ongoing_count || 0, 10) || 0;
            const ongoingList = Array.isArray(server.ongoing) ? server.ongoing : [];

            renderOngoingList(ongoingList, ongoingCount);

            const activeIds = new Set(ongoingList.map(function(item) {
                return String((item && item.id) || '');
            }));
            endingIds.forEach(function(id) {
                if (!activeIds.has(id)) {
                    endingIds.delete(id);
                }
            });
        } finally {
            pollBusy = false;
            if (pollQueued) {
                pollQueued = false;
                pollOngoingFromServer();
            }
        }
    }

    function requestHeaderRefresh() {
        lastRenderKey = '';
        pollOngoingFromServer();
    }

    window.dcmtRefreshOngoingAppointmentsHeader = requestHeaderRefresh;
    window.dcmtSetOngoingAppointment = function() {
        requestHeaderRefresh();
    };
    window.dcmtClearOngoingAppointment = function() {
        requestHeaderRefresh();
    };

    async function endAppointmentFromHeader(appointmentId, endBtn) {
        const id = String(appointmentId || '').trim();
        if (!id || !endBtn || endBtn.disabled || endingIds.has(id)) return;
        if (!csrfToken) {
            alert(labels.invalidToken || 'Invalid token.');
            return;
        }

        endingIds.add(id);
        endBtn.disabled = true;
        const originalHtml = endBtn.innerHTML;
        endBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('appointment_id', id);
        body.set('action', 'end');

        try {
            const res = await fetch(basePath + 'pages/dashboard/appointment_board_action_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });
            const data = await res.json();
            if (!data || !data.success) {
                alert((data && data.message) ? data.message : (labels.failUpdate || 'Failed to update appointment'));
                endingIds.delete(id);
                endBtn.disabled = false;
                endBtn.innerHTML = originalHtml;
                lastRenderKey = '';
                return;
            }
            endingIds.delete(id);
            lastRenderKey = '';
            await pollOngoingFromServer();
            if (window.dcmtAppointmentSync && typeof window.dcmtAppointmentSync.notifyAppointmentChanged === 'function') {
                window.dcmtAppointmentSync.notifyAppointmentChanged();
            }
        } catch (e) {
            alert(labels.failUpdate || 'Failed to update appointment');
            endingIds.delete(id);
            endBtn.disabled = false;
            endBtn.innerHTML = originalHtml;
            lastRenderKey = '';
        }
    }

    if (listEl) {
        listEl.addEventListener('click', function(ev) {
            const btn = ev.target.closest('.dcmt-header-appt-end-btn');
            if (!btn) return;
            ev.preventDefault();
            const appointmentId = btn.getAttribute('data-appointment-id') || '';
            endAppointmentFromHeader(appointmentId, btn);
        });
    }

    function startPolling() {
        purgeLegacyStorage();
        const sync = window.dcmtAppointmentSync;
        if (sync && typeof sync.createPollScheduler === 'function') {
            const scheduler = sync.createPollScheduler(pollOngoingFromServer);
            scheduler.start();
            if (typeof sync.bindVisibilityRefresh === 'function') {
                sync.bindVisibilityRefresh(requestHeaderRefresh);
            }
            window.addEventListener('dcmt:appointment-changed', function() {
                requestHeaderRefresh();
            });
            requestHeaderRefresh();
            return;
        }

        requestHeaderRefresh();
        window.setInterval(pollOngoingFromServer, pollIntervalMs);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                requestHeaderRefresh();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startPolling);
    } else {
        startPolling();
    }
})();
</script>
<?php endif; ?>
