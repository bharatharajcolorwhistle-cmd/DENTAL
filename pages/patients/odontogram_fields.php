<?php
/**
 * Odontogram block for patient forms (problem + solution charts).
 * Expects optional $dcmt_odontogram_initial_json (string JSON) and $dcmt_odontogram_patient_id (int).
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
$dcmt_odontogram_raw_json = trim($dcmt_odontogram_initial_json) === '' ? '{}' : $dcmt_odontogram_initial_json;

$dcmt_odontogram_document = dcmt_patient_odontogram_decode_document($dcmt_odontogram_raw_json);
$dcmt_odontogram_patient_id = isset($dcmt_odontogram_patient_id) ? (int) $dcmt_odontogram_patient_id : 0;

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
    'blockCondition' => trans('patient', 'odontogram_block_condition'),
    'selectBlockFirst' => trans('patient', 'odontogram_select_block_first'),
    'sectionTop' => trans('patient', 'odontogram_section_top'),
    'sectionBottom' => trans('patient', 'odontogram_section_bottom'),
    'sectionLeft' => trans('patient', 'odontogram_section_left'),
    'sectionRight' => trans('patient', 'odontogram_section_right'),
    'sectionCenter' => trans('patient', 'odontogram_section_center'),
    'sectionRootLeft' => trans('patient', 'odontogram_section_root_left'),
    'sectionRootRight' => trans('patient', 'odontogram_section_root_right'),
    'wholeTooth' => trans('patient', 'odontogram_whole_tooth'),
    'modalTitle' => trans('patient', 'odontogram_modal_title'),
    'modalSave' => trans('patient', 'odontogram_modal_save'),
    'modalCancel' => trans('patient', 'odontogram_modal_cancel'),
    'modalClear' => trans('patient', 'odontogram_modal_clear'),
    'confirmResetTooth' => trans('patient', 'odontogram_confirm_reset_tooth'),
    'modalBlockSelected' => trans('patient', 'odontogram_modal_block_selected'),
];
$dcmt_od_trans_json = json_encode($dcmt_od_trans, JSON_UNESCAPED_UNICODE);

$dcmt_od_chart_defs = [
    'problem' => trans('patient', 'odontogram_section_problem'),
    'solution' => trans('patient', 'odontogram_section_solution'),
];
?>
<link rel="stylesheet" href="../../assets/css/odontogram.css">

<div class="mb-4 dcmt-odontogram-section-wrap" id="dcmtOdontogramDualWrap">
    <h5 class="mb-2">
        <i class="fas fa-tooth me-2"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_title')); ?>
    </h5>
    <p class="text-muted small mb-3"><?php echo htmlspecialchars(trans('patient', 'odontogram_dual_intro')); ?></p>

    <?php foreach ($dcmt_od_chart_defs as $chart_key => $chart_title): ?>
        <?php
        $dcmt_od_chart_key = $chart_key;
        $dcmt_od_chart_title = $chart_title;
        $dcmt_od_chart_initial_json = json_encode(
            $dcmt_odontogram_document[$chart_key] ?? dcmt_patient_odontogram_empty_chart(),
            JSON_UNESCAPED_UNICODE
        );
        $dcmt_od_chart_readonly = false;
        include __DIR__ . '/odontogram_chart_inc.php';
        ?>
    <?php endforeach; ?>

    <input type="hidden" name="odontogram_data" id="odontogram_data" value="<?php echo htmlspecialchars($dcmt_odontogram_raw_json, ENT_QUOTES, 'UTF-8'); ?>">
</div>

<div class="modal fade" id="dcmtOdontogramToothModal" tabindex="-1" aria-labelledby="dcmtOdontogramToothModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dcmtOdontogramToothModalLabel"></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(trans('common', 'close')); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="dcmt-od-modal-zone small text-muted mb-2"></p>
                <p class="dcmt-od-modal-block-hint small text-muted mb-3" hidden></p>
                <div class="mb-3">
                    <label class="form-label" for="dcmtOdModalCondition"><?php echo htmlspecialchars(trans('patient', 'odontogram_tooth_condition')); ?></label>
                    <select class="form-select" id="dcmtOdModalCondition"></select>
                </div>
                <div class="mb-2">
                    <label class="form-label" for="dcmtOdModalTreatmentAdd"><?php echo htmlspecialchars(trans('patient', 'odontogram_add_treatment')); ?></label>
                    <div class="input-group">
                        <select class="form-select" id="dcmtOdModalTreatmentAdd">
                            <option value=""><?php echo htmlspecialchars(trans('patient', 'odontogram_choose_treatment')); ?></option>
                        </select>
                        <button type="button" class="btn btn-outline-primary" id="dcmtOdModalTreatmentAddBtn">
                            <i class="fas fa-plus"></i>
                        </button>
                    </div>
                </div>
                <p class="dcmt-od-modal-no-treatments small text-warning mb-2" hidden></p>
                <div class="dcmt-od-modal-treatment-chips d-flex flex-wrap gap-1 mb-1" id="dcmtOdModalTreatmentChips"></div>
                <p class="small text-muted mb-0"><?php echo htmlspecialchars(trans('patient', 'odontogram_modal_footnote')); ?></p>
            </div>
            <div class="modal-footer flex-wrap">
                <button type="button" class="btn btn-outline-danger me-auto" id="dcmtOdModalClearBtn">
                    <i class="fas fa-trash-alt me-1"></i><span class="dcmt-od-modal-clear-label"></span>
                </button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                    <span class="dcmt-od-modal-cancel-label"></span>
                </button>
                <button type="button" class="btn btn-primary" id="dcmtOdModalSaveBtn">
                    <i class="fas fa-check me-1"></i><span class="dcmt-od-modal-save-label"></span>
                </button>
            </div>
        </div>
    </div>
</div>

<script src="../../assets/js/odontogram.js"></script>
