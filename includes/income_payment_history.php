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

if (!function_exists('dcmt_payment_history_method_id_from_notes')) {
    function dcmt_payment_history_method_id_from_notes($notes): ?int
    {
        if ($notes === null || trim((string) $notes) === '') {
            return null;
        }
        $decoded = json_decode((string) $notes, true);
        if (!is_array($decoded) || !isset($decoded['payment_method_id']) || $decoded['payment_method_id'] === '') {
            return null;
        }
        $id = (int) $decoded['payment_method_id'];
        return $id > 0 ? $id : null;
    }
}

if (!function_exists('dcmt_payment_history_resolve_method_id')) {
    function dcmt_payment_history_resolve_method_id(array $row): ?int
    {
        if (isset($row['dcmt_payment_method_id']) && $row['dcmt_payment_method_id'] !== '' && $row['dcmt_payment_method_id'] !== null) {
            $id = (int) $row['dcmt_payment_method_id'];
            if ($id > 0) {
                return $id;
            }
        }
        if (isset($row['payment_method_id']) && $row['payment_method_id'] !== '' && $row['payment_method_id'] !== null) {
            $id = (int) $row['payment_method_id'];
            if ($id > 0) {
                return $id;
            }
        }
        return dcmt_payment_history_method_id_from_notes($row['dcmt_notes'] ?? null);
    }
}

if (!function_exists('dcmt_payment_history_resolve_notes_text')) {
    function dcmt_payment_history_resolve_notes_text(array $row): ?string
    {
        $notes = $row['dcmt_notes'] ?? null;
        if ($notes === null || trim((string) $notes) === '') {
            return null;
        }
        $decoded = json_decode((string) $notes, true);
        if (!is_array($decoded)) {
            return trim((string) $notes);
        }
        if (isset($decoded['note']) && trim((string) $decoded['note']) !== '') {
            return trim((string) $decoded['note']);
        }
        $copy = $decoded;
        unset($copy['payment_method_id']);
        if (empty($copy)) {
            return null;
        }
        $encoded = json_encode($copy, JSON_UNESCAPED_UNICODE);
        return $encoded !== false && $encoded !== '[]' && $encoded !== '{}' ? $encoded : null;
    }
}

if (!function_exists('dcmt_payment_history_prepare_notes_for_storage')) {
    function dcmt_payment_history_prepare_notes_for_storage(?string $userNotes, ?int $paymentMethodId, bool $useMethodColumn): ?string
    {
        $text = $userNotes !== null ? trim($userNotes) : '';
        if ($text !== '') {
            return $text;
        }
        if ($useMethodColumn && $paymentMethodId !== null && $paymentMethodId > 0) {
            return json_encode(['payment_method_id' => $paymentMethodId], JSON_UNESCAPED_UNICODE);
        }
        return null;
    }
}

if (!function_exists('dcmt_normalize_payment_method_id')) {
    function dcmt_normalize_payment_method_id(?int $methodId, ?int $fallbackMethodId = null, ?int $defaultCashMethodId = null): ?int
    {
        if ($methodId !== null && $methodId > 0) {
            return $methodId;
        }
        if ($fallbackMethodId !== null && $fallbackMethodId > 0) {
            return $fallbackMethodId;
        }
        if ($defaultCashMethodId !== null && $defaultCashMethodId > 0) {
            return $defaultCashMethodId;
        }
        return null;
    }
}

if (!function_exists('dcmt_normalize_income_payment_entries')) {
    function dcmt_normalize_income_payment_entries(array $entries, ?int $fallbackMethodId = null, ?int $defaultCashMethodId = null): array
    {
        $normalized = [];
        foreach ($entries as $entry) {
            if (!is_array($entry)) {
                continue;
            }
            $methodId = null;
            if (isset($entry['payment_method_id']) && $entry['payment_method_id'] !== '' && $entry['payment_method_id'] !== null) {
                $methodId = (int) $entry['payment_method_id'];
            }
            $entry['payment_method_id'] = dcmt_normalize_payment_method_id($methodId, $fallbackMethodId, $defaultCashMethodId);
            $normalized[] = $entry;
        }
        return $normalized;
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
        ?string $createdAt = null,
        ?string $notes = null
    ): void {
        if ($amount <= 0) {
            return;
        }

        $useMethodColumn = dcmt_income_payment_history_has_method_column($pdo);
        $notesToStore = dcmt_payment_history_prepare_notes_for_storage($notes, $paymentMethodId, $useMethodColumn);

        if (!$useMethodColumn && $paymentMethodId !== null && $paymentMethodId > 0) {
            $legacyPayload = [];
            if ($notesToStore !== null && !dcmt_payment_history_method_id_from_notes($notesToStore)) {
                $legacyPayload['note'] = $notesToStore;
            }
            $legacyPayload['payment_method_id'] = $paymentMethodId;
            $notesToStore = json_encode($legacyPayload, JSON_UNESCAPED_UNICODE);
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
                    $paymentMethodId, $notesToStore, $recordedBy, $createdAt,
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
                    $paymentMethodId, $notesToStore, $recordedBy,
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
            $stmt->execute([$incomeId, $paymentType, $amount, $paidOn, $notesToStore, $recordedBy, $createdAt]);
        } else {
            $stmt = $pdo->prepare("
                INSERT INTO dcmt_income_payment_history (
                    dcmt_income_id, dcmt_payment_type, dcmt_amount, dcmt_paid_on,
                    dcmt_notes, dcmt_recorded_by
                ) VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute([$incomeId, $paymentType, $amount, $paidOn, $notesToStore, $recordedBy]);
        }
    }
}
