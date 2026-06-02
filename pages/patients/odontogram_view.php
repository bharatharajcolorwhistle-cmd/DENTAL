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
} else {
    $dcmt_od_treatments_json = '[]';
}

if (!isset($dcmt_odontogram_initial_json) || !is_string($dcmt_odontogram_initial_json)) {
    $dcmt_odontogram_initial_json = '{}';
}

$dcmt_odontogram_document = dcmt_patient_odontogram_decode_document($dcmt_odontogram_initial_json);
$dcmt_odontogram_patient_id = isset($dcmt_odontogram_patient_id) ? (int) $dcmt_odontogram_patient_id : 0;
$dcmt_odontogram_has_data = isset($dcmt_odontogram_has_data)
    ? (bool) $dcmt_odontogram_has_data
    : dcmt_patient_odontogram_has_data($dcmt_odontogram_initial_json);

if (!isset($dcmt_odontogram_edit_href) && $dcmt_odontogram_patient_id > 0) {
    $dcmt_odontogram_edit_href = '../patient_odontogram/edit.php?patient_id=' . $dcmt_odontogram_patient_id . '#dcmtOdontogramDualWrap';
}

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
    'problem' => trans('patient', 'odontogram_section_problem'),
    'solution' => trans('patient', 'odontogram_section_solution'),
];
?>
<link rel="stylesheet" href="../../assets/css/odontogram.css">

<div class="mb-4 dcmt-odontogram-section-wrap" id="dcmtOdontogramDualWrap">
    <div class="d-flex flex-wrap justify-content-between align-items-center gap-2 mb-2">
        <h5 class="mb-0">
            <i class="fas fa-tooth me-2"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_title')); ?>
        </h5>
        <?php
        global $dcmt_current_user;
        $dcmt_can_edit_odontogram = dcmt_is_admin() || in_array($dcmt_current_user['dcmt_role'] ?? '', ['staff', 'doctor', 'assistant'], true);
        if ($dcmt_can_edit_odontogram && !empty($dcmt_odontogram_edit_href)):
        ?>
            <a href="<?php echo htmlspecialchars($dcmt_odontogram_edit_href); ?>" class="dcmt-add-form-view-all-link btn-sm">
                <i class="fas fa-edit me-1"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_edit_chart')); ?>
            </a>
        <?php endif; ?>
    </div>

    <?php if (!$dcmt_odontogram_has_data): ?>
        <div class="alert alert-info mb-3">
            <i class="fas fa-info-circle me-2"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_no_data')); ?>
        </div>
    <?php else: ?>
        <p class="text-muted small mb-3"><?php echo htmlspecialchars(trans('patient', 'odontogram_dual_intro')); ?></p>
        <?php foreach ($dcmt_od_chart_defs as $chart_key => $chart_title): ?>
            <?php
            if (!dcmt_patient_odontogram_chart_slice_has_data($dcmt_odontogram_document[$chart_key] ?? [])) {
                continue;
            }
            $dcmt_od_chart_key = $chart_key;
            $dcmt_od_chart_title = $chart_title;
            $dcmt_od_chart_initial_json = json_encode(
                $dcmt_odontogram_document[$chart_key],
                JSON_UNESCAPED_UNICODE
            );
            $dcmt_od_chart_readonly = true;
            include __DIR__ . '/odontogram_chart_inc.php';
            ?>
        <?php endforeach; ?>
    <?php endif; ?>
</div>

<script src="../../assets/js/odontogram.js"></script>
