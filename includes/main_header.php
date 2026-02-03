<?php
/**
 * Main Header Component
 * Dental Clinic Management System
 */

// Get current user data
$current_user = dcmt_get_current_user();

// Get current page to determine active menu item
$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['REQUEST_URI'];

// Get logo path from settings
$logo_path = dcmt_get_logo_path();

// Function to determine the correct base path for navigation
if (!function_exists('get_base_path')) {
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
}

$base_path = get_base_path();

// Function to check if a path is active (only declare if not already declared)
if (!function_exists('is_active_path')) {
    function is_active_path($path) {
        global $current_path;
        return strpos($current_path, $path) !== false;
    }
}
?>

<!-- Main Header -->
<div class="main-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-6">
                <div class="header-content">
                    <div class="site-branding">
                        <div class="site-logo">
                            <a href="<?php echo $base_path; ?>pages/dashboard/index.php" class="logo-link">
                                <?php if (!empty($logo_path) && file_exists(__DIR__ . '/../' . $logo_path)): ?>
                                    <img src="<?php echo $base_path . $logo_path; ?>" alt="<?php echo dcmt_get_site_name(); ?>" class="logo-image">
                                <?php else: ?>
                                    <i class="fas fa-tooth fa-3x"></i>
                                <?php endif; ?>
                            </a>
                        </div>
                        <div class="site-info">
                            <h1 class="site-title">
                                <a href="<?php echo $base_path; ?>pages/dashboard/index.php" class="site-title-link"><?php echo dcmt_get_site_name(); ?></a>
                            </h1>
                            <p class="site-description"><?php echo trans('dashboard', 'site_description'); ?></p>
                        </div>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="header-actions">
                    <div class="current-date">
                        <i class="fas fa-calendar-alt me-2"></i>
                        <span class="date-text" id="currentDateTime" data-locale="<?php echo trans('common', 'date_format', 'en-US'); ?>" data-timezone="America/Mexico_City"><?php echo dcmt_get_current_datetime('D, j M Y - H:i A'); ?></span>
                    </div>
                    <div class="header-controls">
                        <div class="user-profile-dropdown <?php echo is_active_path('/users/view.php') || is_active_path('/users/edit.php') || is_active_path('/settings/') ? 'active' : ''; ?>">
                            <button class="btn btn-user-profile" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                                <div class="user-avatar">
                                    <?php echo isset($current_user['dcmt_username']) ? strtoupper(substr($current_user['dcmt_username'], 0, 1)) : 'U'; ?>
                                </div>
                                <span class="user-name"><?php echo isset($current_user['dcmt_username']) ? htmlspecialchars($current_user['dcmt_username']) : 'User'; ?></span>
                                <i class="fas fa-chevron-down ms-2"></i>
                            </button>
                            <ul class="dropdown-menu dropdown-menu-end user-menu">
                                <li><a class="dropdown-item <?php echo is_active_path('/users/view.php') ? 'active' : ''; ?>" href="../users/view.php?id=<?php echo isset($current_user['dcmt_id']) ? $current_user['dcmt_id'] : ''; ?>"><i class="fas fa-user me-2"></i><?php echo trans('common', 'profile'); ?></a></li>
                                <li><a class="dropdown-item <?php echo is_active_path('/users/edit.php') ? 'active' : ''; ?>" href="../users/edit.php?id=<?php echo isset($current_user['dcmt_id']) ? $current_user['dcmt_id'] : ''; ?>"><i class="fas fa-edit me-2"></i><?php echo trans('common', 'edit_profile'); ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item <?php echo is_active_path('/settings/') ? 'active' : ''; ?>" href="../settings/general.php"><i class="fas fa-sliders-h me-2"></i><?php echo trans('common', 'general_settings'); ?></a></li>
                                <li><hr class="dropdown-divider"></li>
                                <li><a class="dropdown-item" href="../../auth/logout.php"><i class="fas fa-sign-out-alt me-2"></i><?php echo trans('common', 'logout'); ?></a></li>
                            </ul>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
// Real-time clock functionality
function updateDateTime() {
    const now = new Date();
    const dateTimeElement = document.getElementById('currentDateTime');
    
    if (!dateTimeElement) return;
    
    // Get locale from data attribute or default to en-US
    const locale = dateTimeElement.getAttribute('data-locale') || 'en-US';
    const timezone = dateTimeElement.getAttribute('data-timezone') || 'America/Mexico_City';
    
    // Use Intl.DateTimeFormat with the specified locale and timezone
    const formatter = new Intl.DateTimeFormat(locale, {
        timeZone: timezone,
        weekday: 'short',
        day: 'numeric',
        month: 'short',
        year: 'numeric',
        hour: 'numeric',
        minute: '2-digit',
        hour12: true
    });
    
    // Format the date
    const formattedDateTime = formatter.format(now);
    
    dateTimeElement.textContent = formattedDateTime;
}

// Update immediately and then every second
updateDateTime();
setInterval(updateDateTime, 1000);
</script>
