<?php
/**
 * Delete Expense Category
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/expense_category_functions.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

dcmt_require_admin_or_staff();

if (!dcmt_can_delete_records()) {
    dcmt_show_message(trans('common', 'staff_cannot_delete'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$category_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$errors = [];
$success_message = '';

// Get category data
$category = null;
if ($category_id > 0) {
    try {
        $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_expense_categories WHERE dcmt_id = ?");
        $stmt->execute([$category_id]);
        $category = $stmt->fetch();
        
        if (!$category) {
            dcmt_show_message(trans('expense_category', 'category_not_found'), 'danger');
            dcmt_redirect('index.php');
        }
    } catch (PDOException $e) {
        error_log("Error fetching expense category: " . $e->getMessage());
        dcmt_show_message(trans('expense_category', 'error_loading_category'), 'danger');
        dcmt_redirect('index.php');
    }
} else {
    dcmt_show_message(trans('expense_category', 'invalid_category_id'), 'danger');
    dcmt_redirect('index.php');
}

// Check if category is being used by any expenses
$is_used = false;
$usage_count = 0;
try {
    $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) as count FROM dcmt_expenses WHERE dcmt_category_id = ?");
    $stmt->execute([$category_id]);
    $result = $stmt->fetch();
    $usage_count = $result['count'];
    $is_used = $usage_count > 0;
} catch (PDOException $e) {
    error_log("Error checking expense category usage: " . $e->getMessage());
    $errors[] = trans('expense_category', 'error_checking_usage');
}

$child_count = dcmt_expense_category_child_count($dcmt_pdo, $category_id);
$has_children = $child_count > 0;

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!dcmt_verify_csrf_token($csrf_token)) {
        $errors[] = trans('expense_category', 'invalid_token');
    }
    
    // Prevent deletion if category is being used
    if ($is_used) {
        $errors[] = trans('expense_category', 'cannot_delete_used_category');
    }

    if ($has_children) {
        $errors[] = trans('expense_category', 'cannot_delete_with_children');
    }
    
    if (empty($errors)) {
        try {
            // Start transaction
            $dcmt_pdo->beginTransaction();
            
            // Delete the category (only if not used)
            $stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_expense_categories WHERE dcmt_id = ?");
            $stmt->execute([$category_id]);
            
            // Commit transaction
            $dcmt_pdo->commit();
            
            dcmt_log_activity(trans('expense_category', 'category_deleted'), "Category ID: $category_id, Name: {$category['dcmt_name']}");
            dcmt_show_message(trans('expense_category', 'category_deleted_successfully'), 'success');
            dcmt_redirect('index.php');
            
        } catch (PDOException $e) {
            // Rollback transaction on error
            $dcmt_pdo->rollBack();
            error_log("Error deleting expense category: " . $e->getMessage());
            $errors[] = trans('expense_category', 'error_deleting_category');
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
    <title><?php echo trans('expense_category', 'delete_category'); ?> - <?php echo dcmt_get_site_name(); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../../assets/css/main.css" rel="stylesheet">
</head>
<body>
    <?php include '../../includes/header.php'; ?>
    
    <div class="container-fluid">
        <div class="row">
            <div class="col-md-6 mx-auto">
                <div class="card border-danger">
                    <div class="card-header bg-danger text-white">
                        <h4 class="mb-0">
                            <i class="fas fa-trash me-2"></i>
                            <?php echo trans('expense_category', 'delete_category'); ?>
                        </h4>
                    </div>
                    <div class="card-body">
                        <?php if (!empty($errors)): ?>
                            <div class="alert alert-danger">
                                <ul class="mb-0">
                                    <?php foreach ($errors as $error): ?>
                                        <li><?php echo htmlspecialchars($error); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            </div>
                        <?php endif; ?>
                        
                        <div class="alert alert-warning">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle me-2"></i>
                                <?php echo trans('expense_category', 'delete_warning'); ?>
                            </h6>
                            <p class="mb-0">
                                <?php echo trans('expense_category', 'delete_confirmation_message'); ?>
                            </p>
                        </div>
                        
                        <!-- Category Information -->
                        <div class="card mb-3">
                            <div class="card-header">
                                <h6 class="mb-0">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <?php echo trans('expense_category', 'category_information'); ?>
                                </h6>
                            </div>
                            <div class="card-body">
                                <div class="row">
                                    <div class="col-md-6">
                                        <strong><?php echo trans('expense_category', 'category_name'); ?>:</strong><br>
                                        <span class="badge bg-primary">
                                            <?php echo htmlspecialchars($category['dcmt_name']); ?>
                                        </span>
                                    </div>
                                    <div class="col-md-6">
                                        <strong><?php echo trans('expense_category', 'status'); ?>:</strong><br>
                                        <span class="badge bg-<?php echo $category['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                            <?php echo trans('expense_category', $category['dcmt_status']); ?>
                                        </span>
                                    </div>
                                </div>
                                <?php if (!empty($category['dcmt_description'])): ?>
                                    <div class="mt-2">
                                        <strong><?php echo trans('expense_category', 'description'); ?>:</strong><br>
                                        <?php echo htmlspecialchars($category['dcmt_description']); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        </div>
                        
                        <!-- Usage Information -->
                        <?php if ($is_used): ?>
                            <div class="alert alert-info">
                                <h6 class="alert-heading">
                                    <i class="fas fa-info-circle me-2"></i>
                                    <?php echo trans('expense_category', 'category_in_use'); ?>
                                </h6>
                                <p class="mb-0">
                                    <?php echo str_replace('{count}', $usage_count, trans('expense_category', 'category_used_by_expenses')); ?>
                                    <?php echo trans('expense_category', 'expenses_will_be_uncategorized'); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                        
                        <form method="POST" action="">
                            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                            
                            <div class="d-flex justify-content-between">
                                <a href="index.php" class="btn btn-secondary">
                                    <i class="fas fa-arrow-left me-2"></i><?php echo trans('expense_category', 'cancel'); ?>
                                </a>
                                
                                <button type="submit" class="btn btn-danger" onclick="return confirm('<?php echo trans('expense_category', 'confirm_delete'); ?>')">
                                    <i class="fas fa-trash me-2"></i><?php echo trans('expense_category', 'delete_category'); ?>
                                </button>
                            </div>
                        </form>
                    </div>
                </div>
            </div>
        </div>
    </div>
    
    <?php include '../../includes/footer.php'; ?>
</body>
</html>
