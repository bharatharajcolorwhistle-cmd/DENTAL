<?php
/**
 * Edit Service Page
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

// Get service ID from URL
$service_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($service_id <= 0) {
    dcmt_show_message(trans('service', 'invalid_service_id'), "error");
    dcmt_redirect("index.php");
    exit();
}

$errors = [];

// Fetch service details
try {
    $sql = "SELECT * FROM dcmt_services WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$service_id]);
    $service = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$service) {
        dcmt_show_message(trans('service', 'service_not_found'), "error");
        dcmt_redirect("index.php");
        exit();
    }
} catch (PDOException $e) {
    dcmt_show_message(trans('service', 'database_error'), "error");
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
            
            // Check if service name already exists (excluding current service)
            try {
                $check_stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_services WHERE dcmt_name = ? AND dcmt_id != ?");
                $check_stmt->execute([$name, $service_id]);
                if ($check_stmt->fetch()) {
                    $errors[] = trans('service', 'name_exists');
                }
            } catch (PDOException $e) {
                error_log("Error checking service name: " . $e->getMessage());
            }
            
            // If no validation errors, update database
            if (empty($errors)) {
                try {
                    $old_base_price = isset($service['dcmt_base_price']) ? (float)$service['dcmt_base_price'] : null;
                    $new_base_price = (float)$base_price;

                    $sql = "UPDATE dcmt_services 
                            SET dcmt_name = ?, dcmt_description = ?, dcmt_base_price = ?, dcmt_status = ?, dcmt_updated_at = NOW() 
                            WHERE dcmt_id = ?";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $name,
                        $description,
                        $base_price,
                        $status,
                        $service_id
                    ]);
                    
                    if ($old_base_price !== null && $new_base_price !== $old_base_price) {
                        $sync_stmt = $dcmt_pdo->prepare("
                            UPDATE dcmt_doctor_services
                            SET dcmt_price = ?, dcmt_updated_at = NOW()
                            WHERE dcmt_service_id = ? AND dcmt_price = ?
                        ");
                        $sync_stmt->execute([
                            $new_base_price,
                            $service_id,
                            $old_base_price
                        ]);
                    }
                    
                    // Log activity
                    dcmt_log_activity("Service updated: $name - Price: " . dcmt_format_currency($base_price), "service_updated");
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('service', 'update_success'), "success");
                    dcmt_redirect("index.php");
                    exit();
                    
                } catch (PDOException $e) {
                    $errors[] = trans('service', 'update_failed');
                    dcmt_log_activity("Error updating service: " . $e->getMessage(), "error");
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Use POST data if available, otherwise use existing service data
$form_data = [
    'name' => $_POST['name'] ?? $service['dcmt_name'],
    'description' => $_POST['description'] ?? $service['dcmt_description'],
    'base_price' => $_POST['base_price'] ?? $service['dcmt_base_price'],
    'status' => $_POST['status'] ?? $service['dcmt_status']
];

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('service', 'edit_service'); ?></h1>
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
                           value="<?php echo htmlspecialchars($form_data['name']); ?>" 
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
                               value="<?php echo htmlspecialchars($form_data['base_price']); ?>" 
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
                        <option value="active" <?php echo $form_data['status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                        <option value="inactive" <?php echo $form_data['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3" 
                      placeholder="<?php echo trans('service', 'enter_description'); ?>"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
            <div class="form-text"><?php echo trans('service', 'description_help'); ?></div>
        </div>
        
        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('service', 'update_service_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize form change detection
    dcmtInitFormChangeDetection('serviceForm');
    
    const form = document.getElementById('serviceForm');
    const submitBtn = document.getElementById('submitBtn');
    
    if (!form || !submitBtn) {
        return;
    }
    
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
