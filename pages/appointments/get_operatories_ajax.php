<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';


$m = dcmt_appointment_messages();
$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';

if (!in_array($role, ['admin', 'staff', 'assistant', 'doctor'], true)) {
    echo json_encode(['success' => false, 'message' => $m['unauthorized']]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT dcmt_id AS id, dcmt_name AS name, dcmt_sort_order AS sort_order, dcmt_is_active AS is_active
        FROM dcmt_operatories
        ORDER BY dcmt_sort_order ASC, dcmt_id ASC
    ");
    $stmt->execute();
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    $operatories = [];
    foreach ($rows as $row) {
        $operatories[] = [
            'id' => (int)$row['id'],
            'name' => (string)$row['name'],
            'sort_order' => (int)$row['sort_order'],
            'is_active' => (int)$row['is_active'],
        ];
    }

    echo json_encode(['success' => true, 'operatories' => $operatories]);
} catch (PDOException $e) {
    error_log('get_operatories_ajax: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => $m['database_error']]);
}
