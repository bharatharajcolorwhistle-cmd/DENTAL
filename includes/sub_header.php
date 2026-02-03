<?php
/**
 * Sub Header Component
 * Dental Clinic Management System
 */

// Get current page to determine active menu item
$current_page = basename($_SERVER['PHP_SELF']);
$current_path = $_SERVER['REQUEST_URI'];
if (!isset($current_user)) {
    $current_user = dcmt_get_current_user();
}
$dcmt_nav_doctor_restricted = $current_user && ($current_user['dcmt_role'] ?? '') === 'doctor';
$dcmt_is_staff = $current_user && ($current_user['dcmt_role'] ?? '') === 'staff';

// Function to check if a path is active (only declare if not already declared)
if (!function_exists('is_active_path')) {
    function is_active_path($path) {
        global $current_path;
        return strpos($current_path, $path) !== false;
    }
}

// Function to check if any dropdown item is active
function is_dropdown_active($paths) {
    foreach ($paths as $path) {
        if (is_active_path($path)) {
            return true;
        }
    }
    return false;
}

?>

<!-- Sub Header -->
<div class="sub-header">
    <div class="container-fluid">
        <div class="row align-items-center">
            <div class="col-md-12">
                <nav class="sub-nav">
                    <a href="../dashboard/index.php" class="nav-item <?php echo is_active_path('/dashboard/') ? 'active' : ''; ?>">
                        <img src="../../assets/images/layout-wtf.svg" alt="Dashboard" class="me-2"> <?php echo trans('dashboard', 'dashboard'); ?>
                    </a>
                    
                    <!-- Patients Dropdown -->
                    <div class="nav-item dropdown <?php echo is_dropdown_active(['/patients/', '/patient_notes/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-user-injured me-2"></i><?php echo trans('patient', 'patients'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/patients/') ? 'active' : ''; ?>" href="../patients/index.php"><i class="fas fa-user-injured text-info me-2"></i><?php echo trans('patient', 'patients'); ?></a></li>
                            <li><a class="dropdown-item <?php echo is_active_path('/patient_notes/') ? 'active' : ''; ?>" href="../patient_notes/index.php"><i class="fas fa-sticky-note text-info me-2"></i><?php echo trans('patient_note', 'patient_notes'); ?></a></li>
                        </ul>
                    </div>
                    
                    <!-- Income Dropdown -->
                    <div class="nav-item dropdown <?php echo is_dropdown_active(['/income/', '/income_payment_methods/', '/income_payment_status/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-plus me-2"></i><?php echo trans('dashboard', 'add_income'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/income/add.php') ? 'active' : ''; ?>" href="../income/add.php"><i class="fas fa-plus text-success me-2"></i><?php echo trans('dashboard', 'submenu_add_income'); ?></a></li>
                            <li><a class="dropdown-item <?php echo is_active_path('/income/') && !is_active_path('/income/add.php') ? 'active' : ''; ?>" href="../income/"><i class="fas fa-list text-success me-2"></i><?php echo trans('dashboard', 'view_income'); ?></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (!$dcmt_nav_doctor_restricted && !($dcmt_is_staff ?? false)): ?>
                                <li><a class="dropdown-item <?php echo is_active_path('/income_payment_methods/') ? 'active' : ''; ?>" href="../income_payment_methods/"><i class="fas fa-credit-card text-warning me-2"></i><?php echo trans('dashboard', 'income_payment_methods'); ?></a></li>
                                <li><a class="dropdown-item <?php echo is_active_path('/income_payment_status/') ? 'active' : ''; ?>" href="../income_payment_status/"><i class="fas fa-check-circle text-info me-2"></i><?php echo trans('dashboard', 'income_payment_status'); ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Expense Dropdown -->
                    <div class="nav-item dropdown <?php echo is_dropdown_active(['/expenses/', '/expense_categories/', '/expense_payment_methods/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-minus me-2"></i><?php echo trans('dashboard', 'add_expense'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/expenses/add.php') ? 'active' : ''; ?>" href="../expenses/add.php"><i class="fas fa-plus text-danger me-2"></i><?php echo trans('dashboard', 'submenu_add_expense'); ?></a></li>
                            <li><a class="dropdown-item <?php echo is_active_path('/expenses/') && !is_active_path('/expenses/add.php') ? 'active' : ''; ?>" href="../expenses/"><i class="fas fa-list text-danger me-2"></i><?php echo trans('dashboard', 'view_expense'); ?></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (!$dcmt_nav_doctor_restricted && !($dcmt_is_staff ?? false)): ?>
                                <li><a class="dropdown-item <?php echo is_active_path('/expense_categories/') ? 'active' : ''; ?>" href="../expense_categories/"><i class="fas fa-tags text-warning me-2"></i><?php echo trans('dashboard', 'expense_category'); ?></a></li>
                                <li><a class="dropdown-item <?php echo is_active_path('/expense_payment_methods/') ? 'active' : ''; ?>" href="../expense_payment_methods/"><i class="fas fa-credit-card text-warning me-2"></i><?php echo trans('dashboard', 'expense_payment_methods'); ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <!-- Inventory Dropdown -->
                    <div class="nav-item dropdown <?php echo is_dropdown_active(['/inventory/', '/inventory_categories/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <img src="../../assets/images/inventory-management-icon-blk.svg" alt="Inventory" class="me-2"> <?php echo trans('dashboard', 'add_inventory'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/inventory/add.php') ? 'active' : ''; ?>" href="../inventory/add.php"><i class="fas fa-plus text-info me-2"></i><?php echo trans('dashboard', 'submenu_add_inventory'); ?></a></li>
                            <li><a class="dropdown-item <?php echo is_active_path('/inventory/') && !is_active_path('/inventory/add.php') ? 'active' : ''; ?>" href="../inventory/"><i class="fas fa-list text-info me-2"></i><?php echo trans('dashboard', 'view_inventory'); ?></a></li>
                            <li><hr class="dropdown-divider"></li>
                            <?php if (!$dcmt_nav_doctor_restricted && !($dcmt_is_staff ?? false)): ?>
                                <li><a class="dropdown-item <?php echo is_active_path('/inventory_categories/') ? 'active' : ''; ?>" href="../inventory_categories/"><i class="fas fa-tags text-warning me-2"></i><?php echo trans('dashboard', 'inventory_category'); ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </div>

                    <?php
                        $dcmt_role = $current_user['dcmt_role'] ?? '';
                        $dcmt_show_cashflow = in_array($dcmt_role, ['admin', 'staff'], true);
                    ?>
                    <?php if ($dcmt_show_cashflow): ?>
                    <!-- Cashflow -->
                    <a href="../cashflow/index.php" class="nav-item <?php echo is_active_path('/cashflow/') ? 'active' : ''; ?>">
                        <i class="fas fa-wallet me-2"></i><?php echo trans('cashflow', 'cashflow_management'); ?>
                    </a>
                    <?php endif; ?>

                    <!-- Configuration Dropdown (hidden for staff) -->
                    <?php if (!($dcmt_is_staff ?? false)): ?>
                    <div class="nav-item dropdown <?php echo is_dropdown_active(['/users/', '/services/', '/specializations/', '/doctor_goals/']) ? 'active' : ''; ?>">
                        <a class="nav-item dropdown-toggle" href="#" role="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-cog me-2"></i><?php echo trans('common', 'configuration'); ?>
                        </a>
                        <ul class="dropdown-menu">
                            <li><a class="dropdown-item <?php echo is_active_path('/specializations/') ? 'active' : ''; ?>" href="../specializations/index.php"><i class="fas fa-graduation-cap text-warning me-2"></i><?php echo trans('specialization', 'specializations'); ?></a></li>
                            <li><a class="dropdown-item <?php echo is_active_path('/services/') ? 'active' : ''; ?>" href="../services/index.php"><i class="fas fa-tooth text-info me-2"></i><?php echo trans('service', 'services'); ?></a></li>
                            <li><a class="dropdown-item <?php echo is_active_path('/users/') ? 'active' : ''; ?>" href="../users/index.php"><i class="fas fa-users text-success me-2"></i><?php echo trans('common', 'users'); ?></a></li>
                            <?php if (dcmt_is_admin()): ?>
                                <li><a class="dropdown-item <?php echo is_active_path('/doctor_goals/') ? 'active' : ''; ?>" href="../doctor_goals/index.php"><i class="fas fa-bullseye text-primary me-2"></i><?php echo trans('user', 'doctor_goals'); ?></a></li>
                            <?php endif; ?>
                        </ul>
                    </div>
                    <?php endif; ?>
                </nav>
            </div>
        </div>
    </div>
</div>

