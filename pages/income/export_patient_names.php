<?php
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

try {
    $sql = "
        SELECT DISTINCT
            i.dcmt_patient_id,
            TRIM(i.dcmt_patient_name) AS full_name,
            p.dcmt_first_name AS first_name,
            p.dcmt_fathers_last_name AS father_ln,
            p.dcmt_mothers_last_name AS mother_ln,
            COALESCE(NULLIF(TRIM(p.dcmt_phone), ''), '') AS phone,
            COALESCE(NULLIF(TRIM(p.dcmt_gender), ''), '') AS gender
        FROM dcmt_income i
        LEFT JOIN dcmt_patients p ON i.dcmt_patient_id = p.dcmt_id
        WHERE i.dcmt_patient_name IS NOT NULL
          AND TRIM(i.dcmt_patient_name) <> ''
        ORDER BY full_name ASC
    ";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    dcmt_show_message(trans('income', 'database_error'), 'error');
    dcmt_redirect('index.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="patient_names_for_migration_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');

fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

$headers = [
    'Full Name',          // for matching on import
    'Name',               // first name (you will fill manually if blank)
    'Mother Last Name',
    'Father Last Name',
    'Phone Number',
    'Gender'
];
fputcsv($output, $headers);

$seen = [];
foreach ($rows as $row) {
    $full = trim((string)$row['full_name']);
    // Do not split name; only use stored patient fields if available
    $name = trim((string)($row['first_name'] ?? ''));
    $mother = trim((string)($row['mother_ln'] ?? ''));
    $father = trim((string)($row['father_ln'] ?? ''));
    $phone = (string)($row['phone'] ?? '');
    $gender = (string)($row['gender'] ?? '');

    // Deduplicate primarily by Full Name + Phone
    $key = strtolower($full) . '|' . strtolower($phone);
    if (isset($seen[$key])) {
        continue;
    }
    $seen[$key] = true;

    // Order: Full Name, Name, Mother Last Name, Father Last Name, Phone, Gender
    fputcsv($output, [$full, $name, $mother, $father, $phone, $gender]);
}

fclose($output);
exit();
?>
