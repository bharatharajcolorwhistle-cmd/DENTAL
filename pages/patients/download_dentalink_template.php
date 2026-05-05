<?php
/**
 * Download Dentalink-format patient import template (Spanish column headers).
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect('/dental/auth/login.php');
    exit();
}

header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="patient_import_dentalink_template.csv"');

$output = fopen('php://output', 'w');
fprintf($output, chr(0xEF) . chr(0xBB) . chr(0xBF));

$headers = [
    '# Paciente',
    'Nombre',
    'Apellidos',
    'Fecha de nac.',
    'Edad',
    'Teléfono',
    'Celular',
    'Ciudad',
    'Comuna',
    'Dirección',
    'E-Mail',
    'Sexo',
    '# Apoderado',
];
fputcsv($output, $headers, ',', '"', '\\');

$sample_row = [
    '100',
    'María',
    'García López',
    '1995-03-15',
    '31',
    '9711234567',
    '',
    'Oaxaca',
    'Centro',
    'Calle Ejemplo 123',
    'maria@example.com',
    'F',
    'Juan García Ruiz',
];
fputcsv($output, $sample_row, ',', '"', '\\');

fclose($output);
exit();
