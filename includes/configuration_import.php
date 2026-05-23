<?php
/**
 * Clinic configuration import / template helpers
 * Dental Clinic Management System
 */

require_once __DIR__ . '/lib/SimpleXLSX.php';
require_once __DIR__ . '/lib/SimpleXLSXGen.php';
use Shuchkin\SimpleXLSX;
use Shuchkin\SimpleXLSXGen;

/**
 * Translated string from configuration_import language file.
 */
function dcmt_configuration_import_t(string $key): string
{
    return trans('configuration_import', $key);
}

/**
 * Localized worksheet tab name.
 */
function dcmt_configuration_import_sheet_name(string $sheet_key): string
{
    return dcmt_configuration_import_t($sheet_key);
}

/**
 * Localized column header labels for template sheets.
 *
 * @param string[] $field_keys e.g. name, status, base_price
 */
function dcmt_configuration_import_col_headers(array $field_keys): array
{
    return array_map(
        static fn(string $field) => dcmt_configuration_import_t('col_' . $field),
        $field_keys
    );
}

/**
 * Prepend a translated title row (row 1) above headers/data.
 */
function dcmt_configuration_import_prepend_sheet_title(string $sheet_key, array $rows): array
{
    if ($rows === []) {
        return [[dcmt_configuration_import_sheet_name($sheet_key)]];
    }

    $width = 0;
    foreach ($rows as $row) {
        $width = max($width, count($row));
    }

    return array_merge(
        [array_pad([dcmt_configuration_import_sheet_name($sheet_key)], $width, '')],
        $rows
    );
}

/**
 * Build multi-sheet onboarding template and send as download.
 */
function dcmt_configuration_import_download_template(): void
{
    $xlsx = SimpleXLSXGen::fromArray(
        dcmt_configuration_import_instructions_sheet(),
        dcmt_configuration_import_sheet_name('sheet_instructions')
    );

    $data_sheets = [
        ['key' => 'specializations', 'rows' => dcmt_configuration_import_specializations_sheet()],
        ['key' => 'services', 'rows' => dcmt_configuration_import_services_sheet()],
        ['key' => 'users', 'rows' => dcmt_configuration_import_users_sheet()],
        ['key' => 'income_payment_methods', 'rows' => dcmt_configuration_import_income_payment_methods_sheet()],
        ['key' => 'income_payment_status', 'rows' => dcmt_configuration_import_income_payment_status_sheet()],
        ['key' => 'expense_categories', 'rows' => dcmt_configuration_import_expense_categories_sheet()],
        ['key' => 'expense_payment_methods', 'rows' => dcmt_configuration_import_expense_payment_methods_sheet()],
        ['key' => 'inventory_categories', 'rows' => dcmt_configuration_import_inventory_categories_sheet()],
    ];

    foreach ($data_sheets as $sheet) {
        $xlsx->addSheet($sheet['rows'], dcmt_configuration_import_sheet_name($sheet['key']));
    }

    $filename = 'clinic_configuration_template_' . date('Y-m-d') . '.xlsx';
    $xlsx->downloadAs($filename);
    exit;
}

function dcmt_configuration_import_instructions_sheet(): array
{
    $min_password = defined('DCMT_PASSWORD_MIN_LENGTH') ? (int) DCMT_PASSWORD_MIN_LENGTH : 8;

    return [
        [dcmt_configuration_import_t('template_title')],
        [''],
        [dcmt_configuration_import_t('template_intro')],
        [''],
        [dcmt_configuration_import_t('col_sheet'), dcmt_configuration_import_t('col_description'), dcmt_configuration_import_t('col_required_columns')],
        [dcmt_configuration_import_sheet_name('specializations'), dcmt_configuration_import_t('desc_specializations'), dcmt_configuration_import_t('req_specializations')],
        [dcmt_configuration_import_sheet_name('services'), dcmt_configuration_import_t('desc_services'), dcmt_configuration_import_t('req_services')],
        [dcmt_configuration_import_sheet_name('users'), dcmt_configuration_import_t('desc_users'), dcmt_configuration_import_t('req_users')],
        [dcmt_configuration_import_sheet_name('income_payment_methods'), dcmt_configuration_import_t('desc_income_payment_methods'), dcmt_configuration_import_t('req_income_payment_methods')],
        [dcmt_configuration_import_sheet_name('income_payment_status'), dcmt_configuration_import_t('desc_income_payment_status'), dcmt_configuration_import_t('req_income_payment_status')],
        [dcmt_configuration_import_sheet_name('expense_categories'), dcmt_configuration_import_t('desc_expense_categories'), dcmt_configuration_import_t('req_expense_categories')],
        [dcmt_configuration_import_sheet_name('expense_payment_methods'), dcmt_configuration_import_t('desc_expense_payment_methods'), dcmt_configuration_import_t('req_expense_payment_methods')],
        [dcmt_configuration_import_sheet_name('inventory_categories'), dcmt_configuration_import_t('desc_inventory_categories'), dcmt_configuration_import_t('req_inventory_categories')],
        [''],
        [dcmt_configuration_import_t('notes_heading')],
        [dcmt_configuration_import_t('note_skip_duplicates')],
        [dcmt_configuration_import_t('note_import_order')],
        [dcmt_configuration_import_t('note_user_roles')],
        [sprintf(dcmt_configuration_import_t('note_password_min'), $min_password)],
    ];
}

function dcmt_configuration_import_specializations_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('specializations', [
        dcmt_configuration_import_col_headers(['name', 'description', 'status']),
        [dcmt_configuration_import_t('sample_spec_general'), dcmt_configuration_import_t('sample_spec_general_desc'), 'active'],
        [dcmt_configuration_import_t('sample_spec_ortho'), dcmt_configuration_import_t('sample_spec_ortho_desc'), 'active'],
        [dcmt_configuration_import_t('sample_spec_endo'), dcmt_configuration_import_t('sample_spec_endo_desc'), 'active'],
    ]);
}

function dcmt_configuration_import_services_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('services', [
        dcmt_configuration_import_col_headers(['name', 'description', 'base_price', 'status']),
        [dcmt_configuration_import_t('sample_svc_cleaning'), dcmt_configuration_import_t('sample_svc_cleaning_desc'), '500', 'active'],
        [dcmt_configuration_import_t('sample_svc_root_canal'), dcmt_configuration_import_t('sample_svc_root_canal_desc'), '2000', 'active'],
        [dcmt_configuration_import_t('sample_svc_filling'), dcmt_configuration_import_t('sample_svc_filling_desc'), '600', 'active'],
        [dcmt_configuration_import_t('sample_svc_extraction'), dcmt_configuration_import_t('sample_svc_extraction_desc'), '800', 'active'],
    ]);
}

function dcmt_configuration_import_users_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('users', [
        dcmt_configuration_import_col_headers(['username', 'email', 'password', 'full_name', 'role', 'status', 'phone', 'address', 'notes', 'qualification', 'specialization_name', 'color_code']),
        ['dr.smith', 'dr.smith@clinic.com', 'ChangeMe123!', dcmt_configuration_import_t('sample_user_doctor_name'), 'doctor', 'active', '+5215512345678', '', '', 'DDS', dcmt_configuration_import_t('sample_spec_general'), '#0D6EFD'],
        ['reception', 'reception@clinic.com', 'ChangeMe123!', dcmt_configuration_import_t('sample_user_staff_name'), 'staff', 'active', '', '', '', '', '', ''],
    ]);
}

function dcmt_configuration_import_income_payment_methods_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('income_payment_methods', [
        dcmt_configuration_import_col_headers(['name', 'description', 'status']),
        [dcmt_configuration_import_t('sample_pay_cash'), dcmt_configuration_import_t('sample_pay_cash_desc'), 'active'],
        [dcmt_configuration_import_t('sample_pay_card'), dcmt_configuration_import_t('sample_pay_card_desc'), 'active'],
        [dcmt_configuration_import_t('sample_pay_transfer'), dcmt_configuration_import_t('sample_pay_transfer_desc'), 'active'],
    ]);
}

function dcmt_configuration_import_income_payment_status_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('income_payment_status', [
        dcmt_configuration_import_col_headers(['name', 'description', 'status']),
        [dcmt_configuration_import_t('sample_status_paid'), dcmt_configuration_import_t('sample_status_paid_desc'), 'active'],
        [dcmt_configuration_import_t('sample_status_pending'), dcmt_configuration_import_t('sample_status_pending_desc'), 'active'],
        [dcmt_configuration_import_t('sample_status_partial'), dcmt_configuration_import_t('sample_status_partial_desc'), 'active'],
    ]);
}

function dcmt_configuration_import_expense_categories_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('expense_categories', [
        dcmt_configuration_import_col_headers(['name', 'description', 'status', 'parent_category_name']),
        [dcmt_configuration_import_t('sample_exp_rent'), dcmt_configuration_import_t('sample_exp_rent_desc'), 'active', ''],
        [dcmt_configuration_import_t('sample_exp_supplies'), dcmt_configuration_import_t('sample_exp_supplies_desc'), 'active', ''],
        [dcmt_configuration_import_t('sample_exp_utilities'), dcmt_configuration_import_t('sample_exp_utilities_desc'), 'active', ''],
    ]);
}

function dcmt_configuration_import_expense_payment_methods_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('expense_payment_methods', [
        dcmt_configuration_import_col_headers(['name', 'description', 'status']),
        [dcmt_configuration_import_t('sample_exp_pay_cash'), dcmt_configuration_import_t('sample_exp_pay_cash_desc'), 'active'],
        [dcmt_configuration_import_t('sample_exp_pay_card'), dcmt_configuration_import_t('sample_exp_pay_card_desc'), 'active'],
    ]);
}

function dcmt_configuration_import_inventory_categories_sheet(): array
{
    return dcmt_configuration_import_prepend_sheet_title('inventory_categories', [
        dcmt_configuration_import_col_headers(['name', 'description', 'status', 'product_type']),
        [dcmt_configuration_import_t('sample_inv_oral'), dcmt_configuration_import_t('sample_inv_oral_desc'), 'active', 'for_sale'],
        [dcmt_configuration_import_t('sample_inv_medical'), dcmt_configuration_import_t('sample_inv_medical_desc'), 'active', 'for_use'],
    ]);
}

/**
 * Process uploaded configuration workbook.
 *
 * @return array{errors: string[], success: string[], imported_count: int, skipped_count: int, by_sheet: array}
 */
function dcmt_process_configuration_import(string $file_path): array
{
    global $dcmt_pdo;

    $errors = [];
    $success = [];
    $imported_count = 0;
    $skipped_count = 0;
    $by_sheet = [];

    if (!class_exists('ZipArchive')) {
        $errors[] = 'ZipArchive PHP extension is required to read Excel files.';
        return compact('errors', 'success', 'imported_count', 'skipped_count', 'by_sheet');
    }

    $xlsx = SimpleXLSX::parse($file_path);
    if (!$xlsx) {
        $errors[] = trans('configuration_import', 'parse_error') . ' ' . SimpleXLSX::parseError();
        return compact('errors', 'success', 'imported_count', 'skipped_count', 'by_sheet');
    }

    $sheet_map = dcmt_configuration_import_sheet_index_map($xlsx);
    $created_by = dcmt_get_current_user()['dcmt_username'] ?? 'system';
    $max_per_sheet = defined('DCMT_MAX_IMPORT_RECORDS') ? (int) DCMT_MAX_IMPORT_RECORDS : 500;

    $processors = [
        'specializations' => 'dcmt_config_import_specializations',
        'services' => 'dcmt_config_import_services',
        'users' => 'dcmt_config_import_users',
        'income_payment_methods' => 'dcmt_config_import_income_payment_methods',
        'income_payment_status' => 'dcmt_config_import_income_payment_status',
        'expense_categories' => 'dcmt_config_import_expense_categories',
        'expense_payment_methods' => 'dcmt_config_import_expense_payment_methods',
        'inventory_categories' => 'dcmt_config_import_inventory_categories',
    ];

    try {
        $dcmt_pdo->beginTransaction();

        foreach ($processors as $key => $callback) {
            if (!isset($sheet_map[$key])) {
                continue;
            }
            $rows = $xlsx->rows($sheet_map[$key]);
            $result = $callback($dcmt_pdo, $rows, $created_by, $max_per_sheet);
            $by_sheet[$key] = $result;
            $imported_count += $result['imported'];
            $skipped_count += $result['skipped'];
            $errors = array_merge($errors, $result['errors']);
            $success = array_merge($success, $result['success']);
        }

        $dcmt_pdo->commit();
        dcmt_log_activity('Configuration import completed', 'configuration_import');
    } catch (Throwable $e) {
        if ($dcmt_pdo->inTransaction()) {
            $dcmt_pdo->rollBack();
        }
        $errors[] = $e->getMessage();
        error_log('Configuration import failed: ' . $e->getMessage());
    }

    return compact('errors', 'success', 'imported_count', 'skipped_count', 'by_sheet');
}

/**
 * Normalize sheet tab names for matching (lowercase, no accents).
 */
function dcmt_configuration_import_normalize_sheet_name(string $name): string
{
    $name = strtolower(trim($name));
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $name);
        if ($ascii !== false) {
            $name = strtolower(preg_replace('/[^a-z0-9 _-]+/', '', $ascii));
        }
    }
    return preg_replace('/\s+/', ' ', $name);
}

/**
 * Map logical sheet keys to workbook indices (case-insensitive).
 */
function dcmt_configuration_import_sheet_index_map(SimpleXLSX $xlsx): array
{
    $sheet_keys = [
        'specializations',
        'services',
        'users',
        'income_payment_methods',
        'income_payment_status',
        'expense_categories',
        'expense_payment_methods',
        'inventory_categories',
    ];

    $aliases = [];
    foreach ($sheet_keys as $key) {
        $aliases[$key] = [
            $key,
            str_replace('_', ' ', $key),
            dcmt_configuration_import_sheet_name($key),
        ];
        if ($key === 'specializations') {
            $aliases[$key][] = 'especializaciones';
        } elseif ($key === 'services') {
            $aliases[$key][] = 'servicios';
        } elseif ($key === 'users') {
            $aliases[$key][] = 'usuarios';
        }
    }

    $map = [];
    $names = $xlsx->sheetNames();
    $skip_sheets = [
        dcmt_configuration_import_normalize_sheet_name('instructions'),
        dcmt_configuration_import_normalize_sheet_name('readme'),
        dcmt_configuration_import_normalize_sheet_name('instrucciones'),
        dcmt_configuration_import_normalize_sheet_name(dcmt_configuration_import_sheet_name('sheet_instructions')),
    ];
    foreach ($names as $index => $name) {
        $normalized = dcmt_configuration_import_normalize_sheet_name((string) $name);
        if (in_array($normalized, $skip_sheets, true)) {
            continue;
        }
        foreach ($aliases as $key => $list) {
            foreach ($list as $alias) {
                if ($normalized === dcmt_configuration_import_normalize_sheet_name($alias)) {
                    $map[$key] = $index;
                    break 2;
                }
            }
        }
    }
    return $map;
}

/**
 * @return array<string, mixed>
 */
function dcmt_config_import_rows_to_assoc(array $rows, string $sheet_label): array
{
    $errors = [];
    $records = [];

    if (count($rows) < 2) {
        return ['records' => [], 'errors' => $errors];
    }

    $row_num = 0;
    while (count($rows) > 0 && !dcmt_config_import_row_is_header($rows[0])) {
        array_shift($rows);
        $row_num++;
    }

    if (count($rows) < 1) {
        return ['records' => [], 'errors' => $errors];
    }

    $header_row = array_shift($rows);
    $row_num++;
    $headers = [];
    foreach ($header_row as $i => $cell) {
        $key = dcmt_config_import_normalize_header((string) $cell);
        if ($key !== '') {
            $headers[$i] = $key;
        }
    }

    foreach ($rows as $row) {
        $row_num++;
        if (!dcmt_config_import_row_has_data($row)) {
            continue;
        }
        $assoc = [];
        foreach ($headers as $i => $key) {
            $assoc[$key] = isset($row[$i]) ? trim((string) $row[$i]) : '';
        }
        $assoc['_row'] = $row_num;
        $records[] = $assoc;
    }

    return ['records' => $records, 'errors' => $errors];
}

function dcmt_config_import_row_is_header(array $row): bool
{
    $first = dcmt_config_import_normalize_header((string) ($row[0] ?? ''));
    return in_array($first, ['name', 'username'], true);
}

function dcmt_config_import_normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    if (function_exists('iconv')) {
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $header);
        if ($ascii !== false) {
            $header = strtolower($ascii);
        }
    }
    $header = str_replace([' ', '-'], '_', $header);
    $header = preg_replace('/[^a-z0-9_]+/', '', $header);
    $header = preg_replace('/_+/', '_', trim($header, '_'));

    $aliases = [
        'nombre' => 'name',
        'estado' => 'status',
        'precio_base' => 'base_price',
        'usuario' => 'username',
        'correo' => 'email',
        'contrasena' => 'password',
        'nombre_completo' => 'full_name',
        'rol' => 'role',
        'telefono' => 'phone',
        'direccion' => 'address',
        'notas' => 'notes',
        'calificacion' => 'qualification',
        'nombre_especializacion' => 'specialization_name',
        'codigo_color' => 'color_code',
        'categoria_padre' => 'parent_category_name',
        'nombre_categoria_padre' => 'parent_category_name',
        'tipo_producto' => 'product_type',
    ];

    return $aliases[$header] ?? $header;
}

function dcmt_config_import_row_has_data(array $row): bool
{
    foreach ($row as $cell) {
        if (trim((string) $cell) !== '') {
            return true;
        }
    }
    return false;
}

function dcmt_config_import_check_limit(array $records, string $sheet_label, int $max): ?string
{
    if (count($records) > $max) {
        return sprintf(trans('configuration_import', 'max_records_exceeded'), $sheet_label, count($records), $max);
    }
    return null;
}

function dcmt_config_import_result_template(): array
{
    return ['imported' => 0, 'skipped' => 0, 'errors' => [], 'success' => []];
}

function dcmt_config_import_specializations(PDO $pdo, array $rows, string $created_by, int $max): array
{
    $result = dcmt_config_import_result_template();
    $parsed = dcmt_config_import_rows_to_assoc($rows, 'Specializations');
    if ($limit_err = dcmt_config_import_check_limit($parsed['records'], 'Specializations', $max)) {
        $result['errors'][] = $limit_err;
        return $result;
    }

    foreach ($parsed['records'] as $row) {
        $row_num = $row['_row'];
        unset($row['_row']);
        $name = $row['name'] ?? '';
        $status = strtolower($row['status'] ?? 'active') ?: 'active';
        $description = $row['description'] ?? '';

        if ($name === '') {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Specializations', 'name is required');
            $result['skipped']++;
            continue;
        }
        if (!in_array($status, ['active', 'inactive'], true)) {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Specializations', 'invalid status');
            $result['skipped']++;
            continue;
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM dcmt_doctor_specializations WHERE dcmt_name = ?');
        $check->execute([$name]);
        if ($check->fetchColumn() > 0) {
            $result['skipped']++;
            $result['success'][] = sprintf(trans('configuration_import', 'skipped_duplicate'), $name);
            continue;
        }

        $stmt = $pdo->prepare('INSERT INTO dcmt_doctor_specializations (dcmt_name, dcmt_description, dcmt_status, dcmt_created_by) VALUES (?, ?, ?, ?)');
        $stmt->execute([$name, $description, $status, $created_by]);
        $result['imported']++;
        $result['success'][] = sprintf(trans('configuration_import', 'imported_item'), $name);
    }
    return $result;
}

function dcmt_config_import_services(PDO $pdo, array $rows, string $created_by, int $max): array
{
    $result = dcmt_config_import_result_template();
    $parsed = dcmt_config_import_rows_to_assoc($rows, 'Services');
    if ($limit_err = dcmt_config_import_check_limit($parsed['records'], 'Services', $max)) {
        $result['errors'][] = $limit_err;
        return $result;
    }

    foreach ($parsed['records'] as $row) {
        $row_num = $row['_row'];
        unset($row['_row']);
        $name = $row['name'] ?? '';
        $base_price = $row['base_price'] ?? '';
        $status = strtolower($row['status'] ?? 'active') ?: 'active';
        $description = $row['description'] ?? '';

        if ($name === '' || $base_price === '') {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Services', 'name and base_price are required');
            $result['skipped']++;
            continue;
        }
        if (!is_numeric($base_price) || (float) $base_price < 0) {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Services', 'invalid base_price');
            $result['skipped']++;
            continue;
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM dcmt_services WHERE dcmt_name = ?');
        $check->execute([$name]);
        if ($check->fetchColumn() > 0) {
            $result['skipped']++;
            $result['success'][] = sprintf(trans('configuration_import', 'skipped_duplicate'), $name);
            continue;
        }

        $stmt = $pdo->prepare('INSERT INTO dcmt_services (dcmt_name, dcmt_description, dcmt_base_price, dcmt_status, dcmt_created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $description, (float) $base_price, $status, $created_by]);
        $result['imported']++;
        $result['success'][] = sprintf(trans('configuration_import', 'imported_item'), $name);
    }
    return $result;
}

function dcmt_config_import_users(PDO $pdo, array $rows, string $created_by, int $max): array
{
    $result = dcmt_config_import_result_template();
    $parsed = dcmt_config_import_rows_to_assoc($rows, 'Users');
    if ($limit_err = dcmt_config_import_check_limit($parsed['records'], 'Users', $max)) {
        $result['errors'][] = $limit_err;
        return $result;
    }

    $has_qualification = (bool) $pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_qualification'")->fetch();
    $has_specialization = (bool) $pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_specialization_id'")->fetch();
    $has_color = (bool) $pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_color_code'")->fetch();
    $min_password = defined('DCMT_PASSWORD_MIN_LENGTH') ? (int) DCMT_PASSWORD_MIN_LENGTH : 8;

    $spec_map = [];
    $spec_stmt = $pdo->query('SELECT dcmt_id, dcmt_name FROM dcmt_doctor_specializations');
    foreach ($spec_stmt->fetchAll(PDO::FETCH_ASSOC) as $s) {
        $spec_map[strtolower($s['dcmt_name'])] = (int) $s['dcmt_id'];
    }

    foreach ($parsed['records'] as $row) {
        $row_num = $row['_row'];
        unset($row['_row']);

        $username = $row['username'] ?? '';
        $email = $row['email'] ?? '';
        $password = $row['password'] ?? '';
        $full_name = $row['full_name'] ?? '';
        $role = strtolower($row['role'] ?? 'staff');
        $status = strtolower($row['status'] ?? 'active') ?: 'active';

        if ($username === '' || $email === '' || $password === '' || $full_name === '' || $role === '') {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Users', 'username, email, password, full_name, and role are required');
            $result['skipped']++;
            continue;
        }
        if (!in_array($role, ['admin', 'staff', 'doctor', 'assistant'], true)) {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Users', 'invalid role');
            $result['skipped']++;
            continue;
        }
        if (strlen($password) < $min_password) {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Users', 'password too short');
            $result['skipped']++;
            continue;
        }
        if (!dcmt_validate_email($email)) {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Users', 'invalid email');
            $result['skipped']++;
            continue;
        }

        $check_u = $pdo->prepare('SELECT COUNT(*) FROM dcmt_users WHERE dcmt_username = ?');
        $check_u->execute([$username]);
        if ($check_u->fetchColumn() > 0) {
            $result['skipped']++;
            $result['success'][] = sprintf(trans('configuration_import', 'skipped_duplicate'), $username);
            continue;
        }
        $check_e = $pdo->prepare('SELECT COUNT(*) FROM dcmt_users WHERE dcmt_email = ?');
        $check_e->execute([$email]);
        if ($check_e->fetchColumn() > 0) {
            $result['skipped']++;
            $result['success'][] = sprintf(trans('configuration_import', 'skipped_duplicate'), $email);
            continue;
        }

        $specialization_id = null;
        $spec_name = trim($row['specialization_name'] ?? '');
        if ($spec_name !== '' && isset($spec_map[strtolower($spec_name)])) {
            $specialization_id = $spec_map[strtolower($spec_name)];
        }

        $hashed = password_hash($password, PASSWORD_DEFAULT);
        $phone = $row['phone'] ?? '';
        $address = $row['address'] ?? '';
        $notes = $row['notes'] ?? '';
        $qualification = $row['qualification'] ?? '';
        $color_code = strtoupper(trim($row['color_code'] ?? '#0D6EFD'));

        if ($has_qualification && $has_specialization && $has_color) {
            $sql = 'INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_qualification, dcmt_specialization_id, dcmt_color_code, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
            $params = [$username, $email, $hashed, $full_name, $role, $status, $phone, $address, $notes, $qualification, $specialization_id, $color_code, $created_by];
        } elseif ($has_qualification && $has_specialization) {
            $sql = 'INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_qualification, dcmt_specialization_id, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
            $params = [$username, $email, $hashed, $full_name, $role, $status, $phone, $address, $notes, $qualification, $specialization_id, $created_by];
        } else {
            $sql = 'INSERT INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_status, dcmt_phone, dcmt_address, dcmt_notes, dcmt_created_by, dcmt_created_at) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, NOW())';
            $params = [$username, $email, $hashed, $full_name, $role, $status, $phone, $address, $notes, $created_by];
        }

        $stmt = $pdo->prepare($sql);
        $stmt->execute($params);
        $result['imported']++;
        $result['success'][] = sprintf(trans('configuration_import', 'imported_item'), $username);
    }
    return $result;
}

function dcmt_config_import_simple_lookup_table(PDO $pdo, string $table, array $rows, string $sheet_label, string $created_by, int $max): array
{
    $result = dcmt_config_import_result_template();
    $parsed = dcmt_config_import_rows_to_assoc($rows, $sheet_label);
    if ($limit_err = dcmt_config_import_check_limit($parsed['records'], $sheet_label, $max)) {
        $result['errors'][] = $limit_err;
        return $result;
    }

    foreach ($parsed['records'] as $row) {
        $row_num = $row['_row'];
        unset($row['_row']);
        $name = $row['name'] ?? '';
        $status = strtolower($row['status'] ?? 'active') ?: 'active';
        $description = $row['description'] ?? '';

        if ($name === '') {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, $sheet_label, 'name is required');
            $result['skipped']++;
            continue;
        }

        $check = $pdo->prepare("SELECT COUNT(*) FROM {$table} WHERE dcmt_name = ?");
        $check->execute([$name]);
        if ($check->fetchColumn() > 0) {
            $result['skipped']++;
            $result['success'][] = sprintf(trans('configuration_import', 'skipped_duplicate'), $name);
            continue;
        }

        $stmt = $pdo->prepare("INSERT INTO {$table} (dcmt_name, dcmt_description, dcmt_status, dcmt_created_by) VALUES (?, ?, ?, ?)");
        $stmt->execute([$name, $description, $status, $created_by]);

        $result['imported']++;
        $result['success'][] = sprintf(trans('configuration_import', 'imported_item'), $name);
    }
    return $result;
}

function dcmt_config_import_income_payment_methods(PDO $pdo, array $rows, string $created_by, int $max): array
{
    return dcmt_config_import_simple_lookup_table($pdo, 'dcmt_income_payment_methods', $rows, 'Income Payment Methods', $created_by, $max);
}

function dcmt_config_import_income_payment_status(PDO $pdo, array $rows, string $created_by, int $max): array
{
    return dcmt_config_import_simple_lookup_table($pdo, 'dcmt_income_payment_status', $rows, 'Income Payment Status', $created_by, $max);
}

function dcmt_config_import_expense_payment_methods(PDO $pdo, array $rows, string $created_by, int $max): array
{
    return dcmt_config_import_simple_lookup_table($pdo, 'dcmt_expense_payment_methods', $rows, 'Expense Payment Methods', $created_by, $max);
}

function dcmt_config_import_expense_categories(PDO $pdo, array $rows, string $created_by, int $max): array
{
    $result = dcmt_config_import_result_template();
    $parsed = dcmt_config_import_rows_to_assoc($rows, 'Expense Categories');
    if ($limit_err = dcmt_config_import_check_limit($parsed['records'], 'Expense Categories', $max)) {
        $result['errors'][] = $limit_err;
        return $result;
    }

    $has_parent = (bool) $pdo->query("SHOW COLUMNS FROM dcmt_expense_categories LIKE 'dcmt_parent_category_id'")->fetch();
    $parent_map = [];
    if ($has_parent) {
        $pstmt = $pdo->query('SELECT dcmt_id, dcmt_name FROM dcmt_expense_categories');
        foreach ($pstmt->fetchAll(PDO::FETCH_ASSOC) as $p) {
            $parent_map[strtolower($p['dcmt_name'])] = (int) $p['dcmt_id'];
        }
    }

    foreach ($parsed['records'] as $row) {
        $row_num = $row['_row'];
        unset($row['_row']);
        $name = $row['name'] ?? '';
        $status = strtolower($row['status'] ?? 'active') ?: 'active';
        $description = $row['description'] ?? '';
        $parent_name = trim($row['parent_category_name'] ?? '');

        if ($name === '') {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Expense Categories', 'name is required');
            $result['skipped']++;
            continue;
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM dcmt_expense_categories WHERE dcmt_name = ?');
        $check->execute([$name]);
        if ($check->fetchColumn() > 0) {
            $result['skipped']++;
            $result['success'][] = sprintf(trans('configuration_import', 'skipped_duplicate'), $name);
            continue;
        }

        $parent_id = null;
        if ($has_parent && $parent_name !== '' && isset($parent_map[strtolower($parent_name)])) {
            $parent_id = $parent_map[strtolower($parent_name)];
        }

        if ($has_parent) {
            $stmt = $pdo->prepare('INSERT INTO dcmt_expense_categories (dcmt_name, dcmt_description, dcmt_status, dcmt_parent_category_id, dcmt_created_by) VALUES (?, ?, ?, ?, ?)');
            $stmt->execute([$name, $description, $status, $parent_id, $created_by]);
        } else {
            $stmt = $pdo->prepare('INSERT INTO dcmt_expense_categories (dcmt_name, dcmt_description, dcmt_status, dcmt_created_by) VALUES (?, ?, ?, ?)');
            $stmt->execute([$name, $description, $status, $created_by]);
        }

        $parent_map[strtolower($name)] = (int) $pdo->lastInsertId();
        $result['imported']++;
        $result['success'][] = sprintf(trans('configuration_import', 'imported_item'), $name);
    }
    return $result;
}

function dcmt_config_import_inventory_categories(PDO $pdo, array $rows, string $created_by, int $max): array
{
    $result = dcmt_config_import_result_template();
    $parsed = dcmt_config_import_rows_to_assoc($rows, 'Inventory Categories');
    if ($limit_err = dcmt_config_import_check_limit($parsed['records'], 'Inventory Categories', $max)) {
        $result['errors'][] = $limit_err;
        return $result;
    }

    foreach ($parsed['records'] as $row) {
        $row_num = $row['_row'];
        unset($row['_row']);
        $name = $row['name'] ?? '';
        $status = strtolower($row['status'] ?? 'active') ?: 'active';
        $description = $row['description'] ?? '';
        $product_type = strtolower($row['product_type'] ?? 'for_sale') ?: 'for_sale';

        if ($name === '') {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Inventory Categories', 'name is required');
            $result['skipped']++;
            continue;
        }
        if (!in_array($product_type, ['for_sale', 'for_use'], true)) {
            $result['errors'][] = sprintf(trans('configuration_import', 'row_error'), $row_num, 'Inventory Categories', 'product_type must be for_sale or for_use');
            $result['skipped']++;
            continue;
        }

        $check = $pdo->prepare('SELECT COUNT(*) FROM dcmt_inventory_categories WHERE dcmt_name = ?');
        $check->execute([$name]);
        if ($check->fetchColumn() > 0) {
            $result['skipped']++;
            $result['success'][] = sprintf(trans('configuration_import', 'skipped_duplicate'), $name);
            continue;
        }

        $stmt = $pdo->prepare('INSERT INTO dcmt_inventory_categories (dcmt_name, dcmt_description, dcmt_status, dcmt_product_type, dcmt_created_by) VALUES (?, ?, ?, ?, ?)');
        $stmt->execute([$name, $description, $status, $product_type, $created_by]);
        $result['imported']++;
        $result['success'][] = sprintf(trans('configuration_import', 'imported_item'), $name);
    }
    return $result;
}
