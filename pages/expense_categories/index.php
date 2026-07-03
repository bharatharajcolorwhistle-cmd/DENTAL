<?php
/**
 * Expense Categories Management - Index Page
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
$dcmt_can_delete = dcmt_can_delete_records();

require_once __DIR__ . '/../../includes/header.php';
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
    // Check if parent_category_id column exists for backward compatibility
    $column_exists = false;
    try {
        $check_stmt = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_expense_categories LIKE 'dcmt_parent_category_id'");
        $column_exists = $check_stmt->rowCount() > 0;
    } catch (Exception $e) {
        // Column doesn't exist, use old format
    }
    
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
                        <table class="table table-hover">
                            <thead>
                                <tr>
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
                                    <tr class="<?php echo (isset($category['dcmt_parent_category_id']) && $category['dcmt_parent_category_id']) ? 'table-light' : ''; ?>">
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
                                                <?php if (($category['expense_count'] > 0 || $category['sub_category_count'] > 0) && $category['dcmt_created_by'] !== 'system'): ?>
                                                    <button type="button" 
                                                            class="btn dcmt-disabled-lock-btn" 
                                                            title="<?php echo $category['expense_count'] > 0 ? trans('expense_category', 'cannot_delete_used_category') : trans('expense_category', 'cannot_delete_with_children'); ?>"
                                                            disabled>
                                                        <i class="fas fa-lock text-muted"></i>
                                                    </button>
                                                <?php elseif ($dcmt_can_delete && $category['dcmt_created_by'] !== 'system'): ?>
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
