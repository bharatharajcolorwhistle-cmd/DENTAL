<?php
/**
 * Expense Categories Management - Index Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/expense_category_functions.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check admin access
dcmt_require_admin_or_staff();
$dcmt_can_delete = dcmt_can_delete_records();

require_once __DIR__ . '/../../includes/header.php';

$csrf_token = dcmt_generate_csrf_token();
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
<?php

if (isset($_SESSION['expense_category_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['expense_category_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['expense_category_delete_success']);
}

if (isset($_SESSION['expense_category_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['expense_category_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['expense_category_delete_error']);
}
?>


<?php
// Get search parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(c.dcmt_name LIKE ? OR c.dcmt_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

try {
    $column_exists = dcmt_expense_category_has_parent_column($dcmt_pdo);
    
    if ($column_exists) {
        // New format with parent category support
        $sql = "
            SELECT c.*, 
                   p.dcmt_name as parent_name,
                   COUNT(DISTINCT e.dcmt_id) as expense_count,
                   (SELECT COUNT(*) FROM dcmt_expense_categories sc WHERE sc.dcmt_parent_category_id = c.dcmt_id) as sub_category_count
            FROM dcmt_expense_categories c
            LEFT JOIN dcmt_expense_categories p ON c.dcmt_parent_category_id = p.dcmt_id
            LEFT JOIN dcmt_expenses e ON c.dcmt_id = e.dcmt_category_id
            $where_clause
            GROUP BY c.dcmt_id
            ORDER BY 
                CASE WHEN c.dcmt_parent_category_id IS NULL THEN 0 ELSE 1 END,
                p.dcmt_name,
                c.dcmt_name
        ";
    } else {
        // Old format without parent category support
        $sql = "
            SELECT c.*, 
                   NULL as parent_name,
                   COUNT(DISTINCT e.dcmt_id) as expense_count,
                   0 as sub_category_count
            FROM dcmt_expense_categories c
            LEFT JOIN dcmt_expenses e ON c.dcmt_id = e.dcmt_category_id
            $where_clause
            GROUP BY c.dcmt_id
            ORDER BY c.dcmt_name
        ";
    }
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $categories = $stmt->fetchAll();
    
    // Clear any previous error messages if query was successful
    if (isset($_SESSION['dcmt_message'])) {
        unset($_SESSION['dcmt_message']);
    }
    
} catch (PDOException $e) {
    error_log("Expense categories fetch error: " . $e->getMessage());
    $categories = [];
    // Only show error message if it's a real database error, not a search issue
    if (empty($search)) {
        dcmt_show_message(trans('expense_category', 'load_error'), 'danger');
    }
}
?>

<!-- Search and Filter Form -->
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="searchForm">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="<?php echo trans('expense_category', 'search_placeholder'); ?>"
                       onkeypress="handleSearchKeypress(event)">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn" id="searchBtn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="index.php" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
        
        <?php if (!empty($search)): ?>
            <div class="mt-3">
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i>
                    <?php echo trans('expense_category', 'search_results_for'); ?>: <strong><?php echo htmlspecialchars($search); ?></strong>
                    <span class="badge bg-primary ms-2"><?php echo count($categories); ?> <?php echo trans('common', 'results'); ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Categories List -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <i class="fas fa-tags dcmt-view-card-title-icon"></i><?php echo trans('expense_category', 'expense_categories'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('expense_category', 'add_category'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($categories)): ?>
            <div class="text-center py-4">
                <i class="fas fa-tags fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('expense_category', 'no_categories_found'); ?></h5>
                <p class="text-muted"><?php echo trans('expense_category', 'start_adding_category'); ?></p>
            </div>
        <?php else: ?>
                    <div class="table-responsive">
                        <?php if ($dcmt_can_delete): ?>
                        <div id="bulkActionsBar" class="dcmt-bulk-actions-bar mb-3" style="display: none;">
                            <div class="d-flex align-items-center justify-content-between">
                                <div class="d-flex align-items-center">
                                    <span id="selectedCount" class="me-3">0 <?php echo trans('common', 'selected'); ?></span>
                                    <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="selectAll()">
                                        <i class="fas fa-check-square me-1"></i><?php echo trans('common', 'select_all'); ?>
                                    </button>
                                    <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="deselectAll()">
                                        <i class="fas fa-square me-1"></i><?php echo trans('common', 'deselect_all'); ?>
                                    </button>
                                </div>
                                <div>
                                    <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">
                                        <i class="fas fa-trash me-1"></i><?php echo trans('common', 'delete_selected'); ?>
                                    </button>
                                </div>
                            </div>
                        </div>
                        <?php endif; ?>

                        <table class="table table-hover">
                            <thead>
                                <tr>
                                    <?php if ($dcmt_can_delete): ?>
                                    <th style="width: 40px;">
                                        <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" class="form-check-input">
                                    </th>
                                    <?php endif; ?>
                                    <th><?php echo trans('expense_category', 'category_name'); ?></th>
                                    <th><?php echo trans('expense_category', 'parent'); ?></th>
                                    <th><?php echo trans('common', 'description'); ?></th>
                                    <th><?php echo trans('common', 'status'); ?></th>
                                    <th><?php echo trans('expense_category', 'usage'); ?></th>
                                    <th><?php echo trans('expense_category', 'sub_categories'); ?></th>
                                    <th><?php echo trans('common', 'created_at'); ?></th>
                                    <th><?php echo trans('common', 'actions'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($categories as $category): ?>
                                    <?php
                                    $is_system_category = (($category['dcmt_created_by'] ?? '') === 'system');
                                    $is_used_category = ((int) $category['expense_count'] > 0) || ((int) $category['sub_category_count'] > 0);
                                    $can_delete_item = $dcmt_can_delete && !$is_system_category && !$is_used_category;
                                    ?>
                                    <tr class="<?php echo (isset($category['dcmt_parent_category_id']) && $category['dcmt_parent_category_id']) ? 'table-light' : ''; ?>">
                                        <?php if ($dcmt_can_delete): ?>
                                        <td>
                                            <?php if ($can_delete_item): ?>
                                            <input type="checkbox" class="form-check-input dcmt-expense-category-checkbox"
                                                   value="<?php echo (int) $category['dcmt_id']; ?>"
                                                   onchange="updateBulkActions()">
                                            <?php endif; ?>
                                        </td>
                                        <?php endif; ?>
                                        <td>
                                            <?php if (isset($category['dcmt_parent_category_id']) && $category['dcmt_parent_category_id']): ?>
                                                <i class="fas fa-level-down-alt text-muted me-2"></i>
                                            <?php endif; ?>
                                            <?php 
                                            // Translate category name if it's a system default
                                            $category_name = $category['dcmt_name'];
                                            if (isset($category['dcmt_created_by']) && $category['dcmt_created_by'] === 'system') {
                                                $translated_name = trans('expense_category', $category_name);
                                                $display_name = ($translated_name !== $category_name) ? $translated_name : $category_name;
                                                echo htmlspecialchars($display_name);
                                            } else {
                                                echo htmlspecialchars($category_name);
                                            }
                                            ?>
                                            <?php if (isset($category['dcmt_parent_category_id']) && $category['dcmt_parent_category_id']): ?>
                                                <small class="text-muted d-block"><?php echo trans('expense_category', 'sub_categories'); ?></small>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php if ($category['parent_name']): ?>
                                                <span class="text-secondary"><?php echo htmlspecialchars($category['parent_name']); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted"><?php echo trans('expense_category', 'main_categories'); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo htmlspecialchars($category['dcmt_description'] ?? ''); ?>
                                        </td>
                                        <td>
                                            <span class="text-<?php echo $category['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                                <?php echo trans('common', $category['dcmt_status']); ?>
                                            </span>
                                        </td>
                                        <td>
                                            <span class="text-info"><?php echo $category['expense_count']; ?> <?php echo trans('expense_category', 'expenses'); ?></span>
                                        </td>
                                        <td>
                                            <?php if ($category['sub_category_count'] > 0): ?>
                                                <span class="text-warning"><?php echo $category['sub_category_count']; ?> <?php echo trans('expense_category', 'sub_categories'); ?></span>
                                            <?php else: ?>
                                                <span class="text-muted">-</span>
                                            <?php endif; ?>
                                        </td>
                                        <td>
                                            <?php echo dcmt_format_date($category['dcmt_created_at']); ?>
                                        </td>
                                        <td>
                                            <div class="btn-group btn-group-sm btn-group-action" role="group">
                                                <a href="edit.php?id=<?php echo $category['dcmt_id']; ?>" 
                                                class="btn" 
                                                title="<?php echo trans('common', 'edit'); ?>">
                                                    <img src="../../assets/images/edit.svg" alt="Edit">
                                                </a>
                                                <?php if ($is_used_category && !$is_system_category): ?>
                                                    <button type="button" 
                                                            class="btn dcmt-disabled-lock-btn" 
                                                            title="<?php echo $category['expense_count'] > 0 ? trans('expense_category', 'cannot_delete_used_category') : trans('expense_category', 'cannot_delete_with_children'); ?>"
                                                            disabled>
                                                        <i class="fas fa-lock text-muted"></i>
                                                    </button>
                                                <?php elseif ($can_delete_item): ?>
                                                    <button type="button" 
                                                            class="btn" 
                                                            title="<?php echo trans('common', 'delete'); ?>"
                                                            onclick="showDeleteModal(<?php echo $category['dcmt_id']; ?>, '<?php echo htmlspecialchars($category['dcmt_name'], ENT_QUOTES); ?>', 'expense')">
                                                        <img src="../../assets/images/delete.svg" alt="Delete">
                                                    </button>
                                                <?php endif; ?>
                                            </div>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo trans('expense_category', 'delete_category'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <h6 class="alert-heading">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo trans('expense_category', 'delete_warning'); ?>
                    </h6>
                    <p class="mb-0" id="deleteMessage">
                        <!-- Message will be inserted here -->
                    </p>
                </div>
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="fas fa-info-circle me-2"></i>
                            <?php echo trans('expense_category', 'category_information'); ?>
                        </h6>
                        <p class="mb-0">
                            <strong><?php echo trans('expense_category', 'category_name'); ?>:</strong> 
                            <span id="categoryName" class="text-primary"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i><?php echo trans('common', 'cancel'); ?>
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-2"></i><?php echo trans('expense_category', 'delete_category'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
window.translations = {
    confirm_deletion: <?php echo json_encode(trans('common', 'confirm_deletion'), JSON_UNESCAPED_UNICODE); ?>,
    warning: <?php echo json_encode(trans('common', 'warning'), JSON_UNESCAPED_UNICODE); ?>,
    cancel: <?php echo json_encode(trans('common', 'cancel'), JSON_UNESCAPED_UNICODE); ?>,
    yes_delete: <?php echo json_encode(trans('common', 'yes_delete'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_single: <?php echo json_encode(trans('expense_category', 'confirm_delete_single'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_multiple: <?php echo json_encode(trans('expense_category', 'confirm_delete_multiple'), JSON_UNESCAPED_UNICODE); ?>
};

window.csrfToken = <?php echo json_encode($csrf_token); ?>;

function getExpenseCategoryCsrfToken() {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    return (csrfMeta && csrfMeta.getAttribute('content')) || window.csrfToken || '';
}

function getExpenseCategoryCheckboxes() {
    return document.querySelectorAll('.dcmt-expense-category-checkbox');
}

function updateBulkActions() {
    const checkboxes = getExpenseCategoryCheckboxes();
    const checkedBoxes = document.querySelectorAll('.dcmt-expense-category-checkbox:checked');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');

    if (!bulkActionsBar || !selectedCount || !selectAllCheckbox) {
        return;
    }

    const count = checkedBoxes.length;

    if (count > 0) {
        bulkActionsBar.style.display = 'block';
        selectedCount.textContent = count + ' <?php echo trans('common', 'selected'); ?>';
    } else {
        bulkActionsBar.style.display = 'none';
    }

    if (checkboxes.length === 0 || count === 0) {
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
    getExpenseCategoryCheckboxes().forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    updateBulkActions();
}

function selectAll() {
    getExpenseCategoryCheckboxes().forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkActions();
}

function deselectAll() {
    getExpenseCategoryCheckboxes().forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkActions();
}

function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.dcmt-expense-category-checkbox:checked');

    if (checkedBoxes.length === 0) {
        alert(<?php echo json_encode(trans('expense_category', 'please_select_one_record'), JSON_UNESCAPED_UNICODE); ?>);
        return;
    }

    const categoryIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value, 10));
    confirmBulkDelete(categoryIds, categoryIds.length);
}

function confirmBulkDelete(categoryIds, count) {
    const message = count === 1
        ? window.translations.confirm_delete_single
        : window.translations.confirm_delete_multiple.replace('{count}', count);

    const existingModal = document.getElementById('deleteConfirmationModal');
    if (existingModal) {
        existingModal.remove();
    }

    const confirmDeletion = window.translations.confirm_deletion;
    const warning = window.translations.warning;
    const cancel = window.translations.cancel;
    const yesDelete = window.translations.yes_delete;

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
                        <button type="button" class="btn btn-danger" onclick="proceedWithBulkDelete([${categoryIds.join(',')}])">
                            <i class="fas fa-trash"></i> ${yesDelete}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
    modal.show();

    document.getElementById('deleteConfirmationModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function proceedWithBulkDelete(categoryIds) {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }

    bulkDeleteAjax(categoryIds);
}

function bulkDeleteAjax(categoryIds) {
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage(<?php echo json_encode(trans('expense_category', 'deleting_records'), JSON_UNESCAPED_UNICODE); ?>);
    }

    fetch('bulk_delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            ids: categoryIds,
            csrf_token: getExpenseCategoryCsrfToken()
        })
    })
    .then(response => response.json())
    .then(data => {
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }

        if (data.success) {
            location.reload();
            return;
        }

        const errorMessage = data.message || <?php echo json_encode(trans('expense_category', 'failed_to_delete_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            showAlert('danger', errorMessage);
        }
    })
    .catch(error => {
        console.error('Error deleting expense categories:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        const errorMessage = <?php echo json_encode(trans('expense_category', 'error_occurred_deleting_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            showAlert('danger', errorMessage);
        }
    });
}

let currentCategoryId = null;
let currentCategoryType = null;
let deleteModal = null;

// Initialize modal when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    
    // Add event listener for confirm delete button
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (currentCategoryId && currentCategoryType) {
            deleteCategory(currentCategoryId, currentCategoryType);
        }
    });
});

// Handle search form submission
function handleSearchKeypress(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        document.getElementById('searchForm').submit();
    }
}

function showDeleteModal(categoryId, categoryName, categoryType) {
    // Check if the delete button is disabled (category is locked)
    const deleteButton = document.querySelector(`button[onclick*="${categoryId}"]`);
    if (deleteButton && deleteButton.disabled) {
        // Show alert for locked category
        showAlert('warning', '<?php echo trans('expense_category', 'cannot_delete_used_category'); ?>');
        return;
    }
    
    currentCategoryId = categoryId;
    currentCategoryType = categoryType;
    
    // Update modal content safely
    const categoryNameElement = document.getElementById('categoryName');
    const deleteMessageElement = document.getElementById('deleteMessage');
    
    if (categoryNameElement) {
        categoryNameElement.textContent = categoryName;
    }
    
    if (deleteMessageElement) {
        if (categoryType === 'expense') {
            deleteMessageElement.textContent = '<?php echo trans('expense_category', 'delete_confirmation_message'); ?>';
        } else {
            deleteMessageElement.textContent = '<?php echo trans('inventory_category', 'delete_confirmation_message'); ?>';
        }
    }
    
    // Show modal
    if (deleteModal) {
        deleteModal.show();
    }
}

function deleteCategory(categoryId, categoryType) {
    // Show loading state
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Deleting...';
    confirmBtn.disabled = true;
    
    // Prepare form data
    const formData = new FormData();
    formData.append('id', categoryId);
    formData.append('csrf_token', '<?php echo dcmt_generate_csrf_token(); ?>');
    
    // Determine the correct endpoint
    const endpoint = categoryType === 'expense' ? 'delete_ajax.php' : '../inventory_categories/delete_ajax.php';
    
    // Make AJAX request
    fetch(endpoint, {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showAlert('success', data.message);
            
            // Remove the row from the table
            const row = document.querySelector(`button[onclick*="${categoryId}"]`).closest('tr');
            if (row) {
                row.remove();
            }
            
            // Hide modal
            if (deleteModal) {
                deleteModal.hide();
            }
            
            // Check if table is empty
            const tbody = document.querySelector('tbody');
            if (tbody && tbody.children.length === 0) {
                // Reload page to show "no categories" message
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        } else {
            // Show error message
            showAlert('danger', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('danger', 'An error occurred while deleting the category.');
    })
    .finally(() => {
        // Reset button state
        confirmBtn.innerHTML = originalText;
        confirmBtn.disabled = false;
    });
}

function showAlert(type, message) {
    // Remove existing alerts
    const existingAlerts = document.querySelectorAll('.alert-dismissible');
    existingAlerts.forEach(alert => alert.remove());
    
    // Create new alert
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    // Insert alert at the top of the main content area (after header)
    const mainContent = document.querySelector('.main-content');
    if (mainContent) {
        // Insert after the first child (which should be the page content)
        const firstChild = mainContent.firstElementChild;
        if (firstChild) {
            mainContent.insertBefore(alertDiv, firstChild);
        } else {
            mainContent.appendChild(alertDiv);
        }
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
