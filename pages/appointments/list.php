<?php
/**
 * Created Appointments List Page
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$current_user = dcmt_get_current_user();
$current_role = $current_user['dcmt_role'] ?? '';
$can_manage = in_array($current_role, ['admin', 'staff'], true);
$is_doctor = $current_role === 'doctor';

if (!$can_manage && !$is_doctor) {
    dcmt_show_message('Access denied.', 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
    exit();
}

$search = dcmt_sanitize_input($_GET['search'] ?? '');
$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$status = dcmt_sanitize_input($_GET['status'] ?? '');
$from_date = dcmt_sanitize_input($_GET['from_date'] ?? '');
$to_date = dcmt_sanitize_input($_GET['to_date'] ?? '');

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

if ($is_doctor) {
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
    $doctor_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_full_name FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name ASC");
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);

    $count_sql = "
        SELECT COUNT(*)
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
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
        SELECT a.dcmt_id, a.dcmt_start_at, a.dcmt_end_at, a.dcmt_status, a.dcmt_reason, a.dcmt_notes,
               ($status_expression) AS normalized_status,
               p.dcmt_patient_name, p.dcmt_phone, d.dcmt_full_name AS doctor_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
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
                <select class="form-select dcmt-filter-field" name="doctor_id" <?php echo $is_doctor ? 'disabled' : ''; ?>>
                    <option value=""><?php echo trans('common', 'all'); ?></option>
                    <?php foreach ($doctors as $doctor): ?>
                        <?php $did = (int)$doctor['dcmt_id']; ?>
                        <option value="<?php echo $did; ?>" <?php echo $doctor_id === $did ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doctor['dcmt_full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <?php if ($is_doctor): ?>
                    <input type="hidden" name="doctor_id" value="<?php echo (int)$doctor_id; ?>">
                <?php endif; ?>
            </div>
            <div class="col-md">
                <label class="form-label"><?php echo trans('appointment', 'status'); ?></label>
                <select class="form-select dcmt-filter-field" name="status">
                    <option value=""><?php echo trans('common', 'all'); ?></option>
                    <?php foreach ($status_options as $s): ?>
                        <option value="<?php echo $s; ?>" <?php echo $status === $s ? 'selected' : ''; ?>>
                            <?php echo trans('appointment', $s); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label class="form-label"><?php echo trans('common', 'from'); ?></label>
                <input type="date" class="form-control dcmt-filter-field" name="from_date" value="<?php echo htmlspecialchars($from_date); ?>">
            </div>
            <div class="col-md">
                <label class="form-label"><?php echo trans('common', 'to'); ?></label>
                <input type="date" class="form-control dcmt-filter-field" name="to_date" value="<?php echo htmlspecialchars($to_date); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="list.php" class="dcmt-add-form-view-all-link text-center">
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
            <div class="ms-3 d-flex gap-2">
                <a href="index.php" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-calendar-alt me-1"></i><?php echo trans('appointment', 'appointment_calendar'); ?>
                </a>
                <?php if ($can_manage): ?>
                    <a href="index.php" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'new_appointment'); ?>
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
                    <a href="index.php" class="btn btn-primary">
                        <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'new_appointment'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('common', 'date'); ?></th>
                            <th><?php echo trans('appointment', 'patient'); ?></th>
                            <th><?php echo trans('appointment', 'start_time'); ?></th>
                            <th><?php echo trans('appointment', 'end_time'); ?></th>
                            <th><?php echo trans('appointment', 'doctor'); ?></th>
                            <th><?php echo trans('appointment', 'status'); ?></th>
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

                                $google_title = 'Appointment - ' . $patient_name;
                                if ($reason_text !== '') {
                                    $google_title .= ' (' . $reason_text . ')';
                                }

                                $google_details = "Patient: {$patient_name}\nDoctor: {$doctor_name}";
                                if ($reason_text !== '') {
                                    $google_details .= "\nReason: {$reason_text}";
                                }
                                if ($notes_text !== '') {
                                    $google_details .= "\nNotes: {$notes_text}";
                                }

                                $google_start = gmdate('Ymd\THis\Z', strtotime((string)$appointment['dcmt_start_at']));
                                $google_end = gmdate('Ymd\THis\Z', strtotime((string)$appointment['dcmt_end_at']));
                                $google_url = 'https://calendar.google.com/calendar/render?action=TEMPLATE'
                                    . '&text=' . urlencode($google_title)
                                    . '&dates=' . urlencode($google_start . '/' . $google_end)
                                    . '&details=' . urlencode($google_details);
                            ?>
                            <tr>
                                <td><?php echo dcmt_format_date($appointment['dcmt_start_at']); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($appointment['dcmt_patient_name']); ?>
                                    <?php if (!empty($appointment['dcmt_phone'])): ?>
                                        <div class="text-muted small"><?php echo htmlspecialchars($appointment['dcmt_phone']); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo date('h:i A', strtotime($appointment['dcmt_start_at'])); ?></td>
                                <td><?php echo date('h:i A', strtotime($appointment['dcmt_end_at'])); ?></td>
                                <td><?php echo htmlspecialchars($appointment['doctor_name']); ?></td>
                                <td><?php echo trans('appointment', $appointment['normalized_status']); ?></td>
                                <td>
                                    <?php if (($appointment['normalized_status'] ?? '') === 'scheduled'): ?>
                                        <div class="d-flex flex-column gap-1">
                                            <a href="<?php echo htmlspecialchars($google_url); ?>" target="_blank" rel="noopener noreferrer" class="dcmt-add-form-view-all-link">
                                                <i class="fas fa-calendar-plus me-1"></i><?php echo trans('appointment', 'google_calendar'); ?>
                                            </a>
                                            <a href="export_ics.php?id=<?php echo $appointment_id; ?>" class="dcmt-add-form-view-all-link">
                                                <i class="fas fa-file-download me-1"></i><?php echo trans('appointment', 'download_ics'); ?>
                                            </a>
                                        </div>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="Appointment list pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link"
                                   href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&doctor_id=<?php echo urlencode((string)$doctor_id); ?>&status=<?php echo urlencode($status); ?>&from_date=<?php echo urlencode($from_date); ?>&to_date=<?php echo urlencode($to_date); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
