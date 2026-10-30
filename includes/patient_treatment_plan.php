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

if (!function_exists('dcmt_ensure_odontogram_treatment_doctor_column')) {
    function dcmt_ensure_odontogram_treatment_doctor_column(PDO $pdo): void
    {
        dcmt_ensure_odontogram_treatment_service_column($pdo);
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM dcmt_odontogram_treatments LIKE 'dcmt_doctor_user_id'");
            if ($chk && $chk->rowCount() === 0) {
                $pdo->exec('ALTER TABLE dcmt_odontogram_treatments ADD COLUMN dcmt_doctor_user_id INT NULL DEFAULT NULL AFTER dcmt_service_id');
            }
        } catch (PDOException $e) {
            error_log('dcmt_ensure_odontogram_treatment_doctor_column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_ensure_odontogram_treatment_show_in_plan_column')) {
    /**
     * Flag controlling whether a clinical treatment appears on Proposed Treatment Plan lines.
     * Default 1 (show). Uncheck for chart-only treatments (e.g. marking a tooth healthy).
     */
    function dcmt_ensure_odontogram_treatment_show_in_plan_column(PDO $pdo): void
    {
        dcmt_ensure_odontogram_treatments_table($pdo);
        try {
            $chk = $pdo->query("SHOW COLUMNS FROM dcmt_odontogram_treatments LIKE 'dcmt_show_in_treatment_plan'");
            if ($chk && $chk->rowCount() === 0) {
                $pdo->exec('
                    ALTER TABLE dcmt_odontogram_treatments
                    ADD COLUMN dcmt_show_in_treatment_plan TINYINT(1) NOT NULL DEFAULT 1
                    AFTER dcmt_whole_tooth
                ');
            }
        } catch (PDOException $e) {
            error_log('dcmt_ensure_odontogram_treatment_show_in_plan_column: ' . $e->getMessage());
        }
    }
}

if (!function_exists('dcmt_odontogram_treatments_show_in_plan_map')) {
    /**
     * Map of treatment name => whether it should appear on the Proposed Treatment Plan.
     * Unknown / missing treatments default to true (legacy behaviour).
     *
     * @return array<string, bool>
     */
    function dcmt_odontogram_treatments_show_in_plan_map(PDO $pdo): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        dcmt_ensure_odontogram_treatment_show_in_plan_column($pdo);
        $map = [];
        try {
            $stmt = $pdo->query('SELECT dcmt_name, dcmt_show_in_treatment_plan FROM dcmt_odontogram_treatments');
            if ($stmt) {
                while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                    $name = trim((string) ($row['dcmt_name'] ?? ''));
                    if ($name === '') {
                        continue;
                    }
                    $map[$name] = ((int) ($row['dcmt_show_in_treatment_plan'] ?? 1)) === 1;
                }
            }
        } catch (PDOException $e) {
            error_log('dcmt_odontogram_treatments_show_in_plan_map: ' . $e->getMessage());
        }

        // Chart-only markers (e.g. "Healthy") are never part of the proposed plan.
        $cache = $map;
        return $cache;
    }
}

if (!function_exists('dcmt_odontogram_treatment_shows_in_plan')) {
    function dcmt_odontogram_treatment_shows_in_plan(PDO $pdo, string $treatmentName): bool
    {
        $treatmentName = trim($treatmentName);
        if ($treatmentName === '') {
            return false;
        }
        if (dcmt_odontogram_is_chart_only_treatment($treatmentName)) {
            return false;
        }
        $map = dcmt_odontogram_treatments_show_in_plan_map($pdo);
        return $map[$treatmentName] ?? true;
    }
}

if (!function_exists('dcmt_filter_treatment_plan_lines_by_show_flag')) {
    /**
     * Drop odontogram-sourced plan lines for treatments configured to hide from the Proposed Treatment Plan.
     * Additional (manual) service lines are always kept.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    function dcmt_filter_treatment_plan_lines_by_show_flag(PDO $pdo, array $lines): array
    {
        $map = dcmt_odontogram_treatments_show_in_plan_map($pdo);
        $filtered = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (!empty($line['is_additional'])) {
                $filtered[] = $line;
                continue;
            }
            $name = trim((string) ($line['treatment'] ?? ''));
            if ($name === '') {
                continue;
            }
            if (dcmt_odontogram_is_chart_only_treatment($name)) {
                continue;
            }
            if (($map[$name] ?? true) === false) {
                continue;
            }
            $filtered[] = $line;
        }
        return $filtered;
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
        $defaults = dcmt_resolve_treatment_plan_defaults($pdo, $treatmentName);
        return (int) ($defaults['service_id'] ?? 0);
    }
}

if (!function_exists('dcmt_resolve_treatment_plan_defaults')) {
    /**
     * Resolve default doctor + service configured on an odontogram treatment.
     *
     * @return array{doctor_id: int, service_id: int}
     */
    function dcmt_resolve_treatment_plan_defaults(PDO $pdo, string $treatmentName): array
    {
        $treatmentName = trim($treatmentName);
        $result = ['doctor_id' => 0, 'service_id' => 0];
        if ($treatmentName === '') {
            return $result;
        }

        dcmt_ensure_odontogram_treatment_doctor_column($pdo);

        try {
            $stmt = $pdo->prepare("
                SELECT dcmt_doctor_user_id, dcmt_service_id
                FROM dcmt_odontogram_treatments
                WHERE dcmt_name = ? AND dcmt_status = 'active'
                LIMIT 1
            ");
            $stmt->execute([$treatmentName]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row) {
                $result['doctor_id'] = (int) ($row['dcmt_doctor_user_id'] ?? 0);
                $result['service_id'] = (int) ($row['dcmt_service_id'] ?? 0);
            }
        } catch (PDOException $e) {
            error_log('dcmt_resolve_treatment_plan_defaults: ' . $e->getMessage());
        }

        if ($result['service_id'] > 0) {
            return $result;
        }

        $defaults = dcmt_odontogram_treatment_default_service_names();
        $serviceName = $defaults[$treatmentName] ?? null;
        if ($serviceName === null) {
            return $result;
        }

        try {
            $stmt = $pdo->prepare("SELECT dcmt_id FROM dcmt_services WHERE dcmt_name = ? AND dcmt_status = 'active' LIMIT 1");
            $stmt->execute([$serviceName]);
            $result['service_id'] = (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('dcmt_resolve_treatment_plan_defaults fallback: ' . $e->getMessage());
        }

        return $result;
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

if (!function_exists('dcmt_treatment_plan_default_group')) {
    /**
     * Default proposal group used when a plan is generated for the first time.
     *
     * @return array{id: string, name: string}
     */
    function dcmt_treatment_plan_default_group(): array
    {
        $name = function_exists('trans') ? trans('patient', 'treatment_plan_group_default_name') : '';
        if (!is_string($name) || $name === '' || $name === 'treatment_plan_group_default_name') {
            $name = 'Visit 1';
        }

        return ['id' => 'grp_1', 'name' => $name];
    }
}

if (!function_exists('dcmt_treatment_plan_normalize_group_fields')) {
    /**
     * Normalize the group membership stored on a proposal line.
     * Additional (manual) service lines are never part of a group.
     *
     * @param array<string, mixed> $line
     * @return array{group_id: string, group_name: string, group_confirmed: bool}
     */
    function dcmt_treatment_plan_normalize_group_fields(array $line): array
    {
        if (!empty($line['is_additional'])) {
            return ['group_id' => '', 'group_name' => '', 'group_confirmed' => false];
        }

        $default = dcmt_treatment_plan_default_group();
        $groupId = trim((string) ($line['group_id'] ?? ''));
        $groupName = trim((string) ($line['group_name'] ?? ''));

        if ($groupId === '') {
            $groupId = $default['id'];
        }
        if ($groupName === '') {
            $groupName = $groupId === $default['id']
                ? $default['name']
                : $groupId;
        }

        return [
            'group_id' => $groupId,
            'group_name' => $groupName,
            'group_confirmed' => !empty($line['group_confirmed']),
        ];
    }
}

if (!function_exists('dcmt_build_treatment_plan_lines')) {
    /**
     * @return list<array<string, mixed>>
     */
    function dcmt_build_treatment_plan_lines(PDO $pdo, array $solutionChart, int $doctorUserId = 0): array
    {
        $groups = dcmt_patient_odontogram_solution_plan_groups($solutionChart);
        $showInPlanMap = dcmt_odontogram_treatments_show_in_plan_map($pdo);
        $defaultGroup = dcmt_treatment_plan_default_group();
        $lines = [];
        $lineIndex = 0;
        $catalogCache = [];

        foreach ($groups as $group) {
            $treatmentName = trim((string) ($group['treatment'] ?? ''));
            if ($treatmentName === '' || ($showInPlanMap[$treatmentName] ?? true) === false) {
                continue;
            }
            if (dcmt_odontogram_is_chart_only_treatment($treatmentName)) {
                continue;
            }
            $quantity = (int) $group['quantity'];
            $defaults = dcmt_resolve_treatment_plan_defaults($pdo, $treatmentName);
            $lineDoctorId = (int) ($defaults['doctor_id'] ?? 0);
            if ($lineDoctorId <= 0) {
                $lineDoctorId = $doctorUserId > 0 ? $doctorUserId : 0;
            }
            $serviceId = (int) ($defaults['service_id'] ?? 0);
            $serviceName = '';
            $unitPrice = 0.0;

            if ($serviceId > 0) {
                if ($lineDoctorId > 0) {
                    if (!isset($catalogCache[$lineDoctorId])) {
                        $catalogCache[$lineDoctorId] = [];
                        foreach (dcmt_fetch_active_services_catalog($pdo, $lineDoctorId) as $svc) {
                            $catalogCache[$lineDoctorId][(int) $svc['id']] = $svc;
                        }
                    }
                    if (!isset($catalogCache[$lineDoctorId][$serviceId])) {
                        // Default service is not assigned to this doctor — keep doctor, clear service.
                        $serviceId = 0;
                    } else {
                        $serviceName = (string) ($catalogCache[$lineDoctorId][$serviceId]['name'] ?? '');
                        $unitPrice = (float) ($catalogCache[$lineDoctorId][$serviceId]['price'] ?? 0);
                    }
                } else {
                    $unitPrice = dcmt_get_service_price_for_doctor($pdo, 0, $serviceId);
                    try {
                        $nameStmt = $pdo->prepare("SELECT dcmt_name FROM dcmt_services WHERE dcmt_id = ? LIMIT 1");
                        $nameStmt->execute([$serviceId]);
                        $serviceName = (string) ($nameStmt->fetchColumn() ?: '');
                    } catch (PDOException $e) {
                        $serviceName = '';
                    }
                }
            }

            $lines[] = [
                'line_id' => 'line_' . (++$lineIndex),
                'treatment' => $treatmentName,
                'zone' => $group['zone'],
                'quadrant' => $group['quadrant'],
                'quadrant_label' => $group['quadrant_label'],
                'zone_label' => $group['zone_label'],
                'quantity' => $quantity,
                'doctor_id' => $lineDoctorId,
                'service_id' => $serviceId,
                'service_name' => $serviceName,
                'unit_price' => $serviceId > 0 ? round($unitPrice, 2) : 0.0,
                'subtotal' => $serviceId > 0 ? round($unitPrice * $quantity, 2) : 0.0,
                'selected' => true,
                'group_id' => $defaultGroup['id'],
                'group_name' => $defaultGroup['name'],
                'group_confirmed' => false,
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

if (!function_exists('dcmt_treatment_plan_clinical_quadrant_pair_totals')) {
    /**
     * Sum selected odontogram line subtotals by clinical quadrant pair (P+A per Q1, Q2, Q4, Q3).
     *
     * @param list<array<string, mixed>> $odontogramLines
     * @return list<array{quadrant: string, label: string, total: float}>
     */
    function dcmt_treatment_plan_clinical_quadrant_pair_totals(array $odontogramLines): array
    {
        $totalsByQuadrant = array_fill_keys(dcmt_patient_odontogram_clinical_quadrant_keys(), 0.0);

        foreach ($odontogramLines as $line) {
            if (!is_array($line) || empty($line['selected'])) {
                continue;
            }
            $quadrant = (string) ($line['quadrant'] ?? '');
            if (!array_key_exists($quadrant, $totalsByQuadrant)) {
                continue;
            }
            $totalsByQuadrant[$quadrant] += (float) ($line['subtotal'] ?? 0);
        }

        $rows = [];
        foreach (dcmt_patient_odontogram_clinical_quadrant_keys() as $quadrant) {
            $qLabel = dcmt_patient_odontogram_quadrant_label($quadrant);
            $rows[] = [
                'quadrant' => $quadrant,
                'label' => 'P' . $qLabel . ', A' . $qLabel,
                'total' => round($totalsByQuadrant[$quadrant], 2),
            ];
        }

        return $rows;
    }
}

if (!function_exists('dcmt_validate_treatment_plan_odontogram_lines')) {
    /**
     * @param list<array<string, mixed>> $lines
     */
    function dcmt_validate_treatment_plan_odontogram_lines(array $lines): ?string
    {
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (dcmt_treatment_plan_is_additional_line($line)) {
                continue;
            }
            if (isset($line['selected']) && empty($line['selected'])) {
                continue;
            }
            $doctorId = (int) ($line['doctor_id'] ?? 0);
            $serviceId = (int) ($line['service_id'] ?? 0);
            if ($doctorId <= 0 || $serviceId <= 0) {
                return trans('patient', 'treatment_plan_line_doctor_service_required');
            }
        }

        return null;
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

        $catalogCache = [];
        $lines = [];
        $index = 0;
        foreach ($raw as $line) {
            if (!is_array($line)) {
                continue;
            }
            // Drop previously saved chart-only odontogram rows (e.g. "Healthy") from the proposed plan.
            $lineTreatment = trim((string) ($line['treatment'] ?? ''));
            if (empty($line['is_additional']) && dcmt_odontogram_is_chart_only_treatment($lineTreatment)) {
                continue;
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $groupFields = dcmt_treatment_plan_normalize_group_fields($line);
            $lineDoctorId = (int) ($line['doctor_id'] ?? 0);
            if ($lineDoctorId <= 0) {
                $lineDoctorId = $doctorUserId > 0 ? $doctorUserId : 0;
            }
            $serviceId = (int) ($line['service_id'] ?? 0);
            $unitPrice = 0.0;
            $serviceName = '';

            if ($lineDoctorId > 0 && !isset($catalogCache[$lineDoctorId])) {
                $catalogCache[$lineDoctorId] = [];
                foreach (dcmt_fetch_active_services_catalog($pdo, $lineDoctorId) as $service) {
                    $catalogCache[$lineDoctorId][(int) $service['id']] = $service;
                }
            }

            if ($serviceId > 0) {
                $unitPrice = isset($line['unit_price']) ? (float) $line['unit_price'] : 0.0;
                if ($lineDoctorId > 0 && isset($catalogCache[$lineDoctorId][$serviceId])) {
                    if ($unitPrice <= 0) {
                        $unitPrice = (float) $catalogCache[$lineDoctorId][$serviceId]['price'];
                    }
                    $serviceName = (string) $catalogCache[$lineDoctorId][$serviceId]['name'];
                } elseif ($lineDoctorId > 0) {
                    // Service not available for this doctor — do not keep a stale price.
                    $serviceId = 0;
                    $unitPrice = 0.0;
                    $serviceName = '';
                } else {
                    if ($unitPrice <= 0) {
                        $unitPrice = dcmt_get_service_price_for_doctor($pdo, 0, $serviceId);
                    }
                    $serviceName = trim((string) ($line['service_name'] ?? ''));
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
                'doctor_id' => $lineDoctorId,
                'service_id' => $serviceId,
                'service_name' => $serviceName,
                'unit_price' => $serviceId > 0 ? round($unitPrice, 2) : 0.0,
                'subtotal' => $serviceId > 0 ? round($unitPrice * $quantity, 2) : 0.0,
                'selected' => !isset($line['selected']) || !empty($line['selected']),
                'is_additional' => !empty($line['is_additional']),
                'scheduled_appointment_id' => (int) ($line['scheduled_appointment_id'] ?? 0),
                'scheduled_start_at' => (string) ($line['scheduled_start_at'] ?? ''),
                'group_id' => $groupFields['group_id'],
                'group_name' => $groupFields['group_name'],
                'group_confirmed' => $groupFields['group_confirmed'],
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

if (!function_exists('dcmt_treatment_plan_find_line')) {
    /**
     * Find a treatment plan line by its stable line_id.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, mixed>|null
     */
    function dcmt_treatment_plan_find_line(array $lines, string $lineId): ?array
    {
        $lineId = trim($lineId);
        if ($lineId === '') {
            return null;
        }
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if ((string) ($line['line_id'] ?? '') === $lineId) {
                return $line;
            }
        }
        return null;
    }
}

if (!function_exists('dcmt_treatment_plan_line_reason')) {
    /**
     * Default appointment reason for a treatment plan line.
     *
     * @param array<string, mixed> $line
     */
    function dcmt_treatment_plan_line_reason(array $line): string
    {
        $treatment = trim((string) ($line['treatment'] ?? ''));
        if ($treatment === '') {
            $treatment = trim((string) ($line['service_name'] ?? ''));
        }
        $quantity = max(1, (int) ($line['quantity'] ?? 1));
        if ($quantity > 1) {
            $treatment .= ' x' . $quantity;
        }

        $zoneLabel = trim((string) ($line['zone_label'] ?? ''));
        $quadrantLabel = trim((string) ($line['quadrant_label'] ?? ''));
        $location = trim($zoneLabel . ($zoneLabel !== '' && $quadrantLabel !== '' ? ' - ' : '') . $quadrantLabel);
        if ($location === '' && !empty($line['is_additional'])) {
            $location = '';
        }

        return $location !== '' ? $treatment . ' (' . $location . ')' : $treatment;
    }
}

if (!function_exists('dcmt_treatment_plan_mark_line_scheduled')) {
    /**
     * Link a created appointment to a saved treatment plan line.
     */
    function dcmt_treatment_plan_mark_line_scheduled(
        PDO $pdo,
        int $patientId,
        string $lineId,
        int $appointmentId,
        string $startAt = ''
    ): bool {
        $lineId = trim($lineId);
        if ($patientId <= 0 || $lineId === '' || $appointmentId <= 0 || !dcmt_treatment_plan_table_exists($pdo)) {
            return false;
        }

        try {
            $stmt = $pdo->prepare('SELECT dcmt_lines_json FROM dcmt_patient_treatment_plans WHERE dcmt_patient_id = ? LIMIT 1');
            $stmt->execute([$patientId]);
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null) {
                return false;
            }

            $lines = json_decode((string) $raw, true);
            if (!is_array($lines)) {
                return false;
            }

            $changed = false;
            foreach ($lines as &$line) {
                if (!is_array($line) || (string) ($line['line_id'] ?? '') !== $lineId) {
                    continue;
                }
                $line['scheduled_appointment_id'] = $appointmentId;
                $line['scheduled_start_at'] = $startAt;
                $changed = true;
                break;
            }
            unset($line);

            if (!$changed) {
                return false;
            }

            $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return false;
            }

            $upd = $pdo->prepare('UPDATE dcmt_patient_treatment_plans SET dcmt_lines_json = ? WHERE dcmt_patient_id = ?');
            $upd->execute([$json, $patientId]);
            return $upd->rowCount() > 0;
        } catch (PDOException $e) {
            error_log('dcmt_treatment_plan_mark_line_scheduled: ' . $e->getMessage());
            return false;
        }
    }
}

if (!function_exists('dcmt_treatment_plan_lines_for_appointment')) {
    /**
     * Plan lines that were scheduled into the given appointment.
     *
     * @return list<array<string, mixed>>
     */
    function dcmt_treatment_plan_lines_for_appointment(PDO $pdo, int $patientId, int $appointmentId): array
    {
        if ($patientId <= 0 || $appointmentId <= 0) {
            return [];
        }

        $plan = dcmt_fetch_patient_treatment_plan($pdo, $patientId);
        if ($plan === null) {
            return [];
        }

        $lines = [];
        foreach ($plan['lines'] as $line) {
            if (!is_array($line)) {
                continue;
            }
            if ((int) ($line['scheduled_appointment_id'] ?? 0) !== $appointmentId) {
                continue;
            }
            $lines[] = $line;
        }

        return $lines;
    }
}

if (!function_exists('dcmt_treatment_plan_mark_group_scheduled')) {
    /**
     * Link one created appointment to every plan line of a visit group.
     *
     * @return list<string> line ids that were linked
     */
    function dcmt_treatment_plan_mark_group_scheduled(
        PDO $pdo,
        int $patientId,
        string $groupId,
        int $appointmentId,
        string $startAt = ''
    ): array {
        $groupId = trim($groupId);
        if ($patientId <= 0 || $groupId === '' || $appointmentId <= 0 || !dcmt_treatment_plan_table_exists($pdo)) {
            return [];
        }

        try {
            $stmt = $pdo->prepare('SELECT dcmt_lines_json FROM dcmt_patient_treatment_plans WHERE dcmt_patient_id = ? LIMIT 1');
            $stmt->execute([$patientId]);
            $raw = $stmt->fetchColumn();
            if ($raw === false || $raw === null) {
                return [];
            }

            $lines = json_decode((string) $raw, true);
            if (!is_array($lines)) {
                return [];
            }

            $linked = [];
            foreach ($lines as &$line) {
                if (!is_array($line) || !empty($line['is_additional'])) {
                    continue;
                }
                $fields = dcmt_treatment_plan_normalize_group_fields($line);
                if ($fields['group_id'] !== $groupId) {
                    continue;
                }
                $line['scheduled_appointment_id'] = $appointmentId;
                $line['scheduled_start_at'] = $startAt;
                $linked[] = (string) ($line['line_id'] ?? '');
            }
            unset($line);

            if ($linked === []) {
                return [];
            }

            $json = json_encode($lines, JSON_UNESCAPED_UNICODE);
            if ($json === false) {
                return [];
            }

            $upd = $pdo->prepare('UPDATE dcmt_patient_treatment_plans SET dcmt_lines_json = ? WHERE dcmt_patient_id = ?');
            $upd->execute([$json, $patientId]);

            return array_values(array_filter($linked, static function (string $id): bool {
                return $id !== '';
            }));
        } catch (PDOException $e) {
            error_log('dcmt_treatment_plan_mark_group_scheduled: ' . $e->getMessage());
            return [];
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

if (!function_exists('dcmt_treatment_plan_is_additional_line')) {
    function dcmt_treatment_plan_is_additional_line(array $line): bool
    {
        return !empty($line['is_additional']);
    }
}

if (!function_exists('dcmt_treatment_plan_split_lines')) {
    /**
     * @param list<array<string, mixed>> $lines
     * @return array{odontogram: list<array<string, mixed>>, additional: list<array<string, mixed>>}
     */
    function dcmt_treatment_plan_split_lines(array $lines): array
    {
        $odontogram = [];
        $additional = [];
        foreach ($lines as $line) {
            if (!is_array($line)) {
                continue;
            }
            if (dcmt_treatment_plan_is_additional_line($line)) {
                $additional[] = $line;
            } else {
                $odontogram[] = $line;
            }
        }
        return ['odontogram' => $odontogram, 'additional' => $additional];
    }
}

if (!function_exists('dcmt_treatment_plan_group_lines')) {
    /**
     * Aggregate proposal lines into visit groups (first-appearance order).
     * Additional service lines are not grouped.
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    function dcmt_treatment_plan_group_lines(array $lines): array
    {
        $default = dcmt_treatment_plan_default_group();
        $groups = [];

        foreach ($lines as $line) {
            if (!is_array($line) || dcmt_treatment_plan_is_additional_line($line)) {
                continue;
            }

            $fields = dcmt_treatment_plan_normalize_group_fields($line);
            $groupId = $fields['group_id'];
            if (!isset($groups[$groupId])) {
                $groups[$groupId] = [
                    'id' => $groupId,
                    'name' => $fields['group_name'] !== '' ? $fields['group_name'] : $default['name'],
                    'confirmed' => false,
                    'lines' => [],
                    'line_count' => 0,
                    'selected_count' => 0,
                    'treatment_count' => 0,
                    'subtotal' => 0.0,
                    'scheduled_appointment_id' => 0,
                    'scheduled_start_at' => '',
                    'scheduled_count' => 0,
                ];
            }

            $groups[$groupId]['lines'][] = $line;
            $groups[$groupId]['line_count']++;
            if (!empty($line['group_confirmed'])) {
                $groups[$groupId]['confirmed'] = true;
            }
            if (!empty($line['selected'])) {
                $groups[$groupId]['selected_count']++;
                $groups[$groupId]['treatment_count'] += max(1, (int) ($line['quantity'] ?? 1));
                $groups[$groupId]['subtotal'] += (float) ($line['subtotal'] ?? 0);
            }
            $lineAppointmentId = (int) ($line['scheduled_appointment_id'] ?? 0);
            if ($lineAppointmentId > 0) {
                $groups[$groupId]['scheduled_count']++;
                if ($groups[$groupId]['scheduled_appointment_id'] <= 0) {
                    $groups[$groupId]['scheduled_appointment_id'] = $lineAppointmentId;
                    $groups[$groupId]['scheduled_start_at'] = (string) ($line['scheduled_start_at'] ?? '');
                }
            }
        }

        $out = [];
        foreach ($groups as $group) {
            $group['subtotal'] = round((float) $group['subtotal'], 2);
            $out[] = $group;
        }

        return $out;
    }
}

if (!function_exists('dcmt_treatment_plan_group_order_map')) {
    /**
     * Group order map derived from the first appearance of each group in the line list.
     *
     * @param list<array<string, mixed>> $lines
     * @return array<string, int>
     */
    function dcmt_treatment_plan_group_order_map(array $lines): array
    {
        $map = [];
        $index = 0;
        foreach ($lines as $line) {
            if (!is_array($line) || dcmt_treatment_plan_is_additional_line($line)) {
                continue;
            }
            $groupId = trim((string) ($line['group_id'] ?? ''));
            if ($groupId === '') {
                $groupId = dcmt_treatment_plan_default_group()['id'];
            }
            if (!isset($map[$groupId])) {
                $map[$groupId] = $index++;
            }
        }

        return $map;
    }
}

if (!function_exists('dcmt_treatment_plan_group_reason')) {
    /**
     * Appointment reason for a whole group, e.g. "Visit 2: Corona x2, Endodoncia".
     *
     * @param array<string, mixed> $group
     */
    function dcmt_treatment_plan_group_reason(array $group): string
    {
        $counts = [];
        foreach (($group['lines'] ?? []) as $line) {
            if (!is_array($line) || empty($line['selected'])) {
                continue;
            }
            $treatment = trim((string) ($line['treatment'] ?? ''));
            if ($treatment === '') {
                $treatment = trim((string) ($line['service_name'] ?? ''));
            }
            if ($treatment === '') {
                continue;
            }
            $quantity = max(1, (int) ($line['quantity'] ?? 1));
            $counts[$treatment] = (int) ($counts[$treatment] ?? 0) + $quantity;
        }

        $parts = [];
        foreach ($counts as $treatment => $quantity) {
            $parts[] = $quantity > 1 ? $treatment . ' x' . $quantity : $treatment;
        }

        $label = trim((string) ($group['name'] ?? ''));
        $list = implode(', ', $parts);
        $reason = $label !== '' ? $label . ': ' . $list : $list;

        if (function_exists('mb_substr')) {
            return mb_substr($reason, 0, 255);
        }

        return substr($reason, 0, 255);
    }
}

if (!function_exists('dcmt_sort_treatment_plan_odontogram_lines')) {
    /**
     * Sort odontogram plan lines by visit group, then zone-wise (Posterior Q1–Q4, Anterior Q1–Q4).
     *
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    function dcmt_sort_treatment_plan_odontogram_lines(array $lines): array
    {
        $groupOrder = dcmt_treatment_plan_group_order_map($lines);

        usort($lines, static function (array $a, array $b) use ($groupOrder): int {
            $aGroup = trim((string) ($a['group_id'] ?? ''));
            $bGroup = trim((string) ($b['group_id'] ?? ''));
            $aGroupIndex = $groupOrder[$aGroup] ?? PHP_INT_MAX;
            $bGroupIndex = $groupOrder[$bGroup] ?? PHP_INT_MAX;
            if ($aGroupIndex !== $bGroupIndex) {
                return $aGroupIndex <=> $bGroupIndex;
            }

            $cmp = dcmt_patient_odontogram_zone_wise_cell_sort_index(
                (string) ($a['zone'] ?? ''),
                (string) ($a['quadrant'] ?? '')
            ) <=> dcmt_patient_odontogram_zone_wise_cell_sort_index(
                (string) ($b['zone'] ?? ''),
                (string) ($b['quadrant'] ?? '')
            );
            if ($cmp !== 0) {
                return $cmp;
            }

            return strcmp((string) ($a['treatment'] ?? ''), (string) ($b['treatment'] ?? ''));
        });

        return $lines;
    }
}

if (!function_exists('dcmt_treatment_plan_extract_additional_lines')) {
    /**
     * @param list<array<string, mixed>> $lines
     * @return list<array<string, mixed>>
     */
    function dcmt_treatment_plan_extract_additional_lines(array $lines): array
    {
        return dcmt_treatment_plan_split_lines($lines)['additional'];
    }
}

if (!function_exists('dcmt_treatment_plan_merge_with_additional_lines')) {
    /**
     * @param list<array<string, mixed>> $odontogramLines
     * @param list<array<string, mixed>> $additionalLines
     * @return list<array<string, mixed>>
     */
    function dcmt_treatment_plan_merge_with_additional_lines(array $odontogramLines, array $additionalLines): array
    {
        if ($additionalLines === []) {
            return $odontogramLines;
        }
        return array_merge($odontogramLines, $additionalLines);
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
            if (dcmt_treatment_plan_is_additional_line($line)) {
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
            $prevDoctorId = (int) ($prev['doctor_id'] ?? 0);
            if ($prevDoctorId > 0) {
                $line['doctor_id'] = $prevDoctorId;
            }
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
            // Keep the visit group chosen by the clinic for this treatment.
            if (trim((string) ($prev['group_id'] ?? '')) !== '') {
                $line['group_id'] = (string) $prev['group_id'];
                $line['group_name'] = (string) ($prev['group_name'] ?? '');
                $line['group_confirmed'] = !empty($prev['group_confirmed']);
            }
            if ((int) ($prev['scheduled_appointment_id'] ?? 0) > 0) {
                $line['scheduled_appointment_id'] = (int) $prev['scheduled_appointment_id'];
                $line['scheduled_start_at'] = (string) ($prev['scheduled_start_at'] ?? '');
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
            // Solution cleared — keep additional services and notes if a plan exists.
            if ($savedPlan) {
                $doctorId = $doctorUserId !== null ? (int) $doctorUserId : (int) ($savedPlan['dcmt_doctor_user_id'] ?? 0);
                $additionalLines = dcmt_normalize_treatment_plan_lines(
                    dcmt_treatment_plan_extract_additional_lines($savedPlan['lines'] ?? []),
                    $pdo,
                    $doctorId
                );
                dcmt_save_patient_treatment_plan(
                    $pdo,
                    $patientId,
                    $doctorId,
                    $summary,
                    $additionalLines,
                    (string) ($savedPlan['dcmt_notes'] ?? ''),
                    $createdBy
                );
                return ['synced' => true, 'rebuilt' => true, 'lines' => $additionalLines, 'summary' => $summary];
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
        $additionalLines = $savedPlan
            ? dcmt_normalize_treatment_plan_lines(
                dcmt_treatment_plan_extract_additional_lines($savedPlan['lines'] ?? []),
                $pdo,
                $doctorId
            )
            : [];
        $newLines = dcmt_treatment_plan_merge_with_additional_lines($newLines, $additionalLines);

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
