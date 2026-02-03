<?php
/**
 * Edit Inventory Item Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check authentication
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Get inventory ID from URL
$inventory_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($inventory_id <= 0) {
    dcmt_show_message(trans('inventory', 'invalid_inventory_id'), "error");
    dcmt_redirect("index.php");
}

$errors = [];
$success_message = '';

// Fetch inventory item details
try {
    $sql = "SELECT * FROM dcmt_inventory WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$inventory_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        dcmt_show_message(trans('inventory', 'inventory_not_found'), "error");
        dcmt_redirect("index.php");
    }
} catch (PDOException $e) {
    dcmt_show_message(trans('inventory', 'database_error'), "error");
    dcmt_redirect("index.php");
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
            $category = dcmt_sanitize_input($_POST['category_id']);
            $quantity = dcmt_sanitize_input($_POST['quantity']);
            $min_quantity = dcmt_sanitize_input($_POST['min_quantity']);
            $price = dcmt_sanitize_input($_POST['price']);
            $status = dcmt_sanitize_input($_POST['status']);
            $description = isset($_POST['description']) ? dcmt_sanitize_input($_POST['description']) : '';
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
            
            // Check if SKU already exists (excluding current item)
            if ($sku !== $item['dcmt_sku']) {
                try {
                    $sku_check_sql = "SELECT COUNT(*) FROM dcmt_inventory WHERE dcmt_sku = ? AND dcmt_id != ?";
                    $sku_check_stmt = $dcmt_pdo->prepare($sku_check_sql);
                    $sku_check_stmt->execute([$sku, $inventory_id]);
                    if ($sku_check_stmt->fetchColumn() > 0) {
                        $errors[] = trans('inventory', 'sku_exists');
                    }
                } catch (PDOException $e) {
                    $errors[] = trans('inventory', 'sku_check_error');
                }
            }
            
            // If no validation errors, update database
            if (empty($errors)) {
                try {
                    $sql = "UPDATE dcmt_inventory SET dcmt_name = ?, dcmt_sku = ?, dcmt_description = ?, dcmt_category_id = ?, dcmt_quantity = ?, dcmt_min_quantity = ?, dcmt_price = ?, dcmt_supplier = ?, dcmt_expiry_date = ?, dcmt_status = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $name,
                        $sku,
                        $description,
                        $category,
                        $quantity,
                        $min_quantity,
                        $price,
                        $supplier,
                        $expiry_date,
                        $status,
                        $inventory_id
                    ]);
                    
                    // Log activity
                    dcmt_log_activity("Inventory item updated: $name - SKU: $sku", "inventory_updated");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('inventory', 'update_success'), "success");
                    dcmt_redirect("view.php?id=" . $inventory_id);
                    exit();
                    
                } catch (PDOException $e) {
                    $errors[] = trans('inventory', 'database_error');
                    dcmt_log_activity("Error updating inventory item: " . $e->getMessage(), "error");
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

// Now include the header
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<?php
// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Fetch inventory categories from database
try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_inventory_categories WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching inventory categories: " . $e->getMessage());
    $categories = [];
}

// Predefined statuses
$statuses = [
    'active' => 'Active',
    'inactive' => 'Inactive',
    'discontinued' => 'Discontinued'
];

// Use POST data if available, otherwise use existing item data
$form_data = [
    'name' => $_POST['name'] ?? $item['dcmt_name'],
    'sku' => $_POST['sku'] ?? $item['dcmt_sku'],
    'category_id' => $_POST['category_id'] ?? $item['dcmt_category_id'],
    'quantity' => $_POST['quantity'] ?? $item['dcmt_quantity'],
    'min_quantity' => $_POST['min_quantity'] ?? $item['dcmt_min_quantity'],
    'price' => $_POST['price'] ?? $item['dcmt_price'],
    'status' => $_POST['status'] ?? $item['dcmt_status'],
    'description' => $_POST['description'] ?? $item['dcmt_description'],
    'supplier' => $_POST['supplier'] ?? $item['dcmt_supplier'],
    'expiry_date' => $_POST['expiry_date'] ?? $item['dcmt_expiry_date']
];
?>

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('inventory', 'edit_inventory_item'); ?></h1>
            <a href="view.php?id=<?php echo $inventory_id; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('common', 'view'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="inventoryForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo trans('inventory', 'item_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo htmlspecialchars($form_data['name']); ?>" 
                               required maxlength="255" placeholder="<?php echo trans('inventory', 'enter_item_name'); ?>">
                        <div class="form-text"><?php echo trans('inventory', 'item_name_help'); ?></div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="sku" class="form-label"><?php echo trans('inventory', 'sku'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="sku" name="sku" 
                               value="<?php echo htmlspecialchars($form_data['sku']); ?>" 
                               required maxlength="50" placeholder="<?php echo trans('inventory', 'enter_sku'); ?>">
                        <div class="form-text"><?php echo trans('inventory', 'sku_help'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="category_id" class="form-label"><?php echo trans('inventory', 'category'); ?> <span class="text-danger">*</span></label>
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
                                        <?php echo $form_data['category_id'] == $cat['dcmt_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($display_cat); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status" class="form-label"><?php echo trans('inventory', 'status'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value=""><?php echo trans('inventory', 'select_status'); ?></option>
                            <?php foreach ($statuses as $key => $status): ?>
                                <option value="<?php echo $key; ?>" 
                                        <?php echo $form_data['status'] === $key ? 'selected' : ''; ?>>
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
                        <label for="quantity" class="form-label"><?php echo trans('inventory', 'current_quantity'); ?> <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="quantity" name="quantity" 
                               value="<?php echo htmlspecialchars($form_data['quantity']); ?>" 
                               required min="0" step="1" placeholder="0">
                        <div class="form-text"><?php echo trans('inventory', 'current_stock_quantity'); ?></div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="min_quantity" class="form-label"><?php echo trans('inventory', 'minimum_quantity'); ?> <span class="text-danger">*</span></label>
                        <input type="number" class="form-control" id="min_quantity" name="min_quantity" 
                               value="<?php echo htmlspecialchars($form_data['min_quantity']); ?>" 
                               required min="0" step="1" placeholder="5">
                        <div class="form-text"><?php echo trans('inventory', 'low_stock_alert_threshold'); ?></div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="price" class="form-label"><?php echo trans('inventory', 'unit_price'); ?> *</label>
                        <div class="dcmt-amount-input-wrapper">
                            <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                            <input type="number" class="form-control dcmt-amount-input" id="price" name="price" 
                                   value="<?php echo htmlspecialchars($form_data['price']); ?>" 
                                   required step="0.01" min="0" placeholder="0.00">
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="supplier" class="form-label"><?php echo trans('inventory', 'supplier'); ?></label>
                        <input type="text" class="form-control" id="supplier" name="supplier" 
                               value="<?php echo htmlspecialchars($form_data['supplier']); ?>" 
                               maxlength="255" placeholder="<?php echo trans('inventory', 'enter_supplier'); ?>">
                        <div class="form-text"><?php echo trans('inventory', 'supplier_help'); ?></div>
                    </div>
                </div>
                
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="expiry_date" class="form-label"><?php echo trans('inventory', 'expiry_date'); ?></label>
                        <input type="date" class="form-control" id="expiry_date" name="expiry_date" 
                               value="<?php echo htmlspecialchars($form_data['expiry_date']); ?>" 
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
                                  placeholder="<?php echo trans('inventory', 'enter_description'); ?>"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        <div class="form-text"><?php echo trans('inventory', 'description_help'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="dcmt-form-actions">
                <a href="view.php?id=<?php echo $inventory_id; ?>" class="btn dcmt-btn-cancel"><?php echo trans('common', 'cancel'); ?></a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i><?php echo trans('inventory', 'update_inventory_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Define resetForm function first
function resetForm() {
    // Get form element
    const form = document.getElementById('inventoryForm');
    if (!form) {
        return;
    }
    
    // Reset form immediately
    form.reset();
    
    // Use setTimeout to ensure form.reset() completes before manual field setting
    setTimeout(() => {
        // Manually clear and set specific fields to ensure proper reset
        const fields = {
            'name': '<?php echo htmlspecialchars($item['dcmt_name']); ?>',
            'sku': '<?php echo htmlspecialchars($item['dcmt_sku']); ?>',
            'description': '<?php echo htmlspecialchars($item['dcmt_description']); ?>',
            'category_id': '<?php echo $item['dcmt_category_id']; ?>',
            'quantity': '<?php echo $item['dcmt_quantity']; ?>',
            'min_quantity': '<?php echo $item['dcmt_min_quantity']; ?>',
            'price': '<?php echo $item['dcmt_price']; ?>',
            'supplier': '<?php echo htmlspecialchars($item['dcmt_supplier']); ?>',
            'expiry_date': '<?php echo $item['dcmt_expiry_date']; ?>',
            'status': '<?php echo $item['dcmt_status']; ?>'
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
    const form = document.getElementById('inventoryForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !resetBtn) {
        return;
    }
    
    // Form validation
    form.addEventListener('submit', function(e) {
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
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
        
        // Store original text for potential restoration
        submitBtn.setAttribute('data-original-text', originalText);
    });
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        resetForm();
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
