<?php
/**
 * Export Users Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Check admin access
dcmt_require_admin_or_doctor();
$dcmt_current_user = dcmt_get_current_user();
$dcmt_is_admin_user = dcmt_is_admin();

// Get search and filter parameters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$role = isset($_GET['role']) ? dcmt_sanitize_input($_GET['role']) : '';
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(dcmt_username LIKE ? OR dcmt_email LIKE ? OR dcmt_full_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($role)) {
    $where_conditions[] = "dcmt_role = ?";
    $params[] = $role;
}

if (!empty($status)) {
    $where_conditions[] = "dcmt_status = ?";
    $params[] = $status;
}

if (!($dcmt_is_admin_user ?? false)) {
    $where_conditions[] = "dcmt_id = ?";
    $params[] = (int) ($dcmt_current_user['dcmt_id'] ?? 0);
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Get users for export
try {
    $sql = "SELECT * FROM dcmt_users $where_clause ORDER BY dcmt_created_at DESC";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute($params);
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Log export activity
    dcmt_log_activity("Users exported to CSV - " . count($users) . " records", "user_exported");
    
} catch (PDOException $e) {
    dcmt_show_message(trans('user', 'database_error'), "error");
    dcmt_redirect("index.php");
    exit();
}

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="users_' . date('Y-m-d_H-i-s') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add BOM for UTF-8
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));

// CSV Headers
$headers = [
    'ID',
    trans('user', 'username'),
    trans('user', 'full_name'),
    trans('user', 'email_address'),
    trans('user', 'role'),
    trans('common', 'status'),
    trans('user', 'phone'),
    trans('user', 'address'),
    trans('user', 'notes'),
    trans('user', 'last_login'),
    trans('common', 'created_by'),
    trans('common', 'created_at'),
    trans('common', 'updated_at')
];

fputcsv($output, $headers, ',', '"', '\\');

// CSV Data
foreach ($users as $user) {
    $row = [
        $user['dcmt_id'],
        $user['dcmt_username'],
        $user['dcmt_full_name'],
        $user['dcmt_email'],
        trans('user', $user['dcmt_role']),
        ucfirst(str_replace('_', ' ', $user['dcmt_status'])),
        $user['dcmt_phone'] ?? '',
        $user['dcmt_address'] ?? '',
        $user['dcmt_notes'] ?? '',
        $user['dcmt_last_login'] ?? '',
        $user['dcmt_created_by'],
        $user['dcmt_created_at'],
        $user['dcmt_updated_at'] ?? ''
    ];
    
    fputcsv($output, $row, ',', '"', '\\');
}

// Close output stream
fclose($output);

// Exit to prevent any additional output
exit;
?>
