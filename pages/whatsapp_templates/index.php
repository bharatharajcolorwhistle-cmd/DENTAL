<?php
/**
 * WhatsApp Templates - Index
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_require_admin_or_staff();
$dcmt_can_delete = dcmt_can_delete_records();

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$where_conditions = [];
$params = [];

if ($search !== '') {
    $where_conditions[] = '(t.dcmt_name LIKE ? OR t.dcmt_message LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}

$where_clause = $where_conditions ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT t.*
        FROM dcmt_whatsapp_templates t
        $where_clause
        ORDER BY t.dcmt_name ASC
    ");
    $stmt->execute($params);
    $templates = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('WhatsApp templates fetch error: ' . $e->getMessage());
    $templates = [];
    if ($search === '') {
        dcmt_show_message(trans('whatsapp_template', 'load_error'), 'danger');
    }
}

require_once __DIR__ . '/../../includes/header.php';
$csrf_token = dcmt_generate_csrf_token();
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('whatsapp_template', 'search_placeholder'); ?>">
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
        <h6 class="dcmt-view-card-title">
            <i class="fab fa-whatsapp text-success me-2"></i><?php echo trans('whatsapp_template', 'whatsapp_templates'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('whatsapp_template', 'add_template'); ?></a>
    </div>
    <div class="card-body">
        <p class="text-muted small mb-3">
            <?php echo trans('whatsapp_template', 'message_help'); ?>
        </p>
        <?php if (empty($templates)): ?>
            <div class="text-center py-4">
                <h5 class="text-muted"><?php echo trans('whatsapp_template', 'no_templates_found'); ?></h5>
                <p class="text-muted"><?php echo trans('whatsapp_template', 'start_adding_template'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('whatsapp_template', 'template_name'); ?></th>
                            <th><?php echo trans('whatsapp_template', 'template_message'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($templates as $template): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($template['dcmt_name']); ?></td>
                                <td class="text-muted small"><?php echo htmlspecialchars(mb_strimwidth((string)$template['dcmt_message'], 0, 120, '...')); ?></td>
                                <td>
                                    <span class="text-<?php echo $template['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo trans('common', $template['dcmt_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="edit.php?id=<?php echo (int)$template['dcmt_id']; ?>" class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($dcmt_can_delete): ?>
                                            <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="dcmtDeleteWhatsappTemplate(<?php echo (int)$template['dcmt_id']; ?>, <?php echo json_encode($template['dcmt_name']); ?>)">
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

<script>
function dcmtDeleteWhatsappTemplate(id, name) {
    if (!confirm('<?php echo addslashes(trans('common', 'confirm_deletion')); ?>\n' + name)) {
        return;
    }
    const formData = new FormData();
    formData.append('id', String(id));
    formData.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
    fetch('delete_ajax.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                window.location.reload();
            } else {
                alert(data.message || '<?php echo addslashes(trans('whatsapp_template', 'database_error')); ?>');
            }
        })
        .catch(() => alert('<?php echo addslashes(trans('whatsapp_template', 'database_error')); ?>'));
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
