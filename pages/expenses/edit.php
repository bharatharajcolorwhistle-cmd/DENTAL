<?php
/**
 * Edit Expense Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/expense_category_functions.php';

dcmt_require_admin_or_staff();

// Note: Database migration for dcmt_title column should be handled separately
// to avoid "headers already sent" errors

// Get expense ID from URL BEFORE form processing
$expense_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($expense_id <= 0) {
    // Don't use dcmt_show_message here as it might generate output
    // Just redirect directly
    header('Location: index.php');
    exit();
}

// Fetch existing expense details before form processing
$expense = null;
try {
    $sql = "SELECT * FROM dcmt_expenses WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch();
} catch (PDOException $e) {
    header('Location: index.php');
    exit();
}

// Initialize errors array
$errors = [];

// Fetch categories and payment methods early for logging names
$categories = [];
$expense_payment_methods = [];

try {
    $categories = dcmt_fetch_expense_categories_for_select($dcmt_pdo);
} catch (PDOException $e) {
    error_log("Error fetching expense categories: " . $e->getMessage());
}

try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_expense_payment_methods WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $stmt->execute();
    $expense_payment_methods = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Error fetching expense payment methods: " . $e->getMessage());
}

// Handle form submission BEFORE including header
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Validate required fields
        $required_fields = ['title', 'category_id', 'amount', 'payment_method_id', 'payment_status', 'expense_date'];
        $validation_result = dcmt_validate_required_fields($_POST, $required_fields);
        
        if ($validation_result['valid']) {
            // Sanitize and validate input
            $title = dcmt_sanitize_input($_POST['title']);
            $description = dcmt_sanitize_input($_POST['description']);
            $category = dcmt_sanitize_input($_POST['category_id']);
            $amount = dcmt_sanitize_input($_POST['amount']);
            $payment_method_id = !empty($_POST['payment_method_id']) ? intval($_POST['payment_method_id']) : null;
            $payment_status = dcmt_sanitize_input($_POST['payment_status']);
            $expense_date = dcmt_sanitize_input($_POST['expense_date']);
            
            // Validate amount
            $amount_error = dcmt_validate_numeric_field($amount, 'Amount');
            if ($amount_error) {
                $errors[] = $amount_error;
            }
            
            // Validate date
            $date_error = dcmt_validate_date_field($expense_date, trans('expense', 'expense_date'));
            if ($date_error) {
                $errors[] = $date_error;
            }
            
            // Validate amount range
            if ($amount <= 0) {
                $errors[] = 'Amount must be greater than zero.';
            }
            
            // Validate payment status
            $valid_payment_statuses = ['paid', 'pending', 'overdue'];
            if (!in_array($payment_status, $valid_payment_statuses)) {
                $errors[] = 'Invalid payment status selected.';
            }
            
            // If no validation errors, update database
            if (empty($errors)) {
                try {
                    $sql = "UPDATE dcmt_expenses SET dcmt_title = ?, dcmt_description = ?, dcmt_category_id = ?, dcmt_amount = ?, dcmt_payment_method_id = ?, dcmt_payment_status = ?, dcmt_expense_date = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                    
                    $stmt = $dcmt_pdo->prepare($sql);
                    $stmt->execute([
                        $title,
                        $description,
                        $category,
                        $amount,
                        $payment_method_id,
                        $payment_status,
                        $expense_date,
                        $expense_id
                    ]);
                    
                    // Track field changes for detailed logging
                    $expense_changes = [];
                    
                    // Check title change
                    if ($expense['dcmt_title'] !== $title) {
                        $expense_changes[] = "Title: " . $expense['dcmt_title'] . " → " . $title;
                    }

                    // Check amount change
                    if (abs($expense['dcmt_amount'] - $amount) > 0.01) {
                        $expense_changes[] = "Amount: " . dcmt_format_currency($expense['dcmt_amount']) . " → " . dcmt_format_currency($amount);
                    }
                    
                    // Check category change
                    if ($expense['dcmt_category_id'] != $category) {
                        $old_cat_name = 'Unknown';
                        $new_cat_name = 'Unknown';
                        foreach ($categories as $cat) {
                            if ($cat['dcmt_id'] == $expense['dcmt_category_id']) $old_cat_name = $cat['dcmt_name'];
                            if ($cat['dcmt_id'] == $category) $new_cat_name = $cat['dcmt_name'];
                        }
                        $expense_changes[] = "Category: " . $old_cat_name . " → " . $new_cat_name;
                    }

                    // Check payment method change
                    if ($expense['dcmt_payment_method_id'] != $payment_method_id) {
                        $old_method_name = 'Unknown';
                        $new_method_name = 'Unknown';
                        foreach ($expense_payment_methods as $method) {
                            if ($method['dcmt_id'] == $expense['dcmt_payment_method_id']) $old_method_name = $method['dcmt_name'];
                            if ($method['dcmt_id'] == $payment_method_id) $new_method_name = $method['dcmt_name'];
                        }
                        $expense_changes[] = "Payment Method: " . $old_method_name . " → " . $new_method_name;
                    }

                    // Check payment status change
                    if ($expense['dcmt_payment_status'] != $payment_status) {
                        $expense_changes[] = "Payment Status: " . ucfirst($expense['dcmt_payment_status']) . " → " . ucfirst($payment_status);
                    }
                    
                    // Check expense date change
                    if ($expense['dcmt_expense_date'] != $expense_date) {
                        $expense_changes[] = "Date: " . $expense['dcmt_expense_date'] . " → " . $expense_date;
                    }
                    
                    // Check description change
                    if ($expense['dcmt_description'] !== $description) {
                        // Truncate description for log if too long
                        $old_desc = strlen($expense['dcmt_description']) > 20 ? substr($expense['dcmt_description'], 0, 20) . '...' : $expense['dcmt_description'];
                        $new_desc = strlen($description) > 20 ? substr($description, 0, 20) . '...' : $description;
                        $expense_changes[] = "Description: " . ($old_desc ?: 'Empty') . " → " . ($new_desc ?: 'Empty');
                    }

                    // Log activity with detailed changes
                    if (!empty($expense_changes)) {
                        $changes_text = implode(', ', $expense_changes);
                        dcmt_log_activity('Expense Updated', "Expense ID: $expense_id, " . $changes_text);
                    } else {
                        dcmt_log_activity('Expense Updated', "Expense ID: $expense_id, No changes detected");
                    }
                    
                    // Set success message and redirect BEFORE any output
                    dcmt_show_message(trans('expense', 'update_success'), "success");
                    header('Location: view.php?id=' . $expense_id);
                    exit();
                    
                } catch (PDOException $e) {
                    $errors[] = 'Database error: ' . $e->getMessage();
                    dcmt_log_activity("Error updating expense: " . $e->getMessage(), "error");
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

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<?php
// Check if expense was fetched successfully
if (!$expense) {
    header('Location: index.php');
    exit();
}

// Check if title column exists, if not, add a default title
if (!isset($expense['dcmt_title'])) {
    $expense['dcmt_title'] = $expense['dcmt_description'] ? substr($expense['dcmt_description'], 0, 50) . '...' : 'Expense';
}

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Categories and payment methods fetched early for logging purposes


// Use POST data if available, otherwise use existing expense data
$form_data = [
    'title' => $_POST['title'] ?? ($expense['dcmt_title'] ?? ($expense['dcmt_description'] ? substr($expense['dcmt_description'], 0, 50) . '...' : 'Expense')),
    'description' => $_POST['description'] ?? $expense['dcmt_description'],
    'category_id' => $_POST['category_id'] ?? $expense['dcmt_category_id'],
    'amount' => $_POST['amount'] ?? $expense['dcmt_amount'],
    'payment_method_id' => $_POST['payment_method_id'] ?? $expense['dcmt_payment_method_id'],
    'payment_status' => $_POST['payment_status'] ?? $expense['dcmt_payment_status'],
    'expense_date' => $_POST['expense_date'] ?? $expense['dcmt_expense_date'],
];
?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('expense', 'edit_expense'); ?></h1>
            <a href="view.php?id=<?php echo $expense_id; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('common', 'view'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="expenseForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="expense_date" class="form-label"><?php echo trans('expense', 'expense_date'); ?> <span class="text-danger">*</span></label>
                        <input type="date" class="form-control" id="expense_date" name="expense_date" 
                               value="<?php echo htmlspecialchars($form_data['expense_date']); ?>" 
                               required>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="title" class="form-label"><?php echo trans('expense', 'title'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="title" name="title" 
                               value="<?php echo htmlspecialchars($form_data['title']); ?>" 
                               required maxlength="100" placeholder="<?php echo trans('expense', 'enter_title'); ?>">
                        <div class="form-text">
                            <?php echo trans('expense', 'title_help'); ?>
                            <?php if (!isset($expense['dcmt_title']) || empty($expense['dcmt_title'])): ?>
                                <span class="text-warning">(Auto-generated from description)</span>
                            <?php endif; ?>
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="category_id" class="form-label"><?php echo trans('expense', 'category'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="category_id" name="category_id" required>
                            <?php
                            dcmt_render_expense_category_select_options(
                                $categories,
                                $form_data['category_id'] ?? null,
                                true,
                                trans('expense', 'select_category')
                            );
                            ?>
                        </select>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-12">
                    <div class="mb-3">
                        <label for="description" class="form-label"><?php echo trans('common', 'description'); ?></label>
                        <textarea class="form-control" id="description" name="description" 
                                  rows="3" maxlength="255" placeholder="<?php echo trans('expense', 'enter_description'); ?>"><?php echo htmlspecialchars($form_data['description']); ?></textarea>
                        <div class="form-text"><?php echo trans('expense', 'description_help'); ?></div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="amount" class="form-label"><?php echo trans('expense', 'amount'); ?> *</label>
                        <div class="dcmt-amount-input-wrapper">
                            <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                            <input type="number" class="form-control dcmt-amount-input" id="amount" name="amount" 
                                   value="<?php echo htmlspecialchars($form_data['amount']); ?>" 
                                   required step="0.01" min="0.01" placeholder="<?php echo trans('common', 'amount'); ?>">
                        </div>
                    </div>
                </div>
                
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="payment_method_id" class="form-label"><?php echo trans('expense', 'payment_method'); ?> <span class="text-danger">*</span></label>
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
                                        <?php echo $form_data['payment_method_id'] == $method['dcmt_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($display_method); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="mb-3">
                        <label for="payment_status" class="form-label"><?php echo trans('expense', 'payment_status'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="payment_status" name="payment_status" required>
                            <option value=""><?php echo trans('expense', 'select_payment_status'); ?></option>
                            <option value="paid" <?php echo ($expense['dcmt_payment_status'] ?? '') == 'paid' ? 'selected' : ''; ?>><?php echo trans('expense', 'paid'); ?></option>
                            <option value="pending" <?php echo ($expense['dcmt_payment_status'] ?? '') == 'pending' ? 'selected' : ''; ?>><?php echo trans('expense', 'pending'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            
            
            <div class="dcmt-form-actions">
                <a href="view.php?id=<?php echo $expense_id; ?>" class="btn dcmt-btn-cancel"><?php echo trans('common', 'cancel'); ?></a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i><?php echo trans('expense', 'update_expense_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Define resetForm function
function resetForm() {
    // Get form element
    const form = document.getElementById('expenseForm');
    if (!form) {
        return;
    }
    
    // Reset form immediately
    form.reset();
    
    // Use setTimeout to ensure form.reset() completes before manual field setting
    setTimeout(() => {
        // Manually clear and set specific fields to ensure proper reset
        const fields = {
            'title': '<?php echo htmlspecialchars($expense['dcmt_title'] ?? ($expense['dcmt_description'] ? substr($expense['dcmt_description'], 0, 50) . '...' : trans('expense', 'expense'))); ?>',
            'description': '<?php echo htmlspecialchars($expense['dcmt_description']); ?>',
            'category_id': '<?php echo $expense['dcmt_category_id']; ?>',
            'amount': '<?php echo $expense['dcmt_amount']; ?>',
            'payment_method_id': '<?php echo $expense['dcmt_payment_method_id']; ?>',
            'payment_status': '<?php echo $expense['dcmt_payment_status']; ?>',
            'expense_date': '<?php echo $expense['dcmt_expense_date']; ?>'
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
    // Initialize form change detection
    dcmtInitFormChangeDetection('expenseForm');
    
    // Get form elements
    const form = document.getElementById('expenseForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !resetBtn) {
        return;
    }
    
    // Form validation
    form.addEventListener('submit', function(e) {
        const title = document.getElementById('title').value.trim();
        const amount = parseFloat(document.getElementById('amount').value);
        
        if (!title) {
            e.preventDefault();
            alert('Please enter a title for the expense.');
            document.getElementById('title').focus();
            return false;
        }
        
        if (amount <= 0) {
            e.preventDefault();
            alert('Amount must be greater than zero.');
            document.getElementById('amount').focus();
            return false;
        }
        
        // Clear form changed flag since we're submitting
        dcmtClearFormChanged();
        
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
