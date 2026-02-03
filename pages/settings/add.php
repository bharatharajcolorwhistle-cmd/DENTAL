<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$errors = [];
$form_data = [
    'setting_key' => '',
    'setting_name' => '',
    'setting_value' => '',
    'setting_type' => 'text',
    'category' => 'General',
    'required' => 0,
    'setting_description' => '',
    'max_length' => '',
    'min_value' => '',
    'max_value' => '',
    'step_value' => '1',
    'setting_options' => ''
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        dcmt_show_message("Invalid request. Please try again.", "error");
        dcmt_redirect("add.php");
    }
    
    // Get form data
    $form_data = [
        'setting_key' => dcmt_sanitize_input($_POST['setting_key']),
        'setting_name' => dcmt_sanitize_input($_POST['setting_name']),
        'setting_value' => dcmt_sanitize_input($_POST['setting_value']),
        'setting_type' => dcmt_sanitize_input($_POST['setting_type']),
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
    $required_fields = ['setting_key', 'setting_name', 'setting_value', 'setting_type'];
    $validation_result = dcmt_validate_required_fields($form_data, $required_fields);
    if (!$validation_result['valid']) {
        $errors = $validation_result['errors'];
    }
    
    // Validate setting key format (alphanumeric and underscores only)
    if (empty($errors) && !preg_match('/^[a-zA-Z0-9_]+$/', $form_data['setting_key'])) {
        $errors[] = "Setting key can only contain letters, numbers, and underscores.";
    }
    
    // Validate setting key length
    if (empty($errors) && strlen($form_data['setting_key']) > 50) {
        $errors[] = "Setting key cannot exceed 50 characters.";
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
    if (empty($errors) && $form_data['setting_type'] === 'select') {
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
    
    // Check if setting key already exists
    if (empty($errors)) {
        try {
            $stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_settings WHERE dcmt_setting_key = ?");
            $stmt->execute([$form_data['setting_key']]);
            if ($stmt->fetchColumn() > 0) {
                $errors[] = "Setting key already exists. Please choose a different key.";
            }
        } catch (PDOException $e) {
            $errors[] = "Database error occurred. Please try again.";
        }
    }
    
    // If no errors, insert setting
    if (empty($errors)) {
        try {
            $sql = "INSERT INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_type, dcmt_category, dcmt_required, dcmt_setting_description, dcmt_max_length, dcmt_min_value, dcmt_max_value, dcmt_step_value, dcmt_setting_options, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())";
            $stmt = $dcmt_pdo->prepare($sql);
            $stmt->execute([
                $form_data['setting_key'],
                $form_data['setting_name'],
                $form_data['setting_value'],
                $form_data['setting_type'],
                $form_data['category'],
                $form_data['required'],
                $form_data['setting_description'],
                $form_data['max_length'] ?: null,
                $form_data['min_value'] ?: null,
                $form_data['max_value'] ?: null,
                $form_data['step_value'] ?: null,
                $form_data['setting_options'] ?: null,
                dcmt_get_current_user()['dcmt_username']
            ]);
            
            $setting_id = $dcmt_pdo->lastInsertId();
            
            // Log activity
            dcmt_log_activity("System setting created: {$form_data['setting_key']} ({$form_data['setting_name']})", "setting_created");
            
            dcmt_show_message(trans('settings', 'setting_created_success'), "success");
            dcmt_redirect("index.php");
            
        } catch (PDOException $e) {
            $errors[] = "Database error occurred. Please try again.";
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

// Now include the header after all potential redirects
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Add System Setting</h1>
    <a href="index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Settings
    </a>
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
                        <label for="setting_key" class="form-label">Setting Key <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="setting_key" name="setting_key" 
                               value="<?php echo htmlspecialchars($form_data['setting_key']); ?>" 
                               required maxlength="50" pattern="[a-zA-Z0-9_]+">
                        <div class="form-text">Unique identifier for the setting (letters, numbers, underscores only)</div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="setting_name" class="form-label">Setting Name <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="setting_name" name="setting_name" 
                               value="<?php echo htmlspecialchars($form_data['setting_name']); ?>" 
                               required maxlength="100">
                        <div class="form-text">Human-readable name for the setting</div>
                    </div>
                </div>
            </div>
            
            <div class="row">
                <div class="col-md-6">
                    <div class="mb-3">
                        <label for="setting_type" class="form-label">Setting Type <span class="text-danger">*</span></label>
                        <select class="form-select" id="setting_type" name="setting_type" required>
                            <option value="text" <?php echo $form_data['setting_type'] === 'text' ? 'selected' : ''; ?>>Text</option>
                            <option value="textarea" <?php echo $form_data['setting_type'] === 'textarea' ? 'selected' : ''; ?>>Text Area</option>
                            <option value="number" <?php echo $form_data['setting_type'] === 'number' ? 'selected' : ''; ?>>Number</option>
                            <option value="email" <?php echo $form_data['setting_type'] === 'email' ? 'selected' : ''; ?>>Email</option>
                            <option value="url" <?php echo $form_data['setting_type'] === 'url' ? 'selected' : ''; ?>>URL</option>
                            <option value="select" <?php echo $form_data['setting_type'] === 'select' ? 'selected' : ''; ?>>Select Dropdown</option>
                            <option value="checkbox" <?php echo $form_data['setting_type'] === 'checkbox' ? 'selected' : ''; ?>>Checkbox</option>
                        </select>
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
                        <label for="setting_value" class="form-label">Default Value <span class="text-danger">*</span></label>
                        <input type="text" class="form-control" id="setting_value" name="setting_value" 
                               value="<?php echo htmlspecialchars($form_data['setting_value']); ?>" 
                               required maxlength="500">
                        <div class="form-text">Default value for the setting</div>
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
            <div id="textOptions" class="type-options" style="display: none;">
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
            </div>
            
            <div id="numberOptions" class="type-options" style="display: none;">
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
            </div>
            
            <div id="selectOptions" class="type-options" style="display: none;">
                <div class="mb-3">
                    <label for="setting_options" class="form-label">Select Options <span class="text-danger">*</span></label>
                    <textarea class="form-control" id="setting_options" name="setting_options" 
                              rows="5" maxlength="1000"><?php echo htmlspecialchars($form_data['setting_options']); ?></textarea>
                    <div class="form-text">JSON format: {"value1":"Label 1","value2":"Label 2"}</div>
                    <div class="form-text">Example: {"enabled":"Enabled","disabled":"Disabled"}</div>
                </div>
            </div>
            
            <div class="d-flex justify-content-between">
                <button type="button" class="btn btn-secondary" onclick="resetForm()">
                    <i class="fas fa-undo"></i> Reset Form
                </button>
                <button type="submit" class="btn btn-primary">
                    <i class="fas fa-save"></i> Create Setting
                </button>
            </div>
        </form>
    </div>
</div>

<script>
function showTypeOptions() {
    const settingType = document.getElementById('setting_type').value;
    
    // Hide all type-specific options
    document.querySelectorAll('.type-options').forEach(function(option) {
        option.style.display = 'none';
    });
    
    // Show relevant options based on type
    if (['text', 'textarea', 'email', 'url'].includes(settingType)) {
        document.getElementById('textOptions').style.display = 'block';
    } else if (settingType === 'number') {
        document.getElementById('numberOptions').style.display = 'block';
    } else if (settingType === 'select') {
        document.getElementById('selectOptions').style.display = 'block';
    }
}

function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        document.getElementById('settingForm').reset();
        document.getElementById('setting_type').value = 'text';
        showTypeOptions();
    }
}

// Show/hide type-specific options when type changes
document.getElementById('setting_type').addEventListener('change', showTypeOptions);

// Show initial options
document.addEventListener('DOMContentLoaded', function() {
    showTypeOptions();
});

// Form validation
document.getElementById('settingForm').addEventListener('submit', function(e) {
    const settingKey = document.getElementById('setting_key').value.trim();
    const settingName = document.getElementById('setting_name').value.trim();
    const settingValue = document.getElementById('setting_value').value.trim();
    const settingType = document.getElementById('setting_type').value;
    
    if (!settingKey || !settingName || !settingValue) {
        e.preventDefault();
        alert('Please fill in all required fields.');
        return false;
    }
    
    if (!/^[a-zA-Z0-9_]+$/.test(settingKey)) {
        e.preventDefault();
        alert('Setting key can only contain letters, numbers, and underscores.');
        return false;
    }
    
    if (settingType === 'select') {
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
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
