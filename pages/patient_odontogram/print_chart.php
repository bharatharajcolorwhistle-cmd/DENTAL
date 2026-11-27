<?php
/**
 * Print patient chart — odontogram + treatment plan (selectable sections).
 * Dental Clinic Management System
 *
 * Query params:
 *   patient_id (int)   required
 *   sections (string)  optional, comma list of: odontogram, summary, plan, notes
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/odontogram_treatments.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';
require_once __DIR__ . '/../../includes/patient_odontogram_summary.php';
require_once __DIR__ . '/../../includes/patient_treatment_plan.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('view.php');
    exit();
}

$dcmt_db = isset($dcmt_db) && $dcmt_db instanceof Dcmt_Database ? $dcmt_db : new Dcmt_Database();
$dcmt_db->migratePatientTreatmentPlanSchema();

try {
    $patient_cols = dcmt_patient_select_columns_without_odontogram('p', $dcmt_pdo);
    $stmt = $dcmt_pdo->prepare("SELECT {$patient_cols} FROM dcmt_patients p WHERE p.dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('view.php');
        exit();
    }
    dcmt_audit('print', 'patient_chart', $patient_id);
} catch (PDOException $e) {
    error_log('Patient chart print — patient: ' . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('view.php');
    exit();
}

/* -------------------------------------------------------------------------
   Requested sections
   ------------------------------------------------------------------------- */
$dcmt_chart_section_keys = ['odontogram', 'summary', 'plan', 'notes'];
$dcmt_chart_sections = $dcmt_chart_section_keys;
if (isset($_GET['sections']) && trim((string) $_GET['sections']) !== '') {
    $dcmt_chart_sections = [];
    foreach (explode(',', (string) $_GET['sections']) as $dcmt_chart_section) {
        $dcmt_chart_section = strtolower(trim($dcmt_chart_section));
        if (in_array($dcmt_chart_section, $dcmt_chart_section_keys, true)) {
            $dcmt_chart_sections[] = $dcmt_chart_section;
        }
    }
    if (empty($dcmt_chart_sections)) {
        $dcmt_chart_sections = $dcmt_chart_section_keys;
    }
}

/* -------------------------------------------------------------------------
   Odontogram data
   ------------------------------------------------------------------------- */
$dcmt_odontogram_patient_id = $patient_id;
$dcmt_odontogram_initial_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
if ($dcmt_odontogram_initial_json === '') {
    $dcmt_odontogram_initial_json = '{}';
}
$dcmt_odontogram_document = dcmt_patient_odontogram_decode_document($dcmt_odontogram_initial_json);
$dcmt_od_solution_chart = $dcmt_odontogram_document['solution'] ?? dcmt_patient_odontogram_empty_chart();
$dcmt_od_problem_has_data = dcmt_patient_odontogram_chart_slice_has_data($dcmt_odontogram_document['problem'] ?? []);
$dcmt_od_solution_has_data = dcmt_patient_odontogram_chart_slice_has_data($dcmt_od_solution_chart);
$dcmt_od_odontogram_has_chart_data = $dcmt_od_problem_has_data || $dcmt_od_solution_has_data;

dcmt_ensure_odontogram_treatments_table($dcmt_pdo);
$dcmt_od_treatments_json = dcmt_odontogram_treatments_json_for_chart($dcmt_pdo);
$dcmt_od_state_colors_json = dcmt_odontogram_problem_states_json_for_chart($dcmt_pdo);
$dcmt_od_problems_json = dcmt_odontogram_problems_json_for_chart($dcmt_pdo);

$dcmt_od_trans = [
    'confirmReset' => trans('patient', 'odontogram_confirm_reset'),
    'stateDefault' => trans('patient', 'odontogram_state_default'),
    'stateDamaged' => trans('patient', 'odontogram_state_damaged'),
    'stateFilling' => trans('patient', 'odontogram_state_filling'),
    'stateMissing' => trans('patient', 'odontogram_state_missing'),
    'stateCrown' => trans('patient', 'odontogram_state_crown'),
    'stateImplant' => trans('patient', 'odontogram_state_implant'),
    'zonaAnterior' => trans('patient', 'odontogram_zona_anterior'),
    'zonaPosterior' => trans('patient', 'odontogram_zona_posterior'),
    'selectTreatment' => trans('patient', 'odontogram_select_treatment'),
    'noTreatments' => trans('patient', 'odontogram_no_treatments_zone'),
    'selectConditionFirst' => trans('patient', 'odontogram_select_condition_first'),
    'treatmentsUnderState' => trans('patient', 'odontogram_treatments_under_state'),
    'noAppliedTreatments' => trans('patient', 'odontogram_no_applied_treatments'),
    'toothCondition' => trans('patient', 'odontogram_tooth_condition'),
    'clinicalTreatments' => trans('patient', 'odontogram_clinical_treatments'),
    'addTreatment' => trans('patient', 'odontogram_add_treatment'),
    'chooseTreatment' => trans('patient', 'odontogram_choose_treatment'),
    'quadrantEmpty' => trans('patient', 'odontogram_quadrant_empty'),
    'toothLabel' => trans('patient', 'odontogram_tooth_label'),
    'sectionTop' => trans('patient', 'odontogram_section_top'),
    'sectionBottom' => trans('patient', 'odontogram_section_bottom'),
    'sectionLeft' => trans('patient', 'odontogram_section_left'),
    'sectionRight' => trans('patient', 'odontogram_section_right'),
    'sectionCenter' => trans('patient', 'odontogram_section_center'),
    'sectionRootLeft' => trans('patient', 'odontogram_section_root_left'),
    'sectionRootRight' => trans('patient', 'odontogram_section_root_right'),
    'markCompleted' => trans('patient', 'odontogram_mark_completed'),
    'completed' => trans('patient', 'odontogram_completed'),
    'helpCompleted' => trans('patient', 'odontogram_help_completed'),
    'toggleTreatmentCompleted' => trans('patient', 'odontogram_toggle_treatment_completed'),
];
$dcmt_od_trans_json = json_encode($dcmt_od_trans, JSON_UNESCAPED_UNICODE);
$dcmt_od_solution_chart_json = json_encode($dcmt_od_solution_chart, JSON_UNESCAPED_UNICODE);
$dcmt_od_problem_chart_json = json_encode(
    $dcmt_odontogram_document['problem'] ?? dcmt_patient_odontogram_empty_chart(),
    JSON_UNESCAPED_UNICODE
);
$dcmt_od_chart_readonly = true;
$dcmt_od_in_tabs = false;
$dcmt_od_tab_active = false;

/* -------------------------------------------------------------------------
   Treatment plan data (read-only: never written from the print page)
   ------------------------------------------------------------------------- */
$summary = dcmt_patient_odontogram_solution_summary($dcmt_odontogram_document);
$saved_plan = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
$plan_doctor_id = (int) ($saved_plan['dcmt_doctor_user_id'] ?? 0);
$plan_notes = (string) ($saved_plan['dcmt_notes'] ?? '');
$plan_is_saved = is_array($saved_plan) && !empty($saved_plan['lines']);

$plan_is_stale = $plan_is_saved && dcmt_treatment_plan_is_stale($saved_plan, $summary, $dcmt_od_solution_chart);
if ($plan_is_saved && !$plan_is_stale) {
    $plan_lines = dcmt_normalize_treatment_plan_lines($saved_plan['lines'], $dcmt_pdo, $plan_doctor_id);
} else {
    $plan_lines = dcmt_build_treatment_plan_lines($dcmt_pdo, $dcmt_od_solution_chart, $plan_doctor_id);
    if ($plan_is_saved && !empty($saved_plan['lines'])) {
        $plan_lines = dcmt_merge_treatment_plan_lines_with_previous($plan_lines, $saved_plan['lines']);
        $plan_lines = dcmt_normalize_treatment_plan_lines($plan_lines, $dcmt_pdo, $plan_doctor_id);
    }
}
$plan_lines = dcmt_filter_treatment_plan_lines_by_show_flag($dcmt_pdo, $plan_lines);

$selected_lines = [];
foreach ($plan_lines as $plan_line) {
    if (is_array($plan_line) && !empty($plan_line['selected'])) {
        $selected_lines[] = $plan_line;
    }
}
$plan_excluded_count = count($plan_lines) - count($selected_lines);
$plan_display_lines = !empty($selected_lines) ? $selected_lines : $plan_lines;

$split_plan_lines = dcmt_treatment_plan_split_lines($plan_display_lines);
$odontogram_plan_lines = dcmt_sort_treatment_plan_odontogram_lines($split_plan_lines['odontogram']);
$additional_plan_lines = $split_plan_lines['additional'];
$plan_groups = dcmt_treatment_plan_group_lines($odontogram_plan_lines);
$plan_total = dcmt_treatment_plan_calculate_total($plan_display_lines);
$quadrant_totals = dcmt_treatment_plan_clinical_quadrant_pair_totals($odontogram_plan_lines);
$additional_total = 0.0;
foreach ($additional_plan_lines as $additional_line) {
    if (is_array($additional_line) && !empty($additional_line['selected'])) {
        $additional_total += (float) ($additional_line['subtotal'] ?? 0);
    }
}
$additional_total = round($additional_total, 2);

$doctors = dcmt_fetch_active_doctors($dcmt_pdo);
$doctors_by_id = [];
foreach ($doctors as $doctor_row) {
    $doctors_by_id[(int) ($doctor_row['dcmt_id'] ?? 0)] = (string) ($doctor_row['dcmt_name'] ?? '');
}

$cell_headers = dcmt_patient_odontogram_summary_cell_headers();

$dcmt_plan_view = [
    'summary' => $summary,
    'cell_headers' => $cell_headers,
    'groups' => $plan_groups,
    'additional' => $additional_plan_lines,
    'notes' => $plan_notes,
    'total' => $plan_total,
    'quadrant_totals' => $quadrant_totals,
    'additional_total' => $additional_total,
    'doctors' => $doctors_by_id,
    'excluded_count' => $plan_excluded_count,
    'is_saved' => $plan_is_saved,
];

$clinic_name = function_exists('dcmt_get_site_name') ? dcmt_get_site_name() : 'Dental Clinic';
$patient_name = $patient['dcmt_patient_name'] ?? '';
$patient_phone = $patient['dcmt_phone'] ?? '';
$printed_at = dcmt_format_date(dcmt_get_current_date());
$back_url = 'view.php?patient_id=' . $patient_id;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(substr(defined('DCMT_LANG') ? DCMT_LANG : 'en', 0, 2)); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(trans('patient', 'chart_print_title')); ?> — <?php echo htmlspecialchars($patient_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo dcmt_asset('assets/css/main.css', '../../'); ?>" rel="stylesheet">
    <link href="<?php echo dcmt_asset('assets/css/odontogram.css', '../../'); ?>" rel="stylesheet">
    <link href="<?php echo dcmt_asset('assets/css/patient_chart.css', '../../'); ?>" rel="stylesheet">
</head>
<body class="dcmt-chart-print-page">
    <div class="dcmt-chart-print-toolbar dcmt-no-print">
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="dcmt-add-form-view-all-link">
            <i class="fas fa-arrow-left me-1"></i><?php echo htmlspecialchars(trans('patient', 'chart_title')); ?>
        </a>
        <div class="dcmt-chart-print-toggles" role="group" aria-label="<?php echo htmlspecialchars(trans('patient', 'chart_print_options')); ?>">
            <?php
            $dcmt_chart_toggle_labels = [
                'odontogram' => 'chart_print_odontogram',
                'summary' => 'chart_print_summary',
                'plan' => 'chart_print_plan',
                'notes' => 'chart_print_notes',
            ];
            foreach ($dcmt_chart_toggle_labels as $dcmt_toggle_key => $dcmt_toggle_label):
                ?>
                <label class="form-check">
                    <input class="form-check-input" type="checkbox"
                           data-print-toggle="<?php echo htmlspecialchars($dcmt_toggle_key); ?>"
                           <?php echo in_array($dcmt_toggle_key, $dcmt_chart_sections, true) ? 'checked' : ''; ?>>
                    <span class="form-check-label"><?php echo htmlspecialchars(trans('patient', $dcmt_toggle_label)); ?></span>
                </label>
            <?php endforeach; ?>
        </div>
        <div class="dcmt-chart-print-toolbar-actions">
            <button type="button" class="btn btn-primary btn-sm" id="dcmtChartPrintBtn">
                <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient', 'chart_print')); ?>
            </button>
        </div>
    </div>

    <div class="dcmt-chart-print-sheet">
        <header class="dcmt-chart-print-header">
            <div class="text-muted small mb-1"><?php echo htmlspecialchars($clinic_name); ?></div>
            <h1><?php echo htmlspecialchars(trans('patient', 'chart_print_title')); ?></h1>
            <div class="dcmt-chart-print-meta">
                <strong><?php echo htmlspecialchars(trans('patient', 'patient')); ?>:</strong>
                <?php echo htmlspecialchars($patient_name); ?>
                <?php if ($patient_phone !== ''): ?>
                    &nbsp;|&nbsp;<strong><?php echo htmlspecialchars(trans('patient', 'phone')); ?>:</strong>
                    <?php echo htmlspecialchars($patient_phone); ?>
                <?php endif; ?>
                &nbsp;|&nbsp;<strong><?php echo htmlspecialchars(trans('common', 'date')); ?>:</strong>
                <?php echo htmlspecialchars($printed_at); ?>
            </div>
        </header>

        <section class="dcmt-chart-print-section dcmt-chart-print-odontogram dcmt-chart-print-page-charts"
                 data-print-section="odontogram"
                 <?php echo in_array('odontogram', $dcmt_chart_sections, true) ? '' : 'style="display:none;"'; ?>>
            <h2 class="dcmt-chart-print-section-title">
                <i class="fas fa-tooth me-2"></i><?php echo htmlspecialchars(trans('patient', 'chart_section_odontogram')); ?>
            </h2>
            <?php if (!$dcmt_od_odontogram_has_chart_data): ?>
                <p class="dcmt-chart-print-empty"><?php echo htmlspecialchars(trans('patient', 'odontogram_no_data')); ?></p>
            <?php else: ?>
                <?php if ($dcmt_od_problem_has_data): ?>
                    <?php
                    $dcmt_od_chart_key = 'problem';
                    $dcmt_od_chart_title = trans('patient', 'odontogram_section_problem');
                    $dcmt_od_chart_initial_json = $dcmt_od_problem_chart_json;
                    $dcmt_od_hide_zonas = true;
                    $dcmt_od_hide_crosshair = false;
                    unset($dcmt_od_instance_suffix);
                    include __DIR__ . '/../patients/odontogram_chart_inc.php';
                    ?>
                <?php endif; ?>
                <?php if ($dcmt_od_solution_has_data): ?>
                    <?php
                    $dcmt_od_chart_key = 'solution';
                    $dcmt_od_chart_title = trans('patient', 'odontogram_section_solution');
                    $dcmt_od_chart_initial_json = $dcmt_od_solution_chart_json;
                    // Zona detail is printed in its own section right after the charts.
                    $dcmt_od_hide_zonas = true;
                    $dcmt_od_hide_crosshair = false;
                    unset($dcmt_od_instance_suffix);
                    include __DIR__ . '/../patients/odontogram_chart_inc.php';
                    unset($dcmt_od_hide_zonas, $dcmt_od_hide_crosshair);
                    ?>
                <?php endif; ?>
            <?php endif; ?>
        </section>

        <?php if ($dcmt_od_solution_has_data): ?>
            <section class="dcmt-chart-print-section dcmt-chart-print-zonas dcmt-chart-print-page-zonas"
                     data-print-section="odontogram"
                     <?php echo in_array('odontogram', $dcmt_chart_sections, true) ? '' : 'style="display:none;"'; ?>>
                <h2 class="dcmt-chart-print-section-title">
                    <i class="fas fa-table-cells me-2"></i><?php echo htmlspecialchars(trans('patient', 'chart_section_zones')); ?>
                </h2>
                <?php
                $dcmt_od_chart_key = 'solution';
                $dcmt_od_chart_title = trans('patient', 'odontogram_section_solution');
                $dcmt_od_chart_initial_json = $dcmt_od_solution_chart_json;
                $dcmt_od_hide_zonas = false;
                $dcmt_od_hide_crosshair = true;
                $dcmt_od_instance_suffix = 'solution-zonas';
                include __DIR__ . '/../patients/odontogram_chart_inc.php';
                unset($dcmt_od_instance_suffix, $dcmt_od_hide_zonas, $dcmt_od_hide_crosshair);
                ?>
            </section>
        <?php endif; ?>

        <section class="dcmt-chart-print-section"
                 data-print-section="summary"
                 <?php echo in_array('summary', $dcmt_chart_sections, true) ? '' : 'style="display:none;"'; ?>>
            <h2 class="dcmt-chart-print-section-title">
                <i class="fas fa-table me-2"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_summary_title')); ?>
            </h2>
            <?php
            $dcmt_plan_view_mode = 'summary';
            include __DIR__ . '/../../includes/patient_treatment_plan_readonly.php';
            ?>
        </section>

        <section class="dcmt-chart-print-section dcmt-chart-print-page-plan"
                 data-print-section="plan"
                 <?php echo in_array('plan', $dcmt_chart_sections, true) ? '' : 'style="display:none;"'; ?>>
            <h2 class="dcmt-chart-print-section-title">
                <i class="fas fa-clipboard-list me-2"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_lines_title')); ?>
            </h2>
            <?php
            $dcmt_plan_view_mode = 'lines';
            include __DIR__ . '/../../includes/patient_treatment_plan_readonly.php';
            ?>
        </section>

        <section class="dcmt-chart-print-section dcmt-chart-print-page-notes"
                 data-print-section="notes"
                 <?php echo in_array('notes', $dcmt_chart_sections, true) ? '' : 'style="display:none;"'; ?>>
            <h2 class="dcmt-chart-print-section-title">
                <i class="fas fa-sticky-note me-2"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_notes')); ?>
            </h2>
            <?php
            $dcmt_plan_view_mode = 'notes';
            include __DIR__ . '/../../includes/patient_treatment_plan_readonly.php';
            ?>
        </section>

        <p class="dcmt-chart-print-disclaimer">
            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_print_disclaimer')); ?>
        </p>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo dcmt_asset('assets/js/odontogram.js', '../../'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function () {
        /* This page has no shared chart wrapper, so initialise every read-only chart here. */
        if (window.DcmtOdontogram && typeof window.DcmtOdontogram.initReadonlyRoots === 'function') {
            window.DcmtOdontogram.initReadonlyRoots(document);
        }

        var toggles = document.querySelectorAll('[data-print-toggle]');
        var printBtn = document.getElementById('dcmtChartPrintBtn');
        var patientId = <?php echo (int) $patient_id; ?>;
        var allKeys = ['odontogram', 'summary', 'plan', 'notes'];

        function sectionEls(key) {
            return document.querySelectorAll('[data-print-section="' + key + '"]');
        }

        function selectedKeys() {
            var keys = [];
            toggles.forEach(function (t) {
                if (t.checked) {
                    keys.push(t.getAttribute('data-print-toggle'));
                }
            });
            return keys;
        }

        function syncUrl() {
            var keys = selectedKeys();
            var url = 'print_chart.php?patient_id=' + patientId;
            if (keys.length && keys.length < allKeys.length) {
                url += '&sections=' + encodeURIComponent(keys.join(','));
            }
            try {
                window.history.replaceState(null, '', url);
            } catch (e) {
                /* ignore */
            }
            if (printBtn) {
                printBtn.disabled = keys.length === 0;
            }
        }

        toggles.forEach(function (toggle) {
            toggle.addEventListener('change', function () {
                var key = toggle.getAttribute('data-print-toggle');
                sectionEls(key).forEach(function (el) {
                    el.style.display = toggle.checked ? '' : 'none';
                });
                syncUrl();
            });
        });

        if (printBtn) {
            printBtn.addEventListener('click', function () {
                if (selectedKeys().length === 0) {
                    return;
                }
                window.print();
            });
        }
    });
    </script>
</body>
</html>
