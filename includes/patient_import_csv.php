<?php
/**
 * Patient CSV import/export — header normalization and bilingual column labels
 */

/**
 * Normalize a single CSV header cell for matching (UTF-8 safe, BOM stripped).
 *
 * @param mixed $h Cell value from fgetcsv (may be null).
 */
function dcmt_patient_import_normalize_csv_header($h): string
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
 * Map localized CSV header labels (en + es) to internal column keys used by import logic.
 */
function dcmt_patient_import_standard_csv_header_map(): array
{
    static $map = null;
    if ($map !== null) {
        return $map;
    }

    $base = [
        'full name' => 'full_name',
        'patient name' => 'full_name',
        'patient_name' => 'full_name',
        'name' => 'first_name',
        'first_name' => 'first_name',
        'surname' => 'last_name',
        'last_name' => 'last_name',
        'father last name' => 'fathers_last_name',
        'father lastname' => 'fathers_last_name',
        'fathers last name' => 'fathers_last_name',
        'fathers lastname' => 'fathers_last_name',
        'fathers_last_name' => 'fathers_last_name',
        "father's last name" => 'fathers_last_name',
        'mother last name' => 'mothers_last_name',
        'mother lastname' => 'mothers_last_name',
        'mothers last name' => 'mothers_last_name',
        'mothers lastname' => 'mothers_last_name',
        'mothers_last_name' => 'mothers_last_name',
        "mother's last name" => 'mothers_last_name',
        'apellido paterno' => 'fathers_last_name',
        'apellido materno' => 'mothers_last_name',
        'gender' => 'gender',
        'date_of_birth' => 'date_of_birth',
        'dob' => 'date_of_birth',
        'age' => 'age',
        'height_cm' => 'height_cm',
        'weight_kg' => 'weight_kg',
        'email' => 'email',
        'phone number' => 'phone',
        'phone' => 'phone',
        'telefono' => 'phone',
        'teléfono' => 'phone',
        'address' => 'address',
        'medications' => 'medications',
        'allergies' => 'allergies',
        'emergency_contact_name' => 'emergency_contact_name',
        'emergency_contact_relation' => 'emergency_contact_relation',
        'emergency_contact_phone' => 'emergency_contact_phone',
        'notes' => 'notes',
        'status' => 'status',
        'id' => 'record_id',
    ];

    $suffix_to_col = [];
    foreach (dcmt_patient_import_standard_template_field_order() as $suffix) {
        $suffix_to_col[$suffix] = ($suffix === 'patient_name') ? 'full_name' : $suffix;
    }
    $suffix_to_col = array_merge($suffix_to_col, [
        'record_id' => 'record_id',
        'odontogram_json' => 'odontogram_json',
        'created_by' => 'created_by',
        'created_at' => 'created_at',
        'updated_at' => 'updated_at',
    ]);

    foreach (['en', 'es'] as $lang) {
        $file = __DIR__ . '/../lang/' . $lang . '/patient.php';
        if (!is_readable($file)) {
            continue;
        }
        /** @var array<string, string> $translations */
        $translations = include $file;
        foreach ($suffix_to_col as $suffix => $col) {
            $key = 'import_csv_hdr_' . $suffix;
            if (!empty($translations[$key]) && is_string($translations[$key])) {
                $norm = dcmt_patient_import_normalize_csv_header($translations[$key]);
                if ($norm !== '') {
                    $base[$norm] = $col;
                }
            }
        }
    }

    $map = $base;
    return $map;
}

/**
 * Ordered field suffixes for the standard patient import template (matches import columns).
 *
 * @return list<string>
 */
function dcmt_patient_import_standard_template_field_order(): array
{
    return [
        'patient_name',
        'first_name',
        'fathers_last_name',
        'mothers_last_name',
        'gender',
        'date_of_birth',
        'age',
        'height_cm',
        'weight_kg',
        'email',
        'phone',
        'address',
        'medications',
        'allergies',
        'emergency_contact_name',
        'emergency_contact_relation',
        'emergency_contact_phone',
        'notes',
        'status',
    ];
}

/**
 * Build one header row for the standard template using current locale.
 *
 * @return list<string>
 */
function dcmt_patient_import_standard_template_headers(): array
{
    $row = [];
    foreach (dcmt_patient_import_standard_template_field_order() as $suffix) {
        $row[] = trans('patient', 'import_csv_hdr_' . $suffix);
    }
    return $row;
}

/**
 * Header suffix order for patient CSV export (includes DB metadata columns).
 *
 * @return list<string>
 */
function dcmt_patient_import_export_header_suffixes(): array
{
    return array_merge(
        ['record_id'],
        dcmt_patient_import_standard_template_field_order(),
        ['odontogram_json', 'created_by', 'created_at', 'updated_at']
    );
}

/**
 * Localized header row for patient CSV export.
 *
 * @return list<string>
 */
function dcmt_patient_import_export_headers(): array
{
    $row = [];
    foreach (dcmt_patient_import_export_header_suffixes() as $suffix) {
        $row[] = trans('patient', 'import_csv_hdr_' . $suffix);
    }
    return $row;
}

/**
 * Merge Dentalink-style header map with labels from en/es language files.
 *
 * @param array<string, string> $base
 * @return array<string, string>
 */
function dcmt_patient_import_dentalink_header_map(array $base): array
{
    $suffixes = [
        'first_name',
        'surnames',
        'birth_date',
        'age',
        'phone',
        'city',
        'district',
        'address_line',
        'email',
        'sex',
        'guardian',
    ];

    foreach (['en', 'es'] as $lang) {
        $file = __DIR__ . '/../lang/' . $lang . '/patient.php';
        if (!is_readable($file)) {
            continue;
        }
        /** @var array<string, string> $translations */
        $translations = include $file;
        foreach ($suffixes as $suffix) {
            $key = 'import_dentalink_hdr_' . $suffix;
            if (!empty($translations[$key]) && is_string($translations[$key])) {
                $norm = dcmt_patient_import_normalize_csv_header($translations[$key]);
                if ($norm === '') {
                    continue;
                }
                if ($suffix === 'first_name') {
                    $base[$norm] = 'first_name';
                } elseif ($suffix === 'surnames') {
                    $base[$norm] = 'fathers_last_name';
                } elseif ($suffix === 'birth_date') {
                    $base[$norm] = 'date_of_birth';
                } elseif ($suffix === 'age') {
                    $base[$norm] = 'age';
                } elseif ($suffix === 'phone') {
                    $base[$norm] = 'phone';
                } elseif ($suffix === 'city') {
                    $base[$norm] = 'addr_city';
                } elseif ($suffix === 'district') {
                    $base[$norm] = 'addr_comuna';
                } elseif ($suffix === 'address_line') {
                    $base[$norm] = 'addr_line';
                } elseif ($suffix === 'email') {
                    $base[$norm] = 'email';
                } elseif ($suffix === 'sex') {
                    $base[$norm] = 'gender';
                } elseif ($suffix === 'guardian') {
                    $base[$norm] = 'emergency_contact_name';
                }
            }
        }
    }

    return $base;
}

/**
 * Normalize gender values from CSV (standard or localized).
 *
 * @param mixed $raw
 */
function dcmt_patient_import_normalize_gender($raw): string
{
    $g = dcmt_patient_import_normalize_csv_header($raw);
    $map = [
        'm' => 'male',
        'f' => 'female',
        'male' => 'male',
        'female' => 'female',
        'other' => 'other',
        'otro' => 'other',
        'otra' => 'other',
        'hombre' => 'male',
        'mujer' => 'female',
        'masculino' => 'male',
        'femenino' => 'female',
    ];
    return $map[$g] ?? $g;
}

/**
 * Normalize status values from CSV.
 *
 * @param mixed $raw
 */
function dcmt_patient_import_normalize_status($raw): string
{
    $s = dcmt_patient_import_normalize_csv_header($raw);
    $map = [
        'active' => 'active',
        'inactive' => 'inactive',
        'activo' => 'active',
        'activa' => 'active',
        'inactivo' => 'inactive',
        'inactiva' => 'inactive',
    ];
    return $map[$s] ?? $s;
}
