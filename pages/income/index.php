<?php
/**
 * Income Management - List View
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Check if user is logged in
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

require_once __DIR__ . '/../../includes/header.php';

// Get current user for permission checks
$current_user = dcmt_get_current_user();
$is_staff = $current_user && $current_user['dcmt_role'] === 'staff';
$is_doctor_user = $current_user && ($current_user['dcmt_role'] ?? '') === 'doctor';
$is_owner_doctor = $is_doctor_user && dcmt_is_admin();
$is_limited_doctor = $is_doctor_user && !$is_owner_doctor;
$is_admin_or_doctor = dcmt_is_admin_or_doctor();
$dcmt_can_delete = dcmt_can_delete_records();

// Generate CSRF token for AJAX operations
$csrf_token = dcmt_generate_csrf_token();
?>
<meta name="csrf-token" content="<?php echo $csrf_token; ?>">
<?php

// Display delete operation messages
if (isset($_SESSION['income_delete_success'])) {
    echo '<div class="alert alert-success alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['income_delete_success']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['income_delete_success']);
}

if (isset($_SESSION['income_delete_error'])) {
    echo '<div class="alert alert-danger alert-dismissible fade show" role="alert">';
    echo htmlspecialchars($_SESSION['income_delete_error']);
    echo '<button type="button" class="btn-close" data-bs-dismiss="alert"></button>';
    echo '</div>';
    unset($_SESSION['income_delete_error']);
}

// Get search and filter parameters
$search = dcmt_sanitize_input($_GET['search'] ?? '');
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$type_filter = dcmt_sanitize_input($_GET['type'] ?? ''); // Now refers to line_type (service/product)
$doctor_filter = dcmt_sanitize_input($_GET['doctor'] ?? '');
$status_filter = dcmt_sanitize_input($_GET['status'] ?? '');
$payment_method_filter = dcmt_sanitize_input($_GET['payment_method'] ?? '');
$date_range = dcmt_sanitize_input($_GET['date_range'] ?? '');
$clear_filters = isset($_GET['clear']) && $_GET['clear'] === '1';
$is_date_range_provided = isset($_GET['date_range']);
$has_active_non_date_filters = !empty($search)
    || $patient_id > 0
    || !empty($type_filter)
    || !empty($doctor_filter)
    || !empty($status_filter)
    || !empty($payment_method_filter);
if (
    !$clear_filters
    && !$is_date_range_provided
    && !$has_active_non_date_filters
) {
    $date_range = date('Y-m-01') . ' to ' . date('Y-m-t');
}
$has_line_type_filter = !empty($type_filter) && in_array($type_filter, ['service', 'product'], true);

// Non-owner doctors are locked to their own records in Income index.
if ($is_limited_doctor) {
    $doctor_filter = (string) ((int) ($current_user['dcmt_id'] ?? 0));
}

// Parse date range
$date_from = '';
$date_to = '';
if (!empty($date_range) && strpos($date_range, ' to ') !== false) {
    $dates = explode(' to ', $date_range);
    if (count($dates) === 2) {
        $date_from = trim($dates[0]);
        $date_to = trim($dates[1]);
    }
}
$page = max(1, intval($_GET['page'] ?? 1));
$limit = DCMT_PER_PAGE;
$offset = ($page - 1) * $limit;

// Build WHERE clause
$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(i.dcmt_patient_name LIKE ? OR i.dcmt_description LIKE ?)";
    $like = "%$search%";
    $params[] = $like;
    $params[] = $like;
}

if ($patient_id > 0) {
    $where_conditions[] = "(i.dcmt_patient_id = ? OR (i.dcmt_patient_id IS NULL AND i.dcmt_patient_name = (SELECT dcmt_patient_name FROM dcmt_patients WHERE dcmt_id = ?)))";
    $params[] = $patient_id;
    $params[] = $patient_id;
}

if ($has_line_type_filter) {
    if ($type_filter === 'service') {
        $legacy_amount_condition = "(COALESCE(i.dcmt_service_amount, 0) > 0 OR COALESCE(i.dcmt_service_pending_amount, 0) > 0 OR COALESCE(i.dcmt_service_paid_amount, 0) > 0)";
    } else {
        $legacy_amount_condition = "(COALESCE(i.dcmt_product_amount, 0) > 0 OR COALESCE(i.dcmt_product_pending_amount, 0) > 0 OR COALESCE(i.dcmt_product_paid_amount, 0) > 0)";
    }

    $where_conditions[] = "(
        EXISTS (
            SELECT 1 FROM dcmt_income_breakdown ib 
            WHERE ib.dcmt_id = i.dcmt_id AND ib.dcmt_line_type = ?
        )
        OR (
            NOT EXISTS (
                SELECT 1 FROM dcmt_income_breakdown ib_any
                WHERE ib_any.dcmt_id = i.dcmt_id
            )
            AND $legacy_amount_condition
        )
    )";
    $params[] = $type_filter;
}

if (!empty($doctor_filter)) {
    if ($has_line_type_filter && $type_filter === 'product') {
        // Product revenue is on breakdown lines (default doctor), not the income header doctor
        $where_conditions[] = "EXISTS (
            SELECT 1 FROM dcmt_income_breakdown ib 
            WHERE ib.dcmt_id = i.dcmt_id 
              AND ib.dcmt_line_type = 'product' 
              AND ib.dcmt_user_id = ?
        )";
        $params[] = $doctor_filter;
    } elseif ($has_line_type_filter) {
        $where_conditions[] = "(i.dcmt_user_id = ? OR EXISTS (
            SELECT 1 FROM dcmt_income_breakdown ib 
            WHERE ib.dcmt_id = i.dcmt_id 
              AND ib.dcmt_line_type = ? 
              AND ib.dcmt_user_id = ?
        ))";
        $params[] = $doctor_filter;
        $params[] = $type_filter;
        $params[] = $doctor_filter;
    } else {
        $where_conditions[] = "(i.dcmt_user_id = ? OR EXISTS (
            SELECT 1 FROM dcmt_income_breakdown ib 
            WHERE ib.dcmt_id = i.dcmt_id AND ib.dcmt_user_id = ?
        ))";
        $params[] = $doctor_filter;
        $params[] = $doctor_filter;
    }
}

if (!empty($status_filter)) {
    $where_conditions[] = "ps.dcmt_name = ?";
    $params[] = $status_filter;
}

// Filter by payment method - check payment history for multiple payment methods per record
if (!empty($payment_method_filter)) {
    $where_conditions[] = "(pm.dcmt_name = ? OR EXISTS (
        SELECT 1 FROM dcmt_income_payment_history iph 
        INNER JOIN dcmt_income_payment_methods pm2 ON iph.dcmt_payment_method_id = pm2.dcmt_id
        WHERE iph.dcmt_income_id = i.dcmt_id 
        AND pm2.dcmt_name = ?
    ))";
    $params[] = $payment_method_filter;
    $params[] = $payment_method_filter;
}

if (!empty($date_from)) {
    $where_conditions[] = "i.dcmt_transaction_date >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "i.dcmt_transaction_date <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';
$base_params = $params;

// Get total count for pagination
$count_sql = "
    SELECT COUNT(*) as total 
    FROM dcmt_income i
    LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
    LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
    LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
    LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
    $where_clause
";
$stmt = $dcmt_pdo->prepare($count_sql);
$stmt->execute($params);
$total_records = $stmt->fetch()['total'];
$total_pages = ceil($total_records / $limit);

// Get income records
$sql = "
    SELECT i.*, 
           u_doctor.dcmt_full_name as doctor_name, 
           u.dcmt_full_name as created_by_name, 
           pm.dcmt_name as payment_method_name, 
           ps.dcmt_name as payment_status_name,
           p.dcmt_patient_name as linked_patient_name,
           p.dcmt_phone as linked_patient_phone
    FROM dcmt_income i
    LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
    LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
    LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
    LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
    LEFT JOIN dcmt_patients p ON i.dcmt_patient_id = p.dcmt_id
    $where_clause
    ORDER BY i.dcmt_transaction_date DESC, i.dcmt_created_at DESC
    LIMIT ? OFFSET ?
";

$params[] = $limit;
$params[] = $offset;

$stmt = $dcmt_pdo->prepare($sql);
$stmt->execute($params);
$income_records = $stmt->fetchAll();
$payment_method_paid_map = [];
$income_has_payment_history = [];
if (!empty($payment_method_filter) && !empty($income_records)) {
    $income_ids = array_column($income_records, 'dcmt_id');
    if (!empty($income_ids)) {
        $placeholders = implode(',', array_fill(0, count($income_ids), '?'));
        $method_amount_sql = "
            SELECT iph.dcmt_income_id, COALESCE(SUM(iph.dcmt_amount), 0) as method_paid_amount
            FROM dcmt_income_payment_history iph
            INNER JOIN dcmt_income_payment_methods pm_hist ON iph.dcmt_payment_method_id = pm_hist.dcmt_id
            WHERE iph.dcmt_income_id IN ($placeholders)
              AND pm_hist.dcmt_name = ?
            GROUP BY iph.dcmt_income_id
        ";
        $stmt = $dcmt_pdo->prepare($method_amount_sql);
        $stmt->execute(array_merge($income_ids, [$payment_method_filter]));
        $method_amount_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($method_amount_rows as $row) {
            $payment_method_paid_map[(int)$row['dcmt_income_id']] = $row['method_paid_amount'];
        }

        $has_history_sql = "
            SELECT DISTINCT dcmt_income_id
            FROM dcmt_income_payment_history
            WHERE dcmt_income_id IN ($placeholders)
        ";
        $stmt = $dcmt_pdo->prepare($has_history_sql);
        $stmt->execute($income_ids);
        $history_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($history_ids as $history_id) {
            $income_has_payment_history[(int)$history_id] = true;
        }
    }
}

$apply_doctor_paid_filter = !empty($doctor_filter);
$doctor_breakdown_total_map = [];
$doctor_breakdown_doctor_map = [];
if ($apply_doctor_paid_filter && !empty($income_records)) {
    $income_ids = array_map('intval', array_column($income_records, 'dcmt_id'));
    if (!empty($income_ids)) {
        $placeholders = implode(',', array_fill(0, count($income_ids), '?'));
        $type_sql = $has_line_type_filter ? " AND ib.dcmt_line_type = ? " : "";
        $doctor_breakdown_sql = "
            SELECT 
                ib.dcmt_id as income_id,
                COALESCE(SUM(ib.dcmt_line_total), 0) as total_line_total,
                COALESCE(SUM(CASE WHEN ib.dcmt_user_id = ? THEN ib.dcmt_line_total ELSE 0 END), 0) as doctor_line_total
            FROM dcmt_income_breakdown ib
            WHERE ib.dcmt_id IN ($placeholders)
            $type_sql
            GROUP BY ib.dcmt_id
        ";
        $query_params = array_merge([(int) $doctor_filter], $income_ids);
        if ($has_line_type_filter) {
            $query_params[] = $type_filter;
        }
        $stmt = $dcmt_pdo->prepare($doctor_breakdown_sql);
        $stmt->execute($query_params);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $income_id_value = (int)($row['income_id'] ?? 0);
            $doctor_breakdown_total_map[$income_id_value] = (float)($row['total_line_total'] ?? 0);
            $doctor_breakdown_doctor_map[$income_id_value] = (float)($row['doctor_line_total'] ?? 0);
        }
    }
}

// Get doctor role users for filter
if ($is_limited_doctor) {
    $stmt = $dcmt_pdo->prepare("
        SELECT dcmt_id, dcmt_full_name as dcmt_name
        FROM dcmt_users
        WHERE dcmt_id = ? AND dcmt_role = 'doctor' AND dcmt_status = 'active'
        LIMIT 1
    ");
    $stmt->execute([(int) ($current_user['dcmt_id'] ?? 0)]);
    $doctors = $stmt->fetchAll();
} else {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_full_name as dcmt_name FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name");
    $stmt->execute();
    $doctors = $stmt->fetchAll();
}

// Get payment statuses for filter
$stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_status WHERE dcmt_status = 'active' ORDER BY dcmt_name");
$stmt->execute();
$payment_statuses = $stmt->fetchAll();

// Get payment methods for filter
$stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active' ORDER BY dcmt_name");
$stmt->execute();
$payment_methods = $stmt->fetchAll();

// Get all patients for filter dropdown
$all_patients = [];
try {
    $stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_patient_name, dcmt_first_name, dcmt_phone, dcmt_status FROM dcmt_patients WHERE dcmt_status = 'active' ORDER BY dcmt_patient_name");
    $all_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching patients for income filter: " . $e->getMessage());
}
$payment_methods_for_js = array_map(function ($method) {
    $method_name = $method['dcmt_name'];
    $translated_name = trans('income_payment_method', $method_name);
    $display_name = ($translated_name !== $method_name) ? $translated_name : $method_name;
    return [
        'id' => $method['dcmt_id'],
        'name' => $method_name,
        'display_name' => ucfirst($display_name)
    ];
}, $payment_methods);

// Get total paid income for current filters
$paid_amount_expression = "CASE 
            WHEN i.dcmt_type IN ('consultation', 'product_sale') THEN i.dcmt_total_paid_amount
            ELSE i.dcmt_paid_amount
        END";
$pending_amount_expression = "CASE 
            WHEN i.dcmt_type IN ('consultation', 'product_sale') THEN i.dcmt_total_pending_amount
            ELSE i.dcmt_pending_amount
        END";
$paid_label_key = 'total_paid_amount';
$pending_label_key = 'total_pending_amount';

if ($type_filter === 'service') {
    $paid_amount_expression = "COALESCE(i.dcmt_service_paid_amount, 0)";
    $pending_amount_expression = "COALESCE(i.dcmt_service_pending_amount, 0)";
    $paid_label_key = 'service_paid_amount';
    $pending_label_key = 'service_pending_amount';
} elseif ($type_filter === 'product') {
    $paid_amount_expression = "COALESCE(i.dcmt_product_paid_amount, 0)";
    $pending_amount_expression = "COALESCE(i.dcmt_product_pending_amount, 0)";
    $paid_label_key = 'product_paid_amount';
    $pending_label_key = 'product_pending_amount';
}

// Build total paid income query. For overall totals (no type filter), sum payment history
// rows for incomes matching the same filters as the list (including transaction date on i).
// Filtering paid totals by iph.dcmt_paid_on instead made "total" disagree with service +
// product splits and with rows, which are all scoped by dcmt_transaction_date.
if (empty($type_filter)) {
    if (!empty($payment_method_filter)) {
        $total_sql = "
            SELECT COALESCE(SUM(iph.dcmt_amount), 0) as total
            FROM dcmt_income_payment_history iph
            INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
            LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
            LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
            LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
            LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
            INNER JOIN dcmt_income_payment_methods pm_hist ON iph.dcmt_payment_method_id = pm_hist.dcmt_id
            $where_clause
            AND pm_hist.dcmt_name = ?
        ";
        $total_params = array_merge($base_params, [$payment_method_filter]);
    } else {
        $total_sql = "
            SELECT COALESCE(SUM(iph.dcmt_amount), 0) as total
            FROM dcmt_income_payment_history iph
            INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
            LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
            LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
            LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
            LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
            $where_clause
        ";
        $total_params = $base_params;
    }
} else {
    $total_sql = "
        SELECT COALESCE(SUM(
            $paid_amount_expression
        ), 0) as total 
        FROM dcmt_income i
        LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
        LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
        $where_clause
    ";
    $total_params = $base_params;
    if (!empty($payment_method_filter)) {
        $total_sql = "
            SELECT COALESCE(SUM(iph.dcmt_amount), 0) as total
            FROM dcmt_income_payment_history iph
            INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
            LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
            LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
            LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
            LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
            INNER JOIN dcmt_income_payment_methods pm_hist ON iph.dcmt_payment_method_id = pm_hist.dcmt_id
            $where_clause
            AND pm_hist.dcmt_name = ?
        ";
        $total_params = array_merge($base_params, [$payment_method_filter]);
    }
}
$stmt = $dcmt_pdo->prepare($total_sql);
$stmt->execute($total_params);
$total_paid_income = $stmt->fetch()['total'];

if (!empty($doctor_filter)) {
    $breakdown_type_where = $has_line_type_filter ? "WHERE ib.dcmt_line_type = ?" : "";
    $breakdown_join = "
        LEFT JOIN (
            SELECT 
                ib.dcmt_id as income_id,
                COALESCE(SUM(ib.dcmt_line_total), 0) as total_line_total,
                COALESCE(SUM(CASE WHEN ib.dcmt_user_id = ? THEN ib.dcmt_line_total ELSE 0 END), 0) as doctor_line_total
            FROM dcmt_income_breakdown ib
            $breakdown_type_where
            GROUP BY ib.dcmt_id
        ) ibs ON ibs.income_id = i.dcmt_id
    ";

    $doctor_ratio_expression = "(
        CASE
            WHEN COALESCE(ibs.total_line_total, 0) > 0 THEN
                CASE
                    WHEN COALESCE(ibs.doctor_line_total, 0) > 0 THEN COALESCE(ibs.doctor_line_total, 0) / NULLIF(COALESCE(ibs.total_line_total, 0), 0)
                    WHEN i.dcmt_user_id = ? THEN 1
                    ELSE 0
                END
            WHEN i.dcmt_user_id = ? THEN 1
            ELSE 0
        END
    )";

    $doctor_ratio_expression_params = [(int) $doctor_filter, (int) $doctor_filter];
    $doctor_breakdown_join_params = [(int) $doctor_filter];
    if ($has_line_type_filter) {
        $doctor_breakdown_join_params[] = $type_filter;
    }

    if (empty($type_filter) || !empty($payment_method_filter)) {
        if (!empty($payment_method_filter)) {
            $doctor_total_sql = "
                SELECT COALESCE(SUM(iph.dcmt_amount * $doctor_ratio_expression), 0) as total
                FROM dcmt_income_payment_history iph
                INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
                LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
                LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
                LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
                LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
                $breakdown_join
                INNER JOIN dcmt_income_payment_methods pm_hist ON iph.dcmt_payment_method_id = pm_hist.dcmt_id
                $where_clause
                AND pm_hist.dcmt_name = ?
            ";
            $doctor_total_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params, [$payment_method_filter]);
        } else {
            $doctor_total_sql = "
                SELECT COALESCE(SUM(iph.dcmt_amount * $doctor_ratio_expression), 0) as total
                FROM dcmt_income_payment_history iph
                INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
                LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
                LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
                LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
                LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
                $breakdown_join
                $where_clause
            ";
            $doctor_total_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params);
        }
    } else {
        $doctor_total_sql = "
            SELECT COALESCE(SUM(($paid_amount_expression) * $doctor_ratio_expression), 0) as total
            FROM dcmt_income i
            LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
            LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
            LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
            LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
            $breakdown_join
            $where_clause
        ";
        $doctor_total_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params);
    }

    $stmt = $dcmt_pdo->prepare($doctor_total_sql);
    $stmt->execute($doctor_total_params);
    $total_paid_income = $stmt->fetch()['total'];

    if (!empty($payment_method_filter)) {
        if ($type_filter === 'service') {
            $legacy_paid_amount_expression = "COALESCE(i.dcmt_service_paid_amount, 0)";
        } elseif ($type_filter === 'product') {
            $legacy_paid_amount_expression = "COALESCE(i.dcmt_product_paid_amount, 0)";
        } else {
            $legacy_paid_amount_expression = "CASE 
                WHEN i.dcmt_type IN ('consultation', 'product_sale') THEN COALESCE(i.dcmt_total_paid_amount, 0)
                ELSE COALESCE(i.dcmt_paid_amount, 0)
            END";
        }

        $legacy_extra_condition = "pm.dcmt_name = ? AND NOT EXISTS (
            SELECT 1 FROM dcmt_income_payment_history iph_any
            WHERE iph_any.dcmt_income_id = i.dcmt_id
        )";
        $legacy_where_clause = trim($where_clause) !== '' ? ($where_clause . " AND " . $legacy_extra_condition) : ("WHERE " . $legacy_extra_condition);
        $doctor_legacy_sql = "
            SELECT COALESCE(SUM(($legacy_paid_amount_expression) * $doctor_ratio_expression), 0) as total 
            FROM dcmt_income i
            LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
            LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
            LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
            LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
            $breakdown_join
            $legacy_where_clause
        ";
        $doctor_legacy_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params, [$payment_method_filter]);
        $stmt = $dcmt_pdo->prepare($doctor_legacy_sql);
        $stmt->execute($doctor_legacy_params);
        $total_paid_income += $stmt->fetch()['total'];
    }
}

if (!empty($payment_method_filter) && empty($doctor_filter)) {
    if ($type_filter === 'service') {
        $legacy_paid_amount_expression = "COALESCE(i.dcmt_service_paid_amount, 0)";
    } elseif ($type_filter === 'product') {
        $legacy_paid_amount_expression = "COALESCE(i.dcmt_product_paid_amount, 0)";
    } else {
        $legacy_paid_amount_expression = "CASE 
            WHEN i.dcmt_type IN ('consultation', 'product_sale') THEN COALESCE(i.dcmt_total_paid_amount, 0)
            ELSE COALESCE(i.dcmt_paid_amount, 0)
        END";
    }

    $legacy_extra_condition = "pm.dcmt_name = ? AND NOT EXISTS (
        SELECT 1 FROM dcmt_income_payment_history iph_any
        WHERE iph_any.dcmt_income_id = i.dcmt_id
    )";
    $legacy_where_clause = trim($where_clause) !== '' ? ($where_clause . " AND " . $legacy_extra_condition) : ("WHERE " . $legacy_extra_condition);
    $legacy_total_sql = "
        SELECT COALESCE(SUM(
            $legacy_paid_amount_expression
        ), 0) as total 
        FROM dcmt_income i
        LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
        LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
        $legacy_where_clause
    ";
    $legacy_params = array_merge($base_params, [$payment_method_filter]);
    $stmt = $dcmt_pdo->prepare($legacy_total_sql);
    $stmt->execute($legacy_params);
    $total_paid_income += $stmt->fetch()['total'];
}

if (!empty($doctor_filter)) {
    $breakdown_type_where = $has_line_type_filter ? "WHERE ib.dcmt_line_type = ?" : "";
    $breakdown_join = "
        LEFT JOIN (
            SELECT 
                ib.dcmt_id as income_id,
                COALESCE(SUM(ib.dcmt_line_total), 0) as total_line_total,
                COALESCE(SUM(CASE WHEN ib.dcmt_user_id = ? THEN ib.dcmt_line_total ELSE 0 END), 0) as doctor_line_total
            FROM dcmt_income_breakdown ib
            $breakdown_type_where
            GROUP BY ib.dcmt_id
        ) ibs ON ibs.income_id = i.dcmt_id
    ";

    $doctor_ratio_expression = "(
        CASE
            WHEN COALESCE(ibs.total_line_total, 0) > 0 THEN
                CASE
                    WHEN COALESCE(ibs.doctor_line_total, 0) > 0 THEN COALESCE(ibs.doctor_line_total, 0) / NULLIF(COALESCE(ibs.total_line_total, 0), 0)
                    WHEN i.dcmt_user_id = ? THEN 1
                    ELSE 0
                END
            WHEN i.dcmt_user_id = ? THEN 1
            ELSE 0
        END
    )";

    $doctor_ratio_expression_params = [(int) $doctor_filter, (int) $doctor_filter];
    $doctor_breakdown_join_params = [(int) $doctor_filter];
    if ($has_line_type_filter) {
        $doctor_breakdown_join_params[] = $type_filter;
    }

    $pending_sql = "
        SELECT COALESCE(SUM(($pending_amount_expression) * $doctor_ratio_expression), 0) as total 
        FROM dcmt_income i
        LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
        LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
        $breakdown_join
        $where_clause
    ";
    $pending_params = array_merge($doctor_ratio_expression_params, $doctor_breakdown_join_params, $base_params);
    $stmt = $dcmt_pdo->prepare($pending_sql);
    $stmt->execute($pending_params);
    $total_pending_income = $stmt->fetch()['total'];
} else {
    $pending_sql = "
        SELECT COALESCE(SUM(
            $pending_amount_expression
        ), 0) as total 
        FROM dcmt_income i
        LEFT JOIN dcmt_users u_doctor ON i.dcmt_user_id = u_doctor.dcmt_id AND u_doctor.dcmt_role = 'doctor'
        LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
        LEFT JOIN dcmt_income_payment_methods pm ON i.dcmt_payment_method_id = pm.dcmt_id
        LEFT JOIN dcmt_income_payment_status ps ON i.dcmt_payment_status_id = ps.dcmt_id
        $where_clause
    ";
    $pending_params = $base_params;
    $stmt = $dcmt_pdo->prepare($pending_sql);
    $stmt->execute($pending_params);
    $total_pending_income = $stmt->fetch()['total'];
}

$total_income_amount = (float)$total_paid_income + (float)$total_pending_income;
?>

<link href="../../assets/css/select2.min.css" rel="stylesheet">

<div class="dcmt-information-panel mb-4">
    <div class="dcmt-information-panel-title">
        <i class="fas fa-info-circle me-2" aria-hidden="true"></i><?php echo trans('income', 'income_information'); ?>
    </div>
    <ul class="dcmt-information-panel-list small mb-0">
        <li><?php echo trans('income', 'income_index_help_totals'); ?></li>
        <li><?php echo trans('income', 'income_index_help_doctor_goals'); ?></li>
        <li><?php echo trans('income', 'income_index_help_lines'); ?></li>
    </ul>
</div>

<!-- <?php echo trans('income', 'search_and_filter_form'); ?> -->
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md">
                <label for="search" class="form-label"><?php echo trans('income', 'search_and_filter'); ?></label>
                <input
                    type="text"
                    class="form-control dcmt-filter-field"
                    id="search"
                    name="search"
                    value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="<?php echo trans('income', 'search_placeholder'); ?>"
                >
            </div>
            <div class="col-md">
                <label for="type" class="form-label"><?php echo trans('income', 'line_type'); ?></label>
                <select class="form-select dcmt-filter-field" id="type" name="type">
                    <option value=""><?php echo trans('income', 'all_types'); ?></option>
                    <option value="service" <?php echo $type_filter === 'service' ? 'selected' : ''; ?>><?php echo trans('income', 'service'); ?></option>
                    <option value="product" <?php echo $type_filter === 'product' ? 'selected' : ''; ?>><?php echo trans('income', 'product'); ?></option>
                </select>
            </div>
            <div class="col-md">
                <label for="doctor" class="form-label"><?php echo trans('common', 'doctor'); ?></label>
                <select class="form-select dcmt-filter-field" id="doctor" name="doctor">
                    <?php if (!$is_limited_doctor): ?>
                        <option value=""><?php echo trans('income', 'all_doctors'); ?></option>
                    <?php endif; ?>
                    <?php foreach ($doctors as $doctor): ?>
                        <option value="<?php echo $doctor['dcmt_id']; ?>"
                                <?php echo $doctor_filter == $doctor['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doctor['dcmt_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label for="status" class="form-label"><?php echo trans('common', 'status'); ?></label>
                <select class="form-select dcmt-filter-field" id="status" name="status">
                    <option value=""><?php echo trans('income', 'all_status'); ?></option>
                    <?php foreach ($payment_statuses as $status): ?>
                        <?php 
                        // Translate payment status name
                        $status_name = $status['dcmt_name'];
                        $translated_status = trans('income_payment_status', $status_name);
                        $display_status = ($translated_status !== $status_name) ? $translated_status : $status_name;
                        ?>
                        <option value="<?php echo htmlspecialchars($status['dcmt_name']); ?>" 
                                <?php echo $status_filter === $status['dcmt_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($display_status)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label for="payment_method" class="form-label"><?php echo trans('income', 'payment_method'); ?></label>
                <select class="form-select dcmt-filter-field" id="payment_method" name="payment_method">
                    <option value=""><?php echo trans('income', 'all_payment_methods'); ?></option>
                    <?php foreach ($payment_methods as $method): ?>
                        <?php 
                        // Translate payment method name
                        $method_name = $method['dcmt_name'];
                        $translated_name = trans('income_payment_method', $method_name);
                        $display_name = ($translated_name !== $method_name) ? $translated_name : $method_name;
                        ?>
                        <option value="<?php echo htmlspecialchars($method['dcmt_name']); ?>" 
                                <?php echo $payment_method_filter === $method['dcmt_name'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(ucfirst($display_name)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md">
                <label for="date_range" class="form-label"><?php echo trans('income', 'date_range'); ?></label>
                <input type="text" class="form-control dcmt-daterange-picker dcmt-filter-field" id="date_range" name="date_range" 
                       value="<?php echo htmlspecialchars($date_range); ?>"
                       placeholder="<?php echo trans('income', 'select_date_range'); ?>"
                       readonly>
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="?clear=1" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<!-- <?php echo trans('income', 'income_records_table'); ?> -->
<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('income', 'income_records'); ?>
                </h6>
                <div class="dcmt-view-card-title-total">
                    (<?php echo trans('income', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('income', 'records'); ?>
                    | <?php echo trans('income', 'income_short'); ?>: <span style="color: #0d6efd; font-weight: 600;" id="totalIncomeHeaderValue"><?php echo dcmt_format_currency($total_income_amount); ?></span>
                    | <?php echo trans('income', 'paid_short'); ?>: <span style="color: #28a745; font-weight: 600;" id="totalPaidHeaderValue"><?php echo dcmt_format_currency($total_paid_income); ?></span>
                    | <?php echo trans('income', 'pending_short'); ?>: <span style="color: #ffc107; font-weight: 600;" id="totalPendingHeaderValue"><?php echo dcmt_format_currency($total_pending_income); ?></span>)
                </div>
            </div>
            <div class="ms-3 d-flex gap-2">
                <?php if (!($dcmt_disable_income_nav ?? false)): ?>
                    <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('income', 'add_income'); ?></a>
                <?php endif; ?>
                <a href="import.php" class="dcmt-add-form-view-all-link dcmt-hide">
                    <i class="fas fa-upload me-1"></i><?php echo trans('income', 'import_income'); ?>
                </a>
                <button type="button" class="dcmt-add-form-view-all-link dcmt-hide" onclick="exportToCSV()">
                    <i class="fas fa-download me-1"></i><?php echo trans('income', 'export_income'); ?>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($income_records)): ?>
            <div class="text-center py-4">
                <i class="fas fa-inbox fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('income', 'no_income_found'); ?></h5>
                <p class="text-muted"><?php echo trans('income', 'no_income_message'); ?></p>
                <?php if (!($dcmt_disable_income_nav ?? false)): ?>
                    <a href="add.php" class="btn btn-success">
                        <i class="fas fa-plus me-1"></i><?php echo trans('income', 'add_first_income'); ?>
                    </a>
                <?php endif; ?>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <!-- Bulk Actions Bar -->
                <div id="bulkActionsBar" class="dcmt-bulk-actions-bar mb-3" style="display: none;">
                    <div class="d-flex align-items-center justify-content-between">
                        <div class="d-flex align-items-center">
                            <span id="selectedCount" class="me-3">0 <?php echo trans('common', 'selected'); ?></span>
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="selectAll()">
                                <i class="fas fa-check-square me-1"></i><?php echo trans('common', 'select_all'); ?>
                            </button>
                            <button type="button" class="btn btn-outline-secondary btn-sm me-2" onclick="deselectAll()">
                                <i class="fas fa-square me-1"></i><?php echo trans('common', 'deselect_all'); ?>
                            </button>
                        </div>
                        <?php if ($dcmt_can_delete && $is_admin_or_doctor): ?>
                        <div>
                            <button type="button" class="btn btn-danger btn-sm" onclick="bulkDelete()">
                                <i class="fas fa-trash me-1"></i><?php echo trans('common', 'delete_selected'); ?>
                            </button>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <table class="table table-hover">
                    <thead>
                        <tr>
                            <?php if ($dcmt_can_delete && $is_admin_or_doctor): ?>
                            <th style="width: 40px;">
                                <input type="checkbox" id="selectAllCheckbox" onchange="toggleSelectAll()" class="form-check-input">
                            </th>
                            <?php endif; ?>
                            <th><?php echo trans('income', 'patient_name'); ?></th>
                            <th><?php echo trans('income', 'paid_short'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('common', 'date'); ?></th>
                            <th><?php echo trans('common', 'created_by'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($income_records as $income): ?>
                            <tr>
                                <?php if ($dcmt_can_delete && $is_admin_or_doctor): ?>
                                <td>
                                    <input type="checkbox" class="form-check-input dcmt-income-checkbox" 
                                           value="<?php echo $income['dcmt_id']; ?>" 
                                           onchange="updateBulkActions()">
                                </td>
                                <?php endif; ?>
                                <td>
                                    <?php
                                        $display_name = $income['dcmt_patient_name'] ?? '';
                                        $whatsapp_phone = $income['linked_patient_phone'] ?? '';

                                        if ($type_filter === 'service') {
                                            $pending_amount_for_reminder = isset($income['dcmt_service_pending_amount']) ? floatval($income['dcmt_service_pending_amount']) : 0;
                                        } elseif ($type_filter === 'product') {
                                            $pending_amount_for_reminder = isset($income['dcmt_product_pending_amount']) ? floatval($income['dcmt_product_pending_amount']) : 0;
                                        } else {
                                            if (($income['dcmt_type'] ?? '') === 'consultation' || ($income['dcmt_type'] ?? '') === 'product_sale') {
                                                $pending_amount_for_reminder = isset($income['dcmt_total_pending_amount']) ? floatval($income['dcmt_total_pending_amount']) : 0;
                                            } else {
                                                $pending_amount_for_reminder = isset($income['dcmt_pending_amount']) ? floatval($income['dcmt_pending_amount']) : 0;
                                            }
                                        }
                                    ?>
                                    <?php echo htmlspecialchars($display_name); ?>
                                </td>
                                <td class="income-amount-cell">
                                    <?php 
                                    $display_amount = 0;
                                    if (!empty($payment_method_filter)) {
                                        $income_id_value = (int)($income['dcmt_id'] ?? 0);
                                        if (isset($payment_method_paid_map[$income_id_value])) {
                                            $display_amount = floatval($payment_method_paid_map[$income_id_value]);
                                        } else {
                                            $has_history = !empty($income_has_payment_history[$income_id_value]);
                                            $main_payment_method_name = (string)($income['payment_method_name'] ?? '');
                                            if (!$has_history && $main_payment_method_name === $payment_method_filter) {
                                                if ($type_filter === 'service') {
                                                    $display_amount = isset($income['dcmt_service_paid_amount']) ? floatval($income['dcmt_service_paid_amount']) : 0;
                                                } elseif ($type_filter === 'product') {
                                                    $display_amount = isset($income['dcmt_product_paid_amount']) ? floatval($income['dcmt_product_paid_amount']) : 0;
                                                } else {
                                                    if (($income['dcmt_type'] ?? '') === 'consultation' || ($income['dcmt_type'] ?? '') === 'product_sale') {
                                                        $display_amount = isset($income['dcmt_total_paid_amount']) ? floatval($income['dcmt_total_paid_amount']) : 0;
                                                    } else {
                                                        $display_amount = isset($income['dcmt_paid_amount']) ? floatval($income['dcmt_paid_amount']) : 0;
                                                    }
                                                }
                                            }
                                        }
                                    } elseif ($type_filter === 'service') {
                                        $display_amount = isset($income['dcmt_service_paid_amount']) ? floatval($income['dcmt_service_paid_amount']) : 0;
                                    } elseif ($type_filter === 'product') {
                                        $display_amount = isset($income['dcmt_product_paid_amount']) ? floatval($income['dcmt_product_paid_amount']) : 0;
                                    } else {
                                        if ($income['dcmt_type'] === 'consultation' || $income['dcmt_type'] === 'product_sale') {
                                            $display_amount = isset($income['dcmt_total_paid_amount']) ? floatval($income['dcmt_total_paid_amount']) : 0;
                                        } else {
                                            $display_amount = isset($income['dcmt_paid_amount']) ? floatval($income['dcmt_paid_amount']) : 0;
                                        }
                                    }
                                    if ($apply_doctor_paid_filter) {
                                        $income_id_value = (int)($income['dcmt_id'] ?? 0);
                                        $income_doctor_id = (int)($income['dcmt_user_id'] ?? 0);
                                        $filter_doctor_id = (int)$doctor_filter;
                                        $total_line_total = $doctor_breakdown_total_map[$income_id_value] ?? 0;
                                        $doctor_line_total = $doctor_breakdown_doctor_map[$income_id_value] ?? 0;
                                        if ($total_line_total > 0) {
                                            if ($doctor_line_total > 0) {
                                                $display_amount *= ($doctor_line_total / $total_line_total);
                                            } elseif ($income_doctor_id === $filter_doctor_id) {
                                                $display_amount *= 1;
                                            } else {
                                                $display_amount = 0;
                                            }
                                        } elseif ($income_doctor_id === $filter_doctor_id) {
                                            $display_amount *= 1;
                                        } else {
                                            $display_amount = 0;
                                        }
                                    }
                                    echo dcmt_format_currency($display_amount);
                                    ?>
                                </td>
                                <td class="income-status-cell">
                                    <div class="d-flex align-items-center gap-2">
                                        <?php 
                                        // Translate payment status name for display
                                        $raw_status_name = $income['payment_status_name'] ?? $income['dcmt_payment_status'] ?? 'unknown';
                                        $translated_status = trans('income_payment_status', ucfirst($raw_status_name));
                                        $display_status = ($translated_status !== ucfirst($raw_status_name)) ? $translated_status : ucfirst($raw_status_name);
                                        
                                        $status_name = strtolower($raw_status_name);
                                        $status_color = match($status_name) {
                                            'completed' => '#28A745',
                                            'pending' => '#FFC107', 
                                            'failed' => '#DC3545',
                                            'cancelled' => '#6C757D',
                                            'refunded' => '#17A2B8',
                                            default => '#6C757D'
                                        };
                                        ?>
                                        <span class="income-status-label" style="color: <?php echo $status_color; ?>; font-weight: 500;">
                                            <?php echo htmlspecialchars($display_status); ?>
                                        </span>
                                        <?php if (strtolower($income['payment_status_name'] ?? $income['dcmt_payment_status'] ?? '') === 'pending' && ($is_admin_or_doctor || $is_staff)): ?>
                                            <button type="button" class="btn btn-sm btn-outline-success btn-mark-payment-complete" 
                                                    title="<?php echo trans('income', 'mark_as_complete'); ?>"
                                                    onclick="markPaymentComplete(<?php echo $income['dcmt_id']; ?>)">
                                                <i class="fas fa-check"></i>
                                            </button>
                                            <button type="button" class="btn btn-sm btn-outline-success btn-whatsapp-reminder"
                                                    title="<?php echo htmlspecialchars(trans('income', 'send_whatsapp_reminder')); ?>"
                                                    onclick='openPendingReminderWhatsApp(<?php echo json_encode($display_name); ?>, <?php echo json_encode(dcmt_format_currency($pending_amount_for_reminder)); ?>, <?php echo json_encode($whatsapp_phone); ?>)'>
                                                <i class="fab fa-whatsapp"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                                <td><?php echo dcmt_format_date($income['dcmt_transaction_date']); ?></td>
                                <td>
                                    <small class="text-muted">
                                        <?php echo htmlspecialchars($income['created_by_name']); ?>
                                    </small>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $income['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="<?php echo trans('common', 'view'); ?>">
                                        </a>
                                        <a href="edit.php?id=<?php echo $income['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="<?php echo trans('common', 'edit'); ?>">
                                        </a>
                                        <?php if ($dcmt_can_delete && $is_admin_or_doctor): ?>
                                        <button type="button" class="btn " title="<?php echo trans('common', 'delete'); ?>"
                                                onclick="confirmDeleteIncome(<?php echo $income['dcmt_id']; ?>)">
                                            <img src="../../assets/images/delete.svg" alt="<?php echo trans('common', 'delete'); ?>">
                                        </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                    <tfoot>
                        <tr class="table-light">
                            <td colspan="7" class="fw-bold">
                                <span class="dcmt-view-card-title-total">
                                    <?php echo trans('income', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('income', 'records'); ?>
                                    | <?php echo trans('income', 'income_short'); ?>: <span style="color: #0d6efd; font-weight: 600;" id="totalIncomeFooterValue"><?php echo dcmt_format_currency($total_income_amount); ?></span>
                                    | <?php echo trans('income', 'paid_short'); ?>: <span style="color: #28a745;" id="totalPaidFooterValue"><?php echo dcmt_format_currency($total_paid_income); ?></span>
                                    | <?php echo trans('income', 'pending_short'); ?>: <span style="color: #ffc107; font-weight: 600;" id="totalPendingFooterValue"><?php echo dcmt_format_currency($total_pending_income); ?></span>
                                </span>
                            </td>
                        </tr>
                    </tfoot>
                </table>
            </div>

            <!-- <?php echo trans('income', 'pagination'); ?> -->
            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('income', 'income_records_pagination'); ?>">
                    <ul class="pagination justify-content-center">
                        <!-- First Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                    <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Previous Page -->
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                    <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Page Numbers -->
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i == $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>
                        
                        <!-- Next Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                    <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                        
                        <!-- Last Page -->
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" title="<?php echo trans('common', 'last_page'); ?>">
                                    <?php echo trans('common', 'last_page'); ?> <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
const incomeCurrencyTemplate = '<?php echo dcmt_format_currency(0); ?>';
const completedStatusLabel = '<?php echo addslashes(trans('income_payment_status', 'Completed')); ?>';
const completedStatusColor = '#28A745';
const paymentMethodSelectPlaceholder = '<?php echo addslashes(trans('income', 'select_payment_method')); ?>';
const paymentMethodsData = <?php echo json_encode($payment_methods_for_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;

function buildPaymentMethodOptions(selectedId) {
    if (!Array.isArray(paymentMethodsData)) {
        return '';
    }
    const selectedValue = selectedId !== undefined && selectedId !== null ? String(selectedId) : '';
    return paymentMethodsData.map(method => {
        const isSelected = selectedValue && String(method.id) === selectedValue ? 'selected' : '';
        const displayName = method.display_name || method.name;
        return `<option value="${method.id}" ${isSelected}>${displayName}</option>`;
    }).join('');
}

// Pass translations to JavaScript
window.translations = {
    confirm_deletion: '<?php echo trans('income', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('income', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('income', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('income', 'cancel'); ?>',
    yes_delete: '<?php echo trans('income', 'yes_delete'); ?>'
};

// Initialize date range picker
$(document).ready(function() {
    $('#date_range').daterangepicker({
        autoUpdateInput: false,
        autoApply: true,
        locale: {
            cancelLabel: '<?php echo trans('common', 'clear'); ?>',
            applyLabel: '<?php echo trans('common', 'apply'); ?>',
            format: 'YYYY-MM-DD',
            separator: ' to ',
            customRangeLabel: '<?php echo trans('common', 'custom_range'); ?>'
        },
        ranges: {
            '<?php echo trans('common', 'today'); ?>': [moment(), moment()],
            '<?php echo trans('common', 'yesterday'); ?>': [moment().subtract(1, 'days'), moment().subtract(1, 'days')],
            '<?php echo trans('common', 'last_7_days'); ?>': [moment().subtract(6, 'days'), moment()],
            '<?php echo trans('common', 'last_30_days'); ?>': [moment().subtract(29, 'days'), moment()],
            '<?php echo trans('common', 'this_month'); ?>': [moment().startOf('month'), moment().endOf('month')],
            '<?php echo trans('common', 'last_month'); ?>': [moment().subtract(1, 'month').startOf('month'), moment().subtract(1, 'month').endOf('month')],
            '<?php echo trans('common', 'this_year'); ?>': [moment().startOf('year'), moment().endOf('year')],
            '<?php echo trans('common', 'last_year'); ?>': [moment().subtract(1, 'year').startOf('year'), moment().subtract(1, 'year').endOf('year')]
        },
        opens: 'left',
        drops: 'down'
    });

    $('#date_range').on('apply.daterangepicker', function(ev, picker) {
        $(this).val(picker.startDate.format('YYYY-MM-DD') + ' to ' + picker.endDate.format('YYYY-MM-DD'));
    });

    $('#date_range').on('cancel.daterangepicker', function(ev, picker) {
        $(this).val('');
    });

    // Set initial value if date range is already set
    <?php if (!empty($date_range)): ?>
    $('#date_range').val('<?php echo htmlspecialchars($date_range); ?>');
    <?php endif; ?>
});

function exportToCSV() {
    // <?php echo trans('income', 'get_current_filter_parameters'); ?>
    const params = new URLSearchParams(window.location.search);
    params.append('export', 'csv');
    
    // <?php echo trans('income', 'create_download_link'); ?>
    const link = document.createElement('a');
    link.href = 'export.php?' + params.toString();
    link.download = 'income_records.csv';
    document.body.appendChild(link);
    link.click();
    document.body.removeChild(link);
}

const dcmtWhatsappPendingTemplate = <?php echo json_encode(trans('income', 'whatsapp_pending_reminder_template')); ?>;
const dcmtWhatsappDefaultPatient = <?php echo json_encode(trans('income', 'whatsapp_default_patient_name')); ?>;

function openPendingReminderWhatsApp(patientName, formattedPendingAmount, patientPhone) {
    const safePatientName = patientName || dcmtWhatsappDefaultPatient;
    const safePendingAmount = formattedPendingAmount || '0.00';
    const siteName = <?php echo json_encode(dcmt_get_site_name()); ?>;
    const message = String(dcmtWhatsappPendingTemplate)
        .replace(/\{patient_name\}/g, safePatientName)
        .replace(/\{site_name\}/g, siteName)
        .replace(/\{pending_amount\}/g, safePendingAmount);
    const encodedMessage = encodeURIComponent(message);
    const digitsOnlyPhone = String(patientPhone || '').replace(/\D/g, '');
    const whatsappUrl = digitsOnlyPhone
        ? `https://wa.me/${digitsOnlyPhone}?text=${encodedMessage}`
        : `https://web.whatsapp.com/send?text=${encodedMessage}`;

    window.open(whatsappUrl, '_blank');
}

// Mark payment as complete
function markPaymentComplete(incomeId) {
    // Show loading state
    const button = event.target.closest('button');
    const originalText = button.innerHTML;
    button.innerHTML = '<i class="fas fa-spinner fa-spin"></i>';
    button.disabled = true;
    
    // Prepare form data
    const formData = new FormData();
    formData.append('income_id', incomeId);
    formData.append('csrf_token', '<?php echo dcmt_generate_csrf_token(); ?>');
    
    // First, get the pending amount
    fetch('get_pending_amount.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            // Show confirmation modal with pending amount
            showMarkCompleteModal(data);
        } else {
            // Show error message
            showAlert('danger', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        showAlert('danger', '<?php echo trans('common', 'error_occurred'); ?>');
    })
    .finally(() => {
        // Reset button state
        button.innerHTML = originalText;
        button.disabled = false;
    });
}

// Show mark complete confirmation modal
function showMarkCompleteModal(data) {
    // Remove existing modal if any
    const existingModal = document.getElementById('markCompleteModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Create modal HTML
    const paymentDateValue = data.default_payment_date || moment().format('YYYY-MM-DD');
    const selectedPaymentMethodId = data.default_payment_method_id || '';
    const paymentMethodOptions = buildPaymentMethodOptions(selectedPaymentMethodId);
    const modalHTML = `
        <div class="modal fade" id="markCompleteModal" tabindex="-1" aria-labelledby="markCompleteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-success">
                    <div class="modal-header bg-success text-white">
                        <h5 class="modal-title" id="markCompleteModalLabel">
                            <i class="fas fa-check-circle"></i> <?php echo trans('income', 'mark_payment_complete'); ?>
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        ${data.no_pending ? `
                        <div class="alert alert-warning mb-3">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle"></i> <?php echo trans('income', 'no_pending_amount'); ?>
                            </h6>
                            <p class="mb-0"><?php echo trans('income', 'no_pending_amount_message'); ?></p>
                        </div>
                        ` : `
                        <div class="alert alert-info mb-3">
                            <h6 class="alert-heading">
                                <i class="fas fa-info-circle"></i> <?php echo trans('income', 'payment_completion_info'); ?>
                            </h6>
                            <p class="mb-0"><?php echo trans('income', 'payment_completion_message'); ?></p>
                        </div>
                        `}
                        
                        <div class="row">
                            <div class="col-md-6">
                                <strong><?php echo trans('income', 'patient_name'); ?>:</strong>
                                <p class="mb-0">${data.patient_name}</p>
                            </div>
                            <div class="col-md-6">
                                <strong>${data.amount_type === 'service' ? '<?php echo trans('income', 'service_pending_amount'); ?>' : (data.amount_type === 'product' ? '<?php echo trans('income', 'product_pending_amount'); ?>' : '<?php echo trans('income', 'pending_amount'); ?>')}:</strong>
                                <p class="mb-0 ${data.no_pending ? 'text-muted' : 'text-danger'} fs-5 fw-bold">${data.formatted_pending_amount}</p>
                            </div>
                        </div>
                        
                        <div class="row mt-3">
                            <div class="col-md-6">
                                <label for="markCompletePaymentDate" class="form-label"><?php echo trans('income', 'payment_date'); ?> <span class="text-danger">*</span></label>
                                <input type="date" class="form-control" id="markCompletePaymentDate" value="${paymentDateValue}">
                                <div class="invalid-feedback" id="markCompletePaymentDateError"><?php echo trans('income', 'payment_date_required_simple'); ?></div>
                            </div>
                            <div class="col-md-6">
                                <label for="markCompletePaymentMethod" class="form-label"><?php echo trans('income', 'payment_method'); ?> <span class="text-danger">*</span></label>
                                <select class="form-select" id="markCompletePaymentMethod">
                                    <option value="">${paymentMethodSelectPlaceholder}</option>
                                    ${paymentMethodOptions}
                                </select>
                                <div class="invalid-feedback" id="markCompletePaymentMethodError"><?php echo trans('income', 'payment_method_required_simple'); ?></div>
                            </div>
                        </div>
                        
                        <div class="mt-3 p-3 bg-light rounded">
                            <h6 class="mb-2"><?php echo trans('income', 'what_will_happen'); ?>:</h6>
                            <ul class="mb-0">
                                ${data.no_pending ? `
                                <li>Payment status will be updated to completed</li>
                                <li>No amount changes will be made</li>
                                ` : `
                                <li>${data.amount_type === 'service' ? '<?php echo trans('income', 'service_pending_to_paid'); ?>' : (data.amount_type === 'product' ? '<?php echo trans('income', 'product_pending_to_paid'); ?>' : '<?php echo trans('income', 'pending_to_paid'); ?>')}</li>
                                <li><?php echo trans('income', 'status_to_completed'); ?></li>
                                `}
                                <li><?php echo trans('income', 'action_cannot_undo'); ?></li>
                            </ul>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> <?php echo trans('common', 'cancel'); ?>
                        </button>
                        <button type="button" class="btn btn-success" onclick="proceedWithMarkComplete(${data.income_id})">
                            <i class="fas fa-check"></i> <?php echo trans('income', 'yes_mark_complete'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('markCompleteModal'));
    modal.show();
    
    // Remove modal from DOM when hidden
    document.getElementById('markCompleteModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// Proceed with marking payment as complete
function proceedWithMarkComplete(incomeId) {
    const paymentDateInput = document.getElementById('markCompletePaymentDate');
    const paymentMethodSelect = document.getElementById('markCompletePaymentMethod');
    const paymentDateError = document.getElementById('markCompletePaymentDateError');
    const paymentMethodError = document.getElementById('markCompletePaymentMethodError');
    
    let hasError = false;
    if (paymentDateInput) {
        paymentDateInput.classList.remove('is-invalid');
    }
    if (paymentMethodSelect) {
        paymentMethodSelect.classList.remove('is-invalid');
    }
    if (paymentDateError) {
        paymentDateError.style.display = '';
    }
    if (paymentMethodError) {
        paymentMethodError.style.display = '';
    }
    
    const paymentDateValue = paymentDateInput ? paymentDateInput.value.trim() : '';
    const paymentMethodValue = paymentMethodSelect ? paymentMethodSelect.value.trim() : '';
    
    if (!paymentDateValue) {
        hasError = true;
        if (paymentDateInput) {
            paymentDateInput.classList.add('is-invalid');
        }
    }
    
    if (!paymentMethodValue) {
        hasError = true;
        if (paymentMethodSelect) {
            paymentMethodSelect.classList.add('is-invalid');
        }
    }
    
    if (hasError) {
        showAlert('danger', '<?php echo trans('income', 'fill_required_fields'); ?>');
        return;
    }
    
    // Hide modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('markCompleteModal'));
    if (modal) {
        modal.hide();
    }
    
    // Show loading state
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage('<?php echo trans('income', 'marking_payment_complete'); ?>...');
    }
    
    // Prepare form data
    const formData = new FormData();
    formData.append('income_id', incomeId);
    formData.append('csrf_token', '<?php echo dcmt_generate_csrf_token(); ?>');
    formData.append('payment_date', paymentDateValue);
    formData.append('payment_method_id', paymentMethodValue);
    
    // Make AJAX request to mark payment complete
    fetch('mark_payment_complete.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {
        if (!response.ok) {
            throw new Error('Network response was not ok');
        }
        return response.text();
    })
    .then(text => {
        try {
            return JSON.parse(text);
        } catch (e) {
            console.error('Invalid JSON response:', text);
            throw new Error('Invalid response from server');
        }
    })
    .then(data => {
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        
        if (data.success) {
            // Show success message
            showAlert('success', data.message);
            
            // Find the row by finding the button first
            const markCompleteButton = document.querySelector(`button[onclick="markPaymentComplete(${incomeId})"]`);
            const row = markCompleteButton ? markCompleteButton.closest('tr') : null;
            
            if (row) {
                // Update the payment status badge in the table
                const statusLabel = row.querySelector('.income-status-label');
                if (statusLabel) {
                    statusLabel.style.color = completedStatusColor;
                    statusLabel.textContent = completedStatusLabel;
                }
                
                // Remove the mark complete and WhatsApp reminder buttons (no longer pending)
                const inlineButton = row.querySelector('.btn-mark-payment-complete');
                if (inlineButton) {
                    inlineButton.remove();
                }
                const whatsappButton = row.querySelector('.btn-whatsapp-reminder');
                if (whatsappButton) {
                    whatsappButton.remove();
                }
                
                // Update the appropriate paid amount in the table based on type
                const amountCell = row.querySelector('.income-amount-cell');
                if (amountCell) {
                    const prevText = amountCell.textContent || '';
                    const parseCurrencyNumber = (val) => {
                        const n = parseFloat(String(val).replace(/[^0-9.\-]/g, ''));
                        return isNaN(n) ? 0 : n;
                    };
                    let newAmount = 0;
                    // Prefer total paid amount for display, since the table shows Total Paid for consultation/product_sale
                    if (typeof data.new_total_paid_amount !== 'undefined') {
                        newAmount = parseFloat(data.new_total_paid_amount) || 0;
                    } else if (data.amount_type === 'service' && data.new_service_paid_amount) {
                        newAmount = data.new_service_paid_amount;
                    } else if (data.amount_type === 'product' && data.new_product_paid_amount) {
                        newAmount = data.new_product_paid_amount;
                    } else if (data.new_paid_amount) {
                        newAmount = data.new_paid_amount;
                    }
                    
                    if (newAmount >= 0) {
                        amountCell.textContent = incomeCurrencyTemplate.replace('0.00', Number(newAmount).toFixed(2));
                        // Update Total Paid in header/footer if present
                        const prevAmount = parseCurrencyNumber(prevText);
                        const delta = Number(newAmount) - prevAmount;
                        if (!isNaN(delta)) {
                            const headerEl = document.getElementById('totalPaidHeaderValue');
                            if (headerEl) {
                                const currentHeader = parseCurrencyNumber(headerEl.textContent);
                                const updatedHeader = currentHeader + delta;
                                headerEl.textContent = incomeCurrencyTemplate.replace('0.00', updatedHeader.toFixed(2));
                            }
                            const footerEl = document.getElementById('totalPaidFooterValue');
                            if (footerEl) {
                                const currentFooter = parseCurrencyNumber(footerEl.textContent);
                                const updatedFooter = currentFooter + delta;
                                footerEl.textContent = incomeCurrencyTemplate.replace('0.00', updatedFooter.toFixed(2));
                            }
                        }
                    }
                }
            }
        } else {
            // Show error message
            showAlert('danger', data.message);
        }
    })
    .catch(error => {
        console.error('Error:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        showAlert('danger', '<?php echo trans('common', 'error_occurred'); ?>');
    });
}

function showAlert(type, message) {
    // Remove existing alerts
    const existingAlerts = document.querySelectorAll('.alert-dismissible');
    existingAlerts.forEach(alert => alert.remove());
    
    // Create new alert
    const alertDiv = document.createElement('div');
    alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
    alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
    
    // Insert alert at the top of the main content area
    const mainContent = document.querySelector('.main-content');
    if (mainContent) {
        const firstChild = mainContent.firstElementChild;
        if (firstChild) {
            mainContent.insertBefore(alertDiv, firstChild);
        } else {
            mainContent.appendChild(alertDiv);
        }
        
        // Auto-hide after 5 seconds
        setTimeout(() => {
            if (alertDiv.parentNode) {
                alertDiv.remove();
            }
        }, 5000);
    }
}

// Pass translations to JavaScript for income
window.translations = {
    confirm_deletion: '<?php echo trans('common', 'confirm_deletion'); ?>',
    warning: '<?php echo trans('common', 'warning'); ?>',
    delete_confirmation_message: '<?php echo trans('income', 'delete_confirmation_message') ?: trans('common', 'delete_confirmation_message'); ?>',
    cancel: '<?php echo trans('common', 'cancel'); ?>',
    yes_delete: '<?php echo trans('common', 'yes_delete'); ?>',
    income_record: '<?php echo trans('income', 'income_record'); ?>',
    income_records: '<?php echo trans('income', 'income_records'); ?>',
    confirm_delete_single: '<?php echo trans('income', 'confirm_delete_single'); ?>',
    confirm_delete_multiple: '<?php echo trans('income', 'confirm_delete_multiple'); ?>'
};

// Bulk actions bar styles are now in main.css

// Income-specific delete function
function confirmDeleteIncome(incomeId) {
    console.log('confirmDeleteIncome called with incomeId:', incomeId);
    
    // Use the main.js confirmDelete function with specific parameters
    if (typeof confirmDelete === 'function') {
        confirmDelete(incomeId, 'income_record');
    } else {
        // Fallback to direct AJAX call if main.js function is not available
        console.warn('Main.js confirmDelete not available, using direct AJAX');
        if (confirm('<?php echo trans('income', 'confirm_delete_single'); ?>')) {
            deleteIncomeDirectly(incomeId);
        }
    }
}

// Direct AJAX deletion as fallback
function deleteIncomeDirectly(incomeId) {
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    
    fetch('delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: incomeId,
            csrf_token: csrfToken
        })
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            alert('Income record deleted successfully');
            location.reload();
        } else {
            alert('<?php echo trans('income', 'failed_to_delete_record'); ?>: ' + (data.message || '<?php echo trans('income', 'unknown_error'); ?>'));
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('<?php echo trans('income', 'error_occurred_deleting'); ?>');
    });
}

// Multi-select functionality
function updateBulkActions() {
    const checkboxes = document.querySelectorAll('.dcmt-income-checkbox');
    const checkedBoxes = document.querySelectorAll('.dcmt-income-checkbox:checked');
    const bulkActionsBar = document.getElementById('bulkActionsBar');
    const selectedCount = document.getElementById('selectedCount');
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    
    const count = checkedBoxes.length;
    
    if (count > 0) {
        bulkActionsBar.style.display = 'block';
        selectedCount.textContent = count + ' <?php echo trans('common', 'selected'); ?>';
    } else {
        bulkActionsBar.style.display = 'none';
    }
    
    // Update select all checkbox state
    if (count === 0) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = false;
    } else if (count === checkboxes.length) {
        selectAllCheckbox.indeterminate = false;
        selectAllCheckbox.checked = true;
    } else {
        selectAllCheckbox.indeterminate = true;
    }
}

function toggleSelectAll() {
    const selectAllCheckbox = document.getElementById('selectAllCheckbox');
    const checkboxes = document.querySelectorAll('.dcmt-income-checkbox');
    
    checkboxes.forEach(checkbox => {
        checkbox.checked = selectAllCheckbox.checked;
    });
    
    updateBulkActions();
}

function selectAll() {
    const checkboxes = document.querySelectorAll('.dcmt-income-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = true;
    });
    updateBulkActions();
}

function deselectAll() {
    const checkboxes = document.querySelectorAll('.dcmt-income-checkbox');
    checkboxes.forEach(checkbox => {
        checkbox.checked = false;
    });
    updateBulkActions();
}

function bulkDelete() {
    const checkedBoxes = document.querySelectorAll('.dcmt-income-checkbox:checked');
    
    if (checkedBoxes.length === 0) {
        alert('<?php echo trans('income', 'please_select_one_record'); ?>');
        return;
    }
    
    const incomeIds = Array.from(checkedBoxes).map(cb => parseInt(cb.value));
    const count = incomeIds.length;
    
    // Use the main.js confirmDelete function with bulk parameters
    if (typeof confirmDelete === 'function') {
        confirmBulkDelete(incomeIds, count);
    } else {
        // Fallback confirmation
        const message = count === 1 ? 
            '<?php echo trans('income', 'confirm_delete_single'); ?>' : 
            '<?php echo trans('income', 'confirm_delete_multiple'); ?>'.replace('{count}', count);
            
        if (confirm(message)) {
            bulkDeleteAjax(incomeIds);
        }
    }
}

function confirmBulkDelete(incomeIds, count) {
    // Create custom confirmation for bulk delete
    const itemType = count === 1 ? window.translations.income_record : `${count} ${window.translations.income_records}`;
    const message = count === 1 ? 
        window.translations.confirm_delete_single :
        window.translations.confirm_delete_multiple.replace('{count}', count);
    
    // Remove existing modal if any
    const existingModal = document.getElementById('deleteConfirmationModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Get translated text
    const confirmDeletion = window.translations.confirm_deletion;
    const warning = window.translations.warning;
    const cancel = window.translations.cancel;
    const yesDelete = window.translations.yes_delete;
    
    // Create modal HTML
    const modalHTML = `
        <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteConfirmationModalLabel">
                            <i class="fas fa-exclamation-triangle"></i> ${confirmDeletion}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle"></i> ${warning}
                            </h6>
                            <p class="mb-0">${message}</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> ${cancel}
                        </button>
                        <button type="button" class="btn btn-danger" onclick="proceedWithBulkDelete([${incomeIds.join(',')}])">
                            <i class="fas fa-trash"></i> ${yesDelete}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
    modal.show();
    
    // Remove modal from DOM when hidden
    document.getElementById('deleteConfirmationModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

function proceedWithBulkDelete(incomeIds) {
    // Hide modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }
    
    bulkDeleteAjax(incomeIds);
}

function bulkDeleteAjax(incomeIds) {
    console.log('bulkDeleteAjax called with incomeIds:', incomeIds);
    
    // Show loading state
    if (typeof showLoadingMessage === 'function') {
        showLoadingMessage('Deleting income records...');
    }
    
    const csrfToken = document.querySelector('meta[name="csrf-token"]').getAttribute('content');
    
    // Make AJAX request to bulk delete endpoint
    fetch('bulk_delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            ids: incomeIds,
            csrf_token: csrfToken
        })
    })
    .then(response => {
        console.log('Response status:', response.status);
        return response.json();
    })
    .then(data => {
        console.log('Bulk delete response:', data);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        
        if (data.success) {
            // Reload to show session-based success message
            location.reload();
        } else {
            if (typeof showErrorMessage === 'function') {
                showErrorMessage(data.message || 'Failed to delete income records');
            } else {
                alert('<?php echo trans('income', 'failed_to_delete_records'); ?>: ' + (data.message || '<?php echo trans('income', 'unknown_error'); ?>'));
            }
        }
    })
    .catch(error => {
        console.error('Error deleting income records:', error);
        if (typeof hideLoadingMessage === 'function') {
            hideLoadingMessage();
        }
        if (typeof showErrorMessage === 'function') {
            showErrorMessage('<?php echo trans('income', 'error_occurred_deleting_records'); ?>');
        } else {
            alert('<?php echo trans('income', 'error_occurred_deleting_records'); ?>');
        }
    });
}
</script>

<script src="../../assets/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 on patient filter field
    if (typeof $ !== 'undefined') {
        $('#patient_id').select2({
            placeholder: '<?php echo addslashes(trans('patient_note', 'all_patients')); ?>',
            allowClear: true,
            width: '100%'
        });
        
        // Apply the reusable class to the Select2 container
        $('#patient_id').on('select2:select select2:clear', function() {
            $('#patient_id').next('.select2-container').addClass('dcmt-filter-select2');
        });
        
        // Apply class immediately after initialization
        setTimeout(function() {
            $('#patient_id').next('.select2-container').addClass('dcmt-filter-select2');
        }, 100);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
