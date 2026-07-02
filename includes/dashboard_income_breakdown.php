<?php
/**
 * Dashboard income breakdown (service vs product) for the financial tab.
 * Uses the same totals as pages/income/index.php when filtering by line type and transaction date.
 */

require_once __DIR__ . '/income_doctor_filter_totals.php';

if (!function_exists('dcmt_dashboard_income_breakdown_totals')) {
    /**
     * Income split by service vs product for a transaction-date range (inclusive).
     * Totals match income index header "Income" (paid + pending) per line-type filter.
     *
     * @return array{service: float, product: float}
     */
    function dcmt_dashboard_income_breakdown_totals(
        PDO $pdo,
        string $dateFrom,
        string $dateTo,
        ?int $doctorUserId = null
    ): array {
        if ($dateFrom === '' || $dateTo === '') {
            return ['service' => 0.0, 'product' => 0.0];
        }

        return [
            'service' => dcmt_income_period_total_like_index($pdo, $dateFrom, $dateTo, 'service', $doctorUserId),
            'product' => dcmt_income_period_total_like_index($pdo, $dateFrom, $dateTo, 'product', $doctorUserId),
        ];
    }
}
