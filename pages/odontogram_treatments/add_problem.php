<?php
/**
 * Odontogram problems — Add
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

$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('odontogram_treatment', 'invalid_token');
    } else {
        $name = dcmt_sanitize_input($_POST['name'] ?? '');
        $description = dcmt_sanitize_input($_POST['description'] ?? '');
        $color = dcmt_sanitize_odontogram_hex_color(
            dcmt_sanitize_input($_POST['color'] ?? ''),
            '#6C757D'
        );
        $whole_tooth = !empty($_POST['whole_tooth']);
        $status = dcmt_sanitize_input($_POST['status'] ?? 'active');

        if ($name === '') {
            $errors[] = trans('odontogram_treatment', 'problem_name_required');
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $errors[] = trans('odontogram_treatment', 'invalid_status');
        }

        if (empty($errors)) {
            try {
                $state_key = dcmt_odontogram_unique_problem_state_key($dcmt_pdo, $name);
                if ($state_key === 'default') {
                    $errors[] = trans('odontogram_treatment', 'problem_key_reserved');
                } else {
                    $stroke = dcmt_odontogram_derive_stroke_color($color);
                    $sort = (int) $dcmt_pdo->query('SELECT COALESCE(MAX(dcmt_sort_order), 0) + 1 FROM dcmt_odontogram_problem_states')->fetchColumn();
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_odontogram_problem_states
                            (dcmt_state_key, dcmt_name, dcmt_description, dcmt_color, dcmt_stroke_color,
                             dcmt_whole_tooth, dcmt_status, dcmt_sort_order, dcmt_created_by)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $state_key,
                        $name,
                        $description,
                        $color,
                        $stroke,
                        $whole_tooth ? 1 : 0,
                        $status,
                        $sort,
                        dcmt_get_current_user()['dcmt_username'] ?? 'admin',
                    ]);
                    dcmt_log_activity('Odontogram problem added: ' . $name, 'odontogram_problem_added');
                    dcmt_show_message(trans('odontogram_treatment', 'problem_add_success'), 'success');
                    dcmt_redirect('index.php?tab=problems');
                }
            } catch (PDOException $e) {
                $errors[] = trans('odontogram_treatment', 'database_error');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
$form_color = dcmt_sanitize_odontogram_hex_color(
    dcmt_sanitize_input($_POST['color'] ?? ''),
    '#6C757D'
);
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger"><ul class="mb-0"><?php foreach ($errors as $e): ?><li><?php echo htmlspecialchars($e); ?></li><?php endforeach; ?></ul></div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('odontogram_treatment', 'add_problem'); ?></h1>
            <a href="index.php?tab=problems" class="dcmt-add-form-view-all-link"><?php echo trans('odontogram_treatment', 'view_all_problems'); ?></a>
        </div>
    </div>
    <form method="POST" id="dcmtOdontogramProblemForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="name" class="form-label"><?php echo trans('odontogram_treatment', 'problem_name'); ?> <span class="text-danger">*</span></label>
                <input type="text" class="form-control" id="name" name="name" required maxlength="100"
                       value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>"
                       placeholder="<?php echo trans('odontogram_treatment', 'problem_name_example'); ?>">
                <div class="form-text"><?php echo trans('odontogram_treatment', 'problem_name_help'); ?></div>
            </div>
            <div class="col-md-6 mb-3">
                <label for="status" class="form-label"><?php echo trans('odontogram_treatment', 'status'); ?></label>
                <select class="form-select" id="status" name="status">
                    <option value="active" selected><?php echo trans('odontogram_treatment', 'active'); ?></option>
                    <option value="inactive" <?php echo ($_POST['status'] ?? '') === 'inactive' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'inactive'); ?></option>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="color" class="form-label"><?php echo trans('odontogram_treatment', 'color'); ?></label>
                <div class="d-flex align-items-center gap-3">
                    <input type="color" class="form-control form-control-color" id="color" name="color"
                           value="<?php echo htmlspecialchars($form_color); ?>">
                    <span class="text-muted small" id="colorHexPreview"><?php echo htmlspecialchars($form_color); ?></span>
                </div>
                <div class="form-text"><?php echo trans('odontogram_treatment', 'problem_color_help'); ?></div>
            </div>
            <div class="col-md-6 mb-3 d-flex flex-column justify-content-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="whole_tooth" name="whole_tooth" value="1"
                        <?php echo !empty($_POST['whole_tooth']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="whole_tooth"><?php echo trans('odontogram_treatment', 'whole_tooth'); ?></label>
                </div>
            </div>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
        </div>
        <div class="dcmt-form-actions">
            <a href="index.php?tab=problems" class="btn dcmt-btn-cancel"><i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?></a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('odontogram_treatment', 'add_problem_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const colorInput = document.getElementById('color');
    const colorHex = document.getElementById('colorHexPreview');
    if (colorInput && colorHex) {
        colorInput.addEventListener('input', function() {
            colorHex.textContent = (colorInput.value || '').toUpperCase();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
