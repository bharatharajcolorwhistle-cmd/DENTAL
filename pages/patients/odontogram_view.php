<?php
/**
 * Read-only odontogram (problem + solution) for patient view.
 * Expects $dcmt_odontogram_initial_json (string JSON) and optional $dcmt_odontogram_patient_id (int).
 */
require_once __DIR__ . '/../../includes/odontogram_treatments.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';

global $dcmt_pdo;
if (isset($dcmt_pdo) && $dcmt_pdo instanceof PDO) {
    dcmt_ensure_odontogram_treatments_table($dcmt_pdo);
    $dcmt_od_treatments_json = dcmt_odontogram_treatments_json_for_chart($dcmt_pdo);
    $dcmt_od_state_colors_json = dcmt_odontogram_problem_states_json_for_chart($dcmt_pdo);
    $dcmt_od_problems_json = dcmt_odontogram_problems_json_for_chart($dcmt_pdo);
} else {
    $dcmt_od_treatments_json = '[]';
    $dcmt_od_state_colors_json = '{}';
    $dcmt_od_problems_json = '[]';
}

if (!isset($dcmt_odontogram_initial_json) || !is_string($dcmt_odontogram_initial_json)) {
    $dcmt_odontogram_initial_json = '{}';
}

$dcmt_odontogram_document = dcmt_patient_odontogram_decode_document($dcmt_odontogram_initial_json);
$dcmt_odontogram_patient_id = isset($dcmt_odontogram_patient_id) ? (int) $dcmt_odontogram_patient_id : 0;
$dcmt_odontogram_has_data = isset($dcmt_odontogram_has_data)
    ? (bool) $dcmt_odontogram_has_data
    : dcmt_patient_odontogram_has_data($dcmt_odontogram_initial_json);

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
];
$dcmt_od_trans_json = json_encode($dcmt_od_trans, JSON_UNESCAPED_UNICODE);

$dcmt_od_chart_defs = [
    'solution' => trans('patient', 'odontogram_section_solution'),
    'problem' => trans('patient', 'odontogram_section_problem'),
];
?>
<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/odontogram.css', '../../'); ?>">

<div class="mb-4 dcmt-odontogram-section-wrap dcmt-odontogram-section-wrap--view" id="dcmtOdontogramDualWrap">
    <?php if (!$dcmt_odontogram_has_data): ?>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_no_data')); ?>
        </div>
    <?php else: ?>
        <p class="dcmt-odontogram-section-intro"><?php echo htmlspecialchars(trans('patient', 'odontogram_dual_intro')); ?></p>

        <nav class="dcmt-odontogram-tab-section" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_title')); ?>">
            <ul class="dcmt-odontogram-tab-list" id="dcmtOdontogramViewTabs" role="tablist">
                <li class="dcmt-odontogram-tab-item" role="presentation">
                    <button class="dcmt-odontogram-tab-link dcmt-odontogram-tab-link--solution active"
                            id="dcmt-od-tab-solution-btn"
                            data-bs-toggle="tab" data-bs-target="#dcmt-od-tab-solution" type="button" role="tab"
                            aria-controls="dcmt-od-tab-solution" aria-selected="true">
                        <i class="fas fa-check-circle dcmt-odontogram-tab-icon"></i>
                        <?php echo htmlspecialchars(trans('patient', 'odontogram_tab_solution')); ?>
                    </button>
                </li>
                <li class="dcmt-odontogram-tab-item" role="presentation">
                    <button class="dcmt-odontogram-tab-link dcmt-odontogram-tab-link--problem"
                            id="dcmt-od-tab-problem-btn"
                            data-bs-toggle="tab" data-bs-target="#dcmt-od-tab-problem" type="button" role="tab"
                            aria-controls="dcmt-od-tab-problem" aria-selected="false">
                        <i class="fas fa-exclamation-circle dcmt-odontogram-tab-icon"></i>
                        <?php echo htmlspecialchars(trans('patient', 'odontogram_tab_problem')); ?>
                    </button>
                </li>
            </ul>
        </nav>

        <div class="tab-content dcmt-odontogram-tab-content" id="dcmtOdontogramViewTabContent">
            <?php foreach ($dcmt_od_chart_defs as $chart_key => $chart_title): ?>
                <?php
                $dcmt_od_chart_key = $chart_key;
                $dcmt_od_chart_title = $chart_title;
                $dcmt_od_chart_initial_json = json_encode(
                    $dcmt_odontogram_document[$chart_key] ?? dcmt_patient_odontogram_empty_chart(),
                    JSON_UNESCAPED_UNICODE
                );
                $dcmt_od_chart_readonly = true;
                $dcmt_od_in_tabs = true;
                $dcmt_od_tab_active = ($chart_key === 'solution');
                include __DIR__ . '/odontogram_chart_inc.php';
                ?>
            <?php endforeach; ?>
        </div>
    <?php endif; ?>
</div>

<script src="<?php echo dcmt_asset('assets/js/odontogram.js', '../../'); ?>"></script>
