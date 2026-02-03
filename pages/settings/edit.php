<?php
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/header.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Get setting ID from URL
$setting_id = isset($_GET['id']) ? intval($_GET['id']) : 0;

if ($setting_id <= 0) {
    dcmt_show_message("Invalid setting ID.", "error");
    dcmt_redirect("index.php");
}

// Get setting details
try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_settings WHERE dcmt_id = ?");
    $stmt->execute([$setting_id]);
    $setting = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$setting) {
        dcmt_show_message("Setting not found.", "error");
        dcmt_redirect("index.php");
    }
    
} catch (PDOException $e) {
    dcmt_show_message("Database error: " . $e->getMessage(), "error");
    dcmt_redirect("index.php");
}

$errors = [];
$form_data = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        dcmt_show_message("Invalid request. Please try again.", "error");
        dcmt_redirect("edit.php?id=$setting_id");
    }
    
    // Get form data
    $form_data = [
        'setting_name' => dcmt_sanitize_input($_POST['setting_name']),
        'setting_value' => dcmt_sanitize_input($_POST['setting_value']),
        'category' => dcmt_sanitize_input($_POST['category']),
        'required' => isset($_POST['required']) ? 1 : 0,
        'setting_description' => dcmt_sanitize_input($_POST['setting_description']),
        'max_length' => dcmt_sanitize_input($_POST['max_length']),
        'min_value' => dcmt_sanitize_input($_POST['min_value']),
        'max_value' => dcmt_sanitize_input($_POST['max_value']),
        'step_value' => dcmt_sanitize_input($_POST['step_value']),
        'setting_options' => dcmt_sanitize_input($_POST['setting_options'])
    ];
    
    // Validate required fields
    $required_fields = ['setting_name', 'setting_value'];
    $validation_result = dcmt_validate_required_fields($form_data, $required_fields);
    if (!$validation_result['valid']) {
        $errors = $validation_result['errors'];
    }
    
    // Validate numeric fields
    if (empty($errors) && $form_data['max_length'] && !is_numeric($form_data['max_length'])) {
        $errors[] = "Maximum length must be a number.";
    }
    
    if (empty($errors) && $form_data['min_value'] && !is_numeric($form_data['min_value'])) {
        $errors[] = "Minimum value must be a number.";
    }
    
    if (empty($errors) && $form_data['max_value'] && !is_numeric($form_data['max_value'])) {
        $errors[] = "Maximum value must be a number.";
    }
    
    if (empty($errors) && $form_data['step_value'] && !is_numeric($form_data['step_value'])) {
        $errors[] = "Step value must be a number.";
    }
    
    // Validate select options if setting type is select
    if (empty($errors) && $setting['dcmt_setting_type'] === 'select') {
        if (empty($form_data['setting_options'])) {
            $errors[] = "Select options are required for select type settings.";
        } else {
            // Validate JSON format for options
            $options = json_decode($form_data['setting_options'], true);
            if (json_last_error() !== JSON_ERROR_NONE) {
                $errors[] = "Select options must be in valid JSON format (e.g., {\"value1\":\"Label 1\",\"value2\":\"Label 2\"}).";
            }
        }
    }
    
    // If no errors, update setting
    if (empty($errors)) {
        try {
            $sql = "UPDATE dcmt_settings SET dcmt_setting_name = ?, dcmt_setting_value = ?, dcmt_category = ?, dcmt_required = ?, dcmt_setting_description = ?, dcmt_max_length = ?, dcmt_min_value = ?, dcmt_max_value = ?, dcmt_step_value = ?, dcmt_setting_options = ?, dcmt_updated_at = NOW() WHERE dcmt_id = ?";
            $stmt = $dcmt_pdo->prepare($sql);
            $stmt->execute([
                $form_data['setting_name'],
                $form_data['setting_value'],
                $form_data['category'],
                $form_data['required'],
                $form_data['setting_description'],
                $form_data['max_length'] ?: null,
                $form_data['min_value'] ?: null,
                $form_data['max_value'] ?: null,
                $form_data['step_value'] ?: null,
                $form_data['setting_options'] ?: null,
                $setting_id
            ]);
            
            // Log activity
            dcmt_log_activity("System setting updated: {$setting['dcmt_setting_key']} ({$form_data['setting_name']})", "setting_updated");
            
            dcmt_show_message(trans('settings', 'setting_updated_success'), "success");
            dcmt_redirect("index.php");
            
        } catch (PDOException $e) {
            $errors[] = "Database error occurred. Please try again.";
        }
    }
} else {
    // Use existing setting data for form
    $form_data = [
        'setting_name' => $setting['dcmt_setting_name'],
        'setting_value' => $setting['dcmt_setting_value'],
        'category' => $setting['dcmt_category'],
        'required' => $setting['dcmt_required'],
        'setting_description' => $setting['dcmt_setting_description'],
        'max_length' => $setting['dcmt_max_length'],
        'min_value' => $setting['dcmt_min_value'],
        'max_value' => $setting['dcmt_max_value'],
        'step_value' => $setting['dcmt_step_value'],
        'setting_options' => $setting['dcmt_setting_options']
    ];
}

$csrf_token = dcmt_generate_csrf_token();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Edit System Setting</h1>
    <div>
        <a href="index.php" class="btn btn-secondary">
            <i class="fas fa-arrow-left"></i> Back to Settings
        </a>
    </div>
</div>

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <h6><i class="fas fa-exclamation-triangle"></i> Please correct the following errors:</h6>
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo $error; ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="card">
    <div class="card-body">
        <form method="POST" action="" id="settingForm">
            <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="setting_key" class="form-label">Setting Key</label>
                        <input type="text" class="form-control" id="setting_key" 
                               value="<?php echo htmlspecialchars($setting['dcmt_setting_key']); ?>" 
                               readonly disabled>
                        <div class="form-text">Setting key cannot be changed</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="setting_type" class="form-label">Setting Type</label>
                        <input type="text" class="form-control" id="setting_type" 
                               value="<?php echo ucfirst(htmlspecialchars($setting['dcmt_setting_type'])); ?>" 
                               readonly disabled>
                        <div class="form-text">Setting type cannot be changed</div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="setting_name" class="form-label">Setting Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="setting_name" name="setting_name" 
                               value="<?php echo htmlspecialchars($form_data['setting_name']); ?>" 
                               required maxlength="100">
                        <div class="form-text">Human-readable name for the setting</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="category" class="form-label">Category</label>
                        <input type="text" class="form-control" id="category" name="category" 
                               value="<?php echo htmlspecialchars($form_data['category']); ?>" 
                               maxlength="50">
                        <div class="form-text">Category to group related settings</div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="setting_value" class="form-label">Setting Value <span class="text-danger">*</span></label>
                        <?php if ($setting['dcmt_setting_type'] === 'textarea'): ?>
                            <textarea class="form-control" id="setting_value" name="setting_value" 
                                      rows="3" required maxlength="<?php echo $setting['dcmt_max_length'] ?? 500; ?>"><?php echo htmlspecialchars($form_data['setting_value']); ?></textarea>
                        <?php elseif ($setting['dcmt_setting_type'] === 'select'): ?>
                            <select class="form-select" id="setting_value" name="setting_value" required>
                                <?php
                                $options = json_decode($setting['dcmt_setting_options'] ?? '[]', true);
                                foreach ($options as $option_value => $option_label):
                                ?>
                                    <option value="<?php echo htmlspecialchars($option_value); ?>" 
                                            <?php echo $form_data['setting_value'] == $option_value ? 'selected' : ''; ?>>
                                        <?php echo htmlspecialchars($option_label); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php elseif ($setting['dcmt_setting_type'] === 'number'): ?>
                            <input type="number" class="form-control" id="setting_value" name="setting_value" 
                                   value="<?php echo htmlspecialchars($form_data['setting_value']); ?>" 
                                   required min="<?php echo $setting['dcmt_min_value'] ?? ''; ?>" 
                                   max="<?php echo $setting['dcmt_max_value'] ?? ''; ?>" 
                                   step="<?php echo $setting['dcmt_step_value'] ?? '1'; ?>">
                        <?php elseif ($setting['dcmt_setting_type'] === 'checkbox'): ?>
                            <div class="form-check">
                                <input type="checkbox" class="form-check-input" id="setting_value" name="setting_value" 
                                       value="1" <?php echo $form_data['setting_value'] == '1' ? 'checked' : ''; ?>>
                                <label class="form-check-label" for="setting_value">
                                    Enable this setting
                                </label>
                            </div>
                        <?php else: ?>
                            <input type="<?php echo $setting['dcmt_setting_type']; ?>" class="form-control" id="setting_value" name="setting_value" 
                                   value="<?php echo htmlspecialchars($form_data['setting_value']); ?>" 
                                   required maxlength="<?php echo $setting['dcmt_max_length'] ?? 255; ?>">
                        <?php endif; ?>
                        <div class="form-text">Current value for the setting</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="required" class="form-label">Required</label>
                        <div class="form-check mt-2">
                            <input class="form-check-input" type="checkbox" id="required" name="required" 
                                   value="1" <?php echo $form_data['required'] ? 'checked' : ''; ?>>
                            <label class="form-check-label" for="required">
                                This setting is required
                            </label>
                        </div>
                    </div>
                </div>
            </div>
            
            <div class="mb-3">
                <label for="setting_description" class="form-label">Description</label>
                <textarea class="form-control" id="setting_description" name="setting_description" 
                          rows="3" maxlength="500"><?php echo htmlspecialchars($form_data['setting_description']); ?></textarea>
                <div class="form-text">Description of what this setting controls</div>
            </div>
            
            <!-- Type-specific options -->
            <?php if (in_array($setting['dcmt_setting_type'], ['text', 'textarea', 'email', 'url'])): ?>
                <div class="row">
                    <div class="col-md-6">
                        <div class="mb-3">
                            <label for="max_length" class="form-label">Maximum Length</label>
                            <input type="number" class="form-control" id="max_length" name="max_length" 
                                   value="<?php echo htmlspecialchars($form_data['max_length']); ?>" 
                                   min="1" max="1000">
                            <div class="form-text">Maximum number of characters allowed</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($setting['dcmt_setting_type'] === 'number'): ?>
                <div class="row">
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="min_value" class="form-label">Minimum Value</label>
                            <input type="number" class="form-control" id="min_value" name="min_value" 
                                   value="<?php echo htmlspecialchars($form_data['min_value']); ?>" 
                                   step="any">
                            <div class="form-text">Minimum allowed value</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="max_value" class="form-label">Maximum Value</label>
                            <input type="number" class="form-control" id="max_value" name="max_value" 
                                   value="<?php echo htmlspecialchars($form_data['max_value']); ?>" 
                                   step="any">
                            <div class="form-text">Maximum allowed value</div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="mb-3">
                            <label for="step_value" class="form-label">Step Value</label>
                            <input type="number" class="form-control" id="step_value" name="step_value" 
                                   value="<?php echo htmlspecialchars($form_data['step_value']); ?>" 
                                   min="0.01" step="0.01">
                            <div class="form-text">Increment step for number inputs</div>
                        </div>
                    </div>
                </div>
            <?php endif; ?>
            
            <?php if ($setting['dcmt_setting_type'] === 'select'): ?>
                <div class="mb-3">
                    <label for="setting_options" class="form-label">Select Options <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="setting_options" name="setting_options" 
                              rows="5" maxlength="1000"><?php echo htmlspecialchars($form_data['setting_options']); ?></textarea>
                    <div class="form-text">JSON format: {"value1":"Label 1","value2":"Label 2"}</div>
                    <div class="form-text">Example: {"enabled":"Enabled","disabled":"Disabled"}</div>
                </div>
            <?php endif; ?>
            
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset to Original
                </button>
                <div>
                    <a href="index.php" class="btn btn-secondary me-2">
                        <i class="fas fa-times"></i> Cancel
                    </a>
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-save"></i> Update Setting
                    </button>
                </div>
            </div>
        </form>
    </div>
</div>

<script>
function resetForm() {
    if (confirm('Are you sure you want to reset the form to the original values? All changes will be lost.')) {
        // Reset form to original values
        document.getElementById('setting_name').value = '<?php echo addslashes($setting['dcmt_setting_name']); ?>';
        document.getElementById('category').value = '<?php echo addslashes($setting['dcmt_category'] ?? ''); ?>';
        document.getElementById('setting_description').value = '<?php echo addslashes($setting['dcmt_setting_description'] ?? ''); ?>';
        document.getElementById('max_length').value = '<?php echo addslashes($setting['dcmt_max_length'] ?? ''); ?>';
        document.getElementById('min_value').value = '<?php echo addslashes($setting['dcmt_min_value'] ?? ''); ?>';
        document.getElementById('max_value').value = '<?php echo addslashes($setting['dcmt_max_value'] ?? ''); ?>';
        document.getElementById('step_value').value = '<?php echo addslashes($setting['dcmt_step_value'] ?? ''); ?>';
        document.getElementById('setting_options').value = '<?php echo addslashes($setting['dcmt_setting_options'] ?? ''); ?>';
        
        // Reset checkbox
        if (<?php echo $setting['dcmt_required']; ?>) {
            document.getElementById('required').checked = true;
        } else {
            document.getElementById('required').checked = false;
        }
        
        // Reset value field based on type
        const valueField = document.getElementById('setting_value');
        if (valueField.type === 'checkbox') {
            valueField.checked = <?php echo $setting['dcmt_setting_value'] == '1' ? 'true' : 'false'; ?>;
        } else {
            valueField.value = '<?php echo addslashes($setting['dcmt_setting_value']); ?>';
        }
    }
}

// Form validation
document.getElementById('settingForm').addEventListener('submit', function(e) {
    const settingName = document.getElementById('setting_name').value.trim();
    const settingValue = document.getElementById('setting_value').value.trim();
    
    if (!settingName || !settingValue) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    <?php if ($setting['dcmt_setting_type'] === 'select'): ?>
    const options = document.getElementById('setting_options').value.trim();
    if (!options) {
        e.preventDefault();
        alert('Select options are required for select type settings.');
        return false;
    }
    
    try {
        JSON.parse(options);
    } catch (error) {
        e.preventDefault();
        alert('Select options must be in valid JSON format.');
        return false;
    }
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
