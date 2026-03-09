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
        SELECT DISTINCT i.dcmt_patient_name, i.dcmt_transaction_date, i.dcmt_id
        FROM dcmt_income_payment_history iph
        INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
        WHERE iph.dcmt_notes IS NULL OR iph.dcmt_notes = ''
        ORDER BY i.dcmt_transaction_date DESC, i.dcmt_created_at DESC
    ";

    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute();
    $income_records = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    dcmt_show_message(trans('income', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="income_payment_history_notes_null_' . date('Y-m-d_H-i-s') . '.csv"');

$output = fopen('php://output', 'w');

fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

$headers = [
    'name',
    'income_date',
    'income_id'
];

fputcsv($output, $headers);

foreach ($income_records as $income) {
    $row = [
        ucfirst($income['dcmt_patient_name']),
        $income['dcmt_transaction_date'],
        $income['dcmt_id']
    ];

    fputcsv($output, $row);
}

fclose($output);
exit();
