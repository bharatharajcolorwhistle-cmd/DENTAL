<?php
/**
 * Manage User Services Page
 * Dental Clinic Management System
 * 
 * This page allows managing services for doctor role users
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check admin access
dcmt_require_admin_or_doctor();

// Get user ID from URL
$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($user_id <= 0) {
    dcmt_show_message(trans('user', 'invalid_user_id'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Get search parameter
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

$errors = [];

// Fetch user details
try {
    $sql = "SELECT * FROM dcmt_users WHERE dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$user_id]);
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$user) {
        dcmt_show_message(trans('user', 'user_not_found'), "error");
        dcmt_redirect("index.php");
        exit();
    }
    
    // Check if user has doctor role
    if ($user['dcmt_role'] !== 'doctor') {
        dcmt_show_message(trans('user', 'user_not_doctor'), "error");
        dcmt_redirect("view.php?id=" . $user_id);
        exit();
    }
    
    // Use user_id directly - no need to find/create doctor record
    
} catch (PDOException $e) {
    dcmt_show_message(trans('user', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Fetch all active services with search filter
try {
    $services_sql = "SELECT * FROM dcmt_services WHERE dcmt_status = 'active'";
    $params = [];
    
    if (!empty($search)) {
        $services_sql .= " AND (dcmt_name LIKE ? OR dcmt_description LIKE ?)";
        $params[] = "%$search%";
        $params[] = "%$search%";
    }
    
    $services_sql .= " ORDER BY dcmt_name";
    
    $services_stmt = $dcmt_pdo->prepare($services_sql);
    $services_stmt->execute($params);
    $all_services = $services_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $all_services = [];
    error_log("Error fetching services: " . $e->getMessage());
}

// Fetch currently assigned services for this user (doctor role)
try {
    $assigned_sql = "SELECT dcmt_service_id, dcmt_price, dcmt_status FROM dcmt_doctor_services WHERE dcmt_user_id = ?";
    $assigned_stmt = $dcmt_pdo->prepare($assigned_sql);
    $assigned_stmt->execute([$user_id]);
    $assigned_services = $assigned_stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Create a map for easy lookup
    $assigned_map = [];
    foreach ($assigned_services as $assigned) {
        $assigned_map[$assigned['dcmt_service_id']] = [
            'price' => $assigned['dcmt_price'],
            'status' => $assigned['dcmt_status']
        ];
    }
} catch (PDOException $e) {
    $assigned_map = [];
    error_log("Error fetching assigned services: " . $e->getMessage());
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        try {
            $dcmt_pdo->beginTransaction();
            
            // Get selected services and prices
            $selected_services = $_POST['services'] ?? [];
            $service_prices = $_POST['service_prices'] ?? [];
            
            // First, delete all existing assignments for this user
            $delete_stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_doctor_services WHERE dcmt_user_id = ?");
            $delete_stmt->execute([$user_id]);
            
            // Insert new assignments
            $insert_stmt = $dcmt_pdo->prepare("
                INSERT INTO dcmt_doctor_services (dcmt_user_id, dcmt_service_id, dcmt_price, dcmt_status, dcmt_created_by) 
                VALUES (?, ?, ?, 'active', ?)
            ");
            
            $assigned_count = 0;
            foreach ($selected_services as $service_id) {
                $service_id = (int)$service_id;
                $price = isset($service_prices[$service_id]) ? floatval($service_prices[$service_id]) : 0;
                
                if ($price >= 0) {
                    $insert_stmt->execute([
                        $user_id,
                        $service_id,
                        $price,
                        dcmt_get_current_user()['dcmt_username']
                    ]);
                    $assigned_count++;
                }
            }
            
            $dcmt_pdo->commit();
            
            // Log activity
            dcmt_log_activity("Updated services for user: " . $user['dcmt_full_name'] . " (Assigned: $assigned_count services)", "user_services_updated");
            
            // Set success message and redirect
            dcmt_show_message(trans('service', 'services_updated_success'), "success");
            dcmt_redirect("view.php?id=" . $user_id);
            exit();
            
        } catch (PDOException $e) {
            $dcmt_pdo->rollBack();
            $errors[] = trans('service', 'services_updated_failed');
            dcmt_log_activity("Error updating user services: " . $e->getMessage(), "error");
        }
    }
}

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<!-- Search and Filter Form -->
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end" id="searchForm">
            <input type="hidden" name="id" value="<?php echo $user_id; ?>">
            <div class="col-md-10">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control" id="search" name="search" 
                        value="<?php echo htmlspecialchars($search); ?>" 
                        placeholder="<?php echo trans('service', 'search_placeholder'); ?>"
                        onkeypress="handleSearchKeypress(event)">
            </div>
            <div class="col-md-2 d-flex flex-column gap-2 align-items-center">
                <button type="submit" class="dcmt-filter-btn" id="searchBtn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="manage_services.php?id=<?php echo $user_id; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
        
    </div>
</div>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('service', 'manage_services'); ?> - <?php echo htmlspecialchars($user['dcmt_full_name']); ?></h1>
            <a href="view.php?id=<?php echo $user_id; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('common', 'back'); ?></a>
        </div>
    </div>
    

    <!-- Services List -->
    <div class="card dcmt-records-table">
        <div class="">
            <form method="POST" action="" id="servicesForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        
        <?php if (empty($all_services)): ?>
            <div class="alert alert-warning">
                <i class="fas fa-exclamation-triangle me-2"></i>
                <?php echo trans('service', 'no_services_found'); ?>. 
                <a href="../services/add.php"><?php echo trans('service', 'add_service'); ?></a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th style="width: 50px;">
                                <input type="checkbox" id="selectAll" onclick="toggleAllServices(this)">
                            </th>
                            <th><?php echo trans('service', 'service_name'); ?></th>
                            <th><?php echo trans('service', 'base_price'); ?></th>
                            <th><?php echo trans('service', 'custom_price'); ?> *</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($all_services as $service): 
                            $is_assigned = isset($assigned_map[$service['dcmt_id']]);
                            $current_price = $is_assigned ? $assigned_map[$service['dcmt_id']]['price'] : $service['dcmt_base_price'];
                        ?>
                            <tr>
                                <td>
                                    <input type="checkbox" 
                                           name="services[]" 
                                           value="<?php echo $service['dcmt_id']; ?>" 
                                           class="service-checkbox"
                                           <?php echo $is_assigned ? 'checked' : ''; ?>
                                           onchange="togglePriceField(this)">
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($service['dcmt_name']); ?></strong>
                                    <?php if (!empty($service['dcmt_description'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($service['dcmt_description']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-muted"><?php echo dcmt_format_currency($service['dcmt_base_price']); ?></span>
                                </td>
                                <td>
                                    <div class="dcmt-amount-input-wrapper">
                                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                        <input type="number" 
                                               class="form-control dcmt-amount-input price-field" 
                                               name="service_prices[<?php echo $service['dcmt_id']; ?>]" 
                                               value="<?php echo htmlspecialchars($current_price); ?>" 
                                               step="0.01" 
                                               min="0" 
                                               placeholder="0.00"
                                               <?php echo !$is_assigned ? 'disabled' : ''; ?>
                                               id="price_<?php echo $service['dcmt_id']; ?>">
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            
            
            <div class="dcmt-form-actions">
                <a href="view.php?id=<?php echo $user_id; ?>" class="btn dcmt-btn-cancel">
                    <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
                </a>
                <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                    <i class="fas fa-save"></i><?php echo trans('service', 'save_services'); ?>
                </button>
            </div>
        <?php endif; ?>
            </form>
        </div>
    </div>
</div>

<script>
// Handle search form submission
function handleSearchKeypress(event) {
    if (event.key === 'Enter') {
        event.preventDefault();
        document.getElementById('searchForm').submit();
    }
}

function toggleAllServices(checkbox) {
    const checkboxes = document.querySelectorAll('.service-checkbox');
    checkboxes.forEach(cb => {
        cb.checked = checkbox.checked;
        togglePriceField(cb);
    });
}

function togglePriceField(checkbox) {
    const row = checkbox.closest('tr');
    const priceField = row.querySelector('.price-field');
    
    if (checkbox.checked) {
        priceField.disabled = false;
        priceField.focus();
    } else {
        priceField.disabled = true;
    }
}

// Initialize everything when DOM is loaded
document.addEventListener('DOMContentLoaded', function() {
    // Initialize form change detection
    if (typeof dcmtInitFormChangeDetection === 'function') {
        dcmtInitFormChangeDetection('servicesForm');
    }
    
    const form = document.getElementById('servicesForm');
    const submitBtn = document.getElementById('submitBtn');
    
    if (!form || !submitBtn) {
        return;
    }
    
    // Form validation
    form.addEventListener('submit', function(e) {
        // Clear form changed flag since we're submitting
        if (typeof dcmtClearFormChanged === 'function') {
            dcmtClearFormChanged();
        }
        
        // Check if at least one service is selected
        const selectedServices = document.querySelectorAll('.service-checkbox:checked');
        
        if (selectedServices.length === 0) {
            e.preventDefault();
            alert('<?php echo trans('service', 'no_services_assigned'); ?>');
            return false;
        }
        
        // Validate that all selected services have valid prices
        let hasInvalidPrice = false;
        selectedServices.forEach(checkbox => {
            const serviceId = checkbox.value;
            const priceField = document.getElementById('price_' + serviceId);
            const price = parseFloat(priceField.value);
            
            if (isNaN(price) || price < 0) {
                hasInvalidPrice = true;
            }
        });
        
        if (hasInvalidPrice) {
            e.preventDefault();
            alert('<?php echo trans('service', 'price_negative'); ?>');
            return false;
        }
        
        // Show loading state
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
        submitBtn.disabled = true;
        
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

