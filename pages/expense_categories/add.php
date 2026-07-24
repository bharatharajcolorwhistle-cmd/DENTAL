<?php
/**
 * Expense Categories Management - Add Category
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/expense_category_functions.php';

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
                $parent_category_id = isset($_POST['parent_category_id']) && !empty($_POST['parent_category_id']) ? intval($_POST['parent_category_id']) : null;
            
            // Check for duplicate name
            if (empty($errors)) {
                try {
                    $check_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_expense_categories WHERE dcmt_name = ?");
                    $check_stmt->execute([$name]);
                    
                    if ($check_stmt->fetchColumn() > 0) {
                        $errors[] = trans('expense_category', 'name_exists');
                    }
                } catch (PDOException $e) {
                    $errors[] = trans('expense_category', 'duplicate_check_error');
                }
            }
            
            // Validate parent category (must be a top-level active category)
            if ($parent_category_id && empty($errors) && dcmt_expense_category_has_parent_column($dcmt_pdo)) {
                $parent_check = $dcmt_pdo->prepare("
                    SELECT dcmt_id FROM dcmt_expense_categories
                    WHERE dcmt_id = ? AND dcmt_parent_category_id IS NULL AND dcmt_status = 'active'
                ");
                $parent_check->execute([$parent_category_id]);
                if (!$parent_check->fetch()) {
                    $errors[] = trans('expense_category', 'invalid_parent_category');
                }
            } elseif (!dcmt_expense_category_has_parent_column($dcmt_pdo)) {
                $parent_category_id = null;
            }
            
            // Insert category if no errors
            if (empty($errors)) {
                try {
                    // Check if parent_category_id column exists for backward compatibility
                    $column_exists = false;
                    try {
                        $check_stmt = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_expense_categories LIKE 'dcmt_parent_category_id'");
                        $column_exists = $check_stmt->rowCount() > 0;
                    } catch (Exception $e) {
                        // Column doesn't exist, use old format
                    }
                    
                    if ($column_exists) {
                        // New format with parent_category_id
                        $stmt = $dcmt_pdo->prepare("
                            INSERT INTO dcmt_expense_categories (dcmt_name, dcmt_description, dcmt_status, dcmt_parent_category_id, dcmt_created_by) 
                            VALUES (?, ?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $name,
                            $description,
                            $status,
                            $parent_category_id,
                            dcmt_get_current_user()['dcmt_username']
                        ]);
                    } else {
                        // Old format without parent_category_id
                        $stmt = $dcmt_pdo->prepare("
                            INSERT INTO dcmt_expense_categories (dcmt_name, dcmt_description, dcmt_status, dcmt_created_by) 
                            VALUES (?, ?, ?, ?)
                        ");
                        
                        $stmt->execute([
                            $name,
                            $description,
                            $status,
                            dcmt_get_current_user()['dcmt_username']
                        ]);
                    }
                    
                    $category_id = $dcmt_pdo->lastInsertId();
                    
                    // Log activity
                    dcmt_log_activity("Expense category added: $name", "expense_category_added");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('expense_category', 'add_success') . ': ' . $name, 'success');
                    dcmt_redirect("index.php");
                    
                } catch (PDOException $e) {
                    $errors[] = trans('expense_category', 'database_error') . ': ' . $e->getMessage();
                    dcmt_log_activity("Error adding expense category: " . $e->getMessage(), "error");
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('expense_category', 'add_category'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('expense_category', 'view_all_categories'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="categoryForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo trans('expense_category', 'category_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                               required maxlength="100" placeholder="<?php echo trans('expense_category', 'enter_name'); ?>">
                        <div class="form-text"><?php echo trans('expense_category', 'name_help'); ?></div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status" class="form-label"><?php echo trans('expense_category', 'status'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value=""><?php echo trans('expense_category', 'select_status'); ?></option>
                            <option value="active" <?php echo (isset($_POST['status']) && $_POST['status'] === 'active') ? 'selected' : ''; ?>>
                                <?php echo trans('expense_category', 'active'); ?>
                            </option>
                            <option value="inactive" <?php echo (isset($_POST['status']) && $_POST['status'] === 'inactive') ? 'selected' : ''; ?>>
                                <?php echo trans('expense_category', 'inactive'); ?>
                            </option>
                        </select>
                        <div class="form-text"><?php echo trans('expense_category', 'status_help'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
                <textarea class="form-control" id="description" name="description" rows="3" 
                          placeholder="<?php echo trans('expense_category', 'enter_description'); ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                <div class="form-text"><?php echo trans('expense_category', 'description_help'); ?></div>
            </div>
            
            <div class="mb-3">
                <label for="parent_category_id" class="form-label"><?php echo trans('expense_category', 'parent_category'); ?></label>
                <select class="form-select" id="parent_category_id" name="parent_category_id">
                    <option value=""><?php echo trans('expense_category', 'no_parent'); ?></option>
                    <?php
                    // Fetch main categories (categories without parent)
                    try {
                        $parent_stmt = $dcmt_pdo->prepare("
                            SELECT dcmt_id, dcmt_name 
                            FROM dcmt_expense_categories 
                            WHERE dcmt_parent_category_id IS NULL 
                            AND dcmt_status = 'active' 
                            ORDER BY dcmt_name
                        ");
                        $parent_stmt->execute();
                        $parent_categories = $parent_stmt->fetchAll();
                        
                        foreach ($parent_categories as $parent) {
                            $selected = (isset($_POST['parent_category_id']) && $_POST['parent_category_id'] == $parent['dcmt_id']) ? 'selected' : '';
                            echo "<option value=\"{$parent['dcmt_id']}\" $selected>" . htmlspecialchars($parent['dcmt_name']) . "</option>";
                        }
                    } catch (PDOException $e) {
                        echo "<option value=\"\">Error loading categories</option>";
                    }
                    ?>
                </select>
                <div class="form-text"><?php echo trans('expense_category', 'parent_category_help'); ?></div>
            </div>
            
            <div class="dcmt-form-actions">
                <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                    <i class="fas fa-undo"></i><?php echo trans('expense_category', 'reset'); ?>
                </button>
                <a href="index.php" class="btn dcmt-btn-cancel">
                    <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
                </a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-plus"></i><?php echo trans('expense_category', 'add_category_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Define reset function with project prefix to avoid collisions
function dcmt_resetExpenseCategoryForm() {
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
            'parent_category_id': ''
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
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('expense_category', 'add_category_record'); ?>';
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
        if (confirm('<?php echo trans('expense_category', 'confirm_reset'); ?>')) {
            dcmt_resetExpenseCategoryForm();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
