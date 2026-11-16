<?php
/**
 * Inventory stock movement and costing helpers.
 *
 * Every change to stock quantity must go through a movement row so the clinic
 * has a complete, user-attributed audit trail and can compute a weighted
 * average purchase cost (moving average).
 */

if (!function_exists('dcmt_inventory_movement_types')) {
    /**
     * Supported movement types.
     *
     * @return array<string, string>
     */
    function dcmt_inventory_movement_types(): array
    {
        return [
            'opening' => trans('inventory', 'movement_type_opening', 'Opening stock'),
            'purchase' => trans('inventory', 'movement_type_purchase', 'Purchase'),
            'sale' => trans('inventory', 'movement_type_sale', 'Sale'),
            'sale_return' => trans('inventory', 'movement_type_sale_return', 'Sale return'),
            'adjustment_in' => trans('inventory', 'movement_type_adjustment_in', 'Adjustment in'),
            'adjustment_out' => trans('inventory', 'movement_type_adjustment_out', 'Adjustment out'),
            'damage' => trans('inventory', 'movement_type_damage', 'Damaged / lost'),
            'expiry' => trans('inventory', 'movement_type_expiry', 'Expired / written off'),
            'supplier_return' => trans('inventory', 'movement_type_supplier_return', 'Returned to supplier'),
            'revaluation' => trans('inventory', 'movement_type_revaluation', 'Cost revaluation'),
            'correction' => trans('inventory', 'movement_type_correction', 'Correction'),
        ];
    }
}

if (!function_exists('dcmt_inventory_movement_label')) {
    function dcmt_inventory_movement_label(string $type): string
    {
        $types = dcmt_inventory_movement_types();
        return $types[$type] ?? ucwords(str_replace('_', ' ', $type));
    }
}

if (!function_exists('dcmt_inventory_movement_badge_class')) {
    function dcmt_inventory_movement_badge_class(string $type): string
    {
        $map = [
            'opening' => 'bg-secondary',
            'purchase' => 'bg-success',
            'sale' => 'bg-primary',
            'sale_return' => 'bg-info text-dark',
            'adjustment_in' => 'bg-info text-dark',
            'adjustment_out' => 'bg-warning text-dark',
            'damage' => 'bg-danger',
            'expiry' => 'bg-danger',
            'supplier_return' => 'bg-warning text-dark',
            'revaluation' => 'bg-dark',
            'correction' => 'bg-secondary',
        ];
        return $map[$type] ?? 'bg-secondary';
    }
}

if (!function_exists('dcmt_inventory_ensure_movement_schema')) {
    /**
     * Create the movement ledger and add costing columns when missing.
     * Safe to call on every inventory request; work is skipped after the first
     * successful call in a request.
     */
    function dcmt_inventory_ensure_movement_schema($pdo): void
    {
        static $done = false;
        if ($done || !($pdo instanceof PDO)) {
            return;
        }
        $done = true;

        try {
            $col = $pdo->query("SHOW COLUMNS FROM dcmt_inventory LIKE 'dcmt_average_cost'")->fetch(PDO::FETCH_ASSOC);
            if (!$col) {
                $pdo->exec("ALTER TABLE dcmt_inventory ADD COLUMN dcmt_average_cost DECIMAL(12,4) NOT NULL DEFAULT 0.0000 AFTER dcmt_price");
            }
        } catch (PDOException $e) {
            error_log('Inventory average cost column ensure failed: ' . $e->getMessage());
        }

        try {
            $col = $pdo->query("SHOW COLUMNS FROM dcmt_inventory LIKE 'dcmt_cost_price'")->fetch(PDO::FETCH_ASSOC);
            if (!$col) {
                $pdo->exec("ALTER TABLE dcmt_inventory ADD COLUMN dcmt_cost_price DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dcmt_average_cost");
            }
        } catch (PDOException $e) {
            error_log('Inventory cost price column ensure failed: ' . $e->getMessage());
        }

        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS dcmt_inventory_movements (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_inventory_id INT NOT NULL,
                dcmt_movement_type VARCHAR(30) NOT NULL,
                dcmt_quantity_delta DECIMAL(12,2) NOT NULL DEFAULT 0,
                dcmt_quantity_before DECIMAL(12,2) NOT NULL DEFAULT 0,
                dcmt_quantity_after DECIMAL(12,2) NOT NULL DEFAULT 0,
                dcmt_unit_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                dcmt_total_cost DECIMAL(12,2) NOT NULL DEFAULT 0,
                dcmt_average_cost_after DECIMAL(12,4) NOT NULL DEFAULT 0,
                dcmt_reference_type VARCHAR(50) NULL,
                dcmt_reference_id INT NULL,
                dcmt_reference_no VARCHAR(100) NULL,
                dcmt_supplier VARCHAR(100) NULL,
                dcmt_notes TEXT NULL,
                dcmt_movement_date DATETIME NOT NULL,
                dcmt_created_by VARCHAR(50) NOT NULL,
                dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                INDEX idx_inv_mov_inventory (dcmt_inventory_id),
                INDEX idx_inv_mov_type (dcmt_movement_type),
                INDEX idx_inv_mov_date (dcmt_movement_date)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            error_log('Inventory movements table ensure failed: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_inventory_apply_movement')) {
    /**
     * Apply a stock movement and recalculate the moving average cost.
     *
     * Callers should wrap this in a transaction when applying more than one
     * movement so the quantity and ledger stay consistent.
     *
     * @param PDO   $pdo
     * @param int   $inventory_id
     * @param string $type One of dcmt_inventory_movement_types() keys.
     * @param float $delta Positive for stock in, negative for stock out.
     * @param array $options unit_cost, supplier, reference_type, reference_id,
     *                       reference_no, notes, movement_date, allow_negative,
     *                       affects_average_cost, log_activity, created_by
     * @return array{success:bool,error?:string,movement_id?:int,quantity_before?:float,quantity_after?:float,average_cost?:float,unit_cost?:float}
     */
    function dcmt_inventory_apply_movement($pdo, int $inventory_id, string $type, float $delta, array $options = []): array
    {
        if (!($pdo instanceof PDO) || $inventory_id <= 0) {
            return ['success' => false, 'error' => trans('inventory', 'invalid_inventory_id')];
        }

        if (!array_key_exists($type, dcmt_inventory_movement_types())) {
            return ['success' => false, 'error' => trans('inventory', 'invalid_movement_type', 'Invalid stock movement type.')];
        }

        $is_revaluation = ($type === 'revaluation');
        if (!$is_revaluation && abs($delta) < 0.000001) {
            return ['success' => false, 'error' => trans('inventory', 'movement_quantity_required', 'Enter a quantity for this stock movement.')];
        }
        if ($is_revaluation && !array_key_exists('unit_cost', $options)) {
            return ['success' => false, 'error' => trans('inventory', 'cost_value_required', 'Enter the cost value.')];
        }

        $unit_cost = array_key_exists('unit_cost', $options) && $options['unit_cost'] !== '' && $options['unit_cost'] !== null
            ? (float) $options['unit_cost']
            : null;
        $allow_negative = !empty($options['allow_negative']);
        $log_activity = !array_key_exists('log_activity', $options) || (bool) $options['log_activity'];
        $movement_date = !empty($options['movement_date']) ? (string) $options['movement_date'] : date('Y-m-d H:i:s');
        $created_by = !empty($options['created_by']) ? (string) $options['created_by'] : 'system';

        try {
            $stmt = $pdo->prepare("SELECT dcmt_name, dcmt_quantity, dcmt_average_cost, dcmt_cost_price FROM dcmt_inventory WHERE dcmt_id = ? FOR UPDATE");
            $stmt->execute([$inventory_id]);
            $item = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$item) {
                return ['success' => false, 'error' => trans('inventory', 'inventory_not_found')];
            }

            $before = (float) $item['dcmt_quantity'];
            $average_cost = (float) $item['dcmt_average_cost'];
            $last_cost = (float) $item['dcmt_cost_price'];
            $after = $is_revaluation ? $before : ($before + $delta);

            if ($after < 0 && !$allow_negative) {
                return [
                    'success' => false,
                    'error' => sprintf(
                        trans('inventory', 'insufficient_stock_for_movement', 'Not enough stock. Available: %s.'),
                        rtrim(rtrim(number_format($before, 2, '.', ''), '0'), '.')
                    ),
                ];
            }

            $affects_average_cost = array_key_exists('affects_average_cost', $options)
                ? (bool) $options['affects_average_cost']
                : in_array($type, ['opening', 'purchase'], true);

            $new_average_cost = $average_cost;
            if ($is_revaluation) {
                $new_average_cost = max(0.0, (float) $unit_cost);
            } elseif ($affects_average_cost && $delta > 0) {
                $cost_for_average = $unit_cost;
                if ($cost_for_average === null) {
                    $cost_for_average = $last_cost > 0 ? $last_cost : $average_cost;
                }
                if ($after > 0) {
                    $new_average_cost = (($before * $average_cost) + ($delta * $cost_for_average)) / $after;
                } else {
                    $new_average_cost = $cost_for_average;
                }
            }

            if ($unit_cost === null) {
                $unit_cost = $is_revaluation
                    ? $new_average_cost
                    : ($delta >= 0 ? ($last_cost > 0 ? $last_cost : $average_cost) : $average_cost);
            }

            $new_average_cost = round(max(0.0, $new_average_cost), 4);
            $unit_cost = round(max(0.0, $unit_cost), 2);
            $total_cost = round(abs($delta) * $unit_cost, 2);
            $stored_after = (float) $after;

            $update_sql = "UPDATE dcmt_inventory
                           SET dcmt_quantity = ?,
                               dcmt_average_cost = ?,
                               dcmt_cost_price = ?,
                               dcmt_updated_at = NOW()
                           WHERE dcmt_id = ?";
            $update_cost_price = $last_cost;
            // Only a real receipt updates the "last purchase cost". A manual
            // revaluation corrects the average cost baseline and must not
            // pretend it was the price on a supplier invoice.
            if ($delta > 0 && in_array($type, ['opening', 'purchase'], true) && array_key_exists('unit_cost', $options) && $options['unit_cost'] !== '' && $options['unit_cost'] !== null) {
                $update_cost_price = $unit_cost;
            }
            $pdo->prepare($update_sql)->execute([$stored_after, $new_average_cost, $update_cost_price, $inventory_id]);

            $insert_sql = "INSERT INTO dcmt_inventory_movements (
                                dcmt_inventory_id, dcmt_movement_type, dcmt_quantity_delta,
                                dcmt_quantity_before, dcmt_quantity_after, dcmt_unit_cost,
                                dcmt_total_cost, dcmt_average_cost_after, dcmt_reference_type,
                                dcmt_reference_id, dcmt_reference_no, dcmt_supplier, dcmt_notes,
                                dcmt_movement_date, dcmt_created_by
                           ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($insert_sql)->execute([
                $inventory_id,
                $type,
                $is_revaluation ? 0 : $delta,
                $before,
                $stored_after,
                $unit_cost,
                $total_cost,
                $new_average_cost,
                $options['reference_type'] ?? null,
                isset($options['reference_id']) ? (int) $options['reference_id'] : null,
                $options['reference_no'] ?? null,
                $options['supplier'] ?? null,
                $options['notes'] ?? null,
                $movement_date,
                $created_by,
            ]);
            $movement_id = (int) $pdo->lastInsertId();

            if ($log_activity && function_exists('dcmt_log_activity')) {
                $quantity_text = $is_revaluation
                    ? 'No quantity change'
                    : sprintf('%s → %s (%+g)', dcmt_inventory_format_quantity($before), dcmt_inventory_format_quantity($stored_after), $delta);
                $details = sprintf(
                    'Inventory ID: %d | Item: %s | Type: %s | Qty: %s | Unit Cost: %s | Avg Cost: %s%s%s',
                    $inventory_id,
                    (string) ($item['dcmt_name'] ?? ''),
                    dcmt_inventory_movement_label($type),
                    $quantity_text,
                    dcmt_format_currency($unit_cost),
                    number_format($new_average_cost, 2, '.', '') . ' ' . dcmt_get_current_currency(),
                    !empty($options['reference_no']) ? ' | Ref: ' . $options['reference_no'] : '',
                    !empty($options['notes']) ? ' | Notes: ' . $options['notes'] : ''
                );
                dcmt_log_activity('Inventory Movement: ' . dcmt_inventory_movement_label($type), $details);
            }

            return [
                'success' => true,
                'movement_id' => $movement_id,
                'quantity_before' => $before,
                'quantity_after' => $stored_after,
                'average_cost' => $new_average_cost,
                'unit_cost' => $unit_cost,
            ];
        } catch (PDOException $e) {
            error_log('Inventory movement failed: ' . $e->getMessage());
            return ['success' => false, 'error' => trans('inventory', 'database_error') . ': ' . $e->getMessage()];
        }
    }
}

if (!function_exists('dcmt_inventory_format_quantity')) {
    function dcmt_inventory_format_quantity($quantity): string
    {
        $quantity = (float) $quantity;
        if (abs($quantity - round($quantity)) < 0.000001) {
            return number_format($quantity);
        }
        return rtrim(rtrim(number_format($quantity, 2, '.', ''), '0'), '.');
    }
}

if (!function_exists('dcmt_inventory_margin_percent')) {
    /**
     * Gross margin percent based on selling price and average cost.
     */
    function dcmt_inventory_margin_percent($selling_price, $average_cost): ?float
    {
        $selling_price = (float) $selling_price;
        $average_cost = (float) $average_cost;
        if ($selling_price <= 0 || $average_cost <= 0) {
            return null;
        }
        return (($selling_price - $average_cost) / $selling_price) * 100;
    }
}

if (!function_exists('dcmt_inventory_sale_unit_cost')) {
    /**
     * Unit cost of the most recent sale movement already recorded for an income
     * record. Used when reversing a sale so the profitability report nets the
     * original cost of goods sold, not the cost at reversal time.
     */
    function dcmt_inventory_sale_unit_cost($pdo, int $inventory_id, int $income_id): ?float
    {
        if (!($pdo instanceof PDO) || $inventory_id <= 0 || $income_id <= 0) {
            return null;
        }
        try {
            $stmt = $pdo->prepare("SELECT dcmt_unit_cost
                                   FROM dcmt_inventory_movements
                                   WHERE dcmt_inventory_id = ?
                                     AND dcmt_movement_type = 'sale'
                                     AND dcmt_reference_type = 'income'
                                     AND dcmt_reference_id = ?
                                   ORDER BY dcmt_id DESC
                                   LIMIT 1");
            $stmt->execute([$inventory_id, $income_id]);
            $value = $stmt->fetchColumn();
            return $value === null || $value === false ? null : (float) $value;
        } catch (PDOException $e) {
            error_log('Failed to resolve inventory sale cost: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('dcmt_inventory_get_movements')) {
    /**
     * Fetch movement rows for a single item (newest first).
     *
     * @return array<int, array<string, mixed>>
     */
    function dcmt_inventory_get_movements($pdo, int $inventory_id, int $limit = 100, ?string $type = null): array
    {
        if (!($pdo instanceof PDO) || $inventory_id <= 0) {
            return [];
        }
        try {
            $sql = "SELECT m.*, u.dcmt_full_name AS user_name
                    FROM dcmt_inventory_movements m
                    LEFT JOIN dcmt_users u ON m.dcmt_created_by = u.dcmt_username
                    WHERE m.dcmt_inventory_id = ?";
            $params = [$inventory_id];
            if ($type !== null && $type !== '') {
                $sql .= " AND m.dcmt_movement_type = ?";
                $params[] = $type;
            }
            $sql .= " ORDER BY m.dcmt_movement_date DESC, m.dcmt_id DESC LIMIT " . max(1, min(1000, $limit));
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Failed to fetch inventory movements: ' . $e->getMessage());
            return [];
        }
    }
}
