<?php
/**
 * Edit Patient Checklist Item
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_checklist_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$item_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($item_id <= 0) {
    dcmt_show_message(trans('patient_checklist', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$errors = [];
$item = null;

try {
    dcmt_patient_checklist_ensure_table($dcmt_pdo);
    $item = dcmt_patient_checklist_get($dcmt_pdo, $item_id);
    if (!$item) {
        dcmt_show_message(trans('patient_checklist', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log('Patient checklist edit load: ' . $e->getMessage());
    dcmt_show_message(trans('patient_checklist', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$form_data = [
    'title' => $item['dcmt_title'] ?? '',
    'description' => $item['dcmt_description'] ?? '',
];
$patient_id = (int) ($item['dcmt_patient_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient_checklist', 'invalid_token');
    } else {
        $form_data['title'] = trim((string) ($_POST['title'] ?? ''));
        $form_data['description'] = trim((string) ($_POST['description'] ?? ''));

        $result = dcmt_patient_checklist_update($dcmt_pdo, $item_id, $form_data, $dcmt_current_user);
        if ($result['success']) {
            dcmt_log_activity('Patient checklist item updated', 'Item ID: ' . $item_id);
            dcmt_show_message($result['message'], 'success');
            dcmt_redirect('view.php?patient_id=' . $patient_id);
            exit();
        }
        $errors[] = $result['message'] ?? trans('patient_checklist', 'database_error');
    }
}

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('patient_checklist', 'edit_item'); ?></h1>
            <a href="view.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link">
                <?php echo trans('patient_checklist', 'view_checklist'); ?>
            </a>
        </div>
    </div>

    <form method="POST" action="" id="dcmtChecklistEditForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label"><?php echo trans('patient_checklist', 'patient'); ?></label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($item['dcmt_patient_name'] ?? '-'); ?>" disabled>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="title" class="form-label"><?php echo trans('patient_checklist', 'title'); ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="title" name="title" maxlength="255" required
                   value="<?php echo htmlspecialchars($form_data['title']); ?>"
                   placeholder="<?php echo trans('patient_checklist', 'title_placeholder'); ?>">
        </div>

        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('patient_checklist', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="4"
                      placeholder="<?php echo trans('patient_checklist', 'description_placeholder'); ?>"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
            <div class="form-text"><?php echo trans('patient_checklist', 'description_help'); ?></div>
        </div>

        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="dcmtResetChecklistEditBtn">
                <i class="fas fa-undo"></i><?php echo trans('common', 'reset'); ?>
            </button>
            <a href="view.php?patient_id=<?php echo $patient_id; ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('patient_checklist', 'update_item_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dcmtChecklistEditForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('dcmtResetChecklistEditBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
            submitBtn.disabled = true;
        });
    }

    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (!confirm(<?php echo json_encode(trans('patient_checklist', 'confirm_reset')); ?>)) return;
            form.reset();
            if (submitBtn) {
                submitBtn.disabled = false;
                submitBtn.innerHTML = '<i class="fas fa-save"></i><?php echo htmlspecialchars(trans('patient_checklist', 'update_item_record'), ENT_QUOTES); ?>';
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
