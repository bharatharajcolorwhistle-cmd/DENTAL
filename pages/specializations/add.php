<?php
/**
 * Doctor Specializations Management - Add Specialization
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check admin access
dcmt_require_admin_or_staff();

$errors = [];
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Validate required fields
        $required_fields = ['name', 'status'];
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if ($validation_result['valid']) {
            // Sanitize and validate input
            $name = dcmt_sanitize_input($_POST['name']);
            $description = isset($_POST['description']) ? dcmt_sanitize_input($_POST['description']) : '';
            $status = dcmt_sanitize_input($_POST['status']);
        
            // Check for duplicate name
            if (empty($errors)) {
                try {
                    $check_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_doctor_specializations WHERE dcmt_name = ?");
                    $check_stmt->execute([$name]);
                    
                    if ($check_stmt->fetchColumn() > 0) {
                        $errors[] = trans('specialization', 'name_exists');
                    }
                } catch (PDOException $e) {
                    $errors[] = trans('specialization', 'duplicate_check_error');
                }
            }
            
            // Insert specialization if no errors
            if (empty($errors)) {
                try {
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_doctor_specializations (dcmt_name, dcmt_description, dcmt_status, dcmt_created_by) 
                        VALUES (?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $name,
                        $description,
                        $status,
                        dcmt_get_current_user()['dcmt_username']
                    ]);
                    
                    $specialization_id = $dcmt_pdo->lastInsertId();
                    
                    // Log activity
                    dcmt_log_activity("Doctor specialization added: $name", "specialization_added");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('specialization', 'add_success'), "success");
                    dcmt_redirect("index.php");
                    exit();
                    
                } catch (PDOException $e) {
                    error_log("Database error adding specialization: " . $e->getMessage());
                    $errors[] = trans('specialization', 'add_error');
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('specialization', 'add_specialization'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('specialization', 'view_all_specializations'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="specializationForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="name" class="form-label"><?php echo trans('specialization', 'name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" 
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                           required maxlength="100" placeholder="<?php echo trans('specialization', 'enter_name'); ?>">
                    <div class="form-text"><?php echo trans('specialization', 'name_help'); ?></div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="status" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="status" name="status" required>
                        <option value=""><?php echo trans('specialization', 'select_status'); ?></option>
                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'active'); ?>
                        </option>
                        <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'inactive'); ?>
                        </option>
                    </select>
                    <div class="form-text"><?php echo trans('specialization', 'status_help'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('specialization', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3" 
                      placeholder="<?php echo trans('specialization', 'enter_description'); ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            <div class="form-text"><?php echo trans('specialization', 'description_help'); ?></div>
        </div>
        
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                <i class="fas fa-undo"></i><?php echo trans('specialization', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('specialization', 'add_specialization_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
// Define reset function with project prefix to avoid collisions
function dcmt_resetSpecializationForm() {
    // Get form element
    const form = document.getElementById('specializationForm');
    if (!form) {
        return;
    }
    
    // Reset form immediately
    form.reset();
    
    // Use setTimeout to ensure form.reset() completes before manual field setting
    setTimeout(() => {
        // Manually clear and set specific fields to ensure proper reset
        const fields = {
            'name': '',
            'description': '',
            'status': 'active'
        };
        
        // Clear each field individually
        Object.keys(fields).forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = fields[fieldId];
            }
        });
    }, 10);

    // Remove any visible server-side error alert
    const errorAlert = document.querySelector('.alert.alert-danger');
    if (errorAlert && errorAlert.parentNode) {
        errorAlert.parentNode.removeChild(errorAlert);
    }

    // Clear validation/UI states
    document.querySelectorAll('.is-invalid').forEach(function(el){ el.classList.remove('is-invalid'); });
    document.querySelectorAll('.invalid-feedback').forEach(function(el){ el.textContent = ''; });

    // Re-enable and restore submit button text
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('specialization', 'add_specialization_record'); ?>';
    }

    // Focus first field
    const nameField = document.getElementById('name');
    if (nameField) {
        nameField.focus();
    }
}

// Add event listener for reset button
document.addEventListener('DOMContentLoaded', function() {
    const resetBtn = document.getElementById('resetBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (confirm('<?php echo trans('specialization', 'confirm_reset'); ?>')) {
                dcmt_resetSpecializationForm();
            }
        });
    }
});

// Form validation
document.getElementById('specializationForm').addEventListener('submit', function(e) {
    const name = document.getElementById('name').value.trim();
    const status = document.getElementById('status').value;
    
    if (!name) {
        e.preventDefault();
        alert('<?php echo trans('specialization', 'name_required'); ?>');
        document.getElementById('name').focus();
        return false;
    }
    
    if (!status) {
        e.preventDefault();
        alert('<?php echo trans('specialization', 'status_required'); ?>');
        document.getElementById('status').focus();
        return false;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
