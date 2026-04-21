<?php
/**
 * Operatories Management - Edit
 */
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$current_user = dcmt_get_current_user();
if (!in_array($current_user['dcmt_role'] ?? '', ['admin', 'staff'], true)) {
    dcmt_show_message(trans('appointment', 'unauthorized'), 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
    exit();
}

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) {
    dcmt_show_message(trans('appointment', 'invalid_request'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$errors = [];
try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_operatories WHERE dcmt_id = ? LIMIT 1");
    $stmt->execute([$id]);
    $op = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$op) {
        dcmt_show_message(trans('appointment', 'invalid_request'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log('Operatory load error: ' . $e->getMessage());
    dcmt_show_message(trans('appointment', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        $name = trim(dcmt_sanitize_input($_POST['name'] ?? ''));
        $is_active = ((string)($_POST['is_active'] ?? '1') === '1') ? 1 : 0;
        if ($name === '') {
            $errors[] = trans('appointment', 'operatory_name_required');
        } elseif (strlen($name) > 120) {
            $errors[] = trans('appointment', 'invalid_request');
        }

        if (empty($errors)) {
            try {
                $stmt = $dcmt_pdo->prepare("UPDATE dcmt_operatories SET dcmt_name = ?, dcmt_is_active = ? WHERE dcmt_id = ?");
                $stmt->execute([$name, $is_active, $id]);
                dcmt_show_message(trans('appointment', 'operatories_saved'), 'success');
                dcmt_redirect('index.php');
                exit();
            } catch (PDOException $e) {
                error_log('Operatory update error: ' . $e->getMessage());
                $errors[] = trans('appointment', 'database_error');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('common', 'edit'); ?> <?php echo trans('appointment', 'operatory'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('common', 'back'); ?></a>
        </div>
    </div>

    <form method="POST" action="" id="operatoryForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="name" class="form-label"><?php echo trans('appointment', 'operatory_name_label'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" maxlength="120" required
                        value="<?php echo htmlspecialchars((string)($op['dcmt_name'] ?? '')); ?>"
                        placeholder="<?php echo htmlspecialchars(trans('appointment', 'operatory_name_placeholder')); ?>">
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="is_active" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="is_active" name="is_active" required>
                        <option value="1" <?php echo ((int)($op['dcmt_is_active'] ?? 1) === 1) ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'active'); ?>
                        </option>
                        <option value="0" <?php echo ((int)($op['dcmt_is_active'] ?? 1) === 0) ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'inactive'); ?>
                        </option>
                    </select>
                </div>
            </div>
        </div>

        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('appointment', 'update_operatory_submit'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('operatoryForm');
    const submitBtn = document.getElementById('submitBtn');
    if (!form || !submitBtn) return;

    form.addEventListener('submit', function() {
        if (typeof form.checkValidity === 'function' && !form.checkValidity()) {
            return;
        }
        const originalText = submitBtn.innerHTML;
        submitBtn.setAttribute('data-original-text', originalText);
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
    });

    const originalText = submitBtn.getAttribute('data-original-text');
    if (originalText) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

