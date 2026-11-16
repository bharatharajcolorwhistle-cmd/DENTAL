<?php
/**
 * Inventory Reports
 * Stock valuation, low stock, expiry, purchases, movement ledger,
 * product profitability and dead stock — all with CSV export.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/inventory_functions.php';

if (isset($dcmt_db) && method_exists($dcmt_db, 'ensureInventoryMovementSchema')) {
    $dcmt_db->ensureInventoryMovementSchema();
}

dcmt_require_inventory_access();

$reports = [
    'valuation' => trans('inventory', 'report_valuation', 'Stock Valuation'),
    'low_stock' => trans('inventory', 'report_low_stock', 'Low Stock / Reorder'),
    'expiry' => trans('inventory', 'report_expiry', 'Expiry Report'),
    'purchases' => trans('inventory', 'report_purchases', 'Purchase Report'),
    'movements' => trans('inventory', 'report_movements', 'Movement Ledger'),
    'profitability' => trans('inventory', 'report_profitability', 'Product Profitability'),
    'dead_stock' => trans('inventory', 'report_dead_stock', 'Dead Stock'),
];

$report = isset($_GET['report']) ? (string) $_GET['report'] : 'valuation';
if (!isset($reports[$report])) {
    $report = 'valuation';
}

$from = isset($_GET['from']) && $_GET['from'] !== '' ? (string) $_GET['from'] : date('Y-m-01');
$to = isset($_GET['to']) && $_GET['to'] !== '' ? (string) $_GET['to'] : date('Y-m-d');
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $from)) {
    $from = date('Y-m-01');
}
if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $to)) {
    $to = date('Y-m-d');
}
$item_id = isset($_GET['item_id']) ? (int) $_GET['item_id'] : 0;
$movement_type = isset($_GET['type']) ? (string) $_GET['type'] : '';
$supplier = isset($_GET['supplier']) ? trim((string) $_GET['supplier']) : '';
$user_filter = isset($_GET['user']) ? trim((string) $_GET['user']) : '';
$days = isset($_GET['days']) ? max(1, min(3650, (int) $_GET['days'])) : 90;
$export = isset($_GET['export']) && $_GET['export'] === 'csv';

$movement_types = dcmt_inventory_movement_types();

$rows = [];
$summary = [];

try {
    if ($report === 'valuation') {
        $sql = "SELECT i.dcmt_id, i.dcmt_name, i.dcmt_sku, i.dcmt_status, i.dcmt_quantity,
                       i.dcmt_min_quantity, i.dcmt_price, i.dcmt_average_cost, i.dcmt_cost_price,
                       i.dcmt_supplier, c.dcmt_name AS category_name, c.dcmt_product_type
                FROM dcmt_inventory i
                LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
                WHERE i.dcmt_status <> 'discontinued'
                ORDER BY (i.dcmt_quantity * i.dcmt_average_cost) DESC, i.dcmt_name";
        $rows = $dcmt_pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['units' => 0, 'cost_value' => 0, 'retail_value' => 0];
        foreach ($rows as &$row) {
            $row['cost_value'] = (float) $row['dcmt_quantity'] * (float) $row['dcmt_average_cost'];
            $row['retail_value'] = (float) $row['dcmt_quantity'] * (float) $row['dcmt_price'];
            $row['margin_percent'] = dcmt_inventory_margin_percent($row['dcmt_price'], $row['dcmt_average_cost']);
            $summary['units'] += (float) $row['dcmt_quantity'];
            $summary['cost_value'] += $row['cost_value'];
            $summary['retail_value'] += $row['retail_value'];
        }
        unset($row);
        $summary['potential_profit'] = $summary['retail_value'] - $summary['cost_value'];
    } elseif ($report === 'low_stock') {
        $sql = "SELECT i.dcmt_id, i.dcmt_name, i.dcmt_sku, i.dcmt_status, i.dcmt_quantity,
                       i.dcmt_min_quantity, i.dcmt_supplier, i.dcmt_average_cost, i.dcmt_price,
                       c.dcmt_name AS category_name
                FROM dcmt_inventory i
                LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
                WHERE i.dcmt_status = 'active' AND i.dcmt_quantity <= i.dcmt_min_quantity
                ORDER BY (i.dcmt_quantity = 0) DESC, i.dcmt_name";
        $rows = $dcmt_pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['items' => count($rows), 'out_of_stock' => 0, 'cost_to_restock' => 0];
        foreach ($rows as &$row) {
            $row['reorder_quantity'] = max(((int) $row['dcmt_min_quantity'] * 2) - (int) $row['dcmt_quantity'], 0);
            $row['cost_to_restock'] = $row['reorder_quantity'] * (float) $row['dcmt_average_cost'];
            if ((float) $row['dcmt_quantity'] <= 0) {
                $summary['out_of_stock']++;
            }
            $summary['cost_to_restock'] += $row['cost_to_restock'];
        }
        unset($row);
    } elseif ($report === 'expiry') {
        $stmt = $dcmt_pdo->prepare("SELECT i.dcmt_id, i.dcmt_name, i.dcmt_sku, i.dcmt_quantity,
                                           i.dcmt_expiry_date, i.dcmt_average_cost, i.dcmt_price,
                                           c.dcmt_name AS category_name
                                    FROM dcmt_inventory i
                                    LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
                                    WHERE i.dcmt_expiry_date IS NOT NULL
                                      AND i.dcmt_expiry_date <= DATE_ADD(CURDATE(), INTERVAL ? DAY)
                                    ORDER BY i.dcmt_expiry_date ASC, i.dcmt_name");
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $today = new DateTime('today');
        $summary = ['items' => count($rows), 'expired' => 0, 'expiring' => 0, 'value_at_risk' => 0];
        foreach ($rows as &$row) {
            $expiry = new DateTime($row['dcmt_expiry_date']);
            $row['days_left'] = (int) $today->diff($expiry)->format('%r%a');
            $row['cost_value'] = (float) $row['dcmt_quantity'] * (float) $row['dcmt_average_cost'];
            $summary['value_at_risk'] += $row['cost_value'];
            if ($row['days_left'] < 0) {
                $summary['expired']++;
            } else {
                $summary['expiring']++;
            }
        }
        unset($row);
    } elseif ($report === 'purchases') {
        $sql = "SELECT m.*, i.dcmt_name, i.dcmt_sku, u.dcmt_full_name AS user_name
                FROM dcmt_inventory_movements m
                INNER JOIN dcmt_inventory i ON i.dcmt_id = m.dcmt_inventory_id
                LEFT JOIN dcmt_users u ON u.dcmt_username = m.dcmt_created_by
                WHERE m.dcmt_movement_type IN ('opening', 'purchase')
                  AND DATE(m.dcmt_movement_date) BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($item_id > 0) {
            $sql .= " AND m.dcmt_inventory_id = ?";
            $params[] = $item_id;
        }
        if ($supplier !== '') {
            $sql .= " AND m.dcmt_supplier LIKE ?";
            $params[] = '%' . $supplier . '%';
        }
        $sql .= " ORDER BY m.dcmt_movement_date DESC, m.dcmt_id DESC";
        $stmt = $dcmt_pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['movements' => count($rows), 'units' => 0, 'total_cost' => 0];
        foreach ($rows as $row) {
            $summary['units'] += (float) $row['dcmt_quantity_delta'];
            $summary['total_cost'] += (float) $row['dcmt_total_cost'];
        }
    } elseif ($report === 'movements') {
        $sql = "SELECT m.*, i.dcmt_name, i.dcmt_sku, u.dcmt_full_name AS user_name
                FROM dcmt_inventory_movements m
                INNER JOIN dcmt_inventory i ON i.dcmt_id = m.dcmt_inventory_id
                LEFT JOIN dcmt_users u ON u.dcmt_username = m.dcmt_created_by
                WHERE DATE(m.dcmt_movement_date) BETWEEN ? AND ?";
        $params = [$from, $to];
        if ($item_id > 0) {
            $sql .= " AND m.dcmt_inventory_id = ?";
            $params[] = $item_id;
        }
        if ($movement_type !== '' && isset($movement_types[$movement_type])) {
            $sql .= " AND m.dcmt_movement_type = ?";
            $params[] = $movement_type;
        }
        if ($user_filter !== '') {
            $sql .= " AND (m.dcmt_created_by LIKE ? OR u.dcmt_full_name LIKE ?)";
            $params[] = '%' . $user_filter . '%';
            $params[] = '%' . $user_filter . '%';
        }
        $sql .= " ORDER BY m.dcmt_movement_date DESC, m.dcmt_id DESC LIMIT 2000";
        $stmt = $dcmt_pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['movements' => count($rows), 'in' => 0, 'out' => 0];
        foreach ($rows as $row) {
            $delta = (float) $row['dcmt_quantity_delta'];
            if ($delta > 0) {
                $summary['in'] += $delta;
            } elseif ($delta < 0) {
                $summary['out'] += abs($delta);
            }
        }
    } elseif ($report === 'profitability') {
        $sql = "SELECT i.dcmt_id, i.dcmt_name, i.dcmt_sku, i.dcmt_price, i.dcmt_average_cost,
                       COALESCE(s.qty_sold, 0) AS qty_sold,
                       COALESCE(s.revenue, 0) AS revenue,
                       COALESCE(c.cogs, 0) AS cogs,
                       (COALESCE(s.revenue, 0) - COALESCE(c.cogs, 0)) AS gross_profit
                FROM dcmt_inventory i
                LEFT JOIN (
                    SELECT b.dcmt_inventory_id, SUM(b.dcmt_quantity) AS qty_sold, SUM(b.dcmt_line_total) AS revenue
                    FROM dcmt_income_breakdown b
                    INNER JOIN dcmt_income inc ON inc.dcmt_id = b.dcmt_id
                    WHERE b.dcmt_line_type = 'product'
                      AND DATE(inc.dcmt_transaction_date) BETWEEN ? AND ?
                    GROUP BY b.dcmt_inventory_id
                ) s ON s.dcmt_inventory_id = i.dcmt_id
                LEFT JOIN (
                    SELECT dcmt_inventory_id,
                           SUM(CASE WHEN dcmt_movement_type = 'sale'
                                    THEN ABS(dcmt_quantity_delta) * dcmt_unit_cost
                                    WHEN dcmt_movement_type = 'sale_return'
                                    THEN -ABS(dcmt_quantity_delta) * dcmt_unit_cost
                                    ELSE 0 END) AS cogs
                    FROM dcmt_inventory_movements
                    WHERE dcmt_movement_type IN ('sale', 'sale_return')
                      AND DATE(dcmt_movement_date) BETWEEN ? AND ?
                    GROUP BY dcmt_inventory_id
                ) c ON c.dcmt_inventory_id = i.dcmt_id
                WHERE s.dcmt_inventory_id IS NOT NULL OR c.dcmt_inventory_id IS NOT NULL
                HAVING qty_sold > 0 OR cogs > 0
                ORDER BY gross_profit DESC, revenue DESC";
        $stmt = $dcmt_pdo->prepare($sql);
        $stmt->execute([$from, $to, $from, $to]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['revenue' => 0, 'cogs' => 0, 'gross_profit' => 0, 'units_sold' => 0];
        foreach ($rows as &$row) {
            $row['margin_percent'] = ((float) $row['revenue']) > 0
                ? (((float) $row['revenue'] - (float) $row['cogs']) / (float) $row['revenue']) * 100
                : null;
            $summary['revenue'] += (float) $row['revenue'];
            $summary['cogs'] += (float) $row['cogs'];
            $summary['gross_profit'] += (float) $row['gross_profit'];
            $summary['units_sold'] += (float) $row['qty_sold'];
        }
        unset($row);
        $summary['margin_percent'] = $summary['revenue'] > 0
            ? (($summary['revenue'] - $summary['cogs']) / $summary['revenue']) * 100
            : null;
    } elseif ($report === 'dead_stock') {
        $stmt = $dcmt_pdo->prepare("SELECT i.dcmt_id, i.dcmt_name, i.dcmt_sku, i.dcmt_quantity,
                                           i.dcmt_average_cost, i.dcmt_price, i.dcmt_supplier,
                                           c.dcmt_name AS category_name,
                                           lm.last_movement
                                    FROM dcmt_inventory i
                                    LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
                                    LEFT JOIN (
                                        SELECT dcmt_inventory_id, MAX(dcmt_movement_date) AS last_movement
                                        FROM dcmt_inventory_movements
                                        GROUP BY dcmt_inventory_id
                                    ) lm ON lm.dcmt_inventory_id = i.dcmt_id
                                    WHERE i.dcmt_status = 'active' AND i.dcmt_quantity > 0
                                      AND (lm.last_movement IS NULL OR lm.last_movement < DATE_SUB(NOW(), INTERVAL ? DAY))
                                    ORDER BY lm.last_movement ASC, i.dcmt_name");
        $stmt->execute([$days]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $summary = ['items' => count($rows), 'units' => 0, 'cost_value' => 0];
        $now = new DateTime();
        foreach ($rows as &$row) {
            $row['cost_value'] = (float) $row['dcmt_quantity'] * (float) $row['dcmt_average_cost'];
            $summary['units'] += (float) $row['dcmt_quantity'];
            $summary['cost_value'] += $row['cost_value'];
            $row['days_since'] = null;
            if (!empty($row['last_movement'])) {
                $row['days_since'] = (int) (new DateTime($row['last_movement']))->diff($now)->format('%a');
            }
        }
        unset($row);
    }
} catch (PDOException $e) {
    error_log('Inventory report failed: ' . $e->getMessage());
    $rows = [];
    $summary = [];
}

// ---------------------------------------------------------------------------
// CSV export
// ---------------------------------------------------------------------------
if ($export) {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_' . $report . '_' . date('Y-m-d_H-i-s') . '.csv"');
    $output = fopen('php://output', 'w');
    fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

    if ($report === 'valuation') {
        fputcsv($output, [
            trans('inventory', 'csv_item_name'),
            trans('inventory', 'csv_sku'),
            trans('inventory', 'csv_category'),
            trans('inventory', 'csv_quantity_on_hand'),
            trans('inventory', 'csv_average_cost'),
            trans('inventory', 'csv_last_cost'),
            trans('inventory', 'csv_selling_price'),
            trans('inventory', 'csv_cost_value'),
            trans('inventory', 'csv_retail_value'),
            trans('inventory', 'csv_margin'),
        ], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['dcmt_name'], $row['dcmt_sku'], $row['category_name'] ?? '',
                $row['dcmt_quantity'], number_format((float) $row['dcmt_average_cost'], 2, '.', ''),
                number_format((float) $row['dcmt_cost_price'], 2, '.', ''),
                number_format((float) $row['dcmt_price'], 2, '.', ''),
                number_format($row['cost_value'], 2, '.', ''),
                number_format($row['retail_value'], 2, '.', ''),
                $row['margin_percent'] === null ? '' : number_format($row['margin_percent'], 1, '.', ''),
            ], ',', '"', '\\');
        }
    } elseif ($report === 'low_stock') {
        fputcsv($output, [
            trans('inventory', 'csv_item_name'), trans('inventory', 'csv_sku'), trans('inventory', 'csv_category'),
            trans('inventory', 'quantity'), trans('inventory', 'minimum_quantity'),
            trans('inventory', 'suggested_reorder'), trans('inventory', 'csv_supplier'),
            trans('inventory', 'csv_average_cost'), trans('inventory', 'csv_cost_value'),
        ], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['dcmt_name'], $row['dcmt_sku'], $row['category_name'] ?? '',
                $row['dcmt_quantity'], $row['dcmt_min_quantity'], $row['reorder_quantity'],
                $row['dcmt_supplier'] ?? '', number_format((float) $row['dcmt_average_cost'], 2, '.', ''),
                number_format($row['cost_to_restock'], 2, '.', ''),
            ], ',', '"', '\\');
        }
    } elseif ($report === 'expiry') {
        fputcsv($output, [
            trans('inventory', 'csv_item_name'), trans('inventory', 'csv_sku'), trans('inventory', 'expiry_date'),
            trans('inventory', 'days_left'), trans('inventory', 'quantity'),
            trans('inventory', 'csv_average_cost'), trans('inventory', 'csv_cost_value'),
        ], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['dcmt_name'], $row['dcmt_sku'], $row['dcmt_expiry_date'], $row['days_left'],
                $row['dcmt_quantity'], number_format((float) $row['dcmt_average_cost'], 2, '.', ''),
                number_format($row['cost_value'], 2, '.', ''),
            ], ',', '"', '\\');
        }
    } elseif ($report === 'purchases' || $report === 'movements') {
        fputcsv($output, [
            trans('inventory', 'csv_date'), trans('inventory', 'csv_item_name'), trans('inventory', 'csv_sku'),
            trans('inventory', 'csv_movement_type'), trans('inventory', 'csv_change'),
            trans('inventory', 'before_movement'), trans('inventory', 'after_movement'),
            trans('inventory', 'csv_unit_cost'), trans('inventory', 'csv_total_cost'),
            trans('inventory', 'csv_supplier'), trans('inventory', 'csv_reference'),
            trans('inventory', 'csv_recorded_by'), trans('inventory', 'csv_notes'),
        ], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['dcmt_movement_date'], $row['dcmt_name'], $row['dcmt_sku'],
                dcmt_inventory_movement_label($row['dcmt_movement_type']),
                number_format((float) $row['dcmt_quantity_delta'], 2, '.', ''),
                number_format((float) $row['dcmt_quantity_before'], 2, '.', ''),
                number_format((float) $row['dcmt_quantity_after'], 2, '.', ''),
                number_format((float) $row['dcmt_unit_cost'], 2, '.', ''),
                number_format((float) $row['dcmt_total_cost'], 2, '.', ''),
                $row['dcmt_supplier'] ?? '', $row['dcmt_reference_no'] ?? '',
                $row['user_name'] ?? $row['dcmt_created_by'], $row['dcmt_notes'] ?? '',
            ], ',', '"', '\\');
        }
    } elseif ($report === 'profitability') {
        fputcsv($output, [
            trans('inventory', 'csv_item_name'), trans('inventory', 'csv_sku'),
            trans('inventory', 'quantity_sold'), trans('inventory', 'csv_revenue'),
            trans('inventory', 'csv_cogs'), trans('inventory', 'csv_gross_profit'),
            trans('inventory', 'csv_margin'),
        ], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['dcmt_name'], $row['dcmt_sku'],
                number_format((float) $row['qty_sold'], 2, '.', ''),
                number_format((float) $row['revenue'], 2, '.', ''),
                number_format((float) $row['cogs'], 2, '.', ''),
                number_format((float) $row['gross_profit'], 2, '.', ''),
                $row['margin_percent'] === null ? '' : number_format($row['margin_percent'], 1, '.', ''),
            ], ',', '"', '\\');
        }
    } elseif ($report === 'dead_stock') {
        fputcsv($output, [
            trans('inventory', 'csv_item_name'), trans('inventory', 'csv_sku'), trans('inventory', 'csv_category'),
            trans('inventory', 'quantity'), trans('inventory', 'csv_average_cost'),
            trans('inventory', 'csv_cost_value'), trans('inventory', 'last_movement'),
            trans('inventory', 'days_since_movement'),
        ], ',', '"', '\\');
        foreach ($rows as $row) {
            fputcsv($output, [
                $row['dcmt_name'], $row['dcmt_sku'], $row['category_name'] ?? '',
                $row['dcmt_quantity'], number_format((float) $row['dcmt_average_cost'], 2, '.', ''),
                number_format($row['cost_value'], 2, '.', ''),
                $row['last_movement'] ?? '', $row['days_since'] === null ? '' : $row['days_since'],
            ], ',', '"', '\\');
        }
    }

    fclose($output);
    exit();
}

$csrf_token = dcmt_generate_csrf_token();
$item_options = [];
try {
    $item_options = $dcmt_pdo->query("SELECT dcmt_id, dcmt_name, dcmt_sku FROM dcmt_inventory ORDER BY dcmt_name")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $item_options = [];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<div class="card mb-4 dcmt-filter-form">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <i class="fas fa-chart-column dcmt-view-card-title-icon"></i><?php echo trans('inventory', 'inventory_reports'); ?>
                </h6>
            </div>
            <div class="dcmt-view-header-links">
                <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('inventory', 'back_to_inventory'); ?></a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <form method="GET" action="reports.php" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="report" class="form-label"><?php echo trans('inventory', 'report_type'); ?></label>
                <select class="form-select dcmt-filter-field" id="report" name="report" onchange="this.form.submit()">
                    <?php foreach ($reports as $key => $label): ?>
                        <option value="<?php echo htmlspecialchars($key); ?>" <?php echo $report === $key ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($label); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>

            <?php if (in_array($report, ['purchases', 'movements', 'profitability'], true)): ?>
                <div class="col-md-3">
                    <label class="form-label"><?php echo trans('inventory', 'date_from'); ?></label>
                    <input type="date" class="form-control dcmt-filter-field" name="from" value="<?php echo htmlspecialchars($from); ?>">
                </div>
                <div class="col-md-3">
                    <label class="form-label"><?php echo trans('inventory', 'date_to'); ?></label>
                    <input type="date" class="form-control dcmt-filter-field" name="to" value="<?php echo htmlspecialchars($to); ?>">
                </div>
            <?php endif; ?>

            <?php if (in_array($report, ['purchases', 'movements'], true)): ?>
                <div class="col-md-3">
                    <label class="form-label"><?php echo trans('inventory', 'name'); ?></label>
                    <select class="form-select dcmt-filter-field" name="item_id">
                        <option value="0"><?php echo trans('inventory', 'all_items'); ?></option>
                        <?php foreach ($item_options as $option): ?>
                            <option value="<?php echo (int) $option['dcmt_id']; ?>" <?php echo $item_id === (int) $option['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($option['dcmt_name'] . ' (' . $option['dcmt_sku'] . ')'); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($report === 'purchases'): ?>
                <div class="col-md-3">
                    <label class="form-label"><?php echo trans('inventory', 'supplier'); ?></label>
                    <input type="text" class="form-control dcmt-filter-field" name="supplier" value="<?php echo htmlspecialchars($supplier); ?>" placeholder="<?php echo trans('inventory', 'all_suppliers'); ?>">
                </div>
            <?php endif; ?>

            <?php if ($report === 'movements'): ?>
                <div class="col-md-2">
                    <label class="form-label"><?php echo trans('inventory', 'movement_type'); ?></label>
                    <select class="form-select dcmt-filter-field" name="type">
                        <option value=""><?php echo trans('inventory', 'all_types'); ?></option>
                        <?php foreach ($movement_types as $type_key => $type_label): ?>
                            <option value="<?php echo htmlspecialchars($type_key); ?>" <?php echo $movement_type === $type_key ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($type_label); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo trans('inventory', 'performed_by'); ?></label>
                    <input type="text" class="form-control dcmt-filter-field" name="user" value="<?php echo htmlspecialchars($user_filter); ?>" placeholder="<?php echo trans('inventory', 'all_users'); ?>">
                </div>
            <?php endif; ?>

            <?php if ($report === 'expiry'): ?>
                <div class="col-md-3">
                    <label class="form-label"><?php echo trans('inventory', 'expiry_filter'); ?></label>
                    <select class="form-select dcmt-filter-field" name="days">
                        <?php foreach ([7, 30, 60, 90, 180, 365] as $option_days): ?>
                            <option value="<?php echo $option_days; ?>" <?php echo $days === $option_days ? 'selected' : ''; ?>>
                                <?php echo sprintf(trans('inventory', 'expiring_within_days', 'Within %d days'), $option_days); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>

            <?php if ($report === 'dead_stock'): ?>
                <div class="col-md-3">
                    <label class="form-label"><?php echo trans('inventory', 'days_without_movement'); ?></label>
                    <input type="number" class="form-control dcmt-filter-field" name="days" min="1" max="3650" value="<?php echo $days; ?>">
                </div>
            <?php endif; ?>

            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="reports.php?report=<?php echo urlencode($report); ?>" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

    <?php
    $export_params = $_GET;
    $export_params['report'] = $report;
    $export_params['export'] = 'csv';
    $export_url = 'reports.php?' . http_build_query($export_params);
    $summary_cards = [];
    if ($report === 'valuation') {
        $summary_cards = [
            [trans('inventory', 'total_units'), dcmt_inventory_format_quantity($summary['units'] ?? 0), ''],
            [trans('inventory', 'total_cost_value'), dcmt_format_currency($summary['cost_value'] ?? 0), ''],
            [trans('inventory', 'total_retail_value'), dcmt_format_currency($summary['retail_value'] ?? 0), ''],
            [trans('inventory', 'potential_profit'), dcmt_format_currency($summary['potential_profit'] ?? 0), 'text-success'],
        ];
    } elseif ($report === 'low_stock') {
        $summary_cards = [
            [trans('inventory', 'total_items'), (string) ($summary['items'] ?? 0), ''],
            [trans('inventory', 'out_of_stock'), (string) ($summary['out_of_stock'] ?? 0), 'text-danger'],
            [trans('inventory', 'cost_to_restock'), dcmt_format_currency($summary['cost_to_restock'] ?? 0), ''],
        ];
    } elseif ($report === 'expiry') {
        // The headline filter lets the user pick 7 / 30 / 60 / ... days, so the
        // "expiring" label has to follow that window instead of always saying
        // "this week".
        $expiring_label = $days === 7
            ? trans('inventory', 'expiring_this_week')
            : sprintf(trans('inventory', 'expiring_within_days_label', 'Expiring within %d days'), $days);
        $summary_cards = [
            [trans('inventory', 'total_items'), (string) ($summary['items'] ?? 0), ''],
            [trans('inventory', 'expired'), (string) ($summary['expired'] ?? 0), 'text-danger'],
            [$expiring_label, (string) ($summary['expiring'] ?? 0), 'text-warning'],
            [trans('inventory', 'cost_value'), dcmt_format_currency($summary['value_at_risk'] ?? 0), ''],
        ];
    } elseif ($report === 'purchases') {
        $summary_cards = [
            [trans('inventory', 'total_purchases'), (string) ($summary['movements'] ?? 0), ''],
            [trans('inventory', 'total_units'), dcmt_inventory_format_quantity($summary['units'] ?? 0), ''],
            [trans('inventory', 'total_cost_value'), dcmt_format_currency($summary['total_cost'] ?? 0), ''],
        ];
    } elseif ($report === 'movements') {
        $summary_cards = [
            [trans('inventory', 'total_records'), (string) ($summary['movements'] ?? 0), ''],
            [trans('inventory', 'stock_in'), dcmt_inventory_format_quantity($summary['in'] ?? 0), 'text-success'],
            [trans('inventory', 'stock_out'), dcmt_inventory_format_quantity($summary['out'] ?? 0), 'text-danger'],
        ];
    } elseif ($report === 'profitability') {
        $summary_cards = [
            [trans('inventory', 'revenue'), dcmt_format_currency($summary['revenue'] ?? 0), ''],
            [trans('inventory', 'cogs'), dcmt_format_currency($summary['cogs'] ?? 0), ''],
            [trans('inventory', 'gross_profit'), dcmt_format_currency($summary['gross_profit'] ?? 0), 'text-success'],
            [trans('inventory', 'average_margin'), ($summary['margin_percent'] ?? null) === null ? '-' : number_format($summary['margin_percent'], 1) . '%', ''],
        ];
    } elseif ($report === 'dead_stock') {
        $summary_cards = [
            [trans('inventory', 'total_items'), (string) ($summary['items'] ?? 0), ''],
            [trans('inventory', 'total_units'), dcmt_inventory_format_quantity($summary['units'] ?? 0), ''],
            [trans('inventory', 'cost_value'), dcmt_format_currency($summary['cost_value'] ?? 0), ''],
        ];
    }
    $report_help = trans('inventory', 'report_' . $report . '_help', '');
    ?>

    <div class="card dcmt-records-table">
        <div class="card-header dcmt-view-card-header">
            <div class="dcmt-view-card-header-content">
                <div>
                    <h6 class="dcmt-view-card-title mb-0">
                        <i class="fas fa-chart-column dcmt-view-card-title-icon"></i><?php echo htmlspecialchars($reports[$report]); ?>
                    </h6>
                    <?php if ($report_help !== '' && $report_help !== 'report_' . $report . '_help'): ?>
                        <div class="dcmt-view-card-title-total"><?php echo htmlspecialchars($report_help); ?></div>
                    <?php endif; ?>
                    <?php if (!empty($summary_cards)): ?>
                        <div class="dcmt-view-card-title-total">
                            (&nbsp;<?php foreach ($summary_cards as $index => $card): ?><?php echo $index > 0 ? ' | ' : ''; ?><?php echo htmlspecialchars($card[0]); ?>: <span class="<?php echo $card[2] !== '' ? $card[2] . ' fw-semibold' : 'fw-semibold'; ?>"><?php echo $card[1]; ?></span><?php endforeach; ?>&nbsp;)
                        </div>
                    <?php endif; ?>
                </div>
                <div class="ms-3 d-flex gap-2 flex-wrap">
                    <a href="<?php echo htmlspecialchars($export_url); ?>" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-file-csv me-1"></i><?php echo trans('inventory', 'export_csv'); ?>
                    </a>
                </div>
            </div>
        </div>
        <div class="card-body">

            <?php if (empty($rows)): ?>
                <div class="text-center text-muted py-5">
                    <i class="fas fa-chart-simple fa-2x mb-3"></i>
                    <p class="mb-0"><?php echo trans('inventory', 'no_report_data'); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <?php if ($report === 'valuation'): ?>
                        <table class="table table-hover align-middle">
                            <thead><tr>
                                <th><?php echo trans('inventory', 'name'); ?></th>
                                <th><?php echo trans('inventory', 'sku'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'average_cost'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'selling_price'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'margin'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'cost_value'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'retail_value'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['dcmt_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['category_name'] ?? ''); ?></small></td>
                                        <td><?php echo htmlspecialchars($row['dcmt_sku']); ?></td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['dcmt_quantity']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['dcmt_average_cost']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['dcmt_price']); ?></td>
                                        <td class="text-end"><?php echo $row['margin_percent'] === null ? '-' : number_format($row['margin_percent'], 1) . '%'; ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['cost_value']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['retail_value']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report === 'low_stock'): ?>
                        <table class="table table-hover align-middle">
                            <thead><tr>
                                <th><?php echo trans('inventory', 'name'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'minimum_quantity'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'suggested_reorder'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'cost_value'); ?></th>
                                <th><?php echo trans('inventory', 'supplier'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['dcmt_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['dcmt_sku']); ?></small></td>
                                        <td class="text-end <?php echo (float) $row['dcmt_quantity'] <= 0 ? 'text-danger fw-bold' : 'text-warning'; ?>"><?php echo dcmt_inventory_format_quantity($row['dcmt_quantity']); ?></td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['dcmt_min_quantity']); ?></td>
                                        <td class="text-end fw-bold"><?php echo dcmt_inventory_format_quantity($row['reorder_quantity']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['cost_to_restock']); ?></td>
                                        <td><?php echo htmlspecialchars($row['dcmt_supplier'] ?? '-'); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                        <small class="text-muted"><?php echo trans('inventory', 'reorder_note'); ?></small>
                    <?php elseif ($report === 'expiry'): ?>
                        <table class="table table-hover align-middle">
                            <thead><tr>
                                <th><?php echo trans('inventory', 'name'); ?></th>
                                <th><?php echo trans('inventory', 'expiry_date'); ?></th>
                                <th><?php echo trans('inventory', 'stock_status'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'cost_value'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['dcmt_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['dcmt_sku']); ?></small></td>
                                        <td><?php echo dcmt_format_date($row['dcmt_expiry_date']); ?></td>
                                        <td>
                                            <?php if ($row['days_left'] < 0): ?>
                                                <span class="badge bg-danger"><?php echo trans('inventory', 'expired'); ?></span>
                                            <?php elseif ($row['days_left'] === 0): ?>
                                                <span class="badge bg-warning text-dark"><?php echo trans('inventory', 'expires_today'); ?></span>
                                            <?php else: ?>
                                                <span class="badge bg-warning text-dark"><?php echo sprintf(trans('inventory', 'expires_in_days', 'Expires in %d days'), $row['days_left']); ?></span>
                                            <?php endif; ?>
                                        </td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['dcmt_quantity']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['cost_value']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report === 'purchases'): ?>
                        <table class="table table-hover align-middle">
                            <thead><tr>
                                <th><?php echo trans('common', 'date'); ?></th>
                                <th><?php echo trans('inventory', 'name'); ?></th>
                                <th><?php echo trans('inventory', 'supplier'); ?></th>
                                <th><?php echo trans('inventory', 'reference'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'unit_cost'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'csv_total_cost'); ?></th>
                                <th><?php echo trans('inventory', 'performed_by'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo dcmt_format_date($row['dcmt_movement_date'], 'M j, Y'); ?></td>
                                        <td><?php echo htmlspecialchars($row['dcmt_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['dcmt_sku']); ?></small></td>
                                        <td><?php echo htmlspecialchars($row['dcmt_supplier'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($row['dcmt_reference_no'] ?? '-'); ?></td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['dcmt_quantity_delta']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['dcmt_unit_cost']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['dcmt_total_cost']); ?></td>
                                        <td><?php echo htmlspecialchars($row['user_name'] ?? $row['dcmt_created_by']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report === 'movements'): ?>
                        <table class="table table-hover align-middle">
                            <thead><tr>
                                <th><?php echo trans('common', 'date'); ?></th>
                                <th><?php echo trans('inventory', 'name'); ?></th>
                                <th><?php echo trans('inventory', 'movement_type'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity_change'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'before_movement'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'after_movement'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'unit_cost'); ?></th>
                                <th><?php echo trans('inventory', 'performed_by'); ?></th>
                                <th><?php echo trans('inventory', 'movement_notes'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <?php $delta = (float) $row['dcmt_quantity_delta']; ?>
                                    <tr>
                                        <td><?php echo dcmt_format_date($row['dcmt_movement_date'], 'M j, Y g:i A'); ?></td>
                                        <td><?php echo htmlspecialchars($row['dcmt_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['dcmt_sku']); ?></small></td>
                                        <td><span class="badge <?php echo dcmt_inventory_movement_badge_class($row['dcmt_movement_type']); ?>"><?php echo htmlspecialchars(dcmt_inventory_movement_label($row['dcmt_movement_type'])); ?></span></td>
                                        <td class="text-end <?php echo $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : ''); ?>"><?php echo $delta > 0 ? '+' : ''; ?><?php echo dcmt_inventory_format_quantity($delta); ?></td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['dcmt_quantity_before']); ?></td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['dcmt_quantity_after']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['dcmt_unit_cost']); ?></td>
                                        <td><?php echo htmlspecialchars($row['user_name'] ?? $row['dcmt_created_by']); ?></td>
                                        <td class="small text-muted"><?php echo htmlspecialchars($row['dcmt_notes'] ?? ''); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report === 'profitability'): ?>
                        <table class="table table-hover align-middle">
                            <thead><tr>
                                <th><?php echo trans('inventory', 'name'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity_sold'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'revenue'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'cogs'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'gross_profit'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'margin'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['dcmt_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['dcmt_sku']); ?></small></td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['qty_sold']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['revenue']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['cogs']); ?></td>
                                        <td class="text-end fw-bold <?php echo (float) $row['gross_profit'] >= 0 ? 'text-success' : 'text-danger'; ?>"><?php echo dcmt_format_currency($row['gross_profit']); ?></td>
                                        <td class="text-end"><?php echo $row['margin_percent'] === null ? '-' : number_format($row['margin_percent'], 1) . '%'; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php elseif ($report === 'dead_stock'): ?>
                        <table class="table table-hover align-middle">
                            <thead><tr>
                                <th><?php echo trans('inventory', 'name'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'average_cost'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'cost_value'); ?></th>
                                <th><?php echo trans('inventory', 'last_movement'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'days_since_movement'); ?></th>
                            </tr></thead>
                            <tbody>
                                <?php foreach ($rows as $row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($row['dcmt_name']); ?><br><small class="text-muted"><?php echo htmlspecialchars($row['dcmt_sku']); ?></small></td>
                                        <td class="text-end"><?php echo dcmt_inventory_format_quantity($row['dcmt_quantity']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['dcmt_average_cost']); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency($row['cost_value']); ?></td>
                                        <td><?php echo $row['last_movement'] ? dcmt_format_date($row['last_movement'], 'M j, Y') : trans('inventory', 'never_moved'); ?></td>
                                        <td class="text-end"><?php echo $row['days_since'] === null ? '-' : (int) $row['days_since']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        </div>
    </div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
