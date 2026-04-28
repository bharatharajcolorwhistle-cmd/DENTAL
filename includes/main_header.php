<?php
/**
 * Main Header Component
 * Dental Clinic Management System
 */

$current_user = dcmt_get_current_user();
$dcmt_is_assistant_user = $current_user && ($current_user['dcmt_role'] ?? '') === 'assistant';
$dcmt_is_staff_user = $current_user && ($current_user['dcmt_role'] ?? '') === 'staff';

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
                            <p class="site-description"><?php echo trans('dashboard', 'site_description'); ?></p>
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
                    <div class="header-controls">
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
