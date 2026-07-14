<?php
/**
 * Odontogram solution summary — quadrant-wise treatment counts.
 */

require_once __DIR__ . '/patient_odontogram.php';

if (!function_exists('dcmt_patient_odontogram_quadrant_keys')) {
    /** @return list<string> */
    function dcmt_patient_odontogram_quadrant_keys(): array
    {
        return ['tl', 'tr', 'bl', 'br'];
    }
}

if (!function_exists('dcmt_patient_odontogram_quadrant_label')) {
    function dcmt_patient_odontogram_quadrant_label(string $quadrant): string
    {
        static $map = ['tl' => 'Q1', 'tr' => 'Q2', 'bl' => 'Q3', 'br' => 'Q4'];
        return $map[$quadrant] ?? strtoupper($quadrant);
    }
}

if (!function_exists('dcmt_patient_odontogram_zone_keys')) {
    /** @return list<string> */
    function dcmt_patient_odontogram_zone_keys(): array
    {
        return ['posterior', 'anterior'];
    }
}

if (!function_exists('dcmt_patient_odontogram_zone_label')) {
    function dcmt_patient_odontogram_zone_label(string $zone): string
    {
        if ($zone === 'anterior') {
            return trans('patient', 'odontogram_zona_anterior');
        }
        return trans('patient', 'odontogram_zona_posterior');
    }
}

if (!function_exists('dcmt_patient_odontogram_solution_zone_field')) {
    function dcmt_patient_odontogram_solution_zone_field(string $zone): string
    {
        return $zone === 'anterior' ? 'zonaAnterior' : 'zonaPosterior';
    }
}

if (!function_exists('dcmt_patient_odontogram_count_solution_treatments')) {
    /**
     * Count clinical treatments in the solution chart, grouped by zone and quadrant.
     *
     * @return array{
     *   by_zone_quadrant: array<string, array<string, array<string, int>>>,
     *   by_treatment: array<string, int>,
     *   treatments: list<string>,
     *   total_applications: int
     * }
     */
    function dcmt_patient_odontogram_count_solution_treatments(array $solutionChart): array
    {
        $byZoneQuadrant = [
            'posterior' => array_fill_keys(dcmt_patient_odontogram_quadrant_keys(), []),
            'anterior' => array_fill_keys(dcmt_patient_odontogram_quadrant_keys(), []),
        ];
        $byTreatment = [];
        $totalApplications = 0;

        foreach (dcmt_patient_odontogram_zone_keys() as $zone) {
            $field = dcmt_patient_odontogram_solution_zone_field($zone);
            $zona = $solutionChart[$field] ?? [];
            if (!is_array($zona)) {
                continue;
            }
            foreach (dcmt_patient_odontogram_quadrant_keys() as $quadrant) {
                $entries = $zona[$quadrant] ?? [];
                if (!is_array($entries)) {
                    continue;
                }
                foreach ($entries as $entry) {
                    if (!is_array($entry) || empty($entry['treatments']) || !is_array($entry['treatments'])) {
                        continue;
                    }
                    foreach ($entry['treatments'] as $name) {
                        $treatment = trim((string) $name);
                        if ($treatment === '') {
                            continue;
                        }
                        if (!isset($byZoneQuadrant[$zone][$quadrant][$treatment])) {
                            $byZoneQuadrant[$zone][$quadrant][$treatment] = 0;
                        }
                        $byZoneQuadrant[$zone][$quadrant][$treatment]++;
                        if (!isset($byTreatment[$treatment])) {
                            $byTreatment[$treatment] = 0;
                        }
                        $byTreatment[$treatment]++;
                        $totalApplications++;
                    }
                }
            }
        }

        $treatments = array_keys($byTreatment);
        sort($treatments, SORT_NATURAL | SORT_FLAG_CASE);

        return [
            'by_zone_quadrant' => $byZoneQuadrant,
            'by_treatment' => $byTreatment,
            'treatments' => $treatments,
            'total_applications' => $totalApplications,
        ];
    }
}

if (!function_exists('dcmt_patient_odontogram_solution_summary')) {
    /**
     * Build a full solution summary from stored odontogram JSON or document array.
     *
     * @param mixed $odontogramJsonOrDocument
     * @return array{
     *   has_data: bool,
     *   by_zone_quadrant: array<string, array<string, array<string, int>>>,
     *   by_treatment: array<string, int>,
     *   treatments: list<string>,
     *   total_applications: int,
     *   matrix: list<array{treatment: string, cells: array<string, int>, total: int}>
     * }
     */
    function dcmt_patient_odontogram_solution_summary($odontogramJsonOrDocument): array
    {
        if (is_string($odontogramJsonOrDocument)) {
            $document = dcmt_patient_odontogram_decode_document($odontogramJsonOrDocument);
        } elseif (is_array($odontogramJsonOrDocument)) {
            if (isset($odontogramJsonOrDocument['solution'])) {
                $document = $odontogramJsonOrDocument;
            } else {
                $document = ['solution' => $odontogramJsonOrDocument];
            }
        } else {
            $document = dcmt_patient_odontogram_decode_document('{}');
        }

        $solution = $document['solution'] ?? dcmt_patient_odontogram_empty_chart();
        $counts = dcmt_patient_odontogram_count_solution_treatments($solution);

        $cellKeys = [];
        foreach (dcmt_patient_odontogram_zone_keys() as $zone) {
            foreach (dcmt_patient_odontogram_quadrant_keys() as $quadrant) {
                $cellKeys[] = $zone . '_' . $quadrant;
            }
        }

        $matrix = [];
        foreach ($counts['treatments'] as $treatment) {
            $cells = [];
            $rowTotal = 0;
            foreach (dcmt_patient_odontogram_zone_keys() as $zone) {
                foreach (dcmt_patient_odontogram_quadrant_keys() as $quadrant) {
                    $key = $zone . '_' . $quadrant;
                    $qty = (int) ($counts['by_zone_quadrant'][$zone][$quadrant][$treatment] ?? 0);
                    $cells[$key] = $qty;
                    $rowTotal += $qty;
                }
            }
            $matrix[] = [
                'treatment' => $treatment,
                'cells' => $cells,
                'total' => $rowTotal,
            ];
        }

        return [
            'has_data' => $counts['total_applications'] > 0,
            'by_zone_quadrant' => $counts['by_zone_quadrant'],
            'by_treatment' => $counts['by_treatment'],
            'treatments' => $counts['treatments'],
            'total_applications' => $counts['total_applications'],
            'matrix' => $matrix,
            'cell_keys' => $cellKeys,
        ];
    }
}

if (!function_exists('dcmt_patient_odontogram_solution_plan_groups')) {
    /**
     * Group solution treatments for treatment-plan line generation (per treatment, zone, quadrant).
     *
     * @return list<array{treatment: string, zone: string, quadrant: string, quadrant_label: string, zone_label: string, quantity: int}>
     */
    function dcmt_patient_odontogram_solution_plan_groups(array $solutionChart): array
    {
        $summary = dcmt_patient_odontogram_count_solution_treatments($solutionChart);
        $groups = [];

        foreach (dcmt_patient_odontogram_zone_keys() as $zone) {
            foreach (dcmt_patient_odontogram_quadrant_keys() as $quadrant) {
                $bucket = $summary['by_zone_quadrant'][$zone][$quadrant] ?? [];
                if (!is_array($bucket)) {
                    continue;
                }
                foreach ($bucket as $treatment => $qty) {
                    $quantity = (int) $qty;
                    if ($quantity <= 0) {
                        continue;
                    }
                    $groups[] = [
                        'treatment' => (string) $treatment,
                        'zone' => $zone,
                        'quadrant' => $quadrant,
                        'quadrant_label' => dcmt_patient_odontogram_quadrant_label($quadrant),
                        'zone_label' => dcmt_patient_odontogram_zone_label($zone),
                        'quantity' => $quantity,
                    ];
                }
            }
        }

        usort($groups, static function (array $a, array $b): int {
            $cmp = strcmp($a['treatment'], $b['treatment']);
            if ($cmp !== 0) {
                return $cmp;
            }
            $cmp = strcmp($a['zone'], $b['zone']);
            if ($cmp !== 0) {
                return $cmp;
            }
            return strcmp($a['quadrant'], $b['quadrant']);
        });

        return $groups;
    }
}
