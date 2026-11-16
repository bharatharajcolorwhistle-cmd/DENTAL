<?php
/**
 * Inventory Stock Management
 * Records stock receipts, stock removals and cost revaluations with a full
 * user-attributed audit trail.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/inventory_functions.php';

if (isset($dcmt_db) && method_exists($dcmt_db, 'ensureInventoryMovementSchema')) {
    $dcmt_db->ensureInventoryMovementSchema();
}

dcmt_require_inventory_access();

$inventory_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($inventory_id <= 0) {
    dcmt_show_message(trans('inventory', 'invalid_inventory_id'), 'error');
    dcmt_redirect('index.php');
}

$dcmt_can_manage_pricing = dcmt_can_manage_inventory_pricing();
$errors = [];

function dcmt_inventory_load_item(PDO $pdo, int $inventory_id): ?array
{
    $stmt = $pdo->prepare("SELECT i.*, c.dcmt_name AS category_name, c.dcmt_product_type
                           FROM dcmt_inventory i
                           LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
                           WHERE i.dcmt_id = ?");
    $stmt->execute([$inventory_id]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    return $row ?: null;
}

$item = dcmt_inventory_load_item($dcmt_pdo, $inventory_id);
if (!$item) {
    dcmt_show_message(trans('inventory', 'inventory_not_found'), 'error');
    dcmt_redirect('index.php');
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        $action = (string) ($_POST['action'] ?? '');
        $movement_date_input = trim((string) ($_POST['movement_date'] ?? ''));
        $movement_date = date('Y-m-d H:i:s');
        if ($movement_date_input !== '') {
            $timestamp = strtotime($movement_date_input);
            if ($timestamp === false) {
                $errors[] = trans('inventory', 'invalid_date_format', 'Invalid date.') . ': ' . $movement_date_input;
            } else {
                $movement_date = date('Y-m-d H:i:s', $timestamp);
            }
        }
        $notes = isset($_POST['notes']) ? trim((string) $_POST['notes']) : '';
        $created_by = (string) (dcmt_get_current_user()['dcmt_username'] ?? 'system');

        if (empty($errors) && $action === 'stock_in') {
            $quantity = (float) ($_POST['quantity'] ?? 0);
            $unit_cost_raw = trim((string) ($_POST['unit_cost'] ?? ''));
            $reference_no = trim((string) ($_POST['reference_no'] ?? ''));
            $supplier = trim((string) ($_POST['supplier'] ?? ''));
            $expiry_date = trim((string) ($_POST['expiry_date'] ?? ''));

            if ($quantity <= 0) {
                $errors[] = trans('inventory', 'movement_quantity_required');
            } elseif (floor($quantity) != $quantity) {
                $errors[] = trans('inventory', 'quantity_whole_numbers_only');
            }
            if ($unit_cost_raw === '' || !is_numeric($unit_cost_raw) || (float) $unit_cost_raw < 0) {
                $errors[] = trans('inventory', 'cost_value_required');
            }

            if (empty($errors)) {
                try {
                    $dcmt_pdo->beginTransaction();
                    $result = dcmt_inventory_apply_movement($dcmt_pdo, $inventory_id, 'purchase', $quantity, [
                        'unit_cost' => (float) $unit_cost_raw,
                        'supplier' => $supplier !== '' ? $supplier : null,
                        'reference_no' => $reference_no !== '' ? $reference_no : null,
                        'notes' => $notes !== '' ? $notes : null,
                        'movement_date' => $movement_date,
                        'created_by' => $created_by,
                        'affects_average_cost' => true,
                    ]);
                    if (empty($result['success'])) {
                        throw new Exception($result['error'] ?? trans('inventory', 'movement_failed'));
                    }

                    $updates = [];
                    $update_params = [];
                    if ($supplier !== '') {
                        $updates[] = 'dcmt_supplier = ?';
                        $update_params[] = $supplier;
                    }
                    if ($expiry_date !== '') {
                        $updates[] = 'dcmt_expiry_date = ?';
                        $update_params[] = $expiry_date;
                    }
                    if (!empty($updates)) {
                        $update_params[] = $inventory_id;
                        $dcmt_pdo->prepare('UPDATE dcmt_inventory SET ' . implode(', ', $updates) . ' WHERE dcmt_id = ?')
                            ->execute($update_params);
                    }

                    $dcmt_pdo->commit();
                    dcmt_show_message(trans('inventory', 'stock_in_success'), 'success');
                    dcmt_redirect('stock.php?id=' . $inventory_id);
                } catch (Exception $e) {
                    if ($dcmt_pdo->inTransaction()) {
                        $dcmt_pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                }
            }
        } elseif (empty($errors) && $action === 'stock_out') {
            $quantity = (float) ($_POST['quantity'] ?? 0);
            $reason = (string) ($_POST['reason'] ?? 'other');
            $reason_map = [
                'damage' => 'damage',
                'expiry' => 'expiry',
                'supplier_return' => 'supplier_return',
                'correction' => 'correction',
                'other' => 'adjustment_out',
            ];
            $movement_type = $reason_map[$reason] ?? 'adjustment_out';

            if ($quantity <= 0) {
                $errors[] = trans('inventory', 'movement_quantity_required');
            } elseif (floor($quantity) != $quantity) {
                $errors[] = trans('inventory', 'quantity_whole_numbers_only');
            }

            if (empty($errors)) {
                try {
                    $dcmt_pdo->beginTransaction();
                    $result = dcmt_inventory_apply_movement($dcmt_pdo, $inventory_id, $movement_type, -abs($quantity), [
                        'reference_no' => !empty($_POST['reference_no']) ? trim((string) $_POST['reference_no']) : null,
                        'notes' => $notes !== '' ? $notes : dcmt_inventory_movement_label($movement_type),
                        'movement_date' => $movement_date,
                        'created_by' => $created_by,
                    ]);
                    if (empty($result['success'])) {
                        throw new Exception($result['error'] ?? trans('inventory', 'movement_failed'));
                    }
                    $dcmt_pdo->commit();
                    dcmt_show_message(trans('inventory', 'stock_out_success'), 'success');
                    dcmt_redirect('stock.php?id=' . $inventory_id);
                } catch (Exception $e) {
                    if ($dcmt_pdo->inTransaction()) {
                        $dcmt_pdo->rollBack();
                    }
                    $errors[] = $e->getMessage();
                }
            }
        } elseif (empty($errors) && $action === 'revalue') {
            if (!$dcmt_can_manage_pricing) {
                $errors[] = trans('inventory', 'pricing_admin_only');
            } else {
                $new_cost_raw = trim((string) ($_POST['new_average_cost'] ?? ''));
                if ($new_cost_raw === '' || !is_numeric($new_cost_raw) || (float) $new_cost_raw < 0) {
                    $errors[] = trans('inventory', 'cost_value_required');
                }
                if (empty($errors)) {
                    try {
                        $dcmt_pdo->beginTransaction();
                        $result = dcmt_inventory_apply_movement($dcmt_pdo, $inventory_id, 'revaluation', 0, [
                            'unit_cost' => (float) $new_cost_raw,
                            'notes' => $notes !== '' ? $notes : trans('inventory', 'revalue_cost'),
                            'movement_date' => $movement_date,
                            'created_by' => $created_by,
                        ]);
                        if (empty($result['success'])) {
                            throw new Exception($result['error'] ?? trans('inventory', 'movement_failed'));
                        }
                        $dcmt_pdo->commit();
                        dcmt_show_message(trans('inventory', 'revaluation_success'), 'success');
                        dcmt_redirect('stock.php?id=' . $inventory_id);
                    } catch (Exception $e) {
                        if ($dcmt_pdo->inTransaction()) {
                            $dcmt_pdo->rollBack();
                        }
                        $errors[] = $e->getMessage();
                    }
                }
            }
        }
    }

    // Reload latest values after any failed submit as well.
    $item = dcmt_inventory_load_item($dcmt_pdo, $inventory_id);
}

$quantity_on_hand = (float) ($item['dcmt_quantity'] ?? 0);
$average_cost = (float) ($item['dcmt_average_cost'] ?? 0);
$last_cost = (float) ($item['dcmt_cost_price'] ?? 0);
$selling_price = (float) ($item['dcmt_price'] ?? 0);
$stock_value_cost = $quantity_on_hand * $average_cost;
$stock_value_retail = $quantity_on_hand * $selling_price;
$margin_percent = dcmt_inventory_margin_percent($selling_price, $average_cost);
// Items kept "for use" are never sold, so retail value and potential profit
// do not apply to them: showing a negative "profit" would be misleading.
$is_for_use = (($item['dcmt_product_type'] ?? 'for_sale') === 'for_use');

$purchase_movements = dcmt_inventory_get_movements($dcmt_pdo, $inventory_id, 200, null);
$purchases = array_values(array_filter($purchase_movements, static function ($row) {
    return in_array($row['dcmt_movement_type'], ['opening', 'purchase'], true);
}));
$all_movements = array_slice($purchase_movements, 0, 200);

$category_display = '';
if (!empty($item['category_name'])) {
    $translated = trans('inventory_category', $item['category_name']);
    $category_display = $translated !== $item['category_name'] ? $translated : $item['category_name'];
}

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title">
                <i class="fas fa-boxes-stacked me-2"></i><?php echo trans('inventory', 'stock_management_for'); ?>: <?php echo htmlspecialchars($item['dcmt_name']); ?>
            </h1>
            <div class="d-flex gap-3 flex-wrap">
                <a href="edit.php?id=<?php echo $inventory_id; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                </a>
                <a href="view.php?id=<?php echo $inventory_id; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-eye me-1"></i><?php echo trans('common', 'view'); ?>
                </a>
                <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('inventory', 'back_to_inventory'); ?></a>
            </div>
        </div>
    </div>

    <div class="dcmt-information-panel mb-4">
        <div class="dcmt-information-panel-title">
            <i class="fas fa-calculator me-2" aria-hidden="true"></i><?php echo trans('inventory', 'stock_math_title'); ?>
        </div>
        <ul class="dcmt-information-panel-list small mb-0">
            <li><?php echo trans('inventory', 'stock_math_current'); ?></li>
            <li><?php echo trans('inventory', 'stock_math_average'); ?></li>
            <li><?php echo trans('inventory', 'stock_math_value_cost'); ?></li>
            <li><?php echo trans('inventory', 'stock_math_value_retail'); ?></li>
            <li><?php echo trans('inventory', 'stock_math_profit'); ?></li>
            <li><?php echo trans('inventory', 'stock_math_revaluation'); ?></li>
        </ul>
    </div>

    <div class="row g-3 mb-4">
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body py-3">
                <div class="text-muted small"><?php echo trans('inventory', 'current_stock'); ?></div>
                <div class="h4 mb-0"><?php echo dcmt_inventory_format_quantity($quantity_on_hand); ?></div>
                <div class="small text-muted"><?php echo htmlspecialchars($item['dcmt_sku']); ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body py-3">
                <div class="text-muted small"><?php echo trans('inventory', 'average_cost'); ?></div>
                <div class="h4 mb-0"><?php echo dcmt_format_currency($average_cost); ?></div>
                <div class="small text-muted"><?php echo trans('inventory', 'last_cost'); ?>: <?php echo dcmt_format_currency($last_cost); ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body py-3">
                <div class="text-muted small"><?php echo trans('inventory', 'selling_price'); ?></div>
                <div class="h4 mb-0"><?php echo dcmt_format_currency($selling_price); ?></div>
                <?php if (!$is_for_use && $margin_percent !== null): ?>
                    <div class="small text-success"><?php echo trans('inventory', 'margin'); ?>: <?php echo number_format($margin_percent, 1); ?>%</div>
                <?php elseif ($is_for_use): ?>
                    <div class="small text-muted"><?php echo trans('inventory', 'not_for_sale'); ?></div>
                <?php endif; ?>
            </div></div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body py-3">
                <div class="text-muted small"><?php echo trans('inventory', 'stock_value_at_cost'); ?></div>
                <div class="h5 mb-0"><?php echo dcmt_format_currency($stock_value_cost); ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body py-3">
                <div class="text-muted small"><?php echo trans('inventory', 'retail_value'); ?></div>
                <div class="h5 mb-0"><?php echo $is_for_use ? '&mdash;' : dcmt_format_currency($stock_value_retail); ?></div>
            </div></div>
        </div>
        <div class="col-6 col-lg-2">
            <div class="card h-100"><div class="card-body py-3">
                <div class="text-muted small"><?php echo trans('inventory', 'potential_profit'); ?></div>
                <div class="h5 mb-0 text-success"><?php echo $is_for_use ? '&mdash;' : dcmt_format_currency($stock_value_retail - $stock_value_cost); ?></div>
                <div class="small text-muted"><?php echo htmlspecialchars($category_display ?: trans('inventory', 'no_category')); ?></div>
            </div></div>
        </div>
    </div>

    <div class="row g-4">
        <div class="col-lg-6">
            <div class="card h-100 dcmt-records-table">
                <div class="card-header dcmt-view-card-header">
                    <h6 class="dcmt-view-card-title mb-0"><i class="fas fa-arrow-down me-2 text-success"></i><?php echo trans('inventory', 'stock_in'); ?></h6>
                </div>
                <div class="card-body">
                    <p class="text-muted small"><?php echo trans('inventory', 'stock_in_desc'); ?></p>
                    <form method="POST" action="stock.php?id=<?php echo $inventory_id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="stock_in">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'quantity_to_add'); ?> *</label>
                                <input type="number" class="form-control" name="quantity" min="1" step="1" inputmode="numeric" required placeholder="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'purchase_cost'); ?> *</label>
                                <div class="dcmt-amount-input-wrapper">
                                    <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                    <input type="number" class="form-control dcmt-amount-input" name="unit_cost" min="0" step="0.01" required placeholder="<?php echo trans('common', 'amount'); ?>">
                                </div>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'supplier'); ?></label>
                                <input type="text" class="form-control" name="supplier" maxlength="100" value="<?php echo htmlspecialchars($item['dcmt_supplier'] ?? ''); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'reference_no'); ?></label>
                                <input type="text" class="form-control" name="reference_no" maxlength="100" placeholder="<?php echo trans('inventory', 'enter_reference_no'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'movement_date'); ?></label>
                                <input type="date" class="form-control" name="movement_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'expiry_date'); ?></label>
                                <input type="date" class="form-control" name="expiry_date" value="<?php echo htmlspecialchars($item['dcmt_expiry_date'] ?? ''); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo trans('inventory', 'movement_notes'); ?></label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="<?php echo trans('inventory', 'movement_notes_placeholder'); ?>"></textarea>
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <button type="submit" class="btn dcmt-btn-submit">
                                <i class="fas fa-plus me-1"></i><?php echo trans('inventory', 'record_stock_in'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-6">
            <div class="card h-100 dcmt-records-table">
                <div class="card-header dcmt-view-card-header">
                    <h6 class="dcmt-view-card-title mb-0"><i class="fas fa-arrow-up me-2 text-danger"></i><?php echo trans('inventory', 'stock_out'); ?></h6>
                </div>
                <div class="card-body">
                    <p class="text-muted small"><?php echo trans('inventory', 'stock_out_desc'); ?></p>
                    <form method="POST" action="stock.php?id=<?php echo $inventory_id; ?>">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                        <input type="hidden" name="action" value="stock_out">
                        <div class="row g-3">
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'quantity_to_remove'); ?> *</label>
                                <input type="number" class="form-control" name="quantity" min="1" step="1" inputmode="numeric" required placeholder="0">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'reason'); ?> *</label>
                                <select class="form-select" name="reason" required>
                                    <option value="damage"><?php echo trans('inventory', 'reason_damage'); ?></option>
                                    <option value="expiry"><?php echo trans('inventory', 'reason_expiry'); ?></option>
                                    <option value="supplier_return"><?php echo trans('inventory', 'reason_supplier_return'); ?></option>
                                    <option value="correction"><?php echo trans('inventory', 'reason_correction'); ?></option>
                                    <option value="other"><?php echo trans('inventory', 'reason_other'); ?></option>
                                </select>
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'reference_no'); ?></label>
                                <input type="text" class="form-control" name="reference_no" maxlength="100" placeholder="<?php echo trans('inventory', 'enter_reference_no'); ?>">
                            </div>
                            <div class="col-md-6">
                                <label class="form-label"><?php echo trans('inventory', 'movement_date'); ?></label>
                                <input type="date" class="form-control" name="movement_date" value="<?php echo date('Y-m-d'); ?>">
                            </div>
                            <div class="col-12">
                                <label class="form-label"><?php echo trans('inventory', 'movement_notes'); ?></label>
                                <textarea class="form-control" name="notes" rows="2" placeholder="<?php echo trans('inventory', 'movement_notes_placeholder'); ?>"></textarea>
                            </div>
                        </div>
                        <div class="mt-3 text-end">
                            <button type="submit" class="btn btn-danger">
                                <i class="fas fa-minus me-1"></i><?php echo trans('inventory', 'record_stock_out'); ?>
                            </button>
                        </div>
                    </form>
                </div>
            </div>
        </div>
    </div>

    <?php if ($dcmt_can_manage_pricing): ?>
    <div class="card mt-4 dcmt-records-table">
        <div class="card-header dcmt-view-card-header">
            <h6 class="dcmt-view-card-title mb-0"><i class="fas fa-scale-balanced me-2"></i><?php echo trans('inventory', 'revaluation'); ?></h6>
        </div>
        <div class="card-body">
            <p class="text-muted small mb-3"><?php echo trans('inventory', 'revalue_cost'); ?> — <?php echo trans('inventory', 'average_cost_help', 'Use this only to correct an existing average cost.'); ?></p>
            <form method="POST" action="stock.php?id=<?php echo $inventory_id; ?>" class="row g-3 align-items-end">
                <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
                <input type="hidden" name="action" value="revalue">
                <div class="col-md-4">
                    <label class="form-label"><?php echo trans('inventory', 'new_average_cost'); ?> *</label>
                    <div class="dcmt-amount-input-wrapper">
                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                        <input type="number" class="form-control dcmt-amount-input" name="new_average_cost" min="0" step="0.01" required value="<?php echo number_format($average_cost, 2, '.', ''); ?>">
                    </div>
                </div>
                <div class="col-md-4">
                    <label class="form-label"><?php echo trans('inventory', 'movement_notes'); ?></label>
                    <input type="text" class="form-control" name="notes" maxlength="255">
                </div>
                <div class="col-md-2">
                    <label class="form-label"><?php echo trans('inventory', 'movement_date'); ?></label>
                    <input type="date" class="form-control" name="movement_date" value="<?php echo date('Y-m-d'); ?>">
                </div>
                <div class="col-md-2 text-end">
                    <button type="submit" class="btn dcmt-btn-submit w-100">
                        <i class="fas fa-save me-1"></i><?php echo trans('common', 'save', 'Save'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
    <?php endif; ?>

    <div class="card mt-4 dcmt-records-table">
        <div class="card-header dcmt-view-card-header">
            <h6 class="dcmt-view-card-title mb-0"><i class="fas fa-cart-shopping me-2"></i><?php echo trans('inventory', 'purchase_history'); ?></h6>
        </div>
        <div class="card-body">
            <?php if (empty($purchases)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-cart-shopping fa-2x mb-3"></i>
                    <p class="mb-0"><?php echo trans('inventory', 'no_purchases_yet'); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th><?php echo trans('common', 'date'); ?></th>
                                <th><?php echo trans('inventory', 'supplier'); ?></th>
                                <th><?php echo trans('inventory', 'reference'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'unit_cost'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'csv_total_cost'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'average_cost'); ?></th>
                                <th><?php echo trans('inventory', 'performed_by'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($purchases as $movement): ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($movement['dcmt_movement_date'], 'M j, Y'); ?></td>
                                    <td><?php echo htmlspecialchars($movement['dcmt_supplier'] ?: ($item['dcmt_supplier'] ?? '-')); ?></td>
                                    <td><?php echo htmlspecialchars($movement['dcmt_reference_no'] ?: '-'); ?></td>
                                    <td class="text-end"><?php echo dcmt_inventory_format_quantity($movement['dcmt_quantity_delta']); ?></td>
                                    <td class="text-end"><?php echo dcmt_format_currency($movement['dcmt_unit_cost']); ?></td>
                                    <td class="text-end"><?php echo dcmt_format_currency($movement['dcmt_total_cost']); ?></td>
                                    <td class="text-end"><?php echo dcmt_format_currency($movement['dcmt_average_cost_after']); ?></td>
                                    <td><?php echo htmlspecialchars($movement['user_name'] ?: $movement['dcmt_created_by']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>
    </div>

    <div class="card mt-4 dcmt-records-table">
        <div class="card-header dcmt-view-card-header">
            <h6 class="dcmt-view-card-title mb-0"><i class="fas fa-clock-rotate-left me-2"></i><?php echo trans('inventory', 'movement_history'); ?></h6>
        </div>
        <div class="card-body">
            <?php if (empty($all_movements)): ?>
                <div class="text-center text-muted py-4">
                    <i class="fas fa-clock-rotate-left fa-2x mb-3"></i>
                    <p class="mb-0"><?php echo trans('inventory', 'no_movements_yet'); ?></p>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-hover align-middle">
                        <thead>
                            <tr>
                                <th><?php echo trans('common', 'date'); ?></th>
                                <th><?php echo trans('inventory', 'movement_type'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'quantity_change'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'before_movement'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'after_movement'); ?></th>
                                <th class="text-end"><?php echo trans('inventory', 'unit_cost'); ?></th>
                                <th><?php echo trans('inventory', 'reference'); ?></th>
                                <th><?php echo trans('inventory', 'performed_by'); ?></th>
                                <th><?php echo trans('inventory', 'movement_notes'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($all_movements as $movement): ?>
                                <?php $delta = (float) $movement['dcmt_quantity_delta']; ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($movement['dcmt_movement_date'], 'M j, Y g:i A'); ?></td>
                                    <td>
                                        <span class="badge <?php echo dcmt_inventory_movement_badge_class($movement['dcmt_movement_type']); ?>">
                                            <?php echo htmlspecialchars(dcmt_inventory_movement_label($movement['dcmt_movement_type'])); ?>
                                        </span>
                                    </td>
                                    <td class="text-end <?php echo $delta > 0 ? 'text-success' : ($delta < 0 ? 'text-danger' : ''); ?>">
                                        <?php echo $delta > 0 ? '+' : ''; ?><?php echo dcmt_inventory_format_quantity($delta); ?>
                                    </td>
                                    <td class="text-end"><?php echo dcmt_inventory_format_quantity($movement['dcmt_quantity_before']); ?></td>
                                    <td class="text-end"><?php echo dcmt_inventory_format_quantity($movement['dcmt_quantity_after']); ?></td>
                                    <td class="text-end"><?php echo dcmt_format_currency($movement['dcmt_unit_cost']); ?></td>
                                    <td><?php echo htmlspecialchars($movement['dcmt_reference_no'] ?: '-'); ?></td>
                                    <td><?php echo htmlspecialchars($movement['user_name'] ?: $movement['dcmt_created_by']); ?></td>
                                    <td class="small text-muted"><?php echo htmlspecialchars($movement['dcmt_notes'] ?: ''); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <div class="text-end mt-2">
                    <a href="reports.php?report=movements&amp;item_id=<?php echo $inventory_id; ?>" class="small">
                        <?php echo trans('inventory', 'view_stock_movements'); ?> <i class="fas fa-arrow-right ms-1"></i>
                    </a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
