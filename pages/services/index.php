<?php
/**
 * Services Management - Index Page
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

// Get search parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';

// Pagination parameters
$page = max(1, intval($_GET['page'] ?? 1));
$limit = DCMT_PER_PAGE;
$offset = ($page - 1) * $limit;

// Build query
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(s.dcmt_name LIKE ? OR s.dcmt_description LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status)) {
    $where_conditions[] = "s.dcmt_status = ?";
    $params[] = $status;
}

$where_clause = !empty($where_conditions) ? "WHERE " . implode(" AND ", $where_conditions) : "";

try {
    // Get total count for pagination
    $count_sql = "
        SELECT COUNT(*) as total 
        FROM dcmt_services s
        $where_clause
    ";
    $stmt = $dcmt_pdo->prepare($count_sql);
    $stmt->execute($params);
    $total_records = $stmt->fetch()['total'];
    $total_pages = ceil($total_records / $limit);

    // Get services with pagination
    $sql = "
        SELECT s.*, 
               COALESCE(doctor_counts.doctor_count, 0) as doctor_count,
               COALESCE(income_counts.usage_count, 0) as usage_count
        FROM dcmt_services s
        LEFT JOIN (
            SELECT dcmt_service_id, COUNT(DISTINCT dcmt_user_id) as doctor_count
            FROM dcmt_doctor_services 
            WHERE dcmt_status = 'active'
            GROUP BY dcmt_service_id
        ) doctor_counts ON s.dcmt_id = doctor_counts.dcmt_service_id
        LEFT JOIN (
            SELECT usage_rows.dcmt_service_id, SUM(usage_rows.usage_count) as usage_count
            FROM (
                -- New model: service usage is stored in income breakdown rows
                SELECT ib.dcmt_reference_id as dcmt_service_id,
                       COALESCE(SUM(ib.dcmt_quantity), 0) as usage_count
                FROM dcmt_income_breakdown ib
                WHERE ib.dcmt_line_type = 'service'
                  AND ib.dcmt_reference_id IS NOT NULL
                GROUP BY ib.dcmt_reference_id

                UNION ALL

                -- Legacy fallback for rows created before breakdown adoption
                SELECT i.dcmt_service_id,
                       COUNT(*) as usage_count
                FROM dcmt_income i
                WHERE i.dcmt_service_id IS NOT NULL
                  AND NOT EXISTS (
                      SELECT 1
                      FROM dcmt_income_breakdown ib_legacy
                      WHERE ib_legacy.dcmt_id = i.dcmt_id
                        AND ib_legacy.dcmt_line_type = 'service'
                  )
                GROUP BY i.dcmt_service_id
            ) usage_rows
            GROUP BY usage_rows.dcmt_service_id
        ) income_counts ON s.dcmt_id = income_counts.dcmt_service_id
        $where_clause
        ORDER BY s.dcmt_name
        LIMIT ? OFFSET ?
    ";
    
    $params[] = $limit;
    $params[] = $offset;
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $services = $stmt->fetchAll();
    
} catch (PDOException $e) {
    error_log("Services fetch error: " . $e->getMessage());
    $services = [];
    dcmt_show_message(trans('common', 'load_error'), 'danger');
}

require_once __DIR__ . '/../../includes/header.php';

$csrf_token = dcmt_generate_csrf_token();
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
<?php

if (isset($_SESSION['service_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['service_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['service_delete_success']);
}

if (isset($_SESSION['service_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['service_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['service_delete_error']);
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
                       placeholder="<?php echo trans('service', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label"><?php echo trans('common', 'status'); ?></label>
                <select class="form-select dcmt-filter-field" id="status" name="status">
                    <option value=""><?php echo trans('service', 'all_status'); ?></option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                </select>
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
    </div>
</div>

<!-- Services List -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('service', 'services'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('service', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('service', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('service', 'add_service'); ?></a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($services)): ?>
            <div class="text-center py-4">
                <i class="fas fa-concierge-bell fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('service', 'no_services_found'); ?></h5>
                <p class="text-muted"><?php echo trans('service', 'start_adding_service'); ?></p>
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
                            <th><?php echo trans('service', 'service_name'); ?></th>
                            <th><?php echo trans('common', 'description'); ?></th>
                            <th><?php echo trans('service', 'base_price'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('service', 'doctors_assigned'); ?></th>
                            <th><?php echo trans('service', 'times_used'); ?></th>
                            <th><?php echo trans('common', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($services as $service): ?>
                            <?php
                            $is_used_service = ((int) $service['doctor_count'] > 0) || ((float) ($service['usage_count'] ?? 0) > 0);
                            $can_delete_item = $dcmt_can_delete && !$is_used_service;
                            ?>
                            <tr>
                                <?php if ($dcmt_can_delete): ?>
                                <td>
                                    <?php if ($can_delete_item): ?>
                                    <input type="checkbox" class="form-check-input dcmt-service-checkbox"
                                           value="<?php echo (int) $service['dcmt_id']; ?>"
                                           onchange="updateBulkActions()">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php echo htmlspecialchars($service['dcmt_name']); ?>
                                </td>
                                <td>
                                    <?php 
                                    $description = $service['dcmt_description'] ?? '';
                                    echo !empty($description) ? htmlspecialchars($description) : '<span class="text-muted">-</span>';
                                    ?>
                                </td>
                                <td>
                                    <span class="">
                                        <?php echo dcmt_format_currency($service['dcmt_base_price']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-<?php echo $service['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo trans('common', $service['dcmt_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class=""><?php echo $service['doctor_count']; ?> <?php echo trans('service', 'doctors'); ?></span>
                                </td>
                                <td>
                                    <?php
                                    $usage_count_raw = (float)($service['usage_count'] ?? 0);
                                    $usage_count_display = fmod($usage_count_raw, 1.0) === 0.0
                                        ? (string)(int)$usage_count_raw
                                        : rtrim(rtrim(number_format($usage_count_raw, 2, '.', ''), '0'), '.');
                                    ?>
                                    <span class=""><?php echo $usage_count_display; ?> <?php echo trans('service', 'times'); ?></span>
                                </td>
                                <td><?php echo date('Y-m-d', strtotime($service['dcmt_created_at'])); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $service['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <a href="edit.php?id=<?php echo $service['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($is_used_service): ?>
                                            <button type="button" 
                                                    class="btn dcmt-btn-borderless" 
                                                    title="<?php echo trans('service', 'cannot_delete_used_service'); ?>"
                                                    disabled>
                                                <i class="fas fa-lock text-muted"></i>
                                            </button>
                                        <?php elseif ($dcmt_can_delete): ?>
                                            <button type="button" 
                                                    class="btn" 
                                                    title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="showDeleteModal(<?php echo $service['dcmt_id']; ?>, '<?php echo htmlspecialchars($service['dcmt_name'], ENT_QUOTES); ?>', 'service')">
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
            
            <!-- Pagination -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('service', 'services_pagination'); ?>">
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

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="deleteModal" tabindex="-1" aria-labelledby="deleteModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title" id="deleteModalLabel">
                    <i class="fas fa-exclamation-triangle me-2"></i>
                    <?php echo trans('service', 'delete_service'); ?>
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning">
                    <h6 class="alert-heading">
                        <i class="fas fa-exclamation-triangle me-2"></i>
                        <?php echo trans('service', 'delete_warning'); ?>
                    </h6>
                    <p class="mb-0" id="deleteMessage">
                        <!-- Message will be inserted here -->
                    </p>
                </div>
                <div class="card">
                    <div class="card-body">
                        <h6 class="card-title">
                            <i class="fas fa-info-circle me-2"></i>
                            <?php echo trans('service', 'service_information'); ?>
                        </h6>
                        <p class="mb-0">
                            <strong><?php echo trans('service', 'service_name'); ?>:</strong> 
                            <span id="serviceName" class="text-primary"></span>
                        </p>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <i class="fas fa-times me-2"></i><?php echo trans('common', 'cancel'); ?>
                </button>
                <button type="button" class="btn btn-danger" id="confirmDeleteBtn">
                    <i class="fas fa-trash me-2"></i><?php echo trans('service', 'delete_service'); ?>
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
    confirm_delete_single: <?php echo json_encode(trans('service', 'confirm_delete_single'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_multiple: <?php echo json_encode(trans('service', 'confirm_delete_multiple'), JSON_UNESCAPED_UNICODE); ?>
};

window.csrfToken = <?php echo json_encode($csrf_token); ?>;

function getServiceCsrfToken() {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    return (csrfMeta && csrfMeta.getAttribute('content')) || window.csrfToken || '';
}

function getServiceCheckboxes() {
    return document.querySelectorAll('.dcmt-service-checkbox');
}

function updateBulkActions() {
    const checkboxes = getServiceCheckboxes();
    const checkedBoxes = document.querySelectorAll('.dcmt-service-checkbox:checked');
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
    getServiceCheckboxes().forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    updateBulkActions();
}

function selectAll() {
    getServiceCheckboxes().forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkActions();
}

function deselectAll() {
    getServiceCheckboxes().forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkActions();
}

function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.dcmt-service-checkbox:checked');

    if (checkedBoxes.length === 0) {
        alert(<?php echo json_encode(trans('service', 'please_select_one_record'), JSON_UNESCAPED_UNICODE); ?>);
        return;
    }

    const serviceIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value, 10));
    confirmBulkDelete(serviceIds, serviceIds.length);
}

function confirmBulkDelete(serviceIds, count) {
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
                        <button type="button" class="btn btn-danger" onclick="proceedWithBulkDelete([${serviceIds.join(',')}])">
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

function proceedWithBulkDelete(serviceIds) {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }

    bulkDeleteAjax(serviceIds);
}

function bulkDeleteAjax(serviceIds) {
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage(<?php echo json_encode(trans('service', 'deleting_records'), JSON_UNESCAPED_UNICODE); ?>);
    }

    fetch('bulk_delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            ids: serviceIds,
            csrf_token: getServiceCsrfToken()
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

        const errorMessage = data.message || <?php echo json_encode(trans('service', 'failed_to_delete_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            showAlert('danger', errorMessage);
        }
    })
    .catch(error => {
        console.error('Error deleting service records:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        const errorMessage = <?php echo json_encode(trans('service', 'error_occurred_deleting_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            showAlert('danger', errorMessage);
        }
    });
}

let currentServiceId = null;
let currentServiceType = null;
let deleteModal = null;

// Initialize modal when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    deleteModal = new bootstrap.Modal(document.getElementById('deleteModal'));
    
    // Add event listener for confirm delete button
    document.getElementById('confirmDeleteBtn').addEventListener('click', function() {
        if (currentServiceId && currentServiceType) {
            deleteService(currentServiceId, currentServiceType);
        }
    });
});

function showDeleteModal(serviceId, serviceName, serviceType) {
    // Check if the delete button is disabled (service is locked)
    const deleteButton = document.querySelector(`button[onclick*="${serviceId}"]`);
    if (deleteButton && deleteButton.disabled) {
        // Show alert for locked service
        showAlert('warning', '<?php echo trans('service', 'cannot_delete_used_service'); ?>');
        return;
    }
    
    currentServiceId = serviceId;
    currentServiceType = serviceType;
    
    // Update modal content safely
    const serviceNameElement = document.getElementById('serviceName');
    const deleteMessageElement = document.getElementById('deleteMessage');
    
    if (serviceNameElement) {
        serviceNameElement.textContent = serviceName;
    }
    
    if (deleteMessageElement) {
        deleteMessageElement.textContent = '<?php echo trans('service', 'delete_confirmation_message'); ?>';
    }
    
    // Show modal
    if (deleteModal) {
        deleteModal.show();
    }
}

function deleteService(serviceId, serviceType) {
    // Show loading state
    const confirmBtn = document.getElementById('confirmDeleteBtn');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i>Deleting...';
    confirmBtn.disabled = true;
    
    // Prepare form data
    const formData = new FormData();
    formData.append('id', serviceId);
    formData.append('csrf_token', '<?php echo dcmt_generate_csrf_token(); ?>');
    
    // Make AJAX request
    fetch('delete_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show success message
            showAlert('success', data.message);
            
            // Remove the row from the table
            const row = document.querySelector(`button[onclick*="${serviceId}"]`).closest('tr');
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
                // Reload page to show "no services" message
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
        showAlert('danger', 'An error occurred while deleting the service.');
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
