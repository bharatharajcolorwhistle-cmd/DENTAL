<?php
/**
 * Inventory List Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

dcmt_require_admin_or_staff();

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

// Now include the header
require_once __DIR__ . '/../../includes/header.php';

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
                <table class="table table-hover">
                    <thead>
                        <tr>
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
                            ?>
                            <tr class="<?php echo $stock_class; ?>" style="<?php echo $separator_style; ?>">
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
                                        <?php
                                        // Check if item can be deleted (not used in income records)
                                        $can_delete_query = "SELECT COUNT(*) FROM dcmt_income_breakdown WHERE dcmt_line_type = 'product' AND dcmt_inventory_id = ?";
                                        $can_delete_stmt = $dcmt_pdo->prepare($can_delete_query);
                                        $can_delete_stmt->execute([$item['dcmt_id']]);
                                        $can_delete = $can_delete_stmt->fetchColumn() == 0;
                                        
                                        if ($can_delete): ?>
                                            <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="confirmDelete(<?php echo $item['dcmt_id']; ?>, 'inventory_item')">
                                                <img src="../../assets/images/delete.svg" alt="Delete">
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn dcmt-btn-borderless" disabled 
                                                    title="<?php echo trans('inventory', 'cannot_delete_used'); ?>">
                                                <i class="fas fa-lock text-muted"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="6" class="fw-bold">
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
    confirm_deletion: '<?php echo trans('inventory', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('inventory', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('inventory', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('inventory', 'cancel'); ?>',
    yes_delete: '<?php echo trans('inventory', 'yes_delete'); ?>',
    inventory_item: '<?php echo trans('inventory', 'inventory_item'); ?>'
};

// Make CSRF token available to JavaScript
window.csrfToken = '<?php echo dcmt_generate_csrf_token(); ?>';

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
