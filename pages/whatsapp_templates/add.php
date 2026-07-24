<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_require_admin_or_staff();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('whatsapp_template', 'invalid_token');
    } else {
        $name = dcmt_sanitize_input($_POST['name'] ?? '');
        $message = trim((string)($_POST['message'] ?? ''));
        $status = dcmt_sanitize_input($_POST['status'] ?? 'active');

        if ($name === '') {
            $errors[] = trans('whatsapp_template', 'name_required');
        } elseif (strlen($name) > 100) {
            $errors[] = trans('whatsapp_template', 'name_too_long');
        }
        if ($message === '') {
            $errors[] = trans('whatsapp_template', 'message_required');
        } elseif (strlen($message) > 4000) {
            $errors[] = trans('whatsapp_template', 'message_too_long');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = trans('whatsapp_template', 'invalid_status');
        }

        if (empty($errors)) {
            try {
                $check = $dcmt_pdo->prepare('SELECT dcmt_id FROM dcmt_whatsapp_templates WHERE dcmt_name = ?');
                $check->execute([$name]);
                if ($check->fetch()) {
                    $errors[] = trans('whatsapp_template', 'name_exists');
                } else {
                    $stmt = $dcmt_pdo->prepare('
                        INSERT INTO dcmt_whatsapp_templates (dcmt_name, dcmt_message, dcmt_status, dcmt_created_by)
                        VALUES (?, ?, ?, ?)
                    ');
                    $stmt->execute([$name, $message, $status, dcmt_get_current_user()['dcmt_username'] ?? 'system']);
                    dcmt_show_message(trans('whatsapp_template', 'add_success'), 'success');
                    dcmt_redirect('index.php');
                }
            } catch (PDOException $e) {
                $errors[] = trans('whatsapp_template', 'database_error');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $error): ?><li><?php echo htmlspecialchars($error); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('whatsapp_template', 'add_template'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('whatsapp_template', 'view_all_templates'); ?></a>
        </div>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="name" class="form-label"><?php echo trans('whatsapp_template', 'template_name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" maxlength="100" required
                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                           placeholder="<?php echo trans('whatsapp_template', 'enter_name'); ?>">
                    <div class="form-text"><?php echo trans('whatsapp_template', 'name_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="status" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="status" name="status" required>
                        <option value="active" <?php echo ($_POST['status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                        <option value="inactive" <?php echo ($_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                    </select>
                    <div class="form-text"><?php echo trans('whatsapp_template', 'status_help'); ?></div>
                </div>
            </div>
        </div>
        <div class="mb-3">
            <label for="message" class="form-label"><?php echo trans('whatsapp_template', 'template_message'); ?> <span class="text-danger">*</span></label>
            <textarea class="form-control" id="message" name="message" rows="5" maxlength="4000" required
                      placeholder="<?php echo trans('whatsapp_template', 'enter_message'); ?>"><?php echo htmlspecialchars($_POST['message'] ?? ''); ?></textarea>
            <div class="form-text"><?php echo trans('whatsapp_template', 'message_help'); ?></div>
        </div>
        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel"><i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?></a>
            <button type="submit" class="btn dcmt-btn-submit"><i class="fas fa-plus"></i><?php echo trans('whatsapp_template', 'add_template_record'); ?></button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
