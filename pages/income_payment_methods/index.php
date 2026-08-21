<?php
/**
 * Income Payment Methods Management - Index Page
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
$dcmt_can_delete = dcmt_can_delete_records();

require_once __DIR__ . '/../../includes/header.php';

$csrf_token = dcmt_generate_csrf_token();
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
<?php

if (isset($_SESSION['income_payment_method_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['income_payment_method_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['income_payment_method_delete_success']);
}

if (isset($_SESSION['income_payment_method_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['income_payment_method_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['income_payment_method_delete_error']);
}
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
               COUNT(DISTINCT i.dcmt_id) as income_count
        FROM dcmt_income_payment_methods pm
        LEFT JOIN dcmt_income i ON pm.dcmt_id = i.dcmt_payment_method_id
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
    error_log("Income payment methods fetch error: " . $e->getMessage());
    $payment_methods = [];
    // Only show error message if it's a real database error, not a search issue
    if (empty($search)) {
        dcmt_show_message(trans('income_payment_method', 'load_error'), 'danger');
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
                       placeholder="<?php echo trans('income_payment_method', 'search_placeholder'); ?>"
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
                    <?php echo trans('income_payment_method', 'search_results_for'); ?>: <strong><?php echo htmlspecialchars($search); ?></strong>
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
            <?php echo trans('income_payment_method', 'income_payment_methods'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('income_payment_method', 'add_payment_method'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($payment_methods)): ?>
            <div class="text-center py-4">
                <h5 class="text-muted"><?php echo trans('income_payment_method', 'no_payment_methods_found'); ?></h5>
                <p class="text-muted"><?php echo trans('income_payment_method', 'no_payment_methods_message'); ?></p>
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
                            <th><?php echo trans('income_payment_method', 'name'); ?></th>
                            <th><?php echo trans('common', 'description'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('income_payment_method', 'usage_count'); ?></th>
                            <th><?php echo trans('common', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($payment_methods as $method): ?>
                            <?php
                            $is_system_item = (($method['dcmt_created_by'] ?? '') === 'system');
                            $is_used_item = ((int) $method['income_count'] > 0);
                            $can_delete_item = $dcmt_can_delete && !$is_system_item && !$is_used_item;
                            ?>
                            <tr>
                                <?php if ($dcmt_can_delete): ?>
                                <td>
                                    <?php if ($can_delete_item): ?>
                                    <input type="checkbox" class="form-check-input dcmt-income-payment-method-checkbox"
                                           value="<?php echo (int) $method['dcmt_id']; ?>"
                                           onchange="updateBulkActions()">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div class="d-flex align-items-center">
                                        <div>
                                            <?php 
                                            // Check if this is a system default payment method
                                            $display_name = $method['dcmt_name'];
                                            if ($method['dcmt_created_by'] === 'system') {
                                                // Try to translate the name, fallback to original if translation not found
                                                $translated_name = trans('income_payment_method', $display_name);
                                                echo htmlspecialchars($translated_name !== $display_name ? $translated_name : $display_name);
                                            } else {
                                                echo htmlspecialchars($display_name);
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
                                    <span class="text-info"><?php echo $method['income_count']; ?> <?php echo trans('income_payment_method', 'income_records'); ?></span>
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
                                        <?php if ($is_used_item && !$is_system_item): ?>
                                            <button type="button" 
                                                    class="btn dcmt-btn-borderless" 
                                                    title="<?php echo trans('income_payment_method', 'cannot_delete_in_use'); ?>"
                                                    disabled>
                                                <i class="fas fa-lock text-muted"></i>
                                            </button>
                                        <?php elseif ($can_delete_item): ?>
                                            <button type="button" 
                                                    class="btn" 
                                                    title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="showDeleteModal(<?php echo $method['dcmt_id']; ?>, '<?php echo htmlspecialchars($method['dcmt_name'], ENT_QUOTES); ?>', 'income_payment_method')">
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
                    <i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('income_payment_method', 'confirm_deletion'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p><?php echo trans('income_payment_method', 'confirm_delete_warning'); ?></p>
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <strong><?php echo trans('common', 'warning'); ?>:</strong> <?php echo trans('income_payment_method', 'delete_warning_message'); ?>
                </div>
                <p><strong><?php echo trans('income_payment_method', 'payment_method'); ?>:</strong> <span id="deleteItemName"></span></p>
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
window.translations = {
    confirm_deletion: <?php echo json_encode(trans('common', 'confirm_deletion'), JSON_UNESCAPED_UNICODE); ?>,
    warning: <?php echo json_encode(trans('common', 'warning'), JSON_UNESCAPED_UNICODE); ?>,
    cancel: <?php echo json_encode(trans('common', 'cancel'), JSON_UNESCAPED_UNICODE); ?>,
    yes_delete: <?php echo json_encode(trans('common', 'yes_delete'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_single: <?php echo json_encode(trans('income_payment_method', 'confirm_delete_single'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_multiple: <?php echo json_encode(trans('income_payment_method', 'confirm_delete_multiple'), JSON_UNESCAPED_UNICODE); ?>
};

window.csrfToken = <?php echo json_encode($csrf_token); ?>;

function getBulkCsrfToken() {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    return (csrfMeta && csrfMeta.getAttribute('content')) || window.csrfToken || '';
}

function getBulkCheckboxes() {
    return document.querySelectorAll('.dcmt-income-payment-method-checkbox');
}

function updateBulkActions() {
    const checkboxes = getBulkCheckboxes();
    const checkedBoxes = document.querySelectorAll('.dcmt-income-payment-method-checkbox:checked');
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
    getBulkCheckboxes().forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    updateBulkActions();
}

function selectAll() {
    getBulkCheckboxes().forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkActions();
}

function deselectAll() {
    getBulkCheckboxes().forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkActions();
}

function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.dcmt-income-payment-method-checkbox:checked');

    if (checkedBoxes.length === 0) {
        alert(<?php echo json_encode(trans('income_payment_method', 'please_select_one_record'), JSON_UNESCAPED_UNICODE); ?>);
        return;
    }

    const itemIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value, 10));
    confirmBulkDelete(itemIds, itemIds.length);
}

function confirmBulkDelete(itemIds, count) {
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
                        <button type="button" class="btn btn-danger" onclick="proceedWithBulkDelete([${itemIds.join(',')}])">
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

function proceedWithBulkDelete(itemIds) {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }

    bulkDeleteAjax(itemIds);
}

function bulkDeleteAjax(itemIds) {
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage(<?php echo json_encode(trans('income_payment_method', 'deleting_records'), JSON_UNESCAPED_UNICODE); ?>);
    }

    fetch('bulk_delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            ids: itemIds,
            csrf_token: getBulkCsrfToken()
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

        const errorMessage = data.message || <?php echo json_encode(trans('income_payment_method', 'failed_to_delete_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            alert(errorMessage);
        }
    })
    .catch(error => {
        console.error('Error deleting payment methods:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        const errorMessage = <?php echo json_encode(trans('income_payment_method', 'error_occurred_deleting_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            alert(errorMessage);
        }
    });
}

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
