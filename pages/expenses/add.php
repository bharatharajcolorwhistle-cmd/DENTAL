<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$errors = [];
$success_message = '';

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Validate required fields
        $required_fields = ['title', 'category_id', 'amount', 'payment_method_id', 'expense_date'];
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if ($validation_result['valid']) {
            // Sanitize and validate input
            $title = dcmt_sanitize_input($_POST['title']);
            $description = dcmt_sanitize_input($_POST['description']);
            $category_id = dcmt_sanitize_input($_POST['category_id']);
            $amount = dcmt_sanitize_input($_POST['amount']);
            $payment_method_id = !empty($_POST['payment_method_id']) ? intval($_POST['payment_method_id']) : null;
            $expense_date = dcmt_sanitize_input($_POST['expense_date']);
            
            // Validate amount
            $amount_error = dcmt_validate_numeric_field($amount, 'Amount');
            if ($amount_error) {
                $errors[] = $amount_error;
            }
            
            // Validate date
            $date_error = dcmt_validate_date_field($expense_date, 'Expense Date');
            if ($date_error) {
                $errors[] = $date_error;
            }
            
            // Validate amount range
            if ($amount <= 0) {
                $errors[] = trans('expense', 'amount_required');
            }
            
            // If no validation errors, insert into database
            if (empty($errors)) {
                try {
                    $sql = "INSERT INTO dcmt_expenses (dcmt_title, dcmt_description, dcmt_category_id, dcmt_amount, dcmt_payment_method_id, dcmt_expense_date, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, NOW())";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $title,
                        $description,
                        $category_id,
                        $amount,
                        $payment_method_id,
                        $expense_date,
                        dcmt_get_current_user()['dcmt_username']
                    ]);
                    
                    $expense_id = $dcmt_pdo->lastInsertId();
                    
                    // Log activity
                    dcmt_log_activity("Expense added: $title - " . dcmt_format_currency($amount), "Expense ID: $expense_id, Title: $title, Amount: " . dcmt_format_currency($amount));
                    
                    // Set success message and redirect
                    dcmt_show_message(trans('expense', 'add_success'), 'success');
                    dcmt_redirect("index.php");
                    
                } catch (PDOException $e) {
                    $errors[] = trans('expense', 'database_error') . ': ' . $e->getMessage();
                    dcmt_log_activity("Error adding expense: " . $e->getMessage(), "error");
                }
            }
        } else {
            $errors = array_merge($errors, $validation_result['errors']);
        }
    }
}

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Fetch expense categories from database
try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_expense_categories WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $stmt->execute();
    $categories = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching expense categories: " . $e->getMessage());
    $categories = [];
}

// Fetch expense payment methods from database
try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_expense_payment_methods WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $stmt->execute();
    $expense_payment_methods = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching expense payment methods: " . $e->getMessage());
    $expense_payment_methods = [];
}

// Payment methods are now fetched from database above

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('expense', 'add_expense'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('expense', 'view_all_expenses'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="expenseForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="expense_date" class="form-label"><?php echo trans('expense', 'expense_date'); ?> *</label>
                    <input type="date" class="form-control" id="expense_date" name="expense_date" 
                           value="<?php echo isset($_POST['expense_date']) ? htmlspecialchars($_POST['expense_date']) : dcmt_get_current_date(); ?>" 
                           required>
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="title" class="form-label"><?php echo trans('expense', 'title'); ?> *</label>
                    <input type="text" class="form-control" id="title" name="title" 
                           value="<?php echo isset($_POST['title']) ? htmlspecialchars($_POST['title']) : ''; ?>" 
                           required maxlength="100" placeholder="<?php echo trans('expense', 'enter_title'); ?>">
                </div>
            </div>
            
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="category_id" class="form-label"><?php echo trans('expense', 'category'); ?> *</label>
                    <select class="form-select" id="category_id" name="category_id" required>
                        <option value=""><?php echo trans('expense', 'select_category'); ?></option>
                        <?php foreach ($categories as $cat): ?>
                            <?php 
                            // Translate category name
                            $cat_name = $cat['dcmt_name'];
                            $translated_cat = trans('expense_category', $cat_name);
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
                            <a href="../expense_categories/add.php" class="text-primary">
                                <i class="fas fa-plus"></i> <?php echo trans('expense', 'add_categories'); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
        
        <div class="mb-3">
            <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
            <textarea class="form-control" id="description" name="description" rows="3"
                      placeholder="<?php echo trans('expense', 'enter_description'); ?>"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
        </div>
        
        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="amount" class="form-label"><?php echo trans('expense', 'amount'); ?> *</label>
                    <div class="dcmt-amount-input-wrapper">
                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                        <input type="number" class="form-control dcmt-amount-input" id="amount" name="amount" 
                               value="<?php echo isset($_POST['amount']) ? htmlspecialchars($_POST['amount']) : ''; ?>" 
                               required step="0.01" min="0.01" placeholder="<?php echo trans('common', 'amount'); ?>">
                    </div>
                </div>
            </div>
            
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="payment_method_id" class="form-label"><?php echo trans('expense', 'payment_method'); ?> *</label>
                    <select class="form-select" id="payment_method_id" name="payment_method_id" required>
                        <option value=""><?php echo trans('expense', 'select_payment_method'); ?></option>
                        <?php foreach ($expense_payment_methods as $method): ?>
                            <?php 
                            // Translate payment method name
                            $method_name = $method['dcmt_name'];
                            $translated_method = trans('expense_payment_method', $method_name);
                            $display_method = ($translated_method !== $method_name) ? $translated_method : $method_name;
                            ?>
                            <option value="<?php echo $method['dcmt_id']; ?>" 
                                    <?php echo (isset($_POST['payment_method_id']) && $_POST['payment_method_id'] == $method['dcmt_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($display_method); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>
        
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                <i class="fas fa-undo"></i><?php echo trans('expense', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times-circle"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('expense', 'add_expense_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
// Function to get current date in Mexico timezone
function getMexicoDate() {
    const now = new Date();
    // Mexico is UTC-6, so we need to adjust for the timezone
    const mexicoTime = new Date(now.getTime() - (6 * 60 * 60 * 1000));
    return mexicoTime.toISOString().split('T')[0];
}

// Define reset function with project prefix to avoid collisions
function dcmt_resetExpenseForm() {
    // Clear form changed flag since user is resetting
    dcmtClearFormChanged();
    
    // Reset form immediately
    const form = document.getElementById('expenseForm');
    if (form) {
        form.reset();
    }
    
    // Manually clear and set specific fields to ensure proper reset
    const fields = {
        'title': '',
        'description': '',
        'amount': '',
        'category_id': '',
        'payment_method_id': '',
        'expense_date': getMexicoDate()
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
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('expense', 'add_expense_record'); ?>';
    }

    // Focus first field
    const titleField = document.getElementById('title');
    if (titleField) {
        titleField.focus();
    }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Ensure localized unsaved-changes message is available for main.js confirm
    (function(){
        var t = window.translations || {};
        if (!t.unsaved_changes_message) {
            t.unsaved_changes_message = <?php echo json_encode(trans('common','unsaved_changes_message')); ?>;
        }
        window.translations = t;
    })();
    // Initialize form change detection
    dcmtInitFormChangeDetection('expenseForm');
    // Get form elements
    const form = document.getElementById('expenseForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !resetBtn) {
        return;
    }
    
    // Add form submission loading state and validation
    form.addEventListener('submit', function(e) {
        const originalText = submitBtn.innerHTML;
        
        // Form validation
        const amount = parseFloat(document.getElementById('amount').value);
        if (amount <= 0) {
            e.preventDefault();
            alert('<?php echo trans('expense', 'amount_required'); ?>');
            document.getElementById('amount').focus();
            return false;
        }
        
        // Clear form changed flag since we're submitting
        dcmtClearFormChanged();
        
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
    
    // Set default date to today if not set
    if (!document.getElementById('expense_date').value) {
        document.getElementById('expense_date').value = getMexicoDate();
    }
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm('<?php echo trans('expense', 'confirm_reset'); ?>')) {
            dcmt_resetExpenseForm();
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
