<?php
/**
 * Odontogram problems — Edit
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

dcmt_require_admin_or_staff();
dcmt_ensure_odontogram_problem_states_table($dcmt_pdo);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];

if ($id <= 0) {
    dcmt_show_message(trans('odontogram_treatment', 'invalid_problem_id'), 'danger');
    dcmt_redirect('index.php?tab=problems');
    exit();
}

$stmt = $dcmt_pdo->prepare('SELECT * FROM dcmt_odontogram_problem_states WHERE dcmt_id = ?');
$stmt->execute([$id]);
$problem = $stmt->fetch();

if (!$problem) {
    dcmt_show_message(trans('odontogram_treatment', 'problem_not_found'), 'danger');
    dcmt_redirect('index.php?tab=problems');
    exit();
}

$state_key = (string) ($problem['dcmt_state_key'] ?? '');
$is_system = ($problem['dcmt_created_by'] ?? '') === 'system';
$color_locked = dcmt_odontogram_problem_state_is_in_use($dcmt_pdo, $state_key);
$locked_color = dcmt_sanitize_odontogram_hex_color((string) ($problem['dcmt_color'] ?? '#6C757D'));

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('odontogram_treatment', 'invalid_token');
    } else {
        $name = dcmt_sanitize_input($_POST['name'] ?? '');
        $description = dcmt_sanitize_input($_POST['description'] ?? '');
        $color = dcmt_sanitize_odontogram_hex_color(
            dcmt_sanitize_input($_POST['color'] ?? ''),
            $locked_color
        );
        $whole_tooth = !empty($_POST['whole_tooth']);
        $status = dcmt_sanitize_input($_POST['status'] ?? 'active');

        if ($name === '') {
            $errors[] = trans('odontogram_treatment', 'problem_name_required');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = trans('odontogram_treatment', 'invalid_status');
        }
        if ($state_key === 'default' && $status !== 'active') {
            $errors[] = trans('odontogram_treatment', 'default_problem_must_be_active');
        }
        if ($color_locked) {
            $submitted_color = dcmt_sanitize_odontogram_hex_color($color, $locked_color);
            if ($submitted_color !== $locked_color) {
                $errors[] = trans('odontogram_treatment', 'color_locked_in_use');
            }
            $color = $locked_color;
        }

        if (empty($errors)) {
            $stroke = dcmt_odontogram_derive_stroke_color($color);
            $upd = $dcmt_pdo->prepare("
                UPDATE dcmt_odontogram_problem_states SET
                    dcmt_name = ?, dcmt_description = ?, dcmt_color = ?, dcmt_stroke_color = ?,
                    dcmt_whole_tooth = ?, dcmt_status = ?
                WHERE dcmt_id = ?
            ");
            $upd->execute([
                $name,
                $description,
                $color,
                $stroke,
                $whole_tooth ? 1 : 0,
                $status,
                $id,
            ]);
            dcmt_show_message(trans('odontogram_treatment', 'problem_update_success'), 'success');
            dcmt_redirect('index.php?tab=problems');
        }
    }
    $stmt->execute([$id]);
    $problem = $stmt->fetch();
}

$csrf_token = dcmt_generate_csrf_token();
$problem_color = $locked_color;
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('odontogram_treatment', 'edit_problem'); ?></h1>
            <a href="index.php?tab=problems" class="dcmt-add-form-view-all-link"><?php echo trans('odontogram_treatment', 'view_all_problems'); ?></a>
        </div>
    </div>
    <form method="POST" action="edit_problem.php?id=<?php echo (int) $id; ?>" id="dcmtOdontogramProblemEditForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="id" value="<?php echo (int) $id; ?>">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="name" class="form-label"><?php echo trans('odontogram_treatment', 'problem_name'); ?> <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required maxlength="100"
                       value="<?php echo htmlspecialchars(dcmt_odontogram_problem_display_name($problem)); ?>">
            </div>
            <div class="col-md-6 mb-3">
                <label class="form-label"><?php echo trans('odontogram_treatment', 'problem_key'); ?></label>
                <input type="text" class="form-control" value="<?php echo htmlspecialchars($state_key); ?>" readonly disabled>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="status" class="form-label"><?php echo trans('odontogram_treatment', 'status'); ?></label>
                <select class="form-select" id="status" name="status" <?php echo $state_key === 'default' ? 'disabled' : ''; ?>>
                    <option value="active" <?php echo ($problem['dcmt_status'] ?? 'active') === 'active' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'active'); ?></option>
                    <option value="inactive" <?php echo ($problem['dcmt_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'inactive'); ?></option>
                </select>
                <?php if ($state_key === 'default'): ?>
                    <input type="hidden" name="status" value="active">
                <?php endif; ?>
            </div>
            <div class="col-md-6 mb-3">
                <label for="color" class="form-label">
                    <?php echo trans('odontogram_treatment', 'color'); ?>
                    <?php if ($color_locked): ?>
                        <i class="fas fa-lock text-muted ms-1" title="<?php echo htmlspecialchars(trans('odontogram_treatment', 'color_locked_in_use')); ?>"></i>
                    <?php endif; ?>
                </label>
                <div class="d-flex align-items-center gap-3">
                    <input type="color" class="form-control form-control-color" id="color"
                           value="<?php echo htmlspecialchars($problem_color); ?>"
                           <?php echo $color_locked ? 'disabled' : 'name="color"'; ?>>
                    <span class="text-muted small" id="colorHexPreview"><?php echo htmlspecialchars($problem_color); ?></span>
                </div>
                <?php if ($color_locked): ?>
                    <input type="hidden" name="color" value="<?php echo htmlspecialchars($problem_color); ?>">
                    <div class="form-text text-warning">
                        <i class="fas fa-lock me-1"></i><?php echo trans('odontogram_treatment', 'color_locked_in_use'); ?>
                    </div>
                <?php else: ?>
                    <div class="form-text"><?php echo trans('odontogram_treatment', 'problem_color_help'); ?></div>
                <?php endif; ?>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3 d-flex flex-column justify-content-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="whole_tooth" name="whole_tooth" value="1"
                        <?php echo !empty($problem['dcmt_whole_tooth']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="whole_tooth"><?php echo trans('odontogram_treatment', 'whole_tooth'); ?></label>
                </div>
            </div>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($problem['dcmt_description'] ?? ''); ?></textarea>
        </div>
        <div class="dcmt-form-actions">
            <a href="index.php?tab=problems" class="btn dcmt-btn-cancel"><?php echo trans('common', 'cancel'); ?></a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('odontogram_treatment', 'update_problem_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const colorInput = document.getElementById('color');
    const colorHex = document.getElementById('colorHexPreview');
    if (colorInput && colorHex && !colorInput.disabled) {
        colorInput.addEventListener('input', function() {
            colorHex.textContent = (colorInput.value || '').toUpperCase();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
