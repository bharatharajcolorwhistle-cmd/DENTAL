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
    $ongoing_where = "WHERE a.dcmt_start_at >= CURDATE()
        AND a.dcmt_start_at < CURDATE() + INTERVAL 1 DAY
        AND a.dcmt_status NOT IN ('completed', 'cancelled', 'no_show')
        AND a.dcmt_actual_start_at IS NOT NULL
        AND a.dcmt_actual_end_at IS NULL";
    $ongoing_params = [];
    if ($is_doctor) {
        $ongoing_where .= " AND a.dcmt_doctor_id = ?";
        $ongoing_params[] = (int)$current_user['dcmt_id'];
    } elseif ($doctor_id > 0) {
        $ongoing_where .= " AND a.dcmt_doctor_id = ?";
        $ongoing_params[] = $doctor_id;
    }

    $ongoing_list_stmt = $dcmt_pdo->prepare("
        SELECT
            a.dcmt_id,
            p.dcmt_patient_name,
            d.dcmt_full_name AS doctor_name,
            o.dcmt_name AS operatory_name,
            a.dcmt_actual_start_at
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        LEFT JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        {$ongoing_where}
        ORDER BY a.dcmt_actual_start_at DESC
        LIMIT 25
    ");
    $ongoing_list_stmt->execute($ongoing_params);
    $ongoing_rows = $ongoing_list_stmt->fetchAll(PDO::FETCH_ASSOC);

    $ongoing = [];
    foreach ($ongoing_rows as $ongoing_row) {
        $ongoing[] = [
            'id' => (int)($ongoing_row['dcmt_id'] ?? 0),
            'patient_name' => (string)($ongoing_row['dcmt_patient_name'] ?? ''),
            'doctor_name' => (string)($ongoing_row['doctor_name'] ?? ''),
            'operatory_name' => (string)($ongoing_row['operatory_name'] ?? ''),
            'actual_start_at' => (string)($ongoing_row['dcmt_actual_start_at'] ?? ''),
        ];
    }

    $ongoing_count_stmt = $dcmt_pdo->prepare("
        SELECT COUNT(*)
        FROM dcmt_appointments a
        {$ongoing_where}
    ");
    $ongoing_count_stmt->execute($ongoing_params);
    $ongoing_count = (int)$ongoing_count_stmt->fetchColumn();

    $where = "WHERE a.dcmt_start_at >= CURDATE()
        AND a.dcmt_start_at < CURDATE() + INTERVAL 1 DAY
        AND a.dcmt_status NOT IN ('completed', 'cancelled', 'no_show')";
    $params = [];

    if ($is_doctor) {
        $where .= " AND a.dcmt_doctor_id = ?";
        $params[] = (int)$current_user['dcmt_id'];
    } elseif ($doctor_id > 0) {
        $where .= " AND a.dcmt_doctor_id = ?";
        $params[] = $doctor_id;
    }

    $raw_ids = trim((string)($_GET['ids'] ?? ''));
    $id_parts = $raw_ids !== '' ? preg_split('/\s*,\s*/', $raw_ids) : [];
    $requested_ids = [];
    foreach ($id_parts as $id_part) {
        $parsed_id = (int)$id_part;
        if ($parsed_id > 0) {
            $requested_ids[$parsed_id] = true;
        }
        if (count($requested_ids) >= 50) {
            break;
        }
    }
    $id_list = array_keys($requested_ids);

    $appointments = [];
    if ($id_list) {
        $placeholders = implode(',', array_fill(0, count($id_list), '?'));
        $row_params = $id_list;
        $row_guard = '';
        if ($is_doctor) {
            $row_guard = ' AND a.dcmt_doctor_id = ?';
            $row_params[] = (int)$current_user['dcmt_id'];
        } elseif ($doctor_id > 0) {
            $row_guard = ' AND a.dcmt_doctor_id = ?';
            $row_params[] = $doctor_id;
        }

        $row_stmt = $dcmt_pdo->prepare("
            SELECT
                a.dcmt_id,
                a.dcmt_status,
                a.dcmt_actual_start_at,
                a.dcmt_actual_end_at
            FROM dcmt_appointments a
            WHERE a.dcmt_id IN ({$placeholders})
            {$row_guard}
        ");
        $row_stmt->execute($row_params);
        $rows_by_id = [];
        foreach ($row_stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $rows_by_id[(int)$row['dcmt_id']] = $row;
        }

        foreach ($id_list as $aid) {
            $row = $rows_by_id[$aid] ?? null;
            if (!$row) {
                $appointments[] = [
                    'id' => $aid,
                    'exists' => false,
                ];
                continue;
            }
            $appointments[] = [
                'id' => $aid,
                'exists' => true,
                'status' => dcmt_normalize_appointment_status((string)$row['dcmt_status']),
                'has_actual_start' => !empty($row['dcmt_actual_start_at']),
                'has_actual_end' => !empty($row['dcmt_actual_end_at']),
            ];
        }
    } else {
        $stmt = $dcmt_pdo->prepare("
            SELECT
                a.dcmt_id,
                a.dcmt_status,
                a.dcmt_actual_start_at,
                a.dcmt_actual_end_at
            FROM dcmt_appointments a
            {$where}
            ORDER BY a.dcmt_start_at ASC
            LIMIT 50
        ");
        $stmt->execute($params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $appointments[] = [
                'id' => (int)$row['dcmt_id'],
                'exists' => true,
                'status' => dcmt_normalize_appointment_status((string)$row['dcmt_status']),
                'has_actual_start' => !empty($row['dcmt_actual_start_at']),
                'has_actual_end' => !empty($row['dcmt_actual_end_at']),
            ];
        }
    }

    $status_where = "WHERE a.dcmt_start_at >= CURDATE()
        AND a.dcmt_start_at < CURDATE() + INTERVAL 1 DAY";
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
        'ongoing_count' => $ongoing_count,
        'ongoing' => $ongoing,
    ];

    echo json_encode([
        'success' => true,
        'appointments' => $appointments,
        'status_counts' => $status_counts,
        'ongoing_count' => $ongoing_count,
        'ongoing' => $ongoing,
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
