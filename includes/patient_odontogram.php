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
        if ($map === null) {
            $keys = ['default', 'damaged', 'filling', 'missing', 'crown', 'implant'];
            $map = array_fill_keys($keys, true);
        }
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
     * @return list<array{tooth: string, condition: string|null, treatments: list<string>}>
     */
    function dcmt_patient_odontogram_parse_zona_quadrant($value): array
    {
        $allowedTeeth = dcmt_patient_odontogram_allowed_teeth_map();
        $allowedStates = dcmt_patient_odontogram_allowed_states();
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
            if ($cond === '' && empty($treatments)) {
                continue;
            }
            $out[] = [
                'tooth' => $tid,
                'condition' => $cond !== '' ? $cond : null,
                'treatments' => $treatments,
            ];
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

if (!function_exists('dcmt_parse_patient_odontogram_post')) {
    /**
     * Normalize POST body odontogram JSON.
     *
     * @param mixed $raw Usually $_POST['odontogram_data']
     * @return array{teeth: array<string, array<string, string>>, zonaPosterior: array<string, string>, zonaAnterior: array<string, string>, json: string|null}
     */
    function dcmt_parse_patient_odontogram_post($raw)
    {
        $allowedTeeth = dcmt_patient_odontogram_allowed_teeth_map();
        $allowedSections = dcmt_patient_odontogram_allowed_sections();
        $allowedStates = dcmt_patient_odontogram_allowed_states();

        $teeth = [];
        $zonaPosterior = dcmt_patient_odontogram_empty_zona();
        $zonaAnterior = dcmt_patient_odontogram_empty_zona();

        if (!is_string($raw) || $raw === '') {
            return dcmt_patient_odontogram_pack($teeth, $zonaPosterior, $zonaAnterior);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return dcmt_patient_odontogram_pack($teeth, $zonaPosterior, $zonaAnterior);
        }

        $metaKeys = dcmt_patient_odontogram_tooth_meta_keys();

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

if (!function_exists('dcmt_patient_odontogram_has_data')) {
    /**
     * Whether stored odontogram JSON contains tooth marks or zone notes.
     *
     * @param mixed $json Raw value from dcmt_odontogram_data
     */
    function dcmt_patient_odontogram_has_data($json): bool
    {
        if (!is_string($json) || trim($json) === '') {
            return false;
        }

        $decoded = json_decode($json, true);
        if (!is_array($decoded)) {
            return false;
        }

        if (!empty($decoded['teeth']) && is_array($decoded['teeth'])) {
            $metaKeys = dcmt_patient_odontogram_tooth_meta_keys();
            foreach ($decoded['teeth'] as $sections) {
                if (!is_array($sections) || empty($sections)) {
                    continue;
                }
                foreach ($sections as $key => $val) {
                    if (isset($metaKeys[$key])) {
                        if ($key === 'treatments' && is_array($val) && !empty($val)) {
                            return true;
                        }
                        continue;
                    }
                    if (is_string($val) && $val !== '' && $val !== 'default') {
                        return true;
                    }
                }
            }
        }

        foreach (['zonaPosterior', 'zonaAnterior'] as $zonaKey) {
            if (!isset($decoded[$zonaKey])) {
                continue;
            }
            $zona = $decoded[$zonaKey];
            if (is_string($zona) && trim($zona) !== '') {
                return true;
            }
            if (is_array($zona)) {
                foreach (['tl', 'tr', 'bl', 'br'] as $q) {
                    $quad = $zona[$q] ?? null;
                    if (is_string($quad) && trim($quad) !== '') {
                        return true;
                    }
                    if (is_array($quad) && !empty($quad)) {
                        return true;
                    }
                }
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
