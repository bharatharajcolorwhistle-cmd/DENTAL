<?php
/**
 * Add Patient Note Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Validate session
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$errors = [];
$form_data = [
    'patient_id' => isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0,
    'note_date' => dcmt_get_current_date(),
    'topic' => '',
    'note_text' => '',
];

// Get all patients for dropdown
$all_patients = [];
$patient_fetch_error = null;
try {
    // Check if table exists first
    $table_check = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_patients'");
    if ($table_check->rowCount() > 0) {
        $stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_status FROM dcmt_patients ORDER BY dcmt_patient_name");
        $all_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $patient_fetch_error = "Patients table does not exist. Please ensure the database is properly initialized.";
        error_log("Patients table does not exist");
    }
} catch (PDOException $e) {
    $patient_fetch_error = "Error fetching patients: " . $e->getMessage();
    error_log($patient_fetch_error);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient_note', 'invalid_token');
    } else {
        // Sanitize input
        $form_data['patient_id'] = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
        $form_data['note_date'] = isset($_POST['note_date']) ? dcmt_sanitize_input($_POST['note_date']) : dcmt_get_current_date();
        $form_data['topic'] = isset($_POST['topic']) ? trim(dcmt_sanitize_input($_POST['topic'])) : '';
        // Don't use htmlspecialchars for note_text - only trim, let PDO handle SQL injection
        $form_data['note_text'] = isset($_POST['note_text']) ? trim($_POST['note_text']) : '';

        // Validation
        if ($form_data['patient_id'] <= 0) {
            $errors[] = trans('patient_note', 'patient_required');
        }

        if (empty($form_data['note_date'])) {
            $errors[] = trans('patient_note', 'note_date_required');
        } else {
            $date_error = dcmt_validate_date_field($form_data['note_date'], trans('patient_note', 'note_date'));
            if ($date_error) {
                $errors[] = $date_error;
            }
        }

        if (empty($form_data['note_text'])) {
            $errors[] = trans('patient_note', 'note_text_required');
        }

        if (empty($errors)) {
            try {
                // Verify patient exists
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_patients WHERE dcmt_id = ?");
                $stmt->execute([$form_data['patient_id']]);
                if (!$stmt->fetch()) {
                    $errors[] = trans('patient', 'not_found');
                } else {
                    // Get current user
                    $current_user = dcmt_get_current_user();
                    $created_by = $current_user['dcmt_username'] ?? 'system';
                    
                    // Insert note
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_patient_notes (dcmt_patient_id, dcmt_note_date, dcmt_topic, dcmt_note_text, dcmt_created_by, dcmt_created_at) 
                        VALUES (?, ?, ?, ?, ?, NOW())
                    ");
                    $stmt->execute([$form_data['patient_id'], $form_data['note_date'], $form_data['topic'] ?: null, $form_data['note_text'], $created_by]);
                    
                    $note_id = $dcmt_pdo->lastInsertId();
                    
                    // Log activity
                    dcmt_log_activity('Patient note added', "Patient ID: {$form_data['patient_id']}, Note ID: $note_id");
                    
                    dcmt_show_message(trans('patient_note', 'add_success'), 'success');
                    dcmt_redirect('index.php');
                    exit();
                }
            } catch (PDOException $e) {
                error_log("Error adding patient note: " . $e->getMessage());
                $errors[] = trans('patient_note', 'database_error');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">
<link href="../../assets/css/select2.min.css" rel="stylesheet">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('patient_note', 'add_note'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient_note', 'view_all_notes'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="dcmtPatientNoteForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="patient_id" class="form-label"><?php echo trans('patient', 'patient'); ?> <span class="text-danger">*</span></label>
                    <?php if ($patient_fetch_error): ?>
                        <div class="alert alert-warning">
                            <small><?php echo htmlspecialchars($patient_fetch_error); ?></small>
                        </div>
                    <?php endif; ?>
                    <select class="form-select" id="patient_id" name="patient_id" required <?php echo !empty($patient_fetch_error) ? 'disabled' : ''; ?>>
                        <option value=""><?php echo trans('patient_note', 'select_patient'); ?></option>
                        <?php if (empty($patient_fetch_error)): ?>
                            <?php foreach ($all_patients as $pat): ?>
                                <option value="<?php echo $pat['dcmt_id']; ?>" 
                                        <?php echo $form_data['patient_id'] == $pat['dcmt_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($pat['dcmt_patient_name'] ?? ''); ?>
                                    <?php if (!empty($pat['dcmt_phone'])): ?>
                                        - <?php echo htmlspecialchars($pat['dcmt_phone']); ?>
                                    <?php endif; ?>
                                    <?php if (($pat['dcmt_status'] ?? 'active') !== 'active'): ?>
                                        (<?php echo trans('common', 'inactive'); ?>)
                                    <?php endif; ?>
                                </option>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </select>
                    <?php if (empty($all_patients) && empty($patient_fetch_error)): ?>
                        <div class="form-text text-warning">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo trans('patient_note', 'no_patients_found'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="note_date" class="form-label"><?php echo trans('patient_note', 'note_date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="note_date" name="note_date" 
                           value="<?php echo htmlspecialchars($form_data['note_date']); ?>" required>
                    <div class="form-text"><?php echo trans('patient_note', 'note_date_help'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="topic" class="form-label"><?php echo trans('patient_note', 'topic'); ?></label>
            <input type="text" class="form-control" id="topic" name="topic" 
                   value="<?php echo htmlspecialchars($form_data['topic']); ?>"
                   placeholder="<?php echo trans('patient_note', 'topic_placeholder'); ?>" maxlength="255">
            <div class="form-text"><?php echo trans('patient_note', 'topic_help'); ?></div>
        </div>
        
        <div class="mb-3">
            <label for="note_text" class="form-label"><?php echo trans('patient_note', 'note'); ?> <span class="text-danger">*</span></label>
            <textarea class="form-control" id="note_text" name="note_text" rows="6" 
                      placeholder="<?php echo trans('patient_note', 'note_placeholder'); ?>" required><?php echo htmlspecialchars($form_data['note_text']); ?></textarea>
            <div class="form-text"><?php echo trans('patient_note', 'note_help'); ?></div>
        </div>
        
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="dcmtResetNoteBtn">
                <i class="fas fa-undo"></i><?php echo trans('common', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit">
                <i class="fas fa-plus"></i><?php echo trans('patient_note', 'add_note_record'); ?>
            </button>
        </div>
    </form>
</div>

<script src="../../assets/js/select2.min.js"></script>
<script>
function dcmt_resetPatientNoteForm() {
    const form = document.getElementById('dcmtPatientNoteForm');
    if (!form) return;
    if (confirm('<?php echo trans('patient_note', 'confirm_reset'); ?>')) {
        form.reset();
        // Re-initialize Select2 after reset
        if (typeof $ !== 'undefined') {
            $('#patient_id').val('').trigger('change');
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const resetBtn = document.getElementById('dcmtResetNoteBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', dcmt_resetPatientNoteForm);
    }
    
    // Initialize Select2 on patient field
    if (typeof $ !== 'undefined') {
        $('#patient_id').select2({
            placeholder: '<?php echo trans('patient_note', 'select_patient'); ?>',
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0
        });
        
        $(document).on('select2:open', function() {
            const input = document.querySelector('.select2-container--open .select2-search__field');
            if (input) input.focus();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

