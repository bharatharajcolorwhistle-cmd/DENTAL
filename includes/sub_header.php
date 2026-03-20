<?php
/**
 * Sub Header Component
 * Dental Clinic Management System
 */

$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['REQUEST_URI'];
if (!isset($current_user)) {
    $current_user = dcmt_get_current_user();
}
$dcmt_nav_doctor_restricted = $current_user && ($current_user['dcmt_role'] ?? '') === 'doctor';
$dcmt_is_staff = $current_user && ($current_user['dcmt_role'] ?? '') === 'staff';

if (!function_exists('is_active_path')) {
    function is_active_path($path)
    {
        global $current_path;
        return strpos($current_path, $path) !== false;
    }
}

function is_dropdown_active($paths)
{
    foreach ($paths as $path) {
        if (is_active_path($path)) {
            return true;
        }
    }
    return false;
}

$dcmt_disable_income_nav = false;
if (isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
    if (!function_exists('dcmt_get_cashflow_by_date')) {
        require_once __DIR__ . '/cashflow_functions.php';
    }
    $dcmt_nav_today = dcmt_get_current_date();
    $dcmt_nav_today_record = dcmt_get_cashflow_by_date($dcmt_pdo, $dcmt_nav_today);
    $dcmt_start_cash_added_today = false;
    if ($dcmt_nav_today_record) {
        $dcmt_start_cash_added_today = true;
    }
    $dcmt_disable_income_nav = !$dcmt_start_cash_added_today;
}

?>

<!-- Sub Header -->
<div class="sub-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-12">
                <nav class="sub-nav">
                    <a href="../dashboard/index.php"
                        class="nav-item <?php echo is_active_path('/dashboard/') ? 'active' : ''; ?>">
                        <img src="../../assets/images/layout-wtf.svg" alt="Dashboard" class="me-2">
                        <?php echo trans('dashboard', 'dashboard'); ?>
                    </a>

                    <!-- Patients Dropdown -->
                    <div
                        class="nav-item dropdown <?php echo is_dropdown_active(['/patients/', '/patient_notes/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="fas fa-user-injured me-2"></i><?php echo trans('patient', 'patients'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/patients/add.php') ? 'active' : ''; ?>"
                                    href="../patients/add.php"><i
                                        class="fas fa-plus text-info me-2"></i><?php echo trans('patient', 'add_patient'); ?></a>
                            </li>
                            <li><a class="dropdown-item <?php echo is_active_path('/patients/') && !is_active_path('/patients/add.php') ? 'active' : ''; ?>"
                                    href="../patients/index.php"><i
                                        class="fas fa-list text-info me-2"></i><?php echo trans('patient', 'view_patient'); ?></a>
                            </li>
                            <li><a class="dropdown-item <?php echo is_active_path('/patient_notes/') ? 'active' : ''; ?>"
                                    href="../patient_notes/index.php"><i
                                        class="fas fa-sticky-note text-info me-2"></i><?php echo trans('patient_note', 'patient_notes'); ?></a>
                            </li>
                        </ul>
                    </div>

                    <div class="nav-item <?php echo is_active_path('/appointments/') ? 'active' : ''; ?>">
                        <a class="nav-link" href="../appointments/index.php">
                            <i class="fas fa-calendar-check me-2"></i><?php echo trans('appointment', 'appointments'); ?>
                        </a>
                    </div>

                    <div
                        class="nav-item dropdown <?php echo is_dropdown_active(['/income/', '/income_payment_methods/', '/income_payment_status/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="fas fa-plus me-2"></i><?php echo trans('dashboard', 'add_income'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/income/add.php') ? 'active' : ''; ?>"
                                    href="../income/add.php"
                                    <?php if ($dcmt_disable_income_nav): ?>
                                    onclick="return dcmtShowStartCashAlert(event);"
                                    style="opacity: 0.4; cursor: not-allowed;"
                                    title="<?php echo htmlspecialchars(trans('cashflow', 'start_cash_required') ?: 'Please set Start Cash before adding income or expenses.'); ?>"
                                    <?php endif; ?>><i
                                        class="fas fa-plus text-success me-2"></i><?php echo trans('dashboard', 'submenu_add_income'); ?></a>
                            </li>
                            <li><a class="dropdown-item <?php echo is_active_path('/income/') && !is_active_path('/income/add.php') ? 'active' : ''; ?>"
                                    href="../income/"><i
                                        class="fas fa-list text-success me-2"></i><?php echo trans('dashboard', 'view_income'); ?></a>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <?php if (!$dcmt_nav_doctor_restricted && !($dcmt_is_staff ?? false)): ?>
                                <li><a class="dropdown-item <?php echo is_active_path('/income_payment_methods/') ? 'active' : ''; ?>"
                                        href="../income_payment_methods/"><i
                                            class="fas fa-credit-card text-warning me-2"></i><?php echo trans('dashboard', 'income_payment_methods'); ?></a>
                                </li>
                                <li><a class="dropdown-item <?php echo is_active_path('/income_payment_status/') ? 'active' : ''; ?>"
                                        href="../income_payment_status/"><i
                                            class="fas fa-check-circle text-info me-2"></i><?php echo trans('dashboard', 'income_payment_status'); ?></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <div
                        class="nav-item dropdown <?php echo is_dropdown_active(['/expenses/', '/expense_categories/', '/expense_payment_methods/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <i class="fas fa-minus me-2"></i><?php echo trans('dashboard', 'add_expense'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/expenses/add.php') ? 'active' : ''; ?>"
                                    href="../expenses/add.php"><i
                                        class="fas fa-plus text-danger me-2"></i><?php echo trans('dashboard', 'submenu_add_expense'); ?></a>
                            </li>
                            <li><a class="dropdown-item <?php echo is_active_path('/expenses/') && !is_active_path('/expenses/add.php') ? 'active' : ''; ?>"
                                    href="../expenses/"><i
                                        class="fas fa-list text-danger me-2"></i><?php echo trans('dashboard', 'view_expense'); ?></a>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <?php if (!$dcmt_nav_doctor_restricted && !($dcmt_is_staff ?? false)): ?>
                                <li><a class="dropdown-item <?php echo is_active_path('/expense_categories/') ? 'active' : ''; ?>"
                                        href="../expense_categories/"><i
                                            class="fas fa-tags text-warning me-2"></i><?php echo trans('dashboard', 'expense_category'); ?></a>
                                </li>
                                <li><a class="dropdown-item <?php echo is_active_path('/expense_payment_methods/') ? 'active' : ''; ?>"
                                        href="../expense_payment_methods/"><i
                                            class="fas fa-credit-card text-warning me-2"></i><?php echo trans('dashboard', 'expense_payment_methods'); ?></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Inventory Dropdown -->
                    <div
                        class="nav-item dropdown <?php echo is_dropdown_active(['/inventory/', '/inventory_categories/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                            aria-expanded="false">
                            <img src="../../assets/images/inventory-management-icon-blk.svg" alt="Inventory"
                                class="me-2"> <?php echo trans('dashboard', 'add_inventory'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/inventory/add.php') ? 'active' : ''; ?>"
                                    href="../inventory/add.php"><i
                                        class="fas fa-plus text-info me-2"></i><?php echo trans('dashboard', 'submenu_add_inventory'); ?></a>
                            </li>
                            <li><a class="dropdown-item <?php echo is_active_path('/inventory/') && !is_active_path('/inventory/add.php') ? 'active' : ''; ?>"
                                    href="../inventory/"><i
                                        class="fas fa-list text-info me-2"></i><?php echo trans('dashboard', 'view_inventory'); ?></a>
                            </li>
                            <li>
                                <hr class="dropdown-divider">
                            </li>
                            <?php if (!$dcmt_nav_doctor_restricted && !($dcmt_is_staff ?? false)): ?>
                                <li><a class="dropdown-item <?php echo is_active_path('/inventory_categories/') ? 'active' : ''; ?>"
                                        href="../inventory_categories/"><i
                                            class="fas fa-tags text-warning me-2"></i><?php echo trans('dashboard', 'inventory_category'); ?></a>
                                </li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <?php
                    $dcmt_role = $current_user['dcmt_role'] ?? '';
                    $dcmt_show_cashflow = in_array($dcmt_role, ['admin', 'staff'], true);
                    ?>
                    <?php if ($dcmt_show_cashflow): ?>
                        <div class="nav-item <?php echo is_active_path('/cashflow/') ? 'active' : ''; ?>">
                            <a class="nav-link" href="../cashflow/index.php">
                                <i class="fas fa-wallet me-2"></i><?php echo trans('cashflow', 'cash_box'); ?>
                            </a>
                        </div>
                    <?php endif; ?>

                    <!-- Configuration Dropdown (hidden for staff) -->
                    <?php if (!($dcmt_is_staff ?? false)): ?>
                        <div
                            class="nav-item dropdown <?php echo is_dropdown_active(['/users/', '/services/', '/specializations/', '/doctor_goals/']) ? 'active' : ''; ?>">
                            <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown"
                                aria-expanded="false">
                                <i class="fas fa-cog me-2"></i><?php echo trans('common', 'configuration'); ?>
                            </a>
                            <ul class="dropdown-menu">
                                <li><a class="dropdown-item <?php echo is_active_path('/specializations/') ? 'active' : ''; ?>"
                                        href="../specializations/index.php"><i
                                            class="fas fa-graduation-cap text-warning me-2"></i><?php echo trans('specialization', 'specializations'); ?></a>
                                </li>
                                <li><a class="dropdown-item <?php echo is_active_path('/services/') ? 'active' : ''; ?>"
                                        href="../services/index.php"><i
                                            class="fas fa-tooth text-info me-2"></i><?php echo trans('service', 'services'); ?></a>
                                </li>
                                <li><a class="dropdown-item <?php echo is_active_path('/users/') ? 'active' : ''; ?>"
                                        href="../users/index.php"><i
                                            class="fas fa-users text-success me-2"></i><?php echo trans('common', 'users'); ?></a>
                                </li>
                                <?php if (dcmt_is_admin()): ?>
                                    <li><a class="dropdown-item <?php echo is_active_path('/doctor_goals/') ? 'active' : ''; ?>"
                                            href="../doctor_goals/index.php"><i
                                                class="fas fa-bullseye text-primary me-2"></i><?php echo trans('user', 'doctor_goals'); ?></a>
                                    </li>
                                <?php endif; ?>
                            </ul>
                        </div>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>
</div>
<script>
function dcmtShowStartCashAlert(e) {
    if (e) {
        if (e.preventDefault) e.preventDefault();
        if (e.stopPropagation) e.stopPropagation();
    }
    alert('<?php echo addslashes(trans('cashflow', 'start_cash_required') ?: 'Please set the Start Cash for today before adding income or expenses.'); ?>');
    window.location.href = '../cashflow/start_cash.php';
    return false;
}
</script>
