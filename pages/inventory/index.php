<?php
/**
 * Inventory List Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin_or_staff();
$dcmt_can_delete = dcmt_can_delete_records();

// Get search and filter parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$category = isset($_GET['category']) ? dcmt_sanitize_input($_GET['category']) : '';
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';
$stock_level = isset($_GET['stock_level']) ? dcmt_sanitize_input($_GET['stock_level']) : '';
$expiry_filter = isset($_GET['expiry_filter']) ? dcmt_sanitize_input($_GET['expiry_filter']) : '';
$product_type = isset($_GET['product_type']) ? dcmt_sanitize_input($_GET['product_type']) : '';
$page = isset($_GET['page']) ? (int)$_GET['page'] : 1;
$per_page = DCMT_PER_PAGE;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(i.dcmt_name LIKE ? OR i.dcmt_brand LIKE ? OR i.dcmt_description LIKE ? OR i.dcmt_sku LIKE ? OR i.dcmt_supplier LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($category)) {
    $where_conditions[] = "i.dcmt_category_id = ?";
    $params[] = $category;
}

if (!empty($status)) {
    $where_conditions[] = "i.dcmt_status = ?";
    $params[] = $status;
}

if (!empty($stock_level)) {
    switch ($stock_level) {
        case 'low':
            // Low stock excludes out-of-stock rows to keep filters distinct.
            $where_conditions[] = "i.dcmt_quantity > 0 AND i.dcmt_quantity <= i.dcmt_min_quantity";
            break;
        case 'out':
            $where_conditions[] = "i.dcmt_quantity = 0";
            break;
        case 'available':
            $where_conditions[] = "i.dcmt_quantity > 0";
            break;
    }
}

if (!empty($expiry_filter)) {
    switch ($expiry_filter) {
        case 'expiring_week':
            $where_conditions[] = "i.dcmt_expiry_date IS NOT NULL AND i.dcmt_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)";
            break;
        case 'expired':
            $where_conditions[] = "i.dcmt_expiry_date IS NOT NULL AND i.dcmt_expiry_date < CURDATE()";
            break;
    }
}

if (!empty($product_type)) {
    $where_conditions[] = "c.dcmt_product_type = ?";
    $params[] = $product_type;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get total count
$count_sql = "SELECT COUNT(*) FROM dcmt_inventory i LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id $where_clause";
$count_stmt = $dcmt_pdo->prepare($count_sql);
$count_stmt->execute($params);
$total_records = $count_stmt->fetchColumn();
$total_pages = ceil($total_records / $per_page);

// Get inventory with pagination
$offset = ($page - 1) * $per_page;
$sql = "SELECT i.*, c.dcmt_name as category_name, c.dcmt_product_type 
        FROM dcmt_inventory i 
        LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id 
        $where_clause 
        ORDER BY (i.dcmt_quantity = 0) DESC, (i.dcmt_quantity <= i.dcmt_min_quantity) DESC, i.dcmt_name ASC 
        LIMIT $per_page OFFSET $offset";
$stmt = $dcmt_pdo->prepare($sql);
$stmt->execute($params);
$inventory_items = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get total inventory value for summary (only for "for sale" items)
$total_value_where_clause = $where_clause !== ''
    ? ($where_clause . " AND (c.dcmt_product_type IS NULL OR c.dcmt_product_type = 'for_sale')")
    : "WHERE (c.dcmt_product_type IS NULL OR c.dcmt_product_type = 'for_sale')";
$total_value_sql = "SELECT SUM(i.dcmt_quantity * i.dcmt_price) as total_value 
                    FROM dcmt_inventory i 
                    LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id 
                    $total_value_where_clause";
$total_value_stmt = $dcmt_pdo->prepare($total_value_sql);
$total_value_stmt->execute($params);
$total_value = $total_value_stmt->fetchColumn() ?: 0;

// Get total quantity for summary
$total_quantity_sql = "SELECT SUM(i.dcmt_quantity) as total_quantity FROM dcmt_inventory i LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id $where_clause";
$total_quantity_stmt = $dcmt_pdo->prepare($total_quantity_sql);
$total_quantity_stmt->execute($params);
$total_quantity = $total_quantity_stmt->fetchColumn() ?: 0;

// Get low stock count (exclude out-of-stock items).
$low_stock_sql = "SELECT COUNT(*) as low_stock_count FROM dcmt_inventory i WHERE i.dcmt_quantity > 0 AND i.dcmt_quantity <= i.dcmt_min_quantity";
$low_stock_stmt = $dcmt_pdo->query($low_stock_sql);
$low_stock_count = $low_stock_stmt->fetchColumn();

// Get categories for filter
$categories_sql = "SELECT c.dcmt_id, c.dcmt_name FROM dcmt_inventory_categories c WHERE c.dcmt_status = 'active' ORDER BY c.dcmt_name";
$categories_stmt = $dcmt_pdo->query($categories_sql);
$categories = $categories_stmt->fetchAll();

$inventory_used_in_income = [];
if ($dcmt_can_delete && !empty($inventory_items)) {
    $page_ids = array_map(static function ($item) {
        return (int) $item['dcmt_id'];
    }, $inventory_items);
    $usage_placeholders = implode(',', array_fill(0, count($page_ids), '?'));
    $used_sql = "SELECT DISTINCT dcmt_inventory_id
                 FROM dcmt_income_breakdown
                 WHERE dcmt_line_type = 'product' AND dcmt_inventory_id IN ($usage_placeholders)";
    $used_stmt = $dcmt_pdo->prepare($used_sql);
    $used_stmt->execute($page_ids);
    $inventory_used_in_income = array_flip(array_map('intval', $used_stmt->fetchAll(PDO::FETCH_COLUMN)));
}

// Now include the header
require_once __DIR__ . '/../../includes/header.php';

$csrf_token = dcmt_generate_csrf_token();
?>
<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
<?php

// Display delete operation messages
if (isset($_SESSION['inventory_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['inventory_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['inventory_delete_success']);
}

if (isset($_SESSION['inventory_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['inventory_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['inventory_delete_error']);
}

if (isset($_SESSION['inventory_delete_info'])) {
    echo '<div class="alert alert-info alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['inventory_delete_info']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['inventory_delete_info']);
}
?>

<!-- <?php echo trans('inventory', 'search_and_filter_form'); ?> -->
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search" 
                       value="<?php echo htmlspecialchars($search); ?>" 
                       placeholder="<?php echo trans('inventory', 'search_placeholder'); ?>">
            </div>
            <div class="col-md">
                <label for="category" class="form-label"><?php echo trans('inventory', 'category'); ?></label>
                <select class="form-select dcmt-filter-field" id="category" name="category">
                    <option value=""><?php echo trans('inventory', 'all_categories'); ?></option>
                    <?php foreach ($categories as $cat): ?>
                        <?php 
                        // Translate category name
                        $cat_name = $cat['dcmt_name'];
                        $translated_cat = trans('inventory_category', $cat_name);
                        $display_cat = ($translated_cat !== $cat_name) ? $translated_cat : $cat_name;
                        ?>
                        <option value="<?php echo htmlspecialchars($cat['dcmt_id']); ?>" 
                                <?php echo $category == $cat['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($display_cat); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label for="status" class="form-label"><?php echo trans('inventory', 'status'); ?></label>
                <select class="form-select dcmt-filter-field" id="status" name="status">
                    <option value=""><?php echo trans('inventory', 'select_status'); ?></option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                    <option value="discontinued" <?php echo $status === 'discontinued' ? 'selected' : ''; ?>><?php echo trans('inventory', 'discontinued'); ?></option>
                </select>
            </div>
            <div class="col-md">
                <label for="stock_level" class="form-label"><?php echo trans('inventory', 'stock_level'); ?></label>
                <select class="form-select dcmt-filter-field" id="stock_level" name="stock_level">
                    <option value=""><?php echo trans('inventory', 'all_levels'); ?></option>
                    <option value="low" <?php echo $stock_level === 'low' ? 'selected' : ''; ?>><?php echo trans('inventory', 'low_stock'); ?></option>
                    <option value="out" <?php echo $stock_level === 'out' ? 'selected' : ''; ?>><?php echo trans('inventory', 'out_of_stock'); ?></option>
                    <option value="available" <?php echo $stock_level === 'available' ? 'selected' : ''; ?>><?php echo trans('inventory', 'available'); ?></option>
                </select>
            </div>
            <div class="col-md">
                <label for="expiry_filter" class="form-label"><?php echo trans('inventory', 'expiry_filter'); ?></label>
                <select class="form-select dcmt-filter-field" id="expiry_filter" name="expiry_filter">
                    <option value=""><?php echo trans('inventory', 'all_items'); ?></option>
                    <option value="expiring_week" <?php echo isset($_GET['expiry_filter']) && $_GET['expiry_filter'] === 'expiring_week' ? 'selected' : ''; ?>><?php echo trans('inventory', 'expiring_this_week'); ?></option>
                    <option value="expired" <?php echo isset($_GET['expiry_filter']) && $_GET['expiry_filter'] === 'expired' ? 'selected' : ''; ?>><?php echo trans('inventory', 'expired'); ?></option>
                </select>
            </div>
            <div class="col-md">
                <label for="product_type" class="form-label"><?php echo trans('inventory_category', 'product_type'); ?></label>
                <select class="form-select dcmt-filter-field" id="product_type" name="product_type">
                    <option value=""><?php echo trans('inventory', 'all_types'); ?></option>
                    <option value="for_sale" <?php echo $product_type === 'for_sale' ? 'selected' : ''; ?>><?php echo trans('inventory_category', 'for_sale'); ?></option>
                    <option value="for_use" <?php echo $product_type === 'for_use' ? 'selected' : ''; ?>><?php echo trans('inventory_category', 'for_use'); ?></option>
                </select>
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="?" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- Helpful Information -->
<div class="alert alert-info mb-3">
    <div class="row align-items-center">
        <div class="col-md-8">
            <h6 class="alert-heading mb-2">
                <i class="fas fa-info-circle"></i> Inventory Deletion Information
            </h6>
            <p class="mb-0 small">
                <strong>Note:</strong> Some inventory items cannot be deleted because they are used in income records. 
                Items that can be safely deleted will show a delete button. Items that cannot be deleted will show 
                an error message explaining why when you attempt to delete them.
            </p>
        </div>
        <div class="col-md-4 text-end">
            <a href="#" class="btn btn-sm btn-outline-info" data-bs-toggle="modal" data-bs-target="#deletionHelpModal">
                <i class="fas fa-question-circle"></i> Learn More
            </a>
        </div>
    </div>
</div>

<!-- <?php echo trans('inventory', 'inventory_records_table'); ?> -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <i class="fas fa-boxes dcmt-view-card-title-icon"></i><?php echo trans('inventory', 'inventory_records'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('inventory', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('inventory', 'records'); ?><?php echo $total_value > 0 ? ' | ' . trans('inventory', 'total_value') . ': <span style="color: #28a745;">' . dcmt_format_currency($total_value) . '</span>' : ''; ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('inventory', 'add_item'); ?></a>
                <a href="import.php" class="dcmt-add-form-view-all-link dcmt-hide">
                    <i class="fas fa-upload me-1"></i><?php echo trans('inventory', 'import_inventory'); ?>
                </a>
                <button type="button" class="dcmt-add-form-view-all-link dcmt-hide" onclick="exportToCSV()">
                    <i class="fas fa-download me-1"></i><?php echo trans('inventory', 'export_inventory'); ?>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($inventory_items)): ?>
            <div class="text-center py-4">
                <i class="fas fa-boxes fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('inventory', 'no_inventory_found'); ?></h5>
                <p class="text-muted"><?php echo trans('inventory', 'no_inventory_message'); ?></p>
                <a href="add.php" class="btn btn-success">
                    <i class="fas fa-plus me-1"></i><?php echo trans('inventory', 'add_first_item'); ?>
                </a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <?php if ($dcmt_can_delete): ?>
                <div id="bulkActionsBar" class="dcmt-bulk-actions-bar mb-3" style="display: none;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <span id="selectedCount" class="me-3">0 <?php echo trans('common', 'selected'); ?></span>
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="selectAll()">
                                <i class="fas fa-check-square me-1"></i><?php echo trans('common', 'select_all'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="deselectAll()">
                                <i class="fas fa-square me-1"></i><?php echo trans('common', 'deselect_all'); ?>
                            </button>
                        </div>
                        <div>
                            <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">
                                <i class="fas fa-trash me-1"></i><?php echo trans('common', 'delete_selected'); ?>
                            </button>
                        </div>
                    </div>
                </div>
                <?php endif; ?>

                <table class="table table-hover">
                    <thead>
                        <tr>
                            <?php if ($dcmt_can_delete): ?>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" class="form-check-input">
                            </th>
                            <?php endif; ?>
                            <th><?php echo trans('inventory', 'name'); ?></th>
                            <th><?php echo trans('inventory', 'category'); ?></th>
                            <th><?php echo trans('inventory', 'quantity'); ?></th>
                            <th><?php echo trans('inventory', 'price'); ?></th>
                            <th><?php echo trans('inventory', 'total_value'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($inventory_items as $key => $item): ?>
                            <?php
                            $stock_class = '';
                            if ($item['dcmt_quantity'] == 0) {
                                $stock_class = 'table-danger';
                            } elseif ($item['dcmt_quantity'] <= $item['dcmt_min_quantity']) {
                                $stock_class = 'table-warning';
                            }
                            
                            // Check for low stock separator
                            $is_low_stock = ($item['dcmt_quantity'] <= $item['dcmt_min_quantity']);
                            $next_is_low_stock = false;
                            if (isset($inventory_items[$key + 1])) {
                                $next_item = $inventory_items[$key + 1];
                                $next_is_low_stock = ($next_item['dcmt_quantity'] <= $next_item['dcmt_min_quantity']);
                            }
                            
                            $separator_style = '';
                            if ($is_low_stock && !$next_is_low_stock && isset($inventory_items[$key + 1])) {
                                $separator_style = 'border-bottom: 2px solid #000 !important;';
                            }

                            $total_item_value = $item['dcmt_quantity'] * $item['dcmt_price'];
                            $is_for_use = ($item['dcmt_product_type'] === 'for_use');
                            $can_delete_item = $dcmt_can_delete && !isset($inventory_used_in_income[(int) $item['dcmt_id']]);
                            ?>
                            <tr class="<?php echo $stock_class; ?>" style="<?php echo $separator_style; ?>">
                                <?php if ($dcmt_can_delete): ?>
                                <td>
                                    <?php if ($can_delete_item): ?>
                                    <input type="checkbox" class="form-check-input dcmt-inventory-checkbox"
                                           value="<?php echo (int) $item['dcmt_id']; ?>"
                                           onchange="updateBulkActions()">
                                    <?php endif; ?>
                                </td>
                                <?php endif; ?>
                                <td>
                                    <div>
                                        <?php echo htmlspecialchars($item['dcmt_name']); ?>
                                        <?php if (!empty($item['dcmt_description'])): ?>
                                            <br><small class="text-muted"><?php echo htmlspecialchars(substr($item['dcmt_description'], 0, 50)) . (strlen($item['dcmt_description']) > 50 ? '...' : ''); ?></small>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td>
                                    <?php if (!empty($item['category_name'])): ?>
                                        <?php 
                                        // Translate category name
                                        $category_name = $item['category_name'];
                                        $translated_category = trans('inventory_category', $category_name);
                                        $display_category = ($translated_category !== $category_name) ? $translated_category : $category_name;
                                        ?>
                                        <span>
                                            <?php echo htmlspecialchars($display_category); ?>
                                        </span>
                                    <?php else: ?>
                                        <span class="text-muted"><?php echo trans('inventory', 'no_category'); ?></span>
                                    <?php endif; ?>
                                </td>

                                <td>
                                    <span class=" <?php echo $item['dcmt_quantity'] <= $item['dcmt_min_quantity'] ? 'text-warning' : ''; ?>">
                                        <?php echo number_format($item['dcmt_quantity']); ?>
                                    </span>
                                </td>

                                <td>
                                    <?php if ($is_for_use): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <?php echo dcmt_format_currency($item['dcmt_price']); ?>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <?php if ($is_for_use): ?>
                                        <span class="text-muted">-</span>
                                    <?php else: ?>
                                        <?php echo dcmt_format_currency($total_item_value); ?>
                                    <?php endif; ?>
                                </td>


                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $item['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <a href="edit.php?id=<?php echo $item['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($dcmt_can_delete): ?>
                                        <?php if ($can_delete_item): ?>
                                            <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="confirmDelete(<?php echo $item['dcmt_id']; ?>, 'inventory_item')">
                                                <img src="../../assets/images/delete.svg" alt="Delete">
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn dcmt-btn-borderless" disabled 
                                                    title="<?php echo trans('inventory', 'cannot_delete_used'); ?>">
                                                <i class="fas fa-lock text-muted"></i>
                                            </button>
                                        <?php endif; endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="<?php echo $dcmt_can_delete ? 7 : 6; ?>" class="fw-bold">
                                <span class="dcmt-view-card-title-total">
                                    <?php echo trans('inventory', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('inventory', 'records'); ?><?php echo $total_value > 0 ? ' | ' . trans('inventory', 'total_value') . ': <span style="color: #28a745;">' . dcmt_format_currency($total_value) . '</span>' : ''; ?>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            
            <!-- Table Legend -->
            <div class="mt-3">
                <small class="text-muted">
                    <i class="fas fa-info-circle me-1"></i>
                    <strong><?php echo trans('inventory', 'legend'); ?>:</strong>
                    <span class="ms-2">
                        <i class="fas fa-trash text-danger me-1"></i><?php echo trans('inventory', 'delete_available'); ?>
                    </span>
                    <span class="ms-3">
                        <i class="fas fa-lock text-secondary me-1"></i><?php echo trans('inventory', 'cannot_delete_used'); ?>
                    </span>
                </small>
            </div>
            <!-- <?php echo trans('inventory', 'pagination'); ?> -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('inventory', 'inventory_records_pagination'); ?>">
                    <ul class="pagination justify-content-center">
                        <!-- First Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                    <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Previous Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                    <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                    <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Last Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" title="<?php echo trans('common', 'last_page'); ?>">
                                    <?php echo trans('common', 'last_page'); ?> <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Help Modal -->
<div class="modal fade" id="deletionHelpModal" tabindex="-1" aria-labelledby="deletionHelpModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-info text-white">
                <h5 class="modal-title" id="deletionHelpModalLabel">
                    <i class="fas fa-question-circle"></i> Understanding Inventory Deletion
                </h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="row">
                    <div class="col-md-6">
                        <div class="card border-success mb-3">
                            <div class="card-header bg-success text-white">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-check-circle"></i> Items That Can Be Deleted
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li><i class="fas fa-check text-success me-2"></i>Items not used in any income records</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Items with no financial transactions</li>
                                    <li><i class="fas fa-check text-success me-2"></i>Items that are no longer needed</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="card border-warning mb-3">
                            <div class="card-header bg-warning text-dark">
                                <h6 class="card-title mb-0">
                                    <i class="fas fa-exclamation-triangle"></i> Items That Cannot Be Deleted
                                </h6>
                            </div>
                            <div class="card-body">
                                <ul class="list-unstyled mb-0">
                                    <li><i class="fas fa-times text-warning me-2"></i>Items used in income records</li>
                                    <li><i class="fas fa-times text-warning me-2"></i>Items with financial history</li>
                                    <li><i class="fas fa-times text-warning me-2"></i>Items referenced in reports</li>
                                </ul>
                            </div>
                        </div>
                    </div>
                </div>
                
                <div class="alert alert-info">
                    <h6 class="alert-heading">
                        <i class="fas fa-lightbulb"></i> Why Can't Some Items Be Deleted?
                    </h6>
                    <p class="mb-2">
                        This is a safety feature to maintain data integrity. When an inventory item is used in income records, 
                        deleting it would break the financial history and make reports inaccurate.
                    </p>
                    <p class="mb-0">
                        <strong>Solution:</strong> If you need to remove an item that cannot be deleted, consider:
                    </p>
                    <ul class="mb-0 mt-2">
                        <li>Setting the status to "discontinued" instead of deleting</li>
                        <li>Setting the quantity to 0 to indicate it's out of stock</li>
                        <li>Contacting an administrator if the item must be removed</li>
                    </ul>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<script>
// Pass translations to JavaScript
window.translations = {
    confirm_deletion: <?php echo json_encode(trans('inventory', 'confirm_deletion'), JSON_UNESCAPED_UNICODE); ?>,
    warning: <?php echo json_encode(trans('inventory', 'warning'), JSON_UNESCAPED_UNICODE); ?>,
    delete_confirmation_message: <?php echo json_encode(trans('inventory', 'delete_confirmation_message'), JSON_UNESCAPED_UNICODE); ?>,
    cancel: <?php echo json_encode(trans('inventory', 'cancel'), JSON_UNESCAPED_UNICODE); ?>,
    yes_delete: <?php echo json_encode(trans('inventory', 'yes_delete'), JSON_UNESCAPED_UNICODE); ?>,
    inventory_item: <?php echo json_encode(trans('inventory', 'inventory_item'), JSON_UNESCAPED_UNICODE); ?>,
    inventory_items: <?php echo json_encode(trans('inventory', 'inventory_items'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_single: <?php echo json_encode(trans('inventory', 'confirm_delete_single'), JSON_UNESCAPED_UNICODE); ?>,
    confirm_delete_multiple: <?php echo json_encode(trans('inventory', 'confirm_delete_multiple'), JSON_UNESCAPED_UNICODE); ?>
};

// Make CSRF token available to JavaScript
window.csrfToken = <?php echo json_encode($csrf_token); ?>;

function getInventoryCsrfToken() {
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    return (csrfMeta && csrfMeta.getAttribute('content')) || window.csrfToken || '';
}

function getInventoryCheckboxes() {
    return document.querySelectorAll('.dcmt-inventory-checkbox');
}

function updateBulkActions() {
    const checkboxes = getInventoryCheckboxes();
    const checkedBoxes = document.querySelectorAll('.dcmt-inventory-checkbox:checked');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');

    if (!bulkActionsBar || !selectedCount || !selectAllCheckbox) {
        return;
    }

    const count = checkedBoxes.length;

    if (count > 0) {
        bulkActionsBar.style.display = 'block';
        selectedCount.textContent = count + ' <?php echo trans('common', 'selected'); ?>';
    } else {
        bulkActionsBar.style.display = 'none';
    }

    if (checkboxes.length === 0 || count === 0) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = false;
    } else if (count === checkboxes.length) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = true;
    } else {
        selectAllCheckbox.indeterminate = true;
    }
}

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    getInventoryCheckboxes().forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    updateBulkActions();
}

function selectAll() {
    getInventoryCheckboxes().forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkActions();
}

function deselectAll() {
    getInventoryCheckboxes().forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkActions();
}

function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.dcmt-inventory-checkbox:checked');

    if (checkedBoxes.length === 0) {
        alert(<?php echo json_encode(trans('inventory', 'please_select_one_record'), JSON_UNESCAPED_UNICODE); ?>);
        return;
    }

    const inventoryIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value, 10));
    confirmBulkDelete(inventoryIds, inventoryIds.length);
}

function confirmBulkDelete(inventoryIds, count) {
    const message = count === 1
        ? window.translations.confirm_delete_single
        : window.translations.confirm_delete_multiple.replace('{count}', count);

    const existingModal = document.getElementById('deleteConfirmationModal');
    if (existingModal) {
        existingModal.remove();
    }

    const confirmDeletion = window.translations.confirm_deletion;
    const warning = window.translations.warning;
    const cancel = window.translations.cancel;
    const yesDelete = window.translations.yes_delete;

    const modalHTML = `
        <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteConfirmationModalLabel">
                            <i class="fas fa-exclamation-triangle"></i> ${confirmDeletion}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle"></i> ${warning}
                            </h6>
                            <p class="mb-0">${message}</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> ${cancel}
                        </button>
                        <button type="button" class="btn btn-danger" onclick="proceedWithBulkDelete([${inventoryIds.join(',')}])">
                            <i class="fas fa-trash"></i> ${yesDelete}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

    document.body.insertAdjacentHTML('beforeend', modalHTML);

    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
    modal.show();

    document.getElementById('deleteConfirmationModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function proceedWithBulkDelete(inventoryIds) {
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }

    bulkDeleteAjax(inventoryIds);
}

function bulkDeleteAjax(inventoryIds) {
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage(<?php echo json_encode(trans('inventory', 'deleting_records'), JSON_UNESCAPED_UNICODE); ?>);
    }

    fetch('bulk_delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            ids: inventoryIds,
            csrf_token: getInventoryCsrfToken()
        })
    })
    .then(response => response.json())
    .then(data => {
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }

        if (data.success) {
            location.reload();
            return;
        }

        const errorMessage = data.message || <?php echo json_encode(trans('inventory', 'failed_to_delete_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            alert(errorMessage);
        }
    })
    .catch(error => {
        console.error('Error deleting inventory records:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        const errorMessage = <?php echo json_encode(trans('inventory', 'error_occurred_deleting_records'), JSON_UNESCAPED_UNICODE); ?>;
        if (typeof showErrorMessage === 'function') {
            showErrorMessage(errorMessage);
        } else {
            alert(errorMessage);
        }
    });
}

// Export to CSV function
function exportToCSV() {
    // Get current filter parameters
    const params = new URLSearchParams(window.location.search);
    params.append('export', 'csv');
    
    // Create download link
    const link = document.createElement('a');
    link.href = 'export.php?' + params.toString();
    link.download = 'inventory_records.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}
</script>

<!-- JavaScript functions are now in assets/js/main.js -->

<?php require_once __DIR__ . '/../../includes/footer.php'; ?> 
