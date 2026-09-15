<?php
/**
 * Patient advance (prepaid credit) helpers.
 *
 * Advances are a patient liability, not clinic revenue. Revenue is recognized
 * only when an advance is applied to a billed income record.
 */

if (!defined('DCMT_PATIENT_ADVANCE_PAYMENT_SOURCE')) {
    define('DCMT_PATIENT_ADVANCE_PAYMENT_SOURCE', 'patient_advance');
}

if (!function_exists('dcmt_patient_advance_money')) {
    function dcmt_patient_advance_money($amount): float
    {
        return round((float) $amount, 2);
    }
}

if (!function_exists('dcmt_patient_advance_tables_exist')) {
    function dcmt_patient_advance_tables_exist(PDO $pdo): bool
    {
        try {
            $advances = $pdo->query("SHOW TABLES LIKE 'dcmt_patient_advances'");
            $apps = $pdo->query("SHOW TABLES LIKE 'dcmt_patient_advance_applications'");
            return $advances && $advances->rowCount() > 0 && $apps && $apps->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }
}

if (!function_exists('dcmt_patient_advance_ensure_tables')) {
    function dcmt_patient_advance_ensure_tables(PDO $pdo): void
    {
        global $dcmt_db;
        if (isset($dcmt_db) && $dcmt_db instanceof Dcmt_Database && method_exists($dcmt_db, 'ensurePatientAdvanceTables')) {
            $dcmt_db->ensurePatientAdvanceTables();
            return;
        }
        // Fallback if the database helper is not in scope.
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS dcmt_patient_advances (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_patient_id INT NOT NULL,
                    dcmt_amount DECIMAL(12,2) NOT NULL,
                    dcmt_remaining_amount DECIMAL(12,2) NOT NULL,
                    dcmt_reason VARCHAR(255) NOT NULL,
                    dcmt_notes TEXT NULL,
                    dcmt_payment_method_id INT NULL,
                    dcmt_received_on DATE NOT NULL,
                    dcmt_status ENUM('active','depleted','refunded') NOT NULL DEFAULT 'active',
                    dcmt_created_by VARCHAR(50) NOT NULL,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_patient_advances_patient (dcmt_patient_id),
                    INDEX idx_patient_advances_remaining (dcmt_patient_id, dcmt_remaining_amount),
                    INDEX idx_patient_advances_received (dcmt_received_on)
                )
            ");
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS dcmt_patient_advance_applications (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_advance_id INT NOT NULL,
                    dcmt_patient_id INT NOT NULL,
                    dcmt_income_id INT NOT NULL,
                    dcmt_amount DECIMAL(12,2) NOT NULL,
                    dcmt_applied_on DATE NOT NULL,
                    dcmt_created_by VARCHAR(50) NOT NULL,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    INDEX idx_advance_app_advance (dcmt_advance_id),
                    INDEX idx_advance_app_patient (dcmt_patient_id),
                    INDEX idx_advance_app_income (dcmt_income_id)
                )
            ");
        } catch (PDOException $e) {
            error_log('dcmt_patient_advance_ensure_tables: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_patient_advance_can_access')) {
    function dcmt_patient_advance_can_access(?array $user = null): bool
    {
        if ($user === null) {
            $user = dcmt_get_current_user();
        }
        if (!$user) {
            return false;
        }
        $role = (string) ($user['dcmt_role'] ?? '');
        return in_array($role, ['admin', 'staff', 'doctor'], true);
    }
}

if (!function_exists('dcmt_patient_advance_require_access')) {
    function dcmt_patient_advance_require_access(): void
    {
        if (dcmt_patient_advance_can_access()) {
            return;
        }
        dcmt_show_message(trans('patient_advance', 'access_denied'), 'danger');
        dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
        exit();
    }
}

if (!function_exists('dcmt_patient_advance_payment_notes')) {
    function dcmt_patient_advance_payment_notes(): string
    {
        return json_encode(['source' => DCMT_PATIENT_ADVANCE_PAYMENT_SOURCE], JSON_UNESCAPED_UNICODE);
    }
}

if (!function_exists('dcmt_payment_history_is_advance_application')) {
    function dcmt_payment_history_is_advance_application($notes): bool
    {
        if ($notes === null || trim((string) $notes) === '') {
            return false;
        }
        $decoded = json_decode((string) $notes, true);
        if (!is_array($decoded)) {
            return false;
        }
        return ($decoded['source'] ?? '') === DCMT_PATIENT_ADVANCE_PAYMENT_SOURCE;
    }
}

if (!function_exists('dcmt_patient_advance_remaining')) {
    function dcmt_patient_advance_remaining(PDO $pdo, int $patientId): float
    {
        if ($patientId <= 0 || !dcmt_patient_advance_tables_exist($pdo)) {
            return 0.0;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(dcmt_remaining_amount), 0)
                FROM dcmt_patient_advances
                WHERE dcmt_patient_id = ?
                  AND dcmt_remaining_amount > 0
            ");
            $stmt->execute([$patientId]);
            return dcmt_patient_advance_money($stmt->fetchColumn());
        } catch (PDOException $e) {
            error_log('dcmt_patient_advance_remaining: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('dcmt_patient_advance_applied_to_income')) {
    function dcmt_patient_advance_applied_to_income(PDO $pdo, int $incomeId): float
    {
        if ($incomeId <= 0 || !dcmt_patient_advance_tables_exist($pdo)) {
            return 0.0;
        }
        try {
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0)
                FROM dcmt_patient_advance_applications
                WHERE dcmt_income_id = ?
            ");
            $stmt->execute([$incomeId]);
            return dcmt_patient_advance_money($stmt->fetchColumn());
        } catch (PDOException $e) {
            error_log('dcmt_patient_advance_applied_to_income: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('dcmt_patient_advance_available_for_income')) {
    /**
     * Remaining balance plus any amount already applied to this income (so edits can re-apply).
     */
    function dcmt_patient_advance_available_for_income(PDO $pdo, int $patientId, ?int $incomeId = null): float
    {
        $available = dcmt_patient_advance_remaining($pdo, $patientId);
        if ($incomeId !== null && $incomeId > 0) {
            $available += dcmt_patient_advance_applied_to_income($pdo, $incomeId);
        }
        return dcmt_patient_advance_money($available);
    }
}

if (!function_exists('dcmt_patient_advance_planned_drawdown')) {
    function dcmt_patient_advance_planned_drawdown(
        PDO $pdo,
        int $patientId,
        float $chargeTotal,
        float $paymentsTotal,
        ?int $incomeId = null
    ): float {
        if ($patientId <= 0) {
            return 0.0;
        }
        $unpaid = dcmt_patient_advance_money($chargeTotal - $paymentsTotal);
        if ($unpaid <= 0.009) {
            return 0.0;
        }
        $available = dcmt_patient_advance_available_for_income($pdo, $patientId, $incomeId);
        if ($available <= 0.009) {
            return 0.0;
        }
        return dcmt_patient_advance_money(min($available, $unpaid));
    }
}

if (!function_exists('dcmt_patient_advance_create')) {
    /**
     * @return array{success:bool,id?:int,errors?:array<int,string>}
     */
    function dcmt_patient_advance_create(PDO $pdo, array $data, array $user): array
    {
        dcmt_patient_advance_ensure_tables($pdo);
        $errors = [];

        $patientId = (int) ($data['patient_id'] ?? 0);
        $amount = dcmt_patient_advance_money($data['amount'] ?? 0);
        $reason = trim((string) ($data['reason'] ?? ''));
        $notes = trim((string) ($data['notes'] ?? ''));
        $receivedOn = trim((string) ($data['received_on'] ?? ''));
        $methodId = isset($data['payment_method_id']) && $data['payment_method_id'] !== '' && $data['payment_method_id'] !== null
            ? (int) $data['payment_method_id']
            : 0;

        if ($patientId <= 0) {
            $errors[] = trans('patient_advance', 'patient_required');
        }
        if ($amount <= 0.009) {
            $errors[] = trans('patient_advance', 'amount_required');
        }
        if ($reason === '') {
            $errors[] = trans('patient_advance', 'reason_required');
        }
        if ($receivedOn === '' || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $receivedOn)) {
            $errors[] = trans('patient_advance', 'date_required');
        }
        if ($methodId <= 0) {
            $errors[] = trans('patient_advance', 'payment_method_required');
        }

        if ($patientId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1");
                $stmt->execute([$patientId]);
                if (!$stmt->fetch()) {
                    $errors[] = trans('patient', 'not_found');
                }
            } catch (PDOException $e) {
                $errors[] = trans('patient_advance', 'database_error');
            }
        }

        if ($methodId > 0) {
            try {
                $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE dcmt_id = ? AND dcmt_status = 'active' LIMIT 1");
                $stmt->execute([$methodId]);
                if (!$stmt->fetch()) {
                    $errors[] = trans('patient_advance', 'payment_method_required');
                }
            } catch (PDOException $e) {
                $errors[] = trans('patient_advance', 'database_error');
            }
        }

        if (!empty($errors)) {
            return ['success' => false, 'errors' => $errors];
        }

        $createdBy = (string) ($user['dcmt_username'] ?? 'system');
        try {
            $stmt = $pdo->prepare("
                INSERT INTO dcmt_patient_advances (
                    dcmt_patient_id, dcmt_amount, dcmt_remaining_amount, dcmt_reason, dcmt_notes,
                    dcmt_payment_method_id, dcmt_received_on, dcmt_status, dcmt_created_by
                ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)
            ");
            $stmt->execute([
                $patientId,
                $amount,
                $amount,
                $reason,
                $notes !== '' ? $notes : null,
                $methodId,
                $receivedOn,
                $createdBy,
            ]);
            $id = (int) $pdo->lastInsertId();
            return ['success' => true, 'id' => $id];
        } catch (PDOException $e) {
            error_log('dcmt_patient_advance_create: ' . $e->getMessage());
            return ['success' => false, 'errors' => [trans('patient_advance', 'database_error')]];
        }
    }
}

if (!function_exists('dcmt_patient_advance_refresh_status')) {
    function dcmt_patient_advance_refresh_status(PDO $pdo, int $advanceId): void
    {
        $stmt = $pdo->prepare("SELECT dcmt_remaining_amount FROM dcmt_patient_advances WHERE dcmt_id = ? LIMIT 1");
        $stmt->execute([$advanceId]);
        $remaining = dcmt_patient_advance_money($stmt->fetchColumn());
        $status = $remaining > 0.009 ? 'active' : 'depleted';
        $upd = $pdo->prepare("UPDATE dcmt_patient_advances SET dcmt_status = ? WHERE dcmt_id = ?");
        $upd->execute([$status, $advanceId]);
    }
}

if (!function_exists('dcmt_patient_advance_apply_to_income')) {
    /**
     * FIFO drawdown. Must be called inside an open transaction.
     * Inserts application rows and a non-cash payment-history entry (revenue on service date).
     */
    function dcmt_patient_advance_apply_to_income(
        PDO $pdo,
        int $patientId,
        int $incomeId,
        float $amountNeeded,
        string $appliedOn,
        string $recordedBy,
        string $paymentHistoryType = 'general'
    ): float {
        $amountNeeded = dcmt_patient_advance_money($amountNeeded);
        if ($patientId <= 0 || $incomeId <= 0 || $amountNeeded <= 0.009) {
            return 0.0;
        }
        if (!dcmt_patient_advance_tables_exist($pdo)) {
            return 0.0;
        }

        $stmt = $pdo->prepare("
            SELECT dcmt_id, dcmt_remaining_amount
            FROM dcmt_patient_advances
            WHERE dcmt_patient_id = ?
              AND dcmt_remaining_amount > 0
            ORDER BY dcmt_received_on ASC, dcmt_id ASC
            FOR UPDATE
        ");
        $stmt->execute([$patientId]);
        $advances = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $stillNeeded = $amountNeeded;
        $appliedTotal = 0.0;
        $insertApp = $pdo->prepare("
            INSERT INTO dcmt_patient_advance_applications (
                dcmt_advance_id, dcmt_patient_id, dcmt_income_id, dcmt_amount, dcmt_applied_on, dcmt_created_by
            ) VALUES (?, ?, ?, ?, ?, ?)
        ");
        $updateAdv = $pdo->prepare("
            UPDATE dcmt_patient_advances
            SET dcmt_remaining_amount = ?
            WHERE dcmt_id = ?
        ");

        foreach ($advances as $advance) {
            if ($stillNeeded <= 0.009) {
                break;
            }
            $advanceId = (int) $advance['dcmt_id'];
            $remaining = dcmt_patient_advance_money($advance['dcmt_remaining_amount']);
            if ($remaining <= 0.009) {
                continue;
            }
            $take = dcmt_patient_advance_money(min($remaining, $stillNeeded));
            if ($take <= 0.009) {
                continue;
            }
            $newRemaining = dcmt_patient_advance_money($remaining - $take);
            $updateAdv->execute([$newRemaining, $advanceId]);
            dcmt_patient_advance_refresh_status($pdo, $advanceId);
            $insertApp->execute([$advanceId, $patientId, $incomeId, $take, $appliedOn, $recordedBy]);
            $appliedTotal = dcmt_patient_advance_money($appliedTotal + $take);
            $stillNeeded = dcmt_patient_advance_money($stillNeeded - $take);
        }

        if ($appliedTotal > 0.009) {
            if (!function_exists('dcmt_add_payment_history_entry')) {
                require_once __DIR__ . '/income_payment_history.php';
            }
            dcmt_add_payment_history_entry(
                $pdo,
                $incomeId,
                $paymentHistoryType,
                $appliedTotal,
                $appliedOn,
                $recordedBy,
                null,
                null,
                dcmt_patient_advance_payment_notes()
            );
        }

        return $appliedTotal;
    }
}

if (!function_exists('dcmt_patient_advance_reverse_for_income')) {
    /**
     * Restore remaining amounts and remove application + payment-history rows for this income.
     * Safe to call if none exist. Prefer calling inside a transaction.
     */
    function dcmt_patient_advance_reverse_for_income(PDO $pdo, int $incomeId): float
    {
        if ($incomeId <= 0 || !dcmt_patient_advance_tables_exist($pdo)) {
            return 0.0;
        }

        $stmt = $pdo->prepare("
            SELECT dcmt_id, dcmt_advance_id, dcmt_amount
            FROM dcmt_patient_advance_applications
            WHERE dcmt_income_id = ?
            FOR UPDATE
        ");
        $stmt->execute([$incomeId]);
        $apps = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $restored = 0.0;

        $updateAdv = $pdo->prepare("
            UPDATE dcmt_patient_advances
            SET dcmt_remaining_amount = dcmt_remaining_amount + ?
            WHERE dcmt_id = ?
        ");
        $deleteApp = $pdo->prepare("DELETE FROM dcmt_patient_advance_applications WHERE dcmt_id = ?");

        foreach ($apps as $app) {
            $amount = dcmt_patient_advance_money($app['dcmt_amount']);
            $advanceId = (int) $app['dcmt_advance_id'];
            $updateAdv->execute([$amount, $advanceId]);
            dcmt_patient_advance_refresh_status($pdo, $advanceId);
            $deleteApp->execute([(int) $app['dcmt_id']]);
            $restored = dcmt_patient_advance_money($restored + $amount);
        }

        $histStmt = $pdo->prepare("
            SELECT dcmt_id, dcmt_notes
            FROM dcmt_income_payment_history
            WHERE dcmt_income_id = ?
        ");
        $histStmt->execute([$incomeId]);
        $deleteHist = $pdo->prepare("DELETE FROM dcmt_income_payment_history WHERE dcmt_id = ?");
        foreach ($histStmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            if (dcmt_payment_history_is_advance_application($row['dcmt_notes'] ?? null)) {
                $deleteHist->execute([(int) $row['dcmt_id']]);
            }
        }

        return $restored;
    }
}

if (!function_exists('dcmt_patient_advance_list_for_patient')) {
    function dcmt_patient_advance_list_for_patient(PDO $pdo, int $patientId): array
    {
        if ($patientId <= 0 || !dcmt_patient_advance_tables_exist($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT a.*, pm.dcmt_name AS payment_method_name, u.dcmt_full_name AS created_by_name
                FROM dcmt_patient_advances a
                LEFT JOIN dcmt_income_payment_methods pm ON a.dcmt_payment_method_id = pm.dcmt_id
                LEFT JOIN dcmt_users u ON a.dcmt_created_by = u.dcmt_username
                WHERE a.dcmt_patient_id = ?
                ORDER BY a.dcmt_received_on DESC, a.dcmt_id DESC
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('dcmt_patient_advance_list_for_patient: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('dcmt_patient_advance_applications_for_patient')) {
    function dcmt_patient_advance_applications_for_patient(PDO $pdo, int $patientId): array
    {
        if ($patientId <= 0 || !dcmt_patient_advance_tables_exist($pdo)) {
            return [];
        }
        try {
            $stmt = $pdo->prepare("
                SELECT ap.*, i.dcmt_transaction_date, i.dcmt_description, i.dcmt_amount AS income_amount,
                       a.dcmt_reason AS advance_reason
                FROM dcmt_patient_advance_applications ap
                INNER JOIN dcmt_patient_advances a ON ap.dcmt_advance_id = a.dcmt_id
                LEFT JOIN dcmt_income i ON ap.dcmt_income_id = i.dcmt_id
                WHERE ap.dcmt_patient_id = ?
                ORDER BY ap.dcmt_applied_on DESC, ap.dcmt_id DESC
            ");
            $stmt->execute([$patientId]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (PDOException $e) {
            error_log('dcmt_patient_advance_applications_for_patient: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('dcmt_patient_advance_cash_total_for_date')) {
    function dcmt_patient_advance_cash_total_for_date(PDO $pdo, string $recordDate, array $cashMethodIds): float
    {
        if ($recordDate === '' || empty($cashMethodIds) || !dcmt_patient_advance_tables_exist($pdo)) {
            return 0.0;
        }
        try {
            $placeholders = implode(',', array_fill(0, count($cashMethodIds), '?'));
            $stmt = $pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0)
                FROM dcmt_patient_advances
                WHERE dcmt_received_on = ?
                  AND dcmt_payment_method_id IN ({$placeholders})
            ");
            $stmt->execute(array_merge([$recordDate], $cashMethodIds));
            return dcmt_patient_advance_money($stmt->fetchColumn());
        } catch (PDOException $e) {
            error_log('dcmt_patient_advance_cash_total_for_date: ' . $e->getMessage());
            return 0.0;
        }
    }
}
