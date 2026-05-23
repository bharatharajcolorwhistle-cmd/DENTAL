<?php
/**
 * Inventory CSV import/export — localized headers and safe UTF-8 normalization
 */

/**
 * @param mixed $h
 */
function dcmt_inventory_import_normalize_csv_header($h): string
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
 * @return list<string>
 */
function dcmt_inventory_import_template_field_order(): array
{
    return [
        'name',
        'brand',
        'sku',
        'description',
        'category_name',
        'quantity',
        'min_quantity',
        'price',
        'status',
        'supplier',
        'expiry_date',
        'created_by',
    ];
}

/**
 * @return list<string>
 */
function dcmt_inventory_import_export_field_order(): array
{
    return [
        'id',
        'name',
        'brand',
        'sku',
        'description',
        'category_name',
        'quantity',
        'min_quantity',
        'price',
        'status',
        'supplier',
        'expiry_date',
        'created_by',
        'created_at',
        'updated_at',
    ];
}

/**
 * @return list<string>
 */
function dcmt_inventory_import_all_internal_header_keys(): array
{
    return array_values(array_unique(array_merge(
        dcmt_inventory_import_template_field_order(),
        dcmt_inventory_import_export_field_order()
    )));
}

/**
 * @return array<string, string> normalized label => internal key
 */
function dcmt_inventory_import_display_to_internal_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $map = [];
    foreach (dcmt_inventory_import_all_internal_header_keys() as $field) {
        $map[dcmt_inventory_import_normalize_csv_header($field)] = $field;
    }

    foreach (['en', 'es'] as $lang) {
        $file = __DIR__ . '/../lang/' . $lang . '/inventory.php';
        if (!is_readable($file)) {
            continue;
        }
        /** @var array<string, string> $translations */
        $translations = include $file;
        foreach (dcmt_inventory_import_all_internal_header_keys() as $field) {
            $key = 'import_csv_hdr_' . $field;
            if (!empty($translations[$key]) && is_string($translations[$key])) {
                $norm = dcmt_inventory_import_normalize_csv_header($translations[$key]);
                if ($norm !== '') {
                    $map[$norm] = $field;
                }
            }
        }
    }

    return $map;
}

/**
 * @param list<string|null> $raw_headers
 * @return list<string>
 */
function dcmt_inventory_import_map_header_row(array $raw_headers): array
{
    $display_map = dcmt_inventory_import_display_to_internal_map();
    $internal = dcmt_inventory_import_all_internal_header_keys();
    $internal_lookup = array_fill_keys($internal, true);

    $out = [];
    foreach ($raw_headers as $cell) {
        $n = dcmt_inventory_import_normalize_csv_header($cell);
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
 * @param list<string> $mapped_headers
 */
function dcmt_inventory_import_mapped_headers_are_valid(array $mapped_headers): bool
{
    foreach (['name', 'sku', 'price'] as $k) {
        if (!in_array($k, $mapped_headers, true)) {
            return false;
        }
    }
    return true;
}

/**
 * @return list<string>
 */
function dcmt_inventory_import_template_headers(): array
{
    $row = [];
    foreach (dcmt_inventory_import_template_field_order() as $suffix) {
        $row[] = trans('inventory', 'import_csv_hdr_' . $suffix);
    }
    return $row;
}

/**
 * @return list<string>
 */
function dcmt_inventory_import_export_headers(): array
{
    $row = [];
    foreach (dcmt_inventory_import_export_field_order() as $suffix) {
        $row[] = trans('inventory', 'import_csv_hdr_' . $suffix);
    }
    return $row;
}
