<?php
/**
 * Login Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';

// Redirect if already logged in
if (dcmt_is_logged_in()) {
    $dcmt_existing_user = dcmt_get_current_user();
    $dcmt_existing_redirect = (($dcmt_existing_user['dcmt_role'] ?? '') === 'assistant')
        ? '../pages/patients/index.php'
        : '../pages/dashboard/';
    dcmt_redirect($dcmt_existing_redirect);
}

$errors = [];
$username = '';

// Handle login form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $username = dcmt_sanitize_input($_POST['username'] ?? '');
    $password = $_POST['password'] ?? '';
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!dcmt_verify_csrf_token($csrf_token)) {
        $errors[] = trans('login', 'invalid_token');
    }
    
    // Validate required fields
    if (empty($username)) {
        $errors[] = trans('login', 'username_required');
    }
    if (empty($password)) {
        $errors[] = trans('login', 'password_required');
    }
    
    // Attempt login if no validation errors
    if (empty($errors)) {
        try {
            $stmt = $dcmt_pdo->prepare("
                SELECT dcmt_id, dcmt_username, dcmt_email, dcmt_password, 
                       dcmt_full_name, dcmt_role, dcmt_status
                FROM dcmt_users 
                WHERE (dcmt_username = ? OR dcmt_email = ?) AND dcmt_status = 'active'
            ");
            $stmt->execute([$username, $username]);
            $user = $stmt->fetch();
            
            if ($user && password_verify($password, $user['dcmt_password'])) {
                // Login successful - update last login time
                $update_stmt = $dcmt_pdo->prepare("UPDATE dcmt_users SET dcmt_last_login = NOW() WHERE dcmt_id = ?");
                $update_stmt->execute([$user['dcmt_id']]);
                
                session_regenerate_id(true);
                $_SESSION['dcmt_user'] = $user;
                $_SESSION['dcmt_last_activity'] = time();
                
                dcmt_log_activity(trans('login', 'user_logged_in'), "User ID: {$user['dcmt_id']}");
                dcmt_show_message(str_replace('{name}', $user['dcmt_full_name'], trans('login', 'welcome_back')), 'success');
                $dcmt_redirect_path = (($user['dcmt_role'] ?? '') === 'assistant')
                    ? '../pages/patients/index.php'
                    : '../pages/dashboard/';
                dcmt_redirect($dcmt_redirect_path);
            } else {
                $errors[] = trans('login', 'invalid_credentials');
                dcmt_log_activity(trans('login', 'failed_login_attempt'), "Username: $username");
            }
        } catch (PDOException $e) {
            error_log("Login error: " . $e->getMessage());
            $errors[] = trans('login', 'system_error');
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo trans('login', 'title'); ?> - <?php echo dcmt_get_site_name(); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/main.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-header">
            <?php 
            $logo_path = dcmt_get_logo_path();
            if (!empty($logo_path) && file_exists(__DIR__ . '/../' . $logo_path)): ?>
                <img src="<?php echo '../' . $logo_path; ?>" alt="Logo" class="mb-3" style="height: 64px; width: auto;">
            <?php else: ?>
                <i class="fas fa-tooth fa-3x mb-3"></i>
            <?php endif; ?>
            <h2 class="mb-0"><?php echo dcmt_get_site_name(); ?></h2>
            <p class="mb-0 mt-2"><?php echo trans('login', 'subtitle'); ?></p>
        </div> 
        
        <div class="login-body">
            <?php if (!empty($errors)): ?>
                <div class="alert alert-danger">
                    <ul class="mb-0">
                        <?php foreach ($errors as $error): ?>
                            <li><?php echo htmlspecialchars($error); ?></li>
                        <?php endforeach; ?>
                    </ul>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                
                <div class="mb-3">
                    <label for="username" class="form-label">
                        <i class="fas fa-user me-2"></i><?php echo trans('login', 'username'); ?>
                    </label>
                    <input type="text" class="form-control" id="username" name="username" 
                           value="<?php echo htmlspecialchars($username); ?>" required>
                </div>
                
                <div class="mb-3">
                    <label for="password" class="form-label">
                        <i class="fas fa-lock me-2"></i><?php echo trans('login', 'password'); ?>
                    </label>
                    <input type="password" class="form-control" id="password" name="password" required>
                </div>
                
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="fas fa-sign-in-alt me-2"></i><?php echo trans('login', 'button_login'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
</body>
</html>
