<?php
/**
 * Created Appointments List Page
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$current_user = dcmt_get_current_user();
$current_role = $current_user['dcmt_role'] ?? '';
$can_manage = dcmt_is_admin() || in_array($current_role, ['staff', 'assistant'], true);
$is_doctor = $current_role === 'doctor';
$is_owner_doctor = $is_doctor && dcmt_is_admin();
$is_limited_doctor = $is_doctor && !$is_owner_doctor;
$csrf_token = dcmt_generate_csrf_token();

if (!$can_manage && !$is_doctor) {
    dcmt_show_message('Access denied.', 'danger');
    $dcmt_deny_redirect = ($current_role === 'assistant')
        ? DCMT_APP_URL . '/pages/patients/index.php'
        : DCMT_APP_URL . '/pages/dashboard/index.php';
    dcmt_redirect($dcmt_deny_redirect);
    exit();
}

$is_ajax_request = (isset($_GET['ajax']) && $_GET['ajax'] === '1')
    || (isset($_SERVER['HTTP_X_REQUESTED_WITH']) && strtolower((string)$_SERVER['HTTP_X_REQUESTED_WITH']) === 'xmlhttprequest');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($can_manage || $is_doctor)) {
    $ajax_response = [
        'ok' => false,
        'message' => '',
        'appointment_id' => 0,
        'action' => '',
        'actual_start_html' => '',
        'actual_end_html' => '',
        'status_label' => '',
        'status_html' => '',
        'can_start' => false,
        'can_end' => false,
        'can_cancel' => false,
    ];

    $posted_token = (string)($_POST['csrf_token'] ?? '');
    if (!dcmt_verify_csrf_token($posted_token)) {
        $ajax_response['message'] = trans('common', 'invalid_token');
        if (!$is_ajax_request) {
            dcmt_show_message(trans('common', 'invalid_token'), 'danger');
        }
    } else {
        $appointment_id = (int)($_POST['appointment_id'] ?? 0);
        $action = dcmt_sanitize_input($_POST['action'] ?? '');
        if ($appointment_id <= 0 || !in_array($action, ['start', 'end', 'cancel'], true)) {
            $ajax_response['message'] = trans('appointment', 'invalid_request');
            if (!$is_ajax_request) {
                dcmt_show_message(trans('appointment', 'invalid_request'), 'danger');
            }
        } else {
            $now = dcmt_get_current_datetime();
            $doctor_guard_sql = '';
            if ($is_doctor) {
                $doctor_guard_sql = ' AND dcmt_doctor_id = ?';
            }

            try {
                if ($action === 'cancel') {
                    $cancel_params = [$appointment_id];
                    if ($is_doctor) {
                        $cancel_params[] = (int)$current_user['dcmt_id'];
                    }
                    $stmt = $dcmt_pdo->prepare("
                        UPDATE dcmt_appointments
                        SET dcmt_status = 'cancelled'
                        WHERE dcmt_id = ?
                          AND dcmt_status NOT IN ('cancelled', 'completed', 'no_show')
                          $doctor_guard_sql
                    ");
                    $stmt->execute($cancel_params);
                } elseif ($action === 'start') {
                    $base_params = [$now, $appointment_id];
                    if ($is_doctor) {
                        $base_params[] = (int)$current_user['dcmt_id'];
                    }
                    $stmt = $dcmt_pdo->prepare("
                        UPDATE dcmt_appointments
                        SET dcmt_actual_start_at = ?
                        WHERE dcmt_id = ?
                          AND dcmt_status NOT IN ('cancelled', 'no_show')
                          AND dcmt_actual_start_at IS NULL
                          $doctor_guard_sql
                    ");
                    $stmt->execute($base_params);
                } else {
                    $base_params = [$now, $appointment_id];
                    if ($is_doctor) {
                        $base_params[] = (int)$current_user['dcmt_id'];
                    }
                    $stmt = $dcmt_pdo->prepare("
                        UPDATE dcmt_appointments
                        SET dcmt_actual_end_at = ?, dcmt_status = 'completed'
                        WHERE dcmt_id = ?
                          AND dcmt_status NOT IN ('cancelled', 'no_show')
                          AND dcmt_actual_start_at IS NOT NULL
                          AND dcmt_actual_end_at IS NULL
                          $doctor_guard_sql
                    ");
                    $stmt->execute($base_params);
                }

                if ($stmt->rowCount() > 0) {
                    $ajax_response['ok'] = true;
                    if ($action === 'cancel') {
                        $ajax_response['message'] = trans('appointment', 'cancel_success');
                    } else {
                        $ajax_response['message'] = trans('appointment', 'update_success');
                    }
                } else {
                    $ajax_response['message'] = trans('appointment', 'save_failed');
                }
            } catch (PDOException $e) {
                error_log('Appointment start/end update error: ' . $e->getMessage());
                $ajax_response['message'] = trans('appointment', 'database_error');
                if (!$is_ajax_request) {
                    dcmt_show_message(trans('appointment', 'database_error'), 'danger');
                }
            }

            if ($is_ajax_request) {
                try {
                    $select_sql = "SELECT dcmt_actual_start_at, dcmt_actual_end_at, dcmt_status FROM dcmt_appointments WHERE dcmt_id = ?";
                    $select_params = [$appointment_id];
                    if ($is_doctor) {
                        $select_sql .= " AND dcmt_doctor_id = ?";
                        $select_params[] = (int)$current_user['dcmt_id'];
                    }
                    $select_sql .= " LIMIT 1";

                    $select_stmt = $dcmt_pdo->prepare($select_sql);
                    $select_stmt->execute($select_params);
                    $row = $select_stmt->fetch(PDO::FETCH_ASSOC);

                    if ($row) {
                        $raw_status = (string)($row['dcmt_status'] ?? 'scheduled');
                        $normalized_status = $raw_status;
                        if ($raw_status === 'confirmed') {
                            $normalized_status = 'scheduled';
                        } elseif ($raw_status === 'no_show') {
                            $normalized_status = 'cancelled';
                        } elseif (!in_array($raw_status, ['scheduled', 'completed', 'cancelled'], true)) {
                            $normalized_status = 'scheduled';
                        }

                        $has_actual_start = !empty($row['dcmt_actual_start_at']);
                        $has_actual_end = !empty($row['dcmt_actual_end_at']);
                        $is_completed = $normalized_status === 'completed';
                        $is_cancelled = $normalized_status === 'cancelled';

                        $ajax_response['appointment_id'] = $appointment_id;
                        $ajax_response['action'] = $action;
                        $ajax_response['status_label'] = trans('appointment', $normalized_status);
                        $status_class = 'text-primary';
                        if ($normalized_status === 'completed') {
                            $status_class = 'text-success';
                        } elseif ($normalized_status === 'cancelled') {
                            $status_class = 'text-danger';
                        }
                        $ajax_response['status_html'] = '<span class="' . $status_class . '">' . htmlspecialchars($ajax_response['status_label']) . '</span>';
                        $ajax_response['actual_start_html'] = $has_actual_start
                            ? htmlspecialchars(date('h:i A', strtotime((string)$row['dcmt_actual_start_at'])))
                            : '<span class="text-muted">-</span>';
                        $ajax_response['actual_end_html'] = $has_actual_end
                            ? htmlspecialchars(date('h:i A', strtotime((string)$row['dcmt_actual_end_at'])))
                            : '<span class="text-muted">-</span>';
                        $ajax_response['can_start'] = ($can_manage || $is_doctor) && !$is_completed && !$is_cancelled && !$has_actual_start;
                        $ajax_response['can_end'] = ($can_manage || $is_doctor) && !$is_completed && !$is_cancelled && $has_actual_start && !$has_actual_end;
                        $ajax_response['can_cancel'] = ($can_manage || $is_doctor) && !$is_completed && !$is_cancelled;
                    } else {
                        $ajax_response['ok'] = false;
                        $ajax_response['message'] = trans('appointment', 'invalid_request');
                    }
                } catch (PDOException $e) {
                    error_log('Appointment ajax fetch error: ' . $e->getMessage());
                    $ajax_response['ok'] = false;
                    $ajax_response['message'] = trans('appointment', 'database_error');
                }
            }
        }
    }

    if ($is_ajax_request) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode($ajax_response);
        exit();
    }

    if ($ajax_response['ok']) {
        dcmt_show_message($ajax_response['message'], 'success');
    } elseif ($ajax_response['message'] !== '') {
        dcmt_show_message($ajax_response['message'], 'warning');
    }

    $redirect_query = $_SERVER['QUERY_STRING'] ?? '';
    dcmt_redirect('list.php' . ($redirect_query !== '' ? ('?' . $redirect_query) : ''));
    exit();
}

$search = dcmt_sanitize_input($_GET['search'] ?? '');
$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$status = dcmt_sanitize_input($_GET['status'] ?? '');
$date_range = dcmt_sanitize_input($_GET['date_range'] ?? '');
$clear_filters = isset($_GET['clear']) && $_GET['clear'] === '1';
$is_date_range_provided = isset($_GET['date_range']);
$has_active_non_date_filters = $search !== ''
    || ((int)($_GET['doctor_id'] ?? 0)) > 0
    || $status !== '';
$from_date = '';
$to_date = '';
if ($date_range !== '' && strpos($date_range, ' to ') !== false) {
    $date_parts = explode(' to ', $date_range, 2);
    $from_date = trim((string)($date_parts[0] ?? ''));
    $to_date = trim((string)($date_parts[1] ?? ''));
} else {
    $from_date = array_key_exists('from_date', $_GET) ? dcmt_sanitize_input($_GET['from_date']) : '';
    $to_date = array_key_exists('to_date', $_GET) ? dcmt_sanitize_input($_GET['to_date']) : '';
}

if (
    $from_date === ''
    && $to_date === ''
    && !$clear_filters
    && !$is_date_range_provided
    && !$has_active_non_date_filters
) {
    $today_date = dcmt_get_current_date();
    $from_date = $today_date;
    $to_date = $today_date;
}
if ($date_range === '' && $from_date !== '' && $to_date !== '') {
    $date_range = $from_date . ' to ' . $to_date;
}

$page = max(1, (int)($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$status_options = ['scheduled', 'completed', 'cancelled'];
$status_expression = "CASE
    WHEN a.dcmt_status = 'confirmed' THEN 'scheduled'
    WHEN a.dcmt_status = 'no_show' THEN 'cancelled'
    WHEN a.dcmt_status IN ('scheduled', 'completed', 'cancelled') THEN a.dcmt_status
    ELSE 'scheduled'
END";
$where = [];
$params = [];

if ($is_limited_doctor) {
    $doctor_id = (int)$current_user['dcmt_id'];
}

if ($doctor_id > 0) {
    $where[] = "a.dcmt_doctor_id = ?";
    $params[] = $doctor_id;
}
if ($status !== '' && in_array($status, $status_options, true)) {
    $where[] = "($status_expression) = ?";
    $params[] = $status;
}
if ($from_date !== '') {
    $where[] = "DATE(a.dcmt_start_at) >= ?";
    $params[] = $from_date;
}
if ($to_date !== '') {
    $where[] = "DATE(a.dcmt_start_at) <= ?";
    $params[] = $to_date;
}
if ($search !== '') {
    $where[] = "(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ? OR d.dcmt_full_name LIKE ? OR a.dcmt_reason LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like]);
}

$where_sql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$doctors = [];
$appointments = [];
$total_records = 0;
$total_pages = 1;
$status_counts = ['scheduled' => 0, 'completed' => 0, 'cancelled' => 0];

try {
    $doctor_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_full_name, COALESCE(dcmt_color_code, '') AS dcmt_color_code FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name ASC");
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);

    $count_sql = "
        SELECT COUNT(*)
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        $where_sql
    ";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int)$count_stmt->fetchColumn();
    $total_pages = max(1, (int)ceil($total_records / $per_page));
    if ($page > $total_pages) {
        $page = $total_pages;
        $offset = ($page - 1) * $per_page;
    }

    $status_count_sql = "
        SELECT ($status_expression) AS normalized_status, COUNT(*) AS total_count
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        $where_sql
        GROUP BY normalized_status
    ";
    $status_count_stmt = $dcmt_pdo->prepare($status_count_sql);
    $status_count_stmt->execute($params);
    $status_count_rows = $status_count_stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($status_count_rows as $count_row) {
        $key = $count_row['normalized_status'] ?? '';
        if (array_key_exists($key, $status_counts)) {
            $status_counts[$key] = (int)$count_row['total_count'];
        }
    }

    $list_sql = "
        SELECT a.dcmt_id, a.dcmt_patient_id, a.dcmt_start_at, a.dcmt_end_at, a.dcmt_actual_start_at, a.dcmt_actual_end_at, a.dcmt_status, a.dcmt_reason, a.dcmt_notes,
               ($status_expression) AS normalized_status,
               p.dcmt_patient_name, p.dcmt_phone, d.dcmt_full_name AS doctor_name, COALESCE(d.dcmt_color_code, '') AS doctor_color,
               o.dcmt_name AS operatory_name,
               u_creator.dcmt_full_name AS created_by_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        LEFT JOIN dcmt_users u_creator ON u_creator.dcmt_id = a.dcmt_created_by
        $where_sql
        ORDER BY a.dcmt_start_at DESC
        LIMIT ? OFFSET ?
    ";
    $list_params = $params;
    $list_params[] = $per_page;
    $list_params[] = $offset;
    $list_stmt = $dcmt_pdo->prepare($list_sql);
    $list_stmt->execute($list_params);
    $appointments = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Appointment list page error: ' . $e->getMessage());
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md">
                <label class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text"
                       class="form-control dcmt-filter-field"
                       name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('patient', 'search_placeholder'); ?>">
            </div>
            <div class="col-md">
                <label class="form-label"><?php echo trans('appointment', 'doctor'); ?></label>
                <select class="form-select dcmt-filter-field" name="doctor_id" <?php echo $is_limited_doctor ? 'disabled' : ''; ?>>
                    <option value="">All</option>
                    <?php foreach ($doctors as $doctor): ?>
                        <?php $did = (int)$doctor['dcmt_id']; ?>
                        <?php
                            $doctor_filter_color = strtoupper(trim((string)($doctor['dcmt_color_code'] ?? '')));
                            $doctor_filter_color_valid = preg_match('/^#([0-9A-F]{6})$/', $doctor_filter_color) === 1;
                        ?>
                        <option value="<?php echo $did; ?>" <?php echo $doctor_id === $did ? 'selected' : ''; ?> <?php echo $doctor_filter_color_valid ? ('style="color:' . htmlspecialchars($doctor_filter_color) . ';"') : ''; ?>>
                            <?php echo htmlspecialchars($doctor['dcmt_full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($is_limited_doctor): ?>
                    <input type="hidden" name="doctor_id" value="<?php echo (int)$doctor_id; ?>">
                <?php endif; ?>
            </div>
            <div class="col-md">
                <label class="form-label"><?php echo trans('appointment', 'status'); ?></label>
                <select class="form-select dcmt-filter-field" name="status">
                    <option value="">All</option>
                    <?php foreach ($status_options as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>>
                            <?php echo trans('appointment', $s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label class="form-label"><?php echo trans('income', 'date_range', 'Date Range'); ?></label>
                <input type="text"
                       class="form-control dcmt-daterange-picker dcmt-filter-field"
                       id="date_range"
                       name="date_range"
                       value="<?php echo htmlspecialchars($date_range); ?>"
                       placeholder="<?php echo trans('income', 'select_date_range', 'Select date range'); ?>"
                       readonly>
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="list.php?clear=1" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="row g-3 mb-3">
    <div class="col-md-4">
        <div class="card h-100 border-primary">
            <div class="card-body py-2">
                <small class="text-muted"><?php echo trans('appointment', 'scheduled'); ?></small>
                <div class="fw-bold fs-5 text-primary"><?php echo (int)$status_counts['scheduled']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-secondary">
            <div class="card-body py-2">
                <small class="text-muted"><?php echo trans('appointment', 'completed'); ?></small>
                <div class="fw-bold fs-5 text-secondary"><?php echo (int)$status_counts['completed']; ?></div>
            </div>
        </div>
    </div>
    <div class="col-md-4">
        <div class="card h-100 border-danger">
            <div class="card-body py-2">
                <small class="text-muted"><?php echo trans('appointment', 'cancelled'); ?></small>
                <div class="fw-bold fs-5 text-danger"><?php echo (int)$status_counts['cancelled']; ?></div>
            </div>
        </div>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('appointment', 'created_appointments'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('patient', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                        <?php echo trans('patient', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex flex-wrap align-items-center gap-2">
                <div id="dcmtBulkExportActions" class="d-none align-items-center flex-wrap">
                    <span class="text-muted small me-2">Selected:&nbsp;<span id="dcmtBulkSelectedCount">0</span></span>
                    <a href="#" id="dcmtBulkGoogleIcsLink" class="dcmt-add-form-view-all-link me-2">
                        <i class="fas fa-calendar-plus me-1"></i><?php echo trans('appointment', 'google_calendar'); ?>
                    </a>
                    <a href="#" id="dcmtBulkIcsLink" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-file-download me-1"></i><?php echo trans('appointment', 'download_ics'); ?>
                    </a>
                </div>
                <a href="index.php" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-calendar-alt me-1"></i><?php echo trans('appointment', 'appointment_calendar'); ?>
                </a>
                <?php if ($can_manage): ?>
                    <a href="add.php" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'add_appointment'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($appointments)): ?>
            <div class="text-center py-4">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('common', 'no_records_found'); ?></h5>
                <p class="text-muted"><?php echo trans('appointment', 'created_appointments'); ?></p>
                <?php if ($can_manage): ?>
                    <a href="add.php" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'add_appointment'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width:32px;">
                                <input type="checkbox" class="form-check-input js-appt-select-all" aria-label="Select all">
                            </th>
                            <th><?php echo trans('common', 'date'); ?></th>
                            <th><?php echo trans('appointment', 'patient'); ?></th>
                            <th><?php echo trans('appointment', 'start_time'); ?></th>
                            <th><?php echo trans('appointment', 'end_time'); ?></th>
                            <th><?php echo trans('appointment', 'actual_start_time'); ?></th>
                            <th><?php echo trans('appointment', 'actual_end_time'); ?></th>
                            <th><?php echo trans('appointment', 'doctor'); ?></th>
                            <th><?php echo trans('appointment', 'operatory'); ?></th>
                            <th><?php echo trans('appointment', 'status'); ?></th>
                            <th><?php echo trans('common', 'created_by'); ?></th>
                            <th>Clinical Action</th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($appointments as $appointment): ?>
                            <?php
                                $appointment_id = (int)$appointment['dcmt_id'];
                                $patient_name = (string)($appointment['dcmt_patient_name'] ?? '');
                                $doctor_name = (string)($appointment['doctor_name'] ?? '');
                                $reason_text = trim((string)($appointment['dcmt_reason'] ?? ''));
                                $notes_text = trim((string)($appointment['dcmt_notes'] ?? ''));
                                $doctor_color = strtoupper(trim((string)($appointment['doctor_color'] ?? '')));
                                $doctor_color_valid = preg_match('/^#([0-9A-F]{6})$/', $doctor_color) === 1;

                                $normalized_status = (string)($appointment['normalized_status'] ?? 'scheduled');
                                $status_class = 'text-primary';
                                if ($normalized_status === 'completed') {
                                    $status_class = 'text-success';
                                } elseif ($normalized_status === 'cancelled') {
                                    $status_class = 'text-danger';
                                }
                            ?>
                            <tr data-appointment-id="<?php echo $appointment_id; ?>">
                                <td>
                                    <input type="checkbox" class="form-check-input js-appt-select" value="<?php echo $appointment_id; ?>" aria-label="Select appointment">
                                </td>
                                <td><?php echo dcmt_format_date($appointment['dcmt_start_at']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($appointment['dcmt_patient_name']); ?>
                                    <?php if (!empty($appointment['dcmt_phone'])): ?>
                                        <div class="text-muted small"><?php echo htmlspecialchars($appointment['dcmt_phone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('h:i A', strtotime($appointment['dcmt_start_at'])); ?></td>
                                <td><?php echo date('h:i A', strtotime($appointment['dcmt_end_at'])); ?></td>
                                <td data-col="actual-start">
                                    <?php echo !empty($appointment['dcmt_actual_start_at']) ? date('h:i A', strtotime((string)$appointment['dcmt_actual_start_at'])) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td data-col="actual-end">
                                    <?php echo !empty($appointment['dcmt_actual_end_at']) ? date('h:i A', strtotime((string)$appointment['dcmt_actual_end_at'])) : '<span class="text-muted">-</span>'; ?>
                                </td>
                                <td <?php echo $doctor_color_valid ? ('style="color:' . htmlspecialchars($doctor_color) . ';"') : ''; ?>>
                                    <?php echo htmlspecialchars($appointment['doctor_name']); ?>
                                </td>
                                <td><?php echo htmlspecialchars((string)($appointment['operatory_name'] ?? '')); ?></td>
                                <td data-col="status"><span class="<?php echo $status_class; ?>"><?php echo trans('appointment', $normalized_status); ?></span></td>
                                <td>
                                    <?php
                                    $creator_name = trim((string)($appointment['created_by_name'] ?? ''));
                                    echo $creator_name !== '' ? htmlspecialchars($creator_name) : '<span class="text-muted">—</span>';
                                    ?>
                                </td>
                                <td>
                                    <div class="d-flex flex-column gap-2 align-items-start">
                                        <?php
                                        $has_actual_start = !empty($appointment['dcmt_actual_start_at']);
                                        $has_actual_end = !empty($appointment['dcmt_actual_end_at']);
                                        $is_completed = $normalized_status === 'completed';
                                        $is_cancelled = $normalized_status === 'cancelled';
                                        $can_start_appt = ($can_manage || $is_doctor) && !$is_completed && !$is_cancelled && !$has_actual_start;
                                        $can_end_appt = ($can_manage || $is_doctor) && !$is_completed && !$is_cancelled && $has_actual_start && !$has_actual_end;
                                        $appt_patient_id = (int)($appointment['dcmt_patient_id'] ?? 0);
                                        $time_start_hm = date('H:i', strtotime((string)$appointment['dcmt_start_at']));
                                        $time_end_hm = date('H:i', strtotime((string)$appointment['dcmt_end_at']));
                                        $wa_phone = preg_replace('/\D+/', '', (string)($appointment['dcmt_phone'] ?? ''));
                                        $wa_message = rawurlencode('Hello ' . (string)$appointment['dcmt_patient_name'] . ', this is a reminder for your appointment at ' . $time_start_hm . '.');
                                        $wa_link = $wa_phone !== '' ? ('https://wa.me/' . $wa_phone . '?text=' . $wa_message) : '#';
                                        ?>
                                        <?php if (($can_manage || $is_doctor) && !$is_completed && !$is_cancelled): ?>
                                            <div class="d-flex flex-column gap-1 js-appt-live-actions">
                                                <?php
                                                $toggle_action = $can_start_appt ? 'start' : 'end';
                                                $toggle_label = $can_start_appt ? trans('appointment', 'appointment_start') : trans('appointment', 'appointment_end');
                                                $toggle_btn_class = $can_start_appt ? 'dcmt-pill-btn dcmt-pill-btn-start' : 'dcmt-pill-btn dcmt-pill-btn-end';
                                                $toggle_disabled = !$can_start_appt && !$can_end_appt;
                                                ?>
                                                <button
                                                    type="button"
                                                    class="<?php echo htmlspecialchars($toggle_btn_class); ?> js-appt-toggle"
                                                    data-appointment-id="<?php echo $appointment_id; ?>"
                                                    data-action="<?php echo htmlspecialchars($toggle_action); ?>"
                                                    data-label-start="<?php echo htmlspecialchars(trans('appointment', 'appointment_start')); ?>"
                                                    data-label-end="<?php echo htmlspecialchars(trans('appointment', 'appointment_end')); ?>"
                                                    <?php echo $toggle_disabled ? 'disabled' : ''; ?>
                                                ><?php echo htmlspecialchars($toggle_label); ?></button>
                                                <button
                                                    type="button"
                                                    class="dcmt-pill-btn dcmt-pill-btn-cancel js-appt-cancel"
                                                    data-appointment-id="<?php echo $appointment_id; ?>"
                                                    data-confirm-message="<?php echo htmlspecialchars(trans('appointment', 'cancel_appointment_confirm'), ENT_QUOTES, 'UTF-8'); ?>"
                                                ><?php echo trans('common', 'cancel'); ?></button>
                                            </div>
                                        <?php endif; ?>
                                        <div class="d-flex align-items-center gap-2">
                                            <a class="dcmt-icon-btn" href="../patient_notes/index.php?patient_id=<?php echo $appt_patient_id; ?>" title="<?php echo htmlspecialchars(trans('appointment', 'view_clinical_history')); ?>">
                                                <i class="far fa-file-alt"></i>
                                            </a>
                                            <a class="dcmt-icon-btn" href="../patient_notes/add.php?patient_id=<?php echo $appt_patient_id; ?>" title="<?php echo htmlspecialchars(trans('patient_note', 'add_note')); ?>">
                                                <i class="fas fa-notes-medical"></i>
                                            </a>
                                            <a class="dcmt-icon-btn" href="add.php?patient_id=<?php echo $appt_patient_id; ?>&date=<?php echo urlencode(date('Y-m-d', strtotime((string)$appointment['dcmt_start_at']))); ?>&start=<?php echo urlencode($time_start_hm); ?>&end=<?php echo urlencode($time_end_hm); ?>" title="<?php echo htmlspecialchars(trans('appointment', 'add_appointment')); ?>">
                                                <i class="far fa-calendar-plus"></i>
                                            </a>
                                            <a class="dcmt-icon-btn dcmt-icon-btn-chat <?php echo $wa_phone === '' ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars($wa_link); ?>" <?php echo $wa_phone === '' ? 'tabindex="-1" aria-disabled="true"' : 'target="_blank" rel="noopener noreferrer"'; ?> title="<?php echo htmlspecialchars(trans('common', 'message')); ?>">
                                                <i class="fab fa-whatsapp"></i>
                                            </a>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $appointment_id; ?>"
                                           class="btn"
                                           title="<?php echo htmlspecialchars(trans('common', 'view')); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="<?php echo htmlspecialchars(trans('common', 'view')); ?>">
                                        </a>
                                        <?php
                                            $edit_allowed_for_status = ($normalized_status !== 'completed' && $normalized_status !== 'cancelled')
                                                || dcmt_is_admin();
                                        ?>
                                        <?php if ($can_manage && $edit_allowed_for_status): ?>
                                            <a href="edit.php?id=<?php echo $appointment_id; ?>"
                                               class="btn"
                                               title="<?php echo htmlspecialchars(trans('common', 'edit')); ?>">
                                                <img src="../../assets/images/edit.svg" alt="<?php echo htmlspecialchars(trans('common', 'edit')); ?>">
                                            </a>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo htmlspecialchars(trans('appointment', 'created_appointments')); ?> pagination" class="mt-3">
                    <ul class="pagination justify-content-center flex-wrap">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                    <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                    <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                    <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" title="<?php echo trans('common', 'last_page'); ?>">
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

<?php if ($can_manage || $is_doctor): ?>
<script>
window.dcmtAppointmentList = window.dcmtAppointmentList || {};
window.dcmtAppointmentList.csrfToken = <?php echo json_encode($csrf_token); ?>;
window.dcmtAppointmentList.ajaxUrl = <?php echo json_encode('list.php?ajax=1'); ?>;
window.dcmtAppointmentList.exportUrl = <?php echo json_encode('export_ics.php'); ?>;
</script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const cfg = window.dcmtAppointmentList || {};
    const csrfToken = cfg.csrfToken || '';
    const ajaxUrl = cfg.ajaxUrl || '';
    const exportUrl = cfg.exportUrl || '';
    if (window.jQuery && window.moment && jQuery.fn && typeof jQuery.fn.daterangepicker === 'function') {
        const $dateRange = jQuery('#date_range');
        if ($dateRange.length) {
            $dateRange.daterangepicker({
                autoUpdateInput: false,
                autoApply: true,
                locale: {
                    cancelLabel: <?php echo json_encode(trans('common', 'clear')); ?>,
                    applyLabel: <?php echo json_encode(trans('common', 'apply')); ?>,
                    format: 'YYYY-MM-DD',
                    separator: ' to ',
                    customRangeLabel: <?php echo json_encode(trans('common', 'custom_range')); ?>
                },
                ranges: {
                    <?php echo json_encode(trans('common', 'today')); ?>: [moment(), moment()],
                    <?php echo json_encode(trans('common', 'yesterday')); ?>: [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
                    <?php echo json_encode(trans('common', 'last_7_days')); ?>: [moment().subtract(6, 'days'), moment()],
                    <?php echo json_encode(trans('common', 'last_30_days')); ?>: [moment().subtract(29, 'days'), moment()],
                    <?php echo json_encode(trans('common', 'this_month')); ?>: [moment().startOf('month'), moment().endOf('month')],
                    <?php echo json_encode(trans('common', 'last_month')); ?>: [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
                    <?php echo json_encode(trans('common', 'this_year')); ?>: [moment().startOf('year'), moment().endOf('year')],
                    <?php echo json_encode(trans('common', 'last_year')); ?>: [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
                },
                opens: 'left',
                drops: 'down'
            });
            $dateRange.on('apply.daterangepicker', function(ev, picker) {
                jQuery(this).val(picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format('YYYY-MM-DD'));
            });
            $dateRange.on('cancel.daterangepicker', function() {
                jQuery(this).val('');
            });
            <?php if (!empty($date_range)): ?>
            $dateRange.val(<?php echo json_encode($date_range); ?>);
            <?php endif; ?>
        }
    }

    function setButtonLoading(btn, loading) {
        if (!btn) return;
        if (loading) {
            if (!btn.dataset.originalHtml) {
                btn.dataset.originalHtml = btn.innerHTML;
            }
            btn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';
        } else if (btn.dataset.originalHtml) {
            btn.innerHTML = btn.dataset.originalHtml;
        }
    }

    function syncApptToggleButton(row, data) {
        const btn = row.querySelector('.js-appt-toggle');
        if (!btn || !data) return;
        const labelStart = btn.getAttribute('data-label-start') || '';
        const labelEnd = btn.getAttribute('data-label-end') || '';
        btn.classList.remove('dcmt-pill-btn-start', 'dcmt-pill-btn-end');
        if (data.can_start) {
            btn.setAttribute('data-action', 'start');
            btn.classList.add('dcmt-pill-btn-start');
            btn.textContent = labelStart;
            btn.disabled = false;
        } else if (data.can_end) {
            btn.setAttribute('data-action', 'end');
            btn.classList.add('dcmt-pill-btn-end');
            btn.textContent = labelEnd;
            btn.disabled = false;
        } else {
            btn.setAttribute('data-action', 'end');
            btn.classList.add('dcmt-pill-btn-end');
            btn.textContent = labelEnd;
            btn.disabled = true;
        }
        delete btn.dataset.originalHtml;
    }

    function showInlineMessage(type, message) {
        const container = document.querySelector('.dcmt-filter-form .card-body');
        if (!container) return;

        const alert = document.createElement('div');
        alert.className = 'alert alert-' + type + ' mt-3 mb-0';
        alert.setAttribute('role', 'alert');
        alert.textContent = message || '';
        container.appendChild(alert);
        setTimeout(() => {
            if (alert && alert.parentNode) {
                alert.parentNode.removeChild(alert);
            }
        }, 2500);
    }

    async function sendAppointmentAction(appointmentId, action, clickedBtn, confirmMessage) {
        if (!ajaxUrl || !csrfToken) return;

        const row = document.querySelector('tr[data-appointment-id="' + appointmentId + '"]');
        if (!row) return;
        if (row.dataset.loading === '1') return;
        if (confirmMessage && !window.confirm(confirmMessage)) return;

        row.dataset.loading = '1';

        if (clickedBtn) {
            clickedBtn.disabled = true;
            setButtonLoading(clickedBtn, true);
        }

        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('appointment_id', String(appointmentId));
        body.set('action', String(action));

        try {
            const res = await fetch(ajaxUrl, {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8',
                    'X-Requested-With': 'XMLHttpRequest'
                },
                body: body.toString()
            });

            const data = await res.json();
            if (!data || typeof data !== 'object') {
                showInlineMessage('warning', 'Unexpected response.');
                if (clickedBtn) clickedBtn.disabled = false;
                return;
            }

            if (!data.ok) {
                showInlineMessage('warning', data.message || 'Unable to save.');
                if (clickedBtn) clickedBtn.disabled = false;
                return;
            }

            const actualStartCell = row.querySelector('[data-col="actual-start"]');
            const actualEndCell = row.querySelector('[data-col="actual-end"]');
            const statusCell = row.querySelector('[data-col="status"]');

            if (actualStartCell && typeof data.actual_start_html === 'string') {
                actualStartCell.innerHTML = data.actual_start_html;
            }
            if (actualEndCell && typeof data.actual_end_html === 'string') {
                actualEndCell.innerHTML = data.actual_end_html;
            }
            if (statusCell && typeof data.status_label === 'string') {
                if (typeof data.status_html === 'string' && data.status_html) {
                    statusCell.innerHTML = data.status_html;
                } else {
                    statusCell.textContent = data.status_label;
                }
            }

            const liveWrap = row.querySelector('.js-appt-live-actions');
            const isTerminal = data.can_start === false && data.can_end === false && data.can_cancel === false;
            if (isTerminal && liveWrap) {
                liveWrap.remove();
            } else {
                syncApptToggleButton(row, data);
                const cancelBtn = row.querySelector('.js-appt-cancel');
                if (cancelBtn && typeof data.can_cancel === 'boolean') {
                    cancelBtn.disabled = !data.can_cancel;
                }
            }

            showInlineMessage('success', data.message || 'Saved.');
        } catch (e) {
            showInlineMessage('warning', 'Network error.');
            if (clickedBtn) clickedBtn.disabled = false;
        } finally {
            if (clickedBtn) {
                setButtonLoading(clickedBtn, false);
            }
            row.dataset.loading = '0';
        }
    }

    const apptTableBody = document.querySelector('.dcmt-records-table .table tbody');
    if (apptTableBody) {
        apptTableBody.addEventListener('click', function(ev) {
            const toggle = ev.target.closest('.js-appt-toggle');
            if (toggle && !toggle.disabled) {
                const appointmentId = toggle.getAttribute('data-appointment-id') || '';
                const action = toggle.getAttribute('data-action') || '';
                if (!appointmentId || !action) return;
                sendAppointmentAction(appointmentId, action, toggle, '');
                return;
            }
            const cancelBtn = ev.target.closest('.js-appt-cancel');
            if (cancelBtn && !cancelBtn.disabled) {
                const appointmentId = cancelBtn.getAttribute('data-appointment-id') || '';
                const msg = (cancelBtn.getAttribute('data-confirm-message') || '').trim();
                if (!appointmentId) return;
                sendAppointmentAction(appointmentId, 'cancel', cancelBtn, msg);
            }
        });
    }

    const bulkActionsEl = document.getElementById('dcmtBulkExportActions');
    const bulkCountEl = document.getElementById('dcmtBulkSelectedCount');
    const bulkIcsLink = document.getElementById('dcmtBulkIcsLink');
    const bulkGoogleIcsLink = document.getElementById('dcmtBulkGoogleIcsLink');
    const selectAllEl = document.querySelector('.js-appt-select-all');
    const selectedIds = new Set();

    function updateBulkUI() {
        const count = selectedIds.size;
        if (bulkCountEl) bulkCountEl.textContent = String(count);
        if (bulkActionsEl) {
            if (count > 0) {
                bulkActionsEl.classList.remove('d-none');
                bulkActionsEl.classList.add('d-flex');
            } else {
                bulkActionsEl.classList.add('d-none');
                bulkActionsEl.classList.remove('d-flex');
            }
        }

        if (exportUrl && (bulkIcsLink || bulkGoogleIcsLink)) {
            const ids = Array.from(selectedIds).join(',');
            const href = exportUrl + '?ids=' + encodeURIComponent(ids);
            if (bulkIcsLink) bulkIcsLink.setAttribute('href', href);
            if (bulkGoogleIcsLink) bulkGoogleIcsLink.setAttribute('href', href);
        }

        if (selectAllEl) {
            const boxes = Array.from(document.querySelectorAll('.js-appt-select'));
            const checkedCount = boxes.filter(b => b.checked).length;
            selectAllEl.checked = boxes.length > 0 && checkedCount === boxes.length;
            selectAllEl.indeterminate = checkedCount > 0 && checkedCount < boxes.length;
        }
    }

    document.querySelectorAll('.js-appt-select').forEach((cb) => {
        cb.addEventListener('change', function() {
            const id = String(this.value || '');
            if (!id) return;
            if (this.checked) {
                selectedIds.add(id);
            } else {
                selectedIds.delete(id);
            }
            updateBulkUI();
        });
    });

    if (selectAllEl) {
        selectAllEl.addEventListener('change', function() {
            const boxes = Array.from(document.querySelectorAll('.js-appt-select'));
            boxes.forEach((cb) => {
                cb.checked = !!selectAllEl.checked;
                const id = String(cb.value || '');
                if (!id) return;
                if (cb.checked) {
                    selectedIds.add(id);
                } else {
                    selectedIds.delete(id);
                }
            });
            updateBulkUI();
        });
    }

    updateBulkUI();
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
