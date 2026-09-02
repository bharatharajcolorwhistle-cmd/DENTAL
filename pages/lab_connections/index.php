<?php
/**
 * Lab Connections - Index
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$user = dcmt_get_current_user();
if (!dcmt_can_access_lab($user)) {
    dcmt_show_message('Access denied.', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
    exit();
}
$dcmt_can_delete = dcmt_can_delete_lab($user);

$load_error = '';
$connections = [];

try {
    dcmt_ensure_lab_tables($dcmt_pdo);
} catch (PDOException $e) {
    error_log('Lab tables ensure error: ' . $e->getMessage());
    $detail = '';
    if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
        $detail = ': ' . $e->getMessage();
    }
    $load_error = trans('lab', 'load_error') . ' (schema)' . $detail;
}

$csrf_token = dcmt_generate_csrf_token();
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

$where_conditions = [];
$params = [];
if ($search !== '') {
    $where_conditions[] = "(c.dcmt_lab_organization LIKE ? OR c.dcmt_name LIKE ? OR c.dcmt_lab_remote_name LIKE ? OR c.dcmt_clinic_url LIKE ? OR c.dcmt_lab_base_url LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like, $like];
}
$where_clause = $where_conditions ? ('WHERE ' . implode(' AND ', $where_conditions)) : '';

if ($load_error === '') {
    try {
        // Subquery avoids ONLY_FULL_GROUP_BY issues with SELECT c.*
        $sql = "
            SELECT c.*,
                   (
                       SELECT COUNT(*)
                       FROM dcmt_lab_work_orders w
                       WHERE w.dcmt_lab_connection_id = c.dcmt_id
                   ) AS work_order_count
            FROM dcmt_lab_connections c
            $where_clause
            ORDER BY COALESCE(NULLIF(TRIM(c.dcmt_lab_organization), ''), c.dcmt_name)
        ";
        $stmt = $dcmt_pdo->prepare($sql);
        $stmt->execute($params);
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($connections)) {
            $connections = [];
        }
    } catch (PDOException $e) {
        error_log('Lab connections fetch error: ' . $e->getMessage());
        $connections = [];
        $detail = '';
        if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
            $detail = ': ' . $e->getMessage();
        }
        $load_error = trans('lab', 'load_error') . $detail;
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<meta name="csrf-token" content="<?php echo $csrf_token; ?>">

<?php if ($load_error !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($load_error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('lab', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="?" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <i class="fas fa-flask dcmt-view-card-title-icon"></i><?php echo trans('lab', 'lab_connections'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'add_connection'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($connections)): ?>
            <div class="text-center py-4">
                <i class="fas fa-flask fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('lab', 'no_connections_found'); ?></h5>
                <p class="text-muted"><?php echo trans('lab', 'try_adjusting_search'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('lab', 'lab_remote_name'); ?></th>
                            <th><?php echo trans('lab', 'lab_base_url'); ?></th>
                            <th><?php echo trans('lab', 'work_orders_count'); ?></th>
                            <th><?php echo trans('lab', 'connection_status'); ?></th>
                            <th><?php echo trans('lab', 'last_synced_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($connections as $connection): ?>
                            <tr>
                                <td><?php echo htmlspecialchars(dcmt_lab_connection_display_name($connection)); ?></td>
                                <td><small><?php echo htmlspecialchars($connection['dcmt_lab_base_url']); ?></small></td>
                                <td><?php echo (int) $connection['work_order_count']; ?></td>
                                <td>
                                    <?php $status_safe = ($connection['dcmt_status'] ?? '') === 'active' ? 'active' : 'inactive'; ?>
                                    <span class="text-<?php echo $status_safe === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo trans('common', $status_safe); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($connection['dcmt_last_synced_at'])): ?>
                                        <?php echo dcmt_format_date($connection['dcmt_last_synced_at'], DCMT_DATETIME_FORMAT); ?>
                                    <?php else: ?>
                                        <span class="text-muted"><?php echo trans('lab', 'never_synced'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="edit.php?id=<?php echo (int) $connection['dcmt_id']; ?>"
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($dcmt_can_delete): ?>
                                            <?php if ((int) $connection['work_order_count'] > 0): ?>
                                                <button type="button" class="btn dcmt-btn-borderless" disabled
                                                        title="<?php echo trans('lab', 'cannot_delete_with_orders'); ?>">
                                                    <i class="fas fa-lock text-muted"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                        onclick="confirmDelete(<?php echo (int) $connection['dcmt_id']; ?>, 'lab_connection')">
                                                    <img src="../../assets/images/delete.svg" alt="Delete">
                                                </button>
                                            <?php endif; ?>
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

<script>
window.translations = {
    confirm_deletion: '<?php echo addslashes(trans('common', 'confirm_deletion')); ?>',
    warning: '<?php echo addslashes(trans('common', 'warning')); ?>',
    delete_confirmation_message: '<?php echo addslashes(trans('lab', 'confirm_delete')); ?>',
    cancel: '<?php echo addslashes(trans('common', 'cancel')); ?>',
    yes_delete: '<?php echo addslashes(trans('common', 'yes_delete')); ?>',
    lab_connection: '<?php echo addslashes(trans('lab', 'lab_connections')); ?>'
};
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
