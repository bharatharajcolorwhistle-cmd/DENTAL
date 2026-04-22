<?php
/**
 * Edit User Page
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
$dcmt_current_user = dcmt_get_current_user();
$dcmt_is_admin_user = dcmt_is_admin();

// Get user ID from URL
$user_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($user_id <= 0) {
    dcmt_show_message(trans('user', 'invalid_user_id'), "error");
    dcmt_redirect("index.php");
    exit();
}

if (!$dcmt_is_admin_user && (int) ($dcmt_current_user['dcmt_id'] ?? 0) !== (int) $user_id) {
    dcmt_show_message('Access denied. You can only edit your own account.', "error");
    dcmt_redirect("index.php");
    exit();
}

// Get user details
try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_users WHERE dcmt_id = ?");
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        dcmt_show_message(trans('user', 'user_not_found'), "error");
        dcmt_redirect("index.php");
        exit();
    }
    
} catch (PDOException $e) {
    dcmt_show_message(trans('user', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

$errors = [];
$form_data = [];

$has_doctor_color_column = false;
try {
    $has_doctor_color_column = (bool)$dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_color_code'")->fetch();
} catch (PDOException $e) {
    $has_doctor_color_column = false;
}

// Fetch specializations from database
try {
    $specialization_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_name FROM dcmt_doctor_specializations WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $specializations = $specialization_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching specializations: " . $e->getMessage());
    $specializations = [];
}

// Initialize form data with current user data
$form_data = [
    'email' => $user['dcmt_email'] ?? '',
    'full_name' => $user['dcmt_full_name'] ?? '',
    'role' => $user['dcmt_role'] ?? 'staff',
    'status' => $user['dcmt_status'] ?? 'active',
    'phone' => $user['dcmt_phone'] ?? '',
    'address' => $user['dcmt_address'] ?? '',
    'notes' => $user['dcmt_notes'] ?? '',
    'qualification' => $user['dcmt_qualification'] ?? '',
    'specialization_id' => $user['dcmt_specialization_id'] ?? '',
    'color_code' => (!empty($user['dcmt_color_code']) ? strtoupper((string)$user['dcmt_color_code']) : '#0d6efd')
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        dcmt_show_message(trans('user', 'invalid_request'), "error");
        dcmt_redirect("edit.php?id=$user_id");
    }
    
    // Get form data
    // Note: Role cannot be changed, use original role from database
    $form_data = [
        'email' => dcmt_sanitize_input($_POST['email']),
        'full_name' => dcmt_sanitize_input($_POST['full_name']),
        'role' => $user['dcmt_role'], // Use original role, cannot be changed
        'status' => dcmt_sanitize_input($_POST['status']),
        'phone' => dcmt_sanitize_input($_POST['phone']),
        'address' => dcmt_sanitize_input($_POST['address']),
        'notes' => dcmt_sanitize_input($_POST['notes']),
        'qualification' => isset($_POST['qualification']) ? dcmt_sanitize_input($_POST['qualification']) : '',
        'specialization_id' => isset($_POST['specialization_id']) && !empty($_POST['specialization_id']) ? intval($_POST['specialization_id']) : null,
        'color_code' => strtoupper(trim((string)($_POST['color_code'] ?? '#0d6efd')))
    ];
    
    // Check if password change is requested
    $change_password = !empty($_POST['new_password']);
    $new_password = '';
    if ($change_password) {
        $new_password = $_POST['new_password'];
        $confirm_password = $_POST['confirm_password'];
        
        if (strlen($new_password) < 6) {
            $errors[] = trans('user', 'password_min_length');
        }
        
        if ($new_password !== $confirm_password) {
            $errors[] = trans('user', 'password_mismatch');
        }
    }
    
    // Validate required fields
    $required_fields = ['email', 'full_name', 'role', 'status'];
    $validation_result = dcmt_validate_required_fields($form_data, $required_fields);
    if (!$validation_result['valid']) {
        $errors = array_merge($errors, $validation_result['errors']);
    }
    
    // Validate email format
    if (empty($errors) && !dcmt_validate_email($form_data['email'])) {
        $errors[] = trans('user', 'invalid_email');
    }
    
    // Validate role
    if (empty($errors) && !in_array($form_data['role'], ['admin', 'staff', 'doctor', 'assistant'], true)) {
        $errors[] = trans('user', 'invalid_role');
    }
    
    // Validate status
    if (empty($errors) && !in_array($form_data['status'], ['active', 'inactive'])) {
        $errors[] = trans('user', 'invalid_status');
    }

    if (
        empty($errors)
        && $form_data['role'] === 'doctor'
        && $has_doctor_color_column
        && !preg_match('/^#([0-9A-F]{6})$/', $form_data['color_code'])
    ) {
        $errors[] = 'Invalid doctor color code.';
    }
    
    // Check if email already exists (excluding current user)
    if (empty($errors)) {
        try {
            $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_email = ? AND dcmt_id != ?");
            $stmt->execute([$form_data['email'], $user_id]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = trans('user', 'email_exists');
            }
        } catch (PDOException $e) {
            $errors[] = trans('user', 'database_error');
        }
    }
    
    // If no errors, update user
    if (empty($errors)) {
        try {
            // Check if qualification and specialization_id columns exist
            $check_qualification = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_qualification'")->fetch();
            $check_specialization = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_specialization_id'")->fetch();
            
            if ($change_password) {
                $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                
                if ($check_qualification && $check_specialization) {
                    if ($has_doctor_color_column) {
                        $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_qualification = ?, dcmt_specialization_id = ?, dcmt_color_code = ?, dcmt_password = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                    } else {
                        $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_qualification = ?, dcmt_specialization_id = ?, dcmt_password = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                    }
                    $stmt = $dcmt_pdo->prepare($sql);
                    $params = [
                        $form_data['email'],
                        $form_data['full_name'],
                        $form_data['role'],
                        $form_data['status'],
                        $form_data['phone'],
                        $form_data['address'],
                        $form_data['notes'],
                        $form_data['qualification'],
                        $form_data['specialization_id'],
                    ];
                    if ($has_doctor_color_column) {
                        $params[] = $form_data['role'] === 'doctor' ? $form_data['color_code'] : null;
                    }
                    $params[] = $hashed_password;
                    $params[] = $user_id;
                    $stmt->execute($params);
                } else {
                if ($has_doctor_color_column) {
                    $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_color_code = ?, dcmt_password = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                } else {
                    $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_password = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                }
                $stmt = $dcmt_pdo->prepare($sql);
                $params = [
                    $form_data['email'],
                    $form_data['full_name'],
                    $form_data['role'],
                    $form_data['status'],
                    $form_data['phone'],
                    $form_data['address'],
                    $form_data['notes']
                ];
                if ($has_doctor_color_column) {
                    $params[] = $form_data['role'] === 'doctor' ? $form_data['color_code'] : null;
                }
                $params[] = $hashed_password;
                $params[] = $user_id;
                $stmt->execute($params);
                }
                
                dcmt_log_activity("User updated with password change: {$user['dcmt_username']} ({$form_data['full_name']})", "user_updated");
                dcmt_show_message(trans('user', 'update_success_with_password'), "success");
            } else {
                if ($check_qualification && $check_specialization) {
                    if ($has_doctor_color_column) {
                        $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_qualification = ?, dcmt_specialization_id = ?, dcmt_color_code = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                    } else {
                        $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_qualification = ?, dcmt_specialization_id = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                    }
                    $stmt = $dcmt_pdo->prepare($sql);
                    $params = [
                        $form_data['email'],
                        $form_data['full_name'],
                        $form_data['role'],
                        $form_data['status'],
                        $form_data['phone'],
                        $form_data['address'],
                        $form_data['notes'],
                        $form_data['qualification'],
                        $form_data['specialization_id']
                    ];
                    if ($has_doctor_color_column) {
                        $params[] = $form_data['role'] === 'doctor' ? $form_data['color_code'] : null;
                    }
                    $params[] = $user_id;
                    $stmt->execute($params);
            } else {
                if ($has_doctor_color_column) {
                    $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_color_code = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                } else {
                    $sql = "UPDATE dcmt_users SET dcmt_email = ?, dcmt_full_name = ?, dcmt_role = ?, dcmt_status = ?, dcmt_phone = ?, dcmt_address = ?, dcmt_notes = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
                }
                $stmt = $dcmt_pdo->prepare($sql);
                $params = [
                    $form_data['email'],
                    $form_data['full_name'],
                    $form_data['role'],
                    $form_data['status'],
                    $form_data['phone'],
                    $form_data['address'],
                    $form_data['notes']
                ];
                if ($has_doctor_color_column) {
                    $params[] = $form_data['role'] === 'doctor' ? $form_data['color_code'] : null;
                }
                $params[] = $user_id;
                $stmt->execute($params);
                }
                
                dcmt_log_activity("User updated: {$user['dcmt_username']} ({$form_data['full_name']})", "user_updated");
                dcmt_show_message(trans('user', 'update_success'), "success");
            }
            
            dcmt_redirect("view.php?id=$user_id");
            exit();
            
        } catch (PDOException $e) {
            $errors[] = trans('user', 'database_error');
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('user', 'edit_user'); ?></h1>
            <a href="view.php?id=<?php echo $user_id; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('common', 'view'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="userForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="username" class="form-label"><?php echo trans('user', 'username'); ?></label>
                        <input type="text" class="form-control" id="username" 
                               value="<?php echo htmlspecialchars($user['dcmt_username']); ?>" 
                               readonly disabled>
                        <div class="form-text"><?php echo trans('user', 'username_cannot_change'); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="email" class="form-label"><?php echo trans('user', 'email_address'); ?> <span class="text-danger">*</span></label>
                        <input type="email" class="form-control" id="email" name="email" 
                               value="<?php echo htmlspecialchars($form_data['email']); ?>" 
                               required maxlength="100">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="full_name" class="form-label"><?php echo trans('user', 'full_name'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="full_name" name="full_name" 
                               value="<?php echo htmlspecialchars($form_data['full_name']); ?>" 
                               required maxlength="100">
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="phone" class="form-label"><?php echo trans('user', 'phone'); ?></label>
                        <input type="tel" class="form-control" id="phone" name="phone" 
                               value="<?php echo htmlspecialchars($form_data['phone']); ?>" 
                               maxlength="20">
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="role" class="form-label"><?php echo trans('user', 'role'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="role" name="role" required disabled>
                            <option value="staff" <?php echo $form_data['role'] === 'staff' ? 'selected' : ''; ?>><?php echo trans('user', 'staff'); ?></option>
                            <option value="admin" <?php echo $form_data['role'] === 'admin' ? 'selected' : ''; ?>><?php echo trans('user', 'administrator'); ?></option>
                            <option value="doctor" <?php echo $form_data['role'] === 'doctor' ? 'selected' : ''; ?>><?php echo trans('user', 'doctor'); ?></option>
                            <option value="assistant" <?php echo $form_data['role'] === 'assistant' ? 'selected' : ''; ?>><?php echo trans('user', 'assistant'); ?></option>
                        </select>
                        <input type="hidden" name="role" value="<?php echo htmlspecialchars($form_data['role']); ?>">
                        <div class="form-text"><?php echo trans('user', 'role_cannot_change') ?: trans('user', 'username_cannot_change'); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status" class="form-label"><?php echo trans('common', 'status'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="active" <?php echo $form_data['status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                            <option value="inactive" <?php echo $form_data['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                        </select>
                    </div>
                </div>
            </div>
            
            <!-- Doctor-specific fields (shown only when doctor role is selected) -->
            <div id="doctorFields" style="display: <?php echo $form_data['role'] === 'doctor' ? 'block' : 'none'; ?>;">
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="qualification" class="form-label"><?php echo trans('doctor', 'qualification'); ?></label>
                            <input type="text" class="form-control" id="qualification" name="qualification" 
                                   value="<?php echo htmlspecialchars($form_data['qualification']); ?>" 
                                   maxlength="255" placeholder="<?php echo trans('doctor', 'qualification_placeholder'); ?>">
                            <div class="form-text"><?php echo trans('doctor', 'qualification_help'); ?></div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="specialization_id" class="form-label"><?php echo trans('doctor', 'specialization'); ?></label>
                            <select class="form-select" id="specialization_id" name="specialization_id">
                                <option value=""><?php echo trans('doctor', 'select_specialization'); ?></option>
                                <?php foreach ($specializations as $spec): ?>
                                    <option value="<?php echo $spec['dcmt_id']; ?>" 
                                            <?php echo $form_data['specialization_id'] == $spec['dcmt_id'] ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($spec['dcmt_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="form-text"><?php echo trans('doctor', 'specialization_help'); ?></div>
                        </div>
                    </div>
                    <?php if ($has_doctor_color_column): ?>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="color_code" class="form-label">Doctor Color Code</label>
                            <div class="d-flex align-items-center gap-2">
                                <input type="color" class="form-control form-control-color" id="color_code" name="color_code"
                                       value="<?php echo htmlspecialchars($form_data['color_code'] ?: '#0d6efd'); ?>"
                                       title="Choose doctor color">
                                <input type="text" class="form-control text-uppercase" id="color_code_text"
                                       value="<?php echo htmlspecialchars($form_data['color_code'] ?: '#0D6EFD'); ?>"
                                       maxlength="7" pattern="^#[0-9A-Fa-f]{6}$" placeholder="#0D6EFD">
                            </div>
                            <div class="form-text">Used in appointment calendar and doctor selectors.</div>
                        </div>
                    </div>
                    <?php endif; ?>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="address" class="form-label"><?php echo trans('user', 'address'); ?></label>
                <textarea class="form-control" id="address" name="address" rows="3" 
                          maxlength="500"><?php echo htmlspecialchars($form_data['address']); ?></textarea>
            </div>
            
            <div class="mb-3">
                <label for="notes" class="form-label"><?php echo trans('user', 'notes'); ?></label>
                <textarea class="form-control" id="notes" name="notes" rows="3" 
                          maxlength="500"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                <div class="form-text"><?php echo trans('user', 'notes_help'); ?></div>
            </div>
            
            <!-- Password Change Section -->
            <div class="card border-info mb-4">
                <div class="card-header bg-info text-white">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-key"></i> <?php echo trans('user', 'change_password_optional'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="row">
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="new_password" class="form-label"><?php echo trans('user', 'new_password'); ?></label>
                                <input type="password" class="form-control" id="new_password" name="new_password" 
                                       minlength="6" maxlength="50">
                                <div class="form-text"><?php echo trans('user', 'password_help'); ?></div>
                            </div>
                        </div>
                        <div class="col-md-6">
                            <div class="mb-3">
                                <label for="confirm_password" class="form-label"><?php echo trans('user', 'confirm_new_password'); ?></label>
                                <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                                       minlength="6" maxlength="50">
                            </div>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="dcmt-form-actions">
                <a href="view.php?id=<?php echo $user_id; ?>" class="btn dcmt-btn-cancel">
                    <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
                </a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i><?php echo trans('user', 'update_user_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Define resetForm function first
function resetForm() {
    // Get form element
    const form = document.getElementById('userForm');
    if (!form) {
        return;
    }
    
    // Reset form immediately
    form.reset();
    
    // Use setTimeout to ensure form.reset() completes before manual field setting
    setTimeout(() => {
        // Manually clear and set specific fields to ensure proper reset
        const fields = {
            'email': '<?php echo addslashes($user['dcmt_email']); ?>',
            'full_name': '<?php echo addslashes($user['dcmt_full_name']); ?>',
            'role': '<?php echo $user['dcmt_role']; ?>',
            'status': '<?php echo $user['dcmt_status']; ?>',
            'phone': '<?php echo addslashes($user['dcmt_phone'] ?? ''); ?>',
            'address': '<?php echo addslashes($user['dcmt_address'] ?? ''); ?>',
            'notes': '<?php echo addslashes($user['dcmt_notes'] ?? ''); ?>',
            'qualification': '<?php echo addslashes($user['dcmt_qualification'] ?? ''); ?>',
            'specialization_id': '<?php echo $user['dcmt_specialization_id'] ?? ''; ?>',
            <?php if ($has_doctor_color_column): ?>
            'color_code': '<?php echo addslashes(!empty($user['dcmt_color_code']) ? strtoupper((string)$user['dcmt_color_code']) : '#0d6efd'); ?>',
            <?php endif; ?>
            'new_password': '',
            'confirm_password': ''
        };
        
        // Clear each field individually
        Object.keys(fields).forEach(fieldId => {
            const field = document.getElementById(fieldId);
            if (field) {
                field.value = fields[fieldId];
                // Trigger change event to ensure any listeners are notified
                field.dispatchEvent(new Event('change', { bubbles: true }));
            }
        });
        
        // Update doctor fields visibility
        const roleSelect = document.getElementById('role');
        const doctorFields = document.getElementById('doctorFields');
        if (roleSelect && doctorFields) {
            doctorFields.style.display = roleSelect.value === 'doctor' ? 'block' : 'none';
        }
    }, 10);
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Get form elements
    const form = document.getElementById('userForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form) {
        return;
    }
    
    // Add reset button event listener (if present)
    if (resetBtn) {
        resetBtn.addEventListener('click', function(e) {
            e.preventDefault();
            resetForm();
        });
    }
    
    // Form validation
    form.addEventListener('submit', function(e) {
    const email = document.getElementById('email').value.trim();
    const fullName = document.getElementById('full_name').value.trim();
    const newPassword = document.getElementById('new_password').value;
    const confirmPassword = document.getElementById('confirm_password').value;
    
    if (!email || !fullName) {
        e.preventDefault();
        alert('<?php echo trans('user', 'fill_required_fields'); ?>');
        return false;
    }
    
    if (!email.includes('@')) {
        e.preventDefault();
        alert('<?php echo trans('user', 'enter_valid_email'); ?>');
        return false;
    }
    
    if (newPassword && newPassword.length < 6) {
        e.preventDefault();
        alert('<?php echo trans('user', 'password_min_length'); ?>');
        return false;
    }
    
    if (newPassword && newPassword !== confirmPassword) {
        e.preventDefault();
        alert('<?php echo trans('user', 'password_mismatch'); ?>');
        return false;
    }
    
    // Show loading state
    const originalText = submitBtn.innerHTML;
    submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
    submitBtn.disabled = true;
    
    // Store original text for potential restoration
    submitBtn.setAttribute('data-original-text', originalText);
    });
    
    // Restore button state if form validation fails
    const originalText = submitBtn.getAttribute('data-original-text');
    if (originalText) {
        submitBtn.innerHTML = originalText;
        submitBtn.disabled = false;
    }
    
    // Auto-hide password confirmation field if new password is empty
    document.getElementById('new_password').addEventListener('input', function() {
        const confirmField = document.getElementById('confirm_password');
        if (this.value === '') {
            confirmField.value = '';
        }
    });
    
    // Show/hide doctor fields based on role selection
    const roleSelect = document.getElementById('role');
    const doctorFields = document.getElementById('doctorFields');
    
    if (roleSelect && doctorFields) {
        function toggleDoctorFields() {
            if (roleSelect.value === 'doctor') {
                doctorFields.style.display = 'block';
            } else {
                doctorFields.style.display = 'none';
            }
        }
        
        // Initial state
        toggleDoctorFields();
        
        // Listen for role changes
        roleSelect.addEventListener('change', toggleDoctorFields);
    }

    <?php if ($has_doctor_color_column): ?>
    const colorPicker = document.getElementById('color_code');
    const colorText = document.getElementById('color_code_text');
    if (colorPicker && colorText) {
        const syncTextFromPicker = function() {
            colorText.value = String(colorPicker.value || '#0D6EFD').toUpperCase();
        };
        syncTextFromPicker();
        colorPicker.addEventListener('input', syncTextFromPicker);
        colorPicker.addEventListener('change', syncTextFromPicker);
        colorText.addEventListener('input', function() {
            const v = String(colorText.value || '').trim();
            if (/^#[0-9A-Fa-f]{6}$/.test(v)) {
                colorPicker.value = v.toUpperCase();
            }
        });
        colorText.addEventListener('blur', function() {
            const v = String(colorText.value || '').trim().toUpperCase();
            colorText.value = /^#[0-9A-F]{6}$/.test(v) ? v : String(colorPicker.value || '#0D6EFD').toUpperCase();
        });
    }
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
