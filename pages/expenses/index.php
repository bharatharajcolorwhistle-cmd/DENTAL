<?php
/**
 * Expenses List Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin_or_staff();

// Get search and filter parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$category = isset($_GET['category']) ? dcmt_sanitize_input($_GET['category']) : '';
$payment_method = isset($_GET['payment_method']) ? dcmt_sanitize_input($_GET['payment_method']) : '';
$payment_status = isset($_GET['payment_status']) ? dcmt_sanitize_input($_GET['payment_status']) : '';
$date_range = isset($_GET['date_range']) ? dcmt_sanitize_input($_GET['date_range']) : '';
$clear_filters = isset($_GET['clear']) && $_GET['clear'] === '1';
$is_date_range_provided = isset($_GET['date_range']);
$has_active_non_date_filters = !empty($search)
    || !empty($category)
    || !empty($payment_method)
    || !empty($payment_status);
if (
    !$clear_filters
    && !$is_date_range_provided
    && !$has_active_non_date_filters
) {
    $date_range = date('Y-m-01') . ' to ' . date('Y-m-t');
}

// Parse date range
$date_from = '';
$date_to = '';
if (!empty($date_range) && strpos($date_range, ' to ') !== false) {
    $dates = explode(' to ', $date_range);
    if (count($dates) === 2) {
        $date_from = trim($dates[0]);
        $date_to = trim($dates[1]);
    }
}
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = DCMT_PER_PAGE;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(e.dcmt_title LIKE ? OR e.dcmt_description LIKE ? OR e.dcmt_payment_method LIKE ? OR pm.dcmt_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where_conditions[] = "e.dcmt_category_id = ?";
    $params[] = $category;
}

if (!empty($payment_method)) {
    $where_conditions[] = "e.dcmt_payment_method_id = ?";
    $params[] = $payment_method;
}

if (!empty($payment_status)) {
    $where_conditions[] = "e.dcmt_payment_status = ?";
    $params[] = $payment_status;
}

if (!empty($date_from)) {
    $where_conditions[] = "e.dcmt_expense_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "e.dcmt_expense_date <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "SELECT COUNT(*) FROM dcmt_expenses e LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id LEFT JOIN dcmt_expense_payment_methods pm ON e.dcmt_payment_method_id = pm.dcmt_id $where_clause";
$count_stmt = $dcmt_pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get expenses with pagination
$offset = ($page - 1) * $per_page;
$sql = "SELECT e.*, c.dcmt_name as category_name, pm.dcmt_name as payment_method_name, u.dcmt_full_name as created_by_name
        FROM dcmt_expenses e 
        LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id 
        LEFT JOIN dcmt_expense_payment_methods pm ON e.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_users u ON e.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
        $where_clause 
        ORDER BY e.dcmt_expense_date DESC, e.dcmt_id DESC 
        LIMIT $per_page OFFSET $offset";
$stmt = $dcmt_pdo->prepare($sql);
$stmt->execute($params);
$expenses = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total expenses for summary
$total_sql = "SELECT COALESCE(SUM(e.dcmt_amount), 0) as total_amount FROM dcmt_expenses e LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id LEFT JOIN dcmt_expense_payment_methods pm ON e.dcmt_payment_method_id = pm.dcmt_id $where_clause";
$total_stmt = $dcmt_pdo->prepare($total_sql);
$total_stmt->execute($params);
$total_amount = $total_stmt->fetchColumn();

// Get categories for filter
$categories_sql = "SELECT c.dcmt_id, c.dcmt_name FROM dcmt_expense_categories c WHERE c.dcmt_status = 'active' ORDER BY c.dcmt_name";
$categories_stmt = $dcmt_pdo->query($categories_sql);
$categories = $categories_stmt->fetchAll();

// Get payment methods for filter
$payment_methods_sql = "SELECT pm.dcmt_id, pm.dcmt_name FROM dcmt_expense_payment_methods pm WHERE pm.dcmt_status = 'active' ORDER BY pm.dcmt_name";
$payment_methods_stmt = $dcmt_pdo->query($payment_methods_sql);
$payment_methods = $payment_methods_stmt->fetchAll();

// Now include the header
require_once __DIR__ . '/../../includes/header.php';

// Generate CSRF token for AJAX operations
$csrf_token = dcmt_generate_csrf_token();
?>
<meta name="csrf-token" content="<?php echo $csrf_token; ?>">
<?php

// Display delete operation messages
if (isset($_SESSION['expense_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['expense_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['expense_delete_success']);
}

if (isset($_SESSION['expense_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['expense_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['expense_delete_error']);
}

if (isset($_SESSION['expense_delete_info'])) {
    echo '<div class="alert alert-info alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['expense_delete_info']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['expense_delete_info']);
}
?>

<!-- <?php echo trans('expense', 'search_and_filter_form'); ?> -->
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="<?php echo trans('expense', 'search_placeholder'); ?>">
            </div>
            <div class="col-md">
                <label for="category" class="form-label"><?php echo trans('expense', 'category'); ?></label>
                <select class="form-select dcmt-filter-field" id="category" name="category">
                    <option value=""><?php echo trans('expense', 'all_categories'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <?php 
                        // Translate category name
                        $cat_name = $cat['dcmt_name'];
                        $translated_cat = trans('expense_category', $cat_name);
                        $display_cat = ($translated_cat !== $cat_name) ? $translated_cat : $cat_name;
                        ?>
                        <option value="<?php echo htmlspecialchars($cat['dcmt_id']); ?>" 
                                <?php echo $category == $cat['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($display_cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label for="payment_method" class="form-label"><?php echo trans('expense', 'payment_method'); ?></label>
                <select class="form-select dcmt-filter-field" id="payment_method" name="payment_method">
                    <option value=""><?php echo trans('expense', 'all_payment_methods'); ?></option>
                    <?php foreach ($payment_methods as $pm): ?>
                        <?php 
                        // Translate payment method name
                        $pm_name = $pm['dcmt_name'];
                        $translated_pm = trans('expense_payment_method', $pm_name);
                        $display_pm = ($translated_pm !== $pm_name) ? $translated_pm : $pm_name;
                        ?>
                        <option value="<?php echo htmlspecialchars($pm['dcmt_id']); ?>" 
                                <?php echo $payment_method == $pm['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($display_pm); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label for="payment_status" class="form-label"><?php echo trans('expense', 'payment_status'); ?></label>
                <select class="form-select dcmt-filter-field" id="payment_status" name="payment_status">
                    <option value=""><?php echo trans('expense', 'all_status'); ?></option>
                    <option value="paid" <?php echo $payment_status === 'paid' ? 'selected' : ''; ?>><?php echo trans('common', 'paid'); ?></option>
                    <option value="pending" <?php echo $payment_status === 'pending' ? 'selected' : ''; ?>><?php echo trans('common', 'pending'); ?></option>
                    <option value="cancelled" <?php echo $payment_status === 'cancelled' ? 'selected' : ''; ?>><?php echo trans('expense', 'cancelled'); ?></option>
                </select>
            </div>
            <div class="col-md">
                <label for="date_range" class="form-label"><?php echo trans('expense', 'date_range'); ?></label>
                <input type="text" class="form-control dcmt-daterange-picker dcmt-filter-field" id="date_range" name="date_range" 
                       value="<?php echo htmlspecialchars($date_range); ?>"
                       placeholder="<?php echo trans('expense', 'select_date_range'); ?>"
                       readonly>
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="?clear=1" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- <?php echo trans('expense', 'expense_records_table'); ?> -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('expense', 'expense_records'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('expense', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('expense', 'records'); ?><?php echo $total_amount > 0 ? ' | ' . trans('expense', 'total_amount') . ': <span style="color: #dc3545;">' . dcmt_format_currency($total_amount) . '</span>' : ''; ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('expense', 'add_expense'); ?></a>
                <a href="import.php" class="dcmt-add-form-view-all-link dcmt-hide">
                    <i class="fas fa-upload me-1"></i><?php echo trans('expense', 'import_expenses'); ?>
                </a>
                <button type="button" class="dcmt-add-form-view-all-link dcmt-hide" onclick="exportToCSV()">
                    <i class="fas fa-download me-1"></i><?php echo trans('expense', 'export_expenses'); ?>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($expenses)): ?>
            <div class="text-center py-4">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('expense', 'no_expenses_found'); ?></h5>
                <p class="text-muted"><?php echo trans('expense', 'no_expenses_message'); ?></p>
                <a href="add.php" class="btn btn-success">
                    <i class="fas fa-plus me-1"></i><?php echo trans('expense', 'add_first_expense'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <!-- Bulk Actions Bar -->
                <div id="bulkActionsBar" class="dcmt-bulk-actions-bar mb-3" style="display: none;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <span id="selectedCount" class="me-3">0 selected</span>
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="selectAll()">
                                <i class="fas fa-check-square me-1"></i>Select All
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="deselectAll()">
                                <i class="fas fa-square me-1"></i>Deselect All
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">
                                <i class="fas fa-trash me-1"></i>Delete Selected
                            </button>
                        </div>
                    </div>
                </div>
                
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" class="form-check-input">
                            </th>
                            <th><?php echo trans('expense', 'title'); ?></th>
                            <th><?php echo trans('expense', 'category'); ?></th>
                            <th><?php echo trans('expense', 'amount'); ?></th>
                            <th><?php echo trans('expense', 'payment_method'); ?></th>
                            <th><?php echo trans('expense', 'payment_status'); ?></th>
                            <th><?php echo trans('expense', 'expense_date'); ?></th>
                            <th><?php echo trans('common', 'created_by'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($expenses as $expense): ?>
                            <tr>
                                <td>
                                    <input type="checkbox" class="form-check-input dcmt-expense-checkbox" 
                                           value="<?php echo $expense['dcmt_id']; ?>" 
                                           onchange="updateBulkActions()">
                                </td>
                                <td>
                                    <?php echo htmlspecialchars($expense['dcmt_title'] ?? 'N/A'); ?>
                                </td>
                                <td>
                                    <?php if (!empty($expense['category_name'])): ?>
                                        <?php 
                                        // Translate category name
                                        $category_name = $expense['category_name'];
                                        $translated_category = trans('expense_category', $category_name);
                                        $display_category = ($translated_category !== $category_name) ? $translated_category : $category_name;
                                        ?>
                                        <span>
                                            <?php echo htmlspecialchars($display_category); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted"><?php echo trans('expense', 'no_category'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-danger">
                                        <?php echo dcmt_format_currency($expense['dcmt_amount']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <span class="status-dot bg-<?php echo strtolower($expense['payment_method_name'] ?? $expense['dcmt_payment_method'] ?? '') === 'cash' ? 'success' : 'warning'; ?>"></span>
                                        <?php 
                                        // Translate payment method name
                                        $method_name = $expense['payment_method_name'] ?? 'N/A';
                                        $translated_method = trans('expense_payment_method', $method_name);
                                        echo htmlspecialchars($translated_method !== $method_name ? $translated_method : $method_name);
                                        ?>
                                    </div>
                                </td>
                                <td>
                                    <div class="d-flex align-items-center gap-2">
                                        <?php 
                                        // Translate payment status
                                        $raw_status = $expense['dcmt_payment_status'];
                                        $translated_status = trans('common', $raw_status);
                                        $display_status = ($translated_status !== $raw_status) ? $translated_status : ucfirst($raw_status);
                                        ?>
                                        <span style="color: <?php echo $expense['dcmt_payment_status'] === 'paid' ? '#28A745' : ($expense['dcmt_payment_status'] === 'cancelled' ? '#DC3545' : '#FFC107'); ?>; font-weight: 500;">
                                            <?php echo htmlspecialchars($display_status); ?>
                                        </span>
                                    </div>
                                </td>
                                <td><?php echo dcmt_format_date($expense['dcmt_expense_date']); ?></td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($expense['created_by_name'] ?? $expense['dcmt_created_by']); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $expense['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <a href="edit.php?id=<?php echo $expense['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                onclick="confirmDeleteExpense(<?php echo $expense['dcmt_id']; ?>)">
                                            <img src="../../assets/images/delete.svg" alt="Delete">
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="9" class="fw-bold">
                                <span class="dcmt-view-card-title-total">
                                    <?php echo trans('expense', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('expense', 'records'); ?><?php echo $total_amount > 0 ? ' | ' . trans('expense', 'total_amount') . ': <span style="color: #dc3545;">' . dcmt_format_currency($total_amount) . '</span>' : ''; ?>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <!-- <?php echo trans('expense', 'pagination'); ?> -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('expense', 'expense_records_pagination'); ?>">
                    <ul class="pagination justify-content-center">
                        <!-- First Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                    <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Previous Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                    <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                    <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Last Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" title="<?php echo trans('common', 'last_page'); ?>">
                                    <?php echo trans('common', 'last_page'); ?> <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Pass translations to JavaScript
window.translations = {
    confirm_deletion: '<?php echo trans('common', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('common', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('expense', 'delete_confirmation_message') ?: trans('common', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('common', 'cancel'); ?>',
    yes_delete: '<?php echo trans('common', 'yes_delete'); ?>',
    expense_record: '<?php echo trans('expense', 'expense_record'); ?>',
    expense_records: '<?php echo trans('expense', 'expense_records'); ?>',
    confirm_delete_single: '<?php echo trans('expense', 'confirm_delete_single'); ?>',
    confirm_delete_multiple: '<?php echo trans('expense', 'confirm_delete_multiple'); ?>'
};

// Bulk actions bar styles are now in main.css

// Expense-specific delete function
function confirmDeleteExpense(expenseId) {
    console.log('confirmDeleteExpense called with expenseId:', expenseId);
    
    // Use the main.js confirmDelete function with specific parameters
    if (typeof confirmDelete === 'function') {
        confirmDelete(expenseId, 'expense_record');
    } else {
        // Fallback to direct AJAX call if main.js function is not available
        console.warn('Main.js confirmDelete not available, using direct AJAX');
        if (confirm('Are you sure you want to delete this expense? This action cannot be undone.')) {
            deleteExpenseDirectly(expenseId);
        }
    }
}

// Direct AJAX deletion as fallback
function deleteExpenseDirectly(expenseId) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    
    fetch('delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: expenseId,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Reload to show session-based success message (consistent with add/edit)
            location.reload();
        } else {
            showErrorMessageFallback('Failed to delete expense: ' + (data.message || 'Unknown error'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('An error occurred while deleting the expense');
    });
}

// Fallback message functions for better UX
function showSuccessMessageFallback(message) {
    showMessageFallback(message, 'success');
}

function showErrorMessageFallback(message) {
    showMessageFallback(message, 'danger');
}

function showMessageFallback(message, type) {
    // Remove existing messages
    const existingMessages = document.querySelectorAll('.alert[data-fallback-message="true"]');
    existingMessages.forEach(msg => msg.remove());
    
    const messageHTML = `
        <div class="alert alert-${type} alert-dismissible" role="alert" data-fallback-message="true" style="position: fixed; top: 80px; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 400px; max-width: 600px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <div class="d-flex align-items-center">
                <i class="fas fa-${type === 'success' ? 'check-circle' : 'exclamation-triangle'} me-2"></i>
                <strong>${message}</strong>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', messageHTML);
    
    // Auto-dismiss after 4 seconds
    setTimeout(() => {
        const messageElement = document.querySelector('.alert[data-fallback-message="true"]');
        if (messageElement) {
            messageElement.remove();
        }
    }, 4000);
}

// Multi-select functionality
function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.dcmt-expense-checkbox');
    const checkedBoxes = document.querySelectorAll('.dcmt-expense-checkbox:checked');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    
    const count = checkedBoxes.length;
    
    if (count > 0) {
        bulkActionsBar.style.display = 'block';
        selectedCount.textContent = count + ' selected';
    } else {
        bulkActionsBar.style.display = 'none';
    }
    
    // Update select all checkbox state
    if (count === 0) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = false;
    } else if (count === checkboxes.length) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = true;
    } else {
        selectAllCheckbox.indeterminate = true;
    }
}

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const checkboxes = document.querySelectorAll('.dcmt-expense-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateBulkActions();
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.dcmt-expense-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkActions();
}

function deselectAll() {
    const checkboxes = document.querySelectorAll('.dcmt-expense-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkActions();
}

function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.dcmt-expense-checkbox:checked');
    
    if (checkedBoxes.length === 0) {
        alert('Please select at least one expense to delete.');
        return;
    }
    
    const expenseIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
    const count = expenseIds.length;
    
    // Use the main.js confirmDelete function with bulk parameters
    if (typeof confirmDelete === 'function') {
        confirmBulkDelete(expenseIds, count);
    } else {
        // Fallback confirmation
        const message = count === 1 ? 
            'Are you sure you want to delete this expense?' : 
            `Are you sure you want to delete these ${count} expenses?`;
            
        if (confirm(message + ' This action cannot be undone.')) {
            bulkDeleteAjax(expenseIds);
        }
    }
}

function confirmBulkDelete(expenseIds, count) {
    // Create custom confirmation for bulk delete
    const itemType = count === 1 ? window.translations.expense_record : `${count} ${window.translations.expense_records}`;
    const message = count === 1 ? 
        window.translations.confirm_delete_single :
        window.translations.confirm_delete_multiple.replace('{count}', count);
    
    // Remove existing modal if any
    const existingModal = document.getElementById('deleteConfirmationModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Get translated text
    const confirmDeletion = window.translations.confirm_deletion;
    const warning = window.translations.warning;
    const cancel = window.translations.cancel;
    const yesDelete = window.translations.yes_delete;
    
    // Create modal HTML
    const modalHTML = `
        <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteConfirmationModalLabel">
                            <i class="fas fa-exclamation-triangle"></i> ${confirmDeletion}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle"></i> ${warning}
                            </h6>
                            <p class="mb-0">${message}</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> ${cancel}
                        </button>
                        <button type="button" class="btn btn-danger" onclick="proceedWithBulkDelete([${expenseIds.join(',')}])">
                            <i class="fas fa-trash"></i> ${yesDelete}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
    modal.show();
    
    // Remove modal from DOM when hidden
    document.getElementById('deleteConfirmationModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function proceedWithBulkDelete(expenseIds) {
    // Hide modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }
    
    bulkDeleteAjax(expenseIds);
}

function bulkDeleteAjax(expenseIds) {
    console.log('bulkDeleteAjax called with expenseIds:', expenseIds);
    
    // Show loading state
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage('Deleting expenses...');
    }
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    
    // Make AJAX request to bulk delete endpoint
    fetch('bulk_delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            ids: expenseIds,
            csrf_token: csrfToken
        })
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Bulk delete response:', data);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        
        if (data.success) {
            // Reload to show session-based success message
            location.reload();
        } else {
            if (typeof showErrorMessage === 'function') {
                showErrorMessage(data.message || 'Failed to delete expenses');
            } else {
                alert('Failed to delete expenses: ' + (data.message || 'Unknown error'));
            }
        }
    })
    .catch(error => {
        console.error('Error deleting expenses:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        if (typeof showErrorMessage === 'function') {
            showErrorMessage('An error occurred while deleting the expenses. Please try again.');
        } else {
            alert('An error occurred while deleting the expenses');
        }
    });
}

// Initialize date range picker
$(document).ready(function() {
    $('#date_range').daterangepicker({
        autoUpdateInput: false,
        autoApply: true,
        locale: {
            cancelLabel: '<?php echo trans('common', 'clear'); ?>',
            applyLabel: '<?php echo trans('common', 'apply'); ?>',
            format: 'YYYY-MM-DD',
            separator: ' to ',
            customRangeLabel: '<?php echo trans('common', 'custom_range'); ?>'
        },
        ranges: {
            '<?php echo trans('common', 'today'); ?>': [moment(), moment()],
            '<?php echo trans('common', 'yesterday'); ?>': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            '<?php echo trans('common', 'last_7_days'); ?>': [moment().subtract(6, 'days'), moment()],
            '<?php echo trans('common', 'last_30_days'); ?>': [moment().subtract(29, 'days'), moment()],
            '<?php echo trans('common', 'this_month'); ?>': [moment().startOf('month'), moment().endOf('month')],
            '<?php echo trans('common', 'last_month'); ?>': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
            '<?php echo trans('common', 'this_year'); ?>': [moment().startOf('year'), moment().endOf('year')],
            '<?php echo trans('common', 'last_year'); ?>': [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
        },
        opens: 'left',
        drops: 'down'
    });

    $('#date_range').on('apply.daterangepicker', function(ev, picker) {
        $(this).val(picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format('YYYY-MM-DD'));
    });

    $('#date_range').on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
    });

    // Set initial value if date range is already set
    <?php if (!empty($date_range)): ?>
    $('#date_range').val('<?php echo htmlspecialchars($date_range); ?>');
    <?php endif; ?>
});

function exportToCSV() {
    // Get current filter parameters
    const params = new URLSearchParams(window.location.search);
    params.append('export', 'csv');
    
    // Create download link
    const link = document.createElement('a');
    link.href = 'export.php?' + params.toString();
    link.download = 'expense_records.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<!-- JavaScript functions are now in assets/js/main.js -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
