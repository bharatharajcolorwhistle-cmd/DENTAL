<?php
/**
 * Edit patient odontogram (one chart per patient).
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';
require_once __DIR__ . '/../../includes/patient_treatment_plan.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$dcmt_db = new Dcmt_Database();
$dcmt_db->migratePatientOdontogramToDedicatedTable();

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$errors = [];

$all_patients = [];
try {
    $stmt = $dcmt_pdo->query('SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_status FROM dcmt_patients ORDER BY dcmt_patient_name');
    $all_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Patient odontogram edit — patients list: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient', 'invalid_token');
    } else {
        $patient_id = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
        if ($patient_id <= 0) {
            $errors[] = trans('patient_note', 'patient_required');
        } else {
            try {
                $chk = $dcmt_pdo->prepare('SELECT dcmt_id FROM dcmt_patients WHERE dcmt_id = ?');
                $chk->execute([$patient_id]);
                if (!$chk->fetch()) {
                    $errors[] = trans('patient', 'not_found');
                }
            } catch (PDOException $e) {
                error_log('Patient odontogram edit — verify patient: ' . $e->getMessage());
                $errors[] = trans('patient_note', 'database_error');
            }
        }

        $dcmt_odontogram_post = dcmt_parse_patient_odontogram_post($_POST['odontogram_data'] ?? null);

        if (empty($errors)) {
            try {
                dcmt_save_patient_odontogram_json($dcmt_pdo, $patient_id, $dcmt_odontogram_post['json'] ?? null);
                // Keep proposed treatment plan in sync with the updated solution chart.
                global $dcmt_current_user;
                dcmt_sync_treatment_plan_from_odontogram(
                    $dcmt_pdo,
                    $patient_id,
                    null,
                    false,
                    $dcmt_current_user['dcmt_username'] ?? null
                );
                dcmt_log_activity('Patient odontogram saved', "Patient ID: $patient_id");
                dcmt_show_message(trans('patient_note', 'odontogram_save_success'), 'success');
                dcmt_redirect('../patient_notes/index.php?patient_id=' . $patient_id);
                exit();
            } catch (PDOException $e) {
                error_log('Patient odontogram save: ' . $e->getMessage());
                $errors[] = trans('patient_note', 'database_error');
            }
        }
    }
}

$dcmt_odontogram_patient_id = $patient_id;
$dcmt_odontogram_initial_json = $patient_id > 0
    ? dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id)
    : '{}';
if ($dcmt_odontogram_initial_json === '') {
    $dcmt_odontogram_initial_json = '{}';
}
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['odontogram_data'])) {
    $dcmt_odontogram_initial_json = (string) $_POST['odontogram_data'];
}

$dcmt_odontogram_has_data = $patient_id > 0 && dcmt_patient_odontogram_has_data($dcmt_odontogram_initial_json);
$page_title = $dcmt_odontogram_has_data
    ? trans('patient_note', 'edit_odontogram')
    : trans('patient_note', 'add_odontogram');

$csrf_token = dcmt_generate_csrf_token();
$back_url = $patient_id > 0
    ? '../patient_notes/index.php?patient_id=' . $patient_id
    : '../patient_notes/index.php';
$treatment_plan_url = 'treatment_plan.php?patient_id=' . $patient_id;

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">
<link href="<?php echo dcmt_asset('assets/css/select2.min.css', '../../'); ?>" rel="stylesheet">

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
            <h1 class="dcmt-add-form-page-title"><?php echo htmlspecialchars($page_title); ?></h1>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <?php if ($patient_id > 0 && $dcmt_odontogram_has_data): ?>
                    <a href="<?php echo htmlspecialchars($treatment_plan_url); ?>" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-clipboard-list me-1"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_open')); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="dcmt-add-form-view-all-link">
                    <?php echo trans('patient_note', 'back_to_notes'); ?>
                </a>
            </div>
        </div>
    </div>
    <form method="POST" action="" id="dcmtPatientOdontogramForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <div class="row mb-4">
            <div class="col-md-6">
                <label for="patient_id" class="form-label"><?php echo trans('patient', 'patient'); ?> <span class="text-danger">*</span></label>
                <select class="form-select" id="patient_id" name="patient_id" required>
                    <option value=""><?php echo trans('patient_note', 'select_patient'); ?></option>
                    <?php foreach ($all_patients as $pat): ?>
                        <option value="<?php echo (int) $pat['dcmt_id']; ?>"
                            <?php echo $patient_id === (int) $pat['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pat['dcmt_patient_name'] ?? ''); ?>
                            <?php if (!empty($pat['dcmt_phone'])): ?>
                                - <?php echo htmlspecialchars($pat['dcmt_phone']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
                <div class="form-text"><?php echo trans('patient_note', 'odontogram_one_per_patient'); ?></div>
            </div>
        </div>

        <div id="dcmtOdontogramFormWrap" class="<?php echo $patient_id > 0 ? '' : 'd-none'; ?>">
            <?php include __DIR__ . '/../patients/odontogram_fields.php'; ?>
        </div>

        <div class="dcmt-form-actions">
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn" <?php echo $patient_id > 0 ? '' : 'disabled'; ?>>
                <i class="fas fa-save"></i><?php echo trans('patient_note', 'save_odontogram'); ?>
            </button>
        </div>
    </form>
</div>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dcmtPatientOdontogramForm');
    const patientSelect = document.getElementById('patient_id');
    const wrap = document.getElementById('dcmtOdontogramFormWrap');
    const submitBtn = document.getElementById('submitBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
            submitBtn.disabled = true;
            submitBtn.setAttribute('data-original-text', originalText);
        });
    }

    function onPatientChange() {
        const id = patientSelect ? parseInt(patientSelect.value, 10) : 0;
        if (id > 0) {
            const params = new URLSearchParams(window.location.search);
            params.set('patient_id', String(id));
            window.location.href = 'edit.php?' + params.toString();
        } else if (wrap) {
            wrap.classList.add('d-none');
            if (submitBtn) submitBtn.disabled = true;
        }
    }

    if (typeof $ !== 'undefined' && patientSelect) {
        $('#patient_id').select2({
            placeholder: '<?php echo addslashes(trans('patient_note', 'select_patient')); ?>',
            allowClear: false,
            width: '100%',
            minimumResultsForSearch: 0
        }).on('change', onPatientChange);
    } else if (patientSelect) {
        patientSelect.addEventListener('change', onPatientChange);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
