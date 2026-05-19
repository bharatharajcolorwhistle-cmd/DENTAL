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
        $zonaPosterior = ['tl' => '', 'tr' => '', 'bl' => '', 'br' => ''];
        $zonaAnterior = ['tl' => '', 'tr' => '', 'bl' => '', 'br' => ''];

        if (!is_string($raw) || $raw === '') {
            return dcmt_patient_odontogram_pack($teeth, $zonaPosterior, $zonaAnterior);
        }

        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return dcmt_patient_odontogram_pack($teeth, $zonaPosterior, $zonaAnterior);
        }

        if (!empty($decoded['teeth']) && is_array($decoded['teeth'])) {
            foreach ($decoded['teeth'] as $toothId => $sections) {
                $tid = (string) $toothId;
                if (!isset($allowedTeeth[$tid]) || !is_array($sections)) {
                    continue;
                }
                $clean = [];
                foreach ($sections as $secName => $state) {
                    $sn = (string) $secName;
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
            if (is_string($decoded['zonaPosterior'])) {
                // Backward compatibility with old single textarea model.
                $zonaPosterior['tl'] = dcmt_patient_odontogram_clamp_text($decoded['zonaPosterior']);
            } elseif (is_array($decoded['zonaPosterior'])) {
                foreach (['tl', 'tr', 'bl', 'br'] as $k) {
                    if (isset($decoded['zonaPosterior'][$k])) {
                        $zonaPosterior[$k] = dcmt_patient_odontogram_clamp_text($decoded['zonaPosterior'][$k]);
                    }
                }
            }
        }
        if (isset($decoded['zonaAnterior'])) {
            if (is_string($decoded['zonaAnterior'])) {
                // Backward compatibility with old single textarea model.
                $zonaAnterior['tl'] = dcmt_patient_odontogram_clamp_text($decoded['zonaAnterior']);
            } elseif (is_array($decoded['zonaAnterior'])) {
                foreach (['tl', 'tr', 'bl', 'br'] as $k) {
                    if (isset($decoded['zonaAnterior'][$k])) {
                        $zonaAnterior[$k] = dcmt_patient_odontogram_clamp_text($decoded['zonaAnterior'][$k]);
                    }
                }
            }
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
            foreach ($decoded['teeth'] as $sections) {
                if (is_array($sections) && !empty($sections)) {
                    return true;
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
                    if (trim((string) ($zona[$q] ?? '')) !== '') {
                        return true;
                    }
                }
            }
        }

        return false;
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
        $emptyZona =
            (($zonaPosterior['tl'] ?? '') === '' && ($zonaPosterior['tr'] ?? '') === '' && ($zonaPosterior['bl'] ?? '') === '' && ($zonaPosterior['br'] ?? '') === '') &&
            (($zonaAnterior['tl'] ?? '') === '' && ($zonaAnterior['tr'] ?? '') === '' && ($zonaAnterior['bl'] ?? '') === '' && ($zonaAnterior['br'] ?? '') === '');
        if ($emptyTeeth && $emptyZona) {
            return [
                'teeth' => [],
                'zonaPosterior' => ['tl' => '', 'tr' => '', 'bl' => '', 'br' => ''],
                'zonaAnterior' => ['tl' => '', 'tr' => '', 'bl' => '', 'br' => ''],
                'json' => null,
            ];
        }

        $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
        if ($json === false) {
            return [
                'teeth' => [],
                'zonaPosterior' => ['tl' => '', 'tr' => '', 'bl' => '', 'br' => ''],
                'zonaAnterior' => ['tl' => '', 'tr' => '', 'bl' => '', 'br' => ''],
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
