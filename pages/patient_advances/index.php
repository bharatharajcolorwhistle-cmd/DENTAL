<?php
/**
 * Patients who currently have unused advance balances.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_advance_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_patient_advance_require_access();
dcmt_patient_advance_ensure_tables($dcmt_pdo);

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$where = ['a.dcmt_remaining_amount > 0.009'];
$params = [];
if ($search !== '') {
    $where[] = '(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$patients = [];
$total_records = 0;
$total_pages = 0;

try {
    $count_sql = "
        SELECT COUNT(*) FROM (
            SELECT p.dcmt_id
            FROM dcmt_patient_advances a
            INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
            {$where_sql}
            GROUP BY p.dcmt_id
        ) grouped_advances
    ";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = (int) ceil($total_records / $per_page);

    $list_sql = "
        SELECT
            p.dcmt_id,
            p.dcmt_patient_name,
            p.dcmt_phone,
            SUM(a.dcmt_remaining_amount) AS remaining_amount,
            MAX(a.dcmt_received_on) AS last_received_on
        FROM dcmt_patient_advances a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        {$where_sql}
        GROUP BY p.dcmt_id, p.dcmt_patient_name, p.dcmt_phone
        ORDER BY remaining_amount DESC, p.dcmt_patient_name ASC
        LIMIT {$per_page} OFFSET {$offset}
    ";
    $list_stmt = $dcmt_pdo->prepare($list_sql);
    $list_stmt->execute($params);
    $patients = $list_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('Patient advances list error: ' . $e->getMessage());
    $patients = [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars(trans('patient_advance', 'not_revenue_notice')); ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo htmlspecialchars(trans('patient_advance', 'search_placeholder')); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2">
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

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('patient_advance', 'patients_with_advance'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('patient_advance', 'showing'); ?>:
                        <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                        <?php echo trans('patient_advance', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient_advance', 'add_advance'); ?></a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($patients)): ?>
            <div class="text-center py-4">
                <i class="fas fa-hand-holding-usd fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('patient_advance', 'no_advances'); ?></h5>
                <p class="text-muted"><?php echo trans('patient_advance', 'start_adding_advance'); ?></p>
                <a href="add.php" class="btn btn-primary"><?php echo trans('patient_advance', 'add_advance'); ?></a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo trans('patient_advance', 'patient'); ?></th>
                            <th><?php echo trans('patient_advance', 'phone'); ?></th>
                            <th class="text-end"><?php echo trans('patient_advance', 'remaining_balance'); ?></th>
                            <th><?php echo trans('patient_advance', 'last_received'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['dcmt_patient_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['dcmt_phone'] ?? '-') ?: '-'; ?></td>
                                <td class="text-end"><?php echo dcmt_format_currency($row['remaining_amount'] ?? 0); ?></td>
                                <td><?php echo !empty($row['last_received_on']) ? dcmt_format_date($row['last_received_on']) : '-'; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="patient.php?patient_id=<?php echo (int) $row['dcmt_id']; ?>" class="btn"
                                            title="<?php echo trans('patient_advance', 'view_patient_advances'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="<?php echo htmlspecialchars(trans('common', 'view')); ?>">
                                        </a>
                                        <a href="add.php?patient_id=<?php echo (int) $row['dcmt_id']; ?>" class="btn"
                                            title="<?php echo trans('patient_advance', 'add_advance'); ?>">
                                            <i class="fas fa-plus text-primary"></i>
                                        </a>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('patient_advance', 'patient_advances'); ?> pagination" class="mt-3">
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
