<?php
/**
 * Doctor Goals Helper Functions
 * Dental Clinic Management System
 */

if (!function_exists('dcmt_goal_normalize_month')) {
    /**
     * Normalize month input to YYYY-MM-01 format.
     */
    function dcmt_goal_normalize_month(?string $input = null): string
    {
        if (empty($input)) {
            return date('Y-m-01');
        }

        // Accept YYYY-MM or any date string
        if (preg_match('/^\d{4}-\d{2}$/', $input)) {
            $input .= '-01';
        }

        $timestamp = strtotime($input);
        if ($timestamp === false) {
            return date('Y-m-01');
        }

        return date('Y-m-01', $timestamp);
    }
}

if (!function_exists('dcmt_goal_month_bounds')) {
    /**
     * Return start (inclusive) and end (exclusive) dates for a goal month.
     */
    function dcmt_goal_month_bounds(string $goalMonth): array
    {
        $start = dcmt_goal_normalize_month($goalMonth);
        $end = date('Y-m-01', strtotime($start . ' +1 month'));
        return [$start, $end];
    }
}

if (!function_exists('dcmt_fetch_doctor_goals_map')) {
    /**
     * Fetch doctor goals for the provided month. Optional doctor filter.
     */
    function dcmt_fetch_doctor_goals_map(PDO $pdo, string $goalMonth, array $doctorIds = []): array
    {
        try {
            $sql = "SELECT * FROM dcmt_doctor_goals WHERE dcmt_goal_month = ?";
            $params = [$goalMonth];

            if (!empty($doctorIds)) {
                $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));
                $sql .= " AND dcmt_user_id IN ($placeholders)";
                $params = array_merge($params, $doctorIds);
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row['dcmt_user_id']] = $row;
            }

            return $map;
        } catch (PDOException $e) {
            error_log('Doctor goals fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('dcmt_fetch_doctor_goal_actuals')) {
    /**
     * Fetch doctor earnings (service share) for the specified month.
     */
    function dcmt_fetch_doctor_goal_actuals(PDO $pdo, string $goalMonth, array $doctorIds): array
    {
        if (empty($doctorIds)) {
            return [];
        }

        try {
            [$start, $end] = dcmt_goal_month_bounds($goalMonth);
            $placeholders = implode(',', array_fill(0, count($doctorIds), '?'));

            $sql = "
                SELECT 
                    ib.dcmt_user_id AS doctor_id,
                    COALESCE(SUM(
                        CASE 
                            WHEN COALESCE(i.dcmt_service_amount, 0) > 0 
                                THEN ib.dcmt_line_total * (COALESCE(i.dcmt_service_paid_amount, 0) / NULLIF(i.dcmt_service_amount, 0))
                            ELSE 0
                        END
                    ), 0) AS total_earned
                FROM dcmt_income_breakdown ib
                INNER JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
                WHERE ib.dcmt_line_type = 'service'
                  AND ib.dcmt_user_id IN ($placeholders)
                  AND (i.dcmt_type = 'consultation' OR i.dcmt_type = 'mixed')
                  AND i.dcmt_transaction_date >= ? AND i.dcmt_transaction_date < ?
                GROUP BY ib.dcmt_user_id
            ";

            $params = array_merge($doctorIds, [$start, $end]);
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

            $map = [];
            foreach ($rows as $row) {
                $map[(int) $row['doctor_id']] = (float) $row['total_earned'];
            }

            return $map;
        } catch (PDOException $e) {
            error_log('Doctor goal actuals fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('dcmt_get_doctor_goal_details')) {
    function dcmt_get_doctor_goal_details(PDO $pdo, int $doctorId, string $goalMonth): ?array
    {
        $stmt = $pdo->prepare("SELECT * FROM dcmt_doctor_goals WHERE dcmt_user_id = ? AND dcmt_goal_month = ?");
        $stmt->execute([$doctorId, $goalMonth]);
        $goal = $stmt->fetch(PDO::FETCH_ASSOC);
        return $goal ?: null;
    }
}

if (!function_exists('dcmt_upsert_doctor_goal')) {
    function dcmt_upsert_doctor_goal(PDO $pdo, int $doctorId, string $goalMonth, float $goalAmount, string $username, ?string $notes = null): void
    {
        $stmt = $pdo->prepare("
            INSERT INTO dcmt_doctor_goals (dcmt_user_id, dcmt_goal_month, dcmt_goal_amount, dcmt_notes, dcmt_created_by, dcmt_updated_by)
            VALUES (?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                dcmt_goal_amount = VALUES(dcmt_goal_amount),
                dcmt_notes = VALUES(dcmt_notes),
                dcmt_updated_by = VALUES(dcmt_updated_by),
                dcmt_updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $doctorId,
            $goalMonth,
            $goalAmount,
            $notes,
            $username,
            $username
        ]);
    }
}

if (!function_exists('dcmt_delete_doctor_goal')) {
    function dcmt_delete_doctor_goal(PDO $pdo, int $doctorId, string $goalMonth): void
    {
        $stmt = $pdo->prepare("DELETE FROM dcmt_doctor_goals WHERE dcmt_user_id = ? AND dcmt_goal_month = ?");
        $stmt->execute([$doctorId, $goalMonth]);
    }
}

if (!function_exists('dcmt_get_doctor_goal_dashboard_summary')) {
    function dcmt_get_doctor_goal_dashboard_summary(PDO $pdo, string $goalMonth): array
    {
        $goals = dcmt_fetch_doctor_goals_map($pdo, $goalMonth);
        if (empty($goals)) {
            return [
                'total_doctors' => 0,
                'goals_met' => 0,
                'total_goal_amount' => 0,
                'total_actual_amount' => 0
            ];
        }

        $doctorIds = array_keys($goals);
        $actuals = dcmt_fetch_doctor_goal_actuals($pdo, $goalMonth, $doctorIds);

        $goalsMet = 0;
        $totalGoal = 0;
        $totalActual = 0;

        foreach ($goals as $doctorId => $goalRow) {
            $goalAmount = (float) $goalRow['dcmt_goal_amount'];
            $actual = $actuals[$doctorId] ?? 0.0;

            $totalGoal += $goalAmount;
            $totalActual += $actual;

            if ($goalAmount > 0 && $actual >= $goalAmount) {
                $goalsMet++;
            }
        }

        return [
            'total_doctors' => count($doctorIds),
            'goals_met' => $goalsMet,
            'total_goal_amount' => $totalGoal,
            'total_actual_amount' => $totalActual
        ];
    }
}

if (!function_exists('dcmt_goal_month_input_value')) {
    /**
     * Helper to convert normalized date to value for month inputs (YYYY-MM).
     */
    function dcmt_goal_month_input_value(string $goalMonth): string
    {
        return date('Y-m', strtotime($goalMonth));
    }
}

