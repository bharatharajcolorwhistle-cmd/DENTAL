<?php
/**
 * Odontogram configuration — Problems & Treatments
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/odontogram_treatments.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_require_admin_or_staff();
$dcmt_can_delete = dcmt_can_delete_records();

dcmt_ensure_odontogram_treatments_table($dcmt_pdo);

$active_tab = (isset($_GET['tab']) && $_GET['tab'] === 'treatments') ? 'treatments' : 'problems';

$problem_search = isset($_GET['problem_search']) ? dcmt_sanitize_input($_GET['problem_search']) : '';
$problem_where = [];
$problem_params = [];
if ($problem_search !== '') {
    $problem_where[] = '(dcmt_name LIKE ? OR dcmt_description LIKE ? OR dcmt_state_key LIKE ?)';
    $problem_params[] = '%' . $problem_search . '%';
    $problem_params[] = '%' . $problem_search . '%';
    $problem_params[] = '%' . $problem_search . '%';
}
$problem_where_clause = $problem_where ? 'WHERE ' . implode(' AND ', $problem_where) : '';

try {
    $problem_sql = "SELECT * FROM dcmt_odontogram_problem_states $problem_where_clause ORDER BY dcmt_sort_order ASC, dcmt_name ASC";
    $problem_stmt = $dcmt_pdo->prepare($problem_sql);
    $problem_stmt->execute($problem_params);
    $problem_states = $problem_stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Odontogram problems fetch: ' . $e->getMessage());
    $problem_states = [];
}

$treatments_in_use = dcmt_odontogram_treatments_in_use_map($dcmt_pdo);
$problem_states_in_use = dcmt_odontogram_problem_states_in_use_map($dcmt_pdo);

require_once __DIR__ . '/../../includes/header.php';

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$where_conditions = [];
$params = [];

if ($search !== '') {
    $where_conditions[] = '(dcmt_name LIKE ? OR dcmt_description LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $sql = "SELECT * FROM dcmt_odontogram_treatments $where_clause ORDER BY dcmt_name ASC";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $treatments = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log('Odontogram treatments fetch: ' . $e->getMessage());
    $treatments = [];
    if ($search === '') {
        dcmt_show_message(trans('odontogram_treatment', 'load_error'), 'danger');
    }
}

$csrf_token = dcmt_generate_csrf_token();
$problems_tab_active = $active_tab === 'problems';
$treatments_tab_active = $active_tab === 'treatments';
?>

<link rel="stylesheet" href="../../assets/css/odontogram.css">

<nav class="dcmt-odontogram-tab-section mb-4" aria-label="<?php echo htmlspecialchars(trans('odontogram_treatment', 'odontogram_configuration')); ?>">
    <ul class="dcmt-odontogram-tab-list" id="dcmtOdontogramConfigTabs" role="tablist">
        <li class="dcmt-odontogram-tab-item" role="presentation">
            <a class="dcmt-odontogram-tab-link dcmt-odontogram-tab-link--problem<?php echo $problems_tab_active ? ' active' : ''; ?>"
               id="dcmt-od-config-tab-problems"
               href="index.php?tab=problems"
               role="tab"
               aria-controls="dcmt-od-config-pane-problems"
               aria-selected="<?php echo $problems_tab_active ? 'true' : 'false'; ?>">
                <i class="fas fa-exclamation-circle dcmt-odontogram-tab-icon"></i>
                <?php echo htmlspecialchars(trans('odontogram_treatment', 'odontogram_problems')); ?>
            </a>
        </li>
        <li class="dcmt-odontogram-tab-item" role="presentation">
            <a class="dcmt-odontogram-tab-link dcmt-odontogram-tab-link--solution<?php echo $treatments_tab_active ? ' active' : ''; ?>"
               id="dcmt-od-config-tab-treatments"
               href="index.php?tab=treatments"
               role="tab"
               aria-controls="dcmt-od-config-pane-treatments"
               aria-selected="<?php echo $treatments_tab_active ? 'true' : 'false'; ?>">
                <i class="fas fa-check-circle dcmt-odontogram-tab-icon"></i>
                <?php echo htmlspecialchars(trans('odontogram_treatment', 'odontogram_treatments')); ?>
            </a>
        </li>
    </ul>
</nav>

<div class="tab-content dcmt-odontogram-tab-content" id="dcmtOdontogramConfigTabContent">
    <div class="tab-pane fade dcmt-odontogram-tab-pane<?php echo $problems_tab_active ? ' show active' : ''; ?>"
         id="dcmt-od-config-pane-problems"
         role="tabpanel"
         aria-labelledby="dcmt-od-config-tab-problems">
        <div class="card mb-4 dcmt-filter-form">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end" id="problemSearchForm">
                    <input type="hidden" name="tab" value="problems">
                    <div class="col-md-4">
                        <label for="problem_search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                        <input type="text" class="form-control dcmt-filter-field" id="problem_search" name="problem_search"
                               value="<?php echo htmlspecialchars($problem_search); ?>"
                               placeholder="<?php echo trans('odontogram_treatment', 'problem_search_placeholder'); ?>">
                    </div>
                    <div class="col-md-auto d-flex flex-column gap-2">
                        <button type="submit" class="dcmt-filter-btn">
                            <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                        </button>
                        <a href="index.php?tab=problems" class="dcmt-add-form-view-all-link text-center">
                            <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card dcmt-records-table mb-0">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-palette dcmt-view-card-title-icon"></i><?php echo trans('odontogram_treatment', 'odontogram_problems'); ?>
                </h6>
                <a href="add_problem.php" class="dcmt-add-form-view-all-link"><?php echo trans('odontogram_treatment', 'add_problem'); ?></a>
            </div>
            <div class="card-body">
                <p class="text-muted small mb-3"><?php echo trans('odontogram_treatment', 'odontogram_problems_help'); ?></p>
                <?php if (empty($problem_states)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-palette fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted"><?php echo trans('odontogram_treatment', 'no_problems_found'); ?></h5>
                        <p class="text-muted"><?php echo trans('odontogram_treatment', 'start_adding_problem'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo trans('odontogram_treatment', 'problem_name'); ?></th>
                                    <th><?php echo trans('odontogram_treatment', 'color'); ?></th>
                                    <th><?php echo trans('odontogram_treatment', 'whole_tooth'); ?></th>
                                    <th><?php echo trans('common', 'status'); ?></th>
                                    <th><?php echo trans('common', 'actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($problem_states as $ps):
                                    $state_key = (string) ($ps['dcmt_state_key'] ?? '');
                                    $state_color = dcmt_sanitize_odontogram_hex_color((string) ($ps['dcmt_color'] ?? '#6C757D'));
                                    $state_label = dcmt_odontogram_problem_display_name($ps);
                                    $state_in_use = isset($problem_states_in_use[$state_key]);
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($state_label); ?></td>
                                        <td>
                                            <span class="dcmt-odontogram-legend-swatch d-inline-block"
                                                  style="background: <?php echo htmlspecialchars($state_color); ?>;"
                                                  title="<?php echo htmlspecialchars($state_in_use ? trans('odontogram_treatment', 'color_locked_in_use') : $state_color); ?>"></span>
                                        </td>
                                        <td><?php echo !empty($ps['dcmt_whole_tooth']) ? trans('common', 'yes') : trans('common', 'no'); ?></td>
                                        <td>
                                            <span class="text-<?php echo ($ps['dcmt_status'] ?? 'active') === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo trans('common', $ps['dcmt_status'] ?? 'active'); ?>
                                            </span>
                                        </td>
                                        <td class="dcmt-table-actions-cell">
                                            <div class="btn-group btn-group-sm btn-group-action" role="group">
                                                <a href="edit_problem.php?id=<?php echo (int) $ps['dcmt_id']; ?>"
                                                   class="btn"
                                                   title="<?php echo trans('common', 'edit'); ?>">
                                                    <img src="../../assets/images/edit.svg" alt="<?php echo trans('common', 'edit'); ?>">
                                                </a>
                                                <?php if (($ps['dcmt_created_by'] ?? '') === 'system'): ?>
                                                    <button type="button"
                                                            class="btn dcmt-disabled-lock-btn"
                                                            title="<?php echo trans('odontogram_treatment', 'cannot_delete_system_problem'); ?>"
                                                            disabled>
                                                        <i class="fas fa-lock text-muted"></i>
                                                    </button>
                                                <?php elseif ($state_in_use): ?>
                                                    <button type="button"
                                                            class="btn dcmt-disabled-lock-btn"
                                                            title="<?php echo trans('odontogram_treatment', 'cannot_delete_problem_in_use'); ?>"
                                                            disabled>
                                                        <i class="fas fa-lock text-muted"></i>
                                                    </button>
                                                <?php elseif ($dcmt_can_delete): ?>
                                                    <button type="button"
                                                            class="btn dcmt-odontogram-problem-delete-btn"
                                                            title="<?php echo trans('common', 'delete'); ?>"
                                                            data-id="<?php echo (int) $ps['dcmt_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($state_label, ENT_QUOTES, 'UTF-8'); ?>">
                                                        <img src="../../assets/images/delete.svg" alt="<?php echo trans('common', 'delete'); ?>">
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="tab-pane fade dcmt-odontogram-tab-pane<?php echo $treatments_tab_active ? ' show active' : ''; ?>"
         id="dcmt-od-config-pane-treatments"
         role="tabpanel"
         aria-labelledby="dcmt-od-config-tab-treatments">
        <div class="card mb-4 dcmt-filter-form">
            <div class="card-body">
                <form method="GET" class="row g-3 align-items-end" id="searchForm">
                    <input type="hidden" name="tab" value="treatments">
                    <div class="col-md-4">
                        <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                        <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                               value="<?php echo htmlspecialchars($search); ?>"
                               placeholder="<?php echo trans('odontogram_treatment', 'search_placeholder'); ?>">
                    </div>
                    <div class="col-md-auto d-flex flex-column gap-2">
                        <button type="submit" class="dcmt-filter-btn">
                            <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                        </button>
                        <a href="index.php?tab=treatments" class="dcmt-add-form-view-all-link text-center">
                            <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                        </a>
                    </div>
                </form>
            </div>
        </div>

        <div class="card dcmt-records-table mb-0">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-tooth dcmt-view-card-title-icon"></i><?php echo trans('odontogram_treatment', 'odontogram_treatments'); ?>
                </h6>
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('odontogram_treatment', 'add_treatment'); ?></a>
            </div>
            <div class="card-body">
                <?php if (empty($treatments)): ?>
                    <div class="text-center py-4">
                        <i class="fas fa-tooth fa-3x text-muted mb-3"></i>
                        <h5 class="text-muted"><?php echo trans('odontogram_treatment', 'no_treatments_found'); ?></h5>
                        <p class="text-muted"><?php echo trans('odontogram_treatment', 'start_adding_treatment'); ?></p>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <th><?php echo trans('odontogram_treatment', 'treatment_name'); ?></th>
                                    <th><?php echo trans('odontogram_treatment', 'color'); ?></th>
                                    <th><?php echo trans('common', 'status'); ?></th>
                                    <th><?php echo trans('common', 'actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($treatments as $t):
                                    $treatment_in_use = isset($treatments_in_use[$t['dcmt_name']]);
                                    ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($t['dcmt_name']); ?></td>
                                        <td>
                                            <?php
                                            $t_color = !empty($t['dcmt_color'])
                                                ? dcmt_sanitize_odontogram_hex_color((string) $t['dcmt_color'])
                                                : dcmt_odontogram_default_treatment_color();
                                            ?>
                                            <span class="dcmt-odontogram-legend-swatch d-inline-block"
                                                  style="background: <?php echo htmlspecialchars($t_color); ?>;"
                                                  title="<?php echo htmlspecialchars($treatment_in_use ? trans('odontogram_treatment', 'color_locked_in_use') : $t_color); ?>"></span>
                                        </td>
                                        <td>
                                            <span class="text-<?php echo $t['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo trans('common', $t['dcmt_status']); ?>
                                            </span>
                                        </td>
                                        <td class="dcmt-table-actions-cell">
                                            <div class="btn-group btn-group-sm btn-group-action" role="group">
                                                <a href="edit.php?id=<?php echo (int) $t['dcmt_id']; ?>"
                                                   class="btn"
                                                   title="<?php echo trans('common', 'edit'); ?>">
                                                    <img src="../../assets/images/edit.svg" alt="<?php echo trans('common', 'edit'); ?>">
                                                </a>
                                                <?php if ($t['dcmt_created_by'] === 'system'): ?>
                                                    <button type="button"
                                                            class="btn dcmt-disabled-lock-btn"
                                                            title="<?php echo trans('odontogram_treatment', 'cannot_delete_system'); ?>"
                                                            disabled>
                                                        <i class="fas fa-lock text-muted"></i>
                                                    </button>
                                                <?php elseif ($treatment_in_use): ?>
                                                    <button type="button"
                                                            class="btn dcmt-disabled-lock-btn"
                                                            title="<?php echo trans('odontogram_treatment', 'cannot_delete_in_use'); ?>"
                                                            disabled>
                                                        <i class="fas fa-lock text-muted"></i>
                                                    </button>
                                                <?php elseif ($dcmt_can_delete): ?>
                                                    <button type="button"
                                                            class="btn dcmt-odontogram-treatment-delete-btn"
                                                            title="<?php echo trans('common', 'delete'); ?>"
                                                            data-id="<?php echo (int) $t['dcmt_id']; ?>"
                                                            data-name="<?php echo htmlspecialchars($t['dcmt_name'], ENT_QUOTES, 'UTF-8'); ?>">
                                                        <img src="../../assets/images/delete.svg" alt="<?php echo trans('common', 'delete'); ?>">
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteProblemModal" tabindex="-1" aria-labelledby="deleteProblemModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteProblemModalLabel"><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('odontogram_treatment', 'delete_problem'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo trans('common', 'cancel'); ?>"></button>
            </div>
            <div class="modal-body">
                <p><?php echo trans('odontogram_treatment', 'delete_problem_confirmation_message'); ?></p>
                <p><strong><?php echo trans('odontogram_treatment', 'problem_name'); ?>:</strong> <span id="problemName"></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="confirmDeleteProblemBtn"><?php echo trans('common', 'delete'); ?></button>
            </div>
        </div>
    </div>
</div>

<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel"><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('odontogram_treatment', 'delete_treatment'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="<?php echo trans('common', 'cancel'); ?>"></button>
            </div>
            <div class="modal-body">
                <p><?php echo trans('odontogram_treatment', 'delete_confirmation_message'); ?></p>
                <p><strong><?php echo trans('odontogram_treatment', 'treatment_name'); ?>:</strong> <span id="treatmentName"></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><?php echo trans('common', 'delete'); ?></button>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

<script>
(function () {
    let deleteItemId = null;
    let deleteProblemItemId = null;
    let deleteModal = null;
    let deleteProblemModal = null;

    document.addEventListener('DOMContentLoaded', function () {
        const deleteEl = document.getElementById('deleteModal');
        if (deleteEl) {
            deleteModal = new bootstrap.Modal(deleteEl);
        }

        const deleteProblemEl = document.getElementById('deleteProblemModal');
        if (deleteProblemEl) {
            deleteProblemModal = new bootstrap.Modal(deleteProblemEl);
        }

        document.querySelectorAll('.dcmt-odontogram-problem-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                deleteProblemItemId = parseInt(btn.getAttribute('data-id'), 10) || null;
                document.getElementById('problemName').textContent = btn.getAttribute('data-name') || '';
                if (deleteProblemModal) {
                    deleteProblemModal.show();
                }
            });
        });

        const confirmProblemBtn = document.getElementById('confirmDeleteProblemBtn');
        if (confirmProblemBtn) {
            confirmProblemBtn.addEventListener('click', function () {
                if (!deleteProblemItemId) {
                    return;
                }
                const fd = new FormData();
                fd.append('id', deleteProblemItemId);
                fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
                confirmProblemBtn.disabled = true;
                fetch('delete_problem_ajax.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            window.location.href = 'index.php?tab=problems';
                        } else {
                            alert(data.message || 'Error');
                            confirmProblemBtn.disabled = false;
                        }
                    })
                    .catch(function () {
                        alert('Error');
                        confirmProblemBtn.disabled = false;
                    });
            });
        }

        document.querySelectorAll('.dcmt-odontogram-treatment-delete-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                e.stopPropagation();
                deleteItemId = parseInt(btn.getAttribute('data-id'), 10) || null;
                document.getElementById('treatmentName').textContent = btn.getAttribute('data-name') || '';
                if (deleteModal) {
                    deleteModal.show();
                }
            });
        });

        const confirmBtn = document.getElementById('confirmDeleteBtn');
        if (confirmBtn) {
            confirmBtn.addEventListener('click', function () {
                if (!deleteItemId) {
                    return;
                }
                const fd = new FormData();
                fd.append('id', deleteItemId);
                fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
                confirmBtn.disabled = true;
                fetch('delete_ajax.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success) {
                            window.location.href = 'index.php?tab=treatments';
                        } else {
                            alert(data.message || 'Error');
                            confirmBtn.disabled = false;
                        }
                    })
                    .catch(function () {
                        alert('Error');
                        confirmBtn.disabled = false;
                    });
            });
        }
    });
})();
</script>
