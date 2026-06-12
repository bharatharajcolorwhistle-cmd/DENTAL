<?php
/**
 * Add Service Page
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

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Validate required fields
        $required_fields = ['name', 'base_price', 'status'];
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if ($validation_result['valid']) {
            // Sanitize and validate input
            $name = dcmt_sanitize_input($_POST['name']);
            $description = isset($_POST['description']) ? dcmt_sanitize_input($_POST['description']) : '';
            $base_price = dcmt_sanitize_input($_POST['base_price']);
            $status = dcmt_sanitize_input($_POST['status']);
            
            // Validate base price
            $price_error = dcmt_validate_numeric_field($base_price, 'Base Price');
            if ($price_error) {
                $errors[] = $price_error;
            }
            
            // Validate base price range
            if ($base_price < 0) {
                $errors[] = trans('service', 'price_negative');
            }
            
            // Check if service name already exists
            try {
                $check_stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_services WHERE dcmt_name = ?");
                $check_stmt->execute([$name]);
                if ($check_stmt->fetch()) {
                    $errors[] = trans('service', 'name_exists');
                }
            } catch (PDOException $e) {
                error_log("Error checking service name: " . $e->getMessage());
            }
            
            // If no validation errors, insert into database
            if (empty($errors)) {
                try {
                    $sql = "INSERT INTO dcmt_services (dcmt_name, dcmt_description, dcmt_base_price, dcmt_status, dcmt_created_by) 
                            VALUES (?, ?, ?, ?, ?)";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $name,
                        $description,
                        $base_price,
                        $status,
                        dcmt_get_current_user()['dcmt_username']
                    ]);
                    
                    // Log activity
                    dcmt_log_activity("Service added: $name - Price: " . dcmt_format_currency($base_price), "service_added");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('service', 'add_success'), "success");
                    dcmt_redirect("index.php");
                    exit();
                    
                } catch (PDOException $e) {
                    $errors[] = trans('service', 'add_failed');
                    dcmt_log_activity("Error adding service: " . $e->getMessage(), "error");
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

<link rel="stylesheet" href="../../assets/css/add-income.css">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('service', 'add_service'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('service', 'view_all_services'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="serviceForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="name" class="form-label"><?php echo trans('service', 'service_name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="name" name="name" 
                           value="<?php echo htmlspecialchars($_POST['name'] ?? ''); ?>" 
                           required maxlength="100" placeholder="<?php echo trans('service', 'enter_name'); ?>">
                    <div class="form-text"><?php echo trans('service', 'name_help'); ?></div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="base_price" class="form-label"><?php echo trans('service', 'base_price'); ?> <span class="text-danger">*</span></label>
                    <div class="dcmt-amount-input-wrapper">
                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                        <input type="number" class="form-control dcmt-amount-input" id="base_price" name="base_price" 
                               value="<?php echo htmlspecialchars($_POST['base_price'] ?? ''); ?>" 
                               required step="0.01" min="0" placeholder="<?php echo trans('common', 'amount'); ?>">
                    </div>
                    <div class="form-text"><?php echo trans('service', 'price_help'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="status" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="status" name="status" required>
                        <option value=""><?php echo trans('service', 'select_status'); ?></option>
                        <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : 'selected'; ?>><?php echo trans('common', 'active'); ?></option>
                        <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3" 
                      placeholder="<?php echo trans('service', 'enter_description'); ?>"><?php echo htmlspecialchars($_POST['description'] ?? ''); ?></textarea>
            <div class="form-text"><?php echo trans('service', 'description_help'); ?></div>
        </div>
        
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                <i class="fas fa-undo"></i><?php echo trans('common', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('service', 'add_service_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
// Define reset function with project prefix to avoid collisions
function dcmt_resetServiceForm() {
    const form = document.getElementById('serviceForm');
    if (!form) {
        return;
    }
    
    form.reset();
    
    setTimeout(() => {
        const fields = {
            'name': '',
            'description': '',
            'base_price': '',
            'status': 'active'
        };
        
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
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('service', 'add_service_record'); ?>';
    }

    // Focus first field
    const nameField = document.getElementById('name');
    if (nameField) {
        nameField.focus();
    }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    const dcmtResetConfirmMessage = '<?php echo addslashes(trans('service', 'confirm_reset') ?: trans('common', 'confirm_reset') ?: 'Are you sure you want to reset the form?'); ?>';
    // Initialize form change detection
    dcmtInitFormChangeDetection('serviceForm');
    
    const form = document.getElementById('serviceForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !submitBtn || !resetBtn) {
        return;
    }
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm(dcmtResetConfirmMessage)) {
            dcmt_resetServiceForm();
        }
    });
    
    // Form validation
    form.addEventListener('submit', function(e) {
        // Clear form changed flag since we're submitting
        dcmtClearFormChanged();
        
        const basePrice = parseFloat(document.getElementById('base_price').value);
        
        if (basePrice < 0) {
            e.preventDefault();
            alert('<?php echo trans('service', 'price_negative'); ?>');
            document.getElementById('base_price').focus();
            return false;
        }
        
        // Show loading state
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
        
        submitBtn.setAttribute('data-original-text', originalText);
    });
    
    // Restore button state if form validation fails
    const originalText = submitBtn.getAttribute('data-original-text');
    if (originalText) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
