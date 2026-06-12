<?php
/**
 * Inventory Categories Management - Add Category
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
            $required_fields = ['name', 'status', 'product_type'];
            $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if ($validation_result['valid']) {
            // Sanitize and validate input
            $name = dcmt_sanitize_input($_POST['name']);
            $description = isset($_POST['description']) ? dcmt_sanitize_input($_POST['description']) : '';
            $status = dcmt_sanitize_input($_POST['status']);
            $product_type = dcmt_sanitize_input($_POST['product_type']);
            
            // Check for duplicate name
            if (empty($errors)) {
                try {
                    $check_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_inventory_categories WHERE dcmt_name = ?");
                    $check_stmt->execute([$name]);
                    
                    if ($check_stmt->fetchColumn() > 0) {
                        $errors[] = trans('inventory_category', 'name_exists');
                    }
                } catch (PDOException $e) {
                    $errors[] = trans('inventory_category', 'duplicate_check_error');
                }
            }
            
            // Insert category if no errors
            if (empty($errors)) {
                try {
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_inventory_categories (dcmt_name, dcmt_description, dcmt_status, dcmt_product_type, dcmt_created_by) 
                        VALUES (?, ?, ?, ?, ?)
                    ");
                    
                    $stmt->execute([
                        $name,
                        $description,
                        $status,
                        $product_type,
                        dcmt_get_current_user()['dcmt_username']
                    ]);
                    
                    $category_id = $dcmt_pdo->lastInsertId();
                    
                    // Log activity
                    dcmt_log_activity("Inventory category added: $name", "inventory_category_added");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('inventory_category', 'add_success') . ': ' . $name, 'success');
                    dcmt_redirect("index.php");
                    
                } catch (PDOException $e) {
                    $errors[] = trans('inventory_category', 'database_error') . ': ' . $e->getMessage();
                    dcmt_log_activity("Error adding inventory category: " . $e->getMessage(), "error");
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Predefined colors


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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('inventory_category', 'add_category'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('inventory_category', 'view_all_categories'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="categoryForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo trans('inventory_category', 'category_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                               required maxlength="100" placeholder="<?php echo trans('inventory_category', 'enter_name'); ?>">
                        <div class="form-text"><?php echo trans('inventory_category', 'name_help'); ?></div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status" class="form-label"><?php echo trans('inventory_category', 'status'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value=""><?php echo trans('inventory_category', 'select_status'); ?></option>
                            <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : ''; ?>>
                                <?php echo trans('inventory_category', 'active'); ?>
                            </option>
                            <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>>
                                <?php echo trans('inventory_category', 'inactive'); ?>
                            </option>
                        </select>
                        <div class="form-text"><?php echo trans('inventory_category', 'status_help'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="product_type" class="form-label"><?php echo trans('inventory_category', 'product_type'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="product_type" name="product_type" required>
                            <option value=""><?php echo trans('inventory_category', 'select_product_type'); ?></option>
                            <option value="for_sale" <?php echo (isset($_POST['product_type']) && $_POST['product_type'] === 'for_sale') ? 'selected' : ''; ?>>
                                <?php echo trans('inventory_category', 'for_sale'); ?>
                            </option>
                            <option value="for_use" <?php echo (isset($_POST['product_type']) && $_POST['product_type'] === 'for_use') ? 'selected' : ''; ?>>
                                <?php echo trans('inventory_category', 'for_use'); ?>
                            </option>
                        </select>
                        <div class="form-text"><?php echo trans('inventory_category', 'product_type_help'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
                <textarea class="form-control" id="description" name="description" rows="3" 
                          placeholder="<?php echo trans('inventory_category', 'enter_description'); ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                <div class="form-text"><?php echo trans('inventory_category', 'description_help'); ?></div>
            </div>
            

            
            <div class="dcmt-form-actions">
                <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                    <i class="fas fa-undo"></i><?php echo trans('inventory_category', 'reset'); ?>
                </button>
                <a href="index.php" class="btn dcmt-btn-cancel">
                    <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
                </a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-plus"></i><?php echo trans('inventory_category', 'add_category_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Define reset function with project prefix to avoid collisions
function dcmt_resetInventoryCategoryForm() {
    // Get form element
    const form = document.getElementById('categoryForm');
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
            'status': 'active',
            'product_type': ''
        };
        
        // Clear each field individually
        Object.keys(fields).forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = fields[fieldId];
                // Trigger change event to ensure any listeners are notified
                field.dispatchEvent(new Event('change', { bubbles: true }));
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
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('inventory_category', 'add_category_record'); ?>';
    }

    // Focus first field
    const nameField = document.getElementById('name');
    if (nameField) {
        nameField.focus();
    }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Get form elements
    const form = document.getElementById('categoryForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !resetBtn) {
        return;
    }
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('<?php echo trans('inventory_category', 'confirm_reset'); ?>')) {
            dcmt_resetInventoryCategoryForm();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
