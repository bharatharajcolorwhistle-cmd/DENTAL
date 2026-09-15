<?php
/**
 * Active patients with no upcoming scheduled/confirmed appointment.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$dcmt_current_user = dcmt_get_current_user();
$dcmt_can_book = dcmt_is_admin() || in_array((string) ($dcmt_current_user['dcmt_role'] ?? ''), ['staff', 'assistant'], true);

$where = ["p.dcmt_status = 'active'"];
$params = [];
if ($search !== '') {
    $where[] = '(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ? OR p.dcmt_email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$where[] = "NOT EXISTS (
    SELECT 1
    FROM dcmt_appointments a
    WHERE a.dcmt_patient_id = p.dcmt_id
      AND a.dcmt_end_at >= NOW()
      AND a.dcmt_status NOT IN ('cancelled', 'completed', 'no_show')
)";
$where_sql = 'WHERE ' . implode(' AND ', $where);

$patients = [];
$total_records = 0;
$total_pages = 0;

try {
    $count_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_patients p {$where_sql}");
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = (int) ceil($total_records / $per_page);

    $list_sql = "
        SELECT
            p.dcmt_id,
            p.dcmt_patient_name,
            p.dcmt_phone,
            p.dcmt_email,
            (
                SELECT MAX(a2.dcmt_start_at)
                FROM dcmt_appointments a2
                WHERE a2.dcmt_patient_id = p.dcmt_id
                  AND a2.dcmt_status NOT IN ('cancelled')
            ) AS last_appointment_at
        FROM dcmt_patients p
        {$where_sql}
        ORDER BY p.dcmt_patient_name ASC
        LIMIT {$per_page} OFFSET {$offset}
    ";
    $list_stmt = $dcmt_pdo->prepare($list_sql);
    $list_stmt->execute($params);
    $patients = $list_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('No upcoming appointments list error: ' . $e->getMessage());
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars(trans('patient', 'no_upcoming_appointments_help')); ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo htmlspecialchars(trans('patient', 'search_placeholder')); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="no_upcoming_appointments.php" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <h6 class="dcmt-view-card-title mb-0">
                <?php echo trans('patient', 'no_upcoming_appointments'); ?>
                <span class="ms-3 dcmt-view-card-title-total">
                    (<?php echo trans('patient', 'showing'); ?>:
                    <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                    <?php echo trans('patient', 'records'); ?>)
                </span>
            </h6>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($patients)): ?>
            <div class="text-center py-4">
                <i class="fas fa-calendar-check fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('patient', 'no_patients_without_upcoming'); ?></h5>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo trans('patient', 'patient'); ?></th>
                            <th><?php echo trans('patient', 'phone'); ?></th>
                            <th><?php echo trans('patient', 'last_appointment'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $row): ?>
                            <tr>
                                <td>
                                    <a href="view.php?id=<?php echo (int) $row['dcmt_id']; ?>">
                                        <?php echo htmlspecialchars($row['dcmt_patient_name'] ?? ''); ?>
                                    </a>
                                </td>
                                <td><?php echo htmlspecialchars($row['dcmt_phone'] ?? '-') ?: '-'; ?></td>
                                <td>
                                    <?php
                                    if (!empty($row['last_appointment_at'])) {
                                        echo dcmt_format_date($row['last_appointment_at'], DCMT_DATETIME_FORMAT);
                                    } else {
                                        echo htmlspecialchars(trans('patient', 'never_booked'));
                                    }
                                    ?>
                                </td>
                                <td>
                                    <a href="view.php?id=<?php echo (int) $row['dcmt_id']; ?>" class="btn btn-sm btn-outline-primary" title="<?php echo trans('common', 'view'); ?>">
                                        <i class="fas fa-eye"></i>
                                    </a>
                                    <?php if ($dcmt_can_book): ?>
                                        <a href="../appointments/add.php?patient_id=<?php echo (int) $row['dcmt_id']; ?>" class="btn btn-sm btn-outline-success" title="<?php echo trans('appointment', 'add_appointment'); ?>">
                                            <i class="fas fa-calendar-plus"></i>
                                        </a>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <?php echo trans('common', 'next'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
