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
    try {
        $dcmt_birthday_count_stmt = $dcmt_pdo->prepare("
            SELECT COUNT(*)
            FROM dcmt_patients
            WHERE dcmt_date_of_birth IS NOT NULL
              AND dcmt_status = 'active'
              AND MONTH(dcmt_date_of_birth) = ?
              AND DAY(dcmt_date_of_birth) = ?
        ");
        $dcmt_birthday_count_stmt->execute([(int) date('m'), (int) date('d')]);
        $dcmt_today_birthday_count = (int) $dcmt_birthday_count_stmt->fetchColumn();
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
                    <?php if ($dcmt_header_can_manage_appointments): ?>
                        <div id="dcmtOngoingAppointmentHeader" class="d-none">
                            <div class="dcmt-header-appt-wrap">
                                <a id="dcmtOngoingAppointmentLink" class="text-decoration-none" href="#">
                                    <span id="dcmtOngoingAppointmentText" class="fw-semibold"></span>
                                </a>
                                <button id="dcmtOngoingAppointmentEndBtn" type="button" class="dcmt-pill-btn dcmt-pill-btn-end">
                                    <?php echo htmlspecialchars(trans('appointment', 'appointment_end')); ?>
                                </button>
                            </div>
                        </div>
                        <script>
                        window.dcmtHeaderOngoingAppt = window.dcmtHeaderOngoingAppt || {};
                        window.dcmtHeaderOngoingAppt.basePath = <?php echo json_encode($base_path); ?>;
                        window.dcmtHeaderOngoingAppt.csrfToken = <?php echo json_encode(dcmt_generate_csrf_token()); ?>;
                        window.dcmtHeaderOngoingAppt.labels = {
                            end: <?php echo json_encode(trans('appointment', 'appointment_end')); ?>
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

<?php if ($dcmt_show_birthday_notice): ?>
    <div class="container-fluid dentl-alert">
        <div id="dcmtBirthdayHeaderAlert" class="alert alert-info alert-dismissible fade show d-flex flex-column flex-md-row justify-content-between align-items-md-center gap-2 mb-0 mt-2" data-persistent="true" role="alert">
            <div class="d-flex align-items-center">
                <i class="fas fa-birthday-cake me-2"></i>
                <strong class="me-1">Patient Birthday:</strong>
                <span>
                    <?php echo htmlspecialchars((string) $dcmt_today_birthday_count); ?>
                    <?php echo $dcmt_today_birthday_count === 1 ? 'patient has' : 'patients have'; ?> birthday today.
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
    const storageKey = 'dcmt_ongoing_appointment';
    const stateUrl = basePath + 'pages/dashboard/appointment_board_state_ajax.php';

    function safeParseJson(raw) {
        try {
            return JSON.parse(raw);
        } catch (e) {
            return null;
        }
    }

    function normalizeStored(value) {
        if (!value || typeof value !== 'object') return null;
        const id = String(value.id || '').trim();
        if (!id) return null;
        return {
            id,
            patient_name: String(value.patient_name || '').trim(),
            doctor_name: String(value.doctor_name || '').trim()
        };
    }

    function getStored() {
        const raw = window.localStorage ? window.localStorage.getItem(storageKey) : '';
        return normalizeStored(safeParseJson(raw || ''));
    }

    function setStored(value) {
        if (!window.localStorage) return;
        const normalized = normalizeStored(value);
        if (!normalized) return;
        window.localStorage.setItem(storageKey, JSON.stringify(normalized));
    }

    function clearStored(appointmentId) {
        if (!window.localStorage) return;
        if (!appointmentId) {
            window.localStorage.removeItem(storageKey);
            return;
        }
        const existing = getStored();
        if (existing && existing.id === String(appointmentId)) {
            window.localStorage.removeItem(storageKey);
        }
    }

    window.dcmtSetOngoingAppointment = function(value) {
        setStored(value);
        refreshFromStorage();
    };

    window.dcmtClearOngoingAppointment = function(appointmentId) {
        clearStored(appointmentId);
        refreshFromStorage();
    };

    const wrap = document.getElementById('dcmtOngoingAppointmentHeader');
    const linkEl = document.getElementById('dcmtOngoingAppointmentLink');
    const textEl = document.getElementById('dcmtOngoingAppointmentText');
    const endBtn = document.getElementById('dcmtOngoingAppointmentEndBtn');

    function setVisible(visible) {
        if (!wrap) return;
        if (visible) {
            wrap.classList.remove('d-none');
        } else {
            wrap.classList.add('d-none');
        }
    }

    function setEndVisible(visible) {
        if (!endBtn) return;
        if (visible) {
            endBtn.classList.remove('d-none');
        } else {
            endBtn.classList.add('d-none');
        }
    }

    function render(stored) {
        if (!wrap || !linkEl || !textEl || !endBtn) return;
        if (!stored) {
            setVisible(false);
            linkEl.setAttribute('href', '#');
            textEl.textContent = '';
            endBtn.disabled = false;
            setEndVisible(true);
            return;
        }

        const labelParts = [];
        if (stored.patient_name) labelParts.push(stored.patient_name);
        if (stored.doctor_name) labelParts.push(stored.doctor_name);
        textEl.textContent = labelParts.length ? labelParts.join(' · ') : ('#' + stored.id);
        linkEl.setAttribute('href', basePath + 'pages/appointments/view.php?id=' + encodeURIComponent(stored.id));
        setVisible(true);
        setEndVisible(true);
    }

    function renderMultiple(items, totalCount) {
        if (!wrap || !linkEl || !textEl || !endBtn) return;
        const count = Number.parseInt(totalCount || 0, 10) || 0;
        if (count <= 0) {
            render(null);
            return;
        }
        const first = Array.isArray(items) && items.length ? items[0] : null;
        const p = first ? String(first.patient_name || '').trim() : '';
        const d = first ? String(first.doctor_name || '').trim() : '';
        const base = [p, d].filter(Boolean).join(' · ');
        const suffix = count > 1 ? (' +' + String(count - 1) + ' more') : '';
        textEl.textContent = (base || 'Ongoing appointments') + suffix;
        linkEl.setAttribute('href', basePath + 'pages/dashboard/index.php?tab=appointment');
        setVisible(true);
        setEndVisible(false);
    }

    async function verifyOngoing(stored) {
        if (!stored || !stored.id) return null;
        const url = basePath + 'pages/appointments/get_ajax.php?id=' + encodeURIComponent(stored.id);
        try {
            const res = await fetch(url, { method: 'GET', cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.success || !data.appointment) return null;

            const appt = data.appointment;
            const hasStart = !!(appt.actual_start_time || '');
            const hasEnd = !!(appt.actual_end_time || '');
            const status = String(appt.status || '').trim();
            const active = hasStart && !hasEnd && status !== 'completed' && status !== 'cancelled';
            if (!active) return null;

            return {
                id: String(appt.id || stored.id),
                patient_name: String(appt.patient_name || stored.patient_name || '').trim(),
                doctor_name: String(appt.doctor_name || stored.doctor_name || '').trim()
            };
        } catch (e) {
            return stored;
        }
    }

    let refreshBusy = false;
    async function refreshFromStorage() {
        if (refreshBusy) return;
        refreshBusy = true;
        try {
            const stored = getStored();
            if (!stored) {
                render(null);
                return;
            }
            render(stored);
            const verified = await verifyOngoing(stored);
            if (!verified) {
                clearStored(stored.id);
                render(null);
                return;
            }
            setStored(verified);
            render(verified);
        } finally {
            refreshBusy = false;
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

    let pollBusy = false;
    async function pollOngoingFromServer() {
        if (pollBusy) return;
        pollBusy = true;
        try {
            const server = await fetchServerState();
            if (!server) return;

            const ongoingCount = Number.parseInt(server.ongoing_count || 0, 10) || 0;
            const ongoingList = Array.isArray(server.ongoing) ? server.ongoing : [];
            const stored = getStored();

            if (ongoingCount <= 0) {
                if (stored) {
                    clearStored(stored.id);
                }
                render(null);
                return;
            }

            if (ongoingCount === 1 && ongoingList.length) {
                const one = ongoingList[0] || {};
                const next = {
                    id: String(one.id || '').trim(),
                    patient_name: String(one.patient_name || '').trim(),
                    doctor_name: String(one.doctor_name || '').trim()
                };
                if (next.id) {
                    setStored(next);
                    render(next);
                }
                return;
            }

            if (stored) {
                const storedId = String(stored.id || '');
                const inTop = ongoingList.some((it) => String((it && it.id) || '') === storedId);
                if (!inTop) {
                    const verified = await verifyOngoing(stored);
                    if (!verified) {
                        clearStored(stored.id);
                        renderMultiple(ongoingList, ongoingCount);
                        return;
                    }
                    setStored(verified);
                    render(verified);
                    return;
                }
                render(stored);
                return;
            }

            renderMultiple(ongoingList, ongoingCount);
        } finally {
            pollBusy = false;
        }
    }

    async function endAppointmentFromHeader() {
        if (!endBtn || endBtn.disabled) return;
        const stored = getStored();
        if (!stored) return;
        if (!csrfToken) {
            alert('Invalid token.');
            return;
        }
        endBtn.disabled = true;
        const originalHtml = endBtn.innerHTML;
        endBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('appointment_id', String(stored.id));
        body.set('action', 'end');

        try {
            const res = await fetch(basePath + 'pages/dashboard/appointment_board_action_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString()
            });
            const data = await res.json();
            if (!data || !data.success) {
                alert((data && data.message) ? data.message : 'Failed to update appointment');
                endBtn.disabled = false;
                endBtn.innerHTML = originalHtml;
                return;
            }
            clearStored(stored.id);
            render(null);
            pollOngoingFromServer();
        } catch (e) {
            alert('Failed to update appointment');
            endBtn.disabled = false;
            endBtn.innerHTML = originalHtml;
        }
    }

    if (endBtn) {
        endBtn.addEventListener('click', function() {
            endAppointmentFromHeader();
        });
    }

    window.addEventListener('storage', function(ev) {
        if (ev && ev.key === storageKey) {
            refreshFromStorage();
        }
    });

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', function() {
            refreshFromStorage();
            pollOngoingFromServer();
            window.setInterval(pollOngoingFromServer, 5000);
        });
    } else {
        refreshFromStorage();
        pollOngoingFromServer();
        window.setInterval(pollOngoingFromServer, 5000);
    }
})();
</script>
<?php endif; ?>
