<?php
/**
 * Dashboard - Main Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Enhanced session validation with timeout checking
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Additional user session validation
$current_user = dcmt_get_current_user();
if (!$current_user) {
    dcmt_show_message(trans('login', 'session_expired'), 'error');
    dcmt_redirect('/dental_new/auth/login.php');
    exit();
}

require_once __DIR__ . '/../../includes/appointment_functions.php';
require_once __DIR__ . '/../../includes/income_doctor_filter_totals.php';

$dashboard_role = $current_user['dcmt_role'] ?? '';
$dashboard_is_doctor = $dashboard_role === 'doctor';
$dashboard_is_owner_doctor = $dashboard_is_doctor && dcmt_is_admin();
$dashboard_is_limited_doctor = $dashboard_is_doctor && !$dashboard_is_owner_doctor;
$dashboard_show_expense_data = !$dashboard_is_limited_doctor;
$dashboard_appointment_only = in_array($dashboard_role, ['staff', 'assistant'], true);
$dashboard_show_both_tabs = in_array($dashboard_role, ['admin', 'doctor'], true);

$tab_param = isset($_GET['tab']) ? trim((string) $_GET['tab']) : '';
if ($dashboard_appointment_only) {
    $dashboard_active_tab = 'appointment';
    if ($tab_param === 'financial') {
        dcmt_redirect('index.php?tab=appointment');
        exit();
    }
} else {
    $dashboard_active_tab = ($tab_param === 'appointment') ? 'appointment' : 'financial';
}

$dashboard_appt_redirect_url = DCMT_APP_URL . '/pages/dashboard/index.php?tab=appointment';
dcmt_try_handle_appointment_dashboard_post($dcmt_pdo, $current_user, $dashboard_appt_redirect_url);

$csrf_token = dcmt_generate_csrf_token();

$dashboard_load_financial = !$dashboard_appointment_only && $dashboard_active_tab === 'financial';
$dashboard_load_appointment = $dashboard_appointment_only || $dashboard_active_tab === 'appointment';

// Get dashboard data - check for filter parameters from URL
$current_month = isset($_GET['month']) ? (int) $_GET['month'] : dcmt_get_current_month();
$current_year = isset($_GET['year']) ? (int) $_GET['year'] : dcmt_get_current_year();

// Validate month and year ranges
if ($current_month < 1 || $current_month > 12) {
    $current_month = dcmt_get_current_month();
}
if ($current_year < 2020 || $current_year > 2030) {
    $current_year = dcmt_get_current_year();
}

// Initialize variables
$monthly_income = 0;
$monthly_expenses = 0;
$net_income = 0;
$previous_month_income = 0;
$previous_month_expenses = 0;
$previous_month_net_income = 0;
$income_change_percent = 0;
$expense_change_percent = 0;
$net_income_change_percent = 0;
$low_stock_items = [];
$recent_transactions = [];
$recent_inventory = [];
$chart_data = [];
$dashboard_summary_toggle = 1; // Default to ON (1)
$appointments_today_count = 0;
$appointments_week_count = 0;

// Get user's dashboard summary toggle preference from database
try {
    $username = $current_user['dcmt_username'];
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_dashboard_summary_toggle FROM dcmt_users WHERE dcmt_username = ?");
    $stmt->execute([$username]);
    $result = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($result && isset($result['dcmt_dashboard_summary_toggle'])) {
        $dashboard_summary_toggle = (int) $result['dcmt_dashboard_summary_toggle'];
    }
} catch (Exception $e) {
    // If there's an error, use default value (ON)
    error_log("Error fetching dashboard summary toggle: " . $e->getMessage());
    $dashboard_summary_toggle = 1;
}

// Calculate previous month
$previous_month = $current_month - 1;
$previous_year = $current_year;
if ($previous_month < 1) {
    $previous_month = 12;
    $previous_year = $current_year - 1;
}

try {
    if (!$dashboard_load_financial) {
        throw new Exception('skip_financial_queries');
    }
    // Get current month income (from payment history).
    // Non-owner doctors can only see income attributed to their own user id.
    if ($dashboard_is_limited_doctor) {
        $month_start = sprintf('%04d-%02d-01', $current_year, $current_month);
        $month_end = date('Y-m-t', strtotime($month_start));
        $monthly_income = dcmt_income_doctor_period_total_like_index(
            $dcmt_pdo,
            (int) $current_user['dcmt_id'],
            $month_start,
            $month_end
        );
    } else {
        $stmt = $dcmt_pdo->prepare("
            SELECT COALESCE(SUM(dcmt_amount), 0) as total_income
            FROM dcmt_income_payment_history
            WHERE MONTH(dcmt_paid_on) = ? AND YEAR(dcmt_paid_on) = ?
        ");
        $stmt->execute([$current_month, $current_year]);
        $monthly_income = $stmt->fetch()['total_income'];
    }

    // Get previous month income (from payment history)
    if ($dashboard_is_limited_doctor) {
        $prev_month_start = sprintf('%04d-%02d-01', $previous_year, $previous_month);
        $prev_month_end = date('Y-m-t', strtotime($prev_month_start));
        $previous_month_income = dcmt_income_doctor_period_total_like_index(
            $dcmt_pdo,
            (int) $current_user['dcmt_id'],
            $prev_month_start,
            $prev_month_end
        );
    } else {
        $stmt = $dcmt_pdo->prepare("
            SELECT COALESCE(SUM(dcmt_amount), 0) as total_income
            FROM dcmt_income_payment_history
            WHERE MONTH(dcmt_paid_on) = ? AND YEAR(dcmt_paid_on) = ?
        ");
        $stmt->execute([$previous_month, $previous_year]);
        $previous_month_income = $stmt->fetch()['total_income'];
    }

    if ($dashboard_show_expense_data) {
        // Get current month expenses
        $stmt = $dcmt_pdo->prepare("
            SELECT COALESCE(SUM(dcmt_amount), 0) as total_expenses
            FROM dcmt_expenses
            WHERE MONTH(dcmt_expense_date) = ? AND YEAR(dcmt_expense_date) = ?
        ");
        $stmt->execute([$current_month, $current_year]);
        $monthly_expenses = $stmt->fetch()['total_expenses'];

        // Get previous month expenses
        $stmt = $dcmt_pdo->prepare("
            SELECT COALESCE(SUM(dcmt_amount), 0) as total_expenses
            FROM dcmt_expenses
            WHERE MONTH(dcmt_expense_date) = ? AND YEAR(dcmt_expense_date) = ?
        ");
        $stmt->execute([$previous_month, $previous_year]);
        $previous_month_expenses = $stmt->fetch()['total_expenses'];
    }

    // Calculate net income
    $net_income = $monthly_income - $monthly_expenses;
    $previous_month_net_income = $previous_month_income - $previous_month_expenses;

    // Calculate percentage changes
    if ($previous_month_income > 0) {
        $income_change_percent = (($monthly_income - $previous_month_income) / $previous_month_income) * 100;
    } else {
        $income_change_percent = $monthly_income > 0 ? 100 : 0;
    }

    if ($dashboard_show_expense_data && $previous_month_expenses > 0) {
        $expense_change_percent = (($monthly_expenses - $previous_month_expenses) / $previous_month_expenses) * 100;
    } else {
        $expense_change_percent = $dashboard_show_expense_data && $monthly_expenses > 0 ? 100 : 0;
    }

    if ($dashboard_show_expense_data && $previous_month_net_income != 0) {
        $net_income_change_percent = (($net_income - $previous_month_net_income) / abs($previous_month_net_income)) * 100;
    } else {
        $net_income_change_percent = $dashboard_show_expense_data ? ($net_income > 0 ? 100 : ($net_income < 0 ? -100 : 0)) : 0;
    }

    if ($dashboard_show_expense_data) {
        // Get low stock items
        $stmt = $dcmt_pdo->prepare("
            SELECT i.dcmt_id, i.dcmt_name, i.dcmt_quantity, i.dcmt_min_quantity, c.dcmt_name as category_name
            FROM dcmt_inventory i
            LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
            WHERE i.dcmt_quantity <= i.dcmt_min_quantity AND i.dcmt_status = 'active'
            ORDER BY i.dcmt_quantity ASC
            LIMIT 10
        ");
        $stmt->execute();
        $low_stock_items = $stmt->fetchAll();

        // Get expiring items (within next 7 days)
        $stmt = $dcmt_pdo->prepare("
            SELECT i.dcmt_id, i.dcmt_name, i.dcmt_sku, i.dcmt_expiry_date,
                   DATEDIFF(i.dcmt_expiry_date, CURDATE()) as days_until_expiry
            FROM dcmt_inventory i
            WHERE i.dcmt_expiry_date IS NOT NULL
            AND i.dcmt_expiry_date BETWEEN CURDATE() AND DATE_ADD(CURDATE(), INTERVAL 7 DAY)
            AND i.dcmt_status = 'active'
            ORDER BY i.dcmt_expiry_date ASC
            LIMIT 10
        ");
        $stmt->execute();
        $expiring_items = $stmt->fetchAll();
    }

    // Get recent transactions.
    if ($dashboard_is_limited_doctor) {
        $stmt = $dcmt_pdo->prepare("
            SELECT 'income' as type, i.dcmt_patient_name as title, p.dcmt_amount as amount, p.dcmt_paid_on as date,
                   i.dcmt_type as category, 'success' as status_class, i.dcmt_id, i.dcmt_payment_status_id as dcmt_payment_status,
                   p.dcmt_created_at as dcmt_activity_at
            FROM dcmt_income_payment_history p
            JOIN dcmt_income i ON p.dcmt_income_id = i.dcmt_id
            WHERE i.dcmt_user_id = ?
            ORDER BY dcmt_activity_at DESC
            LIMIT 10
        ");
        $stmt->execute([(int) $current_user['dcmt_id']]);
    } else {
        $stmt = $dcmt_pdo->prepare("
            SELECT 'income' as type, i.dcmt_patient_name as title, p.dcmt_amount as amount, p.dcmt_paid_on as date,
                   i.dcmt_type as category, 'success' as status_class, i.dcmt_id, i.dcmt_payment_status_id as dcmt_payment_status,
                   p.dcmt_created_at as dcmt_activity_at
            FROM dcmt_income_payment_history p
            JOIN dcmt_income i ON p.dcmt_income_id = i.dcmt_id
            UNION ALL
            SELECT 'expense' as type, e.dcmt_title as title, e.dcmt_amount as amount, e.dcmt_expense_date as date,
                   c.dcmt_name as category, 'danger' as status_class, e.dcmt_id, NULL as dcmt_payment_status,
                   GREATEST(e.dcmt_created_at, e.dcmt_updated_at) as dcmt_activity_at
            FROM dcmt_expenses e
            LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id
            ORDER BY dcmt_activity_at DESC
            LIMIT 10
        ");
        $stmt->execute();
    }
    $recent_transactions = $stmt->fetchAll();


    if ($dashboard_show_expense_data) {
        // Get recent inventory activity (added or updated) for the selected month/year
        $stmt = $dcmt_pdo->prepare("
            SELECT
                i.dcmt_name,
                i.dcmt_quantity,
                c.dcmt_name as category_name,
                i.dcmt_updated_at AS dcmt_activity_at
            FROM dcmt_inventory i
            LEFT JOIN dcmt_inventory_categories c ON i.dcmt_category_id = c.dcmt_id
            WHERE i.dcmt_status = 'active'
            AND MONTH(i.dcmt_updated_at) = ? AND YEAR(i.dcmt_updated_at) = ?
            ORDER BY i.dcmt_updated_at DESC
            LIMIT 10
        ");
        $stmt->execute([$current_month, $current_year]);
        $recent_inventory = $stmt->fetchAll();
    }

    // Get chart data for the current year (12 months)
    $chart_data = [];
    for ($month = 1; $month <= 12; $month++) {
        // Get income for this month (from payment history)
        if ($dashboard_is_limited_doctor) {
            $loop_month_start = sprintf('%04d-%02d-01', $current_year, $month);
            $loop_month_end = date('Y-m-t', strtotime($loop_month_start));
            $monthly_income_data = dcmt_income_doctor_period_total_like_index(
                $dcmt_pdo,
                (int) $current_user['dcmt_id'],
                $loop_month_start,
                $loop_month_end
            );
        } else {
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0) as total_income
                FROM dcmt_income_payment_history
                WHERE MONTH(dcmt_paid_on) = ? AND YEAR(dcmt_paid_on) = ?
            ");
            $stmt->execute([$month, $current_year]);
            $monthly_income_data = $stmt->fetch()['total_income'];
        }

        $monthly_expenses_data = 0.0;
        if ($dashboard_show_expense_data) {
            // Get expenses for this month
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0) as total_expenses
                FROM dcmt_expenses
                WHERE MONTH(dcmt_expense_date) = ? AND YEAR(dcmt_expense_date) = ?
            ");
            $stmt->execute([$month, $current_year]);
            $monthly_expenses_data = $stmt->fetch()['total_expenses'];
        }

        $chart_data[] = [
            'month' => $month,
            'income' => (float) $monthly_income_data,
            'expenses' => (float) $monthly_expenses_data
        ];
    }

    // Appointment counters (today and this week)
    $appointments_where = " WHERE dcmt_status <> 'cancelled' ";
    $appointments_params = [];

    $today_sql = "SELECT COUNT(*) FROM dcmt_appointments {$appointments_where} AND DATE(dcmt_start_at) = CURDATE()";
    $today_stmt = $dcmt_pdo->prepare($today_sql);
    $today_stmt->execute($appointments_params);
    $appointments_today_count = (int)$today_stmt->fetchColumn();

    $week_sql = "SELECT COUNT(*) FROM dcmt_appointments {$appointments_where} AND YEARWEEK(dcmt_start_at, 1) = YEARWEEK(CURDATE(), 1)";
    $week_stmt = $dcmt_pdo->prepare($week_sql);
    $week_stmt->execute($appointments_params);
    $appointments_week_count = (int)$week_stmt->fetchColumn();

} catch (Exception $e) {
    if ($e->getMessage() !== 'skip_financial_queries') {
        error_log("Dashboard data fetch error: " . $e->getMessage());
    }
}

$appointments = [];
$doctors = [];
$appointment_status_counts = ['scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
$appointment_period_counts = ['today' => 0, 'week' => 0, 'month' => 0];
if ($dashboard_load_appointment) {
    $doctor_id = (int) ($_GET['doctor_id'] ?? 0);
    $can_manage = in_array($dashboard_role, ['admin', 'staff', 'assistant'], true);
    $is_doctor = false;

    try {
        $where = "WHERE DATE(a.dcmt_start_at) = CURDATE()
            AND a.dcmt_status NOT IN ('completed', 'cancelled', 'no_show')";
        $params = [];
        if ($is_doctor) {
            $where .= " AND a.dcmt_doctor_id = ?";
            $params[] = (int) $current_user['dcmt_id'];
        } elseif ($doctor_id > 0) {
            $where .= " AND a.dcmt_doctor_id = ?";
            $params[] = $doctor_id;
        }

        $stmt = $dcmt_pdo->prepare("
        SELECT
            a.dcmt_id,
            a.dcmt_patient_id,
            a.dcmt_doctor_id,
            a.dcmt_start_at,
            a.dcmt_end_at,
            a.dcmt_actual_start_at,
            a.dcmt_actual_end_at,
            a.dcmt_status,
            a.dcmt_reason,
            p.dcmt_patient_name,
            p.dcmt_phone,
            d.dcmt_full_name AS doctor_name,
            COALESCE(d.dcmt_color_code, '') AS doctor_color
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        {$where}
        ORDER BY a.dcmt_start_at ASC
        LIMIT 5
    ");
        $stmt->execute($params);
        $appointments = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $status_where = "WHERE DATE(a.dcmt_start_at) = CURDATE()";
        $status_params = [];
        if ($is_doctor) {
            $status_where .= " AND a.dcmt_doctor_id = ?";
            $status_params[] = (int)$current_user['dcmt_id'];
        } elseif ($doctor_id > 0) {
            $status_where .= " AND a.dcmt_doctor_id = ?";
            $status_params[] = $doctor_id;
        }

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
            if (array_key_exists($status_key, $appointment_status_counts)) {
                $appointment_status_counts[$status_key] = (int)$status_row['total_count'];
            }
        }

        $period_where = "WHERE 1=1";
        $period_params = [];
        if ($is_doctor) {
            $period_where .= " AND a.dcmt_doctor_id = ?";
            $period_params[] = (int)$current_user['dcmt_id'];
        } elseif ($doctor_id > 0) {
            $period_where .= " AND a.dcmt_doctor_id = ?";
            $period_params[] = $doctor_id;
        }

        $period_stmt = $dcmt_pdo->prepare("
            SELECT
                SUM(CASE WHEN DATE(a.dcmt_start_at) = CURDATE() THEN 1 ELSE 0 END) AS today_count,
                SUM(CASE WHEN YEARWEEK(a.dcmt_start_at, 1) = YEARWEEK(CURDATE(), 1) THEN 1 ELSE 0 END) AS week_count,
                SUM(CASE WHEN YEAR(a.dcmt_start_at) = YEAR(CURDATE()) AND MONTH(a.dcmt_start_at) = MONTH(CURDATE()) THEN 1 ELSE 0 END) AS month_count
            FROM dcmt_appointments a
            {$period_where}
        ");
        $period_stmt->execute($period_params);
        $period_row = $period_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $appointment_period_counts = [
            'today' => (int)($period_row['today_count'] ?? 0),
            'week' => (int)($period_row['week_count'] ?? 0),
            'month' => (int)($period_row['month_count'] ?? 0),
        ];

        if (!$is_doctor) {
            $doctor_stmt = $dcmt_pdo->query("
            SELECT dcmt_id, dcmt_full_name
            FROM dcmt_users
            WHERE dcmt_role = 'doctor' AND dcmt_status = 'active'
            ORDER BY dcmt_full_name ASC
        ");
            $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);
        }
    } catch (PDOException $e) {
        error_log('Main dashboard appointment panel load error: ' . $e->getMessage());
    }
}

$dashboard_url_financial = 'index.php?' . http_build_query([
    'tab' => 'financial',
    'month' => $current_month,
    'year' => $current_year,
]);
$dashboard_url_appointment = 'index.php?' . http_build_query(['tab' => 'appointment']);

require_once __DIR__ . '/../../includes/header.php';
?>


<?php if ($dashboard_show_both_tabs): ?>
<style>
.dcmt-main-dashboard-tab-section .dcmt-main-dashboard-tab-list {
    display: inline-flex;
    flex-wrap: wrap;
    align-items: center;
    gap: 0.35rem;
    padding: 0;
    margin: 0;
    list-style: none;
    width: fit-content;
    max-width: 100%;
}
.dcmt-main-dashboard-tab-section .dcmt-main-dashboard-tab-link {
    display: inline-block;
    border-radius: 999px;
    padding: 0.5rem 1.25rem;
    font-weight: 600;
    font-size: 0.9rem;
    color: #5c636a;
    text-decoration: none;
    border: 1px solid transparent;
    transition: background-color 0.15s ease, color 0.15s ease, box-shadow 0.15s ease, border-color 0.15s ease;
}
.dcmt-main-dashboard-tab-section .dcmt-main-dashboard-tab-link:hover {
    color: #2c3e50;
    background: rgba(0, 0, 0, 0.04);
}
.dcmt-main-dashboard-tab-section .dcmt-main-dashboard-tab-link.dcmt-main-dashboard-tab-link--active {
    color: #0d6efd;
    background: #fff;
    border-color: #dee2e6;
    box-shadow: 0 1px 4px rgba(13, 110, 253, 0.12);
}
</style>
<nav class="dcmt-main-dashboard-tab-section mb-4" aria-label="<?php echo htmlspecialchars(trans('dashboard', 'title')); ?>">
    <ul class="dcmt-main-dashboard-tab-list" role="tablist">
        <li class="dcmt-main-dashboard-tab-item" role="presentation">
            <a class="dcmt-main-dashboard-tab-link<?php echo $dashboard_active_tab === 'financial' ? ' dcmt-main-dashboard-tab-link--active' : ''; ?>" href="<?php echo htmlspecialchars($dashboard_url_financial); ?>">
                <?php echo trans('dashboard', 'tab_financial'); ?>
            </a>
        </li>
        <li class="dcmt-main-dashboard-tab-item" role="presentation">
            <a class="dcmt-main-dashboard-tab-link<?php echo $dashboard_active_tab === 'appointment' ? ' dcmt-main-dashboard-tab-link--active' : ''; ?>" href="<?php echo htmlspecialchars($dashboard_url_appointment); ?>">
                <?php echo trans('appointment', 'appointment_dashboard'); ?>
            </a>
        </li>
    </ul>
</nav>
<?php elseif ($dashboard_appointment_only): ?>
<div class="mb-3">
    <h5 class="mb-0 text-body"><?php echo trans('appointment', 'appointment_dashboard'); ?></h5>
</div>
<?php endif; ?>

<?php if ($dashboard_load_financial): ?>
<!-- Financial Summary Section with Filter -->
<div class="row mb-4">
    <div class="col-12">
        <div class="card dcmt-summary-section-card">
            <div class="card-body">
                <!-- Monthly Filter -->
                <div class="dcmt-summary-filter-controls mb-1">
                    <div class="dcmt-chart-filters d-flex gap-2 align-items-center">
                        <select class="form-select form-select-sm dcmt-chart-filter" id="filterMonth" name="month"
                            style="width: 100px;">
                            <?php
                            $month_keys = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
                            for ($i = 1; $i <= 12; $i++):
                                ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == $current_month ? 'selected' : ''; ?>>
                                    <?php echo trans('dashboard', $month_keys[$i - 1]); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select form-select-sm dcmt-chart-filter" id="filterYear" name="year"
                            style="width: 80px;">
                            <?php for ($year = date('Y') - 2; $year <= date('Y') + 1; $year++): ?>
                                <option value="<?php echo $year; ?>" <?php echo $year == $current_year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                    <div class="dcmt-summary-toggle-container">
                        <label class="dcmt-toggle-switch">
                            <input type="checkbox" id="dcmt_summary_toggle" <?php echo $dashboard_summary_toggle == 1 ? 'checked' : ''; ?>>
                            <span class="dcmt-toggle-slider"></span>
                        </label>
                    </div>
                </div>

                <!-- Summary Cards Row -->
                <div class="row" id="dcmt_summary_cards_row">
                    <div class="col-xl-3 col-md-6 mb-4 mb-xl-0">
                        <div class="dcmt-summary-column dcmt-income-column">
                            <div class="dcmt-summary-content">
                                <div class="dcmt-summary-title"><?php echo trans('dashboard', 'monthly_income'); ?>
                                </div>
                                <div class="dcmt-summary-amount-row">
                                    <div class="dcmt-summary-amount">
                                        <span class="currency"><?php echo dcmt_get_current_currency(); ?></span>
                                        <span class="amount" data-original-value="<?php echo number_format($monthly_income, 0); ?>"><?php echo number_format($monthly_income, 0); ?></span>
                                    </div>
                                    <div
                                        class="dcmt-summary-change <?php echo $income_change_percent >= 0 ? 'positive' : 'negative'; ?>">
                                        <i
                                            class="fas fa-arrow-<?php echo $income_change_percent >= 0 ? 'up' : 'down'; ?>"></i>
                                        <span><?php echo number_format(abs($income_change_percent), 1); ?>%</span>
                                    </div>
                                </div>
                                <div class="dcmt-summary-description">
                                    <?php echo trans('dashboard', 'current_month_earnings'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4 mb-xl-0">
                        <div class="dcmt-summary-column dcmt-expense-column">
                            <div class="dcmt-summary-content">
                                <div class="dcmt-summary-title"><?php echo trans('dashboard', 'monthly_expenses'); ?>
                                </div>
                                <div class="dcmt-summary-amount-row">
                                    <div class="dcmt-summary-amount">
                                        <?php if ($dashboard_is_limited_doctor): ?>
                                            <span class="amount" data-original-value="******">******</span>
                                        <?php else: ?>
                                            <span class="currency"><?php echo dcmt_get_current_currency(); ?></span>
                                            <span class="amount" data-original-value="<?php echo number_format($monthly_expenses, 0); ?>"><?php echo number_format($monthly_expenses, 0); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div
                                        class="dcmt-summary-change <?php echo $expense_change_percent >= 0 ? 'negative' : 'positive'; ?>">
                                        <?php if ($dashboard_is_limited_doctor): ?>
                                            <span>--</span>
                                        <?php else: ?>
                                            <i
                                                class="fas fa-arrow-<?php echo $expense_change_percent >= 0 ? 'up' : 'down'; ?>"></i>
                                            <span><?php echo number_format(abs($expense_change_percent), 1); ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="dcmt-summary-description">
                                    <?php echo trans('dashboard', 'current_month_expense'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6 mb-4 mb-xl-0">
                        <div class="dcmt-summary-column dcmt-net-income-column">
                            <div class="dcmt-summary-content">
                                <div class="dcmt-summary-title"><?php echo trans('dashboard', 'net_income'); ?></div>
                                <div class="dcmt-summary-amount-row">
                                    <div class="dcmt-summary-amount">
                                        <?php if ($dashboard_is_limited_doctor): ?>
                                            <span class="amount" data-original-value="******">******</span>
                                        <?php else: ?>
                                            <span class="currency"><?php echo dcmt_get_current_currency(); ?></span>
                                            <span class="amount" data-original-value="<?php echo number_format($net_income, 0); ?>"><?php echo number_format($net_income, 0); ?></span>
                                        <?php endif; ?>
                                    </div>
                                    <div
                                        class="dcmt-summary-change <?php echo $net_income_change_percent >= 0 ? 'positive' : 'negative'; ?>">
                                        <?php if ($dashboard_is_limited_doctor): ?>
                                            <span>--</span>
                                        <?php else: ?>
                                            <i
                                                class="fas fa-arrow-<?php echo $net_income_change_percent >= 0 ? 'up' : 'down'; ?>"></i>
                                            <span><?php echo number_format(abs($net_income_change_percent), 1); ?>%</span>
                                        <?php endif; ?>
                                    </div>
                                </div>
                                <div class="dcmt-summary-description">
                                    <?php echo trans('dashboard', 'profit_this_month'); ?>
                                </div>
                            </div>
                        </div>
                    </div>

                    <div class="col-xl-3 col-md-6">
                        <div class="dcmt-summary-column dcmt-quick-actions-column">
                            <div class="dcmt-summary-content dcmt-quick-actions-wrapper">
                                <div class="dcmt-quick-actions">
                                    <a href="../income/add.php" class="btn btn-success btn-sm">
                                        <i
                                            class="fas fa-plus me-1"></i><?php echo trans('dashboard', 'add_income_button'); ?>
                                    </a>
                                    <?php if (!$dashboard_is_limited_doctor): ?>
                                        <a href="../expenses/add.php" class="btn btn-danger btn-sm">
                                            <i
                                                class="fas fa-plus me-1"></i><?php echo trans('dashboard', 'add_expense_button'); ?>
                                        </a>
                                    <?php endif; ?>
                                </div>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<!-- Appointment Counters -->
<div class="row mb-4">
    <div class="col-xl-6 col-md-6 mb-3 mb-xl-0">
        <div class="card">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted"><?php echo trans('dashboard', 'appointments_today'); ?></div>
                    <h4 class="mb-0"><?php echo number_format($appointments_today_count); ?></h4>
                </div>
                <i class="fas fa-calendar-day fa-2x text-primary"></i>
            </div>
        </div>
    </div>
    <div class="col-xl-6 col-md-6">
        <div class="card">
            <div class="card-body d-flex justify-content-between align-items-center">
                <div>
                    <div class="text-muted"><?php echo trans('dashboard', 'appointments_this_week'); ?></div>
                    <h4 class="mb-0"><?php echo number_format($appointments_week_count); ?></h4>
                </div>
                <i class="fas fa-calendar-week fa-2x text-success"></i>
            </div>
        </div>
    </div>
</div>

<!-- Dashboard Content Row -->
<div class="row mb-4">
    <!-- Left Column -->
    <div class="col-xl-6 col-lg-6">
        <!-- Income Expense Chart -->
        <div class="card chart-card mb-4">
            <div class="card-header">
                <div class="d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">
                        <i class="fas fa-chart-line me-2"></i><?php echo $dashboard_is_limited_doctor ? trans('dashboard', 'monthly_income') : trans('dashboard', 'income_expense_chart'); ?>
                    </h6>
                    <div class="chart-filters d-flex gap-2">
                        <select class="form-select form-select-sm chart-filter" id="chartPeriod" style="width: 100px;">
                            <option value="yearly"><?php echo trans('dashboard', 'yearly'); ?></option>
                            <option value="monthly"><?php echo trans('dashboard', 'monthly'); ?></option>
                            <option value="weekly" selected><?php echo trans('dashboard', 'weekly'); ?></option>
                            <option value="daily"><?php echo trans('dashboard', 'daily'); ?></option>
                        </select>
                        <select class="form-select form-select-sm chart-filter" id="chartYear" style="width: 80px;">
                            <?php for ($year = date('Y'); $year >= date('Y') - 4; $year--): ?>
                                <option value="<?php echo $year; ?>" <?php echo $year == $current_year ? 'selected' : ''; ?>>
                                    <?php echo $year; ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                        <select class="form-select form-select-sm chart-filter" id="chartMonth"
                            style="width: 100px; display: none;">
                            <?php
                            $month_keys = ['jan', 'feb', 'mar', 'apr', 'may', 'jun', 'jul', 'aug', 'sep', 'oct', 'nov', 'dec'];
                            for ($i = 1; $i <= 12; $i++):
                                ?>
                                <option value="<?php echo $i; ?>" <?php echo $i == $current_month ? 'selected' : ''; ?>>
                                    <?php echo trans('dashboard', $month_keys[$i - 1]); ?>
                                </option>
                            <?php endfor; ?>
                        </select>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <canvas id="incomeExpenseChart" width="400" height="200"></canvas>
            </div>
        </div>

        <?php if (!$dashboard_is_limited_doctor): ?>
        <!-- Low Stock Alerts -->
        <div class="card low-stock-alerts-card mb-4">
            <div class="card-header">
                <h6 class="card-title">
                    <img src="../../assets/images/alert.svg" alt="Low Stock Alerts" class="me-2">
                    <?php echo trans('dashboard', 'low_stock_alerts'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($low_stock_items)): ?>
                    <p class="text-success mb-0">
                        <i
                            class="fas fa-check-circle me-2"></i><?php echo trans('dashboard', 'all_items_above_threshold'); ?>
                    </p>
                <?php else: ?>
                    <div class="low-stock-list">
                        <?php foreach ($low_stock_items as $item): ?>
                            <?php if ($dashboard_is_limited_doctor): ?>
                                <div class="low-stock-item">
                                    <div class="item-info">
                                        <div class="item-name"><?php echo htmlspecialchars($item['dcmt_name']); ?></div>
                                        <div class="item-stock"><?php echo trans('dashboard', 'stock'); ?>:
                                            <?php echo $item['dcmt_quantity']; ?> / <?php echo trans('dashboard', 'min'); ?>:
                                            <?php echo $item['dcmt_min_quantity']; ?>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="../inventory/edit.php?id=<?php echo $item['dcmt_id']; ?>"
                                    class="low-stock-item clickable-item">
                                    <div class="item-info">
                                        <div class="item-name"><?php echo htmlspecialchars($item['dcmt_name']); ?></div>
                                        <div class="item-stock"><?php echo trans('dashboard', 'stock'); ?>:
                                            <?php echo $item['dcmt_quantity']; ?> / <?php echo trans('dashboard', 'min'); ?>:
                                            <?php echo $item['dcmt_min_quantity']; ?>
                                        </div>
                                    </div>
                                    <i class="fas fa-edit edit-icon"></i>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Expiring Items -->
        <div class="card expiring-items-card">
            <div class="card-header">
                <h6 class="card-title">
                    <i class="fas fa-clock me-2"></i> <?php echo trans('dashboard', 'expiring_items'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($expiring_items)): ?>
                    <p class="text-success mb-0">
                        <i class="fas fa-check-circle me-2"></i><?php echo trans('dashboard', 'no_expiring_items'); ?>
                    </p>
                <?php else: ?>
                    <div class="expiring-items-list">
                        <?php foreach ($expiring_items as $item): ?>
                            <?php if ($dashboard_is_limited_doctor): ?>
                                <div class="expiring-item">
                                    <div class="item-info">
                                        <div class="item-name"><?php echo htmlspecialchars($item['dcmt_name']); ?></div>
                                        <div class="item-expiry">
                                            <?php
                                            $expiry_date = new DateTime($item['dcmt_expiry_date']);
                                            $days_left = $item['days_until_expiry'];

                                            if ($days_left == 0) {
                                                echo '<span class="text-danger">' . trans('dashboard', 'expires_today') . '</span>';
                                            } elseif ($days_left == 1) {
                                                echo '<span class="text-warning">' . trans('dashboard', 'expires_tomorrow') . '</span>';
                                            } else {
                                                echo '<span class="text-warning">' . $days_left . ' ' . trans('dashboard', 'days_left') . '</span>';
                                            }
                                            ?>
                                            <small class="text-muted">(<?php echo $expiry_date->format('M d, Y'); ?>)</small>
                                        </div>
                                    </div>
                                </div>
                            <?php else: ?>
                                <a href="../inventory/view.php?id=<?php echo $item['dcmt_id']; ?>"
                                    class="expiring-item clickable-item">
                                    <div class="item-info">
                                        <div class="item-name"><?php echo htmlspecialchars($item['dcmt_name']); ?></div>
                                        <div class="item-expiry">
                                            <?php
                                            $expiry_date = new DateTime($item['dcmt_expiry_date']);
                                            $days_left = $item['days_until_expiry'];

                                            if ($days_left == 0) {
                                                echo '<span class="text-danger">' . trans('dashboard', 'expires_today') . '</span>';
                                            } elseif ($days_left == 1) {
                                                echo '<span class="text-warning">' . trans('dashboard', 'expires_tomorrow') . '</span>';
                                            } else {
                                                echo '<span class="text-warning">' . $days_left . ' ' . trans('dashboard', 'days_left') . '</span>';
                                            }
                                            ?>
                                            <small class="text-muted">(<?php echo $expiry_date->format('M d, Y'); ?>)</small>
                                        </div>
                                    </div>
                                    <i class="fas fa-eye view-icon"></i>
                                </a>
                            <?php endif; ?>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <!-- Right Column -->
    <div class="col-xl-6 col-lg-6">
        <!-- Recent Transactions -->
        <div class="card recent-transactions-card">
            <div class="card-header">
                <h6 class="card-title">
                    <?php echo trans('dashboard', 'recent_transactions'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($recent_transactions)): ?>
                    <p class="text-muted mb-0"><?php echo trans('dashboard', 'no_recent_transactions'); ?></p>
                <?php else: ?>
                    <div class="transactions-list">
                        <?php foreach ($recent_transactions as $transaction): ?>
                            <a href="../<?php echo $transaction['type'] === 'expense' ? 'expenses' : $transaction['type']; ?>/view.php?id=<?php echo $transaction['dcmt_id']; ?>"
                                class="transaction-item clickable-transaction">
                                <div class="transaction-info">
                                    <div class="transaction-title"><?php echo htmlspecialchars($transaction['title']); ?></div>
                                    <div class="transaction-category-date">
                                        <?php echo ucfirst(trans('dashboard', $transaction['type'])); ?> /
                                        <?php echo dcmt_format_date($transaction['date']); ?>
                                    </div>
                                </div>
                                <div
                                    class="transaction-amount <?php echo $transaction['type'] === 'income' ? 'positive' : 'negative'; ?>">
                                    <span class="currency"><?php echo dcmt_get_site_setting('currency_type', 'USD'); ?></span>
                                    <span class="amount"><?php echo number_format($transaction['amount'], 2); ?></span>
                                </div>
                            </a>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>


<?php if (!$dashboard_is_limited_doctor): ?>
<!-- Recent Inventory Additions -->
<div class="row mb-4">
    <div class="col-xl-12">
        <div class="card recent-inventory-card">
            <div class="card-header">
                <h6 class="card-title">
                    <img src="../../assets/images/inventory-management.svg" alt="Inventory" class="me-2">
                    <?php echo trans('dashboard', 'recent_inventory_additions'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (empty($recent_inventory)): ?>
                    <p class="text-muted mb-0"><?php echo trans('dashboard', 'no_recent_inventory'); ?></p>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table">
                            <thead>
                                <tr>
                                    <th><?php echo trans('dashboard', 'item_name'); ?></th>
                                    <th><?php echo trans('dashboard', 'category'); ?></th>
                                    <th><?php echo trans('dashboard', 'quantity'); ?></th>
                                    <th><?php echo trans('dashboard', 'added_date'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($recent_inventory as $item): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars($item['dcmt_name']); ?></td>
                                        <td><?php echo htmlspecialchars($item['category_name'] ?? trans('dashboard', 'no_category')); ?>
                                        </td>
                                        <td><?php echo $item['dcmt_quantity']; ?></td>
                                        <td><?php echo dcmt_format_date($item['dcmt_activity_at']); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>
<?php endif; ?>

<?php if ($dashboard_load_appointment): ?>
<?php require __DIR__ . '/../appointments/dashboard_panel.inc.php'; ?>
<?php endif; ?>

<?php if ($dashboard_load_financial): ?>
<script>
    // Chart.js for Income Expense Chart
    document.addEventListener('DOMContentLoaded', function () {
        // Initialize Income Expense Chart
        const ctx = document.getElementById('incomeExpenseChart').getContext('2d');

        // Real data from database
        const chartData = {
            labels: ['<?php echo trans('dashboard', 'jan'); ?>', '<?php echo trans('dashboard', 'feb'); ?>', '<?php echo trans('dashboard', 'mar'); ?>', '<?php echo trans('dashboard', 'apr'); ?>', '<?php echo trans('dashboard', 'may'); ?>', '<?php echo trans('dashboard', 'jun'); ?>', '<?php echo trans('dashboard', 'jul'); ?>', '<?php echo trans('dashboard', 'aug'); ?>', '<?php echo trans('dashboard', 'sep'); ?>', '<?php echo trans('dashboard', 'oct'); ?>', '<?php echo trans('dashboard', 'nov'); ?>', '<?php echo trans('dashboard', 'dec'); ?>'],
            datasets: [{
                label: '<?php echo trans('dashboard', 'income'); ?>',
                data: <?php echo json_encode(array_column($chart_data, 'income')); ?>,
                borderColor: '#28a745',
                backgroundColor: 'rgba(40, 167, 69, 0.1)',
                tension: 0.4,
                fill: true
            }<?php if (!$dashboard_is_limited_doctor): ?>, {
                label: '<?php echo trans('dashboard', 'expenses'); ?>',
                data: <?php echo json_encode(array_column($chart_data, 'expenses')); ?>,
                borderColor: '#dc3545',
                backgroundColor: 'rgba(220, 53, 69, 0.1)',
                tension: 0.4,
                fill: true
            }<?php endif; ?>]
        };

        const chart = new Chart(ctx, {
            type: 'line',
            data: chartData,
            options: {
                responsive: true,
                maintainAspectRatio: false,
                plugins: {
                    legend: {
                        position: 'top',
                    }
                },
                scales: {
                    y: {
                        beginAtZero: true,
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    },
                    x: {
                        grid: {
                            color: 'rgba(0,0,0,0.1)'
                        }
                    }
                }
            }
        });


        // Month/Year selector functionality
        const monthSelect = document.getElementById('filterMonth');
        const yearSelect = document.getElementById('filterYear');

        function updateDashboard() {
            const month = monthSelect.value;
            const year = yearSelect.value;
            window.location.href = `?month=${month}&year=${year}&tab=financial`;
        }

        if (monthSelect) {
            monthSelect.addEventListener('change', updateDashboard);
        }
        if (yearSelect) {
            yearSelect.addEventListener('change', updateDashboard);
        }

        // Summary toggle functionality
        const dcmt_summary_toggle = document.getElementById('dcmt_summary_toggle');
        const dcmt_summary_cards_row = document.getElementById('dcmt_summary_cards_row');

        if (dcmt_summary_toggle && dcmt_summary_cards_row) {
            function dcmt_update_summary_visibility() {
                // Ensure the row is always visible
                dcmt_summary_cards_row.style.display = 'flex';
                
                // Get all amount elements that have data-original-value attribute
                const amountElements = document.querySelectorAll('.dcmt-summary-amount .amount[data-original-value]');
                
                amountElements.forEach(element => {
                    if (dcmt_summary_toggle.checked) {
                        // Show original value
                        element.textContent = element.getAttribute('data-original-value');
                    } else {
                        // Hide value with placeholder like internet banking
                        element.textContent = '******';
                    }
                });
            }

            // Initialize visibility on page load based on checkbox state
            dcmt_update_summary_visibility();

            // Save toggle state to database when changed
            dcmt_summary_toggle.addEventListener('change', function () {
                dcmt_update_summary_visibility();

                // Save toggle state to database via AJAX
                const toggle_state = dcmt_summary_toggle.checked ? 1 : 0;

                fetch('summary_toggle.php', {
                    method: 'POST',
                    headers: {
                        'Content-Type': 'application/json',
                    },
                    body: JSON.stringify({
                        toggle_state: toggle_state
                    })
                })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            console.log('Dashboard summary toggle state saved successfully');
                        } else {
                            console.error('Error saving toggle state:', data.error);
                        }
                    })
                    .catch(error => {
                        console.error('Error saving toggle state:', error);
                    });
            });
        }

        // Chart filter functionality
        const chartPeriodSelect = document.getElementById('chartPeriod');
        const chartYearSelect = document.getElementById('chartYear');
        const chartMonthSelect = document.getElementById('chartMonth');

        // Initialize filter controls on page load
        toggleFilterControls(chartPeriodSelect.value);

        // Load weekly data for current month by default
        updateChartData();

        // Handle period type change
        if (chartPeriodSelect) {
            chartPeriodSelect.addEventListener('change', function () {
                const period = this.value;
                toggleFilterControls(period);
                updateChartData();
            });
        }

        // Handle year change
        if (chartYearSelect) {
            chartYearSelect.addEventListener('change', function () {
                updateChartData();
            });
        }

        // Handle month change
        if (chartMonthSelect) {
            chartMonthSelect.addEventListener('change', function () {
                updateChartData();
            });
        }

        // Function to toggle filter controls based on period type
        function toggleFilterControls(period) {
            if (period === 'yearly') {
                // For yearly: only show chartPeriod (no year/month selectors needed)
                chartYearSelect.style.display = 'none';
                chartMonthSelect.style.display = 'none';
            } else if (period === 'monthly') {
                // For monthly: show chartPeriod and chartYear
                chartYearSelect.style.display = 'block';
                chartMonthSelect.style.display = 'none';
            } else if (period === 'weekly') {
                // For weekly: show chartPeriod, chartYear and chartMonth
                chartYearSelect.style.display = 'block';
                chartMonthSelect.style.display = 'block';
            } else if (period === 'daily') {
                // For daily: show chartPeriod, chartYear and chartMonth
                chartYearSelect.style.display = 'block';
                chartMonthSelect.style.display = 'block';
            }
        }

        // Function to update chart data via AJAX
        function updateChartData() {
            // Show loading state
            const canvas = document.getElementById('incomeExpenseChart');
            const ctx = canvas.getContext('2d');

            // Create a simple loading indicator
            ctx.clearRect(0, 0, canvas.width, canvas.height);
            ctx.font = '16px Arial';
            ctx.fillStyle = '#666';
            ctx.textAlign = 'center';
            ctx.fillText('Loading...', canvas.width / 2, canvas.height / 2);

            // Get current filter values
            const period = chartPeriodSelect.value;
            const year = chartYearSelect.value;
            const month = chartMonthSelect.value;

            // Build query parameters
            let queryParams = `period=${period}`;
            if (period === 'monthly') {
                queryParams += `&year=${year}`;
            } else if (period === 'weekly' || period === 'daily') {
                queryParams += `&year=${year}&month=${month}`;
            }
            // For yearly period, no additional parameters needed

            // Fetch new data
            fetch(`chart_data.php?${queryParams}`)
                .then(response => {
                    if (!response.ok) {
                        throw new Error(`HTTP error! status: ${response.status}`);
                    }
                    return response.json();
                })
                .then(data => {
                    console.log('Chart data received:', data);

                    // Validate data before updating chart
                    if (data.income && data.expenses && data.labels) {
                        // Update chart data and labels
                        chart.data.datasets[0].data = data.income;
                        if (chart.data.datasets.length > 1 && Array.isArray(data.expenses)) {
                            chart.data.datasets[1].data = data.expenses;
                        }
                        chart.data.labels = data.labels;
                        chart.update();

                        console.log('Chart updated successfully for period:', data.period);
                        if (data.debug) {
                            console.log('Debug info:', data.debug);
                        }
                    } else {
                        console.error('Invalid data structure received:', data);
                        alert('Error: Invalid chart data received');
                    }
                })
                .catch(error => {
                    console.error('Error fetching chart data:', error);

                    // Show user-friendly error message
                    ctx.clearRect(0, 0, canvas.width, canvas.height);
                    ctx.font = '14px Roboto, Arial';
                    ctx.fillStyle = '#dc3545';
                    ctx.textAlign = 'center';
                    ctx.fillText('Error loading chart data', canvas.width / 2, canvas.height / 2 - 10);
                    ctx.fillStyle = '#6c757d';
                    ctx.font = '12px Roboto, Arial';
                    ctx.fillText('Please check console for details', canvas.width / 2, canvas.height / 2 + 10);
                });
        }
    });

    function refreshDashboard() {
        location.reload();
    }

    function resetToCurrentMonth() {
        window.location.href = window.location.pathname + '?tab=financial';
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
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
