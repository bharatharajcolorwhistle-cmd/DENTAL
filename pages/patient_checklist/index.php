<?php
/**
 * Patient Checklist Index — patients with checklists
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_checklist_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$records = [];
$total_records = 0;
$total_pages = 0;

try {
    dcmt_patient_checklist_ensure_table($dcmt_pdo);
    $result = dcmt_patient_checklist_list_patients($dcmt_pdo, [
        'search' => $search,
        'limit' => $per_page,
        'offset' => $offset,
    ]);
    $records = $result['items'];
    $total_records = $result['total'];
    $total_pages = (int) ceil($total_records / $per_page);
} catch (PDOException $e) {
    error_log('Patient checklist index error: ' . $e->getMessage());
    dcmt_show_message(trans('patient_checklist', 'database_error'), 'danger');
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/patient-checklist.css', '../../'); ?>">

<div class="dcmt-information-panel mb-4">
    <div class="dcmt-information-panel-title">
        <i class="fas fa-info-circle me-2" aria-hidden="true"></i><?php echo trans('patient_checklist', 'checklist_information'); ?>
    </div>
    <ul class="dcmt-information-panel-list small mb-0">
        <li><?php echo trans('patient_checklist', 'index_help_search'); ?></li>
        <li><?php echo trans('patient_checklist', 'index_help_actions'); ?></li>
    </ul>
</div>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('patient_checklist', 'search_placeholder'); ?>">
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

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('patient_checklist', 'patient_checklist'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('patient_checklist', 'showing'); ?>:
                        <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                        <?php echo trans('patient_checklist', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link">
                    <?php echo trans('patient_checklist', 'add_checklist'); ?>
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($records)): ?>
            <div class="text-center py-4">
                <i class="fas fa-tasks fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('patient_checklist', 'no_checklists_found'); ?></h5>
                <p class="text-muted"><?php echo trans('patient_checklist', 'start_adding_checklist'); ?></p>
            </div>
        <?php else: ?>
            <div class="dcmt-appointment-board dcmt-checklist-board">
                <?php foreach ($records as $row): ?>
                    <?php
                    $pid = (int) ($row['patient_id'] ?? 0);
                    $total = (int) ($row['total_items'] ?? 0);
                    $done = (int) ($row['completed_items'] ?? 0);
                    $pending = (int) ($row['pending_items'] ?? 0);
                    $pct = $total > 0 ? (int) round(($done / $total) * 100) : 0;
                    $all_done = $pending === 0 && $total > 0;
                    ?>
                    <div class="dcmt-appointment-row dcmt-checklist-board-row">
                        <a href="view.php?patient_id=<?php echo $pid; ?>"
                           class="dcmt-appointment-row-main-link"
                           title="<?php echo htmlspecialchars(trans('patient_checklist', 'view_checklist')); ?>">
                            <div class="dcmt-checklist-progress-box<?php echo $all_done ? ' dcmt-checklist-progress-box--done' : ''; ?>">
                                <div class="dcmt-checklist-progress-box-label"><?php echo trans('patient_checklist', 'progress'); ?></div>
                                <div class="dcmt-checklist-progress-box-value"><?php echo $done; ?>/<?php echo $total; ?></div>
                                <div class="dcmt-checklist-progress-box-sub"><?php echo $pct; ?>%</div>
                            </div>

                            <div class="dcmt-appointment-main">
                                <div class="dcmt-appointment-patient"><?php echo htmlspecialchars($row['dcmt_patient_name'] ?? ''); ?></div>
                                <?php if (!empty($row['dcmt_phone'])): ?>
                                    <div class="dcmt-appointment-doctor-line">
                                        <span><?php echo trans('patient_checklist', 'phone'); ?>: <?php echo htmlspecialchars($row['dcmt_phone']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="dcmt-appointment-tags">
                                    <span class="dcmt-tag"><?php echo trans('patient_checklist', 'total_items'); ?>: <?php echo $total; ?></span>
                                    <span class="dcmt-tag dcmt-tag--<?php echo $pending > 0 ? 'pending' : 'done'; ?>">
                                        <?php echo trans('patient_checklist', 'pending_items'); ?>: <?php echo $pending; ?>
                                    </span>
                                </div>
                            </div>
                            <span class="dcmt-appointment-row-view-icon" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                        </a>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('patient_checklist', 'patient_checklist'); ?> pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                    <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
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
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                    <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
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
