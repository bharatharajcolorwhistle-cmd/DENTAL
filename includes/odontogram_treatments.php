<?php
/**
 * Odontogram clinical treatments (configurable) — not chart surface states.
 */

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
            $out[] = [
                'id' => (int) $row['dcmt_id'],
                'name' => $row['dcmt_name'],
                'zone' => $row['dcmt_zone'],
                'toothState' => !empty($row['dcmt_tooth_state']) ? (string) $row['dcmt_tooth_state'] : null,
            ];
        }
        $json = json_encode($out, JSON_UNESCAPED_UNICODE);
        return $json !== false ? $json : '[]';
    }
}
