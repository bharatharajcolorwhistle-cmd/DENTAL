<?php
/**
 * Lightweight appointment timer state for cross-device synchronization.
 *
 * The appointment calendar polls this endpoint to pick up appointments that
 * were started/finished on another device without doing a full event refetch.
 */

require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

$m = dcmt_appointment_messages();
$current_user = dcmt_get_current_user();
$role = (string)($current_user['dcmt_role'] ?? '');

if (!in_array($role, ['super_admin', 'admin', 'staff', 'assistant', 'doctor'], true)) {
    http_response_code(403);
    echo json_encode([
        'success' => false,
        'message' => $m['unauthorized']
    ]);
    exit();
}

$start = dcmt_normalize_calendar_bound($_GET['start'] ?? '');
$end = dcmt_normalize_calendar_bound($_GET['end'] ?? '');
$ids_param = $_GET['ids'] ?? '';
$doctor_ids_param = $_GET['doctor_ids'] ?? ($_GET['doctor_id'] ?? []);

$ids = [];
if (is_array($ids_param)) {
    foreach ($ids_param as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
} else {
    foreach (preg_split('/[,\s]+/', (string)$ids_param) as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $ids[$id] = $id;
        }
    }
}

$doctor_ids = [];
if (is_array($doctor_ids_param)) {
    foreach ($doctor_ids_param as $value) {
        $id = (int)$value;
        if ($id > 0) {
            $doctor_ids[$id] = $id;
        }
    }
} else {
    foreach (preg_split('/[,\s]+/', (string)$doctor_ids_param) as $part) {
        $id = (int)$part;
        if ($id > 0) {
            $doctor_ids[$id] = $id;
        }
    }
}

$is_limited_doctor = ($role === 'doctor') && !dcmt_is_admin();
if ($is_limited_doctor) {
    $doctor_ids = [(int)$current_user['dcmt_id'] => (int)$current_user['dcmt_id']];
}

if (empty($ids) && ($start === '' || $end === '')) {
    echo json_encode([
        'success' => false,
        'message' => $m['invalid_request']
    ]);
    exit();
}

try {
    $where = [];
    $params = [];

    if (!empty($ids)) {
        $placeholders = implode(',', array_fill(0, count($ids), '?'));
        $where[] = "a.dcmt_id IN ($placeholders)";
        $params = array_merge($params, array_values($ids));
    }

    if ($start !== '' && $end !== '') {
        $where[] = "a.dcmt_start_at < ? AND a.dcmt_end_at > ?";
        $params[] = $end;
        $params[] = $start;
    }

    if (count($doctor_ids) === 1) {
        $where[] = "a.dcmt_doctor_id = ?";
        $params[] = array_values($doctor_ids)[0];
    } elseif (count($doctor_ids) > 1) {
        $placeholders = implode(',', array_fill(0, count($doctor_ids), '?'));
        $where[] = "a.dcmt_doctor_id IN ($placeholders)";
        $params = array_merge($params, array_values($doctor_ids));
    } elseif ($start !== '' && $end !== '') {
        // Range polling without a doctor filter would leak other doctors'
        // appointments on the limited calendar; return nothing instead.
        echo json_encode(['success' => true, 'signature' => '', 'appointments' => []]);
        exit();
    }

    $sql = "SELECT a.dcmt_id, a.dcmt_status, a.dcmt_actual_start_at, a.dcmt_actual_end_at,
                   a.dcmt_started_by, a.dcmt_ended_by, a.dcmt_cancelled_by, a.dcmt_updated_at,
                   us.dcmt_full_name AS started_by_name,
                   ue.dcmt_full_name AS ended_by_name
            FROM dcmt_appointments a
            LEFT JOIN dcmt_users us ON us.dcmt_id = a.dcmt_started_by
            LEFT JOIN dcmt_users ue ON ue.dcmt_id = a.dcmt_ended_by";

    if (!empty($where)) {
        $sql .= " WHERE " . implode(' AND ', $where);
    }

    $sql .= " ORDER BY a.dcmt_start_at ASC LIMIT 500";

    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

    $appointments = [];
    $tokens = [];
    foreach ($rows as $row) {
        $state = dcmt_appointment_timer_state($row);
        $tokens[] = $state['token'];
        $appointments[] = [
            'id' => $state['id'],
            'status' => $state['status'],
            'actual_start_at' => $state['actual_start_at'],
            'actual_end_at' => $state['actual_end_at'],
            'actual_start_time' => $state['actual_start_time'],
            'actual_end_time' => $state['actual_end_time'],
            'actual_start_label' => $state['actual_start_label'],
            'actual_end_label' => $state['actual_end_label'],
            'has_actual_start' => $state['has_actual_start'],
            'has_actual_end' => $state['has_actual_end'],
            'is_in_progress' => $state['is_in_progress'],
            'can_start' => $state['can_start'],
            'can_end' => $state['can_end'],
            'started_by_id' => $state['started_by_id'],
            'started_by_name' => $state['started_by_name'],
            'ended_by_id' => $state['ended_by_id'],
            'ended_by_name' => $state['ended_by_name'],
            'updated_at' => $state['updated_at'],
            'token' => $state['token'],
        ];
    }

    sort($tokens);

    echo json_encode([
        'success' => true,
        'signature' => sha1(implode('|', $tokens)),
        'appointments' => $appointments,
        'server_time' => dcmt_get_current_datetime(),
    ]);
} catch (PDOException $e) {
    error_log('Appointment timer state error: ' . $e->getMessage());
    http_response_code(500);
    echo json_encode([
        'success' => false,
        'message' => $m['database_error']
    ]);
}
