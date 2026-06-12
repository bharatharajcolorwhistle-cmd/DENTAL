<?php
/**
 * Operatories Management - Index Page
 * Clinic-wide (shared across doctors)
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

// Admin/staff only (operatories affect the whole clinic).
$current_user = dcmt_get_current_user();
if (!(dcmt_is_admin() || in_array($current_user['dcmt_role'] ?? '', ['staff'], true))) {
    dcmt_show_message(trans('appointment', 'unauthorized'), 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
    exit();
}

$dcmt_can_delete = dcmt_can_delete_records();

require_once __DIR__ . '/../../includes/header.php';

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$where = '';
$params = [];
if ($search !== '') {
    $where = "WHERE (o.dcmt_name LIKE ?)";
    $params[] = '%' . $search . '%';
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT o.*,
               (SELECT COUNT(*) FROM dcmt_appointments a WHERE a.dcmt_operatory_id = o.dcmt_id AND a.dcmt_status <> 'cancelled') AS usage_count
        FROM dcmt_operatories o
        $where
        ORDER BY o.dcmt_sort_order ASC, o.dcmt_id ASC
    ");
    $stmt->execute($params);
    $operatories = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Operatories fetch error: ' . $e->getMessage());
    $operatories = [];
    if ($search === '') {
        dcmt_show_message(trans('appointment', 'database_error'), 'danger');
    }
}
?>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="searchForm">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                    value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="<?php echo htmlspecialchars(trans('appointment', 'operatory_name_label')); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn" id="searchBtn">
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
            <i class="fas fa-chair dcmt-view-card-title-icon"></i><?php echo trans('appointment', 'doctor_operatories'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('appointment', 'add_operatory'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($operatories)): ?>
            <div class="text-center py-4">
                <i class="fas fa-chair fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('appointment', 'operatories_empty'); ?></h5>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('appointment', 'operatory_name_label'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('common', 'usage'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($operatories as $op): ?>
                            <tr>
                                <td><?php echo htmlspecialchars((string)$op['dcmt_name']); ?></td>
                                <td>
                                    <span class="text-<?php echo ((int)$op['dcmt_is_active'] === 1) ? 'success' : 'secondary'; ?>">
                                        <?php echo ((int)$op['dcmt_is_active'] === 1) ? trans('common', 'active') : trans('common', 'inactive'); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-info"><?php echo (int)$op['usage_count']; ?></span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="edit.php?id=<?php echo (int)$op['dcmt_id']; ?>" class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ((int)$op['usage_count'] > 0): ?>
                                            <button type="button" class="btn dcmt-disabled-lock-btn" title="<?php echo trans('appointment', 'operatory_delete_blocked'); ?>" disabled>
                                                <i class="fas fa-lock text-muted"></i>
                                            </button>
                                        <?php elseif ($dcmt_can_delete): ?>
                                            <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                onclick="showDeleteModal(<?php echo (int)$op['dcmt_id']; ?>, '<?php echo htmlspecialchars((string)$op['dcmt_name'], ENT_QUOTES); ?>')">
                                                <img src="../../assets/images/delete.svg" alt="Delete">
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

<div class="modal fade" id="deleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title">
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('common', 'delete'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-0">
                    <p class="mb-0">
                        <?php echo htmlspecialchars(trans('common', 'delete')); ?>:
                        <strong id="deleteOperatoryName"></strong>
                    </p>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn"><?php echo trans('common', 'delete'); ?></button>
            </div>
        </div>
    </div>
</div>

<script>
let deleteModal;
let currentOperatoryId = null;

document.addEventListener('DOMContentLoaded', function() {
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (!currentOperatoryId) return;
        const btn = this;
        const original = btn.innerHTML;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo addslashes(trans('common', 'processing')); ?>...';
        const fd = new FormData();
        fd.append('id', String(currentOperatoryId));
        fd.append('csrf_token', '<?php echo dcmt_generate_csrf_token(); ?>');
        fetch('delete_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    alert(data.message || 'Delete failed');
                    return;
                }
                window.location.reload();
            })
            .catch(() => alert('Delete failed'))
            .finally(() => {
                btn.disabled = false;
                btn.innerHTML = original;
            });
    });
});

function showDeleteModal(id, name) {
    currentOperatoryId = id;
    const el = document.getElementById('deleteOperatoryName');
    if (el) el.textContent = name;
    if (deleteModal) deleteModal.show();
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

