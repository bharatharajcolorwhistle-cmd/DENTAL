<?php
/**
 * Edit Patient Note Page
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

$note_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($note_id <= 0) {
    dcmt_show_message(trans('patient_note', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_patient_notes WHERE dcmt_id = ?");
    $stmt->execute([$note_id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$note) {
        dcmt_show_message(trans('patient_note', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching patient note: " . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$errors = [];
$form_data = [
    'patient_id' => $note['dcmt_patient_id'] ?? 0,
    'note_date' => $note['dcmt_note_date'] ?? dcmt_get_current_date(),
    'topic' => $note['dcmt_topic'] ?? '',
    'note_text' => $note['dcmt_note_text'] ?? '',
];

// Get all patients for dropdown
$all_patients = [];
try {
    $stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_status FROM dcmt_patients ORDER BY dcmt_patient_name");
    $all_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching patients: " . $e->getMessage());
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
                // Update note
                $stmt = $dcmt_pdo->prepare("
                    UPDATE dcmt_patient_notes 
                    SET dcmt_patient_id = ?, dcmt_note_date = ?, dcmt_topic = ?, dcmt_note_text = ?
                    WHERE dcmt_id = ?
                ");
                $stmt->execute([$form_data['patient_id'], $form_data['note_date'], $form_data['topic'] ?: null, $form_data['note_text'], $note_id]);
                
                // Log activity
                dcmt_log_activity('Patient note updated', "Note ID: $note_id");
                
                dcmt_show_message(trans('patient_note', 'update_success'), 'success');
                dcmt_redirect('view.php?id=' . $note_id);
                exit();
            } catch (PDOException $e) {
                error_log("Error updating patient note: " . $e->getMessage());
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('patient_note', 'edit_note'); ?></h1>
            <a href="view.php?id=<?php echo $note_id; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('patient_note', 'view_note'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="dcmtPatientNoteEditForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="patient_id" class="form-label"><?php echo trans('patient', 'patient'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="patient_id" name="patient_id" required>
                        <option value=""><?php echo trans('patient_note', 'select_patient'); ?></option>
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
                    </select>
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
            <a href="view.php?id=<?php echo $note_id; ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit">
                <i class="fas fa-save"></i><?php echo trans('patient_note', 'update_note_record'); ?>
            </button>
        </div>
    </form>
</div>

<script src="../../assets/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 on patient field
    if (typeof $ !== 'undefined') {
        $('#patient_id').select2({
            placeholder: '<?php echo trans('patient_note', 'select_patient'); ?>',
            allowClear: true,
            width: '100%'
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

