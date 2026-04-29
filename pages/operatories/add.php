<?php
/**
 * Operatories Management - Add
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
if (!(dcmt_is_admin() || in_array($current_user['dcmt_role'] ?? '', ['staff'], true))) {
    dcmt_show_message(trans('appointment', 'unauthorized'), 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
    exit();
}

$errors = [];

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
                $nextSort = (int)$dcmt_pdo->query("SELECT COALESCE(MAX(dcmt_sort_order), 0) + 1 FROM dcmt_operatories")->fetchColumn();
                $stmt = $dcmt_pdo->prepare("INSERT INTO dcmt_operatories (dcmt_name, dcmt_sort_order, dcmt_is_active) VALUES (?, ?, ?)");
                $stmt->execute([$name, $nextSort, $is_active]);
                dcmt_show_message(trans('appointment', 'operatories_saved'), 'success');
                dcmt_redirect('index.php');
                exit();
            } catch (PDOException $e) {
                error_log('Operatory add error: ' . $e->getMessage());
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('appointment', 'add_operatory'); ?></h1>
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
                        value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>"
                        placeholder="<?php echo htmlspecialchars(trans('appointment', 'operatory_name_placeholder')); ?>">
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="is_active" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="is_active" name="is_active" required>
                        <option value="1" <?php echo (!isset($_POST['is_active']) || (string)($_POST['is_active'] ?? '1') === '1') ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'active'); ?>
                        </option>
                        <option value="0" <?php echo (isset($_POST['is_active']) && (string)$_POST['is_active'] === '0') ? 'selected' : ''; ?>>
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
                <i class="fas fa-plus"></i><?php echo trans('appointment', 'add_operatory_submit'); ?>
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

