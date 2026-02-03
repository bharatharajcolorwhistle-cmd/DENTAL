<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/header.php';

// Get settings
try {
    $stmt = $dcmt_pdo->query("SELECT * FROM dcmt_settings ORDER BY dcmt_setting_key");
    $settings = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Organize settings by category
    $settings_by_category = [];
    foreach ($settings as $setting) {
        $category = $setting['dcmt_category'] ?? 'General';
        if (!isset($settings_by_category[$category])) {
            $settings_by_category[$category] = [];
        }
        $settings_by_category[$category][] = $setting;
    }
    
} catch (PDOException $e) {
    dcmt_show_message("Database error: " . $e->getMessage(), "error");
    $settings_by_category = [];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        dcmt_show_message("Invalid request. Please try again.", "error");
        dcmt_redirect("index.php");
    }
    
    $errors = [];
    $updated_count = 0;
    
    try {
        // Start transaction
        $dcmt_pdo->beginTransaction();
        
        foreach ($_POST['settings'] as $setting_id => $value) {
            $setting_id = intval($setting_id);
            $value = dcmt_sanitize_input($value);
            
            // Update setting
            $stmt = $dcmt_pdo->prepare("UPDATE dcmt_settings SET dcmt_setting_value = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?");
            $stmt->execute([$value, $setting_id]);
            
            if ($stmt->rowCount() > 0) {
                $updated_count++;
            }
        }
        
        if ($updated_count > 0) {
            // Log activity
            dcmt_log_activity("System settings updated - $updated_count settings modified", "settings_updated");
            
            // Commit transaction
            $dcmt_pdo->commit();
            
            dcmt_show_message(trans('settings', 'update_success'), "success");
            dcmt_redirect("index.php");
        } else {
            dcmt_show_message("No changes were made to the settings.", "info");
            dcmt_redirect("index.php");
        }
        
    } catch (Exception $e) {
        // Rollback transaction
        $dcmt_pdo->rollBack();
        dcmt_show_message("Error updating settings: " . $e->getMessage(), "error");
    }
}

$csrf_token = dcmt_generate_csrf_token();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>System Settings</h1>
    <div>
        <button type="button" class="btn btn-success" onclick="document.getElementById('settingsForm').submit()">
            <i class="fas fa-save"></i> Save All Changes
        </button>
        <a href="../../dashboard/" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
    </div>
</div>

<div class="alert alert-info">
    <i class="fas fa-info-circle"></i>
    <strong>Note:</strong> Changes to system settings will take effect immediately. Some settings may require a page refresh to see the changes.
</div>

<form method="POST" action="" id="settingsForm">
    <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
    
    <?php if (empty($settings_by_category)): ?>
        <div class="card">
            <div class="card-body text-center py-5">
                <i class="fas fa-cog fa-3x text-muted mb-3"></i>
                <h5 class="text-muted">No settings found</h5>
                <p class="text-muted">System settings have not been configured yet.</p>
            </div>
        </div>
    <?php else: ?>
        <?php foreach ($settings_by_category as $category => $category_settings): ?>
            <div class="card mb-4">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-folder"></i> <?php echo htmlspecialchars($category); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <div class="row">
                        <?php foreach ($category_settings as $setting): ?>
                            <div class="col-md-6 mb-3">
                                <label for="setting_<?php echo $setting['dcmt_id']; ?>" class="form-label">
                                    <?php echo htmlspecialchars($setting['dcmt_setting_name']); ?>
                                    <?php if ($setting['dcmt_required']): ?>
                                        <span class="text-danger">*</span>
                                    <?php endif; ?>
                                </label>
                                
                                <?php if ($setting['dcmt_setting_type'] === 'textarea'): ?>
                                    <textarea class="form-control" 
                                              id="setting_<?php echo $setting['dcmt_id']; ?>" 
                                              name="settings[<?php echo $setting['dcmt_id']; ?>]" 
                                              rows="3" 
                                              <?php echo $setting['dcmt_required'] ? 'required' : ''; ?>
                                              maxlength="<?php echo $setting['dcmt_max_length'] ?? 500; ?>"
                                              placeholder="<?php echo htmlspecialchars($setting['dcmt_setting_description'] ?? ''); ?>"><?php echo htmlspecialchars($setting['dcmt_setting_value']); ?></textarea>
                                <?php elseif ($setting['dcmt_setting_type'] === 'select'): ?>
                                    <select class="form-select" 
                                            id="setting_<?php echo $setting['dcmt_id']; ?>" 
                                            name="settings[<?php echo $setting['dcmt_id']; ?>]"
                                            <?php echo $setting['dcmt_required'] ? 'required' : ''; ?>>
                                        <?php
                                        $options = json_decode($setting['dcmt_setting_options'] ?? '[]', true);
                                        foreach ($options as $option_value => $option_label):
                                        ?>
                                            <option value="<?php echo htmlspecialchars($option_value); ?>" 
                                                    <?php echo $setting['dcmt_setting_value'] == $option_value ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($option_label); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                <?php elseif ($setting['dcmt_setting_type'] === 'number'): ?>
                                    <input type="number" class="form-control" 
                                           id="setting_<?php echo $setting['dcmt_id']; ?>" 
                                           name="settings[<?php echo $setting['dcmt_id']; ?>]" 
                                           value="<?php echo htmlspecialchars($setting['dcmt_setting_value']); ?>"
                                           <?php echo $setting['dcmt_required'] ? 'required' : ''; ?>
                                           min="<?php echo $setting['dcmt_min_value'] ?? ''; ?>"
                                           max="<?php echo $setting['dcmt_max_value'] ?? ''; ?>"
                                           step="<?php echo $setting['dcmt_step_value'] ?? '1'; ?>"
                                           placeholder="<?php echo htmlspecialchars($setting['dcmt_setting_description'] ?? ''); ?>">
                                <?php elseif ($setting['dcmt_setting_type'] === 'email'): ?>
                                    <input type="email" class="form-control" 
                                           id="setting_<?php echo $setting['dcmt_id']; ?>" 
                                           name="settings[<?php echo $setting['dcmt_id']; ?>]" 
                                           value="<?php echo htmlspecialchars($setting['dcmt_setting_value']); ?>"
                                           <?php echo $setting['dcmt_required'] ? 'required' : ''; ?>
                                           maxlength="<?php echo $setting['dcmt_max_length'] ?? 100; ?>"
                                           placeholder="<?php echo htmlspecialchars($setting['dcmt_setting_description'] ?? ''); ?>">
                                <?php elseif ($setting['dcmt_setting_type'] === 'url'): ?>
                                    <input type="url" class="form-control" 
                                           id="setting_<?php echo $setting['dcmt_id']; ?>" 
                                           name="settings[<?php echo $setting['dcmt_id']; ?>]" 
                                           value="<?php echo htmlspecialchars($setting['dcmt_setting_value']); ?>"
                                           <?php echo $setting['dcmt_required'] ? 'required' : ''; ?>
                                           maxlength="<?php echo $setting['dcmt_max_length'] ?? 255; ?>"
                                           placeholder="<?php echo htmlspecialchars($setting['dcmt_setting_description'] ?? ''); ?>">
                                <?php elseif ($setting['dcmt_setting_type'] === 'checkbox'): ?>
                                    <div class="form-check">
                                        <input type="checkbox" class="form-check-input" 
                                               id="setting_<?php echo $setting['dcmt_id']; ?>" 
                                               name="settings[<?php echo $setting['dcmt_id']; ?>]" 
                                               value="1"
                                               <?php echo $setting['dcmt_setting_value'] == '1' ? 'checked' : ''; ?>>
                                        <label class="form-check-label" for="setting_<?php echo $setting['dcmt_id']; ?>">
                                            <?php echo htmlspecialchars($setting['dcmt_setting_description'] ?? 'Enable this setting'); ?>
                                        </label>
                                    </div>
                                <?php else: ?>
                                    <input type="text" class="form-control" 
                                           id="setting_<?php echo $setting['dcmt_id']; ?>" 
                                           name="settings[<?php echo $setting['dcmt_id']; ?>]" 
                                           value="<?php echo htmlspecialchars($setting['dcmt_setting_value']); ?>"
                                           <?php echo $setting['dcmt_required'] ? 'required' : ''; ?>
                                           maxlength="<?php echo $setting['dcmt_max_length'] ?? 255; ?>"
                                           placeholder="<?php echo htmlspecialchars($setting['dcmt_setting_description'] ?? ''); ?>">
                                <?php endif; ?>
                                
                                <?php if ($setting['dcmt_setting_description']): ?>
                                    <div class="form-text">
                                        <?php echo htmlspecialchars($setting['dcmt_setting_description']); ?>
                                    </div>
                                <?php endif; ?>
                                
                                <div class="mt-1">
                                    <small class="text-muted">
                                        <strong>Key:</strong> <?php echo htmlspecialchars($setting['dcmt_setting_key']); ?>
                                        <?php if ($setting['dcmt_updated_at']): ?>
                                            | <strong>Last Updated:</strong> <?php echo dcmt_format_date($setting['dcmt_updated_at']); ?>
                                        <?php endif; ?>
                                    </small>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        <?php endforeach; ?>
        
        <!-- Save Button -->
        <div class="card">
            <div class="card-body">
                <div class="d-flex justify-content-between align-items-center">
                    <div>
                        <i class="fas fa-save text-primary"></i>
                        <span class="text-muted">Click the button to save all changes</span>
                    </div>
                    <button type="submit" class="btn btn-primary btn-lg">
                        <i class="fas fa-save"></i> Save All Settings
                    </button>
                </div>
            </div>
        </div>
    <?php endif; ?>
</form>

<script>
// Auto-save confirmation
document.getElementById('settingsForm').addEventListener('submit', function(e) {
    return confirm('Are you sure you want to save all setting changes?');
});

// Track changes for unsaved changes warning
let hasChanges = false;
document.querySelectorAll('input, textarea, select').forEach(function(element) {
    element.addEventListener('change', function() {
        hasChanges = true;
    });
});

// Warn before leaving page if changes made
window.addEventListener('beforeunload', function(e) {
    if (hasChanges) {
        e.preventDefault();
        e.returnValue = 'You have unsaved changes. Are you sure you want to leave?';
    }
});

// Reset change tracking after successful save
document.getElementById('settingsForm').addEventListener('submit', function() {
    hasChanges = false;
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
