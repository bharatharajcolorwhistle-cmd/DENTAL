<?php
/**
 * Doctor Specializations Management - Edit Specialization
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

// Get specialization ID
$specialization_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($specialization_id <= 0) {
    dcmt_show_message(trans('specialization', 'invalid_id'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Fetch specialization details
try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_doctor_specializations WHERE dcmt_id = ?");
    $stmt->execute([$specialization_id]);
    $specialization = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$specialization) {
        dcmt_show_message(trans('specialization', 'not_found'), "error");
        dcmt_redirect("index.php");
        exit();
    }
} catch (PDOException $e) {
    dcmt_show_message(trans('specialization', 'load_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

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
        
            // Check for duplicate name (excluding current record)
            if (empty($errors)) {
                try {
                    $check_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_doctor_specializations WHERE dcmt_name = ? AND dcmt_id != ?");
                    $check_stmt->execute([$name, $specialization_id]);
                    
                    if ($check_stmt->fetchColumn() > 0) {
                        $errors[] = trans('specialization', 'name_exists');
                    }
                } catch (PDOException $e) {
                    $errors[] = trans('specialization', 'duplicate_check_error');
                }
            }
            
            // Update specialization if no errors
            if (empty($errors)) {
                try {
                    $stmt = $dcmt_pdo->prepare("
                        UPDATE dcmt_doctor_specializations 
                        SET dcmt_name = ?, dcmt_description = ?, dcmt_status = ?, dcmt_updated_at = CURRENT_TIMESTAMP
                        WHERE dcmt_id = ?
                    ");
                    
                    $stmt->execute([
                        $name,
                        $description,
                        $status,
                        $specialization_id
                    ]);
                    
                    // Log activity
                    dcmt_log_activity("Doctor specialization updated: $name", "specialization_updated");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('specialization', 'update_success'), "success");
                    dcmt_redirect("index.php");
                    exit();
                    
                } catch (PDOException $e) {
                    error_log("Database error updating specialization: " . $e->getMessage());
                    $errors[] = trans('specialization', 'update_error');
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('specialization', 'edit_specialization'); ?></h1>
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
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : htmlspecialchars($specialization['dcmt_name']); ?>" 
                           required maxlength="100" placeholder="<?php echo trans('specialization', 'enter_name'); ?>">
                    <div class="form-text"><?php echo trans('specialization', 'name_help'); ?></div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="status" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="status" name="status" required>
                        <option value=""><?php echo trans('specialization', 'select_status'); ?></option>
                        <option value="active" <?php echo ((isset($_POST['status']) && $_POST['status'] === 'active') || (!isset($_POST['status']) && $specialization['dcmt_status'] === 'active')) ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'active'); ?>
                        </option>
                        <option value="inactive" <?php echo ((isset($_POST['status']) && $_POST['status'] === 'inactive') || (!isset($_POST['status']) && $specialization['dcmt_status'] === 'inactive')) ? 'selected' : ''; ?>>
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
                      placeholder="<?php echo trans('specialization', 'enter_description'); ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : htmlspecialchars($specialization['dcmt_description']); ?></textarea>
            <div class="form-text"><?php echo trans('specialization', 'description_help'); ?></div>
        </div>
        
        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('specialization', 'update_specialization'); ?>
            </button>
        </div>
    </form>
</div>

<script>
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
