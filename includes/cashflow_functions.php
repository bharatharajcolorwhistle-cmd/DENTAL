<?php
/**
 * Cashflow Helper Functions
 * Dental Clinic Management System
 */

if (!function_exists('dcmt_get_cashflow_by_date')) {
    /**
     * Fetch a cashflow record for the provided date.
     */
    function dcmt_get_cashflow_by_date(PDO $pdo, string $recordDate): ?array
    {
        try {
            $stmt = $pdo->prepare("SELECT * FROM dcmt_cashflows WHERE dcmt_record_date = ? LIMIT 1");
            $stmt->execute([$recordDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('Cashflow lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('dcmt_get_cash_denominations')) {
    /**
     * Return the denomination schema (Indian currency default).
     */
    function dcmt_get_cash_denominations(): array
    {
        return [
            ['label' => '1000', 'value' => 1000.00],
            ['label' => '500', 'value' => 500.00],
            ['label' => '200', 'value' => 200.00],
            ['label' => '100', 'value' => 100.00],
            ['label' => '50', 'value' => 50.00],
            ['label' => '20', 'value' => 20.00],
            ['label' => '10', 'value' => 10.00],
            ['label' => '5', 'value' => 5.00],
            ['label' => '2', 'value' => 2.00],
            ['label' => '1', 'value' => 1.00],
            ['label' => '0.50', 'value' => 0.50],
        ];
    }
}

if (!function_exists('dcmt_get_cashflow_starting_amount')) {
    /**
     * Determine starting cash by pulling the latest previous closing value.
     */
    function dcmt_get_cashflow_starting_amount(PDO $pdo, string $recordDate): float
    {
        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_ending_amount 
                FROM dcmt_cashflows 
                WHERE dcmt_record_date < ? 
                ORDER BY dcmt_record_date DESC 
                LIMIT 1
            ");
            $stmt->execute([$recordDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (float) $row['dcmt_ending_amount'] : 0.0;
        } catch (PDOException $e) {
            error_log('Cashflow starting amount lookup failed: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('dcmt_get_cash_method_ids')) {
    /**
     * Return cached list of payment method IDs that represent cash.
     */
    function dcmt_get_cash_method_ids(PDO $pdo): array
    {
        static $cachedIds = null;

        if ($cachedIds !== null) {
            return $cachedIds;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_id 
                FROM dcmt_income_payment_methods 
                WHERE LOWER(dcmt_name) LIKE :cash OR LOWER(dcmt_name) LIKE :efectivo
            ");
            $stmt->execute([
                ':cash' => '%cash%',
                ':efectivo' => '%efectivo%',
            ]);
            $cachedIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'dcmt_id'));
        } catch (PDOException $e) {
            error_log('Cash method lookup failed: ' . $e->getMessage());
            $cachedIds = [];
        }

        return $cachedIds;
    }
}

if (!function_exists('dcmt_calculate_cash_income_total')) {
    /**
     * Calculate total cash income (based on payment method) for a single date.
     * This function sums all cash payments from the payment history table,
     * including multiple payments for the same income record.
     */
    function dcmt_calculate_cash_income_total(PDO $pdo, string $recordDate): float
    {
        $cashMethodIds = dcmt_get_cash_method_ids($pdo);
        if (empty($cashMethodIds)) {
            return 0.0;
        }

        $placeholders = implode(',', array_fill(0, count($cashMethodIds), '?'));
        $params = array_merge([$recordDate], $cashMethodIds);

        try {
            // Calculate from payment history table to include all multiple payments
            // Payment method ID is stored in JSON notes field: {"payment_method_id": X}
            // Fetch all payments for the date and filter by cash method IDs in PHP for reliability
            $stmt = $pdo->prepare("
                SELECT dcmt_amount, dcmt_notes
                FROM dcmt_income_payment_history
                WHERE dcmt_paid_on = ?
            ");
            $stmt->execute([$recordDate]);
            $payments = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $totalCash = 0.0;
            foreach ($payments as $payment) {
                $paymentMethodId = null;

                // Extract payment_method_id from JSON notes
                if (!empty($payment['dcmt_notes'])) {
                    $notesData = json_decode($payment['dcmt_notes'], true);
                    if (is_array($notesData) && isset($notesData['payment_method_id'])) {
                        $paymentMethodId = (int) $notesData['payment_method_id'];
                    }
                }

                // Sum amount if payment method is a cash method
                if ($paymentMethodId !== null && in_array($paymentMethodId, $cashMethodIds, true)) {
                    $totalCash += (float) $payment['dcmt_amount'];
                }
            }

            return $totalCash;
        } catch (PDOException $e) {
            error_log('Cash income total calculation failed: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('dcmt_get_cash_expense_method_ids')) {
    /**
     * Return cached list of expense payment method IDs that represent cash.
     */
    function dcmt_get_cash_expense_method_ids(PDO $pdo): array
    {
        static $cachedIds = null;

        if ($cachedIds !== null) {
            return $cachedIds;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_id 
                FROM dcmt_expense_payment_methods 
                WHERE LOWER(dcmt_name) LIKE :cash OR LOWER(dcmt_name) LIKE :efectivo
            ");
            $stmt->execute([
                ':cash' => '%cash%',
                ':efectivo' => '%efectivo%',
            ]);
            $cachedIds = array_map('intval', array_column($stmt->fetchAll(PDO::FETCH_ASSOC), 'dcmt_id'));
        } catch (PDOException $e) {
            error_log('Cash expense method lookup failed: ' . $e->getMessage());
            $cachedIds = [];
        }

        return $cachedIds;
    }
}

if (!function_exists('dcmt_calculate_cash_expense_total')) {
    /**
     * Calculate total cash expenses (based on payment method) for a single date.
     * This function sums all cash expenses for the given date.
     */
    function dcmt_calculate_cash_expense_total(PDO $pdo, string $recordDate): float
    {
        $cashMethodIds = dcmt_get_cash_expense_method_ids($pdo);

        try {
            // Treat an expense as "cash" if either:
            // - payment_method_id is one of the configured cash expense methods, OR
            // - payment_method name contains 'cash' or 'efectivo'
            if (!empty($cashMethodIds)) {
                $placeholders = implode(',', array_fill(0, count($cashMethodIds), '?'));
                $stmt = $pdo->prepare("
                    SELECT SUM(dcmt_amount) as total
                    FROM dcmt_expenses
                    WHERE dcmt_expense_date = ? 
                    AND dcmt_payment_status = 'paid'
                    AND (
                        dcmt_payment_method_id IN ($placeholders)
                        OR LOWER(dcmt_payment_method) LIKE '%cash%'
                        OR LOWER(dcmt_payment_method) LIKE '%efectivo%'
                    )
                ");
                $params = array_merge([$recordDate], $cashMethodIds);
                $stmt->execute($params);
                $result = $stmt->fetch(PDO::FETCH_ASSOC);
                return $result && $result['total'] !== null ? (float) $result['total'] : 0.0;
            }

            // If we have no configured cash expense methods, fall back to name-only detection
            $stmt = $pdo->prepare("
                SELECT SUM(dcmt_amount) as total
                FROM dcmt_expenses
                WHERE dcmt_expense_date = ? 
                AND dcmt_payment_status = 'paid'
                AND (LOWER(dcmt_payment_method) LIKE '%cash%' OR LOWER(dcmt_payment_method) LIKE '%efectivo%')
            ");
            $stmt->execute([$recordDate]);
            $result = $stmt->fetch(PDO::FETCH_ASSOC);

            return $result && $result['total'] !== null ? (float) $result['total'] : 0.0;
        } catch (PDOException $e) {
            error_log('Cash expense total calculation failed: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('dcmt_build_cashflow_summary')) {
    /**
     * Summarize totals for an array of cashflow rows.
     */
    function dcmt_build_cashflow_summary(array $records): array
    {
        $summary = [
            'days_recorded' => count($records),
            'starting_total' => 0.0,
            'cash_income_total' => 0.0,
            'owner_withdraw_total' => 0.0,
            'ending_total' => 0.0,
            'difference_total' => 0.0,
        ];

        foreach ($records as $row) {
            $summary['starting_total'] += (float) $row['dcmt_starting_amount'];
            $summary['cash_income_total'] += (float) $row['dcmt_cash_income_total'];
            $summary['owner_withdraw_total'] += (float) ($row['dcmt_owner_withdraw_amount'] ?? 0);
            $summary['ending_total'] += (float) $row['dcmt_ending_amount'];
            $summary['difference_total'] += (float) $row['dcmt_difference'];
        }

        return $summary;
    }
}

if (!function_exists('dcmt_fetch_cashflow_denominations')) {
    /**
     * Fetch stored denomination rows for a cashflow record.
     * @param PDO $pdo Database connection
     * @param int $cashflowId Cashflow ID
     * @param string|null $type 'start' or 'end' or null for all
     */
    function dcmt_fetch_cashflow_denominations(PDO $pdo, int $cashflowId, ?string $type = null): array
    {
        try {
            if ($type) {
                $stmt = $pdo->prepare("
                    SELECT dcmt_denomination_label, dcmt_denomination_value, dcmt_quantity, dcmt_total_amount, dcmt_denomination_type
                    FROM dcmt_cashflow_denominations
                    WHERE dcmt_cashflow_id = ? AND dcmt_denomination_type = ?
                    ORDER BY dcmt_denomination_value DESC
                ");
                $stmt->execute([$cashflowId, $type]);
            } else {
                $stmt = $pdo->prepare("
                    SELECT dcmt_denomination_label, dcmt_denomination_value, dcmt_quantity, dcmt_total_amount, dcmt_denomination_type
                    FROM dcmt_cashflow_denominations
                    WHERE dcmt_cashflow_id = ?
                    ORDER BY dcmt_denomination_value DESC
                ");
                $stmt->execute([$cashflowId]);
            }
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('Cashflow denomination fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}

// Legacy dcmt_calculate_doctor_cash_total removed; owner withdraw amount is now stored directly in dcmt_cashflows

if (!function_exists('dcmt_get_previous_cashflow_id')) {
    /**
     * Get the ID of the latest cashflow record before the given date.
     */
    function dcmt_get_previous_cashflow_id(PDO $pdo, string $recordDate): ?int
    {
        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_id 
                FROM dcmt_cashflows 
                WHERE dcmt_record_date < ? 
                ORDER BY dcmt_record_date DESC 
                LIMIT 1
            ");
            $stmt->execute([$recordDate]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ? (int) $row['dcmt_id'] : null;
        } catch (PDOException $e) {
            error_log('Previous cashflow ID lookup failed: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('dcmt_get_aggregated_denominations')) {
    /**
     * Fetch and aggregate 'start' and 'end' denominations for a cashflow ID.
     */
    function dcmt_get_aggregated_denominations(PDO $pdo, int $cashflowId): array
    {
        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_denomination_value, SUM(dcmt_quantity) as total_quantity
                FROM dcmt_cashflow_denominations
                WHERE dcmt_cashflow_id = ?
                GROUP BY dcmt_denomination_value
            ");
            $stmt->execute([$cashflowId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $aggregated = [];
            foreach ($rows as $row) {
                $valueKey = (string) (float) $row['dcmt_denomination_value'];
                $aggregated[$valueKey] = (int) $row['total_quantity'];
            }
            return $aggregated;
        } catch (PDOException $e) {
            error_log('Aggregated denominations fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}
