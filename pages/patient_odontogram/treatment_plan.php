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
if ($current_user_id > 0 && ($dcmt_current_user['dcmt_role'] ?? '') === 'doctor') {
    $default_doctor_id = $current_user_id;
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
$plan_total = dcmt_treatment_plan_calculate_total($plan_lines);
$has_solution_data = !empty($summary['has_data']);

$csrf_token = dcmt_generate_csrf_token();
$back_url = '../patient_odontogram/view.php?patient_id=' . $patient_id;
$print_url = 'print_treatment_plan.php?patient_id=' . $patient_id;

$cell_headers = dcmt_patient_odontogram_summary_cell_headers();

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">
<link href="../../assets/css/select2.min.css" rel="stylesheet">

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
                    <h5 class="dcmt-plan-section-title mb-0">
                        <i class="fas fa-clipboard-check dcmt-plan-section-icon"></i>
                        <?php echo htmlspecialchars(trans('patient', 'treatment_plan_lines_title')); ?>
                    </h5>
                </div>

                <div class="table-responsive dcmt-treatment-plan-panel">
                    <table class="table align-middle mb-0" id="dcmtTreatmentPlanTable">
                        <thead>
                            <tr>
                                <th class="dcmt-plan-col-check"></th>
                                <th class="dcmt-plan-col-treatment"><?php echo trans('patient', 'treatment_plan_col_treatment'); ?></th>
                                <th class="dcmt-plan-col-location"><?php echo trans('patient', 'treatment_plan_col_location'); ?></th>
                                <th class="dcmt-plan-col-qty text-center"><?php echo trans('patient', 'treatment_plan_col_qty'); ?></th>
                                <th class="dcmt-plan-col-doctor"><?php echo trans('patient', 'treatment_plan_doctor'); ?> <span class="text-danger">*</span></th>
                                <th class="dcmt-plan-col-service"><?php echo trans('patient', 'treatment_plan_col_service'); ?> <span class="text-danger">*</span></th>
                                <th class="dcmt-plan-col-price"><?php echo trans('patient', 'treatment_plan_col_unit_price'); ?></th>
                                <th class="dcmt-plan-col-subtotal"><?php echo trans('patient', 'treatment_plan_col_subtotal'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="dcmtTreatmentPlanBody">
                            <?php if (empty($odontogram_plan_lines)): ?>
                                <tr id="dcmtPlanEmptyRow">
                                    <td colspan="8" class="text-muted text-center py-3">
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
                                    ?>
                                    <tr data-line-index="<?php echo (int) $idx; ?>">
                                        <td class="dcmt-plan-col-check text-center">
                                            <input type="checkbox" class="form-check-input plan-line-selected" <?php echo !empty($line['selected']) ? 'checked' : ''; ?>>
                                        </td>
                                        <td class="dcmt-plan-col-treatment plan-treatment"><?php echo htmlspecialchars($line['treatment']); ?></td>
                                        <td class="dcmt-plan-col-location plan-location">
                                            <?php echo htmlspecialchars($line_location); ?>
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

<script src="../../assets/js/select2.min.js"></script>
<script>
(function() {
    const planLinesSeed = <?php echo json_encode($odontogram_plan_lines, JSON_UNESCAPED_UNICODE); ?>;
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
    const fmtMoney = (n) => Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + currencySuffix;

    const tbody = document.getElementById('dcmtTreatmentPlanBody');
    const additionalBody = document.getElementById('dcmtAdditionalServicesBody');
    const additionalSection = document.getElementById('dcmtAdditionalServicesSection');
    const additionalPanel = document.getElementById('dcmtAdditionalServicesPanel');
    const addPlanServiceBtn = document.getElementById('addPlanServiceBtn');
    const addPlanServiceBtnText = document.getElementById('addPlanServiceBtnText');
    const totalCell = document.getElementById('planTotalCell');
    const hiddenJson = document.getElementById('plan_lines_json');
    const form = document.getElementById('dcmtTreatmentPlanForm');

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
        let total = 0;
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row) {
            if (row.querySelector('.plan-line-selected')?.checked) {
                total += recalcPlanRow(row);
            }
        });
        additionalBody.querySelectorAll('.dcmt-plan-additional-item').forEach(function(row) {
            total += recalcAdditionalRow(row);
        });
        total = Math.round(total * 100) / 100;
        if (totalCell) totalCell.textContent = fmtMoney(total);
        return total;
    }

    function collectOdontogramLines() {
        const lines = [];
        tbody.querySelectorAll('tr[data-line-index]').forEach(function(row, idx) {
            const meta = lineMeta[idx] || {};
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
            lines.push({
                line_id: meta.line_id || ('line_' + (idx + 1)),
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
                is_additional: false
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

    tbody.addEventListener('change', function(e) {
        const row = e.target.closest('tr[data-line-index]');
        if (!row) return;
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

    recalcTotal();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
