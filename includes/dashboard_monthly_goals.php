<?php
/**
 * Dashboard monthly goals data for the financial tab.
 */

if (!function_exists('dcmt_dashboard_monthly_goals_data')) {
    /**
     * @return array{
     *   summary: array,
     *   entries: list<array{
     *     user_id: int,
     *     full_name: string,
     *     role: string,
     *     metric: string,
     *     goal_amount: float,
     *     actual: float,
     *     progress_percent: float,
     *     progress_bar_width: float
     *   }>,
     *   product_goal: array{
     *     has_goal: bool,
     *     goal_amount: float,
     *     actual: float,
     *     remaining: float,
     *     progress_percent: float,
     *     progress_bar_width: float,
     *     month_ended: bool
     *   },
     *   has_goals: bool
     * }
     */
    function dcmt_dashboard_monthly_goals_data(PDO $pdo, string $goalMonth, ?int $limitedUserId = null): array
    {
        require_once __DIR__ . '/doctor_goal_functions.php';

        $goalMonth = dcmt_goal_normalize_month($goalMonth);
        $doctorFilter = ($limitedUserId !== null && $limitedUserId > 0) ? [$limitedUserId] : [];
        $goalMap = dcmt_fetch_doctor_goals_map($pdo, $goalMonth, $doctorFilter);

        // Clinic-wide product sales goal. Clinic totals are only exposed on the
        // unrestricted (admin/owner) dashboard; limited doctors see their own goals only.
        $productGoal = [
            'has_goal' => false,
            'goal_amount' => 0.0,
            'actual' => 0.0,
            'remaining' => 0.0,
            'progress_percent' => 0.0,
            'progress_bar_width' => 0.0,
            'month_ended' => false,
        ];
        if ($limitedUserId === null || $limitedUserId <= 0) {
            $productGoal = dcmt_clinic_goal_progress($pdo, $goalMonth, 'product_sales');
        }

        $empty = [
            'summary' => [
                'total_doctors' => 0,
                'goals_met' => 0,
                'total_goal_amount' => 0.0,
                'total_actual_amount' => 0.0,
                'total_goal_appointments' => 0.0,
                'total_actual_appointments' => 0.0,
            ],
            'entries' => [],
            'product_goal' => $productGoal,
            'has_goals' => false,
        ];

        if (empty($goalMap) && !$productGoal['has_goal']) {
            return $empty;
        }

        $summary = dcmt_get_doctor_goal_dashboard_summary($pdo, $goalMonth);
        if ($limitedUserId !== null && $limitedUserId > 0) {
            $userIds = array_map('intval', array_keys($goalMap));
            $summary = [
                'total_doctors' => count($userIds),
                'goals_met' => 0,
                'total_goal_amount' => 0.0,
                'total_actual_amount' => 0.0,
                'total_goal_appointments' => 0.0,
                'total_actual_appointments' => 0.0,
            ];
        }

        $userIds = array_map('intval', array_keys($goalMap));
        $usersById = [];
        if (!empty($userIds)) {
            $placeholders = implode(',', array_fill(0, count($userIds), '?'));
            $userStmt = $pdo->prepare("
                SELECT dcmt_id, dcmt_full_name, dcmt_role
                FROM dcmt_users
                WHERE dcmt_id IN ($placeholders)
            ");
            $userStmt->execute($userIds);
            foreach ($userStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $usersById[(int) $row['dcmt_id']] = $row;
            }
        }

        $userRoles = [];
        foreach ($goalMap as $userId => $goalRow) {
            $userId = (int) $userId;
            $dbRole = (string) ($usersById[$userId]['dcmt_role'] ?? 'doctor');
            $metric = $goalRow['dcmt_goal_metric'] ?? 'income';
            if ($metric === 'appointments' || in_array($dbRole, ['staff', 'assistant'], true)) {
                $userRoles[$userId] = $dbRole === 'assistant' ? 'assistant' : 'staff';
            } else {
                $userRoles[$userId] = 'doctor';
            }
        }

        $actuals = dcmt_fetch_mixed_goal_actuals($pdo, $goalMonth, $userRoles);

        $entries = [];
        $goalsMet = 0;
        $totalGoalIncome = 0.0;
        $totalActualIncome = 0.0;
        $totalGoalAppts = 0.0;
        $totalActualAppts = 0.0;

        foreach ($goalMap as $userId => $goalRow) {
            $userId = (int) $userId;
            $user = $usersById[$userId] ?? null;
            if ($user === null) {
                continue;
            }

            $goalAmount = (float) ($goalRow['dcmt_goal_amount'] ?? 0);
            $metric = $goalRow['dcmt_goal_metric'] ?? 'income';
            $dbRole = (string) ($user['dcmt_role'] ?? 'doctor');
            if (in_array($dbRole, ['staff', 'assistant'], true)) {
                $metric = 'appointments';
            }

            $actual = (float) ($actuals[$userId] ?? 0.0);
            $progressPercent = ($goalAmount > 0) ? (($actual / $goalAmount) * 100) : 0.0;
            $progressBarWidth = min(100, max(0, $progressPercent));

            if ($goalAmount > 0 && $actual >= $goalAmount) {
                $goalsMet++;
            }

            if ($metric === 'appointments') {
                $totalGoalAppts += $goalAmount;
                $totalActualAppts += $actual;
            } else {
                $totalGoalIncome += $goalAmount;
                $totalActualIncome += $actual;
            }

            $entries[] = [
                'user_id' => $userId,
                'full_name' => (string) ($user['dcmt_full_name'] ?? ''),
                'role' => $dbRole,
                'metric' => $metric,
                'goal_amount' => $goalAmount,
                'actual' => $actual,
                'progress_percent' => $progressPercent,
                'progress_bar_width' => $progressBarWidth,
            ];
        }

        usort($entries, static function (array $a, array $b): int {
            $roleOrder = ['doctor' => 0, 'staff' => 1, 'assistant' => 2];
            $aRole = $roleOrder[$a['role']] ?? 9;
            $bRole = $roleOrder[$b['role']] ?? 9;
            if ($aRole !== $bRole) {
                return $aRole <=> $bRole;
            }

            return strcasecmp($a['full_name'], $b['full_name']);
        });

        if ($limitedUserId !== null && $limitedUserId > 0) {
            $summary = [
                'total_doctors' => count($entries),
                'goals_met' => $goalsMet,
                'total_goal_amount' => $totalGoalIncome,
                'total_actual_amount' => $totalActualIncome,
                'total_goal_appointments' => $totalGoalAppts,
                'total_actual_appointments' => $totalActualAppts,
            ];
        }

        return [
            'summary' => $summary,
            'entries' => $entries,
            'product_goal' => $productGoal,
            'has_goals' => !empty($entries) || $productGoal['has_goal'],
        ];
    }
}
