<?php
/**
 * Odontogram clinical treatments — Add
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

$errors = [];
$allowed_states = dcmt_odontogram_treatment_allowed_tooth_states();

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
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = trans('odontogram_treatment', 'invalid_status');
        }
        if ($tooth_state !== '' && !isset($allowed_states[$tooth_state])) {
            $errors[] = trans('odontogram_treatment', 'invalid_tooth_state');
        }

        if (empty($errors)) {
            try {
                $check = $dcmt_pdo->prepare('SELECT COUNT(*) FROM dcmt_odontogram_treatments WHERE dcmt_name = ?');
                $check->execute([$name]);
                if ((int) $check->fetchColumn() > 0) {
                    $errors[] = trans('odontogram_treatment', 'name_exists');
                } else {
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_odontogram_treatments
                            (dcmt_name, dcmt_description, dcmt_zone, dcmt_tooth_state, dcmt_status, dcmt_created_by)
                        VALUES (?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $name,
                        $description,
                        $zone,
                        $tooth_state !== '' ? $tooth_state : null,
                        $status,
                        dcmt_get_current_user()['dcmt_username'] ?? 'admin',
                    ]);
                    dcmt_log_activity('Odontogram treatment added: ' . $name, 'odontogram_treatment_added');
                    dcmt_show_message(trans('odontogram_treatment', 'add_success'), 'success');
                    dcmt_redirect('index.php');
                }
            } catch (PDOException $e) {
                $errors[] = trans('odontogram_treatment', 'database_error');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('odontogram_treatment', 'add_treatment'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('odontogram_treatment', 'view_all_treatments'); ?></a>
        </div>
    </div>
    <form method="POST">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="name" class="form-label"><?php echo trans('odontogram_treatment', 'treatment_name'); ?> <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required maxlength="100"
                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                       placeholder="<?php echo trans('odontogram_treatment', 'name_example'); ?>">
                <div class="form-text"><?php echo trans('odontogram_treatment', 'name_help'); ?></div>
            </div>
            <div class="col-md-6 mb-3">
                <label for="zone" class="form-label"><?php echo trans('odontogram_treatment', 'zone'); ?> <span class="text-danger">*</span></label>
                <select class="form-select" id="zone" name="zone" required>
                    <option value="both"><?php echo trans('odontogram_treatment', 'zone_both'); ?></option>
                    <option value="anterior" <?php echo ($_POST['zone'] ?? '') === 'anterior' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'zone_anterior'); ?></option>
                    <option value="posterior" <?php echo ($_POST['zone'] ?? '') === 'posterior' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'zone_posterior'); ?></option>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="tooth_state" class="form-label"><?php echo trans('odontogram_treatment', 'tooth_state'); ?></label>
                <select class="form-select" id="tooth_state" name="tooth_state">
                    <option value=""><?php echo trans('odontogram_treatment', 'tooth_state_any'); ?></option>
                    <?php foreach (array_keys($allowed_states) as $sk): ?>
                        <option value="<?php echo $sk; ?>" <?php echo ($_POST['tooth_state'] ?? '') === $sk ? 'selected' : ''; ?>><?php echo htmlspecialchars(trans('patient', 'odontogram_state_' . $sk)); ?></option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?php echo trans('odontogram_treatment', 'tooth_state_help'); ?></div>
            </div>
            <div class="col-md-6 mb-3">
                <label for="status" class="form-label"><?php echo trans('odontogram_treatment', 'status'); ?></label>
                <select class="form-select" id="status" name="status">
                    <option value="active" selected><?php echo trans('odontogram_treatment', 'active'); ?></option>
                    <option value="inactive" <?php echo ($_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'inactive'); ?></option>
                </select>
            </div>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
        </div>
        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel"><i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?></a>
            <button type="submit" class="btn dcmt-btn-submit"><i class="fas fa-plus"></i><?php echo trans('odontogram_treatment', 'add_treatment_record'); ?></button>
        </div>
    </form>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
