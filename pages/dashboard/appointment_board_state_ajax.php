<?php
/**
 * Dashboard appointment board lightweight state sync endpoint.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!dcmt_validate_session()) {
    http_response_code(401);
    echo json_encode([
        'success' => false,
        'message' => trans('login', 'session_expired')
    ]);
    exit();
}

$current_user = dcmt_get_current_user();
$role = (string)($current_user['dcmt_role'] ?? '');
if (!in_array($role, ['admin', 'staff', 'assistant', 'doctor'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => trans('common', 'unauthorized')
    ]);
    exit();
}

$doctor_id = (int)($_GET['doctor_id'] ?? 0);
$is_doctor = $role === 'doctor';

try {
    $where = "WHERE DATE(a.dcmt_start_at) = CURDATE()
        AND a.dcmt_status NOT IN ('completed', 'cancelled', 'no_show')";
    $params = [];

    if ($is_doctor) {
        $where .= " AND a.dcmt_doctor_id = ?";
        $params[] = (int)$current_user['dcmt_id'];
    } elseif ($doctor_id > 0) {
        $where .= " AND a.dcmt_doctor_id = ?";
        $params[] = $doctor_id;
    }

    $stmt = $dcmt_pdo->prepare("
        SELECT
            a.dcmt_id,
            a.dcmt_status,
            a.dcmt_actual_start_at,
            a.dcmt_actual_end_at
        FROM dcmt_appointments a
        {$where}
        ORDER BY a.dcmt_start_at ASC
        LIMIT 5
    ");
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $appointments = [];
    foreach ($rows as $row) {
        $appointments[] = [
            'id' => (int)$row['dcmt_id'],
            'status' => dcmt_normalize_appointment_status((string)$row['dcmt_status']),
            'has_actual_start' => !empty($row['dcmt_actual_start_at']),
            'has_actual_end' => !empty($row['dcmt_actual_end_at']),
        ];
    }

    $status_where = "WHERE DATE(a.dcmt_start_at) = CURDATE()";
    $status_params = [];
    if ($is_doctor) {
        $status_where .= " AND a.dcmt_doctor_id = ?";
        $status_params[] = (int)$current_user['dcmt_id'];
    } elseif ($doctor_id > 0) {
        $status_where .= " AND a.dcmt_doctor_id = ?";
        $status_params[] = $doctor_id;
    }

    $status_counts = ['scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
    $status_stmt = $dcmt_pdo->prepare("
        SELECT
            CASE
                WHEN a.dcmt_status = 'confirmed' THEN 'scheduled'
                WHEN a.dcmt_status = 'no_show' THEN 'cancelled'
                WHEN a.dcmt_status IN ('scheduled', 'completed', 'cancelled') THEN a.dcmt_status
                ELSE 'scheduled'
            END AS normalized_status,
            COUNT(*) AS total_count
        FROM dcmt_appointments a
        {$status_where}
        GROUP BY normalized_status
    ");
    $status_stmt->execute($status_params);
    foreach ($status_stmt->fetchAll(PDO::FETCH_ASSOC) as $status_row) {
        $status_key = (string)($status_row['normalized_status'] ?? '');
        if (array_key_exists($status_key, $status_counts)) {
            $status_counts[$status_key] = (int)$status_row['total_count'];
        }
    }

    $signature_payload = [
        'appointments' => $appointments,
        'status_counts' => $status_counts,
    ];

    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'status_counts' => $status_counts,
        'signature' => sha1(json_encode($signature_payload)),
    ]);
} catch (PDOException $e) {
    error_log('Appointment board state AJAX error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => trans('appointment', 'database_error')
    ]);
}

