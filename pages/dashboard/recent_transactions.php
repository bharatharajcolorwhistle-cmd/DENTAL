<?php
/**
 * All Recent Transactions
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

$current_user = dcmt_get_current_user();
if (!$current_user) {
    dcmt_show_message(trans('login', 'session_expired'), 'error');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$dashboard_role = $current_user['dcmt_role'] ?? '';
$dashboard_is_doctor = $dashboard_role === 'doctor';
$dashboard_is_owner_doctor = $dashboard_is_doctor && dcmt_is_admin();
$dashboard_is_limited_doctor = $dashboard_is_doctor && !$dashboard_is_owner_doctor;
$dashboard_can_view_financial = in_array($dashboard_role, ['admin', 'doctor'], true);
$dashboard_show_expense_data = !$dashboard_is_limited_doctor;

if (!$dashboard_can_view_financial) {
    dcmt_show_message('Access denied.', 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
    exit();
}

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$type = isset($_GET['type']) ? dcmt_sanitize_input($_GET['type']) : '';
$payment_method = isset($_GET['payment_method']) ? dcmt_sanitize_input($_GET['payment_method']) : '';
$date_range = isset($_GET['date_range']) ? dcmt_sanitize_input($_GET['date_range']) : '';
$clear_filters = isset($_GET['clear']) && $_GET['clear'] === '1';
$is_date_range_provided = isset($_GET['date_range']);
$has_active_non_date_filters = $search !== '' || $type !== '' || $payment_method !== '';

if ($dashboard_is_limited_doctor && $type === 'expense') {
    $type = '';
}
if (!in_array($type, ['', 'income', 'expense'], true)) {
    $type = '';
}

if (!$clear_filters && !$is_date_range_provided && !$has_active_non_date_filters) {
    $date_range = date('Y-m-01') . ' to ' . date('Y-m-t');
}

$date_from = '';
$date_to = '';
if (!empty($date_range) && strpos($date_range, ' to ') !== false) {
    $dates = explode(' to ', $date_range);
    if (count($dates) === 2) {
        $date_from = trim($dates[0]);
        $date_to = trim($dates[1]);
    }
}

$page = isset($_GET['page']) ? max(1, (int) $_GET['page']) : 1;
$per_page = DCMT_PER_PAGE;

$income_payment_methods = [];
$expense_payment_methods = [];
try {
    $income_payment_methods = $dcmt_pdo->query("
        SELECT dcmt_id, dcmt_name
        FROM dcmt_income_payment_methods
        WHERE dcmt_status = 'active'
        ORDER BY dcmt_name
    ")->fetchAll(PDO::FETCH_ASSOC);

    if ($dashboard_show_expense_data) {
        $expense_payment_methods = $dcmt_pdo->query("
            SELECT dcmt_id, dcmt_name
            FROM dcmt_expense_payment_methods
            WHERE dcmt_status = 'active'
            ORDER BY dcmt_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Recent transactions payment methods fetch failed: ' . $e->getMessage());
}

// Combined payment-method options by name so income/expense IDs can share one filter.
$payment_method_options = [];
$include_income_methods = ($type === '' || $type === 'income');
$include_expense_methods = $dashboard_show_expense_data && ($type === '' || $type === 'expense');

if ($include_income_methods) {
    foreach ($income_payment_methods as $method) {
        $name = trim((string) ($method['dcmt_name'] ?? ''));
        if ($name !== '') {
            $payment_method_options[$name] = $name;
        }
    }
}
if ($include_expense_methods) {
    foreach ($expense_payment_methods as $method) {
        $name = trim((string) ($method['dcmt_name'] ?? ''));
        if ($name !== '') {
            $payment_method_options[$name] = $name;
        }
    }
}
ksort($payment_method_options, SORT_NATURAL | SORT_FLAG_CASE);
if ($payment_method !== '' && !isset($payment_method_options[$payment_method])) {
    $payment_method = '';
}

$income_where = [];
$income_params = [];
$expense_where = [];
$expense_params = [];

if ($dashboard_is_limited_doctor) {
    $income_where[] = 'i.dcmt_user_id = ?';
    $income_params[] = (int) $current_user['dcmt_id'];
}

if ($search !== '') {
    $income_where[] = 'i.dcmt_patient_name LIKE ?';
    $income_params[] = '%' . $search . '%';
    $expense_where[] = '(e.dcmt_title LIKE ? OR c.dcmt_name LIKE ?)';
    $expense_params[] = '%' . $search . '%';
    $expense_params[] = '%' . $search . '%';
}

if ($payment_method !== '') {
    $income_where[] = 'EXISTS (
        SELECT 1
        FROM dcmt_income_payment_methods ipm
        WHERE ipm.dcmt_name = ?
          AND ipm.dcmt_id = COALESCE(p.dcmt_payment_method_id, i.dcmt_payment_method_id)
    )';
    $income_params[] = $payment_method;

    $expense_where[] = 'EXISTS (
        SELECT 1
        FROM dcmt_expense_payment_methods epm
        WHERE epm.dcmt_name = ?
          AND epm.dcmt_id = e.dcmt_payment_method_id
    )';
    $expense_params[] = $payment_method;
}

if ($date_from !== '') {
    $income_where[] = 'p.dcmt_paid_on >= ?';
    $income_params[] = $date_from;
    $expense_where[] = 'e.dcmt_expense_date >= ?';
    $expense_params[] = $date_from;
}

if ($date_to !== '') {
    $income_where[] = 'p.dcmt_paid_on <= ?';
    $income_params[] = $date_to;
    $expense_where[] = 'e.dcmt_expense_date <= ?';
    $expense_params[] = $date_to;
}

$income_where_sql = !empty($income_where) ? 'WHERE ' . implode(' AND ', $income_where) : '';
$expense_where_sql = !empty($expense_where) ? 'WHERE ' . implode(' AND ', $expense_where) : '';

$income_sql = "
    SELECT 'income' AS type,
           i.dcmt_patient_name AS title,
           p.dcmt_amount AS amount,
           p.dcmt_paid_on AS date,
           i.dcmt_type AS category,
           (
               SELECT ipm.dcmt_name
               FROM dcmt_income_payment_methods ipm
               WHERE ipm.dcmt_id = COALESCE(p.dcmt_payment_method_id, i.dcmt_payment_method_id)
               LIMIT 1
           ) AS payment_method,
           i.dcmt_id,
           p.dcmt_created_at AS dcmt_activity_at
    FROM dcmt_income_payment_history p
    JOIN dcmt_income i ON p.dcmt_income_id = i.dcmt_id
    {$income_where_sql}
";

$expense_sql = "
    SELECT 'expense' AS type,
           e.dcmt_title AS title,
           e.dcmt_amount AS amount,
           e.dcmt_expense_date AS date,
           c.dcmt_name AS category,
           epm.dcmt_name AS payment_method,
           e.dcmt_id,
           GREATEST(e.dcmt_created_at, e.dcmt_updated_at) AS dcmt_activity_at
    FROM dcmt_expenses e
    LEFT JOIN dcmt_expense_categories c ON e.dcmt_category_id = c.dcmt_id
    LEFT JOIN dcmt_expense_payment_methods epm ON e.dcmt_payment_method_id = epm.dcmt_id
    {$expense_where_sql}
";

$union_parts = [];
$union_params = [];
$include_income = ($type === '' || $type === 'income');
$include_expense = $dashboard_show_expense_data && ($type === '' || $type === 'expense');

if ($include_income) {
    $union_parts[] = $income_sql;
    $union_params = array_merge($union_params, $income_params);
}
if ($include_expense) {
    $union_parts[] = $expense_sql;
    $union_params = array_merge($union_params, $expense_params);
}

$transactions = [];
$total_records = 0;
$total_pages = 0;
$total_income_amount = 0.0;
$total_expense_amount = 0.0;

try {
    if (!empty($union_parts)) {
        $combined_sql = implode(' UNION ALL ', $union_parts);

        $count_stmt = $dcmt_pdo->prepare("SELECT COUNT(*) FROM ({$combined_sql}) AS combined_transactions");
        $count_stmt->execute($union_params);
        $total_records = (int) $count_stmt->fetchColumn();
        $total_pages = $total_records > 0 ? (int) ceil($total_records / $per_page) : 0;
        if ($total_pages > 0 && $page > $total_pages) {
            $page = $total_pages;
        }
        $offset = ($page - 1) * $per_page;

        $summary_stmt = $dcmt_pdo->prepare("
            SELECT
                COALESCE(SUM(CASE WHEN type = 'income' THEN amount ELSE 0 END), 0) AS total_income,
                COALESCE(SUM(CASE WHEN type = 'expense' THEN amount ELSE 0 END), 0) AS total_expense
            FROM ({$combined_sql}) AS combined_transactions
        ");
        $summary_stmt->execute($union_params);
        $summary = $summary_stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        $total_income_amount = (float) ($summary['total_income'] ?? 0);
        $total_expense_amount = (float) ($summary['total_expense'] ?? 0);

        $list_stmt = $dcmt_pdo->prepare("
            SELECT * FROM ({$combined_sql}) AS combined_transactions
            ORDER BY dcmt_activity_at DESC, date DESC, dcmt_id DESC
            LIMIT " . (int) $per_page . " OFFSET " . (int) $offset . "
        ");
        $list_stmt->execute($union_params);
        $transactions = $list_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log('Recent transactions fetch failed: ' . $e->getMessage());
    $transactions = [];
}

$dashboard_back_url = 'index.php?' . http_build_query(['tab' => 'financial']);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="container-fluid mt-4">
    <div class="card mb-4 dcmt-filter-form">
        <div class="card-body">
            <form method="GET" action="" class="row g-3 align-items-end">
                <div class="col-md-3">
                    <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                    <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                           value="<?php echo htmlspecialchars($search); ?>"
                           placeholder="<?php echo trans('dashboard', 'search_transactions_placeholder'); ?>">
                </div>
                <?php if ($dashboard_show_expense_data): ?>
                    <div class="col-md-2">
                        <label for="type" class="form-label"><?php echo trans('dashboard', 'transaction_type'); ?></label>
                        <select class="form-select dcmt-filter-field" id="type" name="type">
                            <option value=""><?php echo trans('dashboard', 'all_types'); ?></option>
                            <option value="income" <?php echo $type === 'income' ? 'selected' : ''; ?>>
                                <?php echo trans('dashboard', 'income'); ?>
                            </option>
                            <option value="expense" <?php echo $type === 'expense' ? 'selected' : ''; ?>>
                                <?php echo trans('dashboard', 'expense'); ?>
                            </option>
                        </select>
                    </div>
                <?php endif; ?>
                <div class="col-md-2">
                    <label for="payment_method" class="form-label"><?php echo trans('dashboard', 'payment_mode'); ?></label>
                    <select class="form-select dcmt-filter-field" id="payment_method" name="payment_method">
                        <option value=""><?php echo trans('dashboard', 'all_payment_methods'); ?></option>
                        <?php foreach ($payment_method_options as $method_name): ?>
                            <?php
                                $translated_income = trans('income_payment_method', $method_name);
                                $translated_expense = trans('expense_payment_method', $method_name);
                                if ($translated_income !== $method_name) {
                                    $display_method = $translated_income;
                                } elseif ($translated_expense !== $method_name) {
                                    $display_method = $translated_expense;
                                } else {
                                    $display_method = $method_name;
                                }
                            ?>
                            <option value="<?php echo htmlspecialchars($method_name); ?>"
                                    <?php echo $payment_method === $method_name ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($display_method); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="col-md-3">
                    <label for="date_range" class="form-label"><?php echo trans('expense', 'date_range'); ?></label>
                    <input type="text" class="form-control dcmt-daterange-picker dcmt-filter-field" id="date_range" name="date_range"
                           value="<?php echo htmlspecialchars($date_range); ?>"
                           placeholder="<?php echo trans('expense', 'select_date_range'); ?>"
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

    <div class="card dcmt-records-table">
        <div class="card-header dcmt-view-card-header">
            <div class="dcmt-view-card-header-content">
                <div>
                    <h5 class="mb-2 dcmt-view-card-title">
                        <?php echo trans('dashboard', 'all_recent_transactions'); ?>
                        <span class="ms-3 dcmt-view-card-title-total">
                            (<?php echo trans('income', 'showing'); ?>:
                            <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                            <?php echo trans('income', 'records'); ?>
                            | <?php echo trans('dashboard', 'income'); ?>:
                            <span style="color: #198754; font-weight: 600;"><?php echo dcmt_format_currency($total_income_amount); ?></span>
                            <?php if ($dashboard_show_expense_data): ?>
                                | <?php echo trans('dashboard', 'expense'); ?>:
                                <span style="color: #dc3545; font-weight: 600;"><?php echo dcmt_format_currency($total_expense_amount); ?></span>
                            <?php endif; ?>
                            )
                        </span>
                    </h5>
                </div>
                <a href="<?php echo htmlspecialchars($dashboard_back_url); ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-arrow-left me-1"></i><?php echo trans('dashboard', 'back_to_dashboard'); ?>
                </a>
            </div>
        </div>
        <div class="card-body">
            <?php if (empty($transactions)): ?>
                <div class="alert alert-info mb-0">
                    <i class="fas fa-info-circle me-2"></i><?php echo trans('dashboard', 'no_recent_transactions'); ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table align-middle">
                        <thead>
                            <tr>
                                <th><?php echo trans('dashboard', 'date'); ?></th>
                                <th><?php echo trans('dashboard', 'transaction_type'); ?></th>
                                <th><?php echo trans('dashboard', 'transaction_title'); ?></th>
                                <th><?php echo trans('dashboard', 'payment_mode'); ?></th>
                                <th><?php echo trans('dashboard', 'amount'); ?></th>
                                <th><?php echo trans('common', 'actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($transactions as $transaction): ?>
                                <?php
                                    $is_income = ($transaction['type'] ?? '') === 'income';
                                    $view_url = $is_income
                                        ? '../income/view.php?id=' . (int) $transaction['dcmt_id']
                                        : '../expenses/view.php?id=' . (int) $transaction['dcmt_id'];
                                    $payment_method_label = trim((string) ($transaction['payment_method'] ?? ''));
                                    if ($payment_method_label === '') {
                                        $payment_method_label = '-';
                                    } else {
                                        $translated_pm = $is_income
                                            ? trans('income_payment_method', $payment_method_label)
                                            : trans('expense_payment_method', $payment_method_label);
                                        if ($translated_pm !== $payment_method_label) {
                                            $payment_method_label = $translated_pm;
                                        }
                                    }
                                ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($transaction['date']); ?></td>
                                    <td>
                                        <span class="<?php echo $is_income ? 'text-success' : 'text-danger'; ?>">
                                            <?php echo ucfirst(trans('dashboard', $is_income ? 'income' : 'expense')); ?>
                                        </span>
                                    </td>
                                    <td><?php echo htmlspecialchars((string) ($transaction['title'] ?? '')); ?></td>
                                    <td><?php echo htmlspecialchars($payment_method_label); ?></td>
                                    <td class="<?php echo $is_income ? 'text-success' : 'text-danger'; ?>">
                                        <?php echo dcmt_format_currency((float) ($transaction['amount'] ?? 0)); ?>
                                    </td>
                                    <td>
                                        <div class="btn-group btn-group-sm btn-group-action" role="group">
                                            <a href="<?php echo htmlspecialchars($view_url); ?>"
                                               class="btn" title="<?php echo trans('common', 'view'); ?>">
                                                <img src="../../assets/images/view-filled.svg" alt="<?php echo trans('common', 'view'); ?>">
                                            </a>
                                        </div>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>

                <?php if ($total_pages > 1): ?>
                    <nav aria-label="<?php echo trans('dashboard', 'all_recent_transactions_pagination'); ?>">
                        <ul class="pagination justify-content-center">
                            <?php if ($page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                        <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                    </a>
                                </li>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                        <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                                <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                        <?php echo $i; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($page < $total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                        <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
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
</div>

<script>
$(document).ready(function() {
    $('#date_range').daterangepicker({
        autoUpdateInput: false,
        autoApply: true,
        <?php if ($date_from && $date_to): ?>
        startDate: moment('<?php echo htmlspecialchars($date_from, ENT_QUOTES); ?>'),
        endDate: moment('<?php echo htmlspecialchars($date_to, ENT_QUOTES); ?>'),
        <?php endif; ?>
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

    $('#date_range').on('cancel.daterangepicker', function() {
        $(this).val('');
    });

    <?php if (!empty($date_range)): ?>
    $('#date_range').val('<?php echo htmlspecialchars($date_range, ENT_QUOTES); ?>');
    <?php endif; ?>
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
