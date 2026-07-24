<?php
/**
 * Add Income Payment Method Page
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

// Check admin or doctor access
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
        $required_fields = ['name'];
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if ($validation_result['valid']) {
            // Sanitize and validate input
            $name = dcmt_sanitize_input($_POST['name']);
            $description = dcmt_sanitize_input($_POST['description']);
            $status = dcmt_sanitize_input($_POST['status']);
            
            // Validate name length
            if (strlen($name) > 100) {
                $errors[] = trans('income_payment_method', 'name_too_long');
            }
            
            // Check for duplicate name
            try {
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE dcmt_name = ?");
                $stmt->execute([$name]);
                if ($stmt->fetch()) {
                    $errors[] = trans('income_payment_method', 'name_exists');
                }
            } catch (PDOException $e) {
                $errors[] = trans('income_payment_method', 'database_error');
            }
            
            // If no validation errors, insert into database
            if (empty($errors)) {
                try {
                    $sql = "INSERT INTO dcmt_income_payment_methods (dcmt_name, dcmt_description, dcmt_status, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, NOW())";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $name,
                        $description,
                        $status,
                        dcmt_get_current_user()['dcmt_username']
                    ]);
                    
                    $payment_method_id = $dcmt_pdo->lastInsertId();
                    
                    // Log activity
                    dcmt_log_activity("Income payment method added: $name", "Income Payment Method ID: $payment_method_id, Name: $name");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('income_payment_method', 'add_success'), 'success');
                    dcmt_redirect("index.php");
                    
                } catch (PDOException $e) {
                    $errors[] = trans('income_payment_method', 'database_error') . ': ' . $e->getMessage();
                    dcmt_log_activity("Error adding income payment method: " . $e->getMessage(), "error");
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<link href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>" rel="stylesheet">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('income_payment_method', 'add_payment_method'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('income_payment_method', 'view_all_payment_methods'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="paymentMethodForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="row">
            <div class="col-md-8">
                <div class="mb-3">
                    <label for="name" class="form-label"><?php echo trans('income_payment_method', 'name'); ?> *</label>
                    <input type="text" class="form-control" id="name" name="name" 
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                           required maxlength="100" placeholder="<?php echo trans('income_payment_method', 'enter_name'); ?>">
                    <div class="form-text"><?php echo trans('income_payment_method', 'name_help'); ?></div>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="status" class="form-label"><?php echo trans('common', 'status'); ?> *</label>
                    <select class="form-select" id="status" name="status" required>
                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'active'); ?>
                        </option>
                        <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>>
                            <?php echo trans('common', 'inactive'); ?>
                        </option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"
                      placeholder="<?php echo trans('income_payment_method', 'enter_description'); ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
            <div class="form-text"><?php echo trans('income_payment_method', 'description_help'); ?></div>
        </div>
        
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                <i class="fas fa-undo"></i><?php echo trans('income_payment_method', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('income_payment_method', 'add_payment_method_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
// Define reset function with project prefix to avoid collisions
function dcmt_resetPaymentMethodForm() {
    // Reset form immediately
    const form = document.getElementById('paymentMethodForm');
    if (form) {
        form.reset();
    }
    
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
		submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('income_payment_method', 'add_payment_method_record'); ?>';
	}

	// Focus first field
	const nameField = document.getElementById('name');
	if (nameField) {
		nameField.focus();
	}
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    
    // Reset button functionality
    const resetBtn = document.getElementById('resetBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (confirm('<?php echo trans('income_payment_method', 'confirm_reset'); ?>')) {
				dcmt_resetPaymentMethodForm();
            }
        });
    }
    
    // Form submission handling
    const form = document.getElementById('paymentMethodForm');
    if (form) {
        form.addEventListener('submit', function(e) {
            const submitBtn = document.getElementById('submitBtn');
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo trans('income_payment_method', 'adding'); ?>...';
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
