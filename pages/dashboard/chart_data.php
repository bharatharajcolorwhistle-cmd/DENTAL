<?php
/**
 * Chart Data API Endpoint
 * Returns JSON data for Income Expense Chart
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Enhanced session validation
if (!dcmt_validate_session()) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit();
}

// Get parameters
$period = isset($_GET['period']) ? $_GET['period'] : 'weekly';
$year = isset($_GET['year']) ? (int)$_GET['year'] : dcmt_get_current_year();
$month = isset($_GET['month']) ? (int)$_GET['month'] : dcmt_get_current_month();

// Validate parameters
if (!in_array($period, ['yearly', 'monthly', 'weekly', 'daily'])) {
    $period = 'weekly';
}
if ($year < 2020 || $year > 2030) {
    $year = dcmt_get_current_year();
}
if ($month < 1 || $month > 12) {
    $month = dcmt_get_current_month();
}

// Set content type to JSON
header('Content-Type: application/json');

try {
    $chart_data = [];
    $labels = [];
    
    if ($period === 'yearly') {
        // Get chart data for the last 4 years
        $current_year = dcmt_get_current_year();
        $years = [$current_year - 3, $current_year - 2, $current_year - 1, $current_year];
        
        foreach ($years as $year_data) {
            // Get income for this year (paid amounts only)
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_total_paid_amount), 0) as total_income
                FROM dcmt_income 
                WHERE YEAR(dcmt_transaction_date) = ?
            ");
            $stmt->execute([$year_data]);
            $yearly_income_data = $stmt->fetch()['total_income'];

            // Get expenses for this year
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0) as total_expenses
                FROM dcmt_expenses 
                WHERE YEAR(dcmt_expense_date) = ?
            ");
            $stmt->execute([$year_data]);
            $yearly_expenses_data = $stmt->fetch()['total_expenses'];

            $chart_data[] = [
                'income' => (float)$yearly_income_data,
                'expenses' => (float)$yearly_expenses_data
            ];
            
            $labels[] = (string)$year_data;
        }
        
    } elseif ($period === 'monthly') {
        // Get chart data for the specified year (12 months)
        for ($month_num = 1; $month_num <= 12; $month_num++) {
            // Get income for this month (paid amounts only)
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_total_paid_amount), 0) as total_income
                FROM dcmt_income 
                WHERE MONTH(dcmt_transaction_date) = ? AND YEAR(dcmt_transaction_date) = ?
            ");
            $stmt->execute([$month_num, $year]);
            $monthly_income_data = $stmt->fetch()['total_income'];

            // Get expenses for this month
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0) as total_expenses
                FROM dcmt_expenses 
                WHERE MONTH(dcmt_expense_date) = ? AND YEAR(dcmt_expense_date) = ?
            ");
            $stmt->execute([$month_num, $year]);
            $monthly_expenses_data = $stmt->fetch()['total_expenses'];

            $chart_data[] = [
                'income' => (float)$monthly_income_data,
                'expenses' => (float)$monthly_expenses_data
            ];
            
            $month_names = ['Jan', 'Feb', 'Mar', 'Apr', 'May', 'Jun', 'Jul', 'Aug', 'Sep', 'Oct', 'Nov', 'Dec'];
            $labels[] = $month_names[$month_num - 1];
        }
        
    } elseif ($period === 'weekly') {
        // Get chart data for the specified month (weeks)
        $days_in_month = date('t', mktime(0, 0, 0, $month, 1, $year));
        $weeks = ceil($days_in_month / 7);
        
        for ($week = 1; $week <= $weeks; $week++) {
            $start_day = ($week - 1) * 7 + 1;
            $end_day = min($week * 7, $days_in_month);
            
            // Get income for this week (paid amounts only)
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_total_paid_amount), 0) as total_income
                FROM dcmt_income 
                WHERE dcmt_transaction_date >= ? AND dcmt_transaction_date <= ?
            ");
            $start_date = sprintf('%04d-%02d-%02d', $year, $month, $start_day);
            $end_date = sprintf('%04d-%02d-%02d', $year, $month, $end_day);
            $stmt->execute([$start_date, $end_date]);
            $weekly_income_data = $stmt->fetch()['total_income'];

            // Get expenses for this week
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0) as total_expenses
                FROM dcmt_expenses 
                WHERE dcmt_expense_date >= ? AND dcmt_expense_date <= ?
            ");
            $stmt->execute([$start_date, $end_date]);
            $weekly_expenses_data = $stmt->fetch()['total_expenses'];

            $chart_data[] = [
                'income' => (float)$weekly_income_data,
                'expenses' => (float)$weekly_expenses_data
            ];
            
            $labels[] = "Week $week";
        }
        
    } elseif ($period === 'daily') {
        // Get chart data for the specified month (daily)
        $days_in_month = date('t', mktime(0, 0, 0, $month, 1, $year));
        
        for ($day = 1; $day <= $days_in_month; $day++) {
            $current_date = sprintf('%04d-%02d-%02d', $year, $month, $day);
            
            // Get income for this day (paid amounts only)
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_total_paid_amount), 0) as total_income
                FROM dcmt_income 
                WHERE DATE(dcmt_transaction_date) = ?
            ");
            $stmt->execute([$current_date]);
            $daily_income = $stmt->fetch()['total_income'];

            // Get expenses for this day
            $stmt = $dcmt_pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0) as total_expenses
                FROM dcmt_expenses 
                WHERE DATE(dcmt_expense_date) = ?
            ");
            $stmt->execute([$current_date]);
            $daily_expenses = $stmt->fetch()['total_expenses'];

            $chart_data[] = [
                'income' => (float)$daily_income,
                'expenses' => (float)$daily_expenses
            ];
            
            // Create labels showing the day of month
            $labels[] = (string)$day;
        }
    }
    
    // Debug logging for chart data
    error_log("Chart data for period $period ($year" . ($period !== 'yearly' ? "-$month" : '') . "): " . json_encode($chart_data));
    error_log("Chart labels: " . json_encode($labels));
    
    // Return JSON response
    echo json_encode([
        'income' => array_column($chart_data, 'income'),
        'expenses' => array_column($chart_data, 'expenses'),
        'labels' => $labels,
        'period' => $period,
        'year' => $year,
        'month' => $month,
        'debug' => [
            'total_records' => count($chart_data),
            'date_range' => $period === 'weekly' && !empty($chart_data) ? 
                ['first_week' => $labels[0] ?? 'none', 'last_week' => end($labels) ?: 'none'] : null
        ]
    ]);
    
} catch (Exception $e) {
    // Log error and return error response
    error_log("Chart data fetch error: " . $e->getMessage());
    error_log("Chart data error trace: " . $e->getTraceAsString());
    http_response_code(500);
    echo json_encode([
        'error' => 'Failed to fetch chart data',
        'message' => $e->getMessage(),
        'period' => $period ?? 'unknown',
        'year' => $year ?? 'unknown',
        'month' => $month ?? 'unknown'
    ]);
}
?>
