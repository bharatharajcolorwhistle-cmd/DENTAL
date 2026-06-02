<?php
/**
 * Income payment history helpers (shared insert + column detection).
 */

if (!function_exists('dcmt_income_payment_history_has_method_column')) {
    function dcmt_income_payment_history_has_method_column(PDO $pdo): bool
    {
        static $has = null;
        if ($has !== null) {
            return $has;
        }
        try {
            $stmt = $pdo->query("SHOW COLUMNS FROM dcmt_income_payment_history LIKE 'dcmt_payment_method_id'");
            $has = $stmt && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $has = false;
        }
        return $has;
    }
}

if (!function_exists('dcmt_add_payment_history_entry')) {
    function dcmt_add_payment_history_entry(
        PDO $pdo,
        int $incomeId,
        string $paymentType,
        float $amount,
        string $paidOn,
        string $recordedBy,
        ?int $paymentMethodId = null,
        ?string $createdAt = null
    ): void {
        if ($amount <= 0) {
            return;
        }

        $useMethodColumn = dcmt_income_payment_history_has_method_column($pdo);
        $notes = null;
        if (!$useMethodColumn && $paymentMethodId !== null) {
            $notes = json_encode(['payment_method_id' => $paymentMethodId]);
        }

        if ($useMethodColumn) {
            if ($createdAt !== null && trim($createdAt) !== '') {
                $stmt = $pdo->prepare("
                    INSERT INTO dcmt_income_payment_history (
                        dcmt_income_id, dcmt_payment_type, dcmt_amount, dcmt_paid_on,
                        dcmt_payment_method_id, dcmt_notes, dcmt_recorded_by, dcmt_created_at
                    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $incomeId, $paymentType, $amount, $paidOn,
                    $paymentMethodId, $notes, $recordedBy, $createdAt,
                ]);
            } else {
                $stmt = $pdo->prepare("
                    INSERT INTO dcmt_income_payment_history (
                        dcmt_income_id, dcmt_payment_type, dcmt_amount, dcmt_paid_on,
                        dcmt_payment_method_id, dcmt_notes, dcmt_recorded_by
                    ) VALUES (?, ?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute([
                    $incomeId, $paymentType, $amount, $paidOn,
                    $paymentMethodId, $notes, $recordedBy,
                ]);
            }
            return;
        }

        if ($createdAt !== null && trim($createdAt) !== '') {
            $stmt = $pdo->prepare("
                INSERT INTO dcmt_income_payment_history (
                    dcmt_income_id, dcmt_payment_type, dcmt_amount, dcmt_paid_on,
                    dcmt_notes, dcmt_recorded_by, dcmt_created_at
                ) VALUES (?, ?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$incomeId, $paymentType, $amount, $paidOn, $notes, $recordedBy, $createdAt]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO dcmt_income_payment_history (
                    dcmt_income_id, dcmt_payment_type, dcmt_amount, dcmt_paid_on,
                    dcmt_notes, dcmt_recorded_by
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$incomeId, $paymentType, $amount, $paidOn, $notes, $recordedBy]);
        }
    }
}
