<?php
/**
 * Income CSV import/export — localized headers and safe UTF-8 normalization
 */

/**
 * @param mixed $h
 */
function dcmt_income_import_normalize_csv_header($h): string
{
    $h = (string) ($h ?? '');
    if ($h !== '' && strncmp($h, "\xEF\xBB\xBF", 3) === 0) {
        $h = substr($h, 3);
    }
    $cleaned = preg_replace('/[\x00-\x1F\x7F]/u', '', $h);
    $h = trim((string) ($cleaned ?? ''));
    if (function_exists('mb_strtolower')) {
        return mb_strtolower($h, 'UTF-8');
    }
    return strtolower($h);
}

/**
 * Internal column keys in the downloadable import template (same order as CSV columns).
 *
 * @return list<string>
 */
function dcmt_income_import_template_field_order(): array
{
    return [
        'patient_name',
        'type',
        'description',
        'amount',
        'paid_amount',
        'pending_amount',
        'consultation_paid_amount',
        'product_paid_amount',
        'total_paid_amount',
        'total_pending_amount',
        'consultation_fee',
        'service_id',
        'service_amount',
        'service_paid_amount',
        'service_pending_amount',
        'product_amount',
        'product_pending_amount',
        'payment_mode',
        'payment_method',
        'payment_status',
        'doctor_name',
        'transaction_date',
        'created_by',
        'service_items',
        'product_items',
        'payment_details',
    ];
}

/**
 * Internal column keys for CSV export (includes metadata columns).
 *
 * @return list<string>
 */
function dcmt_income_import_export_field_order(): array
{
    return [
        'id',
        'patient_name',
        'type',
        'description',
        'amount',
        'paid_amount',
        'pending_amount',
        'consultation_paid_amount',
        'product_paid_amount',
        'total_paid_amount',
        'total_pending_amount',
        'consultation_fee',
        'service_id',
        'service_amount',
        'service_paid_amount',
        'service_pending_amount',
        'product_amount',
        'product_pending_amount',
        'payment_mode',
        'payment_method_id',
        'payment_method',
        'payment_status_id',
        'payment_status',
        'doctor_id',
        'doctor_name',
        'transaction_date',
        'created_by',
        'created_at',
        'updated_at',
        'service_items',
        'product_items',
        'payment_details',
    ];
}

/**
 * All internal field keys that may appear in CSV headers (template + export).
 *
 * @return list<string>
 */
function dcmt_income_import_all_internal_header_keys(): array
{
    return array_values(array_unique(array_merge(
        dcmt_income_import_template_field_order(),
        dcmt_income_import_export_field_order()
    )));
}

/**
 * Map display labels (en + es) and snake_case keys to internal field names.
 *
 * @return array<string, string> normalized label => internal key
 */
function dcmt_income_import_display_to_internal_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (dcmt_income_import_all_internal_header_keys() as $field) {
        $map[dcmt_income_import_normalize_csv_header($field)] = $field;
    }

    foreach (['en', 'es'] as $lang) {
        $file = __DIR__ . '/../lang/' . $lang . '/income.php';
        if (!is_readable($file)) {
            continue;
        }
        /** @var array<string, string> $translations */
        $translations = include $file;
        foreach (dcmt_income_import_all_internal_header_keys() as $field) {
            $key = 'import_csv_hdr_' . $field;
            if (!empty($translations[$key]) && is_string($translations[$key])) {
                $norm = dcmt_income_import_normalize_csv_header($translations[$key]);
                if ($norm !== '') {
                    $map[$norm] = $field;
                }
            }
        }
    }

    return $map;
}

/**
 * Map one CSV header row to internal field keys (same column count).
 *
 * @param list<string|null> $raw_headers
 * @return list<string>
 */
function dcmt_income_import_map_header_row(array $raw_headers): array
{
    $display_map = dcmt_income_import_display_to_internal_map();
    $internal = dcmt_income_import_all_internal_header_keys();
    $internal_lookup = array_fill_keys($internal, true);

    $out = [];
    foreach ($raw_headers as $cell) {
        $n = dcmt_income_import_normalize_csv_header($cell);
        if (isset($display_map[$n])) {
            $out[] = $display_map[$n];
        } elseif (isset($internal_lookup[$n])) {
            $out[] = $n;
        } else {
            $out[] = $n;
        }
    }
    return $out;
}

/**
 * Whether mapped headers include all required import columns.
 *
 * @param list<string> $mapped_headers
 */
function dcmt_income_import_mapped_headers_are_valid(array $mapped_headers): bool
{
    $need = ['patient_name', 'type', 'amount', 'transaction_date'];
    foreach ($need as $k) {
        if (!in_array($k, $mapped_headers, true)) {
            return false;
        }
    }
    return true;
}

/**
 * Localized header row for the income import template.
 *
 * @return list<string>
 */
function dcmt_income_import_template_headers(): array
{
    $row = [];
    foreach (dcmt_income_import_template_field_order() as $suffix) {
        $row[] = trans('income', 'import_csv_hdr_' . $suffix);
    }
    return $row;
}

/**
 * Localized header row for income CSV export.
 *
 * @return list<string>
 */
function dcmt_income_import_export_headers(): array
{
    $row = [];
    foreach (dcmt_income_import_export_field_order() as $suffix) {
        $row[] = trans('income', 'import_csv_hdr_' . $suffix);
    }
    return $row;
}
