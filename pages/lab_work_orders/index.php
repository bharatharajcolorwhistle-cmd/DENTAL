<?php
/**
 * Lab Work Orders - Index
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
$role = $user['dcmt_role'] ?? '';
if (!in_array($role, ['admin', 'doctor'], true) && !dcmt_is_admin()) {
    dcmt_show_message('Access denied.', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$lab_id = isset($_GET['lab_id']) ? (int) $_GET['lab_id'] : 0;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(w.dcmt_folio_number LIKE ? OR w.dcmt_patient_name LIKE ? OR w.dcmt_doctor_name LIKE ? OR c.dcmt_lab_organization LIKE ? OR c.dcmt_name LIKE ? OR w.dcmt_prosthesis_type_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($lab_id > 0) {
    $where[] = 'w.dcmt_lab_connection_id = ?';
    $params[] = $lab_id;
}
$where_clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT w.*, c.dcmt_name AS lab_connection_name, c.dcmt_lab_remote_name, c.dcmt_lab_organization
        FROM dcmt_lab_work_orders w
        INNER JOIN dcmt_lab_connections c ON c.dcmt_id = w.dcmt_lab_connection_id
        $where_clause
        ORDER BY w.dcmt_created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Lab work orders fetch error: ' . $e->getMessage());
    $orders = [];
}

$labs = dcmt_lab_get_active_connections($dcmt_pdo);
try {
    $all_labs_stmt = $dcmt_pdo->query('SELECT dcmt_id, dcmt_name, dcmt_lab_organization, dcmt_lab_remote_name FROM dcmt_lab_connections ORDER BY COALESCE(NULLIF(TRIM(dcmt_lab_organization), \'\'), dcmt_name)');
    $all_labs = $all_labs_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $all_labs = $labs;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('lab', 'work_order_search_placeholder'); ?>">
            </div>
            <div class="col-md-3">
                <label for="lab_id" class="form-label"><?php echo trans('lab', 'select_lab'); ?></label>
                <select class="form-select dcmt-filter-field" id="lab_id" name="lab_id">
                    <option value=""><?php echo trans('common', 'all') ?: 'All'; ?></option>
                    <?php foreach ($all_labs as $lab): ?>
                        <option value="<?php echo (int) $lab['dcmt_id']; ?>" <?php echo $lab_id === (int) $lab['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(dcmt_lab_connection_display_name($lab)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
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
            <i class="fas fa-clipboard-list dcmt-view-card-title-icon"></i><?php echo trans('lab', 'lab_work_orders'); ?>
        </h6>
        <a href="add.php<?php echo $lab_id ? ('?lab_id=' . $lab_id) : ''; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'add_work_order'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
            <div class="text-center py-4">
                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('lab', 'no_work_orders_found'); ?></h5>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('lab', 'folio_number'); ?></th>
                            <th><?php echo trans('lab', 'select_lab'); ?></th>
                            <th><?php echo trans('lab', 'patient_name'); ?></th>
                            <th><?php echo trans('lab', 'doctor_name'); ?></th>
                            <th><?php echo trans('lab', 'prosthesis_type'); ?></th>
                            <th><?php echo trans('lab', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($order['dcmt_folio_number'] ?: '—'); ?></strong></td>
                                <td><?php echo htmlspecialchars(dcmt_lab_connection_display_name($order)); ?></td>
                                <td><?php echo htmlspecialchars($order['dcmt_patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['dcmt_doctor_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['dcmt_prosthesis_type_name'] ?: $order['dcmt_prosthesis_type_id']); ?></td>
                                <td><?php echo dcmt_format_date($order['dcmt_created_at'], DCMT_DATETIME_FORMAT); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo (int) $order['dcmt_id']; ?>" class="btn"
                                           title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
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

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
