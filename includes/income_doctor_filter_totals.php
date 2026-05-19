<?php
/**
 * Doctor income totals for a date range using the same rules as pages/income/index.php
 * (payment history scoped by income filters / transaction date for paid; same for pending; breakdown ratio).
 * Keep in sync when income index total logic changes.
 */

if (!function_exists('dcmt_income_doctor_period_total_like_index')) {
    /**
     * Total income (paid + pending) for one doctor and transaction-date range,
     * matching the income list header when filtering by that doctor and date range.
     *
     * @param string|null $lineTypeFilter Optional 'service' or 'product' to match income index line-type filter.
     */
    function dcmt_income_doctor_period_total_like_index(
        PDO $pdo,
        int $doctorId,
        string $dateFrom,
        string $dateTo,
        ?string $lineTypeFilter = null
    ): float {
        if ($doctorId <= 0 || $dateFrom === '' || $dateTo === '') {
            return 0.0;
        }

        $type_filter = '';
        $has_line_type_filter = false;
        if ($lineTypeFilter === 'service' || $lineTypeFilter === 'product') {
            $type_filter = $lineTypeFilter;
            $has_line_type_filter = true;
        }

        $doctor_filter = (string) $doctorId;

        $where_conditions = [];
        $params = [];

        if ($has_line_type_filter) {
            if ($type_filter === 'service') {
                $legacy_amount_condition = "(COALESCE(i.dcmt_service_amount, 0) > 0 OR COALESCE(i.dcmt_service_pending_amount, 0) > 0 OR COALESCE(i.dcmt_service_paid_amount, 0) > 0)";
            } else {
                $legacy_amount_condition = "(COALESCE(i.dcmt_product_amount, 0) > 0 OR COALESCE(i.dcmt_product_pending_amount, 0) > 0 OR COALESCE(i.dcmt_product_paid_amount, 0) > 0)";
            }

            $where_conditions[] = "(
                EXISTS (
                    SELECT 1 FROM dcmt_income_breakdown ib
                    WHERE ib.dcmt_id = i.dcmt_id AND ib.dcmt_line_type = ?
                )
                OR (
                    NOT EXISTS (
                        SELECT 1 FROM dcmt_income_breakdown ib_any
                        WHERE ib_any.dcmt_id = i.dcmt_id
                    )
                    AND $legacy_amount_condition
                )
            )";
            $params[] = $type_filter;
        }

        if ($has_line_type_filter && $type_filter === 'product') {
            $where_conditions[] = "EXISTS (
                SELECT 1 FROM dcmt_income_breakdown ib
                WHERE ib.dcmt_id = i.dcmt_id
                  AND ib.dcmt_line_type = 'product'
                  AND ib.dcmt_user_id = ?
            )";
            $params[] = $doctor_filter;
        } elseif ($has_line_type_filter) {
            $where_conditions[] = "(i.dcmt_user_id = ? OR EXISTS (
                SELECT 1 FROM dcmt_income_breakdown ib
                WHERE ib.dcmt_id = i.dcmt_id
                  AND ib.dcmt_line_type = ?
                  AND ib.dcmt_user_id = ?
            ))";
            $params[] = $doctor_filter;
            $params[] = $type_filter;
            $params[] = $doctor_filter;
        } else {
            $where_conditions[] = "(i.dcmt_user_id = ? OR EXISTS (
                SELECT 1 FROM dcmt_income_breakdown ib
                WHERE ib.dcmt_id = i.dcmt_id AND ib.dcmt_user_id = ?
            ))";
            $params[] = $doctor_filter;
            $params[] = $doctor_filter;
        }

        $where_conditions[] = "i.dcmt_transaction_date >= ?";
        $params[] = $dateFrom;

        $where_conditions[] = "i.dcmt_transaction_date <= ?";
        $params[] = $dateTo;

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        $base_params = $params;

        if ($type_filter === 'service') {
            $paid_amount_expression = 'COALESCE(i.dcmt_service_paid_amount, 0)';
            $pending_amount_expression = 'COALESCE(i.dcmt_service_pending_amount, 0)';
        } elseif ($type_filter === 'product') {
            $paid_amount_expression = 'COALESCE(i.dcmt_product_paid_amount, 0)';
            $pending_amount_expression = 'COALESCE(i.dcmt_product_pending_amount, 0)';
        } else {
            $paid_amount_expression = "CASE
                WHEN i.dcmt_type IN ('consultation', 'product_sale') THEN i.dcmt_total_paid_amount
                ELSE i.dcmt_paid_amount
            END";
            $pending_amount_expression = "CASE
                WHEN i.dcmt_type IN ('consultation', 'product_sale') THEN i.dcmt_total_pending_amount
                ELSE i.dcmt_pending_amount
            END";
        }

        $breakdown_type_where = $has_line_type_filter ? 'WHERE ib.dcmt_line_type = ?' : '';
        $breakdown_join = "
        LEFT JOIN (
            SELECT
                ib.dcmt_id as income_id,
                COALESCE(SUM(ib.dcmt_line_total), 0) as total_line_total,
                COALESCE(SUM(CASE WHEN ib.dcmt_user_id = ? THEN ib.dcmt_line_total ELSE 0 END), 0) as doctor_line_total
            FROM dcmt_income_breakdown ib
            $breakdown_type_where
            GROUP BY ib.dcmt_id
        ) ibs ON ibs.income_id = i.dcmt_id
    ";

        $doctor_ratio_expression = "(
        CASE
            WHEN COALESCE(ibs.total_line_total, 0) > 0 THEN
                CASE
                    WHEN COALESCE(ibs.doctor_line_total, 0) > 0 THEN COALESCE(ibs.doctor_line_total, 0) / NULLIF(COALESCE(ibs.total_line_total, 0), 0)
                    WHEN i.dcmt_user_id = ? THEN 1
                    ELSE 0
                END
            WHEN i.dcmt_user_id = ? THEN 1
            ELSE 0
        END
    )";

        $doctor_ratio_expression_params = [(int) $doctor_filter, (int) $doctor_filter];
        $doctor_breakdown_join_params = [(int) $doctor_filter];
        if ($has_line_type_filter) {
            $doctor_breakdown_join_params[] = $type_filter;
        }

        if ($has_line_type_filter) {
            $doctor_total_sql = "
                SELECT COALESCE(SUM(($paid_amount_expression) * $doctor_ratio_expression), 0) as total
                FROM dcmt_income i
                LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
                LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
                LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
                LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
                $breakdown_join
                $where_clause
            ";
            $doctor_total_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params);
        } else {
            $doctor_total_sql = "
                SELECT COALESCE(SUM(iph.dcmt_amount * $doctor_ratio_expression), 0) as total
                FROM dcmt_income_payment_history iph
                INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
                LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
                LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
                LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
                LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
                $breakdown_join
                $where_clause
            ";
            $doctor_total_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params);
        }

        $stmt = $pdo->prepare($doctor_total_sql);
        $stmt->execute($doctor_total_params);
        $total_paid_income = (float) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        $pending_sql = "
        SELECT COALESCE(SUM(($pending_amount_expression) * $doctor_ratio_expression), 0) as total
        FROM dcmt_income i
        LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
        LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
        $breakdown_join
        $where_clause
    ";
        $pending_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params);
        $stmt = $pdo->prepare($pending_sql);
        $stmt->execute($pending_params);
        $total_pending_income = (float) $stmt->fetch(PDO::FETCH_ASSOC)['total'];

        return $total_paid_income + $total_pending_income;
    }
}
