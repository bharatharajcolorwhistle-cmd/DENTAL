<?php
/**
 * Import Patients Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check authentication
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';

// Generate CSRF token
$csrf_token = dcmt_generate_csrf_token();

// Handle file upload and processing
$import_errors = [];
$import_success = [];
$imported_count = 0;
$updated_income_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_patients'])) {
    // Verify CSRF token
    if (!dcmt_verify_csrf_token($_POST['csrf_token'])) {
        $import_errors[] = trans('common', 'invalid_token');
    } else {
        // Check if file was uploaded
        if (!isset($_FILES['csv_file']) || $_FILES['csv_file']['error'] !== UPLOAD_ERR_OK) {
            $import_errors[] = trans('patient', 'no_file_uploaded');
        } else {
            $file = $_FILES['csv_file'];

            // Validate file type
            $file_extension = strtolower(pathinfo($file['name'], PATHINFO_EXTENSION));
            if ($file_extension !== 'csv') {
                $import_errors[] = trans('patient', 'invalid_file_type');
            } else {
                // Process CSV file
                $result = processPatientImport($file['tmp_name']);
                $import_errors = $result['errors'];
                $import_success = $result['success'];
                $imported_count = $result['imported_count'];
                $updated_income_count = $result['updated_income_count'];
            }
        }
    }
}

/**
 * Process the uploaded CSV file and import patient records
 */
function processPatientImport($file_path)
{
    global $dcmt_pdo;

    $errors = [];
    $success = [];
    $imported_count = 0;
    $updated_income_count = 0;

    // Increase execution time for import operations
    set_time_limit(300);

    // Open CSV file
    if (($handle = fopen($file_path, 'r')) === FALSE) {
        $errors[] = trans('patient', 'cannot_read_file');
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'updated_income_count' => 0];
    }

    // Read header row
    $headers = fgetcsv($handle);
    if (!$headers) {
        $errors[] = trans('patient', 'empty_file');
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'updated_income_count' => 0];
    }

    // Clean headers (remove BOM and whitespace)
    $headers = array_map(function ($h) {
        $h = preg_replace('/[\x00-\x1F\x80-\xFF]/', '', $h);
        return trim(strtolower($h));
    }, $headers);

    $header_map = [
        'full name' => 'full_name',
        'patient name' => 'full_name',
        'name' => 'first_name',
        'surname' => 'last_name',
        'father last name' => 'fathers_last_name',
        'father lastname' => 'fathers_last_name',
        'fathers last name' => 'fathers_last_name',
        'fathers lastname' => 'fathers_last_name',
        "father's last name" => 'fathers_last_name',
        'mother last name' => 'mothers_last_name',
        'mother lastname' => 'mothers_last_name',
        'mothers last name' => 'mothers_last_name',
        'mothers lastname' => 'mothers_last_name',
        "mother's last name" => 'mothers_last_name',
        'apellido paterno' => 'fathers_last_name',
        'apellido materno' => 'mothers_last_name',
        'gender' => 'gender',
        'email' => 'email',
        'phone number' => 'phone',
        'phone' => 'phone',
        'telefono' => 'phone'
    ];

    $cols = [];
    foreach ($headers as $index => $header) {
        if (isset($header_map[$header])) {
            $cols[$header_map[$header]] = $index;
        }
    }

    if (!isset($cols['full_name'])) {
        $errors[] = trans('patient', 'missing_name_column');
        fclose($handle);
        return ['errors' => $errors, 'success' => $success, 'imported_count' => 0, 'updated_income_count' => 0];
    }

    $row_number = 1;
    $current_user = dcmt_get_current_user();
    $created_by = $current_user['dcmt_username'] ?? 'system';

    while (($data = fgetcsv($handle)) !== FALSE) {
        $row_number++;

        // Skip empty rows
        if (empty(array_filter($data)))
            continue;

        for ($i = 0; $i < count($data); $i++) {
            $v = $data[$i];
            if ($v !== null && $v !== '') {
                if (function_exists('mb_detect_encoding') && function_exists('mb_convert_encoding')) {
                    $enc = mb_detect_encoding($v, ['UTF-8', 'Windows-1252', 'ISO-8859-1'], true);
                    if ($enc && $enc !== 'UTF-8') {
                        $v = mb_convert_encoding($v, 'UTF-8', $enc);
                    } else {
                        if (!mb_check_encoding($v, 'UTF-8')) {
                            $v = mb_convert_encoding($v, 'UTF-8', 'Windows-1252');
                        }
                    }
                } elseif (function_exists('iconv')) {
                    $v_conv = @iconv('Windows-1252', 'UTF-8//TRANSLIT', $v);
                    if ($v_conv !== false) {
                        $v = $v_conv;
                    }
                }
                $data[$i] = $v;
            }
        }

        $full_name = trim($data[$cols['full_name']] ?? '');
        $first_name = isset($cols['first_name']) ? trim($data[$cols['first_name']] ?? '') : '';
        $last_name = isset($cols['last_name']) ? trim($data[$cols['last_name']] ?? '') : '';
        $father_ln_csv = isset($cols['fathers_last_name']) ? trim($data[$cols['fathers_last_name']] ?? '') : '';
        $mother_ln_csv = isset($cols['mothers_last_name']) ? trim($data[$cols['mothers_last_name']] ?? '') : '';
        $gender = isset($cols['gender']) ? strtolower(trim($data[$cols['gender']] ?? '')) : '';
        $email = isset($cols['email']) ? trim($data[$cols['email']] ?? '') : '';
        $phone = isset($cols['phone']) ? trim($data[$cols['phone']] ?? '') : '';

        if ($full_name === '') {
            $errors[] = sprintf("Row %d: Patient full name is empty.", $row_number);
            continue;
        }

        try {
            // First attempt: match by normalized full name (force consistent collation)
            $stmt = $dcmt_pdo->prepare("
                SELECT dcmt_id, dcmt_first_name, dcmt_fathers_last_name, dcmt_mothers_last_name, dcmt_gender, dcmt_phone, dcmt_email
                FROM dcmt_patients
                WHERE LOWER(TRIM(dcmt_patient_name)) COLLATE utf8mb4_unicode_ci
                      = LOWER(TRIM(CONVERT(? USING utf8mb4))) COLLATE utf8mb4_unicode_ci
            ");
            $stmt->execute([$full_name]);
            $existing_patient = $stmt->fetch();

            // Second attempt: if not found and phone provided, match by normalized phone
            if (!$existing_patient && !empty($phone)) {
                $normalized_phone = preg_replace('/\\D+/', '', $phone);
                if ($normalized_phone !== '') {
                    $stmt = $dcmt_pdo->prepare("
                        SELECT dcmt_id, dcmt_first_name, dcmt_fathers_last_name, dcmt_mothers_last_name, dcmt_gender, dcmt_phone, dcmt_email 
                        FROM dcmt_patients 
                        WHERE REPLACE(REPLACE(REPLACE(dcmt_phone, '+', ''), ' ', ''), '-', '') = ?
                        LIMIT 1
                    ");
                    $stmt->execute([$normalized_phone]);
                    $existing_patient = $stmt->fetch();
                }
            }

            // Third attempt: fuzzy match by name prefix and Levenshtein on ASCII-normalized strings
            if (!$existing_patient) {
                // Helper to normalize strings to ASCII a-z only, no spaces
                $normalize = function ($s) {
                    $s = trim($s);
                    if ($s === '') return '';
                    if (function_exists('mb_strtolower')) {
                        $s = mb_strtolower($s, 'UTF-8');
                    } else {
                        $s = strtolower($s);
                    }
                    if (function_exists('iconv')) {
                        $conv = @iconv('UTF-8', 'ASCII//TRANSLIT', $s);
                        if ($conv !== false) {
                            $s = $conv;
                        }
                    }
                    // remove non letters
                    $s = preg_replace('/[^a-z]/', '', $s);
                    return $s ?: '';
                };
                $tokens = preg_split('/\\s+/', $full_name);
                $prefix_tokens = array_slice($tokens, 0, min(2, count($tokens)));
                $name_prefix = trim(implode(' ', $prefix_tokens));
                if ($name_prefix !== '') {
                    $stmt = $dcmt_pdo->prepare("
                        SELECT dcmt_id, dcmt_patient_name 
                        FROM dcmt_patients 
                        WHERE LOWER(dcmt_patient_name) COLLATE utf8mb4_unicode_ci LIKE 
                              CONCAT(LOWER(CONVERT(? USING utf8mb4) COLLATE utf8mb4_unicode_ci), '%')
                        LIMIT 20
                    ");
                    $stmt->execute([$name_prefix]);
                    $candidates = $stmt->fetchAll();
                    $norm_import = $normalize($full_name);
                    $best = null;
                    $bestDist = PHP_INT_MAX;
                    foreach ($candidates as $cand) {
                        $norm_db = $normalize($cand['dcmt_patient_name']);
                        if ($norm_db === '') continue;
                        $dist = levenshtein($norm_import, $norm_db);
                        if ($dist < $bestDist) {
                            $bestDist = $dist;
                            $best = $cand;
                        }
                    }
                    // Accept if very close (distance <= 2)
                    if ($best && $bestDist <= 2) {
                        // Fetch full row
                        $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_first_name, dcmt_fathers_last_name, dcmt_mothers_last_name, dcmt_gender, dcmt_phone, dcmt_email FROM dcmt_patients WHERE dcmt_id = ?");
                        $stmt->execute([$best['dcmt_id']]);
                        $existing_patient = $stmt->fetch();
                    }
                }
            }

            $patient_id = null;
            if ($existing_patient) {
                $patient_id = $existing_patient['dcmt_id'];
                $update_cols = [];
                $update_params = [];
                if ($first_name !== '') {
                    $update_cols[] = "dcmt_first_name = ?";
                    $update_params[] = $first_name;
                }
                // Prefer explicit father/mother columns if provided; otherwise derive from 'surname'
                if ($father_ln_csv !== '' || $mother_ln_csv !== '') {
                    if ($father_ln_csv !== '') {
                        $update_cols[] = "dcmt_fathers_last_name = ?";
                        $update_params[] = $father_ln_csv;
                    }
                    if ($mother_ln_csv !== '') {
                        $update_cols[] = "dcmt_mothers_last_name = ?";
                        $update_params[] = $mother_ln_csv;
                    }
                } elseif ($last_name !== '') {
                    $ln_parts = preg_split('/\s+/', $last_name);
                    $father_ln = $ln_parts[0] ?? '';
                    $mother_ln = '';
                    if (count($ln_parts) >= 2) {
                        array_shift($ln_parts);
                        $mother_ln = trim(implode(' ', $ln_parts));
                    }
                    $update_cols[] = "dcmt_fathers_last_name = ?";
                    $update_params[] = $father_ln !== '' ? $father_ln : null;
                    $update_cols[] = "dcmt_mothers_last_name = ?";
                    $update_params[] = $mother_ln !== '' ? $mother_ln : null;
                }
                if ($gender !== '' && in_array($gender, ['male', 'female', 'other'], true)) {
                    $update_cols[] = "dcmt_gender = ?";
                    $update_params[] = $gender;
                }
                if (!empty($email)) {
                    $update_cols[] = "dcmt_email = COALESCE(NULLIF(dcmt_email, ''), ?)";
                    $update_params[] = $email;
                }
                if (!empty($phone)) {
                    $update_cols[] = "dcmt_phone = COALESCE(NULLIF(dcmt_phone, ''), ?)";
                    $update_params[] = $phone;
                }

                if (!empty($update_cols)) {
                    $update_params[] = $patient_id;
                    $update_sql = "UPDATE dcmt_patients SET " . implode(', ', $update_cols) . " WHERE dcmt_id = ?";
                    $update_stmt = $dcmt_pdo->prepare($update_sql);
                    $update_stmt->execute($update_params);
                }
            } else {
                $insert_stmt = $dcmt_pdo->prepare("
                    INSERT INTO dcmt_patients (dcmt_first_name, dcmt_fathers_last_name, dcmt_mothers_last_name, dcmt_patient_name, dcmt_gender, dcmt_email, dcmt_phone, dcmt_status, dcmt_created_by)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)
                ");
                $final_first_name = $first_name !== '' ? $first_name : $full_name;
                // Prefer explicit father/mother CSV columns; otherwise derive from 'surname'
                $father_ln = null;
                $mother_ln = null;
                if ($father_ln_csv !== '' || $mother_ln_csv !== '') {
                    $father_ln = $father_ln_csv !== '' ? $father_ln_csv : null;
                    $mother_ln = $mother_ln_csv !== '' ? $mother_ln_csv : null;
                } else {
                    $ln_parts = preg_split('/\s+/', $last_name);
                    $father_ln = $ln_parts[0] ?? null;
                    if ($last_name !== '') {
                        if (count($ln_parts) >= 2) {
                            array_shift($ln_parts);
                            $mother_ln = trim(implode(' ', $ln_parts)) ?: null;
                        }
                    }
                }
                $final_gender = in_array($gender, ['male', 'female', 'other'], true) ? $gender : 'other';
                $insert_stmt->execute([$final_first_name, $father_ln, $mother_ln, $full_name, $final_gender, $email, $phone, $created_by]);
                $patient_id = $dcmt_pdo->lastInsertId();
                $imported_count++;
            }

            // Link health history: update income records that match this name and have no patient_id
            if ($patient_id) {
                $update_income_stmt = $dcmt_pdo->prepare("
                    UPDATE dcmt_income 
                    SET dcmt_patient_id = ? 
                    WHERE (dcmt_patient_id IS NULL OR dcmt_patient_id = 0) 
                    AND LOWER(TRIM(dcmt_patient_name)) = LOWER(TRIM(?))
                ");
                $update_income_stmt->execute([$patient_id, $full_name]);
                $updated_income_count += $update_income_stmt->rowCount();
            }

        } catch (PDOException $e) {
            $errors[] = sprintf("Row %d: Database error - %s", $row_number, $e->getMessage());
        }
    }

    fclose($handle);

    if ($imported_count > 0 || $updated_income_count > 0) {
        dcmt_log_activity("Patients imported: $imported_count new, $updated_income_count income records linked", "patients_imported");
    }

    return [
        'errors' => $errors,
        'success' => $success,
        'imported_count' => $imported_count,
        'updated_income_count' => $updated_income_count
    ];
}

?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12">
            <div class="page-title-box d-flex align-items-center justify-content-between">
                <h4 class="mb-0">
                    <?php echo trans('patient', 'import_patients'); ?>
                </h4>
                <div class="page-title-right">
                    <a href="index.php" class="btn btn-secondary btn-sm">
                        <i class="fas fa-arrow-left me-1"></i>
                        <?php echo trans('common', 'back_to_list'); ?>
                    </a>
                </div>
            </div>
        </div>
    </div>

    <?php if (!empty($import_errors)): ?>
        <div class="alert alert-danger alert-dismissible fade show" role="alert">
            <h6 class="alert-heading"><i class="fas fa-exclamation-circle me-2"></i>
                <?php echo trans('common', 'errors'); ?>
            </h6>
            <ul class="mb-0">
                <?php foreach ($import_errors as $error): ?>
                    <li>
                        <?php echo htmlspecialchars($error); ?>
                    </li>
                <?php endforeach; ?>
            </ul>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <?php if ($imported_count > 0 || $updated_income_count > 0): ?>
        <div class="alert alert-success alert-dismissible fade show" role="alert">
            <h6 class="alert-heading"><i class="fas fa-check-circle me-2"></i>
                <?php echo trans('common', 'import_completed'); ?>
            </h6>
            <p class="mb-0">
                <?php echo sprintf(trans('patient', 'import_summary'), $imported_count, $updated_income_count); ?>
            </p>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    <?php endif; ?>

    <div class="row">
        <div class="col-md-8">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-4">
                        <?php echo trans('patient', 'upload_csv_file'); ?>
                    </h5>

                    <form action="" method="POST" enctype="multipart/form-data">
                        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

                        <div class="mb-4">
                            <label for="csv_file" class="form-label">
                                <?php echo trans('patient', 'select_csv_file'); ?>
                            </label>
                            <input type="file" class="form-control" id="csv_file" name="csv_file" accept=".csv"
                                required>
                            <div class="form-text mt-2">
                                <i class="fas fa-info-circle me-1"></i>
                                <?php echo trans('patient', 'import_instructions'); ?>
                            </div>
                        </div>

                        <div class="d-flex gap-2">
                            <button type="submit" name="import_patients" class="btn btn-primary">
                                <i class="fas fa-upload me-1"></i>
                                <?php echo trans('patient', 'start_import'); ?>
                            </button>
                            <a href="../income/export_patient_names.php" class="btn btn-outline-info">
                                <i class="fas fa-download me-1"></i>
                                <?php echo trans('patient', 'download_migration_template'); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-md-4">
            <div class="card">
                <div class="card-body">
                    <h5 class="card-title mb-3">
                        <?php echo trans('patient', 'csv_requirements'); ?>
                    </h5>
                    <ul class="list-group list-group-flush">
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">
                                    <?php echo trans('patient', 'required_columns'); ?>
                                </div>
                                <span class="badge bg-primary rounded-pill">Full Name</span>
                            </div>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">
                                    <?php echo trans('patient', 'optional_columns'); ?>
                                </div>
                                Name, Surname, Gender, Phone Number
                            </div>
                        </li>
                        <li class="list-group-item d-flex justify-content-between align-items-start">
                            <div class="ms-2 me-auto">
                                <div class="fw-bold">
                                    <?php echo trans('patient', 'file_format'); ?>
                                </div>
                                CSV (UTF-8)
                            </div>
                        </li>
                    </ul>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
