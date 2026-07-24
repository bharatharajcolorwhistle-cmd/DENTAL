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
     * @return array<string, array{fill: string, stroke: string, name: string, whole_tooth: bool}>
     */
    function dcmt_odontogram_problem_state_defaults(): array
    {
        return [
            'default' => ['fill' => '#F1F3F5', 'stroke' => '#5C6570', 'name' => 'Healthy', 'whole_tooth' => false],
            'damaged' => ['fill' => '#FFAB91', 'stroke' => '#D84315', 'name' => 'Damaged', 'whole_tooth' => false],
            'filling' => ['fill' => '#81D4FA', 'stroke' => '#0277BD', 'name' => 'Filling', 'whole_tooth' => true],
            'missing' => ['fill' => '#808080', 'stroke' => '#595959', 'name' => 'Missing', 'whole_tooth' => false],
            'crown' => ['fill' => '#FFCA28', 'stroke' => '#F57F17', 'name' => 'Crown', 'whole_tooth' => true],
            'implant' => ['fill' => '#81C784', 'stroke' => '#2E7D32', 'name' => 'Implant', 'whole_tooth' => false],
        ];
    }
}

if (!function_exists('dcmt_odontogram_problem_display_name')) {
    function dcmt_odontogram_problem_display_name(array $row): string
    {
        $name = trim((string) ($row['dcmt_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        $key = (string) ($row['dcmt_state_key'] ?? '');
        $defaults = dcmt_odontogram_problem_state_defaults();
        if (isset($defaults[$key]['name'])) {
            return (string) $defaults[$key]['name'];
        }
        return $key;
    }
}

if (!function_exists('dcmt_odontogram_problem_slug_from_name')) {
    function dcmt_odontogram_problem_slug_from_name(string $name): string
    {
        $slug = strtolower(trim($name));
        $slug = preg_replace('/[^a-z0-9]+/', '_', $slug);
        $slug = trim((string) $slug, '_');
        if ($slug === '') {
            $slug = 'problem';
        }
        if (strlen($slug) > 40) {
            $slug = substr($slug, 0, 40);
        }
        return $slug;
    }
}

if (!function_exists('dcmt_odontogram_unique_problem_state_key')) {
    function dcmt_odontogram_unique_problem_state_key(PDO $pdo, string $name, ?int $excludeId = null): string
    {
        dcmt_ensure_odontogram_problem_states_table($pdo);
        $base = dcmt_odontogram_problem_slug_from_name($name);
        $candidate = $base;
        $suffix = 2;
        while (true) {
            $sql = 'SELECT dcmt_id FROM dcmt_odontogram_problem_states WHERE dcmt_state_key = ?';
            $params = [$candidate];
            if ($excludeId !== null && $excludeId > 0) {
                $sql .= ' AND dcmt_id != ?';
                $params[] = $excludeId;
            }
            $stmt = $pdo->prepare($sql);
            $stmt->execute($params);
            if (!$stmt->fetchColumn()) {
                return $candidate;
            }
            $candidate = $base . '_' . $suffix;
            $suffix++;
        }
    }
}

if (!function_exists('dcmt_ensure_odontogram_problem_states_table')) {
    function dcmt_ensure_odontogram_problem_states_table(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_odontogram_problem_states (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_state_key VARCHAR(50) NOT NULL,
                dcmt_name VARCHAR(100) NOT NULL,
                dcmt_description TEXT,
                dcmt_color VARCHAR(7) NOT NULL,
                dcmt_stroke_color VARCHAR(7) NOT NULL,
                dcmt_whole_tooth TINYINT(1) NOT NULL DEFAULT 0,
                dcmt_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                dcmt_sort_order INT NOT NULL DEFAULT 0,
                dcmt_created_by VARCHAR(50) NOT NULL DEFAULT 'system',
                dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_odontogram_problem_state (dcmt_state_key)
            )
        ");
        dcmt_migrate_odontogram_problem_states_schema($pdo);
        dcmt_seed_odontogram_problem_states($pdo);
    }
}

if (!function_exists('dcmt_migrate_odontogram_problem_states_schema')) {
    function dcmt_migrate_odontogram_problem_states_schema(PDO $pdo): void
    {
        $cols = [];
        try {
            $stmt = $pdo->query('SHOW COLUMNS FROM dcmt_odontogram_problem_states');
            while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
                $cols[$row['Field']] = true;
            }
        } catch (PDOException $e) {
            return;
        }

        $additions = [
            'dcmt_name' => "ALTER TABLE dcmt_odontogram_problem_states ADD COLUMN dcmt_name VARCHAR(100) NULL AFTER dcmt_state_key",
            'dcmt_description' => "ALTER TABLE dcmt_odontogram_problem_states ADD COLUMN dcmt_description TEXT NULL AFTER dcmt_name",
            'dcmt_whole_tooth' => "ALTER TABLE dcmt_odontogram_problem_states ADD COLUMN dcmt_whole_tooth TINYINT(1) NOT NULL DEFAULT 0 AFTER dcmt_stroke_color",
            'dcmt_status' => "ALTER TABLE dcmt_odontogram_problem_states ADD COLUMN dcmt_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active' AFTER dcmt_whole_tooth",
            'dcmt_created_by' => "ALTER TABLE dcmt_odontogram_problem_states ADD COLUMN dcmt_created_by VARCHAR(50) NOT NULL DEFAULT 'system' AFTER dcmt_sort_order",
            'dcmt_created_at' => "ALTER TABLE dcmt_odontogram_problem_states ADD COLUMN dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER dcmt_created_by",
        ];

        foreach ($additions as $field => $sql) {
            if (!isset($cols[$field])) {
                try {
                    $pdo->exec($sql);
                } catch (PDOException $e) {
                    // ignore if already exists
                }
            }
        }

        try {
            $pdo->exec("ALTER TABLE dcmt_odontogram_problem_states MODIFY dcmt_state_key VARCHAR(50) NOT NULL");
        } catch (PDOException $e) {
            // ignore
        }

        $defaults = dcmt_odontogram_problem_state_defaults();
        $upd = $pdo->prepare("
            UPDATE dcmt_odontogram_problem_states
            SET dcmt_name = COALESCE(NULLIF(dcmt_name, ''), ?),
                dcmt_whole_tooth = ?,
                dcmt_created_by = COALESCE(NULLIF(dcmt_created_by, ''), 'system')
            WHERE dcmt_state_key = ?
        ");
        foreach ($defaults as $key => $meta) {
            $upd->execute([$meta['name'], $meta['whole_tooth'] ? 1 : 0, $key]);
        }
    }
}

if (!function_exists('dcmt_seed_odontogram_problem_states')) {
    function dcmt_seed_odontogram_problem_states(PDO $pdo): void
    {
        $defaults = dcmt_odontogram_problem_state_defaults();
        $sort = 0;
        $stmt = $pdo->prepare("
            INSERT INTO dcmt_odontogram_problem_states
                (dcmt_state_key, dcmt_name, dcmt_description, dcmt_color, dcmt_stroke_color,
                 dcmt_whole_tooth, dcmt_status, dcmt_sort_order, dcmt_created_by)
            VALUES (?, ?, '', ?, ?, ?, 'active', ?, 'system')
            ON DUPLICATE KEY UPDATE dcmt_state_key = dcmt_state_key
        ");
        foreach (dcmt_odontogram_problem_state_keys() as $key) {
            $meta = $defaults[$key] ?? ['fill' => '#6C757D', 'stroke' => '#495057', 'name' => $key, 'whole_tooth' => false];
            $stmt->execute([
                $key,
                $meta['name'],
                $meta['fill'],
                $meta['stroke'],
                !empty($meta['whole_tooth']) ? 1 : 0,
                $sort,
            ]);
            $sort++;
        }
    }
}

if (!function_exists('dcmt_get_odontogram_problem_states')) {
    /**
     * @return list<array<string, mixed>>
     */
    function dcmt_get_odontogram_problem_states(PDO $pdo, bool $includeInactive = true): array
    {
        dcmt_ensure_odontogram_problem_states_table($pdo);
        $sql = 'SELECT * FROM dcmt_odontogram_problem_states';
        if (!$includeInactive) {
            $sql .= " WHERE dcmt_status = 'active'";
        }
        $sql .= ' ORDER BY dcmt_sort_order ASC, dcmt_name ASC, dcmt_state_key ASC';
        $stmt = $pdo->query($sql);
        return $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    }
}

if (!function_exists('dcmt_get_active_odontogram_problems')) {
    /**
     * @return list<array<string, mixed>>
     */
    function dcmt_get_active_odontogram_problems(PDO $pdo): array
    {
        return dcmt_get_odontogram_problem_states($pdo, false);
    }
}

if (!function_exists('dcmt_odontogram_problem_states_map')) {
    /**
     * @return array<string, array{fill: string, stroke: string}>
     */
    function dcmt_odontogram_problem_states_map(PDO $pdo): array
    {
        $defaults = dcmt_odontogram_problem_state_defaults();
        $map = [];
        foreach (dcmt_get_odontogram_problem_states($pdo) as $row) {
            $key = (string) ($row['dcmt_state_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $fallback = $defaults[$key] ?? ['fill' => '#6C757D', 'stroke' => '#495057'];
            $map[$key] = [
                'fill' => dcmt_sanitize_odontogram_hex_color((string) $row['dcmt_color'], $fallback['fill']),
                'stroke' => dcmt_sanitize_odontogram_hex_color((string) $row['dcmt_stroke_color'], $fallback['stroke']),
            ];
        }
        foreach ($defaults as $key => $colors) {
            if (!isset($map[$key])) {
                $map[$key] = ['fill' => $colors['fill'], 'stroke' => $colors['stroke']];
            }
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

if (!function_exists('dcmt_odontogram_problems_json_for_chart')) {
    function dcmt_odontogram_problems_json_for_chart(PDO $pdo): string
    {
        $rows = dcmt_get_active_odontogram_problems($pdo);
        $out = [];
        foreach ($rows as $row) {
            $key = (string) ($row['dcmt_state_key'] ?? '');
            if ($key === '') {
                continue;
            }
            $out[] = [
                'key' => $key,
                'name' => dcmt_odontogram_problem_display_name($row),
                'wholeTooth' => !empty($row['dcmt_whole_tooth']),
            ];
        }
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '[]';
    }
}

if (!function_exists('dcmt_odontogram_allowed_problem_state_keys')) {
    /**
     * @return array<string, true>
     */
    function dcmt_odontogram_allowed_problem_state_keys(PDO $pdo): array
    {
        $keys = [];
        foreach (dcmt_get_active_odontogram_problems($pdo) as $row) {
            $key = (string) ($row['dcmt_state_key'] ?? '');
            if ($key !== '') {
                $keys[$key] = true;
            }
        }
        $keys['default'] = true;
        return $keys;
    }
}

if (!function_exists('dcmt_update_odontogram_problem_state_color')) {
    function dcmt_update_odontogram_problem_state_color(PDO $pdo, string $stateKey, string $fillColor): bool
    {
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
                dcmt_color VARCHAR(7) NULL,
                dcmt_whole_tooth TINYINT(1) NOT NULL DEFAULT 0,
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

        if (!isset($cols['dcmt_color'])) {
            try {
                $pdo->exec("
                    ALTER TABLE dcmt_odontogram_treatments
                    ADD COLUMN dcmt_color VARCHAR(7) NULL
                    AFTER dcmt_description
                ");
                $cols['dcmt_color'] = true;
            } catch (PDOException $e) {
                // ignore if already exists
            }
        }

        if (!isset($cols['dcmt_whole_tooth'])) {
            try {
                $after = isset($cols['dcmt_color']) ? 'dcmt_color' : 'dcmt_description';
                $pdo->exec("
                    ALTER TABLE dcmt_odontogram_treatments
                    ADD COLUMN dcmt_whole_tooth TINYINT(1) NOT NULL DEFAULT 0
                    AFTER {$after}
                ");
                $cols['dcmt_whole_tooth'] = true;
            } catch (PDOException $e) {
                // ignore if already exists
            }
        }

        if (!isset($cols['dcmt_show_in_treatment_plan'])) {
            try {
                $after = isset($cols['dcmt_whole_tooth']) ? 'dcmt_whole_tooth' : (isset($cols['dcmt_color']) ? 'dcmt_color' : 'dcmt_description');
                $pdo->exec("
                    ALTER TABLE dcmt_odontogram_treatments
                    ADD COLUMN dcmt_show_in_treatment_plan TINYINT(1) NOT NULL DEFAULT 1
                    AFTER {$after}
                ");
                $cols['dcmt_show_in_treatment_plan'] = true;
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

            if (isset($cols['dcmt_applies_whole_tooth']) && isset($cols['dcmt_whole_tooth'])) {
                try {
                    $pdo->exec("
                        UPDATE dcmt_odontogram_treatments
                        SET dcmt_whole_tooth = IF(COALESCE(dcmt_applies_whole_tooth, 0) = 1, 1, dcmt_whole_tooth)
                    ");
                } catch (PDOException $e) {
                    // ignore
                }
            }

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
            'Resina',
            'Sellador',
            'Ionomero',
            'Incrustacion',
            'Endodoncia',
            'Corona',
        ];

        $stmt = $pdo->prepare("
            INSERT INTO dcmt_odontogram_treatments
                (dcmt_name, dcmt_description, dcmt_status, dcmt_created_by)
            VALUES (?, '', 'active', 'system')
        ");

        foreach ($defaults as $name) {
            $stmt->execute([$name]);
        }
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
                'color' => $color,
                'wholeTooth' => !empty($row['dcmt_whole_tooth']),
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
