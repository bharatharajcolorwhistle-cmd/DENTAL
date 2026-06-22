<?php
/**
 * View patient odontogram (read-only).
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
    exit();
}

try {
    $patient_cols = dcmt_patient_select_columns_without_odontogram('p', $dcmt_pdo);
    $stmt = $dcmt_pdo->prepare("SELECT {$patient_cols} FROM dcmt_patients p WHERE p.dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('../patient_notes/index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log('Patient odontogram view: ' . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
    exit();
}

$dcmt_odontogram_patient_id = $patient_id;
$dcmt_odontogram_initial_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
if ($dcmt_odontogram_initial_json === '') {
    $dcmt_odontogram_initial_json = '{}';
}
$dcmt_odontogram_has_data = dcmt_patient_odontogram_has_data($dcmt_odontogram_initial_json);

$back_url = '../patient_notes/index.php?patient_id=' . $patient_id;
$dcmt_odontogram_edit_href = 'edit.php?patient_id=' . $patient_id . '#dcmtOdontogramDualWrap';
$dcmt_odontogram_print_href = '../patient_notes/print_clinical.php?patient_id=' . $patient_id;

global $dcmt_current_user;
$dcmt_can_edit_odontogram = dcmt_is_admin() || in_array($dcmt_current_user['dcmt_role'] ?? '', ['staff', 'doctor', 'assistant'], true);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title">
                <?php echo htmlspecialchars(trans('patient', 'odontogram_title')); ?>
            </h1>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <a href="<?php echo htmlspecialchars($dcmt_odontogram_print_href); ?>"
                   class="dcmt-add-form-view-all-link"
                   target="_blank" rel="noopener noreferrer">
                    <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient_note', 'print_clinical_history')); ?>
                </a>
                <?php if ($dcmt_can_edit_odontogram): ?>
                    <a href="<?php echo htmlspecialchars($dcmt_odontogram_edit_href); ?>" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-edit me-1"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_edit_chart')); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="dcmt-add-form-view-all-link">
                    <?php echo trans('patient_note', 'back_to_notes'); ?>
                </a>
            </div>
        </div>
    </div>

    <?php include __DIR__ . '/../patients/odontogram_view.php'; ?>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
