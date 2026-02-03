<?php
/**
 * View Patient Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_patients WHERE dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching patient: " . $e->getMessage());
    dcmt_show_message(trans('patient', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

// Safe field defaults to avoid notices on missing keys
$status_safe = ($patient['dcmt_status'] ?? '') === 'active' ? 'active' : 'inactive';

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-user-injured dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('patient', 'patient_profile'); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?></small>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <a href="../patient_notes/index.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-sticky-note me-1"></i><?php echo trans('patient_note', 'view_all_notes'); ?>
            </a>
            <a href="edit.php?id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('patient', 'back_to_patients'); ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'patient_name'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'gender'); ?>:</span>
                    <div class="dcmt-view-field-value text-capitalize"><?php echo htmlspecialchars($patient['dcmt_gender'] ?? '-'); ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'date_of_birth'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo !empty($patient['dcmt_date_of_birth']) ? dcmt_format_date($patient['dcmt_date_of_birth']) : '-'; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'age'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo isset($patient['dcmt_age']) && $patient['dcmt_age'] !== null ? htmlspecialchars($patient['dcmt_age']) : '-'; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'email'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_email'] ?? '-'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'phone'); ?>:</span>
                    <div class="dcmt-view-field-value">
                        <?php echo htmlspecialchars($patient['dcmt_phone'] ?? '-'); ?>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'date_of_birth'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo !empty($patient['dcmt_date_of_birth']) ? dcmt_format_date($patient['dcmt_date_of_birth']) : '-'; ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'height'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo $patient['dcmt_height_cm'] !== null ? htmlspecialchars($patient['dcmt_height_cm']) . ' cm' : '-'; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'weight'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo $patient['dcmt_weight_kg'] !== null ? htmlspecialchars($patient['dcmt_weight_kg']) . ' kg' : '-'; ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('common', 'status'); ?>:</span>
                    <div class="dcmt-view-field-value text-<?php echo $status_safe === 'active' ? 'success' : 'secondary'; ?>">
                        <?php echo trans('common', $status_safe); ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'allergies'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_allergies'] ?? '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'medications'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_medications'] ?? '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'emergency_contact_name'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_emergency_contact_name'] ?? '-'); ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'emergency_contact_phone'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_emergency_contact_phone'] ?? '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'emergency_contact_relation'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_emergency_contact_relation'] ?? '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('common', 'created_at'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_format_date($patient['dcmt_created_at'], DCMT_DATETIME_FORMAT); ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('common', 'updated_at'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_format_date($patient['dcmt_updated_at'], DCMT_DATETIME_FORMAT); ?></div>
                </div>
            </div>
            <div class="col-md-8">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'address'); ?>:</span>
                    <div class="dcmt-view-field-value">
                        <?php echo htmlspecialchars($patient['dcmt_address'] ?? '-'); ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($patient['dcmt_notes'])): ?>
        <div class="dcmt-view-field">
            <span class="dcmt-view-field-label"><?php echo trans('patient', 'notes'); ?>:</span>
            <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($patient['dcmt_notes'] ?? '-')); ?></div>
        </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

