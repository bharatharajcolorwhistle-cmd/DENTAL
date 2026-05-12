<?php
/**
 * Odontogram block for patient add/edit forms.
 * Expects optional $dcmt_odontogram_initial_json (string JSON) and $dcmt_odontogram_patient_id (int).
 */
if (!isset($dcmt_odontogram_initial_json) || !is_string($dcmt_odontogram_initial_json)) {
    $dcmt_odontogram_initial_json = '{}';
}
if (trim($dcmt_odontogram_initial_json) === '') {
    $dcmt_odontogram_initial_json = '{}';
}
$decoded_initial = json_decode($dcmt_odontogram_initial_json, true);
if (!is_array($decoded_initial)) {
    $dcmt_odontogram_initial_json = '{}';
} else {
    $dcmt_odontogram_initial_json = json_encode($decoded_initial, JSON_UNESCAPED_UNICODE);
}

$dcmt_odontogram_patient_id = isset($dcmt_odontogram_patient_id) ? (int) $dcmt_odontogram_patient_id : 0;

$dcmt_od_trans = [
    'confirmReset' => trans('patient', 'odontogram_confirm_reset'),
    'stateDefault' => trans('patient', 'odontogram_state_default'),
    'stateDamaged' => trans('patient', 'odontogram_state_damaged'),
    'stateFilling' => trans('patient', 'odontogram_state_filling'),
    'stateMissing' => trans('patient', 'odontogram_state_missing'),
    'stateCrown' => trans('patient', 'odontogram_state_crown'),
    'stateImplant' => trans('patient', 'odontogram_state_implant'),
];
$dcmt_od_trans_json = json_encode($dcmt_od_trans, JSON_UNESCAPED_UNICODE);
?>
<link rel="stylesheet" href="../../assets/css/odontogram.css">

<div class="mb-4 dcmt-odontogram-section-wrap">
    <h5 class="mb-2">
        <i class="fas fa-tooth me-2"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_title')); ?>
    </h5>

    <div id="dcmtOdontogramRoot"
         class="dcmt-odontogram-root"
         data-patient-id="<?php echo $dcmt_odontogram_patient_id > 0 ? (int) $dcmt_odontogram_patient_id : ''; ?>"
         data-trans="<?php echo htmlspecialchars($dcmt_od_trans_json, ENT_QUOTES, 'UTF-8'); ?>">

        <p class="dcmt-odontogram-help mb-0"><?php echo htmlspecialchars(trans('patient', 'odontogram_help')); ?></p>

        <div class="dcmt-odontogram-legend" role="list" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_legend')); ?>">
            <?php
            $legend = [
                ['key' => 'default', 'label' => trans('patient', 'odontogram_state_default')],
                ['key' => 'damaged', 'label' => trans('patient', 'odontogram_state_damaged')],
                ['key' => 'filling', 'label' => trans('patient', 'odontogram_state_filling')],
                ['key' => 'missing', 'label' => trans('patient', 'odontogram_state_missing')],
                ['key' => 'crown', 'label' => trans('patient', 'odontogram_state_crown')],
                ['key' => 'implant', 'label' => trans('patient', 'odontogram_state_implant')],
            ];
            foreach ($legend as $leg) :
                ?>
                <span class="dcmt-odontogram-legend-item" role="listitem">
                    <span class="dcmt-odontogram-legend-swatch" data-legend="<?php echo htmlspecialchars($leg['key']); ?>"></span>
                    <?php echo htmlspecialchars($leg['label']); ?>
                </span>
            <?php endforeach; ?>
        </div>

        <div class="dcmt-crosshair-plate">
            <div class="dcmt-odontogram-arch" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_upper_arch')); ?>">
                <div id="dcmtOdontogramUpper" class="dcmt-odontogram-row"></div>
            </div>
            <div class="dcmt-arch-divider" aria-hidden="true"></div>
            <div class="dcmt-odontogram-arch" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_lower_arch')); ?>">
                <div id="dcmtOdontogramLower" class="dcmt-odontogram-row"></div>
            </div>
        </div>

        <div class="dcmt-odontogram-zonas">
            <div class="dcmt-zona-card dcmt-zona-card--posterior">
                <div class="dcmt-zona-label"><?php echo htmlspecialchars(trans('patient', 'odontogram_zona_posterior')); ?></div>
                <div class="dcmt-zona-grid">
                    <textarea class="form-control" id="dcmtZonaPosterior_tl" rows="2" placeholder="Q1" autocomplete="off"></textarea>
                    <textarea class="form-control" id="dcmtZonaPosterior_tr" rows="2" placeholder="Q2" autocomplete="off"></textarea>
                    <textarea class="form-control" id="dcmtZonaPosterior_bl" rows="2" placeholder="Q3" autocomplete="off"></textarea>
                    <textarea class="form-control" id="dcmtZonaPosterior_br" rows="2" placeholder="Q4" autocomplete="off"></textarea>
                </div>
            </div>
            <div class="dcmt-zona-card dcmt-zona-card--anterior">
                <div class="dcmt-zona-label"><?php echo htmlspecialchars(trans('patient', 'odontogram_zona_anterior')); ?></div>
                <div class="dcmt-zona-grid">
                    <textarea class="form-control" id="dcmtZonaAnterior_tl" rows="2" placeholder="Q1" autocomplete="off"></textarea>
                    <textarea class="form-control" id="dcmtZonaAnterior_tr" rows="2" placeholder="Q2" autocomplete="off"></textarea>
                    <textarea class="form-control" id="dcmtZonaAnterior_bl" rows="2" placeholder="Q3" autocomplete="off"></textarea>
                    <textarea class="form-control" id="dcmtZonaAnterior_br" rows="2" placeholder="Q4" autocomplete="off"></textarea>
                </div>
            </div>
        </div>

        <div class="dcmt-odontogram-actions">
            <button type="button" class="btn btn-outline-secondary btn-sm" id="dcmtOdontogramResetBtn">
                <i class="fas fa-undo me-1"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_reset')); ?>
            </button>
            <button type="button" class="btn btn-outline-primary btn-sm" id="dcmtOdontogramPrintBtn">
                <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_print')); ?>
            </button>
        </div>
    </div>

    <input type="hidden" name="odontogram_data" id="odontogram_data" value="">

    <script type="application/json" id="dcmt-odontogram-initial"><?php echo htmlspecialchars($dcmt_odontogram_initial_json, ENT_NOQUOTES, 'UTF-8'); ?></script>
</div>

<script src="../../assets/js/odontogram.js"></script>
