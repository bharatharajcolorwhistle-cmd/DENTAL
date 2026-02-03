<?php
/**
 * Edit Inventory Category
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check if user is admin
dcmt_require_admin_or_doctor();

$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$success_message = '';

// Get category data
$category = null;
$is_system_default = false;
if ($category_id > 0) {
    try {
        $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_inventory_categories WHERE dcmt_id = ?");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch();
        
        if (!$category) {
            dcmt_show_message(trans('inventory_category', 'category_not_found'), 'danger');
            dcmt_redirect('index.php');
        }
        
        // Check if this is a system default
        $is_system_default = ($category['dcmt_created_by'] === 'system');
    } catch (PDOException $e) {
        error_log("Error fetching inventory category: " . $e->getMessage());
        dcmt_show_message(trans('inventory_category', 'error_loading_category'), 'danger');
        dcmt_redirect('index.php');
    }
} else {
    dcmt_show_message(trans('inventory_category', 'invalid_category_id'), 'danger');
    dcmt_redirect('index.php');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!dcmt_verify_csrf_token($csrf_token)) {
        $errors[] = trans('inventory_category', 'invalid_token');
    }
    
    // For system defaults, keep the original name
    $name = $is_system_default ? $category['dcmt_name'] : dcmt_sanitize_input($_POST['name'] ?? '');
    $description = dcmt_sanitize_input($_POST['description'] ?? '');
    $status = dcmt_sanitize_input($_POST['status'] ?? 'active');
    $product_type = dcmt_sanitize_input($_POST['product_type'] ?? 'for_sale');
    
    // Validation (skip name validation for system defaults)
    if (!$is_system_default && empty($name)) {
        $errors[] = trans('inventory_category', 'name_required');
    }
    
    if (!$is_system_default && strlen($name) > 100) {
        $errors[] = trans('inventory_category', 'name_too_long');
    }
    
    if (!in_array($status, ['active', 'inactive'])) {
        $errors[] = trans('inventory_category', 'invalid_status');
    }
    
    if (!in_array($product_type, ['for_sale', 'for_use'])) {
        $errors[] = trans('inventory_category', 'invalid_product_type');
    }
    
    // Check if name already exists (excluding current category and system defaults)
    if (empty($errors) && !$is_system_default) {
        try {
            $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_inventory_categories WHERE dcmt_name = ? AND dcmt_id != ?");
            $stmt->execute([$name, $category_id]);
            if ($stmt->fetch()) {
                $errors[] = trans('inventory_category', 'name_already_exists');
            }
        } catch (PDOException $e) {
            error_log("Error checking inventory category name: " . $e->getMessage());
            $errors[] = trans('inventory_category', 'error_checking_name');
        }
    }
    
    // Update category if no errors
    if (empty($errors)) {
        try {
            $stmt = $dcmt_pdo->prepare("
                UPDATE dcmt_inventory_categories 
                SET dcmt_name = ?, dcmt_description = ?, dcmt_status = ?, dcmt_product_type = ?, dcmt_updated_at = NOW()
                WHERE dcmt_id = ?
            ");
            $stmt->execute([$name, $description, $status, $product_type, $category_id]);
            
            dcmt_log_activity(trans('inventory_category', 'category_updated'), "Category ID: $category_id, Name: $name");
            dcmt_show_message(trans('inventory_category', 'category_updated_successfully'), 'success');
            dcmt_redirect('index.php');
            
        } catch (PDOException $e) {
            error_log("Error updating inventory category: " . $e->getMessage());
            $errors[] = trans('inventory_category', 'error_updating_category');
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo trans('inventory_category', 'edit_category'); ?> - <?php echo dcmt_get_site_name(); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/main.css" rel="stylesheet">
    <link href="../../assets/css/add-income.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container-fluid">
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
                    <h1 class="dcmt-add-form-page-title"><?php echo trans('inventory_category', 'edit_category'); ?></h1>
                    <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('inventory_category', 'view_all_categories'); ?></a>
                </div>
            </div>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="name" class="form-label">
                                            <i class="fas fa-tag me-2"></i><?php echo trans('inventory_category', 'category_name'); ?> <span class="text-danger">*</span>
                                        </label>
                                        <?php
                                        // Get translated name for display
                                        $display_name = $category['dcmt_name'] ?? '';
                                        if ($is_system_default) {
                                            $translated_name = trans('inventory_category', $display_name);
                                            $display_name = ($translated_name !== $display_name) ? $translated_name : $display_name;
                                        }
                                        ?>
                                        <input type="text" class="form-control" id="name" name="name" 
                                               value="<?php echo htmlspecialchars($display_name); ?>" 
                                               <?php echo $is_system_default ? 'readonly' : 'required'; ?> 
                                               maxlength="100"
                                               style="<?php echo $is_system_default ? 'background-color: #f8f9fa; cursor: not-allowed;' : ''; ?>">
                                        <?php if ($is_system_default): ?>
                                            <div class="form-text text-warning">
                                                <i class="fas fa-lock me-1"></i><?php echo trans('inventory_category', 'name_locked'); ?>
                                            </div>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="status" class="form-label">
                                            <i class="fas fa-toggle-on me-2"></i><?php echo trans('inventory_category', 'status'); ?>
                                        </label>
                                        <select class="form-select" id="status" name="status">
                                            <option value="active" <?php echo ($category['dcmt_status'] ?? '') === 'active' ? 'selected' : ''; ?>>
                                                <?php echo trans('inventory_category', 'active'); ?>
                                            </option>
                                            <option value="inactive" <?php echo ($category['dcmt_status'] ?? '') === 'inactive' ? 'selected' : ''; ?>>
                                                <?php echo trans('inventory_category', 'inactive'); ?>
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="row">
                                <div class="col-md-6">
                                    <div class="mb-3">
                                        <label for="product_type" class="form-label">
                                            <i class="fas fa-tags me-2"></i><?php echo trans('inventory_category', 'product_type'); ?>
                                        </label>
                                        <select class="form-select" id="product_type" name="product_type">
                                            <option value="for_sale" <?php echo ($category['dcmt_product_type'] ?? 'for_sale') === 'for_sale' ? 'selected' : ''; ?>>
                                                <?php echo trans('inventory_category', 'for_sale'); ?>
                                            </option>
                                            <option value="for_use" <?php echo ($category['dcmt_product_type'] ?? 'for_sale') === 'for_use' ? 'selected' : ''; ?>>
                                                <?php echo trans('inventory_category', 'for_use'); ?>
                                            </option>
                                        </select>
                                    </div>
                                </div>
                            </div>
                            
                            <div class="mb-3">
                                <label for="description" class="form-label">
                                    <i class="fas fa-align-left me-2"></i><?php echo trans('inventory_category', 'description'); ?>
                                </label>
                                <textarea class="form-control" id="description" name="description" rows="3" 
                                          maxlength="500"><?php echo htmlspecialchars($category['dcmt_description'] ?? ''); ?></textarea>
                            </div>
                            

                            
                            <div class="dcmt-form-actions">
                                <a href="index.php" class="btn dcmt-btn-cancel">
                                    <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
                                </a>
                                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                                    <i class="fas fa-plus"></i><?php echo trans('inventory_category', 'update_category_record'); ?>
                                </button>
                            </div>
                        </form>
        </div>
    </div>
    
    <script>
    // Define resetForm function first
    function resetForm() {
        // Get form element
        const form = document.querySelector('form');
        if (!form) {
            return;
        }
        
        // Reset form immediately
        form.reset();
        
        // Use setTimeout to ensure form.reset() completes before manual field setting
        setTimeout(() => {
            // Manually clear and set specific fields to ensure proper reset
            const fields = {
                'name': '<?php echo addslashes($category['dcmt_name'] ?? ''); ?>',
                'description': '<?php echo addslashes($category['dcmt_description'] ?? ''); ?>',
                'status': '<?php echo $category['dcmt_status'] ?? 'active'; ?>',
                'product_type': '<?php echo $category['dcmt_product_type'] ?? 'for_sale'; ?>'
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
    }

    // Initialize everything when DOM is loaded
    document.addEventListener('DOMContentLoaded', function() {
        // Get form elements
        const form = document.querySelector('form');
        const submitBtn = document.getElementById('submitBtn');
        const resetBtn = document.getElementById('resetBtn');
        
        if (!form || !resetBtn) {
            return;
        }
        
        // Add reset button event listener
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            resetForm();
        });
    });
    </script>

    <?php include '../../includes/footer.php'; ?>
    

</body>
</html>
