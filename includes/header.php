<?php
/**
 * Header Include
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../config/config.php';
require_once __DIR__ . '/../includes/password_policy.php';

$current_page = basename($_SERVER['PHP_SELF'], '.php');

// Extend session lifetime for active users
dcmt_extend_session();

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    // Session expired or invalid, redirect to login
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        dcmt_show_message(trans('login', 'session_expired'), 'warning');
        
        // Use absolute path to login page
        dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
        exit();
    }
}

$current_user = dcmt_get_current_user();

dcmt_enforce_password_change(false);

// Function to determine the correct base path for navigation
function get_base_path() {
    $current_dir = dirname($_SERVER['PHP_SELF']);
    if (strpos($current_dir, '/pages/') !== false) {
        return '../../';
    } elseif (strpos($current_dir, '/auth/') !== false) {
        return '../';
    } else {
        return './';
    }
}

$base_path = get_base_path();

// Ensure current_user has required fields, redirect to login if not
if (!$current_user || !isset($current_user['dcmt_id']) || !isset($current_user['dcmt_username'])) {
    // If we're not already on the login page, redirect there
    if (basename($_SERVER['PHP_SELF']) !== 'login.php') {
        dcmt_show_message(trans('login', 'session_expired'), 'warning');
        
        // Use absolute path to login page
        dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
        exit();
    }
}

// Staff cannot access the Users module
if (($current_user['dcmt_role'] ?? '') === 'staff') {
    $dcmt_request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    if (strpos($dcmt_request_path, '/pages/users/') !== false) {
        dcmt_show_message('Access denied. Staff cannot access user management.', 'danger');
        dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment');
        exit();
    }
}

// Enforce assistant access restriction globally for pages using header.php
if (($current_user['dcmt_role'] ?? '') === 'assistant') {
    $dcmt_request_path = parse_url($_SERVER['REQUEST_URI'] ?? '', PHP_URL_PATH) ?: '';
    $dcmt_allowed_prefixes = [
        '/pages/dashboard/',
        '/pages/patients/',
        '/pages/patient_notes/',
        '/pages/patient_odontogram/',
        '/pages/reminders/',
        '/pages/messaging/',
        '/pages/appointments/',
        '/pages/operatories/',
    ];
    $dcmt_has_allowed_access = false;
    foreach ($dcmt_allowed_prefixes as $dcmt_prefix) {
        if (strpos($dcmt_request_path, $dcmt_prefix) !== false) {
            $dcmt_has_allowed_access = true;
            break;
        }
    }

    if (!$dcmt_has_allowed_access) {
        dcmt_show_message('Access denied. Assistant can only access the Appointments Dashboard, Patients, and Appointments.', 'danger');
        dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment');
        exit();
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?php echo dcmt_get_site_name(); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.css" rel="stylesheet">
    <link href="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.css" rel="stylesheet">
    <link href="<?php echo $base_path; ?>assets/css/main.css" rel="stylesheet">
    <link href="<?php echo $base_path; ?>assets/css/select2.min.css" rel="stylesheet">
    
    <!-- jQuery (loaded in head to be available for page scripts) -->
    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    
    <!-- Moment.js (required for daterangepicker) -->
    <script src="https://cdn.jsdelivr.net/npm/moment@2.29.4/moment.min.js"></script>
    
    <!-- Date Range Picker -->
    <script src="https://cdn.jsdelivr.net/npm/daterangepicker/daterangepicker.min.js"></script>
    
    <!-- Session Keep-Alive Script -->
    <script>
    // Keep session alive with periodic AJAX calls
    (function() {
        let sessionCheckInterval;
        let warningShown = false;
        
        function keepSessionAlive() {
            fetch('<?php echo $base_path; ?>auth/keep_alive.php', {
                method: 'POST',
                headers: {
                    'Content-Type': 'application/x-www-form-urlencoded',
                },
                body: 'csrf_token=<?php echo dcmt_generate_csrf_token(); ?>'
            })
            .then(response => response.json())
            .then(data => {
                if (!data.success && data.session_expired) {
                    // Session expired, redirect to login
                    window.location.href = '<?php echo $base_path; ?>auth/login.php';
                } else if (data.success) {
                    // Reset warning flag on successful session extension
                    warningShown = false;
                }
            })
            .catch(error => {
                console.log('Session keep-alive failed:', error);
            });
        }
        
        function showSessionWarning() {
            if (!warningShown) {
                warningShown = true;
                // Create warning modal
                const warningModal = document.createElement('div');
                warningModal.className = 'modal fade';
                warningModal.innerHTML = `
                    <div class="modal-dialog modal-dialog-centered">
                        <div class="modal-content">
                            <div class="modal-header bg-warning text-dark">
                                <h5 class="modal-title">
                                    <i class="fas fa-exclamation-triangle me-2"></i>Session Warning
                                </h5>
                            </div>
                            <div class="modal-body">
                                <p>Your session will expire in 5 minutes due to inactivity.</p>
                                <p>Click "Extend Session" to continue working, or you will be automatically logged out.</p>
                            </div>
                            <div class="modal-footer">
                                <button type="button" class="btn btn-primary" onclick="extendSession()">
                                    <i class="fas fa-clock me-1"></i>Extend Session
                                </button>
                                <button type="button" class="btn btn-secondary" onclick="logout()">
                                    <i class="fas fa-sign-out-alt me-1"></i>Logout Now
                                </button>
                            </div>
                        </div>
                    </div>
                `;
                document.body.appendChild(warningModal);
                
                // Show modal
                const modal = new bootstrap.Modal(warningModal);
                modal.show();
                
                // Auto-hide after 4 minutes
                setTimeout(() => {
                    if (warningShown) {
                        modal.hide();
                        warningShown = false;
                    }
                }, 240000);
            }
        }
        
        // Global functions for modal buttons
        window.extendSession = function() {
            keepSessionAlive();
            const modal = document.querySelector('.modal.show');
            if (modal) {
                const bsModal = bootstrap.Modal.getInstance(modal);
                if (bsModal) bsModal.hide();
            }
            warningShown = false;
        };
        
        window.logout = function() {
            window.location.href = '<?php echo $base_path; ?>auth/logout.php';
        };
        
        // Start session keep-alive (every 10 minutes)
        if (typeof window !== 'undefined') {
            sessionCheckInterval = setInterval(keepSessionAlive, 600000); // 10 minutes
            
            // Show warning 5 minutes before session expires (7 hours 55 minutes)
            setTimeout(() => {
                setInterval(showSessionWarning, 600000); // Check every 10 minutes
            }, 28500000); // 7 hours 55 minutes
            
            // Also keep alive on user activity
            let activityTimeout;
            ['mousedown', 'mousemove', 'keypress', 'scroll', 'touchstart'].forEach(function(name) {
                document.addEventListener(name, function() {
                    clearTimeout(activityTimeout);
                    activityTimeout = setTimeout(keepSessionAlive, 300000); // 5 minutes after activity
                }, true);
            });
        }
    })();
    </script>
</head>
<body> 
    <!-- Main Header - Show on all pages -->
    <?php include __DIR__ . '/main_header.php'; ?>
    
    <!-- Sub Header - Show on all pages -->
    <?php include __DIR__ . '/sub_header.php'; ?>

    <!-- Main Content Container -->
    <div class="main-content">
        <!-- Flash Messages -->
        <?php
        $message = dcmt_get_message();
        if ($message): ?>
            <div class="alert alert-<?php echo $message['type']; ?> alert-dismissible fade show" role="alert">
                <?php echo htmlspecialchars($message['text']); ?>
                <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
            </div>
        <?php endif; ?>

        <!-- Page content starts here -->
