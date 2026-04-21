<?php
/**
 * View Expense Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin_or_staff();

// Get expense ID from URL
$expense_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($expense_id <= 0) {
    dcmt_show_message(trans('expense', 'invalid_expense_id'), "error");
    dcmt_redirect("index.php");
}

// Fetch expense details
try {
    $sql = "SELECT e.*, c.dcmt_name as category_name, pm.dcmt_name as payment_method_name
            FROM dcmt_expenses e 
            LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id 
            LEFT JOIN dcmt_expense_payment_methods pm ON e.dcmt_payment_method_id = pm.dcmt_id
            WHERE e.dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$expense_id]);
    $expense = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$expense) {
        dcmt_show_message(trans('expense', 'expense_not_found'), "error");
        dcmt_redirect("index.php");
    }
} catch (PDOException $e) {
    dcmt_show_message(trans('expense', 'database_error'), "error");
    dcmt_redirect("index.php");
}

// Log activity
dcmt_log_activity("Viewed expense: " . $expense['dcmt_description'], "expense_viewed");

// Get edit history from activity log
$edit_history = [];
try {
    $stmt = $dcmt_pdo->prepare("
        SELECT al.*, u.dcmt_full_name as user_name
        FROM dcmt_activity_log al
        LEFT JOIN dcmt_users u ON al.dcmt_user = u.dcmt_username
        WHERE al.dcmt_details LIKE ? OR (al.dcmt_activity LIKE ? AND al.dcmt_details LIKE ?)
        ORDER BY al.dcmt_created_at DESC
        LIMIT 20
    ");
    $search_pattern = "%Expense ID: $expense_id%";
    $activity_pattern = "%Expense%";
    $expense_pattern = "%Expense ID: $expense_id%";
    $stmt->execute([$search_pattern, $activity_pattern, $expense_pattern]);
    $edit_history = $stmt->fetchAll();
    
    // Debug: Log the number of records found
    error_log("Edit history query found " . count($edit_history) . " records for expense ID: $expense_id");
} catch (PDOException $e) {
    // If query fails, continue without edit history
    error_log("Failed to fetch edit history: " . $e->getMessage());
}

// Now include the header
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-receipt dcmt-view-card-title-icon"></i><?php echo trans('expense', 'expense_details'); ?>
                </h6>
                <div class="dcmt-view-header-links">
                    <a href="edit.php?id=<?php echo $expense['dcmt_id']; ?>" class="dcmt-add-form-view-all-link me-3">
                        <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                    </a>
                    <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('expense', 'back_to_expenses'); ?></a>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('expense', 'expense_date'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo dcmt_format_date($expense['dcmt_expense_date']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('expense', 'title'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($expense['dcmt_title']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'amount'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo dcmt_format_currency($expense['dcmt_amount']); ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('expense', 'category'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span class="">
                                    <?php 
                                    $category_name = $expense['category_name'] ?? '';
                                    if (!empty($category_name)) {
                                        $translated_category = trans('expense_category', $category_name);
                                        echo htmlspecialchars($translated_category !== $category_name ? $translated_category : $category_name);
                                    } else {
                                        echo trans('expense', 'no_category');
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('expense', 'payment_method'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php 
                                // Translate payment method name
                                $method_name = $expense['payment_method_name'] ?? 'N/A';
                                if (!empty($method_name) && $method_name !== 'N/A') {
                                    $translated_method = trans('expense_payment_method', $method_name);
                                    echo htmlspecialchars($translated_method !== $method_name ? $translated_method : $method_name);
                                } else {
                                    echo 'N/A';
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('expense', 'payment_status'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php
                                $status_class = match($expense['dcmt_payment_status']) {
                                    'paid' => 'dcmt-view-field-value-status-completed',
                                    'pending' => 'dcmt-view-field-value-status-pending',
                                    'cancelled' => 'dcmt-view-field-value-status-failed',
                                    default => 'dcmt-view-field-value'
                                };
                                ?>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo ucfirst(htmlspecialchars($expense['dcmt_payment_status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-12">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'description'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($expense['dcmt_description']); ?></div>
                        </div>
                    </div>
                </div>
                
            </div>
        </div>
        
        <!-- Edit History Section -->
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-history dcmt-view-card-title-icon"></i><?php echo trans('expense', 'edit_history'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($edit_history)): ?>
                <div class="timeline">
                    <?php foreach ($edit_history as $index => $entry): ?>
                    <div class="timeline-item <?php echo $index === 0 ? 'timeline-item-first' : ''; ?>">
                        <div class="timeline-marker">
                            <?php
                            $icon = 'info';
                            if (strpos($entry['dcmt_activity'], 'added') !== false) {
                                $icon = 'plus';
                            } elseif (strpos($entry['dcmt_activity'], 'updated') !== false) {
                                $icon = 'edit';
                            } elseif (strpos($entry['dcmt_activity'], 'deleted') !== false) {
                                $icon = 'trash';
                            }
                            ?>
                            <i class="fas fa-<?php echo $icon; ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($entry['dcmt_activity']); ?></h6>
                                    <?php if (!empty($entry['dcmt_details'])): ?>
                                        <?php
                                        // Remove "Expense ID: X" from details to keep it clean
                                        $details = $entry['dcmt_details'];
                                        $details = preg_replace('/Expense ID: \d+,?\s*/', '', $details);
                                        $details = trim($details, ', ');
                                        ?>
                                        <p class="text-muted mb-1 small"><?php echo htmlspecialchars($details); ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($entry['user_name'] ?: $entry['dcmt_user']); ?>
                                    </small>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo dcmt_format_date($entry['dcmt_created_at'], 'M j, Y g:i A'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <p class="mb-0"><?php echo trans('expense', 'no_edit_history_found'); ?></p>
                    <small><?php echo trans('expense', 'edit_history_will_appear'); ?></small>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
// Pass translations to JavaScript
window.translations = {
    confirm_deletion: '<?php echo trans('expense', 'confirm_deletion') ?: trans('common', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('expense', 'warning') ?: trans('common', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('expense', 'delete_confirmation_message') ?: trans('common', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('expense', 'cancel') ?: trans('common', 'cancel'); ?>',
    yes_delete: '<?php echo trans('expense', 'yes_delete') ?: trans('common', 'yes_delete'); ?>'
};

// Delete functionality moved to AJAX in main.js
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
