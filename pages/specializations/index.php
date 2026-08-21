<?php
/**
 * Doctor Specializations Management - Index Page
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

// Generate CSRF token for AJAX requests
$csrf_token = dcmt_generate_csrf_token();
?>

<meta name="csrf-token" content="<?php echo $csrf_token; ?>">

<?php
// Display session messages
if (isset($_SESSION['specialization_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['specialization_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['specialization_delete_success']);
}

if (isset($_SESSION['specialization_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['specialization_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['specialization_delete_error']);
}
?>

<?php
// Get search parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(s.dcmt_name LIKE ? OR s.dcmt_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

try {
    $sql = "
        SELECT s.*, 
               COUNT(DISTINCT u.dcmt_id) as doctor_count
        FROM dcmt_doctor_specializations s
        LEFT JOIN dcmt_users u ON s.dcmt_id = u.dcmt_specialization_id AND u.dcmt_role = 'doctor'
        $where_clause
        GROUP BY s.dcmt_id
        ORDER BY s.dcmt_name
    ";
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $specializations = $stmt->fetchAll();
    
    // Clear any previous error messages if query was successful
    if (isset($_SESSION['dcmt_message'])) {
        unset($_SESSION['dcmt_message']);
    }
    
} catch (PDOException $e) {
    error_log("Doctor specializations fetch error: " . $e->getMessage());
    $specializations = [];
    // Only show error message if it's a real database error, not a search issue
    if (empty($search)) {
        dcmt_show_message(trans('specialization', 'load_error'), 'danger');
    }
}
?>

<!-- Search Form -->
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="<?php echo trans('specialization', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="?" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Specializations Table -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <i class="fas fa-user-md dcmt-view-card-title-icon"></i><?php echo trans('specialization', 'specializations'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('specialization', 'add_specialization'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($specializations)): ?>
            <div class="text-center py-4">
                <i class="fas fa-user-md fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('specialization', 'no_specializations_found'); ?></h5>
                <p class="text-muted"><?php echo trans('specialization', 'try_adjusting_search'); ?></p>
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

                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <?php if ($dcmt_can_delete): ?>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" class="form-check-input">
                            </th>
                            <?php endif; ?>
                            <th><?php echo trans('specialization', 'name'); ?></th>
                            <th><?php echo trans('specialization', 'description'); ?></th>
                            <th><?php echo trans('specialization', 'doctors_count'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('common', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($specializations as $specialization): ?>
                            <?php
                            $is_used_specialization = ((int) $specialization['doctor_count'] > 0);
                            $can_delete_item = $dcmt_can_delete && !$is_used_specialization;
                            ?>
                            <tr>
                                <?php if ($dcmt_can_delete): ?>
                                <td>
                                    <?php if ($can_delete_item): ?>
                                    <input type="checkbox" class="form-check-input dcmt-specialization-checkbox"
                                           value="<?php echo (int) $specialization['dcmt_id']; ?>"
                                           onchange="updateBulkActions()">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php echo htmlspecialchars($specialization['dcmt_name']); ?>
                                </td>
                                <td>
                                    <?php if (!empty($specialization['dcmt_description'])): ?>
                                        <?php echo htmlspecialchars($specialization['dcmt_description']); ?>
                                    <?php else: ?>
                                        <span class="text-muted"><?php echo trans('specialization', 'no_description'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span><?php echo $specialization['doctor_count']; ?></span>
                                </td>
                                <td>
                                    <span class="text-<?php echo $specialization['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($specialization['dcmt_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span title="<?php echo $specialization['dcmt_created_at']; ?>">
                                        <?php echo dcmt_format_date($specialization['dcmt_created_at']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="edit.php?id=<?php echo $specialization['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($is_used_specialization): ?>
                                            <button type="button" 
                                                    class="btn dcmt-btn-borderless" 
                                                    title="<?php echo trans('specialization', 'locked_specialization_message'); ?> (<?php echo $specialization['doctor_count']; ?> <?php echo trans('specialization', 'doctors'); ?>)"
                                                    disabled>
                                                <i class="fas fa-lock text-muted"></i>
                                            </button>
                                        <?php elseif ($dcmt_can_delete): ?>
                                            <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="confirmDelete(<?php echo $specialization['dcmt_id']; ?>, 'specialization')">
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

<script>
window.translations = {
    confirm_deletion: <?php echo json_encode(trans('common', 'confirm_deletion'), JSON_UNESCAPED_UNICODE); ?>,
    warning: <?php echo json_encode(trans('common', 'warning'), JSON_UNESCAPED_UNICODE); ?>,
    delete_confirmation_message: <?php echo json_encode(trans('specialization', 'confirm_delete') ?: trans('common', 'delete_confirmation_message'), JSON_UNESCAPED_UNICODE); ?>,
    cancel: <?php echo json_encode(trans('common', 'cancel'), JSON_UNESCAPED_UNICODE); ?>,
    yes_delete: <?php echo json_encode(trans('common', 'yes_delete'), JSON_UNESCAPED_UNICODE); ?>,
    specialization: <?php echo json_encode(trans('specialization', 'specialization'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_single: <?php echo json_encode(trans('specialization', 'confirm_delete_single'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_multiple: <?php echo json_encode(trans('specialization', 'confirm_delete_multiple'), JSON_UNESCAPED_UNICODE); ?>
};

window.csrfToken = <?php echo json_encode($csrf_token); ?>;

function getSpecializationCsrfToken() {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    return (csrfMeta && csrfMeta.getAttribute('content')) || window.csrfToken || '';
}

function getSpecializationCheckboxes() {
    return document.querySelectorAll('.dcmt-specialization-checkbox');
}

function updateBulkActions() {
    const checkboxes = getSpecializationCheckboxes();
    const checkedBoxes = document.querySelectorAll('.dcmt-specialization-checkbox:checked');
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
    getSpecializationCheckboxes().forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    updateBulkActions();
}

function selectAll() {
    getSpecializationCheckboxes().forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkActions();
}

function deselectAll() {
    getSpecializationCheckboxes().forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkActions();
}

function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.dcmt-specialization-checkbox:checked');

    if (checkedBoxes.length === 0) {
        alert(<?php echo json_encode(trans('specialization', 'please_select_one_record'), JSON_UNESCAPED_UNICODE); ?>);
        return;
    }

    const specializationIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value, 10));
    confirmBulkDelete(specializationIds, specializationIds.length);
}

function confirmBulkDelete(specializationIds, count) {
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
                        <button type="button" class="btn btn-danger" onclick="proceedWithBulkDelete([${specializationIds.join(',')}])">
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

function proceedWithBulkDelete(specializationIds) {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }

    bulkDeleteAjax(specializationIds);
}

function bulkDeleteAjax(specializationIds) {
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage(<?php echo json_encode(trans('specialization', 'deleting_records'), JSON_UNESCAPED_UNICODE); ?>);
    }

    fetch('bulk_delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            ids: specializationIds,
            csrf_token: getSpecializationCsrfToken()
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

        const errorMessage = data.message || <?php echo json_encode(trans('specialization', 'failed_to_delete_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            showAlert('danger', errorMessage);
        }
    })
    .catch(error => {
        console.error('Error deleting specialization records:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        const errorMessage = <?php echo json_encode(trans('specialization', 'error_occurred_deleting_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            showAlert('danger', errorMessage);
        }
    });
}

// Handle lock icon clicks for used specializations
document.addEventListener('DOMContentLoaded', function() {
    const lockButtons = document.querySelectorAll('button[disabled] i.fa-lock');
    lockButtons.forEach(function(lockIcon) {
        lockIcon.addEventListener('click', function(e) {
            e.preventDefault();
            e.stopPropagation();
            
            const button = this.closest('button');
            const tooltip = button.getAttribute('title');
            
            // Show alert for locked specialization
            showAlert('warning', tooltip);
        });
    });
});

// Alert function for showing messages
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
    const mainContent = document.querySelector('.container-fluid') || document.querySelector('main') || document.body;
    const firstChild = mainContent.firstChild;
    if (firstChild) {
        mainContent.insertBefore(alertDiv, firstChild);
    } else {
        mainContent.appendChild(alertDiv);
    }

    // Auto-remove alert after 5 seconds
    setTimeout(() => {
        if (alertDiv.parentNode) {
            alertDiv.remove();
        }
    }, 5000);
}

// The main.js file will handle specialization deletion via AJAX
// No custom functions needed here
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
