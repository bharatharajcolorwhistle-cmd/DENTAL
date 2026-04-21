<?php
/**
 * Doctor income totals for a date range using the same rules as pages/income/index.php
 * (payment history + dcmt_paid_on for paid; transaction date for pending; breakdown ratio).
 * Keep in sync when income index total logic changes.
 */

if (!function_exists('dcmt_income_doctor_period_total_like_index')) {
    /**
     * Total income (paid + pending) for one doctor and transaction-date range,
     * matching the income list header when filtering by that doctor and date range.
     */
    function dcmt_income_doctor_period_total_like_index(PDO $pdo, int $doctorId, string $dateFrom, string $dateTo): float
    {
        if ($doctorId <= 0 || $dateFrom === '' || $dateTo === '') {
            return 0.0;
        }

        $type_filter = '';
        $has_line_type_filter = false;
        $doctor_filter = (string) $doctorId;

        $where_conditions = [];
        $params = [];

        $where_conditions[] = "(i.dcmt_user_id = ? OR EXISTS (
            SELECT 1 FROM dcmt_income_breakdown ib 
            WHERE ib.dcmt_id = i.dcmt_id AND ib.dcmt_user_id = ?
        ))";
        $params[] = $doctor_filter;
        $params[] = $doctor_filter;

        $where_conditions[] = "i.dcmt_transaction_date >= ?";
        $params[] = $dateFrom;

        $where_conditions[] = "i.dcmt_transaction_date <= ?";
        $params[] = $dateTo;

        $where_clause = 'WHERE ' . implode(' AND ', $where_conditions);
        $base_params = $params;

        $pending_amount_expression = "CASE 
            WHEN i.dcmt_type IN ('consultation', 'product_sale') THEN i.dcmt_total_pending_amount
            ELSE i.dcmt_pending_amount
        END";

        $breakdown_type_where = '';
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

        $paid_where_clause = $where_clause;
        if ($dateFrom !== '') {
            $paid_where_clause = str_replace("i.dcmt_transaction_date >= ?", "iph.dcmt_paid_on >= ?", $paid_where_clause);
        }
        if ($dateTo !== '') {
            $paid_where_clause = str_replace("i.dcmt_transaction_date <= ?", "iph.dcmt_paid_on <= ?", $paid_where_clause);
        }

        $doctor_total_sql = "
                SELECT COALESCE(SUM(iph.dcmt_amount * $doctor_ratio_expression), 0) as total
                FROM dcmt_income_payment_history iph
                INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
                LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
                LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
                LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
                LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
                $breakdown_join
                $paid_where_clause
            ";
        $doctor_total_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params);

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
