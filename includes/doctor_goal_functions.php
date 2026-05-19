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
     * Fetch doctor service income for the specified goal month (paid + pending).
     * Uses the same rules as Income index with doctor + date-range + line type "service".
     */
    function dcmt_fetch_doctor_goal_actuals(PDO $pdo, string $goalMonth, array $doctorIds): array
    {
        if (empty($doctorIds)) {
            return [];
        }

        require_once __DIR__ . '/income_doctor_filter_totals.php';

        try {
            [$start, $endExclusive] = dcmt_goal_month_bounds($goalMonth);
            $dateFrom = $start;
            $dateTo = date('Y-m-d', strtotime($endExclusive . ' -1 day'));

            $map = [];
            foreach ($doctorIds as $doctorId) {
                $doctorId = (int) $doctorId;
                $map[$doctorId] = dcmt_income_doctor_period_total_like_index($pdo, $doctorId, $dateFrom, $dateTo, 'service');
            }

            return $map;
        } catch (PDOException $e) {
            error_log('Doctor goal actuals fetch failed: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('dcmt_fetch_staff_goal_appointment_counts')) {
    /**
     * Progress toward monthly appointment-style goals by user.
     *
     * @param array<int, string> $userIdToRole user id => 'staff'|'assistant'
     *        Staff: counts appointments with status completed in the goal month
     *        (by completion time COALESCE(dcmt_actual_end_at, dcmt_start_at); dcmt_created_by).
     *        Assistant: interim count of non-cancelled appointments created by the user
     *        with start time in the goal month (Google reviews metric to be wired later).
     */
    function dcmt_fetch_staff_goal_appointment_counts(PDO $pdo, string $goalMonth, array $userIdToRole): array
    {
        if (empty($userIdToRole)) {
            return [];
        }

        [$start, $endExclusive] = dcmt_goal_month_bounds($goalMonth);

        $map = [];
        foreach ($userIdToRole as $uid => $_role) {
            $uid = (int) $uid;
            if ($uid > 0) {
                $map[$uid] = 0.0;
            }
        }

        $staffIds = [];
        $assistantIds = [];
        foreach ($userIdToRole as $uid => $role) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            if ($role === 'assistant') {
                $assistantIds[] = $uid;
            } else {
                $staffIds[] = $uid;
            }
        }

        try {
            if (!empty($staffIds)) {
                $placeholders = implode(',', array_fill(0, count($staffIds), '?'));
                $sql = "
                    SELECT dcmt_created_by AS user_id, COUNT(*) AS cnt
                    FROM dcmt_appointments
                    WHERE dcmt_created_by IN ($placeholders)
                    AND dcmt_status = 'completed'
                    AND COALESCE(dcmt_actual_end_at, dcmt_start_at) >= ?
                    AND COALESCE(dcmt_actual_end_at, dcmt_start_at) < ?
                    GROUP BY dcmt_created_by
                ";
                $params = array_merge($staffIds, [$start, $endExclusive]);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $uid = (int) ($row['user_id'] ?? 0);
                    if ($uid > 0) {
                        $map[$uid] = (float) ($row['cnt'] ?? 0);
                    }
                }
            }

            if (!empty($assistantIds)) {
                $placeholders = implode(',', array_fill(0, count($assistantIds), '?'));
                $sql = "
                    SELECT dcmt_created_by AS user_id, COUNT(*) AS cnt
                    FROM dcmt_appointments
                    WHERE dcmt_created_by IN ($placeholders)
                    AND dcmt_start_at >= ?
                    AND dcmt_start_at < ?
                    AND dcmt_status NOT IN ('cancelled')
                    GROUP BY dcmt_created_by
                ";
                $params = array_merge($assistantIds, [$start, $endExclusive]);
                $stmt = $pdo->prepare($sql);
                $stmt->execute($params);
                foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                    $uid = (int) ($row['user_id'] ?? 0);
                    if ($uid > 0) {
                        $map[$uid] = (float) ($row['cnt'] ?? 0);
                    }
                }
            }

            return $map;
        } catch (PDOException $e) {
            error_log('Staff goal appointment counts fetch failed: ' . $e->getMessage());
            return $map;
        }
    }
}

if (!function_exists('dcmt_fetch_mixed_goal_actuals')) {
    /**
     * Actual progress for monthly goals: income totals for doctors; staff vs assistant appointment rules.
     *
     * @param array<int, string> $userRoles user id => 'doctor'|'staff'|'assistant'
     * @return array<int, float> user id => actual value
     */
    function dcmt_fetch_mixed_goal_actuals(PDO $pdo, string $goalMonth, array $userRoles): array
    {
        $doctorIds = [];
        $appointmentUsers = [];
        foreach ($userRoles as $uid => $role) {
            $uid = (int) $uid;
            if ($uid <= 0) {
                continue;
            }
            if ($role === 'staff' || $role === 'assistant') {
                $appointmentUsers[$uid] = $role;
            } else {
                $doctorIds[] = $uid;
            }
        }

        $out = [];
        if (!empty($doctorIds)) {
            $income = dcmt_fetch_doctor_goal_actuals($pdo, $goalMonth, $doctorIds);
            foreach ($income as $k => $v) {
                $out[(int) $k] = (float) $v;
            }
        }
        if (!empty($appointmentUsers)) {
            $counts = dcmt_fetch_staff_goal_appointment_counts($pdo, $goalMonth, $appointmentUsers);
            foreach ($counts as $k => $v) {
                $out[(int) $k] = (float) $v;
            }
        }

        return $out;
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
    /**
     * @param string $goalMetric 'income' (doctors) or 'appointments' (staff/assistant)
     */
    function dcmt_upsert_doctor_goal(PDO $pdo, int $doctorId, string $goalMonth, float $goalAmount, string $username, ?string $notes = null, string $goalMetric = 'income'): void
    {
        $metric = $goalMetric === 'appointments' ? 'appointments' : 'income';

        $stmt = $pdo->prepare("
            INSERT INTO dcmt_doctor_goals (dcmt_user_id, dcmt_goal_month, dcmt_goal_amount, dcmt_goal_metric, dcmt_notes, dcmt_created_by, dcmt_updated_by)
            VALUES (?, ?, ?, ?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE
                dcmt_goal_amount = VALUES(dcmt_goal_amount),
                dcmt_goal_metric = VALUES(dcmt_goal_metric),
                dcmt_notes = VALUES(dcmt_notes),
                dcmt_updated_by = VALUES(dcmt_updated_by),
                dcmt_updated_at = CURRENT_TIMESTAMP
        ");

        $stmt->execute([
            $doctorId,
            $goalMonth,
            $goalAmount,
            $metric,
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
                'total_actual_amount' => 0,
                'total_goal_appointments' => 0,
                'total_actual_appointments' => 0
            ];
        }

        $goalUserIds = array_map('intval', array_keys($goals));
        $roleByUserId = [];
        if (!empty($goalUserIds)) {
            $placeholders = implode(',', array_fill(0, count($goalUserIds), '?'));
            $roleStmt = $pdo->prepare("SELECT dcmt_id, dcmt_role FROM dcmt_users WHERE dcmt_id IN ($placeholders)");
            $roleStmt->execute($goalUserIds);
            foreach ($roleStmt->fetchAll(PDO::FETCH_ASSOC) as $ur) {
                $roleByUserId[(int) $ur['dcmt_id']] = (string) ($ur['dcmt_role'] ?? 'doctor');
            }
        }

        $userRoles = [];
        foreach ($goals as $userId => $goalRow) {
            $userId = (int) $userId;
            $metric = $goalRow['dcmt_goal_metric'] ?? 'income';
            if ($metric !== 'appointments') {
                $userRoles[$userId] = 'doctor';
                continue;
            }
            $dbRole = $roleByUserId[$userId] ?? 'doctor';
            if ($dbRole === 'assistant') {
                $userRoles[$userId] = 'assistant';
            } elseif ($dbRole === 'staff') {
                $userRoles[$userId] = 'staff';
            } else {
                $userRoles[$userId] = 'doctor';
            }
        }

        $actuals = dcmt_fetch_mixed_goal_actuals($pdo, $goalMonth, $userRoles);

        $goalsMet = 0;
        $totalGoalIncome = 0;
        $totalActualIncome = 0;
        $totalGoalAppts = 0;
        $totalActualAppts = 0;

        foreach ($goals as $userId => $goalRow) {
            $goalAmount = (float) $goalRow['dcmt_goal_amount'];
            $metric = $goalRow['dcmt_goal_metric'] ?? 'income';
            $actual = $actuals[$userId] ?? 0.0;

            if ($metric === 'appointments') {
                $totalGoalAppts += $goalAmount;
                $totalActualAppts += $actual;
            } else {
                $totalGoalIncome += $goalAmount;
                $totalActualIncome += $actual;
            }

            if ($goalAmount > 0 && $actual >= $goalAmount) {
                $goalsMet++;
            }
        }

        $userIds = array_keys($goals);

        return [
            'total_doctors' => count($userIds),
            'goals_met' => $goalsMet,
            'total_goal_amount' => $totalGoalIncome,
            'total_actual_amount' => $totalActualIncome,
            'total_goal_appointments' => $totalGoalAppts,
            'total_actual_appointments' => $totalActualAppts
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

