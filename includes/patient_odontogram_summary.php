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

if (!function_exists('dcmt_patient_odontogram_clinical_quadrant_keys')) {
    /**
     * Quadrant order used in clinic treatment planning: Q1, Q2, Q4, Q3.
     *
     * @return list<string>
     */
    function dcmt_patient_odontogram_clinical_quadrant_keys(): array
    {
        return ['tl', 'tr', 'br', 'bl'];
    }
}

if (!function_exists('dcmt_patient_odontogram_summary_cell_headers')) {
    /**
     * Summary table columns in clinic workflow order: PQ1, AQ1, PQ2, AQ2, PQ4, AQ4, PQ3, AQ3.
     *
     * @return list<array{key: string, zone: string, quadrant: string, label: string, title: string}>
     */
    function dcmt_patient_odontogram_summary_cell_headers(): array
    {
        $headers = [];
        foreach (dcmt_patient_odontogram_clinical_quadrant_keys() as $quadrant) {
            foreach (dcmt_patient_odontogram_zone_keys() as $zone) {
                $zoneShort = ($zone === 'anterior') ? 'A' : 'P';
                $quadrantLabel = dcmt_patient_odontogram_quadrant_label($quadrant);
                $headers[] = [
                    'key' => $zone . '_' . $quadrant,
                    'zone' => $zone,
                    'quadrant' => $quadrant,
                    'label' => $zoneShort . ' ' . $quadrantLabel,
                    'title' => dcmt_patient_odontogram_zone_label($zone) . ' ' . $quadrantLabel,
                ];
            }
        }

        return $headers;
    }
}

if (!function_exists('dcmt_patient_odontogram_clinical_cell_sort_index')) {
    function dcmt_patient_odontogram_clinical_cell_sort_index(string $zone, string $quadrant): int
    {
        static $quadrantOrder = ['tl' => 0, 'tr' => 1, 'br' => 2, 'bl' => 3];
        static $zoneOrder = ['posterior' => 0, 'anterior' => 1];

        $q = $quadrantOrder[$quadrant] ?? 99;
        $z = $zoneOrder[$zone] ?? 99;

        return ($q * 10) + $z;
    }
}

if (!function_exists('dcmt_patient_odontogram_zone_wise_cell_sort_index')) {
    /**
     * Proposed plan order: Posterior Q1–Q4, then Anterior Q1–Q4.
     */
    function dcmt_patient_odontogram_zone_wise_cell_sort_index(string $zone, string $quadrant): int
    {
        static $zoneOrder = ['posterior' => 0, 'anterior' => 1];
        static $quadrantOrder = ['tl' => 0, 'tr' => 1, 'bl' => 2, 'br' => 3];

        $z = $zoneOrder[$zone] ?? 99;
        $q = $quadrantOrder[$quadrant] ?? 99;

        return ($z * 10) + $q;
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

        $cellHeaders = dcmt_patient_odontogram_summary_cell_headers();
        $cellKeys = array_map(static function (array $header): string {
            return (string) $header['key'];
        }, $cellHeaders);

        $matrix = [];
        foreach ($counts['treatments'] as $treatment) {
            $cells = [];
            $rowTotal = 0;
            foreach ($cellHeaders as $header) {
                $key = (string) $header['key'];
                $qty = (int) ($counts['by_zone_quadrant'][$header['zone']][$header['quadrant']][$treatment] ?? 0);
                $cells[$key] = $qty;
                $rowTotal += $qty;
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

        return $groups;
    }
}
