<?php
/**
 * Odontogram clinical treatments (configurable) — not chart surface states.
 * Odontogram problem states (Healthy, Damaged, …) with configurable colors.
 */

if (!function_exists('dcmt_sanitize_odontogram_hex_color')) {
    function dcmt_sanitize_odontogram_hex_color(string $color, string $fallback = '#6C757D'): string
    {
        $color = strtoupper(trim($color));
        if (preg_match('/^#[0-9A-F]{6}$/', $color)) {
            return $color;
        }
        return strtoupper($fallback);
    }
}

if (!function_exists('dcmt_odontogram_derive_stroke_color')) {
    function dcmt_odontogram_derive_stroke_color(string $fill): string
    {
        $fill = dcmt_sanitize_odontogram_hex_color($fill);
        $hex = substr($fill, 1);
        $r = max(0, hexdec(substr($hex, 0, 2)) - 45);
        $g = max(0, hexdec(substr($hex, 2, 2)) - 45);
        $b = max(0, hexdec(substr($hex, 4, 2)) - 45);
        return sprintf('#%02X%02X%02X', $r, $g, $b);
    }
}

if (!function_exists('dcmt_odontogram_problem_state_keys')) {
    /**
     * @return list<string>
     */
    function dcmt_odontogram_problem_state_keys(): array
    {
        return ['default', 'damaged', 'filling', 'missing', 'crown', 'implant'];
    }
}

if (!function_exists('dcmt_odontogram_problem_state_defaults')) {
    /**
     * @return array<string, array{fill: string, stroke: string}>
     */
    function dcmt_odontogram_problem_state_defaults(): array
    {
        return [
            'default' => ['fill' => '#F1F3F5', 'stroke' => '#5C6570'],
            'damaged' => ['fill' => '#FFAB91', 'stroke' => '#D84315'],
            'filling' => ['fill' => '#81D4FA', 'stroke' => '#0277BD'],
            'missing' => ['fill' => '#808080', 'stroke' => '#595959'],
            'crown' => ['fill' => '#FFCA28', 'stroke' => '#F57F17'],
            'implant' => ['fill' => '#81C784', 'stroke' => '#2E7D32'],
        ];
    }
}

if (!function_exists('dcmt_ensure_odontogram_problem_states_table')) {
    function dcmt_ensure_odontogram_problem_states_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_odontogram_problem_states (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_state_key VARCHAR(20) NOT NULL,
                dcmt_color VARCHAR(7) NOT NULL,
                dcmt_stroke_color VARCHAR(7) NOT NULL,
                dcmt_sort_order INT NOT NULL DEFAULT 0,
                dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_odontogram_problem_state (dcmt_state_key)
            )
        ");
        dcmt_seed_odontogram_problem_states($pdo);
    }
}

if (!function_exists('dcmt_seed_odontogram_problem_states')) {
    function dcmt_seed_odontogram_problem_states(PDO $pdo): void
    {
        $defaults = dcmt_odontogram_problem_state_defaults();
        $sort = 0;
        $stmt = $pdo->prepare("
            INSERT INTO dcmt_odontogram_problem_states
                (dcmt_state_key, dcmt_color, dcmt_stroke_color, dcmt_sort_order)
            VALUES (?, ?, ?, ?)
            ON DUPLICATE KEY UPDATE dcmt_state_key = dcmt_state_key
        ");
        foreach (dcmt_odontogram_problem_state_keys() as $key) {
            $colors = $defaults[$key] ?? ['fill' => '#6C757D', 'stroke' => '#495057'];
            $stmt->execute([$key, $colors['fill'], $colors['stroke'], $sort]);
            $sort++;
        }
    }
}

if (!function_exists('dcmt_get_odontogram_problem_states')) {
    /**
     * @return list<array<string, mixed>>
     */
    function dcmt_get_odontogram_problem_states(PDO $pdo): array
    {
        dcmt_ensure_odontogram_problem_states_table($pdo);
        $stmt = $pdo->query('SELECT * FROM dcmt_odontogram_problem_states ORDER BY dcmt_sort_order ASC, dcmt_state_key ASC');
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}

if (!function_exists('dcmt_odontogram_problem_states_map')) {
    /**
     * @return array<string, array{fill: string, stroke: string}>
     */
    function dcmt_odontogram_problem_states_map(PDO $pdo): array
    {
        $defaults = dcmt_odontogram_problem_state_defaults();
        $map = $defaults;
        foreach (dcmt_get_odontogram_problem_states($pdo) as $row) {
            $key = (string) ($row['dcmt_state_key'] ?? '');
            if ($key === '' || !isset($defaults[$key])) {
                continue;
            }
            $map[$key] = [
                'fill' => dcmt_sanitize_odontogram_hex_color((string) $row['dcmt_color'], $defaults[$key]['fill']),
                'stroke' => dcmt_sanitize_odontogram_hex_color((string) $row['dcmt_stroke_color'], $defaults[$key]['stroke']),
            ];
        }
        return $map;
    }
}

if (!function_exists('dcmt_odontogram_problem_states_json_for_chart')) {
    function dcmt_odontogram_problem_states_json_for_chart(PDO $pdo): string
    {
        $json = json_encode(dcmt_odontogram_problem_states_map($pdo), JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '{}';
    }
}

if (!function_exists('dcmt_update_odontogram_problem_state_color')) {
    function dcmt_update_odontogram_problem_state_color(PDO $pdo, string $stateKey, string $fillColor): bool
    {
        $allowed = array_fill_keys(dcmt_odontogram_problem_state_keys(), true);
        if (!isset($allowed[$stateKey])) {
            return false;
        }
        dcmt_ensure_odontogram_problem_states_table($pdo);
        $fill = dcmt_sanitize_odontogram_hex_color($fillColor);
        $stroke = dcmt_odontogram_derive_stroke_color($fill);
        $stmt = $pdo->prepare('
            UPDATE dcmt_odontogram_problem_states
            SET dcmt_color = ?, dcmt_stroke_color = ?
            WHERE dcmt_state_key = ?
        ');
        return $stmt->execute([$fill, $stroke, $stateKey]);
    }
}

if (!function_exists('dcmt_odontogram_default_treatment_color')) {
    function dcmt_odontogram_default_treatment_color(): string
    {
        return '#0D6EFD';
    }
}

if (!function_exists('dcmt_ensure_odontogram_treatments_table')) {
    function dcmt_ensure_odontogram_treatments_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_odontogram_treatments (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_name VARCHAR(100) NOT NULL,
                dcmt_description TEXT,
                dcmt_zone ENUM('anterior', 'posterior', 'both') NOT NULL DEFAULT 'both',
                dcmt_tooth_state ENUM('damaged', 'filling', 'missing', 'crown', 'implant') NULL,
                dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
                dcmt_sort_order INT NOT NULL DEFAULT 0,
                dcmt_created_by VARCHAR(50) NOT NULL,
                dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_odontogram_treatment_name (dcmt_name)
            )
        ");

        dcmt_migrate_odontogram_treatments_schema($pdo);
        dcmt_seed_odontogram_clinical_treatments($pdo);
        dcmt_ensure_odontogram_problem_states_table($pdo);
    }
}

if (!function_exists('dcmt_migrate_odontogram_treatments_schema')) {
    function dcmt_migrate_odontogram_treatments_schema(PDO $pdo): void
    {
        $cols = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM dcmt_odontogram_treatments');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[$row['Field']] = true;
            }
        } catch (PDOException $e) {
            return;
        }

        if (!isset($cols['dcmt_tooth_state'])) {
            try {
                $pdo->exec("
                    ALTER TABLE dcmt_odontogram_treatments
                    ADD COLUMN dcmt_tooth_state ENUM('damaged', 'filling', 'missing', 'crown', 'implant') NULL
                    AFTER dcmt_zone
                ");
            } catch (PDOException $e) {
                // ignore if already exists
            }
        }

        if (!isset($cols['dcmt_color'])) {
            try {
                $pdo->exec("
                    ALTER TABLE dcmt_odontogram_treatments
                    ADD COLUMN dcmt_color VARCHAR(7) NULL
                    AFTER dcmt_tooth_state
                ");
            } catch (PDOException $e) {
                // ignore if already exists
            }
        }

        // One-time cleanup of legacy chart-state rows (do not delete current system clinical treatments).
        if (isset($cols['dcmt_state_key']) || isset($cols['dcmt_applies_whole_tooth'])) {
            $legacy = ['Damaged', 'Filling', 'Missing', 'Crown', 'Implant'];
            $placeholders = implode(',', array_fill(0, count($legacy), '?'));
            $del = $pdo->prepare("DELETE FROM dcmt_odontogram_treatments WHERE dcmt_name IN ($placeholders)");
            $del->execute($legacy);

            if (isset($cols['dcmt_state_key'])) {
                try {
                    $pdo->exec('ALTER TABLE dcmt_odontogram_treatments DROP COLUMN dcmt_state_key');
                } catch (PDOException $e) {
                    // ignore
                }
            }
            if (isset($cols['dcmt_applies_whole_tooth'])) {
                try {
                    $pdo->exec('ALTER TABLE dcmt_odontogram_treatments DROP COLUMN dcmt_applies_whole_tooth');
                } catch (PDOException $e) {
                    // ignore
                }
            }
        }
    }
}

if (!function_exists('dcmt_seed_odontogram_clinical_treatments')) {
    function dcmt_seed_odontogram_clinical_treatments(PDO $pdo): void
    {
        $legacy = ['Damaged', 'Filling', 'Missing', 'Crown', 'Implant'];
        $placeholders = implode(',', array_fill(0, count($legacy), '?'));
        $del = $pdo->prepare("DELETE FROM dcmt_odontogram_treatments WHERE dcmt_name IN ($placeholders)");
        $del->execute($legacy);

        $count = (int) $pdo->query('SELECT COUNT(*) FROM dcmt_odontogram_treatments')->fetchColumn();
        if ($count > 0) {
            return;
        }

        $defaults = [
            ['Resina', 'both', 'filling'],
            ['Sellador', 'both', 'filling'],
            ['Ionomero', 'both', 'filling'],
            ['Incrustacion', 'both', 'filling'],
            ['Endodoncia', 'both', 'damaged'],
            ['Corona', 'both', 'crown'],
        ];

        $stmt = $pdo->prepare("
            INSERT INTO dcmt_odontogram_treatments
                (dcmt_name, dcmt_description, dcmt_zone, dcmt_tooth_state, dcmt_status, dcmt_created_by)
            VALUES (?, '', ?, ?, 'active', 'system')
        ");

        foreach ($defaults as $row) {
            $stmt->execute([$row[0], $row[1], $row[2]]);
        }
    }
}

if (!function_exists('dcmt_odontogram_treatment_allowed_tooth_states')) {
    /**
     * @return array<string, true>
     */
    function dcmt_odontogram_treatment_allowed_tooth_states(): array
    {
        return array_fill_keys(['damaged', 'filling', 'missing', 'crown', 'implant'], true);
    }
}

if (!function_exists('dcmt_get_active_odontogram_treatments')) {
    /**
     * @return list<array<string, mixed>>
     */
    function dcmt_get_active_odontogram_treatments(PDO $pdo, bool $includeInactive = false): array
    {
        dcmt_ensure_odontogram_treatments_table($pdo);

        $sql = 'SELECT * FROM dcmt_odontogram_treatments';
        if (!$includeInactive) {
            $sql .= " WHERE dcmt_status = 'active'";
        }
        $sql .= ' ORDER BY dcmt_name ASC';

        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}

if (!function_exists('dcmt_odontogram_treatments_json_for_chart')) {
    function dcmt_odontogram_treatments_json_for_chart(PDO $pdo): string
    {
        $rows = dcmt_get_active_odontogram_treatments($pdo);
        $out = [];
        foreach ($rows as $row) {
            $color = !empty($row['dcmt_color'])
                ? dcmt_sanitize_odontogram_hex_color((string) $row['dcmt_color'])
                : dcmt_odontogram_default_treatment_color();
            $out[] = [
                'id' => (int) $row['dcmt_id'],
                'name' => $row['dcmt_name'],
                'zone' => $row['dcmt_zone'],
                'toothState' => !empty($row['dcmt_tooth_state']) ? (string) $row['dcmt_tooth_state'] : null,
                'color' => $color,
            ];
        }
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '[]';
    }
}

if (!function_exists('dcmt_odontogram_collect_patient_usage_maps')) {
    /**
     * Scan patient odontogram records for treatment names and problem states in use.
     *
     * @return array{treatments: array<string, true>, states: array<string, true>}
     */
    function dcmt_odontogram_collect_patient_usage_maps(PDO $pdo): array
    {
        static $cache = null;
        if (is_array($cache)) {
            return $cache;
        }

        require_once __DIR__ . '/patient_odontogram.php';

        $treatments = [];
        $states = [];

        $scanJson = static function ($json) use (&$treatments, &$states): void {
            if (!is_string($json) || trim($json) === '' || trim($json) === '{}') {
                return;
            }
            $document = dcmt_patient_odontogram_decode_document($json);
            $usage = dcmt_patient_odontogram_collect_usage_from_document($document);
            $treatments += $usage['treatments'];
            $states += $usage['states'];
        };

        try {
            $tbl = $pdo->query("SHOW TABLES LIKE 'dcmt_patient_odontogram'");
            if ($tbl && $tbl->fetchColumn()) {
                $stmt = $pdo->query("
                    SELECT dcmt_data
                    FROM dcmt_patient_odontogram
                    WHERE dcmt_data IS NOT NULL
                      AND TRIM(dcmt_data) <> ''
                      AND dcmt_data <> '{}'
                ");
                if ($stmt) {
                    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                        $scanJson($row['dcmt_data'] ?? '');
                    }
                }
            }

            $legacyCol = $pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_odontogram_data'");
            if ($legacyCol && $legacyCol->fetch()) {
                $legacyStmt = $pdo->query("
                    SELECT dcmt_odontogram_data
                    FROM dcmt_patients
                    WHERE dcmt_odontogram_data IS NOT NULL
                      AND TRIM(dcmt_odontogram_data) <> ''
                      AND dcmt_odontogram_data <> '{}'
                ");
                if ($legacyStmt) {
                    while ($row = $legacyStmt->fetch(PDO::FETCH_ASSOC)) {
                        $scanJson($row['dcmt_odontogram_data'] ?? '');
                    }
                }
            }
        } catch (PDOException $e) {
            error_log('Odontogram usage scan: ' . $e->getMessage());
        }

        $cache = ['treatments' => $treatments, 'states' => $states];
        return $cache;
    }
}

if (!function_exists('dcmt_odontogram_treatments_in_use_map')) {
    /**
     * @return array<string, true>
     */
    function dcmt_odontogram_treatments_in_use_map(PDO $pdo): array
    {
        return dcmt_odontogram_collect_patient_usage_maps($pdo)['treatments'];
    }
}

if (!function_exists('dcmt_odontogram_treatment_is_in_use')) {
    function dcmt_odontogram_treatment_is_in_use(PDO $pdo, string $treatmentName): bool
    {
        $name = trim($treatmentName);
        if ($name === '') {
            return false;
        }
        $map = dcmt_odontogram_treatments_in_use_map($pdo);
        return isset($map[$name]);
    }
}

if (!function_exists('dcmt_odontogram_problem_states_in_use_map')) {
    /**
     * @return array<string, true>
     */
    function dcmt_odontogram_problem_states_in_use_map(PDO $pdo): array
    {
        return dcmt_odontogram_collect_patient_usage_maps($pdo)['states'];
    }
}

if (!function_exists('dcmt_odontogram_problem_state_is_in_use')) {
    function dcmt_odontogram_problem_state_is_in_use(PDO $pdo, string $stateKey): bool
    {
        if ($stateKey === 'default') {
            return false;
        }
        $map = dcmt_odontogram_problem_states_in_use_map($pdo);
        return isset($map[$stateKey]);
    }
}
