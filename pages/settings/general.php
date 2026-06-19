<?php
/**
 * General Settings Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$errors = [];
$success = false;

// Get current settings
$currency_setting = null;
$pagination_setting = null;
$site_name_setting = null;
$logo_setting = null;
$language_setting = null;

try {
    // Get currency setting
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'currency_type'");
    $stmt->execute();
    $currency_setting = $stmt->fetch();
    
    // Get pagination setting
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'pagination_count'");
    $stmt->execute();
    $pagination_setting = $stmt->fetch();
    
    // Get site name setting
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'site_name'");
    $stmt->execute();
    $site_name_setting = $stmt->fetch();
    
    // Get logo setting
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'logo_path'");
    $stmt->execute();
    $logo_setting = $stmt->fetch();
    
    // Get language setting
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'language'");
    $stmt->execute();
    $language_setting = $stmt->fetch();
    
} catch (PDOException $e) {
    error_log("Error fetching settings: " . $e->getMessage());
    $errors[] = trans('settings', 'load_failed');
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $csrf_token = $_POST['csrf_token'] ?? '';
    
    // Validate CSRF token
    if (!dcmt_verify_csrf_token($csrf_token)) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Get form data
        $currency_type = dcmt_sanitize_input($_POST['currency_type'] ?? '');
        $pagination_count = intval($_POST['pagination_count'] ?? 20);
        $site_name = dcmt_sanitize_input($_POST['site_name'] ?? '');
        $language = dcmt_sanitize_input($_POST['language'] ?? 'es');
        
        // Validate required fields
        if (empty($currency_type)) {
            $errors[] = trans('settings', 'currency_required');
        }
        
        if ($pagination_count <= 0 || $pagination_count > 100) {
            $errors[] = trans('settings', 'pagination_range');
        }
        
        if (!in_array($language, ['en', 'es'])) {
            $errors[] = trans('settings', 'invalid_language');
        }
        
        if (empty($site_name)) {
            $errors[] = trans('settings', 'site_name_required');
        }
        
        // Handle logo deletion if requested
        $delete_logo = isset($_POST['delete_logo']) && $_POST['delete_logo'] === '1';
        
        // Handle logo upload if provided
        $logo_path = $logo_setting['dcmt_setting_value'] ?? ''; // Keep existing logo if no new one uploaded
        
        if ($delete_logo) {
            // Delete existing logo file if it exists
            if (!empty($logo_setting['dcmt_setting_value']) && file_exists(__DIR__ . '/../../' . $logo_setting['dcmt_setting_value'])) {
                unlink(__DIR__ . '/../../' . $logo_setting['dcmt_setting_value']);
            }
            $logo_path = ''; // Clear logo path
        } elseif (isset($_FILES['logo']) && $_FILES['logo']['error'] === UPLOAD_ERR_OK) {
            $logo_file = $_FILES['logo'];
            $max_size = 2 * 1024 * 1024; // 2MB
            
            if (!dcmt_validate_image_upload($logo_file)) {
                $errors[] = trans('settings', 'invalid_logo_type');
            }
            
            // Validate file size
            if ($logo_file['size'] > $max_size) {
                $errors[] = trans('settings', 'logo_size_limit');
            }
            
            // If validation passes, process the upload
            if (empty($errors)) {
                $upload_dir = __DIR__ . '/../../uploads/logos/';
                if (!is_dir($upload_dir)) {
                    mkdir($upload_dir, 0755, true);
                }
                
                $file_extension = pathinfo($logo_file['name'], PATHINFO_EXTENSION);
                $logo_filename = 'logo_' . time() . '.' . $file_extension;
                $logo_path = 'uploads/logos/' . $logo_filename;
                
                if (move_uploaded_file($logo_file['tmp_name'], __DIR__ . '/../../' . $logo_path)) {
                    // Delete old logo if exists
                    if (!empty($logo_setting['dcmt_setting_value']) && file_exists(__DIR__ . '/../../' . $logo_setting['dcmt_setting_value'])) {
                        unlink(__DIR__ . '/../../' . $logo_setting['dcmt_setting_value']);
                    }
                } else {
                    $errors[] = trans('settings', 'logo_upload_failed');
                }
            }
        }
        
        // If no validation errors, proceed with saving
        if (empty($errors)) {
            try {
                $dcmt_pdo->beginTransaction();
                
                // Update or insert currency setting
                $stmt = $dcmt_pdo->prepare("
                    INSERT INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_type, dcmt_category, dcmt_created_by) 
                    VALUES (?, ?, ?, ?, ?, ?) 
                    ON DUPLICATE KEY UPDATE 
                    dcmt_setting_value = VALUES(dcmt_setting_value),
                    dcmt_updated_at = CURRENT_TIMESTAMP
                ");
                
                $stmt->execute([
                    'currency_type',
                    'Currency Type',
                    $currency_type,
                    'select',
                    'General',
                    dcmt_get_current_user()['dcmt_username']
                ]);
                
                // Update or insert pagination setting
                $stmt->execute([
                    'pagination_count',
                    'Pagination Count',
                    $pagination_count,
                    'number',
                    'General',
                    dcmt_get_current_user()['dcmt_username']
                ]);
                
                // Update or insert site name setting
                $stmt->execute([
                    'site_name',
                    'Site Name',
                    $site_name,
                    'text',
                    'General',
                    dcmt_get_current_user()['dcmt_username']
                ]);
                
                // Update or insert logo setting
                $stmt->execute([
                    'logo_path',
                    'Logo Path',
                    $logo_path,
                    'text',
                    'General',
                    dcmt_get_current_user()['dcmt_username']
                ]);
                
                // Update or insert language setting
                $stmt->execute([
                    'language',
                    'Language',
                    $language,
                    'select',
                    'General',
                    dcmt_get_current_user()['dcmt_username']
                ]);
                
                $dcmt_pdo->commit();
                
                dcmt_log_activity('General settings updated', "Currency: $currency_type, Pagination: $pagination_count, Site Name: $site_name, Language: $language");
                dcmt_show_message(trans('settings', 'update_success'), 'success');
                
                // Refresh the page to show updated values
                dcmt_redirect('general.php');
                
            } catch (PDOException $e) {
                $dcmt_pdo->rollBack();
                error_log("Settings update failed: " . $e->getMessage());
                $errors[] = trans('settings', 'update_failed');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

// Get current values for form
$current_currency = $currency_setting['dcmt_setting_value'] ?? 'USD';
$current_pagination = $pagination_setting['dcmt_setting_value'] ?? 20;
$current_site_name = $site_name_setting['dcmt_setting_value'] ?? DCMT_APP_NAME;
$current_logo = $logo_setting['dcmt_setting_value'] ?? '';
$current_language = $language_setting['dcmt_setting_value'] ?? 'es';

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>


<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Application Settings -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <?php echo trans('settings', 'application_settings'); ?>
        </h6>
    </div>
            <div class="card-body">
                <form method="POST" action="" enctype="multipart/form-data" class="settings-form">
                    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                    <input type="hidden" name="delete_logo" id="delete_logo" value="0">
                    
                    <div class="mb-4">
                        <label for="site_name" class="form-label"><?php echo trans('settings', 'site_name'); ?> *</label>
                        <input type="text" class="form-control" id="site_name" name="site_name" 
                               value="<?php echo htmlspecialchars($current_site_name); ?>" 
                               maxlength="100" required>
                        <div class="form-text">
                            <?php echo trans('settings', 'site_name_help'); ?>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="logo" class="form-label"><?php echo trans('settings', 'logo'); ?></label>
                        <div class="row">
                            <div class="col-md-8">
                                <input type="file" class="form-control" id="logo" name="logo" 
                                       accept="image/jpeg,image/png,image/gif,image/webp" onchange="previewLogo(this)">
                                <div class="form-text">
                                    <?php echo trans('settings', 'logo_help'); ?>
                                </div>
                            </div>
                            <div class="col-md-4">
                                <div id="logoPreview">
                                    <?php if (!empty($current_logo) && file_exists(__DIR__ . '/../../' . $current_logo)): ?>
                                        <div class="current-logo">
                                            <img src="<?php echo '../../' . $current_logo; ?>" alt="<?php echo trans('settings', 'current_logo'); ?>" 
                                                 class="img-fluid rounded" style="max-height: 80px; max-width: 100%;">
                                            <div class="form-text text-center mt-2"><?php echo trans('settings', 'current_logo'); ?></div>
                                            <div class="text-center mt-2">
                                                <button type="button" class="btn btn-sm btn-outline-danger" onclick="confirmDeleteLogo()">
                                                    <i class="fas fa-trash me-1"></i><?php echo trans('settings', 'delete_logo'); ?>
                                                </button>
                                            </div>
                                        </div>
                                    <?php else: ?>
                                        <div class="no-logo text-center text-muted">
                                            <i class="fas fa-image fa-2x mb-2"></i>
                                            <div class="form-text"><?php echo trans('settings', 'no_logo'); ?></div>
                                        </div>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="currency_type" class="form-label"><?php echo trans('settings', 'currency_type'); ?> *</label>
                        <select class="form-select" id="currency_type" name="currency_type" required>
                            <option value=""><?php echo trans('settings', 'select_currency'); ?></option>
                            <option value="USD" <?php echo $current_currency === 'USD' ? 'selected' : ''; ?>>USD - US Dollar</option>
                            <option value="EUR" <?php echo $current_currency === 'EUR' ? 'selected' : ''; ?>>EUR - Euro</option>
                            <option value="GBP" <?php echo $current_currency === 'GBP' ? 'selected' : ''; ?>>GBP - British Pound</option>
                            <option value="INR" <?php echo $current_currency === 'INR' ? 'selected' : ''; ?>>INR - Indian Rupee</option>
                            <option value="CAD" <?php echo $current_currency === 'CAD' ? 'selected' : ''; ?>>CAD - Canadian Dollar</option>
                            <option value="AUD" <?php echo $current_currency === 'AUD' ? 'selected' : ''; ?>>AUD - Australian Dollar</option>
                            <option value="JPY" <?php echo $current_currency === 'JPY' ? 'selected' : ''; ?>>JPY - Japanese Yen</option>
                            <option value="CHF" <?php echo $current_currency === 'CHF' ? 'selected' : ''; ?>>CHF - Swiss Franc</option>
                            <option value="MXN" <?php echo $current_currency === 'MXN' ? 'selected' : ''; ?>>MXN - Pesos Mexicanos</option>
                        </select>
                        <div class="form-text">
                            <?php echo trans('settings', 'currency_help'); ?>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="language" class="form-label"><?php echo trans('settings', 'language'); ?> *</label>
                        <select class="form-select" id="language" name="language" required>
                            <option value=""><?php echo trans('settings', 'select_language'); ?></option>
                            <option value="es" <?php echo $current_language === 'es' ? 'selected' : ''; ?>>Spanish</option>
                            <option value="en" <?php echo $current_language === 'en' ? 'selected' : ''; ?>>English</option>
                        </select>
                        <div class="form-text">
                            <?php echo trans('settings', 'language_help'); ?>
                        </div>
                    </div>
                    
                    <div class="mb-4">
                        <label for="pagination_count" class="form-label"><?php echo trans('settings', 'pagination_count'); ?> *</label>
                        <input type="number" class="form-control" id="pagination_count" name="pagination_count" 
                               value="<?php echo htmlspecialchars($current_pagination); ?>" 
                               min="5" max="100" step="5" required>
                        <div class="form-text">
                            <?php echo trans('settings', 'pagination_help'); ?>
                        </div>
                    </div>
                    
                    <div class="dcmt-form-actions">
                        <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                            <i class="fas fa-save"></i><?php echo trans('settings', 'save_settings'); ?>
                        </button>
                    </div>
                </form>
            </div>
        </div>
    </div>
</div>

<script>
function previewLogo(input) {
    const preview = document.getElementById('logoPreview');
    
    if (input.files && input.files[0]) {
        const reader = new FileReader();
        
        reader.onload = function(e) {
            preview.innerHTML = `
                <div class="current-logo">
                    <img src="${e.target.result}" alt="<?php echo trans('settings', 'logo_preview'); ?>" 
                         class="img-fluid rounded" style="max-height: 80px; max-width: 100%;">
                    <div class="form-text text-center mt-2"><?php echo trans('settings', 'logo_preview'); ?></div>
                </div>
            `;
        };
        
        reader.readAsDataURL(input.files[0]);
    } else {
        // Reset to current logo or no logo state
        <?php if (!empty($current_logo) && file_exists(__DIR__ . '/../../' . $current_logo)): ?>
        preview.innerHTML = `
            <div class="current-logo">
                <img src="<?php echo '../../' . $current_logo; ?>" alt="<?php echo trans('settings', 'current_logo'); ?>" 
                     class="img-fluid rounded" style="max-height: 80px; max-width: 100%;">
                <div class="form-text text-center mt-2"><?php echo trans('settings', 'current_logo'); ?></div>
            </div>
        `;
        <?php else: ?>
        preview.innerHTML = `
            <div class="no-logo text-center text-muted">
                <i class="fas fa-image fa-2x mb-2"></i>
                <div class="form-text"><?php echo trans('settings', 'no_logo'); ?></div>
            </div>
        `;
        <?php endif; ?>
    }
}

// Function to confirm logo deletion
function confirmDeleteLogo() {
    if (confirm('<?php echo trans('settings', 'confirm_delete_logo'); ?>')) {
        // Set the delete logo flag
        document.getElementById('delete_logo').value = '1';
        
        // Clear the file input
        document.getElementById('logo').value = '';
        
        // Update the preview to show no logo
        const preview = document.getElementById('logoPreview');
        preview.innerHTML = `
            <div class="no-logo text-center text-muted">
                <i class="fas fa-image fa-2x mb-2"></i>
                <div class="form-text"><?php echo trans('settings', 'no_logo'); ?></div>
            </div>
        `;
        
        // Show a message that logo will be deleted
        const message = document.createElement('div');
        message.className = 'alert alert-warning mt-2';
        message.innerHTML = '<i class="fas fa-exclamation-triangle me-1"></i><?php echo trans('settings', 'logo_will_be_deleted'); ?>';
        preview.appendChild(message);
        
        // Auto-hide the message after 3 seconds
        setTimeout(() => {
            if (message.parentNode) {
                message.parentNode.removeChild(message);
            }
        }, 3000);
    }
}

// Initialize form functionality
document.addEventListener('DOMContentLoaded', function() {
    const form = document.querySelector('.settings-form');
    const submitBtn = document.getElementById('submitBtn');
    
    if (!form || !submitBtn) {
        return;
    }
    
    // Add form submission loading state
    form.addEventListener('submit', function(e) {
        const originalText = submitBtn.innerHTML;
        
        // Show loading state
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
    
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
