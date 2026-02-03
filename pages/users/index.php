<?php
/**
 * Users List Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check admin access
dcmt_require_admin_or_doctor();

// Get search and filter parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$role = isset($_GET['role']) ? dcmt_sanitize_input($_GET['role']) : '';
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';

// Pagination
$page = isset($_GET['page']) ? max(1, intval($_GET['page'])) : 1;
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(dcmt_username LIKE ? OR dcmt_email LIKE ? OR dcmt_full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($role)) {
    $where_conditions[] = "dcmt_role = ?";
    $params[] = $role;
}

if (!empty($status)) {
    $where_conditions[] = "dcmt_status = ?";
    $params[] = $status;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
try {
    $count_sql = "SELECT COUNT(*) FROM dcmt_users $where_clause";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = $count_stmt->fetchColumn();
    $total_pages = ceil($total_records / $per_page);
} catch (PDOException $e) {
    dcmt_show_message(trans('user', 'database_error'), "error");
    $total_records = 0;
    $total_pages = 0;
}

// Get users for current page
try {
    $sql = "SELECT * FROM dcmt_users $where_clause ORDER BY dcmt_created_at DESC LIMIT $per_page OFFSET $offset";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Check if each user has created records
    foreach ($users as &$user) {
        // Simple check for user activity records
        $user['has_records'] = false;
        
        try {
            // Check main activity tables
            $tables_to_check = [
                'dcmt_income' => 'dcmt_created_by',
                'dcmt_expenses' => 'dcmt_created_by',
                'dcmt_inventory' => 'dcmt_created_by'
            ];
            
            foreach ($tables_to_check as $table => $column) {
                $sql = "SELECT COUNT(*) FROM $table WHERE $column = ?";
                $stmt = $dcmt_pdo->prepare($sql);
                $stmt->execute([$user['dcmt_username']]);
                $count = $stmt->fetchColumn();
                
                if ($count > 0) {
                    $user['has_records'] = true;
                    break;
                }
            }
        } catch (Exception $e) {
            // If there's an error, assume no records to be safe
            $user['has_records'] = false;
            error_log("Error checking user records for {$user['dcmt_username']}: " . $e->getMessage());
        }
    }
    unset($user); // Break the reference to avoid issues in subsequent loops
} catch (PDOException $e) {
    dcmt_show_message(trans('user', 'database_error'), "error");
    $users = [];
}

// Get counts for summary cards
try {
    $admin_count = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'admin'")->fetchColumn();
    $staff_count = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'staff'")->fetchColumn();
    $doctor_count = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'doctor'")->fetchColumn();
    $active_count = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_status = 'active'")->fetchColumn();
} catch (PDOException $e) {
    $admin_count = $staff_count = $doctor_count = $active_count = 0;
}

// Get current default doctor user
$default_doctor_user_id = null;
try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'default_doctor_user_id'");
    $stmt->execute();
    $default_doctor_user_setting = $stmt->fetch();
    if ($default_doctor_user_setting) {
        $default_doctor_user_id = $default_doctor_user_setting['dcmt_setting_value'];
    }
} catch (PDOException $e) {
    error_log("Error fetching default doctor setting: " . $e->getMessage());
}

// For each doctor role user, check if they are the default doctor
foreach ($users as &$user) {
    if ($user['dcmt_role'] === 'doctor') {
        // Check if this user is the default doctor
        $user['is_default_doctor'] = $default_doctor_user_id && $user['dcmt_id'] == $default_doctor_user_id;
    } else {
        $user['is_default_doctor'] = false;
    }
}
unset($user);

// Handle AJAX request to set default doctor
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'set_default_doctor_user') {
    $user_id = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    
    if ($user_id > 0) {
        try {
            // Verify user exists and is a doctor
            $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_users WHERE dcmt_id = ? AND dcmt_role = 'doctor'");
            $stmt->execute([$user_id]);
            $target_user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($target_user) {
                // Start transaction
                $dcmt_pdo->beginTransaction();
                
                // Save default_doctor_user_id setting
                $stmt = $dcmt_pdo->prepare("
                    INSERT INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_type, dcmt_category, dcmt_created_by) 
                    VALUES (?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                    dcmt_setting_value = VALUES(dcmt_setting_value),
                    dcmt_updated_at = CURRENT_TIMESTAMP
                ");
                
                $result = $stmt->execute([
                    'default_doctor_user_id',
                    'Default Doctor User',
                    $user_id,
                    'select',
                    'Doctor',
                    dcmt_get_current_user()['dcmt_username']
                ]);
                
                if ($result) {
                    $dcmt_pdo->commit();
                    dcmt_log_activity('Default doctor set from user', "User ID: $user_id");
                    
                    header('Content-Type: application/json');
                    echo json_encode(['success' => true, 'message' => 'Default doctor set successfully', 'user_id' => $user_id]);
                    exit;
                } else {
                    $dcmt_pdo->rollBack();
                    throw new Exception("Database update failed");
                }
            } else {
                header('Content-Type: application/json');
                echo json_encode(['success' => false, 'message' => 'User not found or not a doctor']);
                exit;
            }
        } catch (Exception $e) {
            if (isset($dcmt_pdo) && $dcmt_pdo->inTransaction()) {
                $dcmt_pdo->rollBack();
            }
            error_log("Error setting default doctor from user: " . $e->getMessage());
            header('Content-Type: application/json');
            echo json_encode(['success' => false, 'message' => 'Failed to set default doctor: ' . $e->getMessage()]);
            exit;
        }
    } else {
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Invalid user ID']);
        exit;
    }
}

// Generate CSRF token for AJAX operations
$csrf_token = dcmt_generate_csrf_token();

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>
<meta name="csrf-token" content="<?php echo $csrf_token; ?>">
<?php

// Display delete operation messages
if (isset($_SESSION['user_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['user_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['user_delete_success']);
}

if (isset($_SESSION['user_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['user_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['user_delete_error']);
}
?>

<!-- Search and Filter Form -->
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="<?php echo trans('user', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-3">
                <label for="role" class="form-label"><?php echo trans('user', 'role'); ?></label>
                <select class="form-select dcmt-filter-field" id="role" name="role">
                    <option value=""><?php echo trans('user', 'all_roles'); ?></option>
                    <option value="admin" <?php echo $role === 'admin' ? 'selected' : ''; ?>><?php echo trans('user', 'administrator'); ?></option>
                    <option value="staff" <?php echo $role === 'staff' ? 'selected' : ''; ?>><?php echo trans('user', 'staff'); ?></option>
                    <option value="doctor" <?php echo $role === 'doctor' ? 'selected' : ''; ?>><?php echo trans('user', 'doctor'); ?></option>
                </select>
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label"><?php echo trans('common', 'status'); ?></label>
                <select class="form-select dcmt-filter-field" id="status" name="status">
                    <option value=""><?php echo trans('user', 'all_statuses'); ?></option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                </select>
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


<!-- Users Table -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <i class="fas fa-users dcmt-view-card-title-icon"></i><?php echo trans('user', 'users_records'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('user', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('user', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('user', 'add_user'); ?></a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($users)): ?>
            <div class="text-center py-4">
                <i class="fas fa-users fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('user', 'no_users_found'); ?></h5>
                <p class="text-muted"><?php echo trans('user', 'try_adjusting_search'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('user', 'username'); ?></th>
                            <th><?php echo trans('user', 'full_name'); ?></th>
                            <th><?php echo trans('user', 'email_address'); ?></th>
                            <th><?php echo trans('user', 'role'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('user', 'last_login'); ?></th>
                            <th><?php echo trans('common', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $user): ?>
                            <tr>
                                <td>
                                    <?php echo htmlspecialchars($user['dcmt_username']); ?>
                                </td>
                                <td><?php echo htmlspecialchars($user['dcmt_full_name']); ?></td>
                                <td><?php echo htmlspecialchars($user['dcmt_email']); ?></td>
                                <td>
                                    <?php
                                    $role_class = 'info';
                                    if ($user['dcmt_role'] === 'admin') {
                                        $role_class = 'danger';
                                    } elseif ($user['dcmt_role'] === 'doctor') {
                                        $role_class = 'primary';
                                    }
                                    $role_display = trans('user', $user['dcmt_role']);
                                    ?>
                                    <span class="text-<?php echo $role_class; ?>">
                                        <?php echo $role_display; ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="text-<?php echo $user['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo ucfirst($user['dcmt_status']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if ($user['dcmt_last_login']): ?>
                                        <span title="<?php echo $user['dcmt_last_login']; ?>">
                                            <?php echo dcmt_format_date($user['dcmt_last_login'], DCMT_DATETIME_FORMAT); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted"><?php echo trans('user', 'never'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span title="<?php echo $user['dcmt_created_at']; ?>">
                                        <?php echo dcmt_format_date($user['dcmt_created_at']); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $user['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <a href="edit.php?id=<?php echo $user['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($user['dcmt_id'] != dcmt_get_current_user()['dcmt_id']): ?>
                                            <?php if ($user['has_records']): ?>
                                                <button type="button" class="btn btn-transparent" 
                                                        title="<?php echo trans('user', 'cannot_delete_user_with_records'); ?>"
                                                        disabled>
                                                    <img src="../../assets/images/delete.svg" alt="Delete" style="opacity: 0.3;">
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                        onclick="confirmDelete(<?php echo $user['dcmt_id']; ?>, 'user')">
                                                    <img src="../../assets/images/delete.svg" alt="Delete">
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                        <?php if ($user['dcmt_role'] === 'doctor'): ?>
                                            <?php if ($user['is_default_doctor']): ?>
                                                <button type="button" class="btn btn-transparent" title="<?php echo trans('doctor', 'default_doctor'); ?>">
                                                    <i class="fas fa-crown text-warning"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn" title="<?php echo trans('doctor', 'set_as_default'); ?>"
                                                        onclick="setDefaultDoctorUser(<?php echo $user['dcmt_id']; ?>, '<?php echo htmlspecialchars($user['dcmt_full_name']); ?>')">
                                                    <i class="fas fa-crown text-muted"></i>
                                                </button>
                                            <?php endif; ?>
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

<!-- Pagination -->
<?php if ($total_pages > 1): ?>
    <nav aria-label="Users pagination" class="mt-4">
        <ul class="pagination justify-content-center">
            <?php if ($page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                        <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                    </a>
                </li>
            <?php endif; ?>
            
            <?php
            $start_page = max(1, $page - 2);
            $end_page = min($total_pages, $page + 2);
            
            if ($start_page > 1): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>">1</a>
                </li>
                <?php if ($start_page > 2): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
            <?php endif; ?>
            
            <?php for ($i = $start_page; $i <= $end_page; $i++): ?>
                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                </li>
            <?php endfor; ?>
            
            <?php if ($end_page < $total_pages): ?>
                <?php if ($end_page < $total_pages - 1): ?>
                    <li class="page-item disabled"><span class="page-link">...</span></li>
                <?php endif; ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>"><?php echo $total_pages; ?></a>
                </li>
            <?php endif; ?>
            
            <?php if ($page < $total_pages): ?>
                <li class="page-item">
                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                        <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                    </a>
                </li>
            <?php endif; ?>
        </ul>
    </nav>
<?php endif; ?>

<script>
// Pass translations to JavaScript for users
window.translations = {
    confirm_deletion: '<?php echo trans('common', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('common', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('user', 'confirm_delete_user') ?: trans('common', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('common', 'cancel'); ?>',
    yes_delete: '<?php echo trans('common', 'yes_delete'); ?>',
    user: '<?php echo trans('user', 'user'); ?>',
    confirm_delete_single: '<?php echo trans('user', 'confirm_delete_user'); ?>'
};

function exportToCSV() {
    const searchParams = new URLSearchParams(window.location.search);
    const exportUrl = 'export.php?' + searchParams.toString();
    window.location.href = exportUrl;
}

// The main.js file will handle user deletion via AJAX
// No custom functions needed here

function setDefaultDoctorUser(userId, userName) {
    if (confirm('<?php echo trans('doctor', 'confirm_set_default'); ?> "' + userName + '"?')) {
        console.log('Setting default doctor from user:', userId, userName);
        
        // Create form data
        const formData = new FormData();
        formData.append('action', 'set_default_doctor_user');
        formData.append('user_id', userId);
        
        console.log('Sending AJAX request with user ID:', userId);
        
        // Send AJAX request
        fetch('', {
            method: 'POST',
            body: formData
        })
        .then(response => {
            console.log('Response received:', response.status);
            return response.json();
        })
        .then(data => {
            console.log('Response data:', data);
            if (data.success) {
                // Reload page to update UI
                location.reload();
            } else {
                console.error('Server returned error:', data.message);
                alert('Error: ' + data.message);
            }
        })
        .catch(error => {
            console.error('AJAX Error:', error);
            alert('<?php echo trans('doctor', 'set_default_error'); ?>');
        });
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
