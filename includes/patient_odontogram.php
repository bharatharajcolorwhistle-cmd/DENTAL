<?php
/**
 * Parse and normalize odontogram JSON from patient forms (POST).
 */

if (!function_exists('dcmt_patient_odontogram_allowed_teeth_map')) {
    /**
     * @return array<string, true>
     */
    function dcmt_patient_odontogram_allowed_teeth_map()
    {
        static $map = null;
        if ($map === null) {
            $upperMain = [18, 17, 16, 15, 14, 13, 12, 11, 21, 22, 23, 24, 25, 26, 27, 28];
            $lowerMain = [48, 47, 46, 45, 44, 43, 42, 41, 31, 32, 33, 34, 35, 36, 37, 38];
            $upperSecondary = [55, 54, 53, 52, 51, 61, 62, 63, 64, 65];
            $lowerSecondary = [85, 84, 83, 82, 81, 71, 72, 73, 74, 75];
            $ids = array_merge($upperMain, $lowerMain, $upperSecondary, $lowerSecondary);
            $map = [];
            foreach ($ids as $id) {
                $map[(string) $id] = true;
            }
        }
        return $map;
    }
}

if (!function_exists('dcmt_patient_odontogram_allowed_sections')) {
    /**
     * @return array<string, true>
     */
    function dcmt_patient_odontogram_allowed_sections()
    {
        static $map = null;
        if ($map === null) {
            $keys = ['top', 'left', 'center', 'right', 'bottom', 'root-left', 'root-right'];
            $map = array_fill_keys($keys, true);
        }
        return $map;
    }
}

if (!function_exists('dcmt_patient_odontogram_tooth_meta_keys')) {
    /**
     * Reserved keys on each tooth object (not SVG sections).
     *
     * @return array<string, true>
     */
    function dcmt_patient_odontogram_tooth_meta_keys(): array
    {
        return array_fill_keys(['treatments'], true);
    }
}

if (!function_exists('dcmt_patient_odontogram_allowed_states')) {
    /**
     * @return array<string, true>
     */
    function dcmt_patient_odontogram_allowed_states()
    {
        static $map = null;
        if ($map !== null) {
            return $map;
        }

        global $dcmt_pdo;
        if (isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
            if (!function_exists('dcmt_odontogram_allowed_problem_state_keys')) {
                require_once __DIR__ . '/odontogram_treatments.php';
            }
            $map = dcmt_odontogram_allowed_problem_state_keys($dcmt_pdo);
            return $map;
        }

        $keys = ['default', 'damaged', 'filling', 'missing', 'crown', 'implant'];
        $map = array_fill_keys($keys, true);
        return $map;
    }
}

if (!function_exists('dcmt_patient_odontogram_clamp_text')) {
    function dcmt_patient_odontogram_clamp_text($text, $maxLen = 8000)
    {
        $text = trim((string) $text);
        if ($text === '') {
            return '';
        }
        $text = strip_tags($text);
        if (function_exists('mb_substr')) {
            if (mb_strlen($text, 'UTF-8') > $maxLen) {
                return mb_substr($text, 0, $maxLen, 'UTF-8');
            }
            return $text;
        }
        if (strlen($text) > $maxLen) {
            return substr($text, 0, $maxLen);
        }
        return $text;
    }
}

if (!function_exists('dcmt_patient_odontogram_empty_zona')) {
    /**
     * @return array<string, array<int, array<string, mixed>>>
     */
    function dcmt_patient_odontogram_empty_zona(): array
    {
        return ['tl' => [], 'tr' => [], 'bl' => [], 'br' => []];
    }
}

if (!function_exists('dcmt_patient_odontogram_parse_zona_quadrant')) {
    /**
     * @param mixed $value
     * @return list<array{tooth: string, section: string|null, condition: string|null, treatments: list<string>}>
     */
    function dcmt_patient_odontogram_parse_zona_quadrant($value): array
    {
        $allowedTeeth = dcmt_patient_odontogram_allowed_teeth_map();
        $allowedStates = dcmt_patient_odontogram_allowed_states();
        $allowedSections = dcmt_patient_odontogram_allowed_sections();
        if (!is_array($value)) {
            return [];
        }
        $keys = array_keys($value);
        if ($keys !== ['tl', 'tr', 'bl', 'br'] && $keys !== range(0, count($value) - 1)) {
            return [];
        }
        if ($keys === ['tl', 'tr', 'bl', 'br']) {
            return [];
        }
        $out = [];
        foreach ($value as $entry) {
            if (!is_array($entry) || empty($entry['tooth'])) {
                continue;
            }
            $tid = (string) $entry['tooth'];
            if (!isset($allowedTeeth[$tid])) {
                continue;
            }
            $cond = isset($entry['condition']) ? (string) $entry['condition'] : '';
            if ($cond !== '' && !isset($allowedStates[$cond])) {
                $cond = '';
            }
            $treatments = [];
            if (!empty($entry['treatments']) && is_array($entry['treatments'])) {
                foreach ($entry['treatments'] as $name) {
                    $n = dcmt_patient_odontogram_clamp_text($name, 120);
                    if ($n !== '' && !in_array($n, $treatments, true)) {
                        $treatments[] = $n;
                    }
                    if (count($treatments) >= 30) {
                        break;
                    }
                }
            }
            $section = isset($entry['section']) ? (string) $entry['section'] : '';
            if ($section !== '' && !isset($allowedSections[$section])) {
                $section = '';
            }

            if ($cond === '' && empty($treatments)) {
                continue;
            }
            $row = [
                'tooth' => $tid,
                'condition' => $cond !== '' ? $cond : null,
                'treatments' => $treatments,
            ];
            if ($section !== '') {
                $row['section'] = $section;
            }
            $out[] = $row;
        }
        return $out;
    }
}

if (!function_exists('dcmt_patient_odontogram_parse_zona_side')) {
    /**
     * @param mixed $side
     * @return array<string, list<array{tooth: string, condition: string|null, treatments: list<string>}>>
     */
    function dcmt_patient_odontogram_parse_zona_side($side): array
    {
        $empty = dcmt_patient_odontogram_empty_zona();
        if (!is_array($side)) {
            return $empty;
        }
        foreach (['tl', 'tr', 'bl', 'br'] as $q) {
            if (isset($side[$q])) {
                $empty[$q] = dcmt_patient_odontogram_parse_zona_quadrant($side[$q]);
            }
        }
        return $empty;
    }
}

if (!function_exists('dcmt_patient_odontogram_chart_keys')) {
    /** @return list<string> */
    function dcmt_patient_odontogram_chart_keys(): array
    {
        return ['problem', 'solution'];
    }
}

if (!function_exists('dcmt_patient_odontogram_empty_chart')) {
    /** @return array{teeth: array, zonaPosterior: array, zonaAnterior: array} */
    function dcmt_patient_odontogram_empty_chart(): array
    {
        return [
            'teeth' => [],
            'zonaPosterior' => dcmt_patient_odontogram_empty_zona(),
            'zonaAnterior' => dcmt_patient_odontogram_empty_zona(),
        ];
    }
}

if (!function_exists('dcmt_patient_odontogram_is_legacy_chart')) {
    function dcmt_patient_odontogram_is_legacy_chart(array $decoded): bool
    {
        if (isset($decoded['problem']) || isset($decoded['solution'])) {
            return false;
        }

        return isset($decoded['teeth']) || isset($decoded['zonaPosterior']) || isset($decoded['zonaAnterior']);
    }
}

if (!function_exists('dcmt_patient_odontogram_chart_from_pack')) {
    /**
     * @param array{teeth: array, zonaPosterior: array, zonaAnterior: array} $packed
     * @return array{teeth: array, zonaPosterior: array, zonaAnterior: array}
     */
    function dcmt_patient_odontogram_chart_from_pack(array $packed): array
    {
        return [
            'teeth' => $packed['teeth'] ?? [],
            'zonaPosterior' => $packed['zonaPosterior'] ?? dcmt_patient_odontogram_empty_zona(),
            'zonaAnterior' => $packed['zonaAnterior'] ?? dcmt_patient_odontogram_empty_zona(),
        ];
    }
}

if (!function_exists('dcmt_patient_odontogram_parse_chart_slice')) {
    /**
     * Normalize one chart (teeth + zona quadrants).
     *
     * @return array{teeth: array, zonaPosterior: array, zonaAnterior: array, json: string|null}
     */
    function dcmt_patient_odontogram_parse_chart_slice(array $decoded)
    {
        $allowedTeeth = dcmt_patient_odontogram_allowed_teeth_map();
        $allowedSections = dcmt_patient_odontogram_allowed_sections();
        $allowedStates = dcmt_patient_odontogram_allowed_states();
        $metaKeys = dcmt_patient_odontogram_tooth_meta_keys();

        $teeth = [];
        $zonaPosterior = dcmt_patient_odontogram_empty_zona();
        $zonaAnterior = dcmt_patient_odontogram_empty_zona();

        if (!empty($decoded['teeth']) && is_array($decoded['teeth'])) {
            foreach ($decoded['teeth'] as $toothId => $sections) {
                $tid = (string) $toothId;
                if (!isset($allowedTeeth[$tid]) || !is_array($sections)) {
                    continue;
                }
                $clean = [];
                foreach ($sections as $secName => $state) {
                    $sn = (string) $secName;
                    if (isset($metaKeys[$sn])) {
                        if ($sn === 'treatments' && is_array($state)) {
                            $names = [];
                            foreach ($state as $name) {
                                $n = dcmt_patient_odontogram_clamp_text($name, 120);
                                if ($n !== '' && !in_array($n, $names, true)) {
                                    $names[] = $n;
                                }
                                if (count($names) >= 30) {
                                    break;
                                }
                            }
                            if (!empty($names)) {
                                $clean['treatments'] = $names;
                            }
                        }
                        continue;
                    }
                    if (!isset($allowedSections[$sn])) {
                        continue;
                    }
                    $st = is_string($state) ? $state : 'default';
                    if (!isset($allowedStates[$st])) {
                        $st = 'default';
                    }
                    if ($st !== 'default') {
                        $clean[$sn] = $st;
                    }
                }
                if (!empty($clean)) {
                    $teeth[$tid] = $clean;
                }
            }
        }

        if (isset($decoded['zonaPosterior'])) {
            $zonaPosterior = dcmt_patient_odontogram_parse_zona_side($decoded['zonaPosterior']);
        }
        if (isset($decoded['zonaAnterior'])) {
            $zonaAnterior = dcmt_patient_odontogram_parse_zona_side($decoded['zonaAnterior']);
        }

        return dcmt_patient_odontogram_pack($teeth, $zonaPosterior, $zonaAnterior);
    }
}

if (!function_exists('dcmt_patient_odontogram_chart_slice_has_data')) {
    function dcmt_patient_odontogram_chart_slice_has_data(array $chart): bool
    {
        $packed = dcmt_patient_odontogram_pack(
            $chart['teeth'] ?? [],
            $chart['zonaPosterior'] ?? dcmt_patient_odontogram_empty_zona(),
            $chart['zonaAnterior'] ?? dcmt_patient_odontogram_empty_zona()
        );

        return $packed['json'] !== null;
    }
}

if (!function_exists('dcmt_patient_odontogram_decode_document')) {
    /**
     * @param mixed $json Stored patient odontogram JSON
     * @return array{problem: array, solution: array}
     */
    function dcmt_patient_odontogram_decode_document($json): array
    {
        $doc = [
            'problem' => dcmt_patient_odontogram_empty_chart(),
            'solution' => dcmt_patient_odontogram_empty_chart(),
        ];

        if (!is_string($json) || trim($json) === '' || trim($json) === '{}') {
            return $doc;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return $doc;
        }

        if (dcmt_patient_odontogram_is_legacy_chart($decoded)) {
            $packed = dcmt_patient_odontogram_parse_chart_slice($decoded);
            $doc['problem'] = dcmt_patient_odontogram_chart_from_pack($packed);

            return $doc;
        }

        foreach (dcmt_patient_odontogram_chart_keys() as $key) {
            if (!isset($decoded[$key]) || !is_array($decoded[$key])) {
                continue;
            }
            $packed = dcmt_patient_odontogram_parse_chart_slice($decoded[$key]);
            $doc[$key] = dcmt_patient_odontogram_chart_from_pack($packed);
        }

        return $doc;
    }
}

if (!function_exists('dcmt_patient_odontogram_encode_document')) {
    /**
     * @param array $problemChart @param array $solutionChart
     */
    function dcmt_patient_odontogram_encode_document(array $problemChart, array $solutionChart): ?string
    {
        $doc = [];
        $charts = ['problem' => $problemChart, 'solution' => $solutionChart];

        foreach ($charts as $key => $chart) {
            $packed = dcmt_patient_odontogram_pack(
                $chart['teeth'] ?? [],
                $chart['zonaPosterior'] ?? dcmt_patient_odontogram_empty_zona(),
                $chart['zonaAnterior'] ?? dcmt_patient_odontogram_empty_zona()
            );
            if ($packed['json'] !== null) {
                $slice = json_decode($packed['json'], true);
                if (is_array($slice)) {
                    $doc[$key] = $slice;
                }
            }
        }

        if ($doc === []) {
            return null;
        }

        $json = json_encode($doc, JSON_UNESCAPED_UNICODE);

        return $json === false ? null : $json;
    }
}

if (!function_exists('dcmt_parse_patient_odontogram_post')) {
    /**
     * Normalize POST body odontogram JSON (problem + solution charts).
     *
     * @param mixed $raw Usually $_POST['odontogram_data']
     * @return array{problem: array, solution: array, json: string|null}
     */
    function dcmt_parse_patient_odontogram_post($raw)
    {
        $emptyPacked = dcmt_patient_odontogram_pack([], dcmt_patient_odontogram_empty_zona(), dcmt_patient_odontogram_empty_zona());

        if (!is_string($raw) || trim($raw) === '') {
            return [
                'problem' => $emptyPacked,
                'solution' => $emptyPacked,
                'json' => null,
            ];
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return [
                'problem' => $emptyPacked,
                'solution' => $emptyPacked,
                'json' => null,
            ];
        }

        $problemSlice = [];
        $solutionSlice = [];

        if (isset($decoded['problem']) || isset($decoded['solution'])) {
            $problemSlice = is_array($decoded['problem'] ?? null) ? $decoded['problem'] : [];
            $solutionSlice = is_array($decoded['solution'] ?? null) ? $decoded['solution'] : [];
        } elseif (dcmt_patient_odontogram_is_legacy_chart($decoded)) {
            $problemSlice = $decoded;
        }

        $problemPacked = dcmt_patient_odontogram_parse_chart_slice($problemSlice);
        $solutionPacked = dcmt_patient_odontogram_parse_chart_slice($solutionSlice);

        $json = dcmt_patient_odontogram_encode_document(
            dcmt_patient_odontogram_chart_from_pack($problemPacked),
            dcmt_patient_odontogram_chart_from_pack($solutionPacked)
        );

        return [
            'problem' => $problemPacked,
            'solution' => $solutionPacked,
            'json' => $json,
        ];
    }
}

if (!function_exists('dcmt_patient_odontogram_has_data')) {
    /**
     * Whether stored odontogram JSON contains data in problem and/or solution chart.
     *
     * @param mixed $json Raw value from dcmt_patient_odontogram.dcmt_data
     */
    function dcmt_patient_odontogram_has_data($json): bool
    {
        $doc = dcmt_patient_odontogram_decode_document($json);

        foreach (dcmt_patient_odontogram_chart_keys() as $key) {
            if (dcmt_patient_odontogram_chart_slice_has_data($doc[$key])) {
                return true;
            }
        }

        return false;
    }
}

if (!function_exists('dcmt_patient_odontogram_zona_is_empty')) {
    function dcmt_patient_odontogram_zona_is_empty(array $zona): bool
    {
        foreach (['tl', 'tr', 'bl', 'br'] as $q) {
            if (!empty($zona[$q])) {
                return false;
            }
        }
        return true;
    }
}

if (!function_exists('dcmt_patient_odontogram_pack')) {
    /**
     * @param array<string, array<string, string>> $teeth
     * @param array<string, string> $zonaPosterior
     * @param array<string, string> $zonaAnterior
     * @return array{teeth: array<string, array<string, string>>, zonaPosterior: array<string, string>, zonaAnterior: array<string, string>, json: string|null}
     */
    function dcmt_patient_odontogram_pack(array $teeth, $zonaPosterior, $zonaAnterior)
    {
        $payload = [
            'teeth' => $teeth,
            'zonaPosterior' => $zonaPosterior,
            'zonaAnterior' => $zonaAnterior,
        ];

        $emptyTeeth = empty($teeth);
        $emptyZona = dcmt_patient_odontogram_zona_is_empty($zonaPosterior)
            && dcmt_patient_odontogram_zona_is_empty($zonaAnterior);
        if ($emptyTeeth && $emptyZona) {
            return [
                'teeth' => [],
                'zonaPosterior' => dcmt_patient_odontogram_empty_zona(),
                'zonaAnterior' => dcmt_patient_odontogram_empty_zona(),
                'json' => null,
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return [
                'teeth' => [],
                'zonaPosterior' => dcmt_patient_odontogram_empty_zona(),
                'zonaAnterior' => dcmt_patient_odontogram_empty_zona(),
                'json' => null,
            ];
        }

        return [
            'teeth' => $teeth,
            'zonaPosterior' => $zonaPosterior,
            'zonaAnterior' => $zonaAnterior,
            'json' => $json,
        ];
    }
}

if (!function_exists('dcmt_patient_select_columns_without_odontogram')) {
    /**
     * Patient columns for list/light queries (excludes heavy odontogram payload).
     */
    function dcmt_patient_select_columns_without_odontogram(string $alias = 'p', ?PDO $pdo = null): string
    {
        $a = rtrim($alias, '.') . '.';
        $columns = [
            'dcmt_id',
            'dcmt_first_name',
            'dcmt_fathers_last_name',
            'dcmt_mothers_last_name',
            'dcmt_patient_name',
            'dcmt_gender',
            'dcmt_date_of_birth',
            'dcmt_age',
            'dcmt_height_cm',
            'dcmt_weight_kg',
            'dcmt_email',
            'dcmt_phone',
            'dcmt_address',
            'dcmt_medications',
            'dcmt_allergies',
            'dcmt_emergency_contact_name',
            'dcmt_emergency_contact_relation',
            'dcmt_emergency_contact_phone',
            'dcmt_notes',
            'dcmt_referral_source',
            'dcmt_status',
            'dcmt_created_by',
            'dcmt_created_at',
            'dcmt_updated_at',
        ];

        if ($pdo instanceof PDO) {
            try {
                $chk = $pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_birthday_mmdd'");
                if ($chk && $chk->rowCount() > 0) {
                    $columns[] = 'dcmt_birthday_mmdd';
                }
            } catch (PDOException $e) {
                // ignore
            }
        }

        return $a . implode(', ' . $a, $columns);
    }
}

if (!function_exists('dcmt_patient_odontogram_table_exists')) {
    function dcmt_patient_odontogram_table_exists(PDO $pdo): bool
    {
        static $exists = null;
        if ($exists !== null) {
            return $exists;
        }
        try {
            $chk = $pdo->query("SHOW TABLES LIKE 'dcmt_patient_odontogram'");
            $exists = $chk && $chk->rowCount() > 0;
        } catch (PDOException $e) {
            $exists = false;
        }
        return $exists;
    }
}

if (!function_exists('dcmt_load_patient_odontogram_json')) {
    function dcmt_load_patient_odontogram_json(PDO $pdo, int $patientId): string
    {
        if ($patientId <= 0) {
            return '{}';
        }
        if (!dcmt_patient_odontogram_table_exists($pdo)) {
            return '{}';
        }

        try {
            $stmt = $pdo->prepare('SELECT dcmt_data FROM dcmt_patient_odontogram WHERE dcmt_patient_id = ? LIMIT 1');
            $stmt->execute([$patientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($row && isset($row['dcmt_data']) && is_string($row['dcmt_data']) && $row['dcmt_data'] !== '') {
                return $row['dcmt_data'];
            }
        } catch (PDOException $e) {
            error_log('dcmt_load_patient_odontogram_json: ' . $e->getMessage());
        }

        return '{}';
    }
}

if (!function_exists('dcmt_fetch_patient_odontogram_record')) {
    /**
     * @return array{dcmt_patient_id: int, dcmt_data: string, dcmt_updated_at: string|null}|null
     */
    function dcmt_fetch_patient_odontogram_record(PDO $pdo, int $patientId): ?array
    {
        if ($patientId <= 0 || !dcmt_patient_odontogram_table_exists($pdo)) {
            return null;
        }

        try {
            $stmt = $pdo->prepare('SELECT dcmt_patient_id, dcmt_data, dcmt_updated_at FROM dcmt_patient_odontogram WHERE dcmt_patient_id = ? LIMIT 1');
            $stmt->execute([$patientId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (PDOException $e) {
            error_log('dcmt_fetch_patient_odontogram_record: ' . $e->getMessage());
            return null;
        }
    }
}

if (!function_exists('dcmt_patient_odontogram_has_saved_record')) {
    function dcmt_patient_odontogram_has_saved_record(PDO $pdo, int $patientId): bool
    {
        $row = dcmt_fetch_patient_odontogram_record($pdo, $patientId);
        if (!$row) {
            return false;
        }
        return dcmt_patient_odontogram_has_data($row['dcmt_data'] ?? '');
    }
}

if (!function_exists('dcmt_save_patient_odontogram_json')) {
    function dcmt_save_patient_odontogram_json(PDO $pdo, int $patientId, ?string $json): void
    {
        if ($patientId <= 0) {
            return;
        }
        $json = $json ?? null;

        if (!dcmt_patient_odontogram_table_exists($pdo)) {
            return;
        }

        if ($json === null || $json === '' || $json === '{}') {
            $pdo->prepare('DELETE FROM dcmt_patient_odontogram WHERE dcmt_patient_id = ?')->execute([$patientId]);
            return;
        }

        $stmt = $pdo->prepare("
            INSERT INTO dcmt_patient_odontogram (dcmt_patient_id, dcmt_data)
            VALUES (?, ?)
            ON DUPLICATE KEY UPDATE dcmt_data = VALUES(dcmt_data), dcmt_updated_at = CURRENT_TIMESTAMP
        ");
        $stmt->execute([$patientId, $json]);
    }
}

if (!function_exists('dcmt_patient_odontogram_collect_usage_from_chart')) {
    /**
     * @return array{treatments: array<string, true>, states: array<string, true>}
     */
    function dcmt_patient_odontogram_collect_usage_from_chart(array $chart): array
    {
        $treatments = [];
        $states = [];

        if (!empty($chart['teeth']) && is_array($chart['teeth'])) {
            foreach ($chart['teeth'] as $sections) {
                if (!is_array($sections)) {
                    continue;
                }
                foreach ($sections as $key => $value) {
                    if ($key === 'treatments' && is_array($value)) {
                        foreach ($value as $name) {
                            $n = trim((string) $name);
                            if ($n !== '') {
                                $treatments[$n] = true;
                            }
                        }
                        continue;
                    }
                    $st = is_string($value) ? $value : 'default';
                    if ($st !== 'default') {
                        $states[$st] = true;
                    }
                }
            }
        }

        foreach (['zonaPosterior', 'zonaAnterior'] as $zoneKey) {
            if (empty($chart[$zoneKey]) || !is_array($chart[$zoneKey])) {
                continue;
            }
            foreach (['tl', 'tr', 'bl', 'br'] as $quadrant) {
                if (empty($chart[$zoneKey][$quadrant]) || !is_array($chart[$zoneKey][$quadrant])) {
                    continue;
                }
                foreach ($chart[$zoneKey][$quadrant] as $entry) {
                    if (!is_array($entry)) {
                        continue;
                    }
                    $cond = isset($entry['condition']) ? (string) $entry['condition'] : '';
                    if ($cond !== '' && $cond !== 'default') {
                        $states[$cond] = true;
                    }
                    if (!empty($entry['treatments']) && is_array($entry['treatments'])) {
                        foreach ($entry['treatments'] as $name) {
                            $n = trim((string) $name);
                            if ($n !== '') {
                                $treatments[$n] = true;
                            }
                        }
                    }
                }
            }
        }

        return ['treatments' => $treatments, 'states' => $states];
    }
}

if (!function_exists('dcmt_patient_odontogram_collect_usage_from_document')) {
    /**
     * @return array{treatments: array<string, true>, states: array<string, true>}
     */
    function dcmt_patient_odontogram_collect_usage_from_document(array $document): array
    {
        $treatments = [];
        $states = [];

        foreach (dcmt_patient_odontogram_chart_keys() as $chartKey) {
            if (empty($document[$chartKey]) || !is_array($document[$chartKey])) {
                continue;
            }
            $usage = dcmt_patient_odontogram_collect_usage_from_chart($document[$chartKey]);
            $treatments += $usage['treatments'];
            $states += $usage['states'];
        }

        return ['treatments' => $treatments, 'states' => $states];
    }
}
