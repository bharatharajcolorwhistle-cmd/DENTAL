<?php
/**
 * Expense Payment Methods Management - Index Page
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
dcmt_require_admin_or_doctor();

require_once __DIR__ . '/../../includes/header.php';
?>

<?php
// Get search parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(pm.dcmt_name LIKE ? OR pm.dcmt_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

try {
    $sql = "
        SELECT pm.*, 
               COUNT(DISTINCT e.dcmt_id) as expense_count
        FROM dcmt_expense_payment_methods pm
        LEFT JOIN dcmt_expenses e ON pm.dcmt_id = e.dcmt_payment_method_id
        $where_clause
        GROUP BY pm.dcmt_id
        ORDER BY pm.dcmt_name
    ";
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $payment_methods = $stmt->fetchAll();
    
    // Clear any previous error messages if query was successful
    if (isset($_SESSION['dcmt_message'])) {
        unset($_SESSION['dcmt_message']);
    }
    
} catch (PDOException $e) {
    error_log("Expense payment methods fetch error: " . $e->getMessage());
    $payment_methods = [];
    // Only show error message if it's a real database error, not a search issue
    if (empty($search)) {
        dcmt_show_message(trans('expense_payment_method', 'load_error'), 'danger');
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
                       placeholder="<?php echo trans('expense_payment_method', 'search_placeholder'); ?>"
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
                    <?php echo trans('expense_payment_method', 'search_results_for'); ?>: <strong><?php echo htmlspecialchars($search); ?></strong>
                    <span class="badge bg-primary ms-2"><?php echo count($payment_methods); ?> <?php echo trans('common', 'results'); ?></span>
                </div>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Payment Methods List -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <?php echo trans('expense_payment_method', 'expense_payment_methods'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('expense_payment_method', 'add_payment_method'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($payment_methods)): ?>
            <div class="text-center py-4">
                <h5 class="text-muted"><?php echo trans('expense_payment_method', 'no_payment_methods_found'); ?></h5>
                <p class="text-muted"><?php echo trans('expense_payment_method', 'no_payment_methods_message'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('expense_payment_method', 'name'); ?></th>
                            <th><?php echo trans('common', 'description'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('expense_payment_method', 'usage_count'); ?></th>
                            <th><?php echo trans('common', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payment_methods as $method): ?>
                            <tr>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div>
                                            <?php 
                                            // Translate payment method name
                                            $method_name = $method['dcmt_name'];
                                            if ($method['dcmt_created_by'] === 'system') {
                                                $translated_name = trans('expense_payment_method', $method_name);
                                                echo htmlspecialchars($translated_name !== $method_name ? $translated_name : $method_name);
                                            } else {
                                                echo htmlspecialchars($method_name);
                                            }
                                            ?>
                                        </div>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($method['dcmt_description'])): ?>
                                        <?php echo htmlspecialchars($method['dcmt_description']); ?>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-<?php echo $method['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo trans('common', $method['dcmt_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-info"><?php echo $method['expense_count']; ?> <?php echo trans('expense_payment_method', 'expense_records'); ?></span>
                                </td>
                                <td>
                                    <?php echo dcmt_format_date($method['dcmt_created_at']); ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="edit.php?id=<?php echo $method['dcmt_id']; ?>" 
                                           class="btn" 
                                           title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($method['expense_count'] > 0 && $method['dcmt_created_by'] !== 'system'): ?>
                                            <button type="button" 
                                                    class="btn dcmt-btn-borderless" 
                                                    title="<?php echo trans('expense_payment_method', 'cannot_delete_in_use'); ?>"
                                                    disabled>
                                                <i class="fas fa-lock text-muted"></i>
                                            </button>
                                        <?php elseif ($method['dcmt_created_by'] !== 'system'): ?>
                                            <button type="button" 
                                                    class="btn" 
                                                    title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="showDeleteModal(<?php echo $method['dcmt_id']; ?>, '<?php echo htmlspecialchars($method['dcmt_name'], ENT_QUOTES); ?>', 'expense_payment_method')">
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
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('expense_payment_method', 'confirm_deletion'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><?php echo trans('expense_payment_method', 'confirm_delete_warning'); ?></p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong><?php echo trans('common', 'warning'); ?>:</strong> <?php echo trans('expense_payment_method', 'delete_warning_message'); ?>
                </div>
                <p><strong><?php echo trans('expense_payment_method', 'payment_method'); ?>:</strong> <span id="deleteItemName"></span></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'cancel'); ?>
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-1"></i><?php echo trans('common', 'delete'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let deleteItemId = null;
let deleteItemType = null;

function showDeleteModal(itemId, itemName, itemType) {
    deleteItemId = itemId;
    deleteItemType = itemType;
    document.getElementById('deleteItemName').textContent = itemName;
    
    const modal = new bootstrap.Modal(document.getElementById('deleteModal'));
    modal.show();
}


document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
    if (deleteItemId && deleteItemType) {
        // Disable button and show loading
        const btn = this;
        btn.disabled = true;
        btn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'deleting'); ?>...';
        
        // Create form data
        const formData = new FormData();
        formData.append('id', deleteItemId);
        formData.append('csrf_token', '<?php echo dcmt_generate_csrf_token(); ?>');
        
        // Send AJAX request
        fetch('delete_ajax.php', {
            method: 'POST',
            body: formData
        })
        .then(response => response.json())
        .then(data => {
            if (data.success) {
                // Reload page without showing alert
                window.location.reload();
            } else {
                // Show error message and re-enable button
                alert(data.message);
                btn.disabled = false;
                btn.innerHTML = '<i class="fas fa-trash me-1"></i><?php echo trans('common', 'delete'); ?>';
            }
        })
        .catch(error => {
            console.error('Error:', error);
            alert('<?php echo trans('common', 'error_occurred'); ?>');
            btn.disabled = false;
            btn.innerHTML = '<i class="fas fa-trash me-1"></i><?php echo trans('common', 'delete'); ?>';
        });
    }
});

function handleSearchKeypress(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        document.getElementById('searchBtn').click();
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
