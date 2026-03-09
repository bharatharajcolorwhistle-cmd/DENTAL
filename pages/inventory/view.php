<?php
/**
 * View Inventory Item Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check authentication
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Get inventory ID from URL
$inventory_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($inventory_id <= 0) {
    dcmt_show_message(trans('inventory', 'invalid_inventory_id'), "error");
    dcmt_redirect("index.php");
}

// Fetch inventory item details
try {
    $sql = "SELECT i.*, c.dcmt_name as category_name 
            FROM dcmt_inventory i 
            LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id 
            WHERE i.dcmt_id = ?";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$inventory_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$item) {
        dcmt_show_message(trans('inventory', 'inventory_not_found'), "error");
        dcmt_redirect("index.php");
    }
} catch (PDOException $e) {
    dcmt_show_message(trans('inventory', 'database_error'), "error");
    dcmt_redirect("index.php");
}

// Calculate additional values
$total_value = $item['dcmt_quantity'] * $item['dcmt_price'];

// Calculate stock status
$stock_status = '';
$stock_class = '';

if ($item['dcmt_quantity'] == 0) {
    $stock_status = trans('inventory', 'out_of_stock');
    $stock_class = 'text-danger';
} elseif ($item['dcmt_quantity'] <= $item['dcmt_min_quantity']) {
    $stock_status = trans('inventory', 'low_stock');
    $stock_class = 'text-warning';
} else {
    $stock_status = trans('inventory', 'in_stock');
    $stock_class = 'text-success';
}

// Log activity
dcmt_log_activity("Viewed inventory item: " . $item['dcmt_name'], "inventory_viewed");

// Get edit history from activity log
$edit_history = [];
try {
    $stmt = $dcmt_pdo->prepare("
        SELECT al.*, u.dcmt_full_name as user_name
        FROM dcmt_activity_log al
        LEFT JOIN dcmt_users u ON al.dcmt_user = u.dcmt_username
        WHERE al.dcmt_details LIKE ? OR (al.dcmt_activity LIKE ? AND al.dcmt_details LIKE ?)
        ORDER BY al.dcmt_created_at DESC
        LIMIT 20
    ");
    $search_pattern = "%Inventory ID: $inventory_id%";
    $activity_pattern = "%Inventory%";
    $inventory_pattern = "%Inventory ID: $inventory_id%";
    $stmt->execute([$search_pattern, $activity_pattern, $inventory_pattern]);
    $edit_history = $stmt->fetchAll();
} catch (PDOException $e) {
    error_log("Failed to fetch edit history: " . $e->getMessage());
}

// Now include the header
require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-box dcmt-view-card-title-icon"></i><?php echo trans('inventory', 'item_details'); ?>
                </h6>
                <div class="dcmt-view-header-links">
                    <a href="edit.php?id=<?php echo $item['dcmt_id']; ?>" class="dcmt-add-form-view-all-link me-3">
                        <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                    </a>
                    <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('inventory', 'back_to_inventory'); ?></a>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'item_name'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars($item['dcmt_name']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'sku'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <code style="background: #f8f9fa; padding: 4px 8px; border-radius: 4px; font-size: 14px;"><?php echo htmlspecialchars($item['dcmt_sku']); ?></code>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'category'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <span>
                                    <?php 
                                    // Translate category name
                                    $category_name = $item['category_name'] ?? '';
                                    if (!empty($category_name)) {
                                        $translated_category = trans('inventory_category', $category_name);
                                        echo htmlspecialchars($translated_category !== $category_name ? $translated_category : $category_name);
                                    } else {
                                        echo trans('inventory', 'no_category');
                                    }
                                    ?>
                                </span>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'status'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php
                                $status_class = match($item['dcmt_status']) {
                                    'active' => 'dcmt-view-field-value-status-completed',
                                    'inactive' => 'dcmt-view-field-value',
                                    'discontinued' => 'dcmt-view-field-value-status-failed',
                                    default => 'dcmt-view-field-value'
                                };
                                ?>
                                <span class="<?php echo $status_class; ?>">
                                    <?php echo ucfirst(htmlspecialchars($item['dcmt_status'])); ?>
                                </span>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <!-- Empty column for balance -->
                    </div>
                    <div class="col-md-4">
                        <!-- Empty column for balance -->
                    </div>
                </div>
                
                <?php if (!empty($item['dcmt_description'])): ?>
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('inventory', 'description'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($item['dcmt_description'])); ?></div>
                    </div>
                <?php endif; ?>
                
                <?php if (!empty($item['dcmt_supplier'])): ?>
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('inventory', 'supplier'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($item['dcmt_supplier']); ?></div>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Stock Information Card -->
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-chart-line dcmt-view-card-title-icon"></i><?php echo trans('inventory', 'stock_information'); ?>
                </h6>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'current_quantity'); ?>:</span>
                            <div class="dcmt-view-field-value" style="color: <?php echo $stock_class === 'text-danger' ? '#dc3545' : ($stock_class === 'text-warning' ? '#ffc107' : '#28a745'); ?>;">
                                <?php echo number_format($item['dcmt_quantity']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'minimum_quantity'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo number_format($item['dcmt_min_quantity']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'stock_status'); ?>:</span>
                            <div class="dcmt-view-field-value <?php echo $stock_class; ?>">
                                <?php echo $stock_status; ?>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'unit_price'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo dcmt_format_currency($item['dcmt_price']); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'total_value'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo dcmt_format_currency($total_value); ?>
                            </div>
                        </div>
                    </div>
                    <?php if (!empty($item['dcmt_expiry_date'])): ?>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('inventory', 'expiry_date'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php 
                                $expiry_date = new DateTime($item['dcmt_expiry_date']);
                                $today = new DateTime();
                                $diff = $today->diff($expiry_date);
                                $days_until_expiry = $diff->days;
                                
                                if ($expiry_date < $today) {
                                    echo '<span class="text-danger">' . $expiry_date->format('M d, Y') . ' <small>(' . trans('inventory', 'expired_label') . ')</small></span>';
                                } elseif ($days_until_expiry <= 7) {
                                    echo '<span class="text-warning">' . $expiry_date->format('M d, Y') . ' <small>(' . $days_until_expiry . ' ' . trans('inventory', 'days_left') . ')</small></span>';
                                } else {
                                    echo $expiry_date->format('M d, Y');
                                }
                                ?>
                            </div>
                        </div>
                    </div>
                    <?php else: ?>
                    <div class="col-md-4">
                        <!-- Empty column for balance when no expiry date -->
                    </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>
        
        
        <!-- Edit History Section -->
        <div class="card mt-4 dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title">
                    <i class="fas fa-history dcmt-view-card-title-icon"></i><?php echo trans('common', 'audit_trail') ?: 'Audit Trail'; ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($edit_history)): ?>
                <div class="timeline">
                    <?php foreach ($edit_history as $index => $entry): ?>
                    <div class="timeline-item <?php echo $index === 0 ? 'timeline-item-first' : ''; ?>">
                        <div class="timeline-marker">
                            <?php
                            $icon = 'info';
                            if (stripos($entry['dcmt_activity'], 'added') !== false) {
                                $icon = 'plus';
                            } elseif (stripos($entry['dcmt_activity'], 'updated') !== false) {
                                $icon = 'edit';
                            } elseif (stripos($entry['dcmt_activity'], 'deleted') !== false) {
                                $icon = 'trash';
                            }
                            ?>
                            <i class="fas fa-<?php echo $icon; ?>"></i>
                        </div>
                        <div class="timeline-content">
                            <div class="d-flex justify-content-between align-items-start">
                                <div>
                                    <h6 class="mb-1"><?php echo htmlspecialchars($entry['dcmt_activity']); ?></h6>
                                    <?php if (!empty($entry['dcmt_details'])): ?>
                                        <?php
                                        // Format details for better readability
                                        $details = $entry['dcmt_details'];
                                        // Remove "Inventory ID: X" if present to keep it clean
                                        // Handle various separators (- |)
                                        $details = preg_replace('/Inventory ID: \d+\s*(?:-|\|)?\s*/', '', $details);
                                        $details = trim($details, ' |');
                                        // Format "Field: Old -> New" on new lines
                                        $details_html = str_replace(' | ', '<br>', htmlspecialchars($details));
                                        ?>
                                        <p class="text-muted mb-1 small"><?php echo $details_html; ?></p>
                                    <?php endif; ?>
                                </div>
                                <div class="text-end">
                                    <small class="text-muted">
                                        <i class="fas fa-user me-1"></i>
                                        <?php echo htmlspecialchars($entry['user_name'] ?: $entry['dcmt_user']); ?>
                                    </small>
                                    <br>
                                    <small class="text-muted">
                                        <i class="fas fa-clock me-1"></i>
                                        <?php echo dcmt_format_date($entry['dcmt_created_at'], 'M j, Y g:i A'); ?>
                                    </small>
                                </div>
                            </div>
                        </div>
                    </div>
                    <?php endforeach; ?>
                </div>
                <?php else: ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-info-circle fa-2x mb-3"></i>
                    <p class="mb-0"><?php echo trans('inventory', 'no_edit_history_found') ?: 'No edit history found.'; ?></p>
                </div>
                <?php endif; ?>
            </div>
        </div>
        
        <!-- Usage Information Card (shown when deletion is not allowed) -->
        <?php if (isset($_SESSION['inventory_usage_details']) && !empty($_SESSION['inventory_usage_details'])): ?>
            <div class="card mt-4 dcmt-records-table border-info">
                <div class="card-header dcmt-view-card-header bg-info text-white">
                    <h6 class="dcmt-view-card-title">
                        <i class="fas fa-info-circle dcmt-view-card-title-icon"></i><?php echo trans('inventory', 'usage_information'); ?>
                    </h6>
                </div>
                <div class="card-body">
                    <div class="alert alert-info mb-3">
                        <i class="fas fa-shield-alt"></i>
                        <strong><?php echo trans('inventory', 'item_cannot_be_deleted'); ?></strong><br>
                        <?php echo trans('inventory', 'currently_used_in'); ?> <?php echo count($_SESSION['inventory_usage_details']); ?> <?php echo trans('inventory', 'income_records'); ?>.
                    </div>
                    
                    <h6 class="dcmt-view-table-title">
                        <i class="fas fa-list dcmt-view-table-title-icon"></i><?php echo trans('inventory', 'income_records_using_item'); ?>
                    </h6>
                    <div class="table-responsive">
                        <table class="table dcmt-view-table">
                            <thead>
                                <tr>
                                    <th><?php echo trans('common', 'date'); ?></th>
                                    <th><?php echo trans('common', 'type'); ?></th>
                                    <th><?php echo trans('common', 'amount'); ?></th>
                                    <th><?php echo trans('inventory', 'description'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($_SESSION['inventory_usage_details'] as $usage): ?>
                                    <tr>
                                        <td><?php echo dcmt_format_date($usage['dcmt_date']); ?></td>
                                        <td>
                                            <span class="badge bg-secondary">
                                                <?php echo htmlspecialchars(ucfirst($usage['dcmt_type'])); ?>
                                            </span>
                                        </td>
                                        <td class="fw-bold text-success">
                                            <?php echo dcmt_format_currency($usage['dcmt_amount']); ?>
                                        </td>
                                        <td><?php echo htmlspecialchars($usage['dcmt_description']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                    
                    <div class="mt-3">
                        <small class="text-muted">
                            <i class="fas fa-lightbulb"></i>
                            <strong><?php echo trans('inventory', 'note'); ?>:</strong> <?php echo trans('inventory', 'delete_note_message'); ?>
                        </small>
                    </div>
                </div>
            </div>
            <?php 
            // Clear the usage details from session after displaying
            unset($_SESSION['inventory_usage_details']); 
            ?>
        <?php endif; ?>
    </div>
</div>

<script>
// Pass translations to JavaScript
window.translations = {
    confirm_deletion: '<?php echo trans('inventory', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('inventory', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('inventory', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('inventory', 'cancel'); ?>',
    yes_delete: '<?php echo trans('inventory', 'yes_delete'); ?>'
};

function confirmDelete(itemId) {
    if (confirm('<?php echo trans('inventory', 'confirm_delete_message'); ?>')) {
        window.location.href = 'delete.php?id=' + itemId;
    }
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
