<?php
/**
 * Edit Income Payment Method Page
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

// Get payment method ID from URL
$payment_method_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($payment_method_id <= 0) {
    header('Location: index.php');
    exit();
}

// Initialize errors array
$errors = [];

// Fetch payment method details BEFORE processing
try {
    $sql = "SELECT * FROM dcmt_income_payment_methods WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$payment_method_id]);
    $payment_method = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$payment_method) {
        header('Location: index.php');
        exit();
    }
    
    // Check if this is a system default
    $is_system_default = ($payment_method['dcmt_created_by'] === 'system');
} catch (PDOException $e) {
    error_log("Error fetching payment method: " . $e->getMessage());
    header('Location: index.php');
    exit();
}

// Handle form submission BEFORE including header
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
            $name = $is_system_default ? $payment_method['dcmt_name'] : dcmt_sanitize_input($_POST['name']);
            $description = dcmt_sanitize_input($_POST['description']);
            $status = dcmt_sanitize_input($_POST['status']);
            
            // Validate name length (skip for system defaults)
            if (!$is_system_default && strlen($name) > 100) {
                $errors[] = trans('income_payment_method', 'name_too_long');
            }
            
            // Check for duplicate name (excluding current record and system defaults)
            if (!$is_system_default) {
                try {
                    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE dcmt_name = ? AND dcmt_id != ?");
                    $stmt->execute([$name, $payment_method_id]);
                    if ($stmt->fetch()) {
                        $errors[] = trans('income_payment_method', 'name_exists');
                    }
                } catch (PDOException $e) {
                    $errors[] = trans('income_payment_method', 'database_error');
                }
            }
            
            // If no validation errors, update database
            if (empty($errors)) {
                try {
                    $sql = "UPDATE dcmt_income_payment_methods SET dcmt_description = ?, dcmt_status = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $description,
                        $status,
                        $payment_method_id
                    ]);
                    
                    // Log activity
                    $display_name = $is_system_default ? $name : htmlspecialchars($name);
                    dcmt_log_activity("Income payment method updated: $display_name", "Income Payment Method ID: $payment_method_id, Name: $display_name, Status: $status");
                    
                    // Set success message and redirect BEFORE any output
                    dcmt_show_message(trans('income_payment_method', 'update_success'), "success");
                    header('Location: index.php');
                    exit();
                    
                } catch (PDOException $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                    dcmt_log_activity("Error updating income payment method: " . $e->getMessage(), "error");
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

// Include header after form processing
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<?php
// Payment method is already fetched above

// Prepare form data
// If this is a system default, use translation for display
$display_name = $payment_method['dcmt_name'];
if ($is_system_default) {
    $translated_name = trans('income_payment_method', $display_name);
    $display_name = ($translated_name !== $display_name) ? $translated_name : $display_name;
}

$form_data = [
    'name' => $_POST['name'] ?? $payment_method['dcmt_name'],
    'display_name' => $display_name, // Translated name for display
    'description' => $_POST['description'] ?? $payment_method['dcmt_description'],
    'status' => $_POST['status'] ?? $payment_method['dcmt_status']
];

// Check if this is a system default (created by system)
$is_system_default = ($payment_method['dcmt_created_by'] === 'system');

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('income_payment_method', 'edit_payment_method'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('income_payment_method', 'view_all_payment_methods'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="paymentMethodForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-8">
                    <div class="mb-3">
                        <label for="name" class="form-label"><?php echo trans('income_payment_method', 'name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="name" name="name" 
                               value="<?php echo htmlspecialchars($is_system_default ? $form_data['display_name'] : $form_data['name']); ?>" 
                               <?php echo $is_system_default ? 'readonly' : 'required'; ?> 
                               maxlength="100" placeholder="<?php echo trans('income_payment_method', 'enter_name'); ?>"
                               style="<?php echo $is_system_default ? 'background-color: #f8f9fa; cursor: not-allowed;' : ''; ?>">
                        <?php if ($is_system_default): ?>
                            <div class="form-text text-warning">
                                <i class="fas fa-lock me-1"></i><?php echo trans('income_payment_method', 'name_locked'); ?>
                            </div>
                        <?php else: ?>
                            <div class="form-text"><?php echo trans('income_payment_method', 'name_help'); ?></div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="status" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="active" <?php echo $form_data['status'] === 'active' ? 'selected' : ''; ?>>
                                <?php echo trans('common', 'active'); ?>
                            </option>
                            <option value="inactive" <?php echo $form_data['status'] === 'inactive' ? 'selected' : ''; ?>>
                                <?php echo trans('common', 'inactive'); ?>
                            </option>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12">
                    <div class="mb-3">
                        <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
                        <textarea class="form-control" id="description" name="description" 
                                  rows="3" maxlength="500" placeholder="<?php echo trans('income_payment_method', 'enter_description'); ?>"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        <div class="form-text"><?php echo trans('income_payment_method', 'description_help'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="dcmt-form-actions">
                <a href="index.php" class="btn dcmt-btn-cancel">
                    <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
                </a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i><?php echo trans('income_payment_method', 'update_payment_method_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Define resetForm function first
function resetForm() {
    // Reset form to original values
    const form = document.getElementById('paymentMethodForm');
    if (form) {
        form.reset();
    }
    
    // Manually set original values
    const originalValues = {
        'name': '<?php echo htmlspecialchars($payment_method['dcmt_name']); ?>',
        'description': '<?php echo htmlspecialchars($payment_method['dcmt_description']); ?>',
        'status': '<?php echo htmlspecialchars($payment_method['dcmt_status']); ?>'
    };
    
    // Set each field to original value
    Object.keys(originalValues).forEach(fieldId => {
        const field = document.getElementById(fieldId);
        if (field) {
            field.value = originalValues[fieldId];
        }
    });
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    
    // Reset button functionality
    const resetBtn = document.getElementById('resetBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', function() {
            if (confirm('<?php echo trans('income_payment_method', 'confirm_reset'); ?>')) {
                resetForm();
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
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo trans('income_payment_method', 'updating'); ?>...';
            }
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
