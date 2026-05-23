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

dcmt_require_admin();

dcmt_ensure_odontogram_treatments_table($dcmt_pdo);

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
                            <th><?php echo trans('odontogram_treatment', 'zone'); ?></th>
                            <th><?php echo trans('odontogram_treatment', 'tooth_state'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($treatments as $t): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($t['dcmt_name']); ?></td>
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
                                                    class="btn dcmt-btn-borderless"
                                                    title="<?php echo trans('odontogram_treatment', 'cannot_delete_system'); ?>"
                                                    disabled>
                                                <img src="../../assets/images/delete.svg" alt="<?php echo trans('common', 'delete'); ?>">
                                            </button>
                                        <?php else: ?>
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

    document.addEventListener('DOMContentLoaded', function () {
        const deleteEl = document.getElementById('deleteModal');
        if (deleteEl) {
            deleteModal = new bootstrap.Modal(deleteEl);
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
