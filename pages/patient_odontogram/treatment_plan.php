<?php
/**
 * Treatment plan — auto-generated from odontogram solution summary with service pricing.
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';
require_once __DIR__ . '/../../includes/patient_odontogram_summary.php';
require_once __DIR__ . '/../../includes/patient_treatment_plan.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$dcmt_db = new Dcmt_Database();
$dcmt_db->migratePatientTreatmentPlanSchema();

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$errors = [];
$regenerate = isset($_GET['regenerate']) && $_GET['regenerate'] === '1';

if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
    exit();
}

try {
    $patient_cols = dcmt_patient_select_columns_without_odontogram('p', $dcmt_pdo);
    $stmt = $dcmt_pdo->prepare("SELECT {$patient_cols} FROM dcmt_patients p WHERE p.dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('../patient_notes/index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log('Treatment plan — patient: ' . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
    exit();
}

$odontogram_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
$odontogram_document = dcmt_patient_odontogram_decode_document($odontogram_json);
$solution_chart = $odontogram_document['solution'] ?? dcmt_patient_odontogram_empty_chart();
$summary = dcmt_patient_odontogram_solution_summary($odontogram_document);

$doctors = dcmt_fetch_active_doctors($dcmt_pdo);
$default_doctor_id = 0;
if (!empty($doctors)) {
    $default_doctor_id = (int) $doctors[0]['dcmt_id'];
}

global $dcmt_current_user;
$current_user_id = (int) ($dcmt_current_user['dcmt_id'] ?? 0);
$current_role = (string) ($dcmt_current_user['dcmt_role'] ?? '');
if ($current_user_id > 0 && $current_role === 'doctor') {
    $default_doctor_id = $current_user_id;
}

// Scheduling mirrors appointment creation permissions (admin, staff, assistant).
$can_schedule = dcmt_is_admin() || in_array($current_role, ['staff', 'assistant'], true);
$operatories = [];
if ($can_schedule) {
    try {
        $operatory_stmt = $dcmt_pdo->query("
            SELECT dcmt_id, dcmt_name
            FROM dcmt_operatories
            WHERE dcmt_is_active = 1
            ORDER BY dcmt_name ASC
        ");
        $operatories = $operatory_stmt ? $operatory_stmt->fetchAll(PDO::FETCH_ASSOC) : [];
    } catch (PDOException $e) {
        error_log('Treatment plan — operatories: ' . $e->getMessage());
        $operatories = [];
    }
}

$saved_plan = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
$doctor_id = (int) ($saved_plan['dcmt_doctor_user_id'] ?? 0);
$plan_notes = $saved_plan['dcmt_notes'] ?? '';
$plan_synced_from_odontogram = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient', 'invalid_token');
    } else {
        $plan_notes = trim((string) ($_POST['plan_notes'] ?? ''));
        $posted_lines = json_decode((string) ($_POST['plan_lines_json'] ?? ''), true);
        if (!is_array($posted_lines)) {
            $errors[] = trans('patient', 'treatment_plan_invalid_lines');
        } else {
            $line_validation_error = dcmt_validate_treatment_plan_odontogram_lines($posted_lines);
            if ($line_validation_error !== null) {
                $errors[] = $line_validation_error;
            }
        }

        if (empty($errors)) {
            $doctor_id = 0;
            foreach ($posted_lines as $posted_line) {
                if (!is_array($posted_line)) {
                    continue;
                }
                if (isset($posted_line['selected']) && empty($posted_line['selected'])) {
                    continue;
                }
                $lineDoctor = (int) ($posted_line['doctor_id'] ?? 0);
                if ($lineDoctor > 0) {
                    $doctor_id = $lineDoctor;
                    break;
                }
            }
            $normalized = dcmt_normalize_treatment_plan_lines($posted_lines, $dcmt_pdo, $doctor_id);
            $ok = dcmt_save_patient_treatment_plan(
                $dcmt_pdo,
                $patient_id,
                $doctor_id,
                $summary,
                $normalized,
                $plan_notes,
                $dcmt_current_user['dcmt_username'] ?? null
            );
            if ($ok) {
                dcmt_log_activity('Treatment plan saved', "Patient ID: $patient_id");
                dcmt_show_message(trans('patient', 'treatment_plan_save_success'), 'success');
                dcmt_redirect('treatment_plan.php?patient_id=' . $patient_id);
                exit();
            }
            $errors[] = trans('patient_note', 'database_error');
        }
    }
}

// Auto-refresh proposed lines when odontogram solution changed (or regenerate requested).
$sync_result = dcmt_sync_treatment_plan_from_odontogram(
    $dcmt_pdo,
    $patient_id,
    $doctor_id > 0 ? $doctor_id : null,
    $regenerate,
    $dcmt_current_user['dcmt_username'] ?? null
);
$plan_rebuilt_from_odontogram = false;
if (is_array($sync_result)) {
    $summary = $sync_result['summary'] ?? $summary;
    $plan_synced_from_odontogram = !empty($sync_result['synced']);
    $plan_rebuilt_from_odontogram = !empty($sync_result['rebuilt']) || $plan_synced_from_odontogram;
    if ($plan_rebuilt_from_odontogram || $regenerate || !$saved_plan || empty($saved_plan['lines'])) {
        $plan_lines = $sync_result['lines'] ?? [];
    } else {
        $plan_lines = dcmt_normalize_treatment_plan_lines($saved_plan['lines'], $dcmt_pdo, $doctor_id);
    }
    if ($plan_synced_from_odontogram) {
        $saved_plan = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
        if ($saved_plan) {
            $plan_notes = $saved_plan['dcmt_notes'] ?? $plan_notes;
            $doctor_id = (int) ($saved_plan['dcmt_doctor_user_id'] ?? $doctor_id);
        }
    }
} elseif ($regenerate || !$saved_plan || empty($saved_plan['lines'])) {
    $plan_lines = dcmt_build_treatment_plan_lines($dcmt_pdo, $solution_chart, $default_doctor_id);
} else {
    $plan_lines = dcmt_normalize_treatment_plan_lines($saved_plan['lines'], $dcmt_pdo, $doctor_id);
}

$plan_lines = dcmt_filter_treatment_plan_lines_by_show_flag($dcmt_pdo, $plan_lines);

$services_by_doctor = [];
foreach ($plan_lines as $line) {
    $lineDoctorId = (int) ($line['doctor_id'] ?? 0);
    if ($lineDoctorId > 0 && !isset($services_by_doctor[$lineDoctorId])) {
        $services_by_doctor[$lineDoctorId] = dcmt_fetch_active_services_catalog($dcmt_pdo, $lineDoctorId);
    }
}
$split_plan_lines = dcmt_treatment_plan_split_lines($plan_lines);
$odontogram_plan_lines = dcmt_sort_treatment_plan_odontogram_lines($split_plan_lines['odontogram']);
$additional_plan_lines = $split_plan_lines['additional'];
$plan_groups = dcmt_treatment_plan_group_lines($plan_lines);
$plan_default_group = dcmt_treatment_plan_default_group();
if (empty($plan_groups)) {
    $plan_groups = [[
        'id' => $plan_default_group['id'],
        'name' => $plan_default_group['name'],
        'confirmed' => false,
        'line_count' => 0,
        'scheduled_count' => 0,
        'scheduled_appointment_id' => 0,
    ]];
}
$plan_total = dcmt_treatment_plan_calculate_total($plan_lines);
$has_solution_data = !empty($summary['has_data']);

$csrf_token = dcmt_generate_csrf_token();
$back_url = '../patient_odontogram/view.php?patient_id=' . $patient_id;
$print_url = 'print_treatment_plan.php?patient_id=' . $patient_id;

$cell_headers = dcmt_patient_odontogram_summary_cell_headers();

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">
<link href="<?php echo dcmt_asset('assets/css/select2.min.css', '../../'); ?>" rel="stylesheet">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_title')); ?></h1>
            <div class="d-flex flex-wrap gap-3 align-items-center">
                <?php if ($has_solution_data && !empty($plan_lines)): ?>
                    <a href="<?php echo htmlspecialchars($print_url); ?>"
                       class="dcmt-add-form-view-all-link"
                       target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_print')); ?>
                    </a>
                <?php endif; ?>
                <a href="<?php echo htmlspecialchars($back_url); ?>" class="dcmt-add-form-view-all-link">
                    <?php echo trans('patient_note', 'back_to_notes'); ?>
                </a>
            </div>
        </div>
    </div>

    <?php if (!$has_solution_data): ?>
        <div class="alert alert-warning" data-persistent="true" role="alert">
            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_solution')); ?>
            <a href="edit.php?patient_id=<?php echo $patient_id; ?>" class="alert-link">
                <?php echo htmlspecialchars(trans('patient_note', 'edit_odontogram')); ?>
            </a>
        </div>
        <div class="dcmt-form-actions">
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
        </div>
    <?php else: ?>

    <?php if (!empty($plan_synced_from_odontogram) || !empty($regenerate)): ?>
        <div class="alert alert-info">
            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_synced_notice')); ?>
        </div>
    <?php endif; ?>

    <form method="POST" action="" id="dcmtTreatmentPlanForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="plan_lines_json" id="plan_lines_json" value="">

        <div class="mb-4">
            <h5 class="mb-1"><i class="fas fa-user me-2"></i><?php echo htmlspecialchars(trans('patient', 'patient')); ?></h5>
            <p class="text-muted mb-0">
                <?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?>
            </p>
        </div>

        <div class="card dcmt-plan-section-card mb-4">
            <div class="card-body">
                <div class="dcmt-plan-section-header">
                    <h5 class="dcmt-plan-section-title mb-0">
                        <i class="fas fa-th dcmt-plan-section-icon"></i>
                        <?php echo htmlspecialchars(trans('patient', 'treatment_plan_summary_title')); ?>
                    </h5>
                    <p class="dcmt-plan-section-subtitle mb-0">
                        <?php echo htmlspecialchars(trans('patient', 'treatment_plan_summary_help')); ?>
                    </p>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 dcmt-plan-summary-table">
                        <thead>
                            <tr>
                                <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_treatment')); ?></th>
                                <?php foreach ($cell_headers as $header): ?>
                                    <th class="text-center" title="<?php echo htmlspecialchars($header['title'] ?? $header['label']); ?>">
                                        <?php echo htmlspecialchars($header['label']); ?>
                                    </th>
                                <?php endforeach; ?>
                                <th class="text-end dcmt-plan-summary-total-head"><?php echo htmlspecialchars(trans('common', 'total')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if (empty($summary['matrix'])): ?>
                                <tr>
                                    <td colspan="<?php echo count($cell_headers) + 2; ?>" class="text-muted text-center py-3">
                                        <?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_treatments')); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($summary['matrix'] as $row): ?>
                                    <tr>
                                        <td class="dcmt-plan-summary-treatment"><?php echo htmlspecialchars($row['treatment']); ?></td>
                                        <?php foreach ($cell_headers as $header): ?>
                                            <?php $qty = (int) ($row['cells'][$header['key']] ?? 0); ?>
                                            <td class="text-center"><?php echo $qty > 0 ? $qty : '—'; ?></td>
                                        <?php endforeach; ?>
                                        <td class="text-end dcmt-plan-summary-total"><?php echo (int) $row['total']; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>
            </div>
        </div>

        <div class="mb-3" id="dcmtPlanAddServiceToolbar">
            <button type="button" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center" id="addPlanServiceBtn">
                <i class="fas fa-plus"></i>
                <span id="addPlanServiceBtnText" class="ms-1"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_add_service')); ?></span>
            </button>
        </div>

        <div class="card dcmt-plan-section-card mb-4" id="dcmtAdditionalServicesSection" style="<?php echo empty($additional_plan_lines) ? 'display:none;' : ''; ?>">
            <div class="card-body">
                <div class="dcmt-plan-section-header dcmt-plan-section-header--plan mb-3">
                    <div>
                        <h5 class="dcmt-plan-section-title mb-0">
                            <i class="fas fa-plus-circle dcmt-plan-section-icon"></i>
                            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_additional_services')); ?>
                        </h5>
                    </div>
                </div>

                <div id="dcmtAdditionalServicesPanel" class="dcmt-plan-additional-services-panel dcmt-items-panel">
                    <div class="row g-2 mb-2 dcmt-plan-additional-head text-muted small fw-semibold d-none d-md-flex">
                        <div class="col-md-3"><?php echo trans('patient', 'treatment_plan_doctor'); ?></div>
                        <div class="col-md-3"><?php echo trans('patient', 'treatment_plan_col_service'); ?></div>
                        <div class="col-md-1"><?php echo trans('patient', 'treatment_plan_col_qty'); ?></div>
                        <div class="col-md-2"><?php echo trans('patient', 'treatment_plan_col_unit_price'); ?></div>
                        <div class="col-md-2"><?php echo trans('patient', 'treatment_plan_col_subtotal'); ?></div>
                        <div class="col-md-1"></div>
                    </div>
                    <div id="dcmtAdditionalServicesBody">
                        <?php foreach ($additional_plan_lines as $idx => $line): ?>
                            <?php
                            $line_doctor_id = (int) ($line['doctor_id'] ?? 0);
                            $line_services = ($line_doctor_id > 0 && isset($services_by_doctor[$line_doctor_id]))
                                ? $services_by_doctor[$line_doctor_id]
                                : [];
                            $line_service_id = (int) ($line['service_id'] ?? 0);
                            $line_has_service = false;
                            if ($line_service_id > 0) {
                                foreach ($line_services as $svc) {
                                    if ((int) $svc['id'] === $line_service_id) {
                                        $line_has_service = true;
                                        break;
                                    }
                                }
                            }
                            if (!$line_has_service) {
                                $line_service_id = 0;
                            }
                            $line_unit_price_value = ($line_has_service && (float) ($line['unit_price'] ?? 0) > 0)
                                ? number_format((float) $line['unit_price'], 2, '.', '')
                                : '';
                            $line_subtotal_value = ($line_has_service && $line_unit_price_value !== '')
                                ? number_format((float) $line['subtotal'], 2, '.', '')
                                : '';
                            ?>
                            <div class="service-item row mb-2 dcmt-plan-additional-item" data-additional-index="<?php echo (int) $idx; ?>">
                                <div class="col-md-3">
                                    <select class="form-select plan-additional-doctor">
                                        <option value=""><?php echo htmlspecialchars(trans('patient', 'treatment_plan_select_doctor')); ?></option>
                                        <?php foreach ($doctors as $doc): ?>
                                            <option value="<?php echo (int) $doc['dcmt_id']; ?>" <?php echo $line_doctor_id === (int) $doc['dcmt_id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($doc['dcmt_name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-3">
                                    <select class="form-select plan-additional-service" <?php echo $line_doctor_id > 0 ? '' : 'disabled'; ?>>
                                        <option value=""><?php echo htmlspecialchars(trans('patient', 'treatment_plan_select_service')); ?></option>
                                        <?php foreach ($line_services as $svc): ?>
                                            <option value="<?php echo (int) $svc['id']; ?>"
                                                    data-price="<?php echo htmlspecialchars((string) $svc['price']); ?>"
                                                    <?php echo $line_service_id === (int) $svc['id'] ? 'selected' : ''; ?>>
                                                <?php echo htmlspecialchars($svc['name']); ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                                <div class="col-md-1">
                                    <input type="number" class="form-control text-center plan-additional-qty" min="1"
                                           placeholder="<?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_qty')); ?>"
                                           value="<?php echo (int) ($line['quantity'] ?? 1); ?>">
                                </div>
                                <div class="col-md-2">
                                    <div class="dcmt-amount-input-wrapper">
                                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                        <input type="number" class="form-control dcmt-amount-input plan-additional-unit-price" min="0" step="0.01"
                                               value="<?php echo htmlspecialchars($line_unit_price_value); ?>"
                                               placeholder="<?php echo htmlspecialchars(trans('common', 'amount')); ?>">
                                    </div>
                                </div>
                                <div class="col-md-2">
                                    <div class="dcmt-amount-input-wrapper">
                                        <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                        <input type="text" class="form-control dcmt-amount-input plan-additional-subtotal" readonly
                                               value="<?php echo htmlspecialchars($line_subtotal_value); ?>"
                                               placeholder="<?php echo htmlspecialchars(trans('common', 'amount')); ?>">
                                    </div>
                                </div>
                                <div class="col-md-1 dcmt-delete-cell">
                                    <button type="button" class="btn btn-outline-danger btn-sm plan-additional-remove" title="<?php echo htmlspecialchars(trans('common', 'delete')); ?>">
                                        <i class="fas fa-trash"></i>
                                    </button>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="card dcmt-plan-section-card mb-4">
            <div class="card-body">
                <div class="dcmt-plan-section-header dcmt-plan-section-header--plan">
                    <div>
                        <h5 class="dcmt-plan-section-title mb-1">
                            <i class="fas fa-clipboard-check dcmt-plan-section-icon"></i>
                            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_lines_title')); ?>
                        </h5>
                        <p class="dcmt-plan-section-subtitle mb-0">
                            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_groups_help')); ?>
                        </p>
                    </div>
                    <div class="d-flex flex-wrap gap-2">
                        <button type="button" class="btn btn-outline-primary btn-sm d-inline-flex align-items-center" id="addPlanGroupBtn">
                            <i class="fas fa-layer-group"></i>
                            <span class="ms-1"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_group_add')); ?></span>
                        </button>
                    </div>
                </div>

                <div class="table-responsive dcmt-treatment-plan-panel">
                    <table class="table align-middle mb-0" id="dcmtTreatmentPlanTable">
                        <thead>
                            <tr>
                                <th class="dcmt-plan-col-check"></th>
                                <th class="dcmt-plan-col-treatment"><?php echo trans('patient', 'treatment_plan_col_treatment'); ?></th>
                                <th class="dcmt-plan-col-location"><?php echo trans('patient', 'treatment_plan_col_location'); ?></th>
                                <th class="dcmt-plan-col-group"><?php echo trans('patient', 'treatment_plan_col_group'); ?></th>
                                <th class="dcmt-plan-col-qty text-center"><?php echo trans('patient', 'treatment_plan_col_qty'); ?></th>
                                <th class="dcmt-plan-col-doctor"><?php echo trans('patient', 'treatment_plan_doctor'); ?> <span class="text-danger">*</span></th>
                                <th class="dcmt-plan-col-service"><?php echo trans('patient', 'treatment_plan_col_service'); ?> <span class="text-danger">*</span></th>
                                <th class="dcmt-plan-col-price"><?php echo trans('patient', 'treatment_plan_col_unit_price'); ?></th>
                                <th class="dcmt-plan-col-subtotal"><?php echo trans('patient', 'treatment_plan_col_subtotal'); ?></th>
                                <th class="dcmt-plan-col-schedule text-center"><?php echo trans('patient', 'treatment_plan_schedule_col'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="dcmtTreatmentPlanBody">
                            <?php if (empty($odontogram_plan_lines)): ?>
                                <tr id="dcmtPlanEmptyRow">
                                    <td colspan="10" class="text-muted text-center py-3">
                                        <?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_treatments')); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($odontogram_plan_lines as $idx => $line): ?>
                                    <?php
                                    $line_doctor_id = (int) ($line['doctor_id'] ?? 0);
                                    $line_services = ($line_doctor_id > 0 && isset($services_by_doctor[$line_doctor_id]))
                                        ? $services_by_doctor[$line_doctor_id]
                                        : [];
                                    $line_service_id = (int) ($line['service_id'] ?? 0);
                                    $line_has_service = false;
                                    if ($line_service_id > 0) {
                                        foreach ($line_services as $svc) {
                                            if ((int) $svc['id'] === $line_service_id) {
                                                $line_has_service = true;
                                                break;
                                            }
                                        }
                                    }
                                    if (!$line_has_service) {
                                        $line_service_id = 0;
                                    }
                                    $line_unit_price_value = ($line_has_service && (float) ($line['unit_price'] ?? 0) > 0)
                                        ? number_format((float) $line['unit_price'], 2, '.', '')
                                        : '';
                                    $line_subtotal_value = ($line_has_service && $line_unit_price_value !== '')
                                        ? number_format((float) $line['subtotal'], 2, '.', '')
                                        : '';
                                    $line_location = trim(($line['zone_label'] ?? '') . ' - ' . ($line['quadrant_label'] ?? ''), ' -');
                                    $line_group_fields = dcmt_treatment_plan_normalize_group_fields($line);
                                    $line_group_id = $line_group_fields['group_id'];
                                    $scheduled_appointment_id = (int) ($line['scheduled_appointment_id'] ?? 0);
                                    $scheduled_start_at = (string) ($line['scheduled_start_at'] ?? '');
                                    $scheduled_start_display = $scheduled_start_at !== ''
                                        ? trim(dcmt_format_date($scheduled_start_at) . ' ' . date('h:i A', strtotime($scheduled_start_at)))
                                        : '';
                                    ?>
                                    <tr data-line-index="<?php echo (int) $idx; ?>"
                                        data-group-id="<?php echo htmlspecialchars($line_group_id); ?>"
                                        data-scheduled-appointment-id="<?php echo $scheduled_appointment_id; ?>"
                                        data-scheduled-start-at="<?php echo htmlspecialchars($scheduled_start_at); ?>">
                                        <td class="dcmt-plan-col-check text-center">
                                            <input type="checkbox" class="form-check-input plan-line-selected" <?php echo !empty($line['selected']) ? 'checked' : ''; ?>>
                                        </td>
                                        <td class="dcmt-plan-col-treatment plan-treatment"><?php echo htmlspecialchars($line['treatment']); ?></td>
                                        <td class="dcmt-plan-col-location plan-location">
                                            <?php echo htmlspecialchars($line_location); ?>
                                        </td>
                                        <td class="dcmt-plan-col-group">
                                            <select class="form-select form-select-sm plan-group-select" aria-label="<?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_group')); ?>">
                                                <?php foreach ($plan_groups as $group_option): ?>
                                                    <option value="<?php echo htmlspecialchars((string) $group_option['id']); ?>" <?php echo $line_group_id === (string) $group_option['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars((string) $group_option['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="dcmt-plan-col-qty text-center">
                                            <input type="number" class="form-control text-center plan-qty" min="1" value="<?php echo (int) $line['quantity']; ?>">
                                        </td>
                                        <td class="dcmt-plan-col-doctor">
                                            <select class="form-select plan-doctor">
                                                <option value=""><?php echo htmlspecialchars(trans('patient', 'treatment_plan_select_doctor')); ?></option>
                                                <?php foreach ($doctors as $doc): ?>
                                                    <option value="<?php echo (int) $doc['dcmt_id']; ?>" <?php echo $line_doctor_id === (int) $doc['dcmt_id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($doc['dcmt_name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="dcmt-plan-col-service">
                                            <select class="form-select plan-service" <?php echo $line_doctor_id > 0 ? '' : 'disabled'; ?>>
                                                <option value=""><?php echo htmlspecialchars(trans('patient', 'treatment_plan_select_service')); ?></option>
                                                <?php foreach ($line_services as $svc): ?>
                                                    <option value="<?php echo (int) $svc['id']; ?>"
                                                            data-price="<?php echo htmlspecialchars((string) $svc['price']); ?>"
                                                            <?php echo $line_service_id === (int) $svc['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($svc['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="dcmt-plan-col-price">
                                            <div class="dcmt-amount-input-wrapper">
                                                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                                <input type="number" class="form-control dcmt-amount-input plan-unit-price" min="0" step="0.01"
                                                       value="<?php echo htmlspecialchars($line_unit_price_value); ?>"
                                                       placeholder="<?php echo htmlspecialchars(trans('common', 'amount')); ?>">
                                            </div>
                                        </td>
                                        <td class="dcmt-plan-col-subtotal">
                                            <div class="dcmt-amount-input-wrapper">
                                                <span class="dcmt-currency-symbol"><?php echo dcmt_get_current_currency(); ?></span>
                                                <input type="text" class="form-control dcmt-amount-input plan-subtotal" readonly
                                                       value="<?php echo htmlspecialchars($line_subtotal_value); ?>"
                                                       placeholder="<?php echo htmlspecialchars(trans('common', 'amount')); ?>">
                                            </div>
                                        </td>
                                        <td class="dcmt-plan-col-schedule text-center dcmt-plan-schedule-cell">
                                            <?php if ($scheduled_appointment_id > 0): ?>
                                                <a href="../appointments/view.php?id=<?php echo $scheduled_appointment_id; ?>"
                                                   class="badge bg-success text-decoration-none dcmt-plan-scheduled-badge"
                                                   target="_blank" rel="noopener noreferrer"
                                                   title="<?php echo htmlspecialchars($scheduled_start_display !== '' ? $scheduled_start_display : trans('patient', 'treatment_plan_scheduled')); ?>">
                                                    <i class="fas fa-calendar-check me-1"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_scheduled')); ?>
                                                    <?php if ($scheduled_start_display !== ''): ?>
                                                        <span class="d-block fw-normal small"><?php echo htmlspecialchars($scheduled_start_display); ?></span>
                                                    <?php endif; ?>
                                                </a>
                                            <?php elseif ($can_schedule): ?>
                                                <button type="button" class="btn btn-sm btn-outline-primary dcmt-plan-schedule-btn dcmt-plan-schedule-icon-btn"
                                                        title="<?php echo htmlspecialchars(trans('patient', 'treatment_plan_schedule_help')); ?>"
                                                        aria-label="<?php echo htmlspecialchars(trans('patient', 'treatment_plan_schedule_help')); ?>">
                                                    <i class="fas fa-calendar-plus" aria-hidden="true"></i>
                                                </button>
                                            <?php else: ?>
                                                <span class="text-muted">—</span>
                                            <?php endif; ?>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                    </table>
                </div>

                <div class="d-flex justify-content-end align-items-center mt-3 pt-3 border-top">
                    <span class="dcmt-plan-grand-total-label me-3"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_grand_total')); ?>:</span>
                    <span class="dcmt-plan-grand-total-value fs-5 fw-bold" id="planTotalCell"><?php echo dcmt_format_currency($plan_total); ?></span>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h5 class="mb-3"><i class="fas fa-sticky-note me-2"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_notes')); ?></h5>
            <div class="mb-3">
                <textarea class="form-control" id="plan_notes" name="plan_notes" rows="3" placeholder="<?php echo htmlspecialchars(trans('patient', 'treatment_plan_notes_placeholder')); ?>"><?php echo htmlspecialchars($plan_notes); ?></textarea>
            </div>
        </div>

        <div class="dcmt-form-actions">
            <a href="<?php echo htmlspecialchars($back_url); ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="savePlanBtn">
                <i class="fas fa-save"></i><?php echo trans('patient', 'treatment_plan_save'); ?>
            </button>
        </div>
    </form>

    <?php endif; ?>
</div>

<?php if ($can_schedule && $has_solution_data): ?>
<div class="modal fade" id="dcmtPlanScheduleModal" tabindex="-1" aria-labelledby="dcmtPlanScheduleModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dcmtPlanScheduleModalLabel"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_schedule_title')); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(trans('common', 'close')); ?>"></button>
            </div>
            <div class="modal-body">
                <div id="dcmtPlanScheduleAlert" class="alert d-none" role="alert" data-persistent="true"></div>
                <div class="form-text mb-3" id="dcmtPlanScheduleSummary"></div>
                <div class="row g-3">
                    <div class="col-md-6">
                        <label class="form-label" for="dcmtPlanScheduleDoctor"><?php echo trans('appointment', 'doctor'); ?></label>
                        <select class="form-select" id="dcmtPlanScheduleDoctor" required>
                            <option value=""><?php echo htmlspecialchars(trans('patient', 'treatment_plan_select_doctor')); ?></option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?php echo (int) $doc['dcmt_id']; ?>"><?php echo htmlspecialchars($doc['dcmt_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dcmtPlanSchedulePatient"><?php echo trans('appointment', 'patient'); ?></label>
                        <input type="text" class="form-control" id="dcmtPlanSchedulePatient" value="<?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?>" readonly>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dcmtPlanScheduleOperatory"><?php echo trans('appointment', 'operatory'); ?></label>
                        <select class="form-select" id="dcmtPlanScheduleOperatory" required>
                            <option value=""><?php echo htmlspecialchars(trans('appointment', 'select')); ?></option>
                            <?php foreach ($operatories as $operatory): ?>
                                <option value="<?php echo (int) $operatory['dcmt_id']; ?>"><?php echo htmlspecialchars((string) $operatory['dcmt_name']); ?></option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text"><?php echo trans('appointment', 'operatory_help_doctor'); ?></div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dcmtPlanScheduleDate"><?php echo trans('appointment', 'date'); ?></label>
                        <input type="date" class="form-control" id="dcmtPlanScheduleDate" value="<?php echo htmlspecialchars(dcmt_get_current_date('Y-m-d')); ?>" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dcmtPlanScheduleStart"><?php echo trans('appointment', 'start_time'); ?></label>
                        <input type="time" class="form-control" id="dcmtPlanScheduleStart" step="300" required>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dcmtPlanScheduleDuration"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_schedule_duration')); ?></label>
                        <select class="form-select" id="dcmtPlanScheduleDuration">
                            <?php foreach ([15, 30, 45, 60, 90, 120, 180] as $minutes): ?>
                                <option value="<?php echo $minutes; ?>" <?php echo $minutes === 30 ? 'selected' : ''; ?>>
                                    <?php echo $minutes; ?> <?php echo htmlspecialchars(trans('patient', 'treatment_plan_schedule_minutes')); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                        <div class="form-text" id="dcmtPlanScheduleEndLabel"></div>
                    </div>
                    <div class="col-12 d-none" id="dcmtPlanScheduleSlotsWrap">
                        <div class="border rounded-3 bg-light p-3">
                            <div class="fw-semibold small mb-2 text-body-secondary"><?php echo htmlspecialchars(trans('appointment', 'availability_panel_title')); ?></div>
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <div class="small text-success fw-semibold mb-1"><?php echo htmlspecialchars(trans('appointment', 'availability_available')); ?></div>
                                    <div id="dcmtPlanScheduleAvailableList" class="small"></div>
                                </div>
                                <div class="col-md-6">
                                    <div class="small text-danger fw-semibold mb-1"><?php echo htmlspecialchars(trans('appointment', 'availability_booked')); ?></div>
                                    <div id="dcmtPlanScheduleBookedList" class="small"></div>
                                </div>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <label class="form-label" for="dcmtPlanScheduleReason"><?php echo trans('appointment', 'reason'); ?></label>
                        <input type="text" class="form-control" id="dcmtPlanScheduleReason" maxlength="255">
                    </div>
                    <div class="col-12">
                        <label class="form-label" for="dcmtPlanScheduleNotes"><?php echo trans('appointment', 'notes'); ?></label>
                        <textarea class="form-control" id="dcmtPlanScheduleNotes" rows="3"></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <a href="#" id="dcmtPlanScheduleFullForm" class="me-auto small align-self-center" target="_blank" rel="noopener noreferrer">
                    <?php echo htmlspecialchars(trans('patient', 'treatment_plan_schedule_open_full')); ?>
                </a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('appointment', 'close'); ?></button>
                <button type="button" class="btn btn-primary" id="dcmtPlanScheduleSubmit"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_schedule_submit')); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
(function() {
    const planLinesSeed = <?php echo json_encode($odontogram_plan_lines, JSON_UNESCAPED_UNICODE); ?>;
    const planGroupsSeed = <?php echo json_encode(array_map(static function (array $group): array {
        return [
            'id' => (string) ($group['id'] ?? ''),
            'name' => (string) ($group['name'] ?? ''),
            'confirmed' => !empty($group['confirmed']),
            'scheduled_appointment_id' => (int) ($group['scheduled_appointment_id'] ?? 0),
            'scheduled_start_at' => (string) ($group['scheduled_start_at'] ?? ''),
            'scheduled' => !empty($group['line_count'])
                && (int) ($group['scheduled_count'] ?? 0) >= (int) $group['line_count'],
        ];
    }, $plan_groups), JSON_UNESCAPED_UNICODE); ?>;
    const planGroupTexts = <?php echo json_encode([
        'defaultName' => trans('patient', 'treatment_plan_group_default_name'),
        'newName' => trans('patient', 'treatment_plan_group_new_name'),
        'namePlaceholder' => trans('patient', 'treatment_plan_group_name_placeholder'),
        'items' => trans('patient', 'treatment_plan_group_items'),
        'subtotal' => trans('patient', 'treatment_plan_group_subtotal'),
        'confirm' => trans('patient', 'treatment_plan_group_confirm'),
        'confirmFirst' => trans('patient', 'treatment_plan_group_confirm_first'),
        'schedule' => trans('patient', 'treatment_plan_group_schedule'),
        'scheduled' => trans('patient', 'treatment_plan_group_scheduled'),
        'remove' => trans('patient', 'treatment_plan_group_remove'),
        'removeConfirm' => trans('patient', 'treatment_plan_group_remove_confirm'),
        'lastGroup' => trans('patient', 'treatment_plan_group_last_confirm'),
        'scheduleTitle' => trans('patient', 'treatment_plan_group_schedule_title'),
        'scheduleSummary' => trans('patient', 'treatment_plan_group_schedule_summary'),
    ], JSON_UNESCAPED_UNICODE); ?>;
    const additionalLinesSeed = <?php echo json_encode($additional_plan_lines, JSON_UNESCAPED_UNICODE); ?>;
    const doctorsList = <?php echo json_encode($doctors, JSON_UNESCAPED_UNICODE); ?>;
    const servicesByDoctor = <?php echo json_encode($services_by_doctor, JSON_UNESCAPED_UNICODE); ?>;
    const currencySuffix = <?php echo json_encode(' ' . dcmt_get_current_currency()); ?>;
    const doctorPlaceholder = <?php echo json_encode(trans('patient', 'treatment_plan_select_doctor'), JSON_UNESCAPED_UNICODE); ?>;
    const servicePlaceholder = <?php echo json_encode(trans('patient', 'treatment_plan_select_service'), JSON_UNESCAPED_UNICODE); ?>;
    const addServiceText = <?php echo json_encode(trans('patient', 'treatment_plan_add_service'), JSON_UNESCAPED_UNICODE); ?>;
    const addAnotherServiceText = <?php echo json_encode(trans('patient', 'treatment_plan_add_another_service'), JSON_UNESCAPED_UNICODE); ?>;
    const qtyPlaceholder = <?php echo json_encode(trans('patient', 'treatment_plan_col_qty'), JSON_UNESCAPED_UNICODE); ?>;
    const amountPlaceholder = <?php echo json_encode(trans('common', 'amount'), JSON_UNESCAPED_UNICODE); ?>;
    const currencySymbol = <?php echo json_encode(dcmt_get_current_currency()); ?>;
    const doctorServicesUrl = <?php echo json_encode(DCMT_APP_URL . '/pages/income/get_doctor_services.php'); ?>;
    const lineDoctorServiceRequiredText = <?php echo json_encode(trans('patient', 'treatment_plan_line_doctor_service_required'), JSON_UNESCAPED_UNICODE); ?>;
    const scheduleConfig = <?php echo json_encode([
        'url' => DCMT_APP_URL . '/pages/patient_odontogram/schedule_appointment_ajax.php',
        'slotsUrl' => DCMT_APP_URL . '/pages/appointments/available_slots_ajax.php',
        'fullFormUrl' => DCMT_APP_URL . '/pages/appointments/add.php',
        'csrfToken' => $csrf_token,
        'patientId' => $patient_id,
        'today' => dcmt_get_current_date('Y-m-d'),
        'defaultDuration' => 30,
        'scheduledLabel' => trans('patient', 'treatment_plan_scheduled'),
        'doctorRequired' => trans('appointment', 'doctor_required'),
        'operatoryRequired' => trans('appointment', 'operatory_required'),
        'operatoryEmpty' => trans('appointment', 'operatories_empty'),
        'startRequired' => trans('appointment', 'start_required'),
        'slotsFailed' => trans('appointment', 'load_slots_failed'),
        'slotsNoFree' => trans('appointment', 'availability_no_free'),
        'slotsNoneBooked' => trans('appointment', 'availability_none_booked'),
        'endLabel' => trans('patient', 'treatment_plan_schedule_ends_at'),
        'failed' => trans('appointment', 'save_failed'),
        'processing' => trans('common', 'processing'),
        'summaryLabel' => trans('patient', 'treatment_plan_schedule_summary'),
        'groupSummaryLabel' => trans('patient', 'treatment_plan_group_schedule_summary'),
        'groupTitle' => trans('patient', 'treatment_plan_group_schedule_title'),
        'groupConfirmFirst' => trans('patient', 'treatment_plan_group_confirm_first'),
    ], JSON_UNESCAPED_UNICODE); ?>;
    const fmtMoney = (n) => Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + currencySuffix;
    const canSchedulePlan = <?php echo $can_schedule ? 'true' : 'false'; ?>;

    const tbody = document.getElementById('dcmtTreatmentPlanBody');
    const additionalBody = document.getElementById('dcmtAdditionalServicesBody');
    const additionalSection = document.getElementById('dcmtAdditionalServicesSection');
    const additionalPanel = document.getElementById('dcmtAdditionalServicesPanel');
    const addPlanServiceBtn = document.getElementById('addPlanServiceBtn');
    const addPlanServiceBtnText = document.getElementById('addPlanServiceBtnText');
    const addPlanGroupBtn = document.getElementById('addPlanGroupBtn');
    const totalCell = document.getElementById('planTotalCell');
    const hiddenJson = document.getElementById('plan_lines_json');
    const form = document.getElementById('dcmtTreatmentPlanForm');
    let planScheduler = null;

    if (!tbody || !form || !additionalBody) return;

    const doctorServiceCache = Object.assign({}, servicesByDoctor || {});
    let additionalLineMeta = (additionalLinesSeed || []).map(function(line, i) {
        return {
            line_id: line.line_id || ('add_svc_' + (i + 1)),
            is_additional: true
        };
    });
    let additionalLineCounter = additionalLineMeta.length;

    let lineMeta = planLinesSeed.map((line, i) => ({
        line_id: line.line_id || ('line_' + (i + 1)),
        treatment: line.treatment || '',
        zone: line.zone || '',
        quadrant: line.quadrant || '',
        quadrant_label: line.quadrant_label || '',
        zone_label: line.zone_label || ''
    }));

    let planGroups = (planGroupsSeed || []).map(function(group) {
        return {
            id: String(group.id || ''),
            name: String(group.name || ''),
            confirmed: !!group.confirmed,
            scheduledAppointmentId: parseInt(group.scheduled_appointment_id || '0', 10) || 0,
            scheduledStartAt: String(group.scheduled_start_at || ''),
            scheduled: !!group.scheduled
        };
    }).filter(function(group) {
        return group.id !== '';
    });

    if (!planGroups.length) {
        planGroups.push({
            id: 'grp_1',
            name: String(planGroupTexts.defaultName || 'Visit 1'),
            confirmed: false,
            scheduledAppointmentId: 0,
            scheduledStartAt: '',
            scheduled: false
        });
    }

    function findGroup(groupId) {
        return planGroups.find(function(group) {
            return group.id === groupId;
        }) || null;
    }

    function rowsForGroup(groupId) {
        return Array.prototype.slice.call(tbody.querySelectorAll('tr[data-line-index]')).filter(function(row) {
            return (row.getAttribute('data-group-id') || '') === groupId;
        });
    }

    function nextGroupId() {
        let max = 0;
        planGroups.forEach(function(group) {
            const match = /^grp_(\d+)$/.exec(group.id);
            if (match) {
                max = Math.max(max, parseInt(match[1], 10) || 0);
            }
        });
        return 'grp_' + (max + 1);
    }

    function nextGroupName() {
        const used = {};
        planGroups.forEach(function(group) {
            used[String(group.name || '').trim().toLowerCase()] = true;
        });
        const pattern = String(planGroupTexts.newName || 'Visit {n}');
        let n = planGroups.length + 1;
        let name = pattern.replace('{n}', String(n));
        let guard = 0;
        while (used[name.trim().toLowerCase()] && guard < 200) {
            n += 1;
            guard += 1;
            name = pattern.replace('{n}', String(n));
        }
        return name;
    }

    function escapeGroupText(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;');
    }

    function escapeGroupAttr(value) {
        return escapeGroupText(value).replace(/"/g, '&quot;');
    }

    function ensureSelect2(select, placeholder) {
        if (typeof $ === 'undefined' || !select) {
            return;
        }
        const $select = $(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.off('select2:open.dcmtFocusSearch');
            $select.select2('destroy');
        }
        $select.select2({
            placeholder: placeholder,
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0,
            dropdownParent: $(document.body)
        });
        $select.on('select2:open.dcmtFocusSearch', function() {
            window.setTimeout(function() {
                const search = document.querySelector('.select2-container--open .select2-search__field');
                if (search) {
                    search.focus();
                }
            }, 0);
        });
    }

    function destroySelect2(select) {
        if (typeof $ === 'undefined' || !select) return;
        const $select = $(select);
        if ($select.hasClass('select2-hidden-accessible')) {
            $select.off('select2:open.dcmtFocusSearch');
            $select.select2('destroy');
        }
    }

    function destroyRowSelect2(row) {
        if (!row) return;
        row.querySelectorAll('.plan-doctor, .plan-service, .plan-additional-doctor, .plan-additional-service').forEach(destroySelect2);
    }

    function initPlanRowSelect2(row) {
        if (!row) return;
        ensureSelect2(row.querySelector('.plan-doctor'), doctorPlaceholder);
        ensureSelect2(row.querySelector('.plan-service'), servicePlaceholder);
    }

    function initAdditionalRowSelect2(row) {
        if (!row) return;
        ensureSelect2(row.querySelector('.plan-additional-doctor'), doctorPlaceholder);
        ensureSelect2(row.querySelector('.plan-additional-service'), servicePlaceholder);
    }

    function doctorOptionsHtml(selectedDoctorId) {
        let html = '<option value="">' + String(doctorPlaceholder || '').replace(/</g, '&lt;') + '</option>';
        (doctorsList || []).forEach(function(doc) {
            const id = parseInt(doc.dcmt_id, 10) || 0;
            const sel = selectedDoctorId === id ? ' selected' : '';
            html += '<option value="' + id + '"' + sel + '>' + String(doc.dcmt_name || '').replace(/</g, '&lt;') + '</option>';
        });
        return html;
    }

    function serviceOptionsHtml(catalog, selectedServiceId) {
        let html = '<option value="">' + String(servicePlaceholder || '').replace(/</g, '&lt;') + '</option>';
        (catalog || []).forEach(function(svc) {
            const id = parseInt(svc.id, 10) || 0;
            const sel = selectedServiceId === id ? ' selected' : '';
            html += '<option value="' + id + '" data-price="' + (svc.price != null ? svc.price : '') + '"' + sel + '>' +
                String(svc.name || '').replace(/</g, '&lt;') + '</option>';
        });
        return html;
    }

    function loadServicesForDoctor(doctorId) {
        doctorId = parseInt(doctorId || '0', 10) || 0;
        if (doctorId <= 0) {
            return Promise.resolve([]);
        }
        const key = String(doctorId);
        if (Object.prototype.hasOwnProperty.call(doctorServiceCache, key)) {
            return Promise.resolve(doctorServiceCache[key] || []);
        }
        return fetch(doctorServicesUrl + '?doctor_id=' + encodeURIComponent(doctorId))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                const services = (data && data.success && Array.isArray(data.services)) ? data.services : [];
                doctorServiceCache[key] = services;
                return services;
            })
            .catch(function() {
                doctorServiceCache[key] = [];
                return [];
            });
    }

    function populateServiceSelect(row, doctorId, selectedServiceId, options) {
        const serviceSelect = row ? row.querySelector('.plan-service') : null;
        const priceInput = row ? row.querySelector('.plan-unit-price') : null;
        if (!serviceSelect) return Promise.resolve();

        const preservePrice = options && options.preservePrice === true;
        doctorId = parseInt(doctorId || '0', 10) || 0;
        selectedServiceId = parseInt(selectedServiceId || '0', 10) || 0;

        if (doctorId <= 0) {
            destroySelect2(serviceSelect);
            serviceSelect.innerHTML = '<option value=""></option>';
            serviceSelect.value = '';
            serviceSelect.disabled = true;
            if (priceInput && !preservePrice) priceInput.value = '';
            initPlanRowSelect2(row);
            recalcTotal();
            return Promise.resolve();
        }

        serviceSelect.disabled = true;
        return loadServicesForDoctor(doctorId).then(function(services) {
            destroySelect2(serviceSelect);
            serviceSelect.innerHTML = serviceOptionsHtml(services, selectedServiceId);
            serviceSelect.disabled = services.length === 0;
            serviceSelect.value = selectedServiceId > 0 ? String(selectedServiceId) : '';
            if (!preservePrice) applyServicePrice(row, '.plan-service', '.plan-unit-price');
            initPlanRowSelect2(row);
            recalcTotal();
        });
    }

    function populateAdditionalServiceSelect(row, doctorId, selectedServiceId, options) {
        const serviceSelect = row ? row.querySelector('.plan-additional-service') : null;
        const priceInput = row ? row.querySelector('.plan-additional-unit-price') : null;
        if (!serviceSelect) return Promise.resolve();

        const preservePrice = options && options.preservePrice === true;
        doctorId = parseInt(doctorId || '0', 10) || 0;
        selectedServiceId = parseInt(selectedServiceId || '0', 10) || 0;

        if (doctorId <= 0) {
            destroySelect2(serviceSelect);
            serviceSelect.innerHTML = '<option value=""></option>';
            serviceSelect.value = '';
            serviceSelect.disabled = true;
            if (priceInput && !preservePrice) priceInput.value = '';
            initAdditionalRowSelect2(row);
            recalcAdditionalRow(row);
            recalcTotal();
            return Promise.resolve();
        }

        serviceSelect.disabled = true;
        return loadServicesForDoctor(doctorId).then(function(services) {
            destroySelect2(serviceSelect);
            serviceSelect.innerHTML = serviceOptionsHtml(services, selectedServiceId);
            serviceSelect.disabled = services.length === 0;
            serviceSelect.value = selectedServiceId > 0 ? String(selectedServiceId) : '';
            if (!preservePrice) applyServicePrice(row, '.plan-additional-service', '.plan-additional-unit-price');
            initAdditionalRowSelect2(row);
            recalcAdditionalRow(row);
            recalcTotal();
        });
    }

    function applyServicePrice(row, serviceSelector, priceSelector) {
        const select = row.querySelector(serviceSelector);
        const priceInput = row.querySelector(priceSelector);
        if (!select || !priceInput) return;
        const serviceId = parseInt(select.value || '0', 10);
        const opt = select.selectedOptions[0];
        if (serviceId > 0 && opt && opt.dataset.price !== undefined && opt.dataset.price !== '') {
            priceInput.value = Number(opt.dataset.price).toFixed(2);
        } else {
            priceInput.value = '';
        }
    }

    function recalcPlanRow(row) {
        const serviceSelect = row.querySelector('.plan-service');
        const serviceId = parseInt(serviceSelect?.value || '0', 10);
        const qty = Math.max(1, parseInt(row.querySelector('.plan-qty')?.value || '1', 10));
        const priceRaw = row.querySelector('.plan-unit-price')?.value;
        const price = (serviceId > 0 && priceRaw !== '' && priceRaw !== null)
            ? (parseFloat(priceRaw) || 0)
            : 0;
        const subtotal = Math.round(qty * price * 100) / 100;
        const subEl = row.querySelector('.plan-subtotal');
        if (subEl) subEl.value = subtotal > 0 ? subtotal.toFixed(2) : '';
        return subtotal;
    }

    function recalcAdditionalRow(row) {
        const serviceSelect = row.querySelector('.plan-additional-service');
        const serviceId = parseInt(serviceSelect?.value || '0', 10);
        const qty = Math.max(1, parseInt(row.querySelector('.plan-additional-qty')?.value || '1', 10));
        const priceRaw = row.querySelector('.plan-additional-unit-price')?.value;
        const price = (serviceId > 0 && priceRaw !== '' && priceRaw !== null)
            ? (parseFloat(priceRaw) || 0)
            : 0;
        const subtotal = Math.round(qty * price * 100) / 100;
        const subEl = row.querySelector('.plan-additional-subtotal');
        if (subEl) subEl.value = subtotal > 0 ? subtotal.toFixed(2) : '';
        return subtotal;
    }

    function recalcTotal() {
        let total = updateGroupSubtotals();
        additionalBody.querySelectorAll('.dcmt-plan-additional-item').forEach(function(row) {
            total += recalcAdditionalRow(row);
        });
        total = Math.round(total * 100) / 100;
        if (totalCell) totalCell.textContent = fmtMoney(total);
        return total;
    }

    function groupSubtotal(groupId) {
        let subtotal = 0;
        rowsForGroup(groupId).forEach(function(row) {
            if (row.querySelector('.plan-line-selected')?.checked) {
                subtotal += recalcPlanRow(row);
            }
        });
        return Math.round(subtotal * 100) / 100;
    }

    function groupLineCount(groupId) {
        let count = 0;
        rowsForGroup(groupId).forEach(function(row) {
            if (row.querySelector('.plan-line-selected')?.checked) {
                count += Math.max(1, parseInt(row.querySelector('.plan-qty')?.value || '1', 10) || 1);
            }
        });
        return count;
    }

    function groupReason(group) {
        const counts = {};
        rowsForGroup(group.id).forEach(function(row) {
            if (!row.querySelector('.plan-line-selected')?.checked) return;
            const meta = lineMeta[parseInt(row.getAttribute('data-line-index') || '-1', 10)] || {};
            const treatment = String(meta.treatment || '');
            if (treatment === '') return;
            const qty = Math.max(1, parseInt(row.querySelector('.plan-qty')?.value || '1', 10) || 1);
            counts[treatment] = (counts[treatment] || 0) + qty;
        });
        const parts = Object.keys(counts).map(function(treatment) {
            return counts[treatment] > 1 ? treatment + ' x' + counts[treatment] : treatment;
        });
        const label = String(group.name || '').trim();
        return (label !== '' ? label + ': ' : '') + parts.join(', ');
    }

    function collectOdontogramLines() {
        const lines = [];
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
            const meta = lineMeta[parseInt(row.getAttribute('data-line-index') || '-1', 10)] || {};
            const serviceSelect = row.querySelector('.plan-service');
            const serviceId = parseInt(serviceSelect?.value || '0', 10);
            let serviceName = '';
            if (serviceId > 0 && serviceSelect?.selectedOptions?.[0]) {
                serviceName = (serviceSelect.selectedOptions[0].text || '').trim();
            }
            const qty = Math.max(1, parseInt(row.querySelector('.plan-qty')?.value || '1', 10));
            const priceRaw = row.querySelector('.plan-unit-price')?.value;
            const unitPrice = (serviceId > 0 && priceRaw !== '' && priceRaw !== null)
                ? (parseFloat(priceRaw) || 0)
                : 0;
            const group = findGroup(row.getAttribute('data-group-id') || '') || planGroups[0];
            lines.push({
                line_id: meta.line_id || ('line_' + (parseInt(row.getAttribute('data-line-index') || '0', 10) + 1)),
                treatment: meta.treatment,
                zone: meta.zone,
                quadrant: meta.quadrant,
                quadrant_label: meta.quadrant_label,
                zone_label: meta.zone_label,
                quantity: qty,
                doctor_id: parseInt(row.querySelector('.plan-doctor')?.value || '0', 10),
                service_id: serviceId,
                service_name: serviceName,
                unit_price: unitPrice,
                subtotal: Math.round(qty * unitPrice * 100) / 100,
                selected: !!row.querySelector('.plan-line-selected')?.checked,
                is_additional: false,
                scheduled_appointment_id: parseInt(row.dataset.scheduledAppointmentId || '0', 10) || 0,
                scheduled_start_at: row.dataset.scheduledStartAt || '',
                group_id: group.id,
                group_name: group.name,
                group_confirmed: !!group.confirmed
            });
        });
        return lines;
    }

    function collectAdditionalLines() {
        const lines = [];
        additionalBody.querySelectorAll('.dcmt-plan-additional-item').forEach(function(row, idx) {
            const meta = additionalLineMeta[idx] || {};
            const serviceSelect = row.querySelector('.plan-additional-service');
            const serviceId = parseInt(serviceSelect?.value || '0', 10);
            let serviceName = '';
            if (serviceId > 0 && serviceSelect?.selectedOptions?.[0]) {
                serviceName = (serviceSelect.selectedOptions[0].text || '').trim();
            }
            const qty = Math.max(1, parseInt(row.querySelector('.plan-additional-qty')?.value || '1', 10));
            const priceRaw = row.querySelector('.plan-additional-unit-price')?.value;
            const unitPrice = (serviceId > 0 && priceRaw !== '' && priceRaw !== null)
                ? (parseFloat(priceRaw) || 0)
                : 0;
            lines.push({
                line_id: meta.line_id || ('add_svc_' + (idx + 1)),
                treatment: serviceName,
                zone: '',
                quadrant: '',
                quadrant_label: '',
                zone_label: '',
                quantity: qty,
                doctor_id: parseInt(row.querySelector('.plan-additional-doctor')?.value || '0', 10),
                service_id: serviceId,
                service_name: serviceName,
                unit_price: unitPrice,
                subtotal: Math.round(qty * unitPrice * 100) / 100,
                selected: true,
                is_additional: true
            });
        });
        return lines;
    }

    function collectLines() {
        return collectOdontogramLines().concat(collectAdditionalLines());
    }

    function clearPlanRowInvalid(row) {
        if (row) row.classList.remove('dcmt-plan-row-invalid');
    }

    function updatePlanRowRequiredState(row) {
        if (!row) return;
        if (!row.querySelector('.plan-line-selected')?.checked) {
            clearPlanRowInvalid(row);
        }
    }

    function validateSelectedPlanLines() {
        let firstInvalid = null;
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
            if (!row.querySelector('.plan-line-selected')?.checked) {
                clearPlanRowInvalid(row);
                return;
            }
            const doctorId = parseInt(row.querySelector('.plan-doctor')?.value || '0', 10);
            const serviceId = parseInt(row.querySelector('.plan-service')?.value || '0', 10);
            if (doctorId <= 0 || serviceId <= 0) {
                row.classList.add('dcmt-plan-row-invalid');
                if (!firstInvalid) firstInvalid = row;
            } else {
                clearPlanRowInvalid(row);
            }
        });
        if (firstInvalid) {
            firstInvalid.scrollIntoView({ behavior: 'smooth', block: 'center' });
            window.alert(lineDoctorServiceRequiredText);
            return false;
        }
        return true;
    }

    function showAdditionalServicesSection() {
        if (additionalSection) {
            additionalSection.style.display = '';
        }
        if (additionalPanel) {
            additionalPanel.style.display = '';
        }
    }

    function updateAddServiceButtonText() {
        const count = additionalBody.querySelectorAll('.dcmt-plan-additional-item').length;
        if (addPlanServiceBtnText) {
            addPlanServiceBtnText.textContent = count > 0 ? addAnotherServiceText : addServiceText;
        }
        if (additionalSection) {
            additionalSection.style.display = count > 0 ? '' : 'none';
        }
    }

    function ensureRowGroups() {
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
            let groupId = row.getAttribute('data-group-id') || '';
            if (!findGroup(groupId)) {
                groupId = planGroups[0].id;
                row.setAttribute('data-group-id', groupId);
            }
        });
    }

    function populateGroupSelects() {
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
            const select = row.querySelector('.plan-group-select');
            if (!select) return;
            const current = row.getAttribute('data-group-id') || planGroups[0].id;
            select.innerHTML = '';
            planGroups.forEach(function(group) {
                const option = document.createElement('option');
                option.value = group.id;
                option.textContent = group.name || group.id;
                if (group.id === current) option.selected = true;
                select.appendChild(option);
            });
            select.value = current;
        });
    }

    function refreshGroupOptionLabels(group) {
        tbody.querySelectorAll('.plan-group-select').forEach(function(select) {
            Array.prototype.forEach.call(select.options, function(option) {
                if (option.value === group.id) {
                    option.textContent = group.name || group.id;
                }
            });
        });
    }

    function createGroupHeaderRow(group) {
        const row = document.createElement('tr');
        row.className = 'dcmt-plan-group-row';
        row.setAttribute('data-group-row', group.id);
        row.innerHTML =
            '<td colspan="10" class="dcmt-plan-group-cell">' +
                '<div class="dcmt-plan-group-head">' +
                    '<span class="dcmt-plan-group-icon"><i class="fas fa-layer-group"></i></span>' +
                    '<input type="text" class="form-control form-control-sm dcmt-plan-group-name" maxlength="60"' +
                        ' placeholder="' + escapeGroupAttr(planGroupTexts.namePlaceholder) + '">' +
                    '<span class="badge rounded-pill dcmt-plan-group-count"></span>' +
                    '<span class="dcmt-plan-group-subtotal-wrap">' +
                        '<span class="dcmt-plan-group-subtotal-label">' + escapeGroupText(planGroupTexts.subtotal) + ':</span> ' +
                        '<span class="dcmt-plan-group-subtotal"></span>' +
                    '</span>' +
                    '<label class="form-check dcmt-plan-group-confirm-wrap mb-0" title="' + escapeGroupAttr(planGroupTexts.confirmFirst) + '">' +
                        '<input type="checkbox" class="form-check-input plan-group-confirm">' +
                        '<span class="form-check-label">' + escapeGroupText(planGroupTexts.confirm) + '</span>' +
                    '</label>' +
                    '<span class="dcmt-plan-group-actions">' +
                        '<button type="button" class="btn btn-sm btn-outline-primary plan-group-schedule">' +
                            '<i class="fas fa-calendar-plus me-1"></i>' +
                            '<span class="plan-group-schedule-label">' + escapeGroupText(planGroupTexts.schedule) + '</span>' +
                        '</button>' +
                        '<a class="badge bg-success text-decoration-none dcmt-plan-group-scheduled d-none" target="_blank" rel="noopener noreferrer">' +
                            '<i class="fas fa-calendar-check me-1"></i>' +
                            '<span class="dcmt-plan-group-scheduled-label">' + escapeGroupText(planGroupTexts.scheduled) + '</span>' +
                        '</a>' +
                        '<button type="button" class="btn btn-sm btn-outline-danger plan-group-remove" title="' + escapeGroupAttr(planGroupTexts.remove) + '">' +
                            '<i class="fas fa-times"></i>' +
                        '</button>' +
                    '</span>' +
                '</div>' +
            '</td>';
        return row;
    }

    function updateGroupHeader(headerRow, group, lineCount) {
        if (!headerRow || !group) return;
        headerRow.setAttribute('data-group-row', group.id);

        const nameInput = headerRow.querySelector('.dcmt-plan-group-name');
        if (nameInput && document.activeElement !== nameInput) {
            nameInput.value = group.name || '';
        }

        const countEl = headerRow.querySelector('.dcmt-plan-group-count');
        if (countEl) {
            countEl.textContent = String(planGroupTexts.items || '{count}')
                .replace('{count}', String(lineCount));
        }

        const confirmInput = headerRow.querySelector('.plan-group-confirm');
        if (confirmInput) confirmInput.checked = !!group.confirmed;

        const scheduleBtn = headerRow.querySelector('.plan-group-schedule');
        const scheduledBadge = headerRow.querySelector('.dcmt-plan-group-scheduled');
        const appointmentId = parseInt(group.scheduledAppointmentId || '0', 10) || 0;
        const isScheduled = !!group.scheduled && appointmentId > 0;

        if (scheduleBtn) {
            scheduleBtn.classList.toggle('d-none', !canSchedulePlan || isScheduled);
            scheduleBtn.disabled = !group.confirmed;
            scheduleBtn.title = group.confirmed ? '' : String(planGroupTexts.confirmFirst || '');
        }

        if (scheduledBadge) {
            scheduledBadge.classList.toggle('d-none', !isScheduled);
            if (isScheduled) {
                scheduledBadge.href = '../appointments/view.php?id=' + appointmentId;
                const labelEl = scheduledBadge.querySelector('.dcmt-plan-group-scheduled-label');
                if (labelEl) labelEl.textContent = String(planGroupTexts.scheduled || '');
                scheduledBadge.title = group.scheduledStartAt || '';
            }
        }

        const removeBtn = headerRow.querySelector('.plan-group-remove');
        if (removeBtn) removeBtn.disabled = planGroups.length <= 1;
    }

    function updateGroupSubtotals() {
        const totals = {};
        let odontogramTotal = 0;
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
            const groupId = row.getAttribute('data-group-id') || '';
            let subtotal = 0;
            if (row.querySelector('.plan-line-selected')?.checked) {
                subtotal = recalcPlanRow(row);
            } else if (row.querySelector('.plan-subtotal')) {
                row.querySelector('.plan-subtotal').value = '';
            }
            totals[groupId] = (totals[groupId] || 0) + subtotal;
            odontogramTotal += subtotal;
        });

        tbody.querySelectorAll('tr.dcmt-plan-group-row').forEach(function(row) {
            const groupId = row.getAttribute('data-group-row') || '';
            const subtotalEl = row.querySelector('.dcmt-plan-group-subtotal');
            if (subtotalEl) subtotalEl.textContent = fmtMoney(totals[groupId] || 0);
            const rowCount = rowsForGroup(groupId).length;
            const countEl = row.querySelector('.dcmt-plan-group-count');
            if (countEl) {
                countEl.textContent = String(planGroupTexts.items || '{count}')
                    .replace('{count}', String(rowCount));
            }
        });

        return Math.round(odontogramTotal * 100) / 100;
    }

    function layoutPlanGroups() {
        if (!tbody) return;

        const hasLines = tbody.querySelector('tr[data-line-index]') !== null;
        if (!hasLines) {
            tbody.querySelectorAll('tr.dcmt-plan-group-row').forEach(function(row) {
                row.remove();
            });
            return;
        }

        ensureRowGroups();
        populateGroupSelects();

        const existingHeaders = new Map();
        tbody.querySelectorAll('tr.dcmt-plan-group-row').forEach(function(row) {
            const groupId = row.getAttribute('data-group-row') || '';
            if (groupId !== '' && !existingHeaders.has(groupId)) {
                existingHeaders.set(groupId, row);
            } else {
                row.remove();
            }
        });

        const usedGroups = {};
        planGroups.forEach(function(group) {
            const rows = rowsForGroup(group.id);
            let headerRow = existingHeaders.get(group.id);
            if (!headerRow) {
                headerRow = createGroupHeaderRow(group);
            }
            usedGroups[group.id] = true;
            updateGroupHeader(headerRow, group, rows.length);
            tbody.appendChild(headerRow);
            rows.forEach(function(row) {
                tbody.appendChild(row);
            });
        });

        existingHeaders.forEach(function(headerRow, groupId) {
            if (!usedGroups[groupId]) headerRow.remove();
        });

        const emptyRow = document.getElementById('dcmtPlanEmptyRow');
        if (emptyRow) emptyRow.remove();

        updateGroupSubtotals();
    }

    function removePlanGroup(headerRow) {
        if (!headerRow) return;
        const group = findGroup(headerRow.getAttribute('data-group-row') || '');
        if (!group) return;
        if (planGroups.length <= 1) {
            window.alert(planGroupTexts.lastGroup || '');
            return;
        }
        if (!window.confirm(planGroupTexts.removeConfirm || '')) return;

        const fallback = planGroups.find(function(candidate) {
            return candidate.id !== group.id;
        });
        if (!fallback) return;

        rowsForGroup(group.id).forEach(function(row) {
            row.setAttribute('data-group-id', fallback.id);
        });

        planGroups = planGroups.filter(function(candidate) {
            return candidate.id !== group.id;
        });

        headerRow.remove();
        layoutPlanGroups();
        recalcTotal();
    }

    function addAdditionalServiceRow(data) {
        data = data || {};
        const idx = additionalLineCounter++;
        additionalLineMeta.push({
            line_id: data.line_id || ('add_svc_' + idx),
            is_additional: true
        });
        const row = document.createElement('div');
        row.className = 'service-item row mb-2 dcmt-plan-additional-item';
        row.innerHTML =
            '<div class="col-md-3"><select class="form-select plan-additional-doctor">' + doctorOptionsHtml(parseInt(data.doctor_id || '0', 10) || 0) + '</select></div>' +
            '<div class="col-md-3"><select class="form-select plan-additional-service" disabled><option value=""></option></select></div>' +
            '<div class="col-md-1"><input type="number" class="form-control text-center plan-additional-qty" min="1" placeholder="' + String(qtyPlaceholder || '').replace(/"/g, '&quot;') + '" value="' + (data.quantity || 1) + '"></div>' +
            '<div class="col-md-2"><div class="dcmt-amount-input-wrapper"><span class="dcmt-currency-symbol">' + String(currencySymbol || '').replace(/</g, '&lt;') + '</span>' +
            '<input type="number" class="form-control dcmt-amount-input plan-additional-unit-price" min="0" step="0.01" placeholder="' + String(amountPlaceholder || '').replace(/"/g, '&quot;') + '"></div></div>' +
            '<div class="col-md-2"><div class="dcmt-amount-input-wrapper"><span class="dcmt-currency-symbol">' + String(currencySymbol || '').replace(/</g, '&lt;') + '</span>' +
            '<input type="text" class="form-control dcmt-amount-input plan-additional-subtotal" readonly placeholder="' + String(amountPlaceholder || '').replace(/"/g, '&quot;') + '"></div></div>' +
            '<div class="col-md-1 dcmt-delete-cell"><button type="button" class="btn btn-outline-danger btn-sm plan-additional-remove"><i class="fas fa-trash"></i></button></div>';
        additionalBody.appendChild(row);
        initAdditionalRowSelect2(row);
        const doctorId = parseInt(data.doctor_id || '0', 10) || 0;
        const serviceId = parseInt(data.service_id || '0', 10) || 0;
        if (doctorId > 0) {
            populateAdditionalServiceSelect(row, doctorId, serviceId, { preservePrice: !!data.unit_price }).then(function() {
                if (data.unit_price) {
                    const priceInput = row.querySelector('.plan-additional-unit-price');
                    if (priceInput) priceInput.value = Number(data.unit_price).toFixed(2);
                }
                recalcAdditionalRow(row);
                recalcTotal();
            });
        }
        updateAddServiceButtonText();
        recalcTotal();
        return row;
    }

    function removeAdditionalServiceRow(button) {
        const row = button.closest('.dcmt-plan-additional-item');
        if (!row) return;
        const items = Array.from(additionalBody.querySelectorAll('.dcmt-plan-additional-item'));
        const idx = items.indexOf(row);
        destroyRowSelect2(row);
        row.remove();
        if (idx >= 0) additionalLineMeta.splice(idx, 1);
        updateAddServiceButtonText();
        recalcTotal();
    }

    function clearPricesWithoutService() {
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
            const serviceSelect = row.querySelector('.plan-service');
            const priceInput = row.querySelector('.plan-unit-price');
            if (!serviceSelect || !priceInput) return;
            if (!serviceSelect.value) {
                priceInput.value = '';
                const subEl = row.querySelector('.plan-subtotal');
                if (subEl) subEl.value = '';
            }
        });
    }

    if (addPlanServiceBtn) {
        addPlanServiceBtn.addEventListener('click', function() {
            showAdditionalServicesSection();
            addAdditionalServiceRow({});
        });
    }

    if (addPlanGroupBtn) {
        addPlanGroupBtn.addEventListener('click', function() {
            const group = {
                id: nextGroupId(),
                name: nextGroupName(),
                confirmed: false,
                scheduledAppointmentId: 0,
                scheduledStartAt: '',
                scheduled: false
            };
            planGroups.push(group);
            layoutPlanGroups();
            const headerRow = tbody.querySelector('tr.dcmt-plan-group-row[data-group-row="' + group.id + '"]');
            const nameInput = headerRow ? headerRow.querySelector('.dcmt-plan-group-name') : null;
            if (nameInput) {
                nameInput.focus();
                nameInput.select();
            }
        });
    }

    additionalBody.addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.plan-additional-remove');
        if (removeBtn) removeAdditionalServiceRow(removeBtn);
    });
    additionalBody.addEventListener('change', function(e) {
        const row = e.target.closest('.dcmt-plan-additional-item');
        if (!row) return;
        if (e.target.classList.contains('plan-additional-doctor')) {
            populateAdditionalServiceSelect(row, e.target.value, 0, { preservePrice: false });
        } else if (e.target.classList.contains('plan-additional-service')) {
            applyServicePrice(row, '.plan-additional-service', '.plan-additional-unit-price');
            recalcAdditionalRow(row);
            recalcTotal();
        } else {
            recalcAdditionalRow(row);
            recalcTotal();
        }
    });
    additionalBody.addEventListener('input', function(e) {
        const row = e.target.closest('.dcmt-plan-additional-item');
        if (row) {
            recalcAdditionalRow(row);
            recalcTotal();
        }
    });

    tbody.addEventListener('click', function(e) {
        const removeBtn = e.target.closest('.plan-group-remove');
        if (removeBtn) {
            removePlanGroup(removeBtn.closest('tr.dcmt-plan-group-row'));
            return;
        }
        const scheduleBtn = e.target.closest('.plan-group-schedule');
        if (scheduleBtn) {
            const headerRow = scheduleBtn.closest('tr.dcmt-plan-group-row');
            const group = headerRow ? findGroup(headerRow.getAttribute('data-group-row') || '') : null;
            if (group && planScheduler) planScheduler.openGroup(group);
            return;
        }
        const lineScheduleBtn = e.target.closest('.dcmt-plan-schedule-btn');
        if (lineScheduleBtn) {
            const lineRow = lineScheduleBtn.closest('tr[data-line-index]');
            if (lineRow && planScheduler) planScheduler.openRow(lineRow);
        }
    });

    tbody.addEventListener('change', function(e) {
        const headerRow = e.target.closest('tr.dcmt-plan-group-row');
        if (headerRow) {
            if (e.target.classList.contains('plan-group-confirm')) {
                const group = findGroup(headerRow.getAttribute('data-group-row') || '');
                if (group) {
                    group.confirmed = e.target.checked;
                    updateGroupHeader(headerRow, group, rowsForGroup(group.id).length);
                }
            }
            return;
        }

        const row = e.target.closest('tr[data-line-index]');
        if (!row) return;
        if (e.target.classList.contains('plan-group-select')) {
            row.setAttribute('data-group-id', e.target.value);
            layoutPlanGroups();
            recalcTotal();
            return;
        }
        if (e.target.classList.contains('plan-line-selected')) {
            updatePlanRowRequiredState(row);
            recalcTotal();
        } else if (e.target.classList.contains('plan-doctor')) {
            populateServiceSelect(row, e.target.value, 0, { preservePrice: false });
            clearPlanRowInvalid(row);
        } else if (e.target.classList.contains('plan-service')) {
            applyServicePrice(row, '.plan-service', '.plan-unit-price');
            clearPlanRowInvalid(row);
            recalcTotal();
        } else {
            recalcTotal();
        }
    });
    tbody.addEventListener('input', function(e) {
        if (e.target.classList.contains('dcmt-plan-group-name')) {
            const headerRow = e.target.closest('tr.dcmt-plan-group-row');
            const group = headerRow ? findGroup(headerRow.getAttribute('data-group-row') || '') : null;
            if (group) {
                group.name = e.target.value;
                refreshGroupOptionLabels(group);
            }
            return;
        }
        if (e.target.closest('tr[data-line-index]')) recalcTotal();
    });

    if (typeof $ !== 'undefined') {
        $(tbody).on('change', '.plan-doctor', function() {
            const row = this.closest('tr[data-line-index]');
            if (row) {
                populateServiceSelect(row, this.value, 0, { preservePrice: false });
                clearPlanRowInvalid(row);
            }
        });
        $(tbody).on('change', '.plan-service', function() {
            const row = this.closest('tr[data-line-index]');
            if (row) {
                applyServicePrice(row, '.plan-service', '.plan-unit-price');
                clearPlanRowInvalid(row);
                recalcTotal();
            }
        });
        $(additionalBody).on('change', '.plan-additional-doctor', function() {
            const row = this.closest('.dcmt-plan-additional-item');
            if (row) populateAdditionalServiceSelect(row, this.value, 0, { preservePrice: false });
        });
        $(additionalBody).on('change', '.plan-additional-service', function() {
            const row = this.closest('.dcmt-plan-additional-item');
            if (row) {
                applyServicePrice(row, '.plan-additional-service', '.plan-additional-unit-price');
                recalcAdditionalRow(row);
                recalcTotal();
            }
        });
    }

    tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
        initPlanRowSelect2(row);
        updatePlanRowRequiredState(row);
    });
    additionalBody.querySelectorAll('.dcmt-plan-additional-item').forEach(initAdditionalRowSelect2);
    updateAddServiceButtonText();
    clearPricesWithoutService();

    form.addEventListener('submit', function(e) {
        if (!validateSelectedPlanLines()) {
            e.preventDefault();
            return;
        }
        hiddenJson.value = JSON.stringify(collectLines());
    });

    function initPlanScheduling() {
        const modalEl = document.getElementById('dcmtPlanScheduleModal');
        if (!modalEl || !canSchedulePlan) return null;

        const cfg = scheduleConfig || {};
        let modal = null;
        function getModal() {
            if (modal) return modal;
            if (typeof bootstrap === 'undefined' || !bootstrap.Modal) return null;
            modal = bootstrap.Modal.getOrCreateInstance(modalEl);
            return modal;
        }
        const modalAlert = document.getElementById('dcmtPlanScheduleAlert');
        const summaryEl = document.getElementById('dcmtPlanScheduleSummary');
        const dateEl = document.getElementById('dcmtPlanScheduleDate');
        const operatoryEl = document.getElementById('dcmtPlanScheduleOperatory');
        const startEl = document.getElementById('dcmtPlanScheduleStart');
        const durationEl = document.getElementById('dcmtPlanScheduleDuration');
        const endLabelEl = document.getElementById('dcmtPlanScheduleEndLabel');
        const reasonEl = document.getElementById('dcmtPlanScheduleReason');
        const notesEl = document.getElementById('dcmtPlanScheduleNotes');
        const slotsWrap = document.getElementById('dcmtPlanScheduleSlotsWrap');
        const slotsEl = document.getElementById('dcmtPlanScheduleAvailableList');
        const bookedEl = document.getElementById('dcmtPlanScheduleBookedList');
        const submitBtn = document.getElementById('dcmtPlanScheduleSubmit');
        const fullFormLink = document.getElementById('dcmtPlanScheduleFullForm');
        const doctorEl = document.getElementById('dcmtPlanScheduleDoctor');
        const modalTitleEl = document.getElementById('dcmtPlanScheduleModalLabel');
        const originalTitleText = modalTitleEl ? modalTitleEl.textContent : '';
        let activeTarget = null;

        function showModalAlert(message, type) {
            if (!modalAlert) return;
            modalAlert.className = 'alert alert-' + (type || 'danger');
            modalAlert.textContent = message || '';
            modalAlert.classList.remove('d-none');
        }

        function clearModalAlert() {
            if (!modalAlert) return;
            modalAlert.className = 'alert d-none';
            modalAlert.textContent = '';
        }

        function showPageAlert(message, type) {
            const container = document.querySelector('.dcmt-add-form-container');
            let box = document.getElementById('dcmtPlanSchedulePageAlert');
            if (!box) {
                box = document.createElement('div');
                box.id = 'dcmtPlanSchedulePageAlert';
                box.setAttribute('role', 'alert');
                box.setAttribute('data-persistent', 'true');
                const header = container ? container.querySelector('.dcmt-add-form-header') : null;
                if (container && header && header.nextSibling) {
                    container.insertBefore(box, header.nextSibling);
                } else if (container) {
                    container.appendChild(box);
                }
            }
            box.className = 'alert alert-' + (type || 'success');
            box.textContent = message || '';
        }

        function rowMeta(row) {
            const idx = parseInt(row.getAttribute('data-line-index') || '-1', 10);
            return lineMeta[idx] || {};
        }

        function doctorName(doctorId) {
            doctorId = parseInt(doctorId || '0', 10) || 0;
            const match = (doctorsList || []).find(function(doc) {
                return parseInt(doc.dcmt_id, 10) === doctorId;
            });
            return match ? String(match.dcmt_name || '') : '';
        }

        function addMinutes(value, minutes) {
            const parts = String(value || '').split(':');
            if (parts.length < 2) return '';
            const total = (parseInt(parts[0], 10) || 0) * 60 + (parseInt(parts[1], 10) || 0) + (parseInt(minutes, 10) || 0);
            const hours = Math.floor(total / 60) % 24;
            const mins = total % 60;
            return ('0' + hours).slice(-2) + ':' + ('0' + mins).slice(-2);
        }

        function updateEndLabel() {
            if (!endLabelEl) return;
            const start = startEl ? String(startEl.value || '') : '';
            endLabelEl.textContent = start !== ''
                ? String(cfg.endLabel || '').replace('{time}', addMinutes(start, durationEl ? durationEl.value : 30))
                : '';
        }

        function highlightActiveSlot() {
            if (!slotsEl) return;
            const activeStart = startEl ? String(startEl.value || '') : '';
            slotsEl.querySelectorAll('button.dcmt-slot-pill').forEach(function(btn) {
                const isActive = activeStart !== '' && btn.getAttribute('data-slot-start') === activeStart;
                btn.classList.toggle('is-active', isActive);
                btn.classList.toggle('btn-success', isActive);
                btn.classList.toggle('btn-outline-success', !isActive);
            });
        }

        function clearSlotsPanel() {
            if (slotsWrap) slotsWrap.classList.add('d-none');
            if (slotsEl) slotsEl.innerHTML = '';
            if (bookedEl) bookedEl.innerHTML = '';
        }

        function renderSlots(slots, busy) {
            if (!slotsWrap) return;
            slotsWrap.classList.remove('d-none');

            if (slotsEl) {
                if (!Array.isArray(slots) || slots.length === 0) {
                    slotsEl.innerHTML = '<span class="text-muted">' + escapeGroupText(cfg.slotsNoFree || '') + '</span>';
                } else {
                    slotsEl.innerHTML = '<div class="row g-2">' + slots.map(function(slot) {
                        const isActive = startEl && String(startEl.value || '') === String(slot.start);
                        const cls = isActive
                            ? 'btn btn-sm w-100 rounded-pill py-1 px-2 btn-success dcmt-slot-pill is-active'
                            : 'btn btn-sm w-100 rounded-pill py-1 px-2 btn-outline-success dcmt-slot-pill';
                        return '<div class="col-6 col-md-4">' +
                            '<button type="button" class="' + cls + '"' +
                            ' data-slot-start="' + escapeGroupAttr(slot.start) + '"' +
                            ' data-slot-end="' + escapeGroupAttr(slot.end) + '">' +
                            '<span class="small">' + escapeGroupText(slot.start) + '–' + escapeGroupText(slot.end) + '</span>' +
                            '</button></div>';
                    }).join('') + '</div>';

                    slotsEl.querySelectorAll('button.dcmt-slot-pill').forEach(function(btn) {
                        btn.addEventListener('click', function() {
                            const slotStart = btn.getAttribute('data-slot-start') || '';
                            const slotEnd = btn.getAttribute('data-slot-end') || '';
                            if (startEl) startEl.value = slotStart;
                            if (slotEnd) {
                                const startMinutes = parseInt(String(slotStart).split(':')[0], 10) * 60 + parseInt(String(slotStart).split(':')[1], 10);
                                const endMinutes = parseInt(String(slotEnd).split(':')[0], 10) * 60 + parseInt(String(slotEnd).split(':')[1], 10);
                                if (endMinutes > startMinutes && durationEl) {
                                    durationEl.value = String(endMinutes - startMinutes);
                                }
                            }
                            updateEndLabel();
                            highlightActiveSlot();
                        });
                    });
                }
            }

            if (bookedEl) {
                if (Array.isArray(busy) && busy.length) {
                    bookedEl.innerHTML = busy.map(function(slot) {
                        return '<span class="badge rounded-pill bg-secondary me-1 mb-1">' +
                            escapeGroupText(slot.start) + '–' + escapeGroupText(slot.end) +
                            '</span>';
                    }).join(' ');
                } else {
                    bookedEl.innerHTML = '<span class="text-muted">' + escapeGroupText(cfg.slotsNoneBooked || '') + '</span>';
                }
            }

            highlightActiveSlot();
        }

        function loadSlots() {
            if (!activeTarget || !slotsWrap) return;
            const doctorId = parseInt(doctorEl ? doctorEl.value : '0', 10) || 0;
            const operatoryId = parseInt(operatoryEl ? operatoryEl.value : '0', 10) || 0;
            const date = dateEl ? String(dateEl.value || '') : '';
            if (doctorId <= 0 || operatoryId <= 0 || date === '') {
                clearSlotsPanel();
                return;
            }
            const url = String(cfg.slotsUrl || '') +
                '?doctor_id=' + encodeURIComponent(doctorId) +
                '&operatory_id=' + encodeURIComponent(operatoryId) +
                '&date=' + encodeURIComponent(date) +
                '&duration=' + encodeURIComponent(durationEl ? durationEl.value : 30);
            fetch(url, { method: 'GET', cache: 'no-store' })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (!data || !data.success) {
                        clearSlotsPanel();
                        return;
                    }
                    renderSlots(data.slots || [], data.busy || []);
                })
                .catch(function() {
                    clearSlotsPanel();
                });
        }

        function resetScheduleForm() {
            if (notesEl) notesEl.value = '';
            if (dateEl) dateEl.value = cfg.today || dateEl.value;
            if (startEl) startEl.value = '';
            if (durationEl) durationEl.value = String(cfg.defaultDuration || 30);
            if (operatoryEl) operatoryEl.value = '';
            clearModalAlert();
            if (slotsWrap) slotsWrap.classList.add('d-none');
            if (slotsEl) slotsEl.innerHTML = '';
            if (bookedEl) bookedEl.innerHTML = '';
            updateEndLabel();
        }

        function updateFullFormLink(doctorId, reason) {
            if (!fullFormLink) return;
            fullFormLink.href = String(cfg.fullFormUrl || '') +
                '?patient_id=' + encodeURIComponent(cfg.patientId) +
                (doctorId > 0 ? '&doctor_id=' + encodeURIComponent(doctorId) : '') +
                '&date=' + encodeURIComponent(dateEl ? dateEl.value : '') +
                '&reason=' + encodeURIComponent(reason || '');
        }

        function openForRow(row) {
            if (!row) return;
            activeTarget = { type: 'line', row: row };
            if (modalTitleEl) modalTitleEl.textContent = originalTitleText;

            const doctorSelect = row.querySelector('.plan-doctor');
            const doctorId = parseInt(doctorSelect ? doctorSelect.value : '0', 10) || 0;
            if (doctorEl) {
                doctorEl.value = doctorId > 0 ? String(doctorId) : '';
                doctorEl.disabled = true;
            }

            const meta = rowMeta(row);
            const treatment = String(meta.treatment || '');
            const location = [meta.zone_label, meta.quadrant_label].filter(Boolean).join(' - ');
            const qty = Math.max(1, parseInt(row.querySelector('.plan-qty') ? row.querySelector('.plan-qty').value : '1', 10));

            let reason = treatment;
            if (qty > 1) reason += ' x' + qty;
            if (location) reason += ' (' + location + ')';

            if (summaryEl) {
                summaryEl.textContent = String(cfg.summaryLabel || '')
                    .replace('{treatment}', treatment)
                    .replace('{location}', location || '—')
                    .replace('{doctor}', doctorName(doctorId) || '—');
            }
            if (reasonEl) reasonEl.value = reason;
            resetScheduleForm();
            updateFullFormLink(doctorId, reason);

            const modalInstance = getModal();
            if (modalInstance) {
                modalInstance.show();
            }

            if (doctorId <= 0) {
                showModalAlert(cfg.doctorRequired, 'warning');
                return;
            }
            if (operatoryEl && operatoryEl.options.length <= 1) {
                showModalAlert(cfg.operatoryEmpty, 'warning');
            }
        }

        function openGroup(group) {
            if (!group) return;
            activeTarget = { type: 'group', groupId: group.id };
            if (modalTitleEl) {
                modalTitleEl.textContent = String(cfg.groupTitle || '') || originalTitleText;
            }

            const rows = rowsForGroup(group.id);
            const firstDoctorId = rows.length
                ? (parseInt(rows[0].querySelector('.plan-doctor')?.value || '0', 10) || 0)
                : 0;
            if (doctorEl) {
                doctorEl.disabled = false;
                doctorEl.value = firstDoctorId > 0 ? String(firstDoctorId) : '';
            }

            const count = groupLineCount(group.id);
            const subtotal = groupSubtotal(group.id);
            if (summaryEl) {
                summaryEl.textContent = String(cfg.groupSummaryLabel || '')
                    .replace('{group}', group.name || '')
                    .replace('{count}', String(count))
                    .replace('{subtotal}', fmtMoney(subtotal));
            }

            const reason = groupReason(group);
            if (reasonEl) reasonEl.value = reason;
            resetScheduleForm();
            updateFullFormLink(firstDoctorId, reason);

            const modalInstance = getModal();
            if (modalInstance) {
                modalInstance.show();
            }

            if (!group.confirmed) {
                showModalAlert(cfg.groupConfirmFirst || planGroupTexts.confirmFirst, 'warning');
                return;
            }
            if (operatoryEl && operatoryEl.options.length <= 1) {
                showModalAlert(cfg.operatoryEmpty, 'warning');
            }
        }

        function renderScheduledCell(row, data) {
            const appointmentId = parseInt(data.appointment_id || '0', 10) || 0;
            const cell = row ? row.querySelector('.dcmt-plan-schedule-cell') : null;
            if (!cell || appointmentId <= 0) return;
            cell.innerHTML = '';

            const link = document.createElement('a');
            link.href = '../appointments/view.php?id=' + appointmentId;
            link.target = '_blank';
            link.rel = 'noopener noreferrer';
            link.className = 'badge bg-success text-decoration-none dcmt-plan-scheduled-badge';
            link.title = String(data.scheduled_start_display || cfg.scheduledLabel || '');

            const icon = document.createElement('i');
            icon.className = 'fas fa-calendar-check me-1';
            link.appendChild(icon);
            link.appendChild(document.createTextNode(String(cfg.scheduledLabel || '')));

            if (data.scheduled_start_display) {
                const sub = document.createElement('span');
                sub.className = 'd-block fw-normal small';
                sub.textContent = String(data.scheduled_start_display);
                link.appendChild(sub);
            }
            cell.appendChild(link);
        }

        function applyScheduled(row, data) {
            if (!row) return;
            const appointmentId = parseInt(data.appointment_id || '0', 10) || 0;
            row.setAttribute('data-scheduled-appointment-id', String(appointmentId));
            row.setAttribute('data-scheduled-start-at', String(data.scheduled_start_at || ''));
            renderScheduledCell(row, data);
        }

        function applyGroupScheduled(groupId, data) {
            const group = findGroup(groupId);
            const appointmentId = parseInt(data.appointment_id || '0', 10) || 0;
            if (group) {
                group.scheduledAppointmentId = appointmentId;
                group.scheduledStartAt = String(data.scheduled_start_at || '');
                group.scheduled = appointmentId > 0;
            }

            const rows = rowsForGroup(groupId);
            rows.forEach(function(row) {
                row.setAttribute('data-scheduled-appointment-id', String(appointmentId));
                row.setAttribute('data-scheduled-start-at', String(data.scheduled_start_at || ''));
                renderScheduledCell(row, data);
            });

            const headerRow = tbody.querySelector('tr.dcmt-plan-group-row[data-group-row="' + groupId + '"]');
            if (headerRow && group) {
                updateGroupHeader(headerRow, group, rows.length);
            }
        }

        function submitSchedule(confirmed) {
            if (!activeTarget) return;
            const doctorId = parseInt(doctorEl ? doctorEl.value : '0', 10) || 0;
            if (doctorId <= 0) {
                showModalAlert(cfg.doctorRequired, 'warning');
                return;
            }
            const operatoryId = parseInt(operatoryEl ? operatoryEl.value : '0', 10) || 0;
            if (operatoryId <= 0) {
                showModalAlert(cfg.operatoryRequired, 'warning');
                return;
            }
            if (!startEl || String(startEl.value || '') === '') {
                showModalAlert(cfg.startRequired, 'warning');
                return;
            }

            const formData = new FormData();
            formData.append('csrf_token', cfg.csrfToken || '');
            formData.append('patient_id', String(cfg.patientId || 0));
            formData.append('doctor_id', String(doctorId));
            formData.append('operatory_id', String(operatoryId));
            formData.append('appointment_date', dateEl ? dateEl.value : '');
            formData.append('start_time', startEl.value);
            formData.append('duration', durationEl ? String(durationEl.value || 30) : '30');
            formData.append('reason', reasonEl ? reasonEl.value.trim() : '');
            formData.append('notes', notesEl ? notesEl.value.trim() : '');
            if (activeTarget.type === 'group') {
                formData.append('plan_group_id', String(activeTarget.groupId || ''));
            } else {
                formData.append('plan_line_id', String(rowMeta(activeTarget.row).line_id || ''));
            }
            formData.append('plan_lines_json', JSON.stringify(collectLines()));
            if (confirmed) formData.append('confirm_outside_hours', '1');

            const originalLabel = submitBtn ? submitBtn.innerHTML : '';
            if (submitBtn) {
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + (cfg.processing || '') + '...';
            }

            fetch(String(cfg.url || ''), { method: 'POST', body: formData })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalLabel;
                    }
                    if (!data || !data.success) {
                        if (data && data.needs_outside_hours_confirm && !confirmed) {
                            if (window.confirm(data.message || '')) {
                                submitSchedule(true);
                            }
                            return;
                        }
                        showModalAlert((data && data.message) || cfg.failed, 'danger');
                        return;
                    }
                    if (activeTarget && activeTarget.type === 'group') {
                        applyGroupScheduled(activeTarget.groupId, data);
                    } else if (activeTarget && activeTarget.row) {
                        applyScheduled(activeTarget.row, data);
                    }
                    const modalInstance = getModal();
                    if (modalInstance) {
                        modalInstance.hide();
                    }
                    showPageAlert(data.message || cfg.scheduledLabel, 'success');
                })
                .catch(function() {
                    if (submitBtn) {
                        submitBtn.disabled = false;
                        submitBtn.innerHTML = originalLabel;
                    }
                    showModalAlert(cfg.failed, 'danger');
                });
        }

        function initScheduleDoctorSelect2() {
            if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function' || !doctorEl) return;
            const $doctor = $(doctorEl);
            if ($doctor.hasClass('select2-hidden-accessible')) {
                $doctor.off('select2:open.dcmtFocusSearch');
                $doctor.select2('destroy');
            }
            $doctor.select2({
                dropdownParent: $(modalEl),
                width: '100%',
                placeholder: doctorPlaceholder,
                allowClear: !doctorEl.disabled
            });
            $doctor.off('select2:open.dcmtFocusSearch').on('select2:open.dcmtFocusSearch', function() {
                window.setTimeout(function() {
                    const search = document.querySelector('.select2-container--open .select2-search__field');
                    if (search) {
                        search.focus();
                    }
                }, 0);
            });
        }

        if (operatoryEl) operatoryEl.addEventListener('change', loadSlots);
        if (doctorEl) doctorEl.addEventListener('change', loadSlots);
        if (dateEl) dateEl.addEventListener('change', loadSlots);
        if (durationEl) durationEl.addEventListener('change', function() {
            updateEndLabel();
            loadSlots();
        });
        if (startEl) startEl.addEventListener('change', updateEndLabel);
        if (submitBtn) submitBtn.addEventListener('click', function() { submitSchedule(false); });
        modalEl.addEventListener('shown.bs.modal', initScheduleDoctorSelect2);
        modalEl.addEventListener('hidden.bs.modal', function() {
            activeTarget = null;
        });

        return { openRow: openForRow, openGroup: openGroup };
    }

    layoutPlanGroups();
    planScheduler = initPlanScheduling();
    recalcTotal();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
