<?php
/**
 * Patient treatment plan — generated from odontogram solution summary with service pricing.
 */

require_once __DIR__ . '/patient_odontogram.php';
require_once __DIR__ . '/patient_odontogram_summary.php';
require_once __DIR__ . '/odontogram_treatments.php';

if (!function_exists('dcmt_odontogram_treatment_default_service_names')) {
    /**
     * Default mapping from clinical treatment name to billing service name.
     *
     * @return array<string, string>
     */
    function dcmt_odontogram_treatment_default_service_names(): array
    {
        return [
            'Resina' => 'Dental Filling',
            'Sellador' => 'Dental Filling',
            'Ionomero' => 'Dental Filling',
            'Incrustacion' => 'Crown/Bridge',
            'Endodoncia' => 'Root Canal Treatment',
            'Corona' => 'Crown/Bridge',
        ];
    }
}

if (!function_exists('dcmt_ensure_odontogram_treatment_service_column')) {
    function dcmt_ensure_odontogram_treatment_service_column(PDO $pdo): void
    {
        dcmt_ensure_odontogram_treatments_table($pdo);
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM dcmt_odontogram_treatments LIKE 'dcmt_service_id'");
            if ($chk && $chk->rowCount() === 0) {
                $pdo->exec('ALTER TABLE dcmt_odontogram_treatments ADD COLUMN dcmt_service_id INT NULL DEFAULT NULL AFTER dcmt_color');
            }
        } catch (PDOException $e) {
            error_log('dcmt_ensure_odontogram_treatment_service_column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_ensure_patient_treatment_plans_table')) {
    function dcmt_ensure_patient_treatment_plans_table(PDO $pdo): void
    {
        try {
            $pdo->exec("
                CREATE TABLE IF NOT EXISTS dcmt_patient_treatment_plans (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_patient_id INT NOT NULL,
                    dcmt_doctor_user_id INT NULL,
                    dcmt_summary_json LONGTEXT NULL,
                    dcmt_lines_json LONGTEXT NOT NULL,
                    dcmt_total DECIMAL(12,2) NOT NULL DEFAULT 0.00,
                    dcmt_notes TEXT NULL,
                    dcmt_created_by VARCHAR(50) NULL,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uk_dcmt_patient_treatment_plan (dcmt_patient_id),
                    KEY idx_dcmt_treatment_plan_doctor (dcmt_doctor_user_id)
                ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            ");
        } catch (PDOException $e) {
            error_log('dcmt_ensure_patient_treatment_plans_table: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_treatment_plan_table_exists')) {
    function dcmt_treatment_plan_table_exists(PDO $pdo): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'dcmt_patient_treatment_plans'");
            $exists = $chk && $chk->rowCount() > 0;
        } catch (PDOException $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('dcmt_seed_odontogram_treatment_service_links')) {
    function dcmt_seed_odontogram_treatment_service_links(PDO $pdo): void
    {
        dcmt_ensure_odontogram_treatment_service_column($pdo);
        dcmt_seed_odontogram_clinical_treatments($pdo);

        $defaults = dcmt_odontogram_treatment_default_service_names();
        if ($defaults === []) {
            return;
        }

        $serviceStmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_services WHERE dcmt_name = ? AND dcmt_status = 'active' LIMIT 1");
        $updateStmt = $pdo->prepare("
            UPDATE dcmt_odontogram_treatments
            SET dcmt_service_id = ?
            WHERE dcmt_name = ? AND (dcmt_service_id IS NULL OR dcmt_service_id = 0)
        ");

        foreach ($defaults as $treatmentName => $serviceName) {
            $serviceStmt->execute([$serviceName]);
            $serviceId = (int) $serviceStmt->fetchColumn();
            if ($serviceId <= 0) {
                continue;
            }
            $updateStmt->execute([$serviceId, $treatmentName]);
        }
    }
}

if (!function_exists('dcmt_resolve_service_id_for_treatment')) {
    function dcmt_resolve_service_id_for_treatment(PDO $pdo, string $treatmentName): int
    {
        $treatmentName = trim($treatmentName);
        if ($treatmentName === '') {
            return 0;
        }

        dcmt_ensure_odontogram_treatment_service_column($pdo);

        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_service_id
                FROM dcmt_odontogram_treatments
                WHERE dcmt_name = ? AND dcmt_status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$treatmentName]);
            $serviceId = (int) $stmt->fetchColumn();
            if ($serviceId > 0) {
                return $serviceId;
            }
        } catch (PDOException $e) {
            error_log('dcmt_resolve_service_id_for_treatment: ' . $e->getMessage());
        }

        $defaults = dcmt_odontogram_treatment_default_service_names();
        $serviceName = $defaults[$treatmentName] ?? null;
        if ($serviceName === null) {
            return 0;
        }

        try {
            $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_services WHERE dcmt_name = ? AND dcmt_status = 'active' LIMIT 1");
            $stmt->execute([$serviceName]);
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('dcmt_resolve_service_id_for_treatment fallback: ' . $e->getMessage());
            return 0;
        }
    }
}

if (!function_exists('dcmt_get_service_price_for_doctor')) {
    function dcmt_get_service_price_for_doctor(PDO $pdo, int $doctorUserId, int $serviceId): float
    {
        if ($serviceId <= 0) {
            return 0.0;
        }

        if ($doctorUserId > 0) {
            try {
                $stmt = $pdo->prepare("
                    SELECT ds.dcmt_price
                    FROM dcmt_doctor_services ds
                    INNER JOIN dcmt_services s ON ds.dcmt_service_id = s.dcmt_id
                    WHERE ds.dcmt_user_id = ?
                      AND ds.dcmt_service_id = ?
                      AND ds.dcmt_status = 'active'
                      AND s.dcmt_status = 'active'
                    LIMIT 1
                ");
                $stmt->execute([$doctorUserId, $serviceId]);
                $price = $stmt->fetchColumn();
                if ($price !== false) {
                    return (float) $price;
                }
            } catch (PDOException $e) {
                error_log('dcmt_get_service_price_for_doctor: ' . $e->getMessage());
            }
        }

        try {
            $stmt = $pdo->prepare('SELECT dcmt_base_price FROM dcmt_services WHERE dcmt_id = ? AND dcmt_status = \'active\' LIMIT 1');
            $stmt->execute([$serviceId]);
            $price = $stmt->fetchColumn();
            return $price !== false ? (float) $price : 0.0;
        } catch (PDOException $e) {
            error_log('dcmt_get_service_price_for_doctor base: ' . $e->getMessage());
            return 0.0;
        }
    }
}

if (!function_exists('dcmt_fetch_active_services_catalog')) {
    /**
     * @return list<array{id: int, name: string, price: float}>
     */
    function dcmt_fetch_active_services_catalog(PDO $pdo, int $doctorUserId = 0): array
    {
        $catalog = [];
        try {
            if ($doctorUserId > 0) {
                $stmt = $pdo->prepare("
                    SELECT s.dcmt_id AS id, s.dcmt_name AS name, ds.dcmt_price AS price
                    FROM dcmt_doctor_services ds
                    INNER JOIN dcmt_services s ON ds.dcmt_service_id = s.dcmt_id
                    WHERE ds.dcmt_user_id = ?
                      AND ds.dcmt_status = 'active'
                      AND s.dcmt_status = 'active'
                    ORDER BY s.dcmt_name ASC
                ");
                $stmt->execute([$doctorUserId]);
                $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
                if ($rows) {
                    foreach ($rows as $row) {
                        $catalog[] = [
                            'id' => (int) $row['id'],
                            'name' => (string) $row['name'],
                            'price' => (float) $row['price'],
                        ];
                    }
                    return $catalog;
                }
            }

            $stmt = $pdo->query("SELECT dcmt_id AS id, dcmt_name AS name, dcmt_base_price AS price FROM dcmt_services WHERE dcmt_status = 'active' ORDER BY dcmt_name ASC");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            foreach ($rows as $row) {
                $catalog[] = [
                    'id' => (int) $row['id'],
                    'name' => (string) $row['name'],
                    'price' => (float) $row['price'],
                ];
            }
        } catch (PDOException $e) {
            error_log('dcmt_fetch_active_services_catalog: ' . $e->getMessage());
        }

        return $catalog;
    }
}

if (!function_exists('dcmt_build_treatment_plan_lines')) {
    /**
     * @return list<array<string, mixed>>
     */
    function dcmt_build_treatment_plan_lines(PDO $pdo, array $solutionChart, int $doctorUserId = 0): array
    {
        $groups = dcmt_patient_odontogram_solution_plan_groups($solutionChart);
        $lines = [];
        $lineIndex = 0;

        foreach ($groups as $group) {
            $quantity = (int) $group['quantity'];

            // Leave service/price empty — unit price is filled only when a service is selected.
            $lines[] = [
                'line_id' => 'line_' . (++$lineIndex),
                'treatment' => $group['treatment'],
                'zone' => $group['zone'],
                'quadrant' => $group['quadrant'],
                'quadrant_label' => $group['quadrant_label'],
                'zone_label' => $group['zone_label'],
                'quantity' => $quantity,
                'service_id' => 0,
                'service_name' => '',
                'unit_price' => 0.0,
                'subtotal' => 0.0,
                'selected' => true,
            ];
        }

        return $lines;
    }
}

if (!function_exists('dcmt_treatment_plan_calculate_total')) {
    function dcmt_treatment_plan_calculate_total(array $lines): float
    {
        $total = 0.0;
        foreach ($lines as $line) {
            if (empty($line['selected'])) {
                continue;
            }
            $total += (float) ($line['subtotal'] ?? 0);
        }
        return round($total, 2);
    }
}

if (!function_exists('dcmt_normalize_treatment_plan_lines')) {
    /**
     * @param mixed $raw
     * @return list<array<string, mixed>>
     */
    function dcmt_normalize_treatment_plan_lines($raw, PDO $pdo, int $doctorUserId = 0): array
    {
        if (!is_array($raw)) {
            return [];
        }

        $catalogById = [];
        foreach (dcmt_fetch_active_services_catalog($pdo, $doctorUserId) as $service) {
            $catalogById[(int) $service['id']] = $service;
        }

        $lines = [];
        $index = 0;
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $serviceId = (int) ($line['service_id'] ?? 0);
            $unitPrice = 0.0;
            $serviceName = '';

            if ($serviceId > 0) {
                $unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : 0.0;
                if (isset($catalogById[$serviceId])) {
                    if ($unitPrice <= 0) {
                        $unitPrice = (float) $catalogById[$serviceId]['price'];
                    }
                    $serviceName = (string) $catalogById[$serviceId]['name'];
                } else {
                    // Service not available for this doctor — do not keep a stale price.
                    $serviceId = 0;
                    $unitPrice = 0.0;
                    $serviceName = '';
                }
            }

            if ($serviceId <= 0) {
                $unitPrice = 0.0;
                $serviceName = '';
            } elseif ($serviceName === '') {
                $serviceName = trim((string) ($line['service_name'] ?? ''));
            }

            $lines[] = [
                'line_id' => (string) ($line['line_id'] ?? ('line_' . (++$index))),
                'treatment' => trim((string) ($line['treatment'] ?? '')),
                'zone' => (string) ($line['zone'] ?? ''),
                'quadrant' => (string) ($line['quadrant'] ?? ''),
                'quadrant_label' => (string) ($line['quadrant_label'] ?? dcmt_patient_odontogram_quadrant_label((string) ($line['quadrant'] ?? ''))),
                'zone_label' => (string) ($line['zone_label'] ?? ''),
                'quantity' => $quantity,
                'service_id' => $serviceId,
                'service_name' => $serviceName,
                'unit_price' => $serviceId > 0 ? round($unitPrice, 2) : 0.0,
                'subtotal' => $serviceId > 0 ? round($unitPrice * $quantity, 2) : 0.0,
                'selected' => !isset($line['selected']) || !empty($line['selected']),
            ];
        }

        return $lines;
    }
}

if (!function_exists('dcmt_fetch_patient_treatment_plan')) {
    /**
     * @return array<string, mixed>|null
     */
    function dcmt_fetch_patient_treatment_plan(PDO $pdo, int $patientId): ?array
    {
        if ($patientId <= 0 || !dcmt_treatment_plan_table_exists($pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT * FROM dcmt_patient_treatment_plans WHERE dcmt_patient_id = ? LIMIT 1');
            $stmt->execute([$patientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$row) {
                return null;
            }

            $summary = json_decode((string) ($row['dcmt_summary_json'] ?? ''), true);
            $lines = json_decode((string) ($row['dcmt_lines_json'] ?? ''), true);

            return [
                'dcmt_id' => (int) $row['dcmt_id'],
                'dcmt_patient_id' => (int) $row['dcmt_patient_id'],
                'dcmt_doctor_user_id' => isset($row['dcmt_doctor_user_id']) ? (int) $row['dcmt_doctor_user_id'] : 0,
                'summary' => is_array($summary) ? $summary : [],
                'lines' => is_array($lines) ? $lines : [],
                'dcmt_total' => (float) ($row['dcmt_total'] ?? 0),
                'dcmt_notes' => (string) ($row['dcmt_notes'] ?? ''),
                'dcmt_updated_at' => $row['dcmt_updated_at'] ?? null,
            ];
        } catch (PDOException $e) {
            error_log('dcmt_fetch_patient_treatment_plan: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('dcmt_save_patient_treatment_plan')) {
  /**
   * @param list<array<string, mixed>> $lines
   * @param array<string, mixed> $summary
   */
    function dcmt_save_patient_treatment_plan(
        PDO $pdo,
        int $patientId,
        int $doctorUserId,
        array $summary,
        array $lines,
        string $notes = '',
        ?string $createdBy = null
    ): bool {
        if ($patientId <= 0) {
            return false;
        }

        dcmt_ensure_patient_treatment_plans_table($pdo);

        $normalizedLines = dcmt_normalize_treatment_plan_lines($lines, $pdo, $doctorUserId);
        $total = dcmt_treatment_plan_calculate_total($normalizedLines);
        $summaryJson = json_encode($summary, JSON_UNESCAPED_UNICODE);
        $linesJson = json_encode($normalizedLines, JSON_UNESCAPED_UNICODE);

        if ($summaryJson === false || $linesJson === false) {
            return false;
        }

        try {
            $stmt = $pdo->prepare("
                INSERT INTO dcmt_patient_treatment_plans
                    (dcmt_patient_id, dcmt_doctor_user_id, dcmt_summary_json, dcmt_lines_json, dcmt_total, dcmt_notes, dcmt_created_by)
                VALUES (?, ?, ?, ?, ?, ?, ?)
                ON DUPLICATE KEY UPDATE
                    dcmt_doctor_user_id = VALUES(dcmt_doctor_user_id),
                    dcmt_summary_json = VALUES(dcmt_summary_json),
                    dcmt_lines_json = VALUES(dcmt_lines_json),
                    dcmt_total = VALUES(dcmt_total),
                    dcmt_notes = VALUES(dcmt_notes),
                    dcmt_updated_at = CURRENT_TIMESTAMP
            ");
            $stmt->execute([
                $patientId,
                $doctorUserId > 0 ? $doctorUserId : null,
                $summaryJson,
                $linesJson,
                $total,
                $notes !== '' ? $notes : null,
                $createdBy,
            ]);
            return true;
        } catch (PDOException $e) {
            error_log('dcmt_save_patient_treatment_plan: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('dcmt_fetch_active_doctors')) {
    /**
     * @return list<array{dcmt_id: int, dcmt_name: string}>
     */
    function dcmt_fetch_active_doctors(PDO $pdo): array
    {
        try {
            $stmt = $pdo->query("
                SELECT dcmt_id, dcmt_full_name AS dcmt_name
                FROM dcmt_users
                WHERE dcmt_role = 'doctor' AND dcmt_status = 'active'
                ORDER BY dcmt_full_name ASC
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            $out = [];
            foreach ($rows as $row) {
                $out[] = [
                    'dcmt_id' => (int) $row['dcmt_id'],
                    'dcmt_name' => (string) ($row['dcmt_name'] ?? ''),
                ];
            }
            return $out;
        } catch (PDOException $e) {
            error_log('dcmt_fetch_active_doctors: ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('dcmt_treatment_plan_summary_fingerprint')) {
    /**
     * Stable fingerprint of solution treatment counts (zone/quadrant/treatment).
     *
     * @param array<string, mixed> $summary
     */
    function dcmt_treatment_plan_summary_fingerprint(array $summary): string
    {
        $byZone = $summary['by_zone_quadrant'] ?? [];
        if (!is_array($byZone)) {
            $byZone = [];
        }

        $flat = [];
        foreach (['posterior', 'anterior'] as $zone) {
            $zoneBucket = $byZone[$zone] ?? [];
            if (!is_array($zoneBucket)) {
                continue;
            }
            foreach (['tl', 'tr', 'bl', 'br'] as $quadrant) {
                $bucket = $zoneBucket[$quadrant] ?? [];
                if (!is_array($bucket)) {
                    continue;
                }
                ksort($bucket);
                foreach ($bucket as $treatment => $qty) {
                    $qty = (int) $qty;
                    if ($qty <= 0) {
                        continue;
                    }
                    $flat[] = $zone . '|' . $quadrant . '|' . trim((string) $treatment) . '|' . $qty;
                }
            }
        }
        sort($flat);

        return hash('sha256', implode("\n", $flat));
    }
}

if (!function_exists('dcmt_treatment_plan_is_stale')) {
    /**
     * @param array<string, mixed>|null $savedPlan
     * @param array<string, mixed> $currentSummary
     */
    function dcmt_treatment_plan_is_stale(?array $savedPlan, array $currentSummary): bool
    {
        if ($savedPlan === null || empty($savedPlan['lines'])) {
            return !empty($currentSummary['has_data']);
        }

        $savedSummary = is_array($savedPlan['summary'] ?? null) ? $savedPlan['summary'] : [];
        return dcmt_treatment_plan_summary_fingerprint($savedSummary)
            !== dcmt_treatment_plan_summary_fingerprint($currentSummary);
    }
}

if (!function_exists('dcmt_treatment_plan_line_key')) {
    function dcmt_treatment_plan_line_key(array $line): string
    {
        return strtolower(trim((string) ($line['treatment'] ?? '')))
            . '|' . (string) ($line['zone'] ?? '')
            . '|' . (string) ($line['quadrant'] ?? '');
    }
}

if (!function_exists('dcmt_merge_treatment_plan_lines_with_previous')) {
    /**
     * Rebuild lines from odontogram, keeping prior service/selection choices when keys match.
     *
     * @param list<array<string, mixed>> $newLines
     * @param list<array<string, mixed>> $previousLines
     * @return list<array<string, mixed>>
     */
    function dcmt_merge_treatment_plan_lines_with_previous(array $newLines, array $previousLines): array
    {
        $previousByKey = [];
        foreach ($previousLines as $line) {
            if (!is_array($line)) {
                continue;
            }
            $key = dcmt_treatment_plan_line_key($line);
            if ($key === '||' || isset($previousByKey[$key])) {
                continue;
            }
            $previousByKey[$key] = $line;
        }

        foreach ($newLines as &$line) {
            $key = dcmt_treatment_plan_line_key($line);
            if (!isset($previousByKey[$key])) {
                continue;
            }
            $prev = $previousByKey[$key];
            $prevServiceId = (int) ($prev['service_id'] ?? 0);
            if ($prevServiceId > 0) {
                $line['service_id'] = $prevServiceId;
                $line['service_name'] = (string) ($prev['service_name'] ?? $line['service_name']);
                if (isset($prev['unit_price']) && (float) $prev['unit_price'] > 0) {
                    $line['unit_price'] = (float) $prev['unit_price'];
                }
            } else {
                $line['service_id'] = 0;
                $line['service_name'] = '';
                $line['unit_price'] = 0.0;
            }
            if (array_key_exists('selected', $prev)) {
                $line['selected'] = !empty($prev['selected']);
            }
            $qty = max(1, (int) ($line['quantity'] ?? 1));
            $line['subtotal'] = ((int) ($line['service_id'] ?? 0) > 0)
                ? round(((float) ($line['unit_price'] ?? 0)) * $qty, 2)
                : 0.0;
        }
        unset($line);

        return $newLines;
    }
}

if (!function_exists('dcmt_sync_treatment_plan_from_odontogram')) {
    /**
     * Refresh saved treatment plan lines from the current odontogram solution.
     *
     * @return array{synced: bool, lines: list<array<string, mixed>>, summary: array<string, mixed>}|null
     */
    function dcmt_sync_treatment_plan_from_odontogram(
        PDO $pdo,
        int $patientId,
        ?int $doctorUserId = null,
        bool $force = false,
        ?string $createdBy = null
    ): ?array {
        if ($patientId <= 0) {
            return null;
        }

        dcmt_ensure_patient_treatment_plans_table($pdo);

        $odontogramJson = dcmt_load_patient_odontogram_json($pdo, $patientId);
        $document = dcmt_patient_odontogram_decode_document($odontogramJson);
        $solution = $document['solution'] ?? dcmt_patient_odontogram_empty_chart();
        $summary = dcmt_patient_odontogram_solution_summary($document);
        $savedPlan = dcmt_fetch_patient_treatment_plan($pdo, $patientId);

        if (empty($summary['has_data'])) {
            // Solution cleared — keep notes/doctor but empty lines if a plan exists.
            if ($savedPlan) {
                $doctorId = $doctorUserId !== null ? (int) $doctorUserId : (int) ($savedPlan['dcmt_doctor_user_id'] ?? 0);
                dcmt_save_patient_treatment_plan(
                    $pdo,
                    $patientId,
                    $doctorId,
                    $summary,
                    [],
                    (string) ($savedPlan['dcmt_notes'] ?? ''),
                    $createdBy
                );
                return ['synced' => true, 'rebuilt' => true, 'lines' => [], 'summary' => $summary];
            }
            return ['synced' => false, 'rebuilt' => false, 'lines' => [], 'summary' => $summary];
        }

        $needsSync = $force || dcmt_treatment_plan_is_stale($savedPlan, $summary);
        if (!$needsSync && $savedPlan) {
            return [
                'synced' => false,
                'rebuilt' => false,
                'lines' => dcmt_normalize_treatment_plan_lines($savedPlan['lines'], $pdo, (int) ($savedPlan['dcmt_doctor_user_id'] ?? 0)),
                'summary' => $summary,
            ];
        }

        $doctorId = $doctorUserId !== null
            ? (int) $doctorUserId
            : (int) ($savedPlan['dcmt_doctor_user_id'] ?? 0);

        $newLines = dcmt_build_treatment_plan_lines($pdo, $solution, $doctorId);
        if ($savedPlan && !empty($savedPlan['lines'])) {
            $newLines = dcmt_merge_treatment_plan_lines_with_previous($newLines, $savedPlan['lines']);
            $newLines = dcmt_normalize_treatment_plan_lines($newLines, $pdo, $doctorId);
        }

        $didPersist = false;
        // Persist when a plan already exists, or force regenerate was requested.
        if ($savedPlan || $force) {
            $didPersist = dcmt_save_patient_treatment_plan(
                $pdo,
                $patientId,
                $doctorId,
                $summary,
                $newLines,
                (string) ($savedPlan['dcmt_notes'] ?? ''),
                $createdBy
            );
        }

        return [
            'synced' => $didPersist,
            'rebuilt' => true,
            'lines' => $newLines,
            'summary' => $summary,
        ];
    }
}
