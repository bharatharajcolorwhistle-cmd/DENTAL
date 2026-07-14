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
$doctor_id = $saved_plan['dcmt_doctor_user_id'] ?? $default_doctor_id;
$plan_notes = $saved_plan['dcmt_notes'] ?? '';
$plan_synced_from_odontogram = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient', 'invalid_token');
    } else {
        $doctor_id = isset($_POST['doctor_id']) ? (int) $_POST['doctor_id'] : 0;
        $plan_notes = trim((string) ($_POST['plan_notes'] ?? ''));
        $posted_lines = json_decode((string) ($_POST['plan_lines_json'] ?? ''), true);
        if (!is_array($posted_lines)) {
            $errors[] = trans('patient', 'treatment_plan_invalid_lines');
        }

        if (empty($errors)) {
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
    (int) $doctor_id,
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
            $doctor_id = $saved_plan['dcmt_doctor_user_id'] ?? $doctor_id;
        }
    }
} elseif ($regenerate || !$saved_plan || empty($saved_plan['lines'])) {
    $plan_lines = dcmt_build_treatment_plan_lines($dcmt_pdo, $solution_chart, $doctor_id);
} else {
    $plan_lines = dcmt_normalize_treatment_plan_lines($saved_plan['lines'], $dcmt_pdo, $doctor_id);
}

$services_catalog = dcmt_fetch_active_services_catalog($dcmt_pdo, $doctor_id);
$plan_total = dcmt_treatment_plan_calculate_total($plan_lines);
$has_solution_data = !empty($summary['has_data']);

$csrf_token = dcmt_generate_csrf_token();
$back_url = '../patient_odontogram/view.php?patient_id=' . $patient_id;
$print_url = 'print_treatment_plan.php?patient_id=' . $patient_id;

$cell_headers = [];
foreach (dcmt_patient_odontogram_zone_keys() as $zone) {
    $zoneShort = ($zone === 'anterior') ? 'A' : 'P';
    foreach (dcmt_patient_odontogram_quadrant_keys() as $quadrant) {
        $cell_headers[] = [
            'key' => $zone . '_' . $quadrant,
            'label' => $zoneShort . ' ' . dcmt_patient_odontogram_quadrant_label($quadrant),
            'title' => dcmt_patient_odontogram_zone_label($zone) . ' ' . dcmt_patient_odontogram_quadrant_label($quadrant),
        ];
    }
}

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
        <div class="alert alert-warning">
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

        <div class="card dcmt-plan-section-card mb-4">
            <div class="card-body">
                <div class="dcmt-plan-section-header dcmt-plan-section-header--plan">
                    <h5 class="dcmt-plan-section-title mb-0">
                        <i class="fas fa-clipboard-check dcmt-plan-section-icon"></i>
                        <?php echo htmlspecialchars(trans('patient', 'treatment_plan_lines_title')); ?>
                    </h5>
                    <div class="dcmt-plan-doctor-field">
                        <label for="doctor_id" class="form-label"><?php echo trans('patient', 'treatment_plan_doctor'); ?></label>
                        <select class="form-select" id="doctor_id" name="doctor_id">
                            <option value=""></option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?php echo (int) $doc['dcmt_id']; ?>" <?php echo $doctor_id === (int) $doc['dcmt_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($doc['dcmt_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                </div>

                <div class="table-responsive dcmt-treatment-plan-panel">
                    <table class="table align-middle mb-0" id="dcmtTreatmentPlanTable">
                        <thead>
                            <tr>
                                <th class="dcmt-plan-col-check"></th>
                                <th class="dcmt-plan-col-treatment"><?php echo trans('patient', 'treatment_plan_col_treatment'); ?></th>
                                <th class="dcmt-plan-col-location"><?php echo trans('patient', 'treatment_plan_col_location'); ?></th>
                                <th class="dcmt-plan-col-qty text-center"><?php echo trans('patient', 'treatment_plan_col_qty'); ?></th>
                                <th class="dcmt-plan-col-service"><?php echo trans('patient', 'treatment_plan_col_service'); ?></th>
                                <th class="dcmt-plan-col-price text-end"><?php echo htmlspecialchars(str_replace('MXN', dcmt_get_current_currency(), trans('patient', 'treatment_plan_col_unit_price_mxn'))); ?></th>
                                <th class="dcmt-plan-col-subtotal text-end"><?php echo trans('patient', 'treatment_plan_col_subtotal'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="dcmtTreatmentPlanBody">
                            <?php if (empty($plan_lines)): ?>
                                <tr id="dcmtPlanEmptyRow">
                                    <td colspan="7" class="text-muted text-center py-3">
                                        <?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_treatments')); ?>
                                    </td>
                                </tr>
                            <?php else: ?>
                                <?php foreach ($plan_lines as $idx => $line): ?>
                                    <?php
                                    $line_service_id = (int) ($line['service_id'] ?? 0);
                                    $line_has_service = false;
                                    if ($line_service_id > 0) {
                                        foreach ($services_catalog as $svc) {
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
                                    $line_subtotal_display = ($line_has_service && $line_unit_price_value !== '')
                                        ? dcmt_format_currency($line['subtotal'])
                                        : dcmt_format_currency(0);
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
                                        <td class="dcmt-plan-col-service">
                                            <select class="form-select plan-service">
                                                <option value=""></option>
                                                <?php foreach ($services_catalog as $svc): ?>
                                                    <option value="<?php echo (int) $svc['id']; ?>"
                                                            data-price="<?php echo htmlspecialchars((string) $svc['price']); ?>"
                                                            <?php echo $line_service_id === (int) $svc['id'] ? 'selected' : ''; ?>>
                                                        <?php echo htmlspecialchars($svc['name']); ?>
                                                    </option>
                                                <?php endforeach; ?>
                                            </select>
                                        </td>
                                        <td class="dcmt-plan-col-price">
                                            <input type="number" class="form-control text-end plan-unit-price" min="0" step="0.01"
                                                   value="<?php echo htmlspecialchars($line_unit_price_value); ?>"
                                                   placeholder="0.00">
                                        </td>
                                        <td class="dcmt-plan-col-subtotal text-end plan-subtotal"><?php echo $line_subtotal_display; ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endif; ?>
                        </tbody>
                        <tfoot>
                            <tr>
                                <td colspan="6" class="text-end dcmt-plan-grand-total-label"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_grand_total')); ?>:</td>
                                <td class="text-end dcmt-plan-grand-total-value" id="planTotalCell"><?php echo dcmt_format_currency($plan_total); ?></td>
                            </tr>
                        </tfoot>
                    </table>
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
    const planLinesSeed = <?php echo json_encode($plan_lines, JSON_UNESCAPED_UNICODE); ?>;
    const servicesCatalog = <?php echo json_encode($services_catalog, JSON_UNESCAPED_UNICODE); ?>;
    const currencySuffix = <?php echo json_encode(' ' . dcmt_get_current_currency()); ?>;
    const doctorPlaceholder = <?php echo json_encode(trans('patient', 'treatment_plan_select_doctor'), JSON_UNESCAPED_UNICODE); ?>;
    const servicePlaceholder = <?php echo json_encode(trans('patient', 'treatment_plan_select_service'), JSON_UNESCAPED_UNICODE); ?>;
    const fmtMoney = (n) => Number(n || 0).toFixed(2).replace(/\B(?=(\d{3})+(?!\d))/g, ',') + currencySuffix;

    const tbody = document.getElementById('dcmtTreatmentPlanBody');
    const totalCell = document.getElementById('planTotalCell');
    const totalBadge = null;
    const hiddenJson = document.getElementById('plan_lines_json');
    const doctorSelect = document.getElementById('doctor_id');
    const form = document.getElementById('dcmtTreatmentPlanForm');

    if (!tbody || !form) return;

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
        const isService = $select.hasClass('plan-service');
        const $doctorWrap = $select.closest('.dcmt-plan-doctor-field');
        $select.select2({
            placeholder: placeholder,
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0,
            dropdownParent: isService
                ? $(document.body)
                : ($doctorWrap.length ? $doctorWrap : $select.parent())
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

    function initAllServiceSelect2() {
        tbody.querySelectorAll('.plan-service').forEach(function(select) {
            ensureSelect2(select, servicePlaceholder);
        });
    }

    function destroyServiceSelect2InTbody() {
        if (typeof $ === 'undefined') {
            return;
        }
        tbody.querySelectorAll('.plan-service').forEach(function(select) {
            const $select = $(select);
            if ($select.hasClass('select2-hidden-accessible')) {
                try {
                    $select.select2('destroy');
                } catch (e) {
                    // ignore
                }
            }
        });
    }

    function recalcRow(row) {
        const serviceSelect = row.querySelector('.plan-service');
        const serviceId = parseInt(serviceSelect?.value || '0', 10);
        const qty = Math.max(1, parseInt(row.querySelector('.plan-qty')?.value || '1', 10));
        const priceRaw = row.querySelector('.plan-unit-price')?.value;
        const price = (serviceId > 0 && priceRaw !== '' && priceRaw !== null)
            ? (parseFloat(priceRaw) || 0)
            : 0;
        const subtotal = Math.round(qty * price * 100) / 100;
        const subEl = row.querySelector('.plan-subtotal');
        if (subEl) {
            subEl.textContent = fmtMoney(subtotal);
        }
        return subtotal;
    }

    function recalcTotal() {
        let total = 0;
        tbody.querySelectorAll('tr[data-line-index]').forEach(row => {
            const selected = row.querySelector('.plan-line-selected')?.checked;
            if (selected) total += recalcRow(row);
        });
        total = Math.round(total * 100) / 100;
        if (totalCell) totalCell.textContent = fmtMoney(total);
        if (totalBadge) totalBadge.textContent = fmtMoney(total);
        return total;
    }

    function collectLines() {
        const lines = [];
        tbody.querySelectorAll('tr[data-line-index]').forEach((row, idx) => {
            const meta = lineMeta[idx] || {};
            const serviceSelect = row.querySelector('.plan-service');
            const serviceId = parseInt(serviceSelect?.value || '0', 10);
            let serviceName = '';
            if (serviceId > 0 && serviceSelect && serviceSelect.selectedOptions && serviceSelect.selectedOptions[0]) {
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
                service_id: serviceId,
                service_name: serviceName,
                unit_price: unitPrice,
                subtotal: Math.round(qty * unitPrice * 100) / 100,
                selected: row.querySelector('.plan-line-selected')?.checked ? true : false
            });
        });
        return lines;
    }

    function onServiceChange(row) {
        const select = row.querySelector('.plan-service');
        const priceInput = row.querySelector('.plan-unit-price');
        if (!select || !priceInput) return;
        const serviceId = parseInt(select.value || '0', 10);
        const opt = select.selectedOptions[0];
        if (serviceId > 0 && opt && opt.dataset.price !== undefined && opt.dataset.price !== '') {
            priceInput.value = Number(opt.dataset.price).toFixed(2);
        } else {
            priceInput.value = '';
        }
        recalcTotal();
    }

    function rebuildPlanRows(lines, catalog) {
        destroyServiceSelect2InTbody();
        tbody.innerHTML = '';
        lineMeta = lines.map((line, i) => ({
            line_id: line.line_id || ('line_' + (i + 1)),
            treatment: line.treatment,
            zone: line.zone,
            quadrant: line.quadrant,
            quadrant_label: line.quadrant_label,
            zone_label: line.zone_label
        }));
        lines.forEach((line, idx) => {
            const tr = document.createElement('tr');
            tr.dataset.lineIndex = String(idx);
            let options = '<option value=""></option>';
            catalog.forEach(svc => {
                const sel = parseInt(line.service_id, 10) === parseInt(svc.id, 10) ? ' selected' : '';
                options += '<option value="' + svc.id + '" data-price="' + svc.price + '"' + sel + '>' +
                    (svc.name || '').replace(/</g, '&lt;') + '</option>';
            });
            const hasService = parseInt(line.service_id, 10) > 0;
            const priceValue = (hasService && Number(line.unit_price || 0) > 0)
                ? Number(line.unit_price || 0).toFixed(2)
                : '';
            const subtotalText = priceValue !== '' ? fmtMoney(line.subtotal) : fmtMoney(0);
            const locationText = ((line.zone_label || '') + ' - ' + (line.quadrant_label || '')).replace(/^ - | - $/g, '').replace(/</g, '&lt;');
            tr.innerHTML =
                '<td class="dcmt-plan-col-check text-center"><input type="checkbox" class="form-check-input plan-line-selected"' + (line.selected ? ' checked' : '') + '></td>' +
                '<td class="dcmt-plan-col-treatment plan-treatment">' + (line.treatment || '').replace(/</g, '&lt;') + '</td>' +
                '<td class="dcmt-plan-col-location plan-location">' + locationText + '</td>' +
                '<td class="dcmt-plan-col-qty text-center"><input type="number" class="form-control text-center plan-qty" min="1" value="' + (line.quantity || 1) + '"></td>' +
                '<td class="dcmt-plan-col-service"><select class="form-select plan-service">' + options + '</select></td>' +
                '<td class="dcmt-plan-col-price"><input type="number" class="form-control text-end plan-unit-price" min="0" step="0.01" value="' + priceValue + '" placeholder="0.00"></td>' +
                '<td class="dcmt-plan-col-subtotal text-end plan-subtotal">' + subtotalText + '</td>';
            tbody.appendChild(tr);
        });
        initAllServiceSelect2();
        clearPricesWithoutService();
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
                if (subEl) subEl.textContent = fmtMoney(0);
            }
        });
    }

    tbody.addEventListener('change', function(e) {
        const row = e.target.closest('tr[data-line-index]');
        if (!row) return;
        if (e.target.classList.contains('plan-service')) onServiceChange(row);
        else recalcTotal();
    });
    tbody.addEventListener('input', function(e) {
        if (e.target.closest('tr[data-line-index]')) recalcTotal();
    });

    if (typeof $ !== 'undefined') {
        $(tbody).on('change', '.plan-service', function() {
            const row = this.closest('tr[data-line-index]');
            if (row) onServiceChange(row);
        });
    }

    if (doctorSelect) {
        ensureSelect2(doctorSelect, doctorPlaceholder);

        const onDoctorChange = function() {
            const doctorId = parseInt(doctorSelect.value || '0', 10);
            if (doctorId <= 0) return;
            fetch('plan_lines_ajax.php?patient_id=<?php echo $patient_id; ?>&doctor_id=' + encodeURIComponent(doctorId))
                .then(r => r.json())
                .then(data => {
                    if (!data.success || !Array.isArray(data.lines)) return;
                    rebuildPlanRows(data.lines, data.services || servicesCatalog);
                })
                .catch(() => {});
        };

        if (typeof $ !== 'undefined') {
            $(doctorSelect).on('change', onDoctorChange);
        } else {
            doctorSelect.addEventListener('change', onDoctorChange);
        }
    }

    initAllServiceSelect2();
    clearPricesWithoutService();

    form.addEventListener('submit', function() {
        hiddenJson.value = JSON.stringify(collectLines());
    });

    recalcTotal();
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
