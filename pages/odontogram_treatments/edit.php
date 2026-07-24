<?php
/**
 * Odontogram clinical treatments — Edit
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/odontogram_treatments.php';
require_once __DIR__ . '/../../includes/patient_treatment_plan.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_require_admin_or_staff();
dcmt_ensure_odontogram_treatments_table($dcmt_pdo);
dcmt_ensure_odontogram_treatment_doctor_column($dcmt_pdo);
dcmt_ensure_odontogram_treatment_show_in_plan_column($dcmt_pdo);

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$errors = [];
$doctors = dcmt_fetch_active_doctors($dcmt_pdo);

if ($id <= 0) {
    dcmt_show_message(trans('odontogram_treatment', 'invalid_treatment_id'), 'danger');
    dcmt_redirect('index.php?tab=treatments');
    exit();
}

$stmt = $dcmt_pdo->prepare('SELECT * FROM dcmt_odontogram_treatments WHERE dcmt_id = ?');
$stmt->execute([$id]);
$treatment = $stmt->fetch();

if (!$treatment) {
    dcmt_show_message(trans('odontogram_treatment', 'category_not_found'), 'danger');
    dcmt_redirect('index.php?tab=treatments');
    exit();
}

$original_treatment_name = (string) $treatment['dcmt_name'];
$color_locked = dcmt_odontogram_treatment_is_in_use($dcmt_pdo, $original_treatment_name);
$locked_treatment_color = !empty($treatment['dcmt_color'])
    ? dcmt_sanitize_odontogram_hex_color((string) $treatment['dcmt_color'])
    : dcmt_odontogram_default_treatment_color();

$form_doctor_id = isset($_POST['doctor_id'])
    ? (int) $_POST['doctor_id']
    : (int) ($treatment['dcmt_doctor_user_id'] ?? 0);
$form_service_id = isset($_POST['service_id'])
    ? (int) $_POST['service_id']
    : (int) ($treatment['dcmt_service_id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('odontogram_treatment', 'invalid_token');
    } else {
        $name = dcmt_sanitize_input($_POST['name'] ?? '');
        $description = dcmt_sanitize_input($_POST['description'] ?? '');
        $color = dcmt_sanitize_odontogram_hex_color(
            dcmt_sanitize_input($_POST['color'] ?? ''),
            dcmt_odontogram_default_treatment_color()
        );
        $status = dcmt_sanitize_input($_POST['status'] ?? 'active');
        $whole_tooth = !empty($_POST['whole_tooth']);
        $show_in_treatment_plan = !empty($_POST['show_in_treatment_plan']);
        $form_doctor_id = isset($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : 0;
        $form_service_id = isset($_POST['service_id']) ? (int) $_POST['service_id'] : 0;
        if ($name === '') {
            $errors[] = trans('odontogram_treatment', 'name_required');
        }
        if ($form_doctor_id < 0) {
            $form_doctor_id = 0;
        }
        if ($form_service_id < 0) {
            $form_service_id = 0;
        }
        if ($form_service_id > 0 && $form_doctor_id <= 0) {
            $form_service_id = 0;
        }
        if ($color_locked) {
            $submitted_color = dcmt_sanitize_odontogram_hex_color($color, $locked_treatment_color);
            if ($submitted_color !== $locked_treatment_color) {
                $errors[] = trans('odontogram_treatment', 'color_locked_in_use');
            }
            $color = $locked_treatment_color;
        }

        if (empty($errors)) {
            $check = $dcmt_pdo->prepare('SELECT COUNT(*) FROM dcmt_odontogram_treatments WHERE dcmt_name = ? AND dcmt_id != ?');
            $check->execute([$name, $id]);
            if ((int) $check->fetchColumn() > 0) {
                $errors[] = trans('odontogram_treatment', 'name_exists');
            } else {
                $upd = $dcmt_pdo->prepare("
                    UPDATE dcmt_odontogram_treatments SET
                        dcmt_name = ?, dcmt_description = ?,
                        dcmt_color = ?, dcmt_service_id = ?, dcmt_doctor_user_id = ?,
                        dcmt_whole_tooth = ?, dcmt_show_in_treatment_plan = ?, dcmt_status = ?
                    WHERE dcmt_id = ?
                ");
                $upd->execute([
                    $name,
                    $description,
                    $color,
                    $form_service_id > 0 ? $form_service_id : null,
                    $form_doctor_id > 0 ? $form_doctor_id : null,
                    $whole_tooth ? 1 : 0,
                    $show_in_treatment_plan ? 1 : 0,
                    $status,
                    $id,
                ]);
                dcmt_show_message(trans('odontogram_treatment', 'update_success'), 'success');
                dcmt_redirect('index.php?tab=treatments');
            }
        }
    }
    $stmt->execute([$id]);
    $treatment = $stmt->fetch();
}

$csrf_token = dcmt_generate_csrf_token();
$treatment_color = $locked_treatment_color;
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('odontogram_treatment', 'edit_treatment'); ?></h1>
            <a href="index.php?tab=treatments" class="dcmt-add-form-view-all-link"><?php echo trans('odontogram_treatment', 'view_all_treatments'); ?></a>
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
                <label for="status" class="form-label"><?php echo trans('odontogram_treatment', 'status'); ?></label>
                <select class="form-select" id="status" name="status">
                    <option value="active" <?php echo $treatment['dcmt_status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'active'); ?></option>
                    <option value="inactive" <?php echo $treatment['dcmt_status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('odontogram_treatment', 'inactive'); ?></option>
                </select>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="doctor_id" class="form-label"><?php echo trans('odontogram_treatment', 'default_doctor'); ?></label>
                <select class="form-select" id="doctor_id" name="doctor_id">
                    <option value=""><?php echo htmlspecialchars(trans('odontogram_treatment', 'select_doctor')); ?></option>
                    <?php foreach ($doctors as $doctor): ?>
                        <option value="<?php echo (int) $doctor['dcmt_id']; ?>" <?php echo $form_doctor_id === (int) $doctor['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doctor['dcmt_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?php echo trans('odontogram_treatment', 'default_doctor_help'); ?></div>
            </div>
            <div class="col-md-6 mb-3">
                <label for="service_id" class="form-label"><?php echo trans('odontogram_treatment', 'default_service'); ?></label>
                <select class="form-select" id="service_id" name="service_id" <?php echo $form_doctor_id > 0 ? '' : 'disabled'; ?>
                        data-selected-service="<?php echo (int) $form_service_id; ?>">
                    <option value=""><?php echo htmlspecialchars(trans('odontogram_treatment', 'select_service')); ?></option>
                </select>
                <div class="form-text"><?php echo trans('odontogram_treatment', 'default_service_help'); ?></div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <label for="color" class="form-label">
                    <?php echo trans('odontogram_treatment', 'color'); ?>
                    <?php if ($color_locked): ?>
                        <i class="fas fa-lock text-muted ms-1" title="<?php echo htmlspecialchars(trans('odontogram_treatment', 'color_locked_in_use')); ?>"></i>
                    <?php endif; ?>
                </label>
                <div class="d-flex align-items-center gap-3">
                    <input type="color" class="form-control form-control-color" id="color"
                           value="<?php echo htmlspecialchars($treatment_color); ?>"
                           <?php echo $color_locked ? 'disabled' : 'name="color"'; ?>>
                    <span class="text-muted small" id="colorHexPreview"><?php echo htmlspecialchars($treatment_color); ?></span>
                </div>
                <?php if ($color_locked): ?>
                    <input type="hidden" name="color" value="<?php echo htmlspecialchars($treatment_color); ?>">
                    <div class="form-text text-warning">
                        <i class="fas fa-lock me-1"></i><?php echo trans('odontogram_treatment', 'color_locked_in_use'); ?>
                    </div>
                <?php else: ?>
                    <div class="form-text"><?php echo trans('odontogram_treatment', 'color_help'); ?></div>
                <?php endif; ?>
            </div>
            <div class="col-md-6 mb-3 d-flex align-items-end">
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="whole_tooth" name="whole_tooth" value="1"
                        <?php echo !empty($treatment['dcmt_whole_tooth']) ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="whole_tooth"><?php echo trans('odontogram_treatment', 'whole_tooth'); ?></label>
                    <div class="form-text"><?php echo trans('odontogram_treatment', 'whole_tooth_help'); ?></div>
                </div>
            </div>
        </div>
        <div class="row">
            <div class="col-md-6 mb-3">
                <?php
                $show_in_plan_checked = ($_SERVER['REQUEST_METHOD'] === 'POST')
                    ? !empty($_POST['show_in_treatment_plan'])
                    : ((int) ($treatment['dcmt_show_in_treatment_plan'] ?? 1) === 1);
                ?>
                <div class="form-check">
                    <input class="form-check-input" type="checkbox" id="show_in_treatment_plan" name="show_in_treatment_plan" value="1"
                        <?php echo $show_in_plan_checked ? 'checked' : ''; ?>>
                    <label class="form-check-label" for="show_in_treatment_plan"><?php echo trans('odontogram_treatment', 'show_in_treatment_plan'); ?></label>
                    <div class="form-text"><?php echo trans('odontogram_treatment', 'show_in_treatment_plan_help'); ?></div>
                </div>
            </div>
        </div>
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"><?php echo htmlspecialchars($treatment['dcmt_description'] ?? ''); ?></textarea>
        </div>
        <div class="dcmt-form-actions">
            <a href="index.php?tab=treatments" class="btn dcmt-btn-cancel"><?php echo trans('common', 'cancel'); ?></a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('odontogram_treatment', 'update_treatment_record'); ?>
            </button>
        </div>
    </form>
</div>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dcmtOdontogramTreatmentEditForm');
    const submitBtn = document.getElementById('submitBtn');
    const colorInput = document.getElementById('color');
    const colorHex = document.getElementById('colorHexPreview');
    const doctorSelect = document.getElementById('doctor_id');
    const serviceSelect = document.getElementById('service_id');
    const selectDoctorText = <?php echo json_encode(trans('odontogram_treatment', 'select_doctor')); ?>;
    const selectServiceText = <?php echo json_encode(trans('odontogram_treatment', 'select_service')); ?>;
    const loadingServicesText = <?php echo json_encode(trans('odontogram_treatment', 'loading_services')); ?>;
    if (!form || !submitBtn) return;

    if (colorInput && colorHex) {
        colorInput.addEventListener('input', function() {
            colorHex.textContent = (colorInput.value || '').toUpperCase();
        });
    }

    function ensureSelect2(select, placeholder) {
        if (typeof $ === 'undefined' || !select) {
            return;
        }
        const $select = $(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.off('select2:open.dcmtFocusSearch');
            $select.select2('destroy');
        }
        $select.select2({
            placeholder: placeholder,
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0,
            dropdownParent: $(document.body)
        });
        $select.on('select2:open.dcmtFocusSearch', function() {
            window.setTimeout(function() {
                const search = document.querySelector('.select2-container--open .select2-search__field');
                if (search) {
                    search.focus();
                }
            }, 0);
        });
    }

    function destroySelect2(select) {
        if (typeof $ === 'undefined' || !select) return;
        const $select = $(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.off('select2:open.dcmtFocusSearch');
            $select.select2('destroy');
        }
    }

    function clearServiceSelect() {
        if (!serviceSelect) return;
        destroySelect2(serviceSelect);
        serviceSelect.innerHTML = '<option value="">' + selectServiceText + '</option>';
        serviceSelect.value = '';
        serviceSelect.disabled = true;
        ensureSelect2(serviceSelect, selectServiceText);
    }

    function loadServicesForDoctor(doctorId, selectedServiceId) {
        if (!serviceSelect) return Promise.resolve();
        if (!doctorId) {
            clearServiceSelect();
            return Promise.resolve();
        }
        destroySelect2(serviceSelect);
        serviceSelect.disabled = true;
        serviceSelect.innerHTML = '<option value="">' + loadingServicesText + '</option>';
        ensureSelect2(serviceSelect, selectServiceText);
        return fetch('../income/get_doctor_services.php?doctor_id=' + encodeURIComponent(doctorId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                destroySelect2(serviceSelect);
                const services = (data && data.success && Array.isArray(data.services)) ? data.services : [];
                let html = '<option value="">' + selectServiceText + '</option>';
                services.forEach(function(svc) {
                    const id = String(svc.id || '');
                    const sel = selectedServiceId && String(selectedServiceId) === id ? ' selected' : '';
                    html += '<option value="' + id + '"' + sel + '>' + String(svc.name || '').replace(/</g, '&lt;') + '</option>';
                });
                serviceSelect.innerHTML = html;
                serviceSelect.disabled = services.length === 0;
                if (selectedServiceId) {
                    serviceSelect.value = String(selectedServiceId);
                }
                ensureSelect2(serviceSelect, selectServiceText);
                if (typeof $ !== 'undefined' && selectedServiceId) {
                    $(serviceSelect).val(String(selectedServiceId)).trigger('change.select2');
                }
            })
            .catch(function() {
                clearServiceSelect();
            });
    }

    if (doctorSelect) {
        ensureSelect2(doctorSelect, selectDoctorText);
    }

    if (doctorSelect && serviceSelect) {
        if (typeof $ !== 'undefined') {
            $(doctorSelect).on('change', function() {
                loadServicesForDoctor(doctorSelect.value, null);
            });
        } else {
            doctorSelect.addEventListener('change', function() {
                loadServicesForDoctor(doctorSelect.value, null);
            });
        }
        const initialDoctor = doctorSelect.value;
        const initialService = parseInt(serviceSelect.getAttribute('data-selected-service') || '0', 10) || 0;
        if (initialDoctor) {
            loadServicesForDoctor(initialDoctor, initialService > 0 ? initialService : null);
        } else {
            clearServiceSelect();
        }
    } else if (serviceSelect) {
        ensureSelect2(serviceSelect, selectServiceText);
    }

    form.addEventListener('submit', function() {
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
        submitBtn.setAttribute('data-original-text', originalText);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
