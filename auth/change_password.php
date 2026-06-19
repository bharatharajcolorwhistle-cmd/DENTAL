<?php
/**
 * Change Password (required after first login with a temporary password)
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/patient_compliance.php';
require_once __DIR__ . '/../includes/password_policy.php';

if (!dcmt_validate_session()) {
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit;
}

$errors = [];
$user = dcmt_get_current_user();

if (!$user || ($user['dcmt_status'] ?? '') !== 'active') {
    session_destroy();
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    $current_password = $_POST['current_password'] ?? '';
    $new_password = $_POST['new_password'] ?? '';
    $confirm_password = $_POST['confirm_password'] ?? '';

    if (!dcmt_verify_csrf_token($csrf_token)) {
        $errors[] = trans('login', 'invalid_token');
    }
    if ($new_password === '') {
        $errors[] = trans('login', 'new_password_required');
    } elseif (strlen($new_password) < DCMT_PASSWORD_MIN_LENGTH) {
        $errors[] = trans('user', 'password_min_length');
    }
    if ($new_password !== $confirm_password) {
        $errors[] = trans('user', 'password_mismatch');
    }

    if (empty($errors)) {
        try {
            $stmt = $dcmt_pdo->prepare('SELECT dcmt_password FROM dcmt_users WHERE dcmt_id = ? LIMIT 1');
            $stmt->execute([(int) $user['dcmt_id']]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row || !password_verify($current_password, $row['dcmt_password'])) {
                $errors[] = trans('login', 'current_password_invalid');
            } else {
                $hash = password_hash($new_password, PASSWORD_DEFAULT);
                $update = $dcmt_pdo->prepare('
                    UPDATE dcmt_users
                    SET dcmt_password = ?, dcmt_must_change_password = 0, dcmt_updated_at = CURRENT_TIMESTAMP
                    WHERE dcmt_id = ?
                ');
                $update->execute([$hash, (int) $user['dcmt_id']]);
                dcmt_refresh_session_user_flags($dcmt_pdo, (int) $user['dcmt_id']);
                dcmt_audit('password_change', 'auth', (int) $user['dcmt_id']);
                dcmt_log_activity('Password changed', 'User ID: ' . (int) $user['dcmt_id']);
                dcmt_show_message(trans('login', 'password_changed_success'), 'success');
                $role = (string) ($user['dcmt_role'] ?? '');
                $redirect = in_array($role, ['staff', 'assistant'], true)
                    ? DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment'
                    : DCMT_APP_URL . '/pages/dashboard/';
                dcmt_redirect($redirect);
            }
        } catch (PDOException $e) {
            error_log('change_password: ' . $e->getMessage());
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
    <title><?php echo trans('login', 'change_password_title'); ?> - <?php echo dcmt_get_site_name(); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="../assets/css/main.css" rel="stylesheet">
</head>
<body class="login-page">
    <div class="login-card">
        <div class="login-header">
            <i class="fas fa-key fa-3x mb-3"></i>
            <h2 class="mb-0"><?php echo trans('login', 'change_password_title'); ?></h2>
            <p class="mb-0 mt-2"><?php echo trans('login', 'change_password_subtitle'); ?></p>
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
                    <label for="current_password" class="form-label"><?php echo trans('login', 'current_password'); ?></label>
                    <input type="password" class="form-control" id="current_password" name="current_password" required>
                </div>
                <div class="mb-3">
                    <label for="new_password" class="form-label"><?php echo trans('login', 'new_password'); ?></label>
                    <input type="password" class="form-control" id="new_password" name="new_password" required minlength="<?php echo (int) DCMT_PASSWORD_MIN_LENGTH; ?>">
                </div>
                <div class="mb-3">
                    <label for="confirm_password" class="form-label"><?php echo trans('user', 'confirm_password'); ?></label>
                    <input type="password" class="form-control" id="confirm_password" name="confirm_password" required>
                </div>
                <div class="d-grid">
                    <button type="submit" class="btn btn-primary btn-login">
                        <i class="fas fa-save me-2"></i><?php echo trans('login', 'save_new_password'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</body>
</html>
