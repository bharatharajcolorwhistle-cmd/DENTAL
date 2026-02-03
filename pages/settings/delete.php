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
    
    // Check if this is a critical system setting
    $critical_settings = [
        'app_name',
        'app_url',
        'database_host',
        'database_name',
        'database_username',
        'database_password',
        'upload_path',
        'debug_mode',
        'session_timeout',
        'csrf_token_expiry'
    ];
    
    $is_critical = in_array($setting['dcmt_setting_key'], $critical_settings);
    
} catch (PDOException $e) {
    dcmt_show_message("Database error: " . $e->getMessage(), "error");
    dcmt_redirect("index.php");
}

// Handle deletion
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        dcmt_show_message("Invalid request. Please try again.", "error");
        dcmt_redirect("delete.php?id=$setting_id");
    }
    
    // Check confirmation
    if (!isset($_POST['confirm_delete']) || $_POST['confirm_delete'] !== 'yes') {
        dcmt_show_message("Deletion not confirmed.", "error");
        dcmt_redirect("delete.php?id=$setting_id");
    }
    
    // Prevent deletion of critical settings
    if ($is_critical) {
        dcmt_show_message("Cannot delete critical system settings.", "error");
        dcmt_redirect("delete.php?id=$setting_id");
    }
    
    try {
        // Start transaction
        $dcmt_pdo->beginTransaction();
        
        // Delete setting
        $stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_settings WHERE dcmt_id = ?");
        $stmt->execute([$setting_id]);
        
        if ($stmt->rowCount() > 0) {
            // Log activity
            dcmt_log_activity("System setting deleted: {$setting['dcmt_setting_key']} ({$setting['dcmt_setting_name']})", "setting_deleted");
            
            // Commit transaction
            $dcmt_pdo->commit();
            
            dcmt_show_message(trans('settings', 'setting_deleted_success'), "success");
            dcmt_redirect("index.php");
        } else {
            throw new Exception("Setting not found or already deleted.");
        }
        
    } catch (Exception $e) {
        // Rollback transaction
        $dcmt_pdo->rollBack();
        dcmt_show_message("Error deleting setting: " . $e->getMessage(), "error");
        dcmt_redirect("delete.php?id=$setting_id");
    }
}

$csrf_token = dcmt_generate_csrf_token();
?>

<div class="d-flex justify-content-between align-items-center mb-4">
    <h1>Delete System Setting</h1>
    <a href="index.php" class="btn btn-secondary">
        <i class="fas fa-arrow-left"></i> Back to Settings
    </a>
</div>

<div class="row">
    <div class="col-md-8">
        <!-- Warning Card -->
        <div class="card border-danger mb-4">
            <div class="card-header bg-danger text-white">
                <h5 class="card-title mb-0">
                    <i class="fas fa-exclamation-triangle"></i> Warning: Setting Deletion
                </h5>
            </div>
            <div class="card-body">
                <div class="alert alert-danger">
                    <h6><i class="fas fa-exclamation-triangle"></i> This action cannot be undone!</h6>
                    <p class="mb-0">Deleting this setting will permanently remove it from the system configuration.</p>
                </div>
                
                <?php if ($is_critical): ?>
                    <div class="alert alert-warning">
                        <h6><i class="fas fa-shield-alt"></i> Critical System Setting</h6>
                        <p class="mb-0">This is a critical system setting that cannot be deleted. It is essential for the proper functioning of the application.</p>
                    </div>
                <?php endif; ?>
                
                <h6>What will be deleted:</h6>
                <ul>
                    <li>Setting configuration</li>
                    <li>Setting value and metadata</li>
                    <li>All validation rules and constraints</li>
                </ul>
                
                <h6>What will NOT be deleted:</h6>
                <ul>
                    <li>System audit trail (these records will remain for compliance)</li>
                    <li>Other related settings</li>
                </ul>
            </div>
        </div>
        
        <!-- Setting Details Card -->
        <div class="card mb-4">
            <div class="card-header">
                <h5 class="card-title mb-0">
                    <i class="fas fa-cog"></i> Setting Information
                </h5>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold">Setting Key:</td>
                                <td>
                                    <code><?php echo htmlspecialchars($setting['dcmt_setting_key']); ?></code>
                                    <?php if ($is_critical): ?>
                                        <span class="badge bg-warning ms-2">Critical</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Setting Name:</td>
                                <td><?php echo htmlspecialchars($setting['dcmt_setting_name']); ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Setting Type:</td>
                                <td>
                                    <span class="badge bg-info">
                                        <?php echo ucfirst($setting['dcmt_setting_type']); ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Category:</td>
                                <td><?php echo htmlspecialchars($setting['dcmt_category'] ?? 'General'); ?></td>
                            </tr>
                        </table>
                    </div>
                    <div class="col-md-6">
                        <table class="table table-borderless">
                            <tr>
                                <td class="fw-bold">Required:</td>
                                <td>
                                    <span class="badge bg-<?php echo $setting['dcmt_required'] ? 'success' : 'secondary'; ?>">
                                        <?php echo $setting['dcmt_required'] ? 'Yes' : 'No'; ?>
                                    </span>
                                </td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Created:</td>
                                <td><?php echo dcmt_format_date($setting['dcmt_created_at']); ?></td>
                            </tr>
                            <tr>
                                <td class="fw-bold">Created By:</td>
                                <td><?php echo htmlspecialchars($setting['dcmt_created_by']); ?></td>
                            </tr>
                            <?php if ($setting['dcmt_updated_at']): ?>
                                <tr>
                                    <td class="fw-bold">Last Updated:</td>
                                    <td><?php echo dcmt_format_date($setting['dcmt_updated_at']); ?></td>
                                </tr>
                            <?php endif; ?>
                        </table>
                    </div>
                </div>
                
                <div class="mt-3">
                    <strong>Current Value:</strong>
                    <div class="mt-2">
                        <?php if ($setting['dcmt_setting_type'] === 'textarea'): ?>
                            <textarea class="form-control" rows="3" readonly><?php echo htmlspecialchars($setting['dcmt_setting_value']); ?></textarea>
                        <?php elseif ($setting['dcmt_setting_type'] === 'checkbox'): ?>
                            <span class="badge bg-<?php echo $setting['dcmt_setting_value'] == '1' ? 'success' : 'secondary'; ?>">
                                <?php echo $setting['dcmt_setting_value'] == '1' ? 'Enabled' : 'Disabled'; ?>
                            </span>
                        <?php else: ?>
                            <code class="fs-6"><?php echo htmlspecialchars($setting['dcmt_setting_value']); ?></code>
                        <?php endif; ?>
                    </div>
                </div>
                
                <?php if ($setting['dcmt_setting_description']): ?>
                    <div class="mt-3">
                        <strong>Description:</strong>
                        <p class="mb-0"><?php echo htmlspecialchars($setting['dcmt_setting_description']); ?></p>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
    
    <div class="col-md-4">
        <!-- Confirmation Form Card -->
        <div class="card border-warning">
            <div class="card-header bg-warning text-dark">
                <h6 class="card-title mb-0">
                    <i class="fas fa-question-circle"></i> Confirm Deletion
                </h6>
            </div>
            <div class="card-body">
                <?php if ($is_critical): ?>
                    <div class="alert alert-warning">
                        <i class="fas fa-shield-alt"></i>
                        <strong>Critical Setting:</strong> This setting cannot be deleted as it is essential for system operation.
                    </div>
                    <div class="d-grid">
                        <a href="index.php" class="btn btn-secondary">
                            <i class="fas fa-arrow-left"></i> Back to Settings
                        </a>
                    </div>
                <?php else: ?>
                    <form method="POST" action="" id="deleteForm">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        
                        <div class="mb-3">
                            <label class="form-label fw-bold">Are you sure you want to delete this setting?</label>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="confirm_delete" id="confirm_yes" value="yes" required>
                                <label class="form-check-label" for="confirm_yes">
                                    Yes, I want to delete this setting
                                </label>
                            </div>
                            <div class="form-check">
                                <input class="form-check-input" type="radio" name="confirm_delete" id="confirm_no" value="no" required>
                                <label class="form-check-label" for="confirm_no">
                                    No, I want to cancel
                                </label>
                            </div>
                        </div>
                        
                        <div class="d-grid gap-2">
                            <button type="submit" class="btn btn-danger" id="deleteBtn" disabled>
                                <i class="fas fa-trash"></i> Delete Setting
                            </button>
                            <a href="index.php" class="btn btn-secondary">
                                <i class="fas fa-times"></i> Cancel
                            </a>
                        </div>
                    </form>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Additional Information Card -->
        <div class="card mt-4">
            <div class="card-header">
                <h6 class="card-title mb-0">
                    <i class="fas fa-info-circle"></i> Additional Information
                </h6>
            </div>
            <div class="card-body">
                <p><strong>Created:</strong> <?php echo dcmt_format_date($setting['dcmt_created_at']); ?></p>
                <?php if ($setting['dcmt_updated_at']): ?>
                    <p><strong>Last Updated:</strong> <?php echo dcmt_format_date($setting['dcmt_updated_at']); ?></p>
                <?php endif; ?>
                <p><strong>Created By:</strong> <?php echo htmlspecialchars($setting['dcmt_created_by']); ?></p>
                
                <?php if ($setting['dcmt_max_length']): ?>
                    <p><strong>Max Length:</strong> <?php echo $setting['dcmt_max_length']; ?></p>
                <?php endif; ?>
                
                <?php if ($setting['dcmt_min_value']): ?>
                    <p><strong>Min Value:</strong> <?php echo $setting['dcmt_min_value']; ?></p>
                <?php endif; ?>
                
                <?php if ($setting['dcmt_max_value']): ?>
                    <p><strong>Max Value:</strong> <?php echo $setting['dcmt_max_value']; ?></p>
                <?php endif; ?>
                
                <?php if ($setting['dcmt_step_value']): ?>
                    <p><strong>Step Value:</strong> <?php echo $setting['dcmt_step_value']; ?></p>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Setting Type Information Card -->
        <div class="card mt-4 border-info">
            <div class="card-header bg-info text-white">
                <h6 class="card-title mb-0">
                    <i class="fas fa-cog"></i> Setting Type Details
                </h6>
            </div>
            <div class="card-body">
                <p><strong>Type:</strong> <?php echo ucfirst($setting['dcmt_setting_type']); ?></p>
                <p><strong>Required:</strong> <?php echo $setting['dcmt_required'] ? 'Yes' : 'No'; ?></p>
                <p><strong>Category:</strong> <?php echo htmlspecialchars($setting['dcmt_category'] ?? 'General'); ?></p>
                
                <?php if ($setting['dcmt_setting_type'] === 'select' && $setting['dcmt_setting_options']): ?>
                    <hr>
                    <p><strong>Available Options:</strong></p>
                    <?php
                    $options = json_decode($setting['dcmt_setting_options'], true);
                    if ($options && is_array($options)):
                    ?>
                        <ul class="list-unstyled">
                            <?php foreach ($options as $value => $label): ?>
                                <li><code><?php echo htmlspecialchars($value); ?></code> → <?php echo htmlspecialchars($label); ?></li>
                            <?php endforeach; ?>
                        </ul>
                    <?php endif; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<?php if (!$is_critical): ?>
<script>
// Enable delete button only when "Yes" is selected
document.querySelectorAll('input[name="confirm_delete"]').forEach(function(radio) {
    radio.addEventListener('change', function() {
        const deleteBtn = document.getElementById('deleteBtn');
        if (this.value === 'yes') {
            deleteBtn.disabled = false;
        } else {
            deleteBtn.disabled = true;
        }
    });
});

// Form validation
document.getElementById('deleteForm').addEventListener('submit', function(e) {
    const confirmDelete = document.querySelector('input[name="confirm_delete"]:checked');
    
    if (!confirmDelete) {
        e.preventDefault();
        alert('Please select a confirmation option.');
        return false;
    }
    
    if (confirmDelete.value === 'yes') {
        return confirm('Are you absolutely sure you want to delete this setting? This action cannot be undone!');
    }
    
    return true;
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
