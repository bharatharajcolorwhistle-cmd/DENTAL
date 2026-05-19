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
 * Build multi-sheet onboarding template and send as download.
 */
function dcmt_configuration_import_download_template(): void
{
    $xlsx = SimpleXLSXGen::fromArray(dcmt_configuration_import_instructions_sheet(), 'Instructions');

    $xlsx->addSheet(dcmt_configuration_import_specializations_sheet(), 'Specializations');
    $xlsx->addSheet(dcmt_configuration_import_services_sheet(), 'Services');
    $xlsx->addSheet(dcmt_configuration_import_users_sheet(), 'Users');
    $xlsx->addSheet(dcmt_configuration_import_simple_sheet(
        ['name', 'description', 'status'],
        [
            ['Cash', 'Cash payments', 'active'],
            ['Credit Card', 'Card payments', 'active'],
            ['Bank Transfer', 'Wire transfer', 'active'],
        ]
    ), 'Income Payment Methods');
    $xlsx->addSheet(dcmt_configuration_import_simple_sheet(
        ['name', 'description', 'status'],
        [
            ['Paid', 'Payment completed', 'active'],
            ['Pending', 'Awaiting payment', 'active'],
            ['Partial', 'Partially paid', 'active'],
        ]
    ), 'Income Payment Status');
    $xlsx->addSheet(dcmt_configuration_import_simple_sheet(
        ['name', 'description', 'status', 'parent_category_name'],
        [
            ['Rent', 'Office rent', 'active', ''],
            ['Supplies', 'Dental supplies', 'active', ''],
            ['Utilities', 'Electricity and water', 'active', ''],
        ]
    ), 'Expense Categories');
    $xlsx->addSheet(dcmt_configuration_import_simple_sheet(
        ['name', 'description', 'status'],
        [
            ['Cash', 'Cash expense payment', 'active'],
            ['Card', 'Card expense payment', 'active'],
        ]
    ), 'Expense Payment Methods');
    $xlsx->addSheet(dcmt_configuration_import_simple_sheet(
        ['name', 'description', 'status', 'product_type'],
        [
            ['Oral Care', 'Products for patient sale', 'active', 'for_sale'],
            ['Medical Supplies', 'Clinic use only', 'active', 'for_use'],
        ]
    ), 'Inventory Categories');

    $filename = 'clinic_configuration_template_' . date('Y-m-d') . '.xlsx';
    $xlsx->downloadAs($filename);
    exit;
}

function dcmt_configuration_import_instructions_sheet(): array
{
    return [
        ['Clinic Configuration Import Template'],
        [''],
        ['Fill each sheet below, then upload this file via Configuration → Import Configuration.'],
        [''],
        ['Sheet', 'Description', 'Required columns'],
        ['Specializations', 'Doctor specializations', 'name, status'],
        ['Services', 'Clinic services and base prices', 'name, base_price, status'],
        ['Users', 'Staff, doctors, assistants, admins', 'username, email, password, full_name, role, status'],
        ['Income Payment Methods', 'Income payment methods', 'name, status'],
        ['Income Payment Status', 'Income payment statuses', 'name, status'],
        ['Expense Categories', 'Expense categories', 'name, status'],
        ['Expense Payment Methods', 'Expense payment methods', 'name, status'],
        ['Inventory Categories', 'Inventory categories', 'name, status, product_type (for_sale|for_use)'],
        [''],
        ['Notes:'],
        ['- Rows with existing names/usernames/emails are skipped.'],
        ['- Import specializations before users (specialization_name on Users sheet).'],
        ['- User roles: admin, doctor, staff, assistant. Status: active or inactive.'],
        ['- Password minimum length: ' . (defined('DCMT_PASSWORD_MIN_LENGTH') ? DCMT_PASSWORD_MIN_LENGTH : 8) . ' characters.'],
    ];
}

function dcmt_configuration_import_specializations_sheet(): array
{
    return [
        ['name', 'description', 'status'],
        ['General Dentistry', 'General dental care', 'active'],
        ['Orthodontics', 'Braces and alignment', 'active'],
        ['Endodontics', 'Root canal specialist', 'active'],
    ];
}

function dcmt_configuration_import_services_sheet(): array
{
    return [
        ['name', 'description', 'base_price', 'status'],
        ['Teeth Cleaning', 'Professional cleaning', '500', 'active'],
        ['Root Canal Treatment', 'Endodontic treatment', '2000', 'active'],
        ['Dental Filling', 'Cavity restoration', '600', 'active'],
        ['Tooth Extraction', 'Simple extraction', '800', 'active'],
    ];
}

function dcmt_configuration_import_users_sheet(): array
{
    return [
        ['username', 'email', 'password', 'full_name', 'role', 'status', 'phone', 'address', 'notes', 'qualification', 'specialization_name', 'color_code'],
        ['dr.smith', 'dr.smith@clinic.com', 'ChangeMe123!', 'Dr. John Smith', 'doctor', 'active', '+5215512345678', '', '', 'DDS', 'General Dentistry', '#0D6EFD'],
        ['reception', 'reception@clinic.com', 'ChangeMe123!', 'Maria Garcia', 'staff', 'active', '', '', '', '', '', ''],
    ];
}

function dcmt_configuration_import_simple_sheet(array $headers, array $rows): array
{
    $data = [$headers];
    foreach ($rows as $row) {
        $data[] = $row;
    }
    return $data;
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
 * Map logical sheet keys to workbook indices (case-insensitive).
 */
function dcmt_configuration_import_sheet_index_map(SimpleXLSX $xlsx): array
{
    $aliases = [
        'specializations' => ['specializations', 'specialization', 'especializaciones'],
        'services' => ['services', 'service', 'servicios'],
        'users' => ['users', 'user', 'usuarios'],
        'income_payment_methods' => ['income payment methods', 'income_payment_methods', 'metodos de pago de ingresos'],
        'income_payment_status' => ['income payment status', 'income_payment_status', 'estados de pago de ingresos'],
        'expense_categories' => ['expense categories', 'expense_categories', 'categorias de gastos'],
        'expense_payment_methods' => ['expense payment methods', 'expense_payment_methods', 'metodos de pago de gastos'],
        'inventory_categories' => ['inventory categories', 'inventory_categories', 'categorias de inventario'],
    ];

    $map = [];
    $names = $xlsx->sheetNames();
    foreach ($names as $index => $name) {
        $normalized = strtolower(trim((string) $name));
        if ($normalized === 'instructions' || $normalized === 'readme' || $normalized === 'instrucciones') {
            continue;
        }
        foreach ($aliases as $key => $list) {
            foreach ($list as $alias) {
                if ($normalized === strtolower($alias)) {
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

    $header_row = array_shift($rows);
    $headers = [];
    foreach ($header_row as $i => $cell) {
        $key = dcmt_config_import_normalize_header((string) $cell);
        if ($key !== '') {
            $headers[$i] = $key;
        }
    }

    $row_num = 1;
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

function dcmt_config_import_normalize_header(string $header): string
{
    $header = strtolower(trim($header));
    $header = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $header);
    $header = str_replace([' ', '-'], '_', $header);
    return preg_replace('/_+/', '_', $header);
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
