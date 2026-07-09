<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_compliance.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check admin access
dcmt_require_admin_or_doctor();
if (!dcmt_is_admin()) {
    dcmt_show_message('Access denied. Admin privileges required.', "error");
    dcmt_redirect("index.php");
    exit();
}

$errors = [];

$has_doctor_color_column = false;
try {
    $has_doctor_color_column = (bool)$dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_color_code'")->fetch();
} catch (PDOException $e) {
    $has_doctor_color_column = false;
}

$has_doctor_api_key_column = false;
try {
    $has_doctor_api_key_column = (bool)$dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_api_key'")->fetch();
} catch (PDOException $e) {
    $has_doctor_api_key_column = false;
}

$dcmt_allowed_doctor_colors = [
    '#0D6EFD',
    '#0B5ED7',
    '#084298',
    '#6610F2',
    '#6F42C1',
    '#5A32A3',
    '#D63384',
    '#B02A6B',
    '#C82333',
    '#DC3545',
    '#A61E2E',
    '#146C43',
    '#0F5132',
    '#495057',
    '#343A40'
];
$dcmt_default_doctor_color = '#0D6EFD';

$form_data = [
    'username' => '',
    'email' => '',
    'password' => '',
    'full_name' => '',
    'role' => 'staff',
    'status' => 'active',
    'phone' => '',
    'address' => '',
    'notes' => '',
    'qualification' => '',
    'specialization_id' => '',
    'api_key' => '',
    'color_code' => $dcmt_default_doctor_color
];

if (!in_array(strtoupper((string)$form_data['color_code']), $dcmt_allowed_doctor_colors, true)) {
    $form_data['color_code'] = $dcmt_default_doctor_color;
}

// Fetch specializations from database
try {
    $specialization_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_name FROM dcmt_doctor_specializations WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $specializations = $specialization_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching specializations: " . $e->getMessage());
    $specializations = [];
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        dcmt_show_message(trans('common', 'invalid_token'), 'error');
        dcmt_redirect("add.php");
        exit();
    }
    
    // Get form data
    $form_data = [
        'username' => dcmt_sanitize_input($_POST['username']),
        'email' => dcmt_sanitize_input($_POST['email']),
        'password' => $_POST['password'], // Don't sanitize password
        'full_name' => dcmt_sanitize_input($_POST['full_name']),
        'role' => dcmt_sanitize_input($_POST['role']),
        'status' => dcmt_sanitize_input($_POST['status']),
        'phone' => dcmt_sanitize_input($_POST['phone']),
        'address' => dcmt_sanitize_input($_POST['address']),
        'notes' => dcmt_sanitize_input($_POST['notes']),
        'qualification' => isset($_POST['qualification']) ? dcmt_sanitize_input($_POST['qualification']) : '',
        'specialization_id' => isset($_POST['specialization_id']) && !empty($_POST['specialization_id']) ? intval($_POST['specialization_id']) : null,
        'api_key' => isset($_POST['api_key']) ? trim((string) $_POST['api_key']) : '',
        'color_code' => strtoupper(trim((string)($_POST['color_code'] ?? $dcmt_default_doctor_color)))
    ];

    if (!in_array($form_data['color_code'], $dcmt_allowed_doctor_colors, true)) {
        $form_data['color_code'] = $dcmt_default_doctor_color;
    }
    
    // Validate required fields
    $required_fields = ['username', 'email', 'password', 'full_name', 'role', 'status'];
    $validation_result = dcmt_validate_required_fields($form_data, $required_fields);
    if (!$validation_result['valid']) {
        $errors = $validation_result['errors'];
    }
    
    // Validate email format
    if (empty($errors) && !dcmt_validate_email($form_data['email'])) {
        $errors[] = trans('user', 'invalid_email');
    }
    
    // Validate password
    if (empty($errors)) {
        if (strlen($form_data['password']) < DCMT_PASSWORD_MIN_LENGTH) {
            $errors[] = trans('user', 'password_min_length');
        }
        
        // Check password confirmation
        if ($_POST['password'] !== $_POST['confirm_password']) {
            $errors[] = trans('user', 'password_mismatch');
        }
    }
    
    // Validate role
    if (empty($errors) && !in_array($form_data['role'], ['admin', 'staff', 'doctor', 'assistant'], true)) {
        $errors[] = trans('user', 'invalid_role');
    }
    
    // Validate status
    if (empty($errors) && !in_array($form_data['status'], ['active', 'inactive'])) {
        $errors[] = trans('user', 'invalid_status');
    }

    // Check if username already exists
    if (empty($errors)) {
        try {
            $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_username = ?");
            $stmt->execute([$form_data['username']]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = trans('user', 'username_exists');
            }
        } catch (PDOException $e) {
            $errors[] = trans('user', 'database_error');
        }
    }
    
    // Check if email already exists
    if (empty($errors)) {
        try {
            $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_email = ?");
            $stmt->execute([$form_data['email']]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = trans('user', 'email_exists');
            }
        } catch (PDOException $e) {
            $errors[] = trans('user', 'database_error');
        }
    }
    
    // If no errors, insert user
    if (empty($errors)) {
        try {
            // Hash the provided password
            $hashed_password = password_hash($form_data['password'], PASSWORD_DEFAULT);
            
            // Check if qualification and specialization_id columns exist
            $check_qualification = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_qualification'")->fetch();
            $check_specialization = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_specialization_id'")->fetch();
            if ($check_qualification && $check_specialization) {
                if ($has_doctor_api_key_column && $has_doctor_color_column) {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_qualification, dcmt_specialization_id, dcmt_api_key, dcmt_color_code, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } elseif ($has_doctor_api_key_column) {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_qualification, dcmt_specialization_id, dcmt_api_key, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } elseif ($has_doctor_color_column) {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_qualification, dcmt_specialization_id, dcmt_color_code, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } else {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_qualification, dcmt_specialization_id, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                }
                $stmt = $dcmt_pdo->prepare($sql);
                $params = [
                    $form_data['username'],
                    $form_data['email'],
                    $hashed_password,
                    $form_data['full_name'],
                    $form_data['role'],
                    $form_data['status'],
                    $form_data['phone'],
                    $form_data['address'],
                    $form_data['notes'],
                    $form_data['qualification'],
                    $form_data['specialization_id']
                ];
                if ($has_doctor_api_key_column) {
                    $params[] = ($form_data['role'] === 'doctor' && $form_data['api_key'] !== '') ? $form_data['api_key'] : null;
                }
                if ($has_doctor_color_column) {
                    $params[] = $form_data['role'] === 'doctor' ? $form_data['color_code'] : null;
                }
                $params[] = dcmt_get_current_user()['dcmt_username'];
                $stmt->execute($params);
            } else {
                if ($has_doctor_api_key_column && $has_doctor_color_column) {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_api_key, dcmt_color_code, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } elseif ($has_doctor_api_key_column) {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_api_key, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } elseif ($has_doctor_color_column) {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_color_code, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                } else {
                    $sql = "INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
                }
                $stmt = $dcmt_pdo->prepare($sql);
                $params = [
                    $form_data['username'],
                    $form_data['email'],
                    $hashed_password,
                    $form_data['full_name'],
                    $form_data['role'],
                    $form_data['status'],
                    $form_data['phone'],
                    $form_data['address'],
                    $form_data['notes']
                ];
                if ($has_doctor_api_key_column) {
                    $params[] = ($form_data['role'] === 'doctor' && $form_data['api_key'] !== '') ? $form_data['api_key'] : null;
                }
                if ($has_doctor_color_column) {
                    $params[] = $form_data['role'] === 'doctor' ? $form_data['color_code'] : null;
                }
                $params[] = dcmt_get_current_user()['dcmt_username'];
                $stmt->execute($params);
            }
            
            $user_id = $dcmt_pdo->lastInsertId();
            
            if (dcmt_schema_has_column($dcmt_pdo, 'dcmt_users', 'dcmt_must_change_password')) {
                $flag_stmt = $dcmt_pdo->prepare('UPDATE dcmt_users SET dcmt_must_change_password = 1 WHERE dcmt_id = ?');
                $flag_stmt->execute([(int) $user_id]);
            }
            
            // If this is a doctor role user, check if it's the first doctor and set as default
            if ($form_data['role'] === 'doctor') {
                try {
                    // Check if there's already a default doctor set
                    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'default_doctor_user_id'");
                    $stmt->execute();
                    $existing_default = $stmt->fetch();
                    
                    // If no default doctor is set, check if this is the first doctor
                    if (!$existing_default || empty($existing_default['dcmt_setting_value'])) {
                        // Count existing doctors (excluding the one we just created)
                        $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_id != ?");
                        $stmt->execute([$user_id]);
                        $existing_doctor_count = $stmt->fetchColumn();
                        
                        // If this is the first doctor (no other doctors exist), set as default
                        if ($existing_doctor_count == 0) {
                            $stmt = $dcmt_pdo->prepare("
                                INSERT INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_type, dcmt_category, dcmt_created_by) 
                                VALUES (?, ?, ?, ?, ?, ?) 
                                ON DUPLICATE KEY UPDATE 
                                dcmt_setting_value = VALUES(dcmt_setting_value),
                                dcmt_updated_at = CURRENT_TIMESTAMP
                            ");
                            
                            $stmt->execute([
                                'default_doctor_user_id',
                                'Default Doctor User',
                                $user_id,
                                'select',
                                'Doctor',
                                dcmt_get_current_user()['dcmt_username']
                            ]);
                            
                            // Log activity
                            dcmt_log_activity("First doctor set as default: {$form_data['full_name']}", "default_doctor_set");
                        }
                    }
                } catch (PDOException $e) {
                    // Log error but don't fail user creation
                    error_log("Error setting default doctor: " . $e->getMessage());
                }
            }
            
            // Log activity
            dcmt_log_activity("User created: {$form_data['username']} ({$form_data['full_name']})", "user_created");
            
            // Set success message
            dcmt_show_message(trans('user', 'create_success'), "success");
            
            dcmt_redirect("index.php");
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
<style>
.dcmt-color-palette {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(130px, 1fr));
    gap: 0.5rem;
}
.dcmt-color-chip {
    border: 1px solid #d0d7de;
    border-radius: 0.5rem;
    background: #fff;
    padding: 0.45rem 0.55rem;
    display: flex;
    align-items: center;
    gap: 0.45rem;
    font-size: 0.85rem;
    cursor: pointer;
    transition: border-color 0.15s ease, box-shadow 0.15s ease;
}
.dcmt-color-chip:hover {
    border-color: #0d6efd;
}
.dcmt-color-chip.is-active {
    border-color: #0d6efd;
    box-shadow: 0 0 0 2px rgba(13, 110, 253, 0.15);
}
.dcmt-color-chip-swatch {
    width: 16px;
    height: 16px;
    border-radius: 50%;
    border: 1px solid rgba(0, 0, 0, 0.12);
    flex: 0 0 16px;
}
</style>

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('user', 'add_user'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('user', 'view_all_users'); ?></a>
        </div>
    </div>
        <form method="POST" action="" id="userForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="username" class="form-label"><?php echo trans('user', 'username'); ?> <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="username" name="username" 
                               value="<?php echo htmlspecialchars($form_data['username']); ?>" 
                               required maxlength="50">
                        <div class="form-text"><?php echo trans('user', 'username_help'); ?></div>
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
                        <label for="password" class="form-label"><?php echo trans('user', 'password'); ?> <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="password" name="password" 
                               required minlength="<?php echo DCMT_PASSWORD_MIN_LENGTH; ?>">
                        <div class="form-text"><?php echo trans('user', 'password_help'); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="confirm_password" class="form-label"><?php echo trans('user', 'confirm_password'); ?> <span class="text-danger">*</span></label>
                        <input type="password" class="form-control" id="confirm_password" name="confirm_password" 
                               required>
                        <div class="form-text"><?php echo trans('user', 'confirm_password_help'); ?></div>
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
                        <select class="form-select" id="role" name="role" required>
                            <option value="staff" <?php echo $form_data['role'] === 'staff' ? 'selected' : ''; ?>><?php echo trans('user', 'staff'); ?></option>
                            <option value="admin" <?php echo $form_data['role'] === 'admin' ? 'selected' : ''; ?>><?php echo trans('user', 'administrator'); ?></option>
                            <option value="doctor" <?php echo $form_data['role'] === 'doctor' ? 'selected' : ''; ?>><?php echo trans('user', 'doctor'); ?></option>
                            <option value="assistant" <?php echo $form_data['role'] === 'assistant' ? 'selected' : ''; ?>><?php echo trans('user', 'assistant'); ?></option>
                        </select>
                        <div class="form-text"><?php echo trans('user', 'role_help'); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="status" class="form-label"><?php echo trans('user', 'status'); ?> <span class="text-danger">*</span></label>
                        <select class="form-select" id="status" name="status" required>
                            <option value="active" <?php echo $form_data['status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('user', 'active'); ?></option>
                            <option value="inactive" <?php echo $form_data['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('user', 'inactive'); ?></option>
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
                    <?php if ($has_doctor_api_key_column): ?>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="api_key" class="form-label"><?php echo trans('user', 'api_key'); ?></label>
                            <input type="text" class="form-control" id="api_key" name="api_key"
                                   value="<?php echo htmlspecialchars($form_data['api_key']); ?>"
                                   maxlength="255" autocomplete="off"
                                   placeholder="<?php echo trans('user', 'api_key_placeholder'); ?>">
                            <div class="form-text"><?php echo trans('user', 'api_key_help'); ?></div>
                        </div>
                    </div>
                    <?php endif; ?>
                    <?php if ($has_doctor_color_column): ?>
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="color_code" class="form-label">Doctor Color Code</label>
                            <input type="hidden" id="color_code" name="color_code" value="<?php echo htmlspecialchars(strtoupper((string)$form_data['color_code'])); ?>">
                            <div class="dcmt-color-palette" id="dcmtColorPalette">
                                <?php foreach ($dcmt_allowed_doctor_colors as $doctor_color): ?>
                                    <button
                                        type="button"
                                        class="dcmt-color-chip <?php echo strtoupper((string)$form_data['color_code']) === $doctor_color ? 'is-active' : ''; ?>"
                                        data-color="<?php echo htmlspecialchars($doctor_color); ?>"
                                        title="<?php echo htmlspecialchars($doctor_color); ?>"
                                    >
                                        <span class="dcmt-color-chip-swatch" style="background: <?php echo htmlspecialchars($doctor_color); ?>;"></span>
                                        <span><?php echo htmlspecialchars($doctor_color); ?></span>
                                    </button>
                                <?php endforeach; ?>
                            </div>
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
            
            <div class="alert alert-info">
                <i class="fas fa-info-circle"></i>
                <strong><?php echo trans('user', 'note'); ?>:</strong> <?php echo trans('user', 'password_note'); ?>
            </div>
            
            <div class="dcmt-form-actions">
                <button type="button" class="btn dcmt-btn-reset" id="resetBtn">
                    <i class="fas fa-undo"></i><?php echo trans('user', 'reset'); ?>
                </button>
                <a href="view.php" class="btn dcmt-btn-cancel">
                    <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
                </a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-plus"></i><?php echo trans('user', 'add_user_record'); ?>
                </button>
            </div>
        </form>
</div>

<script>
// Define reset function with project prefix to avoid collisions
function dcmt_resetUserForm() {
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
            'username': '',
            'email': '',
            'password': '',
            'confirm_password': '',
            'full_name': '',
            'phone': '',
            'role': 'staff',
            'status': 'active',
            'address': '',
            'notes': '',
            <?php if ($has_doctor_api_key_column): ?>
            'api_key': '',
            <?php endif; ?>
            <?php if ($has_doctor_color_column): ?>
            'color_code': '#0D6EFD'
            <?php endif; ?>
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
        
        // Hide doctor fields on reset
        const doctorFields = document.getElementById('doctorFields');
        if (doctorFields) {
            doctorFields.style.display = 'none';
        }
    }, 10);

    // Remove any visible server-side error alert
    const errorAlert = document.querySelector('.alert.alert-danger');
    if (errorAlert && errorAlert.parentNode) {
        errorAlert.parentNode.removeChild(errorAlert);
    }

    // Clear validation/UI states
    document.querySelectorAll('.is-invalid').forEach(function(el){ el.classList.remove('is-invalid'); });
    document.querySelectorAll('.invalid-feedback').forEach(function(el){ el.textContent = ''; });

    // Re-enable and restore submit button text
    const submitBtn = document.getElementById('submitBtn');
    if (submitBtn) {
        submitBtn.disabled = false;
        submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('user', 'add_user_record'); ?>';
    }

    // Focus first field
    const firstField = document.getElementById('username');
    if (firstField) {
        firstField.focus();
    }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    const dcmtResetConfirmMessage = '<?php echo addslashes(trans('user', 'confirm_reset') ?: trans('common', 'confirm_reset') ?: 'Are you sure you want to reset the form?'); ?>';
    // Get form elements
    const form = document.getElementById('userForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('resetBtn');
    
    if (!form || !resetBtn) {
        return;
    }
    
    // Add reset button event listener
    resetBtn.addEventListener('click', function(e) {
        e.preventDefault();
        if (confirm(dcmtResetConfirmMessage)) {
            dcmt_resetUserForm();
        }
    });
    
    // Form validation
    form.addEventListener('submit', function(e) {
        const username = document.getElementById('username').value.trim();
        const email = document.getElementById('email').value.trim();
        const password = document.getElementById('password').value;
        const confirmPassword = document.getElementById('confirm_password').value;
        const fullName = document.getElementById('full_name').value.trim();
        
        if (!username || !email || !password || !confirmPassword || !fullName) {
            e.preventDefault();
            alert('<?php echo trans('user', 'fill_required_fields'); ?>');
            return false;
        }
        
        if (username.length < 3) {
            e.preventDefault();
            alert('<?php echo trans('user', 'username_min_length_alert'); ?>');
            return false;
        }
        
        if (!email.includes('@')) {
            e.preventDefault();
            alert('<?php echo trans('user', 'valid_email_alert'); ?>');
            return false;
        }
        
        if (password.length < <?php echo DCMT_PASSWORD_MIN_LENGTH; ?>) {
            e.preventDefault();
            alert('<?php echo trans('user', 'password_min_length_alert'); ?>');
            return false;
        }
        
        if (password !== confirmPassword) {
            e.preventDefault();
            alert('<?php echo trans('user', 'password_mismatch_alert'); ?>');
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
    const colorSelect = document.getElementById('color_code');
    const colorPalette = document.getElementById('dcmtColorPalette');
    if (colorSelect && !colorSelect.value) {
        colorSelect.value = '#0D6EFD';
    }
    if (colorSelect && colorPalette) {
        const syncActiveColor = function(selectedColor) {
            colorPalette.querySelectorAll('.dcmt-color-chip').forEach(function(chip) {
                chip.classList.toggle('is-active', String(chip.getAttribute('data-color') || '').toUpperCase() === selectedColor);
            });
        };
        syncActiveColor(String(colorSelect.value || '#0D6EFD').toUpperCase());
        colorPalette.querySelectorAll('.dcmt-color-chip').forEach(function(chip) {
            chip.addEventListener('click', function() {
                const selectedColor = String(chip.getAttribute('data-color') || '#0D6EFD').toUpperCase();
                colorSelect.value = selectedColor;
                syncActiveColor(selectedColor);
            });
        });
    }
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
