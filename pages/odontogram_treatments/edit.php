<?php
/**
 * Odontogram clinical treatments — Edit
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

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$allowed_states = dcmt_odontogram_treatment_allowed_tooth_states();

if ($id <= 0) {
    dcmt_show_message(trans('odontogram_treatment', 'invalid_treatment_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$stmt = $dcmt_pdo->prepare('SELECT * FROM dcmt_odontogram_treatments WHERE dcmt_id = ?');
$stmt->execute([$id]);
$treatment = $stmt->fetch();

if (!$treatment) {
    dcmt_show_message(trans('odontogram_treatment', 'category_not_found'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('odontogram_treatment', 'invalid_token');
    } else {
        $name = dcmt_sanitize_input($_POST['name'] ?? '');
        $description = dcmt_sanitize_input($_POST['description'] ?? '');
        $zone = dcmt_sanitize_input($_POST['zone'] ?? 'both');
        $tooth_state = dcmt_sanitize_input($_POST['tooth_state'] ?? '');
        $status = dcmt_sanitize_input($_POST['status'] ?? 'active');
        if ($name === '') {
            $errors[] = trans('odontogram_treatment', 'name_required');
        }
        if (!in_array($zone, ['anterior', 'posterior', 'both'], true)) {
            $errors[] = trans('odontogram_treatment', 'invalid_zone');
        }
        if ($tooth_state !== '' && !isset($allowed_states[$tooth_state])) {
            $errors[] = trans('odontogram_treatment', 'invalid_tooth_state');
        }

        if (empty($errors)) {
            $check = $dcmt_pdo->prepare('SELECT COUNT(*) FROM dcmt_odontogram_treatments WHERE dcmt_name = ? AND dcmt_id != ?');
            $check->execute([$name, $id]);
            if ((int) $check->fetchColumn() > 0) {
                $errors[] = trans('odontogram_treatment', 'name_exists');
            } else {
                $upd = $dcmt_pdo->prepare("
                    UPDATE dcmt_odontogram_treatments SET
                        dcmt_name = ?, dcmt_description = ?, dcmt_zone = ?, dcmt_tooth_state = ?,
                        dcmt_status = ?
                    WHERE dcmt_id = ?
                ");
                $upd->execute([
                    $name, $description, $zone,
                    $tooth_state !== '' ? $tooth_state : null,
                    $status, $id,
                ]);
                dcmt_show_message(trans('odontogram_treatment', 'update_success'), 'success');
                dcmt_redirect('index.php');
            }
        }
    }
    $stmt->execute([$id]);
    $treatment = $stmt->fetch();
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('odontogram_treatment', 'edit_treatment'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('odontogram_treatment', 'view_all_treatments'); ?></a>
        </div>
    </div>
    <form method="POST" action="edit.php?id=<?php echo (int) $id; ?>" id="dcmtOdontogramTreatmentEditForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="name" class="form-label"><?php echo trans('odontogram_treatment', 'treatment_name'); ?> <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required maxlength="100"
                       value="<?php echo htmlspecialchars($treatment['dcmt_name']); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label for="zone" class="form-label"><?php echo trans('odontogram_treatment', 'zone'); ?></label>
                <select class="form-select" id="zone" name="zone">
                    <?php foreach (['both', 'anterior', 'posterior'] as $z): ?>
                        <option value="<?php echo $z; ?>" <?php echo $treatment['dcmt_zone'] === $z ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'zone_' . $z); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="tooth_state" class="form-label"><?php echo trans('odontogram_treatment', 'tooth_state'); ?></label>
                <select class="form-select" id="tooth_state" name="tooth_state">
                    <option value=""><?php echo trans('odontogram_treatment', 'tooth_state_any'); ?></option>
                    <?php foreach (array_keys($allowed_states) as $sk): ?>
                        <option value="<?php echo $sk; ?>" <?php echo ($treatment['dcmt_tooth_state'] ?? '') === $sk ? 'selected' : ''; ?>><?php echo htmlspecialchars(trans('patient', 'odontogram_state_' . $sk)); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-6 mb-3">
                <label for="status" class="form-label"><?php echo trans('odontogram_treatment', 'status'); ?></label>
                <select class="form-select" id="status" name="status">
                    <option value="active" <?php echo $treatment['dcmt_status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'active'); ?></option>
                    <option value="inactive" <?php echo $treatment['dcmt_status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'inactive'); ?></option>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($treatment['dcmt_description'] ?? ''); ?></textarea>
        </div>
        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel"><?php echo trans('common', 'cancel'); ?></a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('odontogram_treatment', 'update_treatment_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dcmtOdontogramTreatmentEditForm');
    const submitBtn = document.getElementById('submitBtn');
    if (!form || !submitBtn) return;

    form.addEventListener('submit', function() {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
        submitBtn.setAttribute('data-original-text', originalText);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
