<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$errors = [];
$success_message = '';

// Fetch inventory categories from database
try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_inventory_categories WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $stmt->execute();
    $categories = $stmt->fetchAll();
    
    // Create a map for easy lookup
    $category_map = [];
    foreach ($categories as $cat) {
        $category_map[$cat['dcmt_id']] = $cat['dcmt_name'];
    }
} catch (PDOException $e) {
    error_log("Error fetching inventory categories: " . $e->getMessage());
    $categories = [];
    $category_map = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Validate required fields
        $required_fields = ['name', 'sku', 'category_id', 'quantity', 'min_quantity', 'price', 'status'];
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if ($validation_result['valid']) {
            // Sanitize and validate input
            $name = dcmt_sanitize_input($_POST['name']);
            $sku = dcmt_sanitize_input($_POST['sku']);
            $category_id = dcmt_sanitize_input($_POST['category_id']);
            $quantity = dcmt_sanitize_input($_POST['quantity']);
            $min_quantity = dcmt_sanitize_input($_POST['min_quantity']);
            $price = dcmt_sanitize_input($_POST['price']);
            $status = dcmt_sanitize_input($_POST['status']);
            $description = isset($_POST['description']) ? dcmt_sanitize_input($_POST['description']) : '';
            $brand = isset($_POST['brand']) ? dcmt_sanitize_input($_POST['brand']) : '';
            $supplier = isset($_POST['supplier']) ? dcmt_sanitize_input($_POST['supplier']) : '';
            $expiry_date = isset($_POST['expiry_date']) && !empty($_POST['expiry_date']) ? dcmt_sanitize_input($_POST['expiry_date']) : null;
            
            // Validate numeric fields
            $quantity_error = dcmt_validate_numeric_field($quantity, 'Quantity');
            if ($quantity_error) {
                $errors[] = $quantity_error;
            }
            
            $min_quantity_error = dcmt_validate_numeric_field($min_quantity, 'Minimum Quantity');
            if ($min_quantity_error) {
                $errors[] = $min_quantity_error;
            }
            
            $price_error = dcmt_validate_numeric_field($price, 'Price');
            if ($price_error) {
                $errors[] = $price_error;
            }
            
            // Validate ranges
            if ($quantity < 0) {
                $errors[] = trans('inventory', 'quantity_negative');
            }
            
            if ($min_quantity < 0) {
                $errors[] = trans('inventory', 'min_quantity_negative');
            }
            
            if ($price < 0) {
                $errors[] = trans('inventory', 'price_negative');
            }
            
            // Check if SKU already exists
            try {
                $sku_check_sql = "SELECT COUNT(*) FROM dcmt_inventory WHERE dcmt_sku = ?";
                $sku_check_stmt = $dcmt_pdo->prepare($sku_check_sql);
                $sku_check_stmt->execute([$sku]);
                if ($sku_check_stmt->fetchColumn() > 0) {
                    $errors[] = trans('inventory', 'sku_exists');
                }
            } catch (PDOException $e) {
                $errors[] = trans('inventory', 'sku_check_error') . ': ' . $e->getMessage();
            }
            
            // If no validation errors, insert into database
            if (empty($errors)) {
                try {
                    $sql = "INSERT INTO dcmt_inventory (dcmt_name, dcmt_brand, dcmt_sku, dcmt_description, dcmt_category_id, dcmt_quantity, dcmt_min_quantity, dcmt_price, dcmt_supplier, dcmt_expiry_date, dcmt_status, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $name,
                        $brand,
                        $sku,
                        $description,
                        $category_id,
                        $quantity,
                        $min_quantity,
                        $price,
                        $supplier,
                        $expiry_date,
                        $status,
                        dcmt_get_current_user()['dcmt_username']
                    ]);
                    
                    $inventory_id = $dcmt_pdo->lastInsertId();
                    
                    // Log activity
                    $category_name = isset($category_map[$category_id]) ? $category_map[$category_id] : 'Unknown';
                    $log_details = "Inventory ID: $inventory_id | Name: $name | Brand: " . ($brand ?: 'Empty') . " | SKU: $sku | Category: $category_name | Qty: $quantity | Min Qty: $min_quantity | Price: " . dcmt_format_currency($price) . " | Status: $status";
                    dcmt_log_activity("Inventory Added", $log_details);
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('inventory', 'add_success'), 'success');
                    dcmt_redirect("index.php");
                    
                } catch (PDOException $e) {
                    $errors[] = trans('inventory', 'database_error') . ': ' . $e->getMessage();
                    dcmt_log_activity("Error adding inventory item: " . $e->getMessage(), "error");
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Predefined statuses
$statuses = [
    'active' => 'Active',
    'inactive' => 'Inactive',
    'discontinued' => 'Discontinued'
];

// Generate default SKU
$default_sku = 'INV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -4));

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>

<link href="../../assets/css/add-income.css" rel="stylesheet">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('inventory', 'add_inventory_item'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('inventory', 'view_all_inventory'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="inventoryForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
        <div class="row">
            <div class="col-md-8">
                <div class="mb-3">
                    <label for="name" class="form-label"><?php echo trans('inventory', 'item_name'); ?> *</label>
                    <input type="text" class="form-control" id="name" name="name" 
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" 
                           required maxlength="255" placeholder="<?php echo trans('inventory', 'enter_item_name'); ?>">
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="sku" class="form-label"><?php echo trans('inventory', 'sku'); ?> *</label>
                    <input type="text" class="form-control" id="sku" name="sku" 
                           value="<?php echo isset($_POST['sku']) ? htmlspecialchars($_POST['sku']) : $default_sku; ?>" 
                           required maxlength="50" placeholder="<?php echo trans('inventory', 'enter_sku'); ?>">
                </div>
            </div>
        </div>
            
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="category_id" class="form-label"><?php echo trans('inventory', 'category'); ?> *</label>
                    <select class="form-select" id="category_id" name="category_id" required>
                        <option value=""><?php echo trans('inventory', 'select_category'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <?php 
                            // Translate category name
                            $cat_name = $cat['dcmt_name'];
                            $translated_cat = trans('inventory_category', $cat_name);
                            $display_cat = ($translated_cat !== $cat_name) ? $translated_cat : $cat_name;
                            ?>
                            <option value="<?php echo $cat['dcmt_id']; ?>" 
                                    <?php echo (isset($_POST['category_id']) && $_POST['category_id'] == $cat['dcmt_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($display_cat); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($categories)): ?>
                        <div class="form-text">
                            <a href="../inventory_categories/add.php" class="text-primary">
                                <i class="fas fa-plus"></i> <?php echo trans('inventory', 'add_categories'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="status" class="form-label"><?php echo trans('inventory', 'status'); ?> *</label>
                    <select class="form-select" id="status" name="status" required>
                        <option value=""><?php echo trans('inventory', 'select_status'); ?></option>
                        <?php foreach ($statuses as $key => $status): ?>
                            <option value="<?php echo $key; ?>" 
                                    <?php echo (isset($_POST['status']) && $_POST['status'] === $key) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($status); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
            
        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="quantity" class="form-label"><?php echo trans('inventory', 'current_quantity'); ?> *</label>
                    <input type="number" class="form-control" id="quantity" name="quantity" 
                           value="<?php echo isset($_POST['quantity']) ? htmlspecialchars($_POST['quantity']) : '0'; ?>" 
                           required min="0" step="1" placeholder="0">
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="min_quantity" class="form-label"><?php echo trans('inventory', 'minimum_quantity'); ?> *</label>
                    <input type="number" class="form-control" id="min_quantity" name="min_quantity" 
                           value="<?php echo isset($_POST['min_quantity']) ? htmlspecialchars($_POST['min_quantity']) : '5'; ?>" 
                           required min="0" step="1" placeholder="5">
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="price" class="form-label"><?php echo trans('inventory', 'unit_price'); ?> *</label>
                    <div class="dcmt-amount-input-wrapper">
                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                        <input type="number" class="form-control dcmt-amount-input" id="price" name="price" 
                               value="<?php echo isset($_POST['price']) ? htmlspecialchars($_POST['price']) : ''; ?>" 
                               required step="0.01" min="0" placeholder="<?php echo trans('common', 'amount'); ?>">
                    </div>
                </div>
            </div>
        </div>
            
        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="brand" class="form-label"><?php echo trans('inventory', 'brand'); ?></label>
                    <input type="text" class="form-control" id="brand" name="brand" 
                           value="<?php echo isset($_POST['brand']) ? htmlspecialchars($_POST['brand']) : ''; ?>" 
                           maxlength="100" placeholder="<?php echo trans('inventory', 'enter_brand'); ?>">
                    <div class="form-text"><?php echo trans('inventory', 'brand_help'); ?></div>
                </div>
            </div>

            <div class="col-md-4">
                <div class="mb-3">
                    <label for="supplier" class="form-label"><?php echo trans('inventory', 'supplier'); ?></label>
                    <input type="text" class="form-control" id="supplier" name="supplier" 
                           value="<?php echo isset($_POST['supplier']) ? htmlspecialchars($_POST['supplier']) : ''; ?>" 
                           maxlength="255" placeholder="<?php echo trans('inventory', 'enter_supplier'); ?>">
                </div>
            </div>

            <div class="col-md-4">
                <div class="mb-3">
                    <label for="expiry_date" class="form-label"><?php echo trans('inventory', 'expiry_date'); ?></label>
                    <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                           value="<?php echo isset($_POST['expiry_date']) ? htmlspecialchars($_POST['expiry_date']) : ''; ?>" 
                           placeholder="<?php echo trans('inventory', 'enter_expiry_date'); ?>">
                    <div class="form-text"><?php echo trans('inventory', 'expiry_date_help'); ?></div>
                </div>
            </div>
        </div>
            
        <div class="row">
            <div class="col-md-12">
                <div class="mb-3">
                    <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
                    <textarea class="form-control" id="description" name="description" rows="3" 
                              placeholder="<?php echo trans('inventory', 'enter_description'); ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                </div>
            </div>
        </div>
            
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                <i class="fas fa-undo"></i><?php echo trans('inventory', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('inventory', 'add_inventory_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
// Define reset function with project prefix to avoid collisions
function dcmt_resetInventoryForm() {
    // Reset form immediately
    const form = document.getElementById('inventoryForm');
    if (form) {
        form.reset();
    }

    // Manually clear and set specific fields to ensure proper reset
    const fields = {
        'name': '',
        'sku': '<?php echo $default_sku; ?>',
        'description': '',
        'category_id': '',
        'quantity': '0',
        'min_quantity': '5',
        'price': '',
        'brand': '',
        'supplier': '',
        'expiry_date': '',
        'status': 'active'
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
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('inventory', 'add_inventory_record'); ?>';
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
    const form = document.getElementById('inventoryForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !resetBtn) {
        return;
    }
    
    // Add form submission loading state and validation
    form.addEventListener('submit', function(e) {
        const originalText = submitBtn.innerHTML;
        
        // Form validation
        const quantity = parseInt(document.getElementById('quantity').value);
        const minQuantity = parseInt(document.getElementById('min_quantity').value);
        const price = parseFloat(document.getElementById('price').value);
        
        if (quantity < 0) {
            e.preventDefault();
            alert('<?php echo trans('inventory', 'quantity_negative'); ?>');
            document.getElementById('quantity').focus();
            return false;
        }
        
        if (minQuantity < 0) {
            e.preventDefault();
            alert('<?php echo trans('inventory', 'min_quantity_negative'); ?>');
            document.getElementById('min_quantity').focus();
            return false;
        }
        
        if (price < 0) {
            e.preventDefault();
            alert('<?php echo trans('inventory', 'price_negative'); ?>');
            document.getElementById('price').focus();
            return false;
        }
        
        // Show loading state
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
        
        // Store original text for potential restoration
        submitBtn.setAttribute('data-original-text', originalText);
    });
    
    // Restore button state if form validation fails
    const originalText = submitBtn.getAttribute('data-original-text');
    if (originalText) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
    
    // Auto-generate SKU if empty
    const skuField = document.getElementById('sku');
    if (!skuField.value) {
        skuField.value = '<?php echo $default_sku; ?>';
    }
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('<?php echo trans('inventory', 'confirm_reset'); ?>')) {
            dcmt_resetInventoryForm();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
