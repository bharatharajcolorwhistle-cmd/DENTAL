<?php
/**
 * Lab integration helpers (clinic → dental lab API).
 */

if (!function_exists('dcmt_lab_table_has_column')) {
    function dcmt_lab_table_has_column(PDO $pdo, string $table, string $column): bool
    {
        $stmt = $pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $pdo->quote($column));
        return (bool) ($stmt && $stmt->fetch(PDO::FETCH_ASSOC));
    }
}

if (!function_exists('dcmt_lab_ensure_column')) {
    function dcmt_lab_ensure_column(PDO $pdo, string $table, string $column, string $definition): void
    {
        if (dcmt_lab_table_has_column($pdo, $table, $column)) {
            return;
        }
        // Prefer simple ADD without AFTER so incomplete/legacy schemas still upgrade.
        $definition = preg_replace('/\s+AFTER\s+`?[a-z0-9_]+`?/i', '', $definition);
        try {
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        } catch (PDOException $e) {
            // 1118 Row size too large: legacy COMPACT row format stores 768-byte
            // TEXT prefixes inline. Convert to DYNAMIC and retry once.
            if (strpos($e->getMessage(), 'Row size too large') === false) {
                throw $e;
            }
            $pdo->exec("ALTER TABLE `{$table}` ROW_FORMAT=DYNAMIC");
            $pdo->exec("ALTER TABLE `{$table}` ADD COLUMN {$definition}");
        }
    }
}

if (!function_exists('dcmt_lab_table_row_count')) {
    function dcmt_lab_table_row_count(PDO $pdo, string $table): int
    {
        try {
            $stmt = $pdo->query("SELECT COUNT(*) FROM `{$table}`");
            return (int) $stmt->fetchColumn();
        } catch (PDOException $e) {
            return 0;
        }
    }
}

if (!function_exists('dcmt_lab_work_orders_expected_columns')) {
    function dcmt_lab_work_orders_expected_columns(): array
    {
        return [
            'dcmt_id',
            'dcmt_lab_connection_id',
            'dcmt_patient_id',
            'dcmt_doctor_user_id',
            'dcmt_patient_name',
            'dcmt_doctor_name',
            'dcmt_doctor_email',
            'dcmt_doctor_phone',
            'dcmt_doctor_address',
            'dcmt_prosthesis_type_id',
            'dcmt_prosthesis_type_name',
            'dcmt_box_number',
            'dcmt_color',
            'dcmt_specification',
            'dcmt_notes',
            'dcmt_total_quote',
            'dcmt_initial_payment',
            'dcmt_folio_number',
            'dcmt_remote_work_order_id',
            'dcmt_remote_doctor_id',
            'dcmt_remote_status',
            'dcmt_verification_started_at',
            'dcmt_verification_ended_at',
            'dcmt_verification_outcome',
            'dcmt_created_by',
            'dcmt_created_at',
            'dcmt_updated_at',
        ];
    }
}

if (!function_exists('dcmt_lab_work_orders_schema_ok')) {
    function dcmt_lab_work_orders_schema_ok(PDO $pdo): bool
    {
        $required = dcmt_lab_work_orders_expected_columns();
        foreach ($required as $column) {
            if ($column === 'dcmt_id') {
                continue;
            }
            if (!dcmt_lab_table_has_column($pdo, 'dcmt_lab_work_orders', $column)) {
                return false;
            }
        }

        // Legacy/orphan required columns (e.g. dcmt_lab_id) break inserts
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM dcmt_lab_work_orders')->fetchAll(PDO::FETCH_ASSOC);
            $expected = array_flip($required);
            foreach ($cols as $col) {
                $field = $col['Field'] ?? '';
                if ($field === '' || isset($expected[$field])) {
                    continue;
                }
                $nullable = strtoupper((string) ($col['Null'] ?? '')) === 'YES';
                $has_default = array_key_exists('Default', $col) && $col['Default'] !== null;
                $extra = strtolower((string) ($col['Extra'] ?? ''));
                $auto = strpos($extra, 'auto_increment') !== false;
                if (!$nullable && !$has_default && !$auto) {
                    return false;
                }
            }
        } catch (PDOException $e) {
            return false;
        }

        return true;
    }
}

if (!function_exists('dcmt_lab_drop_orphan_work_order_columns')) {
    function dcmt_lab_drop_orphan_work_order_columns(PDO $pdo): void
    {
        $expected = array_flip(dcmt_lab_work_orders_expected_columns());
        try {
            $cols = $pdo->query('SHOW COLUMNS FROM dcmt_lab_work_orders')->fetchAll(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return;
        }

        foreach ($cols as $col) {
            $field = (string) ($col['Field'] ?? '');
            if ($field === '' || isset($expected[$field])) {
                continue;
            }
            try {
                $pdo->exec('ALTER TABLE dcmt_lab_work_orders DROP COLUMN `' . str_replace('`', '``', $field) . '`');
                error_log('Dropped orphan lab work order column: ' . $field);
            } catch (PDOException $e) {
                // If drop fails (e.g. used by an index), make it nullable with default
                try {
                    $type = (string) ($col['Type'] ?? 'INT');
                    $pdo->exec("ALTER TABLE dcmt_lab_work_orders MODIFY `{$field}` {$type} NULL DEFAULT NULL");
                } catch (PDOException $e2) {
                    error_log('Could not neutralize orphan column ' . $field . ': ' . $e2->getMessage());
                }
            }
        }
    }
}

if (!function_exists('dcmt_ensure_lab_tables')) {
    function dcmt_ensure_lab_tables(PDO $pdo): void
    {
        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_lab_connections (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_name VARCHAR(150) NOT NULL,
                dcmt_lab_base_url VARCHAR(255) NOT NULL,
                dcmt_api_key VARCHAR(255) NOT NULL,
                dcmt_clinic_url VARCHAR(255) NOT NULL,
                dcmt_clinic_name VARCHAR(150) NOT NULL,
                dcmt_lab_remote_id VARCHAR(191) NULL,
                dcmt_lab_remote_name VARCHAR(150) NULL,
                dcmt_lab_remote_code VARCHAR(50) NULL,
                dcmt_lab_organization VARCHAR(150) NULL,
                dcmt_clinic_remote_id VARCHAR(191) NULL,
                dcmt_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active',
                dcmt_last_synced_at DATETIME NULL DEFAULT NULL,
                dcmt_notes TEXT NULL,
                dcmt_created_by VARCHAR(50) NOT NULL,
                dcmt_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                UNIQUE KEY unique_lab_connection_name (dcmt_name),
                INDEX idx_lab_connections_status (dcmt_status)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");

        $work_orders_sql = "
            CREATE TABLE dcmt_lab_work_orders (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_lab_connection_id INT NOT NULL DEFAULT 0,
                dcmt_patient_id INT NULL,
                dcmt_doctor_user_id INT NULL,
                dcmt_patient_name VARCHAR(200) NOT NULL DEFAULT '',
                dcmt_doctor_name VARCHAR(150) NOT NULL DEFAULT '',
                dcmt_doctor_email VARCHAR(150) NULL,
                dcmt_doctor_phone VARCHAR(50) NULL,
                dcmt_doctor_address TEXT NULL,
                dcmt_prosthesis_type_id VARCHAR(191) NOT NULL DEFAULT '',
                dcmt_prosthesis_type_name VARCHAR(150) NULL,
                dcmt_box_number VARCHAR(100) NULL,
                dcmt_color VARCHAR(50) NULL,
                dcmt_specification TEXT NULL,
                dcmt_notes TEXT NULL,
                dcmt_total_quote DECIMAL(12,2) NULL,
                dcmt_initial_payment DECIMAL(12,2) NULL,
                dcmt_folio_number VARCHAR(100) NULL,
                dcmt_remote_work_order_id VARCHAR(191) NULL,
                dcmt_remote_doctor_id VARCHAR(191) NULL,
                dcmt_remote_status VARCHAR(50) NULL,
                dcmt_verification_started_at DATETIME NULL DEFAULT NULL,
                dcmt_verification_ended_at DATETIME NULL DEFAULT NULL,
                dcmt_verification_outcome VARCHAR(20) NULL,
                dcmt_created_by VARCHAR(50) NOT NULL DEFAULT '',
                dcmt_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_lab_work_orders_connection (dcmt_lab_connection_id),
                INDEX idx_lab_work_orders_patient (dcmt_patient_id),
                INDEX idx_lab_work_orders_doctor (dcmt_doctor_user_id),
                INDEX idx_lab_work_orders_folio (dcmt_folio_number)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ";

        $pdo->exec(str_replace('CREATE TABLE dcmt_lab_work_orders', 'CREATE TABLE IF NOT EXISTS dcmt_lab_work_orders', $work_orders_sql));

        // Legacy COMPACT row format keeps 768-byte TEXT prefixes inline and can
        // overflow the 8KB row limit when new columns are added. Convert once.
        foreach (['dcmt_lab_connections', 'dcmt_lab_work_orders'] as $dcmt_lab_dynamic_table) {
            try {
                $row_format_stmt = $pdo->query(
                    "SELECT ROW_FORMAT FROM information_schema.TABLES
                     WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = " . $pdo->quote($dcmt_lab_dynamic_table)
                );
                $row_format = $row_format_stmt ? strtoupper((string) $row_format_stmt->fetchColumn()) : '';
                if ($row_format !== '' && $row_format !== 'DYNAMIC' && $row_format !== 'COMPRESSED') {
                    $pdo->exec("ALTER TABLE `{$dcmt_lab_dynamic_table}` ROW_FORMAT=DYNAMIC");
                }
            } catch (PDOException $e) {
                error_log('Lab table row format upgrade skipped for ' . $dcmt_lab_dynamic_table . ': ' . $e->getMessage());
            }
        }

        // Prefer clean rebuild when empty and schema is wrong (legacy columns like dcmt_lab_id)
        if (!dcmt_lab_work_orders_schema_ok($pdo) && dcmt_lab_table_row_count($pdo, 'dcmt_lab_work_orders') === 0) {
            $pdo->exec('DROP TABLE IF EXISTS dcmt_lab_work_orders');
            $pdo->exec($work_orders_sql);
        } else {
            // Keep data: drop or neutralize orphan columns (dcmt_lab_id, etc.)
            dcmt_lab_drop_orphan_work_order_columns($pdo);
        }

        // Upgrade incomplete tables created by an earlier partial schema
        $connection_columns = [
            'dcmt_name' => "dcmt_name VARCHAR(150) NOT NULL DEFAULT ''",
            'dcmt_lab_base_url' => "dcmt_lab_base_url VARCHAR(255) NOT NULL DEFAULT ''",
            'dcmt_api_key' => "dcmt_api_key VARCHAR(255) NOT NULL DEFAULT ''",
            'dcmt_clinic_url' => "dcmt_clinic_url VARCHAR(255) NOT NULL DEFAULT ''",
            'dcmt_clinic_name' => "dcmt_clinic_name VARCHAR(150) NOT NULL DEFAULT ''",
            'dcmt_lab_remote_id' => "dcmt_lab_remote_id VARCHAR(191) NULL",
            'dcmt_lab_remote_name' => "dcmt_lab_remote_name VARCHAR(150) NULL",
            'dcmt_lab_remote_code' => "dcmt_lab_remote_code VARCHAR(50) NULL",
            'dcmt_lab_organization' => "dcmt_lab_organization VARCHAR(150) NULL",
            'dcmt_clinic_remote_id' => "dcmt_clinic_remote_id VARCHAR(191) NULL",
            'dcmt_status' => "dcmt_status ENUM('active', 'inactive') NOT NULL DEFAULT 'active'",
            'dcmt_last_synced_at' => "dcmt_last_synced_at DATETIME NULL DEFAULT NULL",
            'dcmt_notes' => "dcmt_notes TEXT NULL",
            'dcmt_created_by' => "dcmt_created_by VARCHAR(50) NOT NULL DEFAULT ''",
            'dcmt_created_at' => "dcmt_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'dcmt_updated_at' => "dcmt_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ($connection_columns as $column => $definition) {
            dcmt_lab_ensure_column($pdo, 'dcmt_lab_connections', $column, $definition);
        }

        $work_order_columns = [
            'dcmt_lab_connection_id' => "dcmt_lab_connection_id INT NOT NULL DEFAULT 0",
            'dcmt_patient_id' => "dcmt_patient_id INT NULL",
            'dcmt_doctor_user_id' => "dcmt_doctor_user_id INT NULL",
            'dcmt_patient_name' => "dcmt_patient_name VARCHAR(200) NOT NULL DEFAULT ''",
            'dcmt_doctor_name' => "dcmt_doctor_name VARCHAR(150) NOT NULL DEFAULT ''",
            'dcmt_doctor_email' => "dcmt_doctor_email VARCHAR(150) NULL",
            'dcmt_doctor_phone' => "dcmt_doctor_phone VARCHAR(50) NULL",
            'dcmt_doctor_address' => "dcmt_doctor_address TEXT NULL",
            'dcmt_prosthesis_type_id' => "dcmt_prosthesis_type_id VARCHAR(191) NOT NULL DEFAULT ''",
            'dcmt_prosthesis_type_name' => "dcmt_prosthesis_type_name VARCHAR(150) NULL",
            'dcmt_box_number' => "dcmt_box_number VARCHAR(100) NULL",
            'dcmt_color' => "dcmt_color VARCHAR(50) NULL",
            'dcmt_specification' => "dcmt_specification TEXT NULL",
            'dcmt_notes' => "dcmt_notes TEXT NULL",
            'dcmt_total_quote' => "dcmt_total_quote DECIMAL(12,2) NULL",
            'dcmt_initial_payment' => "dcmt_initial_payment DECIMAL(12,2) NULL",
            'dcmt_folio_number' => "dcmt_folio_number VARCHAR(100) NULL",
            'dcmt_remote_work_order_id' => "dcmt_remote_work_order_id VARCHAR(191) NULL",
            'dcmt_remote_doctor_id' => "dcmt_remote_doctor_id VARCHAR(191) NULL",
            'dcmt_remote_status' => "dcmt_remote_status VARCHAR(50) NULL",
            'dcmt_verification_started_at' => "dcmt_verification_started_at DATETIME NULL DEFAULT NULL",
            'dcmt_verification_ended_at' => "dcmt_verification_ended_at DATETIME NULL DEFAULT NULL",
            'dcmt_verification_outcome' => "dcmt_verification_outcome VARCHAR(20) NULL",
            'dcmt_created_by' => "dcmt_created_by VARCHAR(50) NOT NULL DEFAULT ''",
            'dcmt_created_at' => "dcmt_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP",
            'dcmt_updated_at' => "dcmt_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP",
        ];
        foreach ($work_order_columns as $column => $definition) {
            dcmt_lab_ensure_column($pdo, 'dcmt_lab_work_orders', $column, $definition);
        }

        // One more cleanup pass after adds
        dcmt_lab_drop_orphan_work_order_columns($pdo);

        try {
            $pdo->exec('CREATE INDEX idx_lab_work_orders_connection ON dcmt_lab_work_orders (dcmt_lab_connection_id)');
        } catch (PDOException $e) {
            // index may already exist
        }
        try {
            $pdo->exec('CREATE INDEX idx_lab_connections_status ON dcmt_lab_connections (dcmt_status)');
        } catch (PDOException $e) {
            // index may already exist
        }

        $pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_lab_notifications (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_lab_connection_id INT NULL,
                dcmt_user_id INT NOT NULL,
                dcmt_event VARCHAR(100) NOT NULL DEFAULT '',
                dcmt_remote_work_order_id VARCHAR(191) NULL,
                dcmt_local_work_order_id INT NULL,
                dcmt_folio_number VARCHAR(100) NULL,
                dcmt_patient_name VARCHAR(200) NULL,
                dcmt_process_name VARCHAR(150) NULL,
                dcmt_doctor_name VARCHAR(150) NULL,
                dcmt_doctor_email VARCHAR(150) NULL,
                dcmt_title VARCHAR(255) NOT NULL DEFAULT '',
                dcmt_message TEXT NULL,
                dcmt_payload LONGTEXT NULL,
                dcmt_dismissed TINYINT(1) NOT NULL DEFAULT 0,
                dcmt_created_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_lab_notifications_user (dcmt_user_id, dcmt_dismissed, dcmt_created_at),
                INDEX idx_lab_notifications_remote_wo (dcmt_remote_work_order_id),
                INDEX idx_lab_notifications_event (dcmt_event)
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci ROW_FORMAT=DYNAMIC
        ");

        $required = ['dcmt_lab_connections', 'dcmt_lab_work_orders', 'dcmt_lab_notifications'];
        foreach ($required as $table) {
            $stmt = $pdo->query("SHOW TABLES LIKE " . $pdo->quote($table));
            if (!$stmt || !$stmt->fetchColumn()) {
                throw new PDOException('Required lab table missing after ensure: ' . $table);
            }
        }

        if (!dcmt_lab_work_orders_schema_ok($pdo)) {
            // Last resort rebuild when still broken and empty
            if (dcmt_lab_table_row_count($pdo, 'dcmt_lab_work_orders') === 0) {
                $pdo->exec('DROP TABLE IF EXISTS dcmt_lab_work_orders');
                $pdo->exec($work_orders_sql);
            }
        }

        if (!dcmt_lab_work_orders_schema_ok($pdo)) {
            throw new PDOException('dcmt_lab_work_orders schema incomplete after ensure (legacy column may remain)');
        }
    }
}

if (!function_exists('dcmt_lab_normalize_base_url')) {
    function dcmt_lab_normalize_base_url(string $url): string
    {
        $url = trim($url);
        $url = rtrim($url, '/');
        if ($url === '') {
            return '';
        }
        if (!preg_match('#^https?://#i', $url)) {
            $url = 'https://' . $url;
        }
        return $url;
    }
}

if (!function_exists('dcmt_lab_stringify_error_value')) {
    function dcmt_lab_stringify_error_value($value): string
    {
        if ($value === null) {
            return '';
        }
        if (is_string($value)) {
            return trim($value);
        }
        if (is_numeric($value)) {
            return (string) $value;
        }
        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }
        if (!is_array($value)) {
            return '';
        }

        $parts = [];
        foreach ($value as $key => $item) {
            $nested = dcmt_lab_stringify_error_value($item);
            if ($nested === '') {
                continue;
            }
            if (is_string($key) && !is_numeric($key)) {
                $parts[] = $key . ': ' . $nested;
            } else {
                $parts[] = $nested;
            }
        }

        if ($parts !== []) {
            return implode('; ', $parts);
        }

        $json = json_encode($value, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        return is_string($json) ? $json : '';
    }
}

if (!function_exists('dcmt_lab_collect_api_error_messages')) {
    function dcmt_lab_collect_api_error_messages($decoded): string
    {
        if (!is_array($decoded)) {
            return '';
        }

        $messages = [];
        foreach (['message', 'error', 'errors'] as $field) {
            if (!array_key_exists($field, $decoded)) {
                continue;
            }
            $message = dcmt_lab_stringify_error_value($decoded[$field]);
            if ($message !== '') {
                $messages[] = $message;
            }
        }

        if ($messages !== []) {
            return implode('; ', array_unique($messages));
        }

        return '';
    }
}

if (!function_exists('dcmt_lab_extract_error_message')) {
    function dcmt_lab_extract_error_message(array $response, string $fallback = 'Lab API request failed'): string
    {
        if (isset($response['error'])) {
            $message = dcmt_lab_stringify_error_value($response['error']);
            if ($message !== '') {
                return $message;
            }
        }

        $data = $response['data'] ?? null;
        if (is_array($data)) {
            $message = dcmt_lab_collect_api_error_messages($data);
            if ($message !== '') {
                return $message;
            }
        }

        $status = (int) ($response['status'] ?? 0);
        return $status > 0 ? ($fallback . ' (HTTP ' . $status . ')') : $fallback;
    }
}

if (!function_exists('dcmt_lab_request')) {
    /**
     * @return array{success:bool,status:int,data:mixed,raw:string,error:?string}
     */
    function dcmt_lab_request(string $base_url, string $api_key, string $method, string $path, ?array $body = null): array
    {
        $base_url = dcmt_lab_normalize_base_url($base_url);
        $path = '/' . ltrim($path, '/');
        $url = $base_url . $path;

        $headers = [
            'X-API-Key: ' . $api_key,
            'Accept: application/json',
        ];

        $ch = curl_init($url);
        if ($ch === false) {
            return [
                'success' => false,
                'status' => 0,
                'data' => null,
                'raw' => '',
                'error' => 'Unable to initialize HTTP client',
            ];
        }

        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => strtoupper($method),
            CURLOPT_TIMEOUT => 30,
            CURLOPT_CONNECTTIMEOUT => 15,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS => 3,
        ];

        if ($body !== null) {
            $json = json_encode($body, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
            $headers[] = 'Content-Type: application/json';
            $opts[CURLOPT_POSTFIELDS] = $json;
        }

        $opts[CURLOPT_HTTPHEADER] = $headers;
        curl_setopt_array($ch, $opts);

        $raw = curl_exec($ch);
        $errno = curl_errno($ch);
        $error = $errno ? curl_error($ch) : null;
        $status = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            return [
                'success' => false,
                'status' => $status,
                'data' => null,
                'raw' => '',
                'error' => $error ?: 'HTTP request failed',
            ];
        }

        $decoded = json_decode($raw, true);
        $ok = $status >= 200 && $status < 300;
        $api_error = '';
        if (!$ok) {
            $api_error = dcmt_lab_collect_api_error_messages($decoded);
            if ($api_error === '') {
                $api_error = is_array($decoded) ? 'Lab API request failed' : ('Lab API HTTP ' . $status);
            }
        }

        return [
            'success' => $ok,
            'status' => $status,
            'data' => is_array($decoded) ? $decoded : null,
            'raw' => $raw,
            'error' => $ok ? null : $api_error,
        ];
    }
}

if (!function_exists('dcmt_lab_configure_integration')) {
    function dcmt_lab_configure_integration(string $base_url, string $api_key, string $clinic_url, string $clinic_name): array
    {
        return dcmt_lab_request($base_url, $api_key, 'POST', '/api/integration/config', [
            'clinicUrl' => $clinic_url,
            'clinicName' => $clinic_name,
        ]);
    }
}

if (!function_exists('dcmt_lab_fetch_work_order_setup')) {
    function dcmt_lab_fetch_work_order_setup(string $base_url, string $api_key): array
    {
        return dcmt_lab_request($base_url, $api_key, 'GET', '/api/integration/work-orders/setup');
    }
}

if (!function_exists('dcmt_lab_create_work_order')) {
    function dcmt_lab_create_work_order(string $base_url, string $api_key, array $payload): array
    {
        return dcmt_lab_request($base_url, $api_key, 'POST', '/api/integration/work-orders', $payload);
    }
}

if (!function_exists('dcmt_lab_fetch_work_order_status')) {
    function dcmt_lab_fetch_work_order_status(string $base_url, string $api_key, string $work_order_id): array
    {
        $work_order_id = trim($work_order_id);
        if ($work_order_id === '') {
            return [
                'success' => false,
                'status' => 0,
                'data' => null,
                'raw' => '',
                'error' => 'Invalid work order ID',
            ];
        }
        return dcmt_lab_request($base_url, $api_key, 'GET', '/api/integration/work-orders/' . rawurlencode($work_order_id));
    }
}

if (!function_exists('dcmt_lab_start_verification')) {
    function dcmt_lab_start_verification(string $base_url, string $api_key, string $clinic_url, string $doctor_id, string $work_order_id): array
    {
        return dcmt_lab_request($base_url, $api_key, 'POST', '/api/integration/work-orders/start-verification', [
            'clinicUrl' => $clinic_url,
            'doctorId' => $doctor_id,
            'workOrderId' => $work_order_id,
        ]);
    }
}

if (!function_exists('dcmt_lab_submit_verification')) {
    function dcmt_lab_submit_verification(string $base_url, string $api_key, string $clinic_url, string $doctor_id, string $work_order_id, string $outcome, string $notes, array $rework_process_names = []): array
    {
        $payload = [
            'clinicUrl' => $clinic_url,
            'doctorId' => $doctor_id,
            'workOrderId' => $work_order_id,
            'outcome' => $outcome,
        ];
        if ($notes !== '') {
            $payload['notes'] = $notes;
        }
        if ($outcome === 'REWORK') {
            $payload['reworkProcessNames'] = array_values($rework_process_names);
        }
        return dcmt_lab_request($base_url, $api_key, 'POST', '/api/integration/work-orders/verify', $payload);
    }
}

if (!function_exists('dcmt_lab_get_connection')) {
    function dcmt_lab_get_connection(PDO $pdo, int $id): ?array
    {
        $stmt = $pdo->prepare('SELECT * FROM dcmt_lab_connections WHERE dcmt_id = ?');
        $stmt->execute([$id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('dcmt_lab_get_active_connections')) {
    function dcmt_lab_get_active_connections(PDO $pdo): array
    {
        $stmt = $pdo->query("SELECT * FROM dcmt_lab_connections WHERE dcmt_status = 'active' ORDER BY COALESCE(NULLIF(TRIM(dcmt_lab_organization), ''), dcmt_name)");
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

/**
 * Display name for a lab connection: organization only (no branch).
 */
if (!function_exists('dcmt_lab_connection_display_name')) {
    function dcmt_lab_connection_display_name(array $connection): string
    {
        $organization = trim((string) ($connection['dcmt_lab_organization'] ?? ''));
        if ($organization !== '') {
            return $organization;
        }
        $name = trim((string) ($connection['dcmt_name'] ?? ''));
        if ($name !== '') {
            return $name;
        }
        return trim((string) ($connection['dcmt_lab_remote_name'] ?? '')) ?: 'Lab';
    }
}

if (!function_exists('dcmt_lab_default_clinic_name')) {
    function dcmt_lab_default_clinic_name(): string
    {
        if (function_exists('dcmt_get_site_name')) {
            $name = trim((string) dcmt_get_site_name());
            if ($name !== '') {
                return $name;
            }
        }
        return 'Dental Clinic';
    }
}

if (!function_exists('dcmt_lab_default_clinic_url')) {
    function dcmt_lab_default_clinic_url(): string
    {
        return defined('DCMT_APP_URL') ? rtrim((string) DCMT_APP_URL, '/') : '';
    }
}

if (!function_exists('dcmt_lab_find_connection_by_api_key')) {
    function dcmt_lab_find_connection_by_api_key(PDO $pdo, string $api_key): ?array
    {
        $api_key = trim($api_key);
        if ($api_key === '') {
            return null;
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM dcmt_lab_connections
            WHERE TRIM(dcmt_api_key) = ?
              AND dcmt_status = 'active'
            ORDER BY dcmt_id DESC
            LIMIT 1
        ");
        $stmt->execute([$api_key]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('dcmt_lab_find_work_order_by_remote_id')) {
    function dcmt_lab_find_work_order_by_remote_id(PDO $pdo, string $remote_work_order_id, ?int $connection_id = null): ?array
    {
        $remote_work_order_id = trim($remote_work_order_id);
        if ($remote_work_order_id === '') {
            return null;
        }
        if ($connection_id && $connection_id > 0) {
            $stmt = $pdo->prepare("
                SELECT *
                FROM dcmt_lab_work_orders
                WHERE dcmt_remote_work_order_id = ?
                  AND dcmt_lab_connection_id = ?
                ORDER BY dcmt_id DESC
                LIMIT 1
            ");
            $stmt->execute([$remote_work_order_id, $connection_id]);
        } else {
            $stmt = $pdo->prepare("
                SELECT *
                FROM dcmt_lab_work_orders
                WHERE dcmt_remote_work_order_id = ?
                ORDER BY dcmt_id DESC
                LIMIT 1
            ");
            $stmt->execute([$remote_work_order_id]);
        }
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        return $row ?: null;
    }
}

if (!function_exists('dcmt_lab_notification_recipient_user_ids')) {
    /**
     * Admins + matching doctor by email (case-insensitive).
     *
     * @return array<int,int>
     */
    function dcmt_lab_notification_recipient_user_ids(PDO $pdo, string $doctor_email = ''): array
    {
        $ids = [];

        try {
            $admin_stmt = $pdo->query("
                SELECT dcmt_id
                FROM dcmt_users
                WHERE dcmt_role = 'admin'
                  AND dcmt_status = 'active'
            ");
            $admins = $admin_stmt ? $admin_stmt->fetchAll(PDO::FETCH_COLUMN) : [];
            foreach ($admins as $admin_id) {
                $ids[(int) $admin_id] = (int) $admin_id;
            }
        } catch (PDOException $e) {
            error_log('Lab notification admin lookup failed: ' . $e->getMessage());
        }

        $doctor_email = strtolower(trim($doctor_email));
        if ($doctor_email !== '') {
            try {
                $doc_stmt = $pdo->prepare("
                    SELECT dcmt_id
                    FROM dcmt_users
                    WHERE dcmt_role = 'doctor'
                      AND dcmt_status = 'active'
                      AND LOWER(TRIM(dcmt_email)) = ?
                ");
                $doc_stmt->execute([$doctor_email]);
                foreach ($doc_stmt->fetchAll(PDO::FETCH_COLUMN) as $doctor_id) {
                    $ids[(int) $doctor_id] = (int) $doctor_id;
                }
            } catch (PDOException $e) {
                error_log('Lab notification doctor lookup failed: ' . $e->getMessage());
            }
        }

        return array_values($ids);
    }
}

if (!function_exists('dcmt_lab_create_inbound_notifications')) {
    /**
     * Store inbound lab webhook notifications for admins + matching doctor.
     *
     * @param array<string,mixed> $payload
     * @return array{success:bool,created:int,message?:string}
     */
    function dcmt_lab_create_inbound_notifications(PDO $pdo, array $connection, array $payload): array
    {
        $event = trim((string) ($payload['event'] ?? ''));
        if ($event === '') {
            return ['success' => false, 'created' => 0, 'message' => 'event is required'];
        }

        $work_order_id = trim((string) ($payload['workOrderId'] ?? ''));
        $folio = trim((string) ($payload['folioNumber'] ?? ''));
        $patient = trim((string) ($payload['patient'] ?? ''));
        $process_name = trim((string) ($payload['processName'] ?? ''));
        $doctor = is_array($payload['doctor'] ?? null) ? $payload['doctor'] : [];
        $doctor_name = trim((string) ($doctor['name'] ?? ''));
        $doctor_email = trim((string) ($doctor['email'] ?? ''));

        $local_order = $work_order_id !== ''
            ? dcmt_lab_find_work_order_by_remote_id($pdo, $work_order_id, (int) ($connection['dcmt_id'] ?? 0))
            : null;
        $local_order_id = $local_order ? (int) $local_order['dcmt_id'] : null;

        if ($folio === '' && $local_order) {
            $folio = trim((string) ($local_order['dcmt_folio_number'] ?? ''));
        }
        if ($patient === '' && $local_order) {
            $patient = trim((string) ($local_order['dcmt_patient_name'] ?? ''));
        }
        if ($doctor_email === '' && $local_order) {
            $doctor_email = trim((string) ($local_order['dcmt_doctor_email'] ?? ''));
        }
        if ($doctor_name === '' && $local_order) {
            $doctor_name = trim((string) ($local_order['dcmt_doctor_name'] ?? ''));
        }

        $recipient_ids = dcmt_lab_notification_recipient_user_ids($pdo, $doctor_email);
        if ($recipient_ids === []) {
            return ['success' => false, 'created' => 0, 'message' => 'No matching admin or doctor recipients'];
        }

        $title = $event === 'EXTERNAL_VERIFICATION_REQUESTED'
            ? (function_exists('trans') ? trans('lab', 'notification_verification_title') : 'Lab verification requested')
            : (function_exists('trans') ? trans('lab', 'notification_generic_title') : 'Lab notification');

        $message_parts = [];
        if ($folio !== '') {
            $message_parts[] = (function_exists('trans') ? trans('lab', 'folio_number') : 'Folio') . ': ' . $folio;
        }
        if ($patient !== '') {
            $message_parts[] = (function_exists('trans') ? trans('lab', 'patient_name') : 'Patient') . ': ' . $patient;
        }
        if ($process_name !== '') {
            $message_parts[] = (function_exists('trans') ? trans('lab', 'process_name') : 'Process') . ': ' . $process_name;
        }
        if ($doctor_name !== '') {
            $message_parts[] = (function_exists('trans') ? trans('lab', 'doctor_name') : 'Doctor') . ': ' . $doctor_name;
        }
        $message = implode(' • ', $message_parts);

        $payload_json = json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if (!is_string($payload_json)) {
            $payload_json = null;
        }

        $stmt = $pdo->prepare("
            INSERT INTO dcmt_lab_notifications (
                dcmt_lab_connection_id, dcmt_user_id, dcmt_event,
                dcmt_remote_work_order_id, dcmt_local_work_order_id, dcmt_folio_number,
                dcmt_patient_name, dcmt_process_name, dcmt_doctor_name, dcmt_doctor_email,
                dcmt_title, dcmt_message, dcmt_payload, dcmt_dismissed
            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0)
        ");

        $created = 0;
        foreach ($recipient_ids as $user_id) {
            // Avoid duplicate active notifications for same user + work order + event
            $dup = $pdo->prepare("
                SELECT dcmt_id
                FROM dcmt_lab_notifications
                WHERE dcmt_user_id = ?
                  AND dcmt_event = ?
                  AND dcmt_remote_work_order_id = ?
                  AND dcmt_dismissed = 0
                LIMIT 1
            ");
            $dup->execute([$user_id, $event, $work_order_id !== '' ? $work_order_id : null]);
            if ($dup->fetchColumn()) {
                continue;
            }

            $stmt->execute([
                (int) ($connection['dcmt_id'] ?? 0) ?: null,
                $user_id,
                $event,
                $work_order_id !== '' ? $work_order_id : null,
                $local_order_id,
                $folio !== '' ? $folio : null,
                $patient !== '' ? $patient : null,
                $process_name !== '' ? $process_name : null,
                $doctor_name !== '' ? $doctor_name : null,
                $doctor_email !== '' ? $doctor_email : null,
                $title,
                $message !== '' ? $message : null,
                $payload_json,
            ]);
            $created++;
        }

        return ['success' => true, 'created' => $created];
    }
}

if (!function_exists('dcmt_lab_fetch_active_notifications')) {
    function dcmt_lab_fetch_active_notifications(PDO $pdo, int $user_id, int $limit = 15): array
    {
        if ($user_id <= 0) {
            return [];
        }
        $stmt = $pdo->prepare("
            SELECT *
            FROM dcmt_lab_notifications
            WHERE dcmt_user_id = ?
              AND dcmt_dismissed = 0
            ORDER BY dcmt_created_at DESC
            LIMIT ?
        ");
        $stmt->bindValue(1, $user_id, PDO::PARAM_INT);
        $stmt->bindValue(2, $limit, PDO::PARAM_INT);
        $stmt->execute();
        return $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    }
}

if (!function_exists('dcmt_lab_count_active_notifications')) {
    function dcmt_lab_count_active_notifications(PDO $pdo, int $user_id): int
    {
        if ($user_id <= 0) {
            return 0;
        }
        $stmt = $pdo->prepare("
            SELECT COUNT(*)
            FROM dcmt_lab_notifications
            WHERE dcmt_user_id = ?
              AND dcmt_dismissed = 0
        ");
        $stmt->execute([$user_id]);
        return (int) $stmt->fetchColumn();
    }
}

if (!function_exists('dcmt_lab_dismiss_notification')) {
    function dcmt_lab_dismiss_notification(PDO $pdo, int $notification_id, int $user_id): array
    {
        if ($notification_id <= 0 || $user_id <= 0) {
            return ['success' => false, 'message' => 'Invalid notification'];
        }
        $stmt = $pdo->prepare("
            UPDATE dcmt_lab_notifications
            SET dcmt_dismissed = 1, dcmt_updated_at = NOW()
            WHERE dcmt_id = ?
              AND dcmt_user_id = ?
              AND dcmt_dismissed = 0
        ");
        $stmt->execute([$notification_id, $user_id]);
        if ($stmt->rowCount() < 1) {
            return ['success' => false, 'message' => 'Notification not found'];
        }
        return ['success' => true];
    }
}
