<?php
/**
 * Single odontogram chart block (problem or solution).
 *
 * Required: $dcmt_od_chart_key ('problem'|'solution')
 * Required: $dcmt_od_chart_title (string)
 * Required: $dcmt_od_chart_initial_json (string JSON for this chart only)
 * Required: $dcmt_od_trans_json, $dcmt_od_treatments_json
 * Optional: $dcmt_odontogram_patient_id, $dcmt_od_chart_readonly (bool)
 */
$dcmt_od_chart_key = isset($dcmt_od_chart_key) ? (string) $dcmt_od_chart_key : 'problem';
$dcmt_od_chart_readonly = !empty($dcmt_od_chart_readonly);
$dcmt_od_chart_suffix = preg_replace('/[^a-z0-9_-]/i', '', $dcmt_od_chart_key);
$dcmt_odontogram_patient_id = isset($dcmt_odontogram_patient_id) ? (int) $dcmt_odontogram_patient_id : 0;

if (!isset($dcmt_od_chart_initial_json) || !is_string($dcmt_od_chart_initial_json)) {
    $dcmt_od_chart_initial_json = '{}';
}
if (trim($dcmt_od_chart_initial_json) === '') {
    $dcmt_od_chart_initial_json = '{}';
}
$decoded_chart = json_decode($dcmt_od_chart_initial_json, true);
if (!is_array($decoded_chart)) {
    $dcmt_od_chart_initial_json = '{}';
} else {
    $dcmt_od_chart_initial_json = json_encode($decoded_chart, JSON_UNESCAPED_UNICODE);
}

$dcmt_od_section_class = 'dcmt-odontogram-dual-block dcmt-odontogram-dual-block--' . htmlspecialchars($dcmt_od_chart_suffix, ENT_QUOTES, 'UTF-8');
?>
<div class="<?php echo $dcmt_od_section_class; ?>">
    <h6 class="dcmt-odontogram-dual-heading mb-2">
        <?php if ($dcmt_od_chart_key === 'problem'): ?>
            <i class="fas fa-exclamation-circle text-warning me-2"></i>
        <?php else: ?>
            <i class="fas fa-check-circle text-success me-2"></i>
        <?php endif; ?>
        <?php echo htmlspecialchars($dcmt_od_chart_title); ?>
    </h6>

    <div id="dcmtOdontogramRoot-<?php echo htmlspecialchars($dcmt_od_chart_suffix); ?>"
         class="dcmt-odontogram-root"
         data-chart-key="<?php echo htmlspecialchars($dcmt_od_chart_key); ?>"
         data-readonly="<?php echo $dcmt_od_chart_readonly ? '1' : '0'; ?>"
         data-patient-id="<?php echo $dcmt_odontogram_patient_id > 0 ? (int) $dcmt_odontogram_patient_id : ''; ?>"
         data-trans="<?php echo htmlspecialchars($dcmt_od_trans_json, ENT_QUOTES, 'UTF-8'); ?>"
         data-treatments="<?php echo htmlspecialchars($dcmt_od_treatments_json, ENT_QUOTES, 'UTF-8'); ?>">

        <?php if (!$dcmt_od_chart_readonly): ?>
            <p class="dcmt-odontogram-help mb-2"><?php echo htmlspecialchars(trans('patient', 'odontogram_help_blocks')); ?></p>
        <?php endif; ?>

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
                <div class="dcmt-odontogram-row dcmt-odontogram-row-upper"></div>
            </div>
            <div class="dcmt-arch-divider" aria-hidden="true"></div>
            <div class="dcmt-odontogram-arch" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_lower_arch')); ?>">
                <div class="dcmt-odontogram-row dcmt-odontogram-row-lower"></div>
            </div>
        </div>

        <div class="dcmt-odontogram-zonas">
            <div class="dcmt-zona-card dcmt-zona-card--posterior">
                <div class="dcmt-zona-label"><?php echo htmlspecialchars(trans('patient', 'odontogram_zona_posterior')); ?></div>
                <?php
                $dcmt_zona_zone_key = 'zonaPosterior';
                $dcmt_zona_zone_slug = 'posterior';
                $dcmt_zona_id_prefix = 'dcmtZonaPosterior_' . $dcmt_od_chart_suffix;
                $dcmt_zona_readonly = $dcmt_od_chart_readonly;
                include __DIR__ . '/odontogram_zona_grid.php';
                ?>
            </div>
            <div class="dcmt-zona-card dcmt-zona-card--anterior">
                <div class="dcmt-zona-label"><?php echo htmlspecialchars(trans('patient', 'odontogram_zona_anterior')); ?></div>
                <?php
                $dcmt_zona_zone_key = 'zonaAnterior';
                $dcmt_zona_zone_slug = 'anterior';
                $dcmt_zona_id_prefix = 'dcmtZonaAnterior_' . $dcmt_od_chart_suffix;
                $dcmt_zona_readonly = $dcmt_od_chart_readonly;
                include __DIR__ . '/odontogram_zona_grid.php';
                ?>
            </div>
        </div>

        <div class="dcmt-odontogram-actions">
            <button type="button" class="btn btn-outline-primary btn-sm dcmt-odontogram-print-btn">
                <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_print')); ?>
            </button>
        </div>
    </div>

    <script type="application/json" id="dcmt-odontogram-initial-<?php echo htmlspecialchars($dcmt_od_chart_suffix); ?>"><?php echo htmlspecialchars($dcmt_od_chart_initial_json, ENT_NOQUOTES, 'UTF-8'); ?></script>
</div>
