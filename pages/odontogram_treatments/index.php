<?php
/**
 * Odontogram Treatments — Index
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

$problem_states = dcmt_get_odontogram_problem_states($dcmt_pdo);
$treatments_in_use = dcmt_odontogram_treatments_in_use_map($dcmt_pdo);
$problem_states_in_use = dcmt_odontogram_problem_states_in_use_map($dcmt_pdo);
$problem_state_labels = [
    'default' => trans('patient', 'odontogram_state_default'),
    'damaged' => trans('patient', 'odontogram_state_damaged'),
    'filling' => trans('patient', 'odontogram_state_filling'),
    'missing' => trans('patient', 'odontogram_state_missing'),
    'crown' => trans('patient', 'odontogram_state_crown'),
    'implant' => trans('patient', 'odontogram_state_implant'),
];

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

$zone_labels = [
    'anterior' => trans('odontogram_treatment', 'zone_anterior'),
    'posterior' => trans('odontogram_treatment', 'zone_posterior'),
    'both' => trans('odontogram_treatment', 'zone_both'),
];
$csrf_token = dcmt_generate_csrf_token();
?>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="searchForm">
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
                <a href="index.php" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card dcmt-records-table mb-4">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <i class="fas fa-palette dcmt-view-card-title-icon"></i><?php echo trans('odontogram_treatment', 'odontogram_problems'); ?>
        </h6>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3"><?php echo trans('odontogram_treatment', 'odontogram_problems_help'); ?></p>
        <div class="row g-2">
            <?php foreach ($problem_states as $ps):
                $state_key = (string) ($ps['dcmt_state_key'] ?? '');
                $state_color = htmlspecialchars((string) ($ps['dcmt_color'] ?? '#6C757D'));
                $state_label = $problem_state_labels[$state_key] ?? $state_key;
                $state_in_use = isset($problem_states_in_use[$state_key]);
                ?>
                <div class="col-sm-6 col-md-4 col-lg-3">
                    <div class="d-flex align-items-center gap-2 border rounded px-2 py-2 dcmt-od-problem-state-item"
                         data-state-key="<?php echo htmlspecialchars($state_key); ?>"
                         data-color="<?php echo $state_color; ?>"
                         data-in-use="<?php echo $state_in_use ? '1' : '0'; ?>">
                        <span class="dcmt-odontogram-legend-swatch flex-shrink-0"
                              data-legend="<?php echo htmlspecialchars($state_key); ?>"
                              style="background: <?php echo $state_color; ?>;"></span>
                        <span class="flex-grow-1 small fw-medium"><?php echo htmlspecialchars($state_label); ?></span>
                        <?php if ($state_in_use): ?>
                            <span class="text-muted" title="<?php echo htmlspecialchars(trans('odontogram_treatment', 'color_locked_in_use')); ?>">
                                <i class="fas fa-lock" aria-hidden="true"></i>
                            </span>
                        <?php else: ?>
                            <button type="button"
                                    class="btn btn-sm p-0 border-0 dcmt-od-problem-state-edit-btn"
                                    title="<?php echo trans('odontogram_treatment', 'edit_color'); ?>"
                                    data-state-key="<?php echo htmlspecialchars($state_key); ?>"
                                    data-state-label="<?php echo htmlspecialchars($state_label, ENT_QUOTES, 'UTF-8'); ?>"
                                    data-color="<?php echo $state_color; ?>">
                                <img src="../../assets/images/edit.svg" alt="<?php echo trans('common', 'edit'); ?>" width="18" height="18">
                            </button>
                        <?php endif; ?>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>
</div>

<div class="card dcmt-records-table">
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
                            <th><?php echo trans('odontogram_treatment', 'zone'); ?></th>
                            <th><?php echo trans('odontogram_treatment', 'tooth_state'); ?></th>
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
                                <td><?php echo htmlspecialchars($zone_labels[$t['dcmt_zone']] ?? $t['dcmt_zone']); ?></td>
                                <td><?php
                                    if (!empty($t['dcmt_tooth_state'])) {
                                        echo htmlspecialchars(trans('patient', 'odontogram_state_' . $t['dcmt_tooth_state']));
                                    } else {
                                        echo trans('odontogram_treatment', 'tooth_state_any');
                                    }
                                    ?></td>
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

<div class="modal fade" id="problemStateColorModal" tabindex="-1" aria-labelledby="problemStateColorModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="problemStateColorModalLabel"><?php echo trans('odontogram_treatment', 'edit_problem_state_color'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo trans('common', 'cancel'); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><strong id="problemStateLabel"></strong></p>
                <label for="problemStateColorInput" class="form-label"><?php echo trans('odontogram_treatment', 'color'); ?></label>
                <div class="d-flex align-items-center gap-3">
                    <input type="color" class="form-control form-control-color" id="problemStateColorInput" value="#F1F3F5">
                    <span class="text-muted small" id="problemStateColorHex"></span>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                <button type="button" class="btn btn-primary" id="confirmProblemStateColorBtn"><?php echo trans('odontogram_treatment', 'save_color'); ?></button>
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
    let deleteModal = null;
    let problemStateModal = null;
    let problemStateKey = null;
    let problemStateItemEl = null;

    document.addEventListener('DOMContentLoaded', function () {
        const deleteEl = document.getElementById('deleteModal');
        if (deleteEl) {
            deleteModal = new bootstrap.Modal(deleteEl);
        }

        const problemStateEl = document.getElementById('problemStateColorModal');
        const colorInput = document.getElementById('problemStateColorInput');
        const colorHex = document.getElementById('problemStateColorHex');
        const confirmColorBtn = document.getElementById('confirmProblemStateColorBtn');

        if (problemStateEl) {
            problemStateModal = new bootstrap.Modal(problemStateEl);
        }

        function syncColorHex() {
            if (colorInput && colorHex) {
                colorHex.textContent = (colorInput.value || '').toUpperCase();
            }
        }

        if (colorInput) {
            colorInput.addEventListener('input', syncColorHex);
        }

        document.querySelectorAll('.dcmt-od-problem-state-edit-btn').forEach(function (btn) {
            btn.addEventListener('click', function (e) {
                e.preventDefault();
                problemStateKey = btn.getAttribute('data-state-key') || null;
                const label = btn.getAttribute('data-state-label') || '';
                const color = btn.getAttribute('data-color') || '#6C757D';
                problemStateItemEl = btn.closest('.dcmt-od-problem-state-item');
                document.getElementById('problemStateLabel').textContent = label;
                if (colorInput) {
                    colorInput.value = color;
                    syncColorHex();
                }
                if (problemStateModal) {
                    problemStateModal.show();
                }
            });
        });

        if (confirmColorBtn) {
            confirmColorBtn.addEventListener('click', function () {
                if (!problemStateKey || !colorInput) {
                    return;
                }
                const fd = new FormData();
                fd.append('state_key', problemStateKey);
                fd.append('color', colorInput.value);
                fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
                confirmColorBtn.disabled = true;
                fetch('update_problem_state_ajax.php', { method: 'POST', body: fd })
                    .then(function (r) { return r.json(); })
                    .then(function (data) {
                        if (data.success && problemStateItemEl) {
                            const newColor = data.color || colorInput.value;
                            problemStateItemEl.setAttribute('data-color', newColor);
                            const swatch = problemStateItemEl.querySelector('.dcmt-odontogram-legend-swatch');
                            if (swatch) {
                                swatch.style.background = newColor;
                            }
                            const editBtn = problemStateItemEl.querySelector('.dcmt-od-problem-state-edit-btn');
                            if (editBtn) {
                                editBtn.setAttribute('data-color', newColor);
                            }
                            if (problemStateModal) {
                                problemStateModal.hide();
                            }
                        } else {
                            alert(data.message || 'Error');
                        }
                        confirmColorBtn.disabled = false;
                    })
                    .catch(function () {
                        alert('Error');
                        confirmColorBtn.disabled = false;
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
                            window.location.reload();
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
