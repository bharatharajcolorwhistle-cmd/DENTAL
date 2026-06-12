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
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
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
                            <tr>
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
                                        <?php if ($specialization['doctor_count'] > 0): ?>
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
// Pass translations to JavaScript for specializations
window.translations = {
    confirm_deletion: '<?php echo trans('common', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('common', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('specialization', 'confirm_delete') ?: trans('common', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('common', 'cancel'); ?>',
    yes_delete: '<?php echo trans('common', 'yes_delete'); ?>',
    specialization: '<?php echo trans('specialization', 'specialization'); ?>',
    confirm_delete_single: '<?php echo trans('specialization', 'confirm_delete'); ?>'
};

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
