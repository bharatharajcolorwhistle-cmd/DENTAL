<?php
/**
 * Birthday wish tracking helpers.
 */

if (!function_exists('dcmt_birthday_use_mmdd_col')) {
    function dcmt_birthday_use_mmdd_col(PDO $pdo): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $chk = $pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_birthday_mmdd'");
            $cached = $chk && $chk->rowCount() > 0;
        } catch (PDOException $e) {
            $cached = false;
        }

        return $cached;
    }
}

if (!function_exists('dcmt_birthday_wishes_table_exists')) {
    function dcmt_birthday_wishes_table_exists(PDO $pdo): bool
    {
        static $cached = null;
        if ($cached !== null) {
            return $cached;
        }

        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'dcmt_birthday_wishes'");
            $cached = $chk && $chk->rowCount() > 0;
        } catch (PDOException $e) {
            $cached = false;
        }

        return $cached;
    }
}

if (!function_exists('dcmt_birthday_pending_sql')) {
    /**
     * @return array{join: string, where: string, params: array<int, string|int>}
     */
    function dcmt_birthday_pending_sql(PDO $pdo, ?string $mmdd = null): array
    {
        $mmdd = $mmdd ?? date('m-d');
        $year = (int) date('Y');
        $use_mmdd = dcmt_birthday_use_mmdd_col($pdo);

        $birthday_match = $use_mmdd
            ? "COALESCE(p.dcmt_birthday_mmdd, DATE_FORMAT(p.dcmt_date_of_birth, '%m-%d')) = ?"
            : "DATE_FORMAT(p.dcmt_date_of_birth, '%m-%d') = ?";

        $join = '';
        $where = "p.dcmt_date_of_birth IS NOT NULL
            AND p.dcmt_status = 'active'
            AND {$birthday_match}";

        $params = [];

        if (dcmt_birthday_wishes_table_exists($pdo)) {
            $join = "
                LEFT JOIN dcmt_birthday_wishes bw
                    ON bw.dcmt_patient_id = p.dcmt_id
                   AND bw.dcmt_wish_year = ?
            ";
            $where .= '
                AND bw.dcmt_id IS NULL';
            $params[] = $year;
        }

        $params[] = $mmdd;

        return [
            'join' => $join,
            'where' => $where,
            'params' => $params,
        ];
    }
}

if (!function_exists('dcmt_get_pending_birthday_count')) {
    function dcmt_get_pending_birthday_count(PDO $pdo, ?string $mmdd = null): int
    {
        try {
            $parts = dcmt_birthday_pending_sql($pdo, $mmdd);
            $stmt = $pdo->prepare("
                SELECT COUNT(*)
                FROM dcmt_patients p
                {$parts['join']}
                WHERE {$parts['where']}
            ");
            $stmt->execute($parts['params']);

            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('dcmt_get_pending_birthday_count: ' . $e->getMessage());

            return 0;
        }
    }
}

if (!function_exists('dcmt_get_acknowledged_birthday_patient_ids')) {
    /**
     * @param array<int, int> $patient_ids
     * @return array<int, int>
     */
    function dcmt_get_acknowledged_birthday_patient_ids(PDO $pdo, array $patient_ids, ?int $year = null): array
    {
        if (!dcmt_birthday_wishes_table_exists($pdo) || $patient_ids === []) {
            return [];
        }

        $year = $year ?? (int) date('Y');
        $patient_ids = array_values(array_unique(array_map('intval', $patient_ids)));
        $patient_ids = array_filter($patient_ids, static fn(int $id): bool => $id > 0);

        if ($patient_ids === []) {
            return [];
        }

        $placeholders = implode(',', array_fill(0, count($patient_ids), '?'));
        $params = array_merge([$year], $patient_ids);

        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_patient_id
                FROM dcmt_birthday_wishes
                WHERE dcmt_wish_year = ?
                  AND dcmt_patient_id IN ({$placeholders})
            ");
            $stmt->execute($params);

            return array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));
        } catch (PDOException $e) {
            error_log('dcmt_get_acknowledged_birthday_patient_ids: ' . $e->getMessage());

            return [];
        }
    }
}

if (!function_exists('dcmt_patient_has_birthday_today')) {
    function dcmt_patient_has_birthday_today(array $patient, ?string $mmdd = null): bool
    {
        $mmdd = $mmdd ?? date('m-d');

        if (!empty($patient['dcmt_birthday_mmdd'])) {
            return (string) $patient['dcmt_birthday_mmdd'] === $mmdd;
        }

        if (empty($patient['dcmt_date_of_birth'])) {
            return false;
        }

        try {
            $dob = new DateTime((string) $patient['dcmt_date_of_birth']);

            return $dob->format('m-d') === $mmdd;
        } catch (Exception $e) {
            return false;
        }
    }
}

if (!function_exists('dcmt_acknowledge_birthday_wish')) {
    function dcmt_acknowledge_birthday_wish(PDO $pdo, int $patient_id, ?array $current_user = null): array
    {
        if (!dcmt_birthday_wishes_table_exists($pdo)) {
            return ['success' => false, 'message' => trans('patient', 'database_error')];
        }

        if ($patient_id <= 0) {
            return ['success' => false, 'message' => trans('patient', 'invalid_id')];
        }

        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_id, dcmt_date_of_birth, dcmt_birthday_mmdd, dcmt_status
                FROM dcmt_patients
                WHERE dcmt_id = ?
            ");
            $stmt->execute([$patient_id]);
            $patient = $stmt->fetch(PDO::FETCH_ASSOC);

            if (!$patient) {
                return ['success' => false, 'message' => trans('patient', 'not_found')];
            }

            if (($patient['dcmt_status'] ?? '') !== 'active') {
                return ['success' => false, 'message' => trans('patient', 'not_found')];
            }

            if (!dcmt_patient_has_birthday_today($patient)) {
                return ['success' => false, 'message' => trans('patient', 'invalid_id')];
            }

            $year = (int) date('Y');
            $user_id = isset($current_user['dcmt_id']) ? (int) $current_user['dcmt_id'] : null;

            $insert = $pdo->prepare("
                INSERT INTO dcmt_birthday_wishes (dcmt_patient_id, dcmt_wish_year, dcmt_sent_by_user_id)
                VALUES (?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    dcmt_sent_at = CURRENT_TIMESTAMP,
                    dcmt_sent_by_user_id = VALUES(dcmt_sent_by_user_id)
            ");
            $insert->execute([$patient_id, $year, $user_id]);

            $remaining_count = dcmt_get_pending_birthday_count($pdo);
            $alert_key = $remaining_count === 1 ? 'birthday_header_alert_one' : 'birthday_header_alert_many';

            return [
                'success' => true,
                'message' => trans('patient', 'birthday_wish_sent'),
                'remaining_count' => $remaining_count,
                'alert_message' => str_replace('{count}', (string) $remaining_count, trans('patient', $alert_key)),
            ];
        } catch (PDOException $e) {
            error_log('dcmt_acknowledge_birthday_wish: ' . $e->getMessage());

            return ['success' => false, 'message' => trans('patient', 'database_error')];
        }
    }
}
