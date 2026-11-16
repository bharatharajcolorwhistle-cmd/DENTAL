<?php
/**
 * View patient chart (odontogram + treatment plan) on a single page.
 * Sections are collapsible and can be printed selectively.
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

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
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
        dcmt_redirect('../patient_notes/index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log('Patient odontogram view: ' . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
    exit();
}

/* -------------------------------------------------------------------------
   Odontogram
   ------------------------------------------------------------------------- */
$dcmt_odontogram_patient_id = $patient_id;
$dcmt_odontogram_initial_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
if ($dcmt_odontogram_initial_json === '') {
    $dcmt_odontogram_initial_json = '{}';
}
$dcmt_odontogram_has_data = dcmt_patient_odontogram_has_data($dcmt_odontogram_initial_json);
$dcmt_odontogram_document = dcmt_patient_odontogram_decode_document($dcmt_odontogram_initial_json);
$dcmt_odontogram_solution_chart = $dcmt_odontogram_document['solution'] ?? dcmt_patient_odontogram_empty_chart();

/* -------------------------------------------------------------------------
   Treatment plan (read-only preview, mirrors the plan editor and print output)
   ------------------------------------------------------------------------- */
$summary = dcmt_patient_odontogram_solution_summary($dcmt_odontogram_document);
$saved_plan = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);
$plan_doctor_id = (int) ($saved_plan['dcmt_doctor_user_id'] ?? 0);
$plan_notes = (string) ($saved_plan['dcmt_notes'] ?? '');
$plan_is_saved = is_array($saved_plan) && !empty($saved_plan['lines']);
$plan_is_stale = $plan_is_saved && dcmt_treatment_plan_is_stale($saved_plan, $summary);

if ($plan_is_saved) {
    $plan_lines = dcmt_normalize_treatment_plan_lines($saved_plan['lines'], $dcmt_pdo, $plan_doctor_id);
} else {
    $plan_lines = dcmt_build_treatment_plan_lines($dcmt_pdo, $dcmt_odontogram_solution_chart, $plan_doctor_id);
}
$plan_lines = dcmt_filter_treatment_plan_lines_by_show_flag($dcmt_pdo, $plan_lines);

$selected_plan_lines = [];
foreach ($plan_lines as $plan_line) {
    if (is_array($plan_line) && !empty($plan_line['selected'])) {
        $selected_plan_lines[] = $plan_line;
    }
}
$plan_excluded_count = max(0, count($plan_lines) - count($selected_plan_lines));
$plan_display_lines = !empty($selected_plan_lines) ? $selected_plan_lines : $plan_lines;

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

$dcmt_plan_view = [
    'summary' => $summary,
    'cell_headers' => dcmt_patient_odontogram_summary_cell_headers(),
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
$plan_line_count = count($plan_display_lines);

/* -------------------------------------------------------------------------
   Links
   ------------------------------------------------------------------------- */
$dcmt_odontogram_edit_href = 'edit.php?patient_id=' . $patient_id . '#dcmtOdontogramDualWrap';
$dcmt_odontogram_treatment_plan_href = 'treatment_plan.php?patient_id=' . $patient_id;
$dcmt_odontogram_print_clinical_href = '../patient_notes/print_clinical.php?patient_id=' . $patient_id;

global $dcmt_current_user;
$dcmt_can_edit_odontogram = dcmt_is_admin() || in_array($dcmt_current_user['dcmt_role'] ?? '', ['staff', 'doctor', 'assistant'], true);

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">
<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/patient_chart.css', '../../'); ?>">

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <div class="dcmt-add-form-header-main">
                <h1 class="dcmt-add-form-page-title">
                    <?php echo htmlspecialchars(trans('patient', 'chart_title')); ?>
                </h1>
                <p class="text-muted mb-0 mt-1">
                    <?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?>
                </p>
            </div>
            <div class="d-flex flex-wrap gap-2 align-items-center">
                <div class="dcmt-quick-actions-menu dcmt-header-quick-actions-menu" tabindex="0">
                    <button type="button" class="btn btn-primary btn-sm dcmt-quick-actions-trigger">
                        <span class="dcmt-quick-actions-trigger-icon"><i class="fas fa-bars"></i></span>
                        <span class="dcmt-quick-actions-trigger-label"><?php echo htmlspecialchars(trans('common', 'actions')); ?></span>
                        <i class="fas fa-chevron-down dcmt-quick-actions-trigger-caret"></i>
                    </button>
                    <div class="dcmt-quick-actions-dropdown">
                        <button type="button" class="dcmt-quick-action-link dcmt-quick-action-link--patient"
                                data-bs-toggle="modal" data-bs-target="#dcmtChartPrintModal">
                            <span class="dcmt-quick-action-icon"><i class="fas fa-print"></i></span>
                            <span><?php echo htmlspecialchars(trans('patient', 'chart_print')); ?></span>
                        </button>
                        <?php if ($dcmt_can_edit_odontogram): ?>
                            <a href="<?php echo htmlspecialchars($dcmt_odontogram_edit_href); ?>"
                               class="dcmt-quick-action-link dcmt-quick-action-link--appointment">
                                <span class="dcmt-quick-action-icon"><i class="fas fa-edit"></i></span>
                                <span><?php echo htmlspecialchars(trans('patient', 'odontogram_edit_chart')); ?></span>
                            </a>
                        <?php endif; ?>
                        <?php if ($dcmt_odontogram_has_data): ?>
                            <a href="<?php echo htmlspecialchars($dcmt_odontogram_treatment_plan_href); ?>"
                               class="dcmt-quick-action-link dcmt-quick-action-link--expense">
                                <span class="dcmt-quick-action-icon"><i class="fas fa-clipboard-list"></i></span>
                                <span><?php echo htmlspecialchars(trans('patient', 'chart_plan_edit')); ?></span>
                            </a>
                        <?php endif; ?>
                        <a href="<?php echo htmlspecialchars($dcmt_odontogram_print_clinical_href); ?>"
                           class="dcmt-quick-action-link dcmt-quick-action-link--patient"
                           target="_blank" rel="noopener noreferrer">
                            <span class="dcmt-quick-action-icon"><i class="fas fa-notes-medical"></i></span>
                            <span><?php echo htmlspecialchars(trans('patient_note', 'print_clinical_history')); ?></span>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>

    <div class="dcmt-chart-toolbar">
        <div class="dcmt-chart-toolbar-group">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="dcmtChartExpandAll">
                <i class="fas fa-angles-down"></i><?php echo htmlspecialchars(trans('patient', 'chart_expand_all')); ?>
            </button>
            <button type="button" class="btn btn-outline-secondary btn-sm" id="dcmtChartCollapseAll">
                <i class="fas fa-angles-up"></i><?php echo htmlspecialchars(trans('patient', 'chart_collapse_all')); ?>
            </button>
        </div>
        <div class="dcmt-chart-toolbar-group">
            <span class="text-muted small d-none d-md-inline">
                <?php echo htmlspecialchars(trans('patient', 'chart_print_hint')); ?>
            </span>
        </div>
    </div>

    <div class="dcmt-chart-sections" id="dcmtChartSections">
        <section class="dcmt-chart-section" data-chart-section="odontogram">
            <div class="dcmt-chart-section-head">
                <button class="dcmt-chart-section-toggle" type="button"
                        data-bs-toggle="collapse" data-bs-target="#dcmtChartSectionOdontogram"
                        aria-expanded="true" aria-controls="dcmtChartSectionOdontogram">
                    <i class="fas fa-tooth dcmt-chart-section-icon" aria-hidden="true"></i>
                    <span class="dcmt-chart-section-title"><?php echo htmlspecialchars(trans('patient', 'chart_section_odontogram')); ?></span>
                    <i class="fas fa-chevron-down dcmt-chart-section-chevron" aria-hidden="true"></i>
                </button>
                <button type="button" class="dcmt-chart-section-print" data-chart-print="odontogram"
                        title="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>"
                        aria-label="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>">
                    <i class="fas fa-print" aria-hidden="true"></i>
                </button>
            </div>
            <div id="dcmtChartSectionOdontogram" class="collapse show" data-chart-collapse>
                <div class="dcmt-chart-section-body">
                    <?php include __DIR__ . '/../patients/odontogram_view.php'; ?>
                </div>
            </div>
        </section>

        <section class="dcmt-chart-section" data-chart-section="summary">
            <div class="dcmt-chart-section-head">
                <button class="dcmt-chart-section-toggle" type="button"
                        data-bs-toggle="collapse" data-bs-target="#dcmtChartSectionSummary"
                        aria-expanded="false" aria-controls="dcmtChartSectionSummary">
                    <i class="fas fa-table dcmt-chart-section-icon" aria-hidden="true"></i>
                    <span class="dcmt-chart-section-title"><?php echo htmlspecialchars(trans('patient', 'chart_section_summary')); ?></span>
                    <i class="fas fa-chevron-down dcmt-chart-section-chevron" aria-hidden="true"></i>
                </button>
                <button type="button" class="dcmt-chart-section-print" data-chart-print="summary"
                        title="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>"
                        aria-label="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>">
                    <i class="fas fa-print" aria-hidden="true"></i>
                </button>
            </div>
            <div id="dcmtChartSectionSummary" class="collapse" data-chart-collapse>
                <div class="dcmt-chart-section-body">
                    <?php
                    $dcmt_plan_view_mode = 'summary';
                    include __DIR__ . '/../../includes/patient_treatment_plan_readonly.php';
                    unset($dcmt_plan_view_mode);
                    ?>
                </div>
            </div>
        </section>

        <section class="dcmt-chart-section" data-chart-section="plan">
            <div class="dcmt-chart-section-head">
                <button class="dcmt-chart-section-toggle" type="button"
                        data-bs-toggle="collapse" data-bs-target="#dcmtChartSectionPlan"
                        aria-expanded="true" aria-controls="dcmtChartSectionPlan">
                    <i class="fas fa-clipboard-list dcmt-chart-section-icon" aria-hidden="true"></i>
                    <span class="dcmt-chart-section-title"><?php echo htmlspecialchars(trans('patient', 'chart_section_plan')); ?></span>
                    <?php if ($plan_line_count > 0): ?>
                        <span class="dcmt-chart-section-meta">
                            <?php echo htmlspecialchars(str_replace('{count}', (string) $plan_line_count, trans('patient', 'treatment_plan_group_items'))); ?>
                        </span>
                    <?php endif; ?>
                    <i class="fas fa-chevron-down dcmt-chart-section-chevron" aria-hidden="true"></i>
                </button>
                <button type="button" class="dcmt-chart-section-print" data-chart-print="plan"
                        title="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>"
                        aria-label="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>">
                    <i class="fas fa-print" aria-hidden="true"></i>
                </button>
            </div>
            <div id="dcmtChartSectionPlan" class="collapse show" data-chart-collapse>
                <div class="dcmt-chart-section-body">
                    <?php if (!$dcmt_odontogram_has_data): ?>
                        <div class="alert alert-warning mb-3" data-persistent="true" role="alert">
                            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_solution')); ?>
                            <a href="<?php echo htmlspecialchars($dcmt_odontogram_edit_href); ?>" class="alert-link">
                                <?php echo htmlspecialchars(trans('patient', 'odontogram_edit_chart')); ?>
                            </a>
                        </div>
                    <?php elseif (!$plan_is_saved): ?>
                        <div class="alert alert-info mb-3" data-persistent="true" role="alert">
                            <?php echo htmlspecialchars(trans('patient', 'chart_plan_preview_notice')); ?>
                            <a href="<?php echo htmlspecialchars($dcmt_odontogram_treatment_plan_href); ?>" class="alert-link">
                                <?php echo htmlspecialchars(trans('patient', 'chart_plan_edit')); ?>
                            </a>
                        </div>
                    <?php elseif ($plan_is_stale): ?>
                        <div class="alert alert-warning mb-3" data-persistent="true" role="alert">
                            <?php echo htmlspecialchars(trans('patient', 'chart_plan_stale_notice')); ?>
                            <a href="<?php echo htmlspecialchars($dcmt_odontogram_treatment_plan_href); ?>" class="alert-link">
                                <?php echo htmlspecialchars(trans('patient', 'chart_plan_edit')); ?>
                            </a>
                        </div>
                    <?php endif; ?>
                    <?php
                    $dcmt_plan_view_mode = 'lines';
                    include __DIR__ . '/../../includes/patient_treatment_plan_readonly.php';
                    unset($dcmt_plan_view_mode);
                    ?>
                </div>
            </div>
        </section>

        <section class="dcmt-chart-section" data-chart-section="notes">
            <div class="dcmt-chart-section-head">
                <button class="dcmt-chart-section-toggle" type="button"
                        data-bs-toggle="collapse" data-bs-target="#dcmtChartSectionNotes"
                        aria-expanded="false" aria-controls="dcmtChartSectionNotes">
                    <i class="fas fa-sticky-note dcmt-chart-section-icon" aria-hidden="true"></i>
                    <span class="dcmt-chart-section-title"><?php echo htmlspecialchars(trans('patient', 'chart_section_notes')); ?></span>
                    <i class="fas fa-chevron-down dcmt-chart-section-chevron" aria-hidden="true"></i>
                </button>
                <button type="button" class="dcmt-chart-section-print" data-chart-print="notes"
                        title="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>"
                        aria-label="<?php echo htmlspecialchars(trans('patient', 'chart_print_this')); ?>">
                    <i class="fas fa-print" aria-hidden="true"></i>
                </button>
            </div>
            <div id="dcmtChartSectionNotes" class="collapse" data-chart-collapse>
                <div class="dcmt-chart-section-body">
                    <?php
                    $dcmt_plan_view_mode = 'notes';
                    include __DIR__ . '/../../includes/patient_treatment_plan_readonly.php';
                    unset($dcmt_plan_view_mode);
                    ?>
                </div>
            </div>
        </section>
    </div>
</div>

<div class="modal fade" id="dcmtChartPrintModal" tabindex="-1" aria-labelledby="dcmtChartPrintModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dcmtChartPrintModalLabel">
                    <?php echo htmlspecialchars(trans('patient', 'chart_print_options')); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(trans('common', 'close')); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="text-muted small mb-3"><?php echo htmlspecialchars(trans('patient', 'chart_print_options_help')); ?></p>
                <div class="dcmt-chart-print-options">
                    <?php
                    $dcmt_print_options = [
                        'odontogram' => ['label' => 'chart_print_odontogram', 'help' => 'chart_print_odontogram_help'],
                        'summary' => ['label' => 'chart_print_summary', 'help' => 'chart_print_summary_help'],
                        'plan' => ['label' => 'chart_print_plan', 'help' => 'chart_print_plan_help'],
                        'notes' => ['label' => 'chart_print_notes', 'help' => 'chart_print_notes_help'],
                    ];
                    foreach ($dcmt_print_options as $dcmt_option_key => $dcmt_option):
                        ?>
                        <label class="dcmt-chart-print-option">
                            <input class="form-check-input dcmt-chart-print-choice" type="checkbox"
                                   value="<?php echo htmlspecialchars($dcmt_option_key); ?>" checked>
                            <span>
                                <span class="dcmt-chart-print-option-label"><?php echo htmlspecialchars(trans('patient', $dcmt_option['label'])); ?></span>
                                <span class="dcmt-chart-print-option-help"><?php echo htmlspecialchars(trans('patient', $dcmt_option['help'])); ?></span>
                            </span>
                        </label>
                    <?php endforeach; ?>
                </div>
                <div class="alert alert-warning d-none mt-3 mb-0" id="dcmtChartPrintAlert" role="alert">
                    <?php echo htmlspecialchars(trans('patient', 'chart_print_nothing_selected')); ?>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-outline-secondary btn-sm" data-bs-dismiss="modal">
                    <?php echo htmlspecialchars(trans('common', 'cancel')); ?>
                </button>
                <button type="button" class="btn btn-primary btn-sm" id="dcmtChartPrintGo">
                    <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient', 'chart_print_selected')); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    var patientId = <?php echo (int) $patient_id; ?>;
    var sectionKeys = ['odontogram', 'summary', 'plan', 'notes'];

    function printUrl(sections) {
        return 'print_chart.php?patient_id=' + patientId + '&sections=' + encodeURIComponent(sections.join(','));
    }

    /* Print a single section from its header button. */
    document.querySelectorAll('[data-chart-print]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            var section = btn.getAttribute('data-chart-print');
            if (sectionKeys.indexOf(section) === -1) {
                return;
            }
            window.open(printUrl([section]), '_blank', 'noopener');
        });
    });

    /* Print selection modal. */
    var printGo = document.getElementById('dcmtChartPrintGo');
    var printAlert = document.getElementById('dcmtChartPrintAlert');
    if (printGo) {
        printGo.addEventListener('click', function () {
            var sections = [];
            document.querySelectorAll('.dcmt-chart-print-choice').forEach(function (box) {
                if (box.checked) {
                    sections.push(box.value);
                }
            });
            if (!sections.length) {
                if (printAlert) {
                    printAlert.classList.remove('d-none');
                }
                return;
            }
            if (printAlert) {
                printAlert.classList.add('d-none');
            }
            window.open(printUrl(sections), '_blank', 'noopener');
        });
    }

    /* Expand / collapse all sections. */
    function setAllSections(show) {
        document.querySelectorAll('#dcmtChartSections [data-chart-collapse]').forEach(function (el) {
            if (typeof bootstrap === 'undefined' || !bootstrap.Collapse) {
                if (show) {
                    el.classList.add('show');
                } else {
                    el.classList.remove('show');
                }
                return;
            }
            var instance = bootstrap.Collapse.getOrCreateInstance(el, { toggle: false });
            if (show) {
                instance.show();
            } else {
                instance.hide();
            }
        });
    }

    var expandAll = document.getElementById('dcmtChartExpandAll');
    var collapseAll = document.getElementById('dcmtChartCollapseAll');
    if (expandAll) {
        expandAll.addEventListener('click', function () {
            setAllSections(true);
        });
    }
    if (collapseAll) {
        collapseAll.addEventListener('click', function () {
            setAllSections(false);
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
