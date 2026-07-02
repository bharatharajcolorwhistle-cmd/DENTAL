<?php
/**
 * Single odontogram chart block (problem or solution).
 *
 * Required: $dcmt_od_chart_key ('problem'|'solution')
 * Required: $dcmt_od_chart_title (string)
 * Required: $dcmt_od_chart_initial_json (string JSON for this chart only)
 * Required: $dcmt_od_trans_json, $dcmt_od_treatments_json, $dcmt_od_state_colors_json
 * Optional: $dcmt_odontogram_patient_id, $dcmt_od_chart_readonly (bool), $dcmt_od_in_tabs (bool), $dcmt_od_tab_active (bool)
 */
$dcmt_od_chart_key = isset($dcmt_od_chart_key) ? (string) $dcmt_od_chart_key : 'problem';
$dcmt_od_chart_readonly = !empty($dcmt_od_chart_readonly);
$dcmt_od_chart_suffix = preg_replace('/[^a-z0-9_-]/i', '', $dcmt_od_chart_key);
$dcmt_odontogram_patient_id = isset($dcmt_odontogram_patient_id) ? (int) $dcmt_odontogram_patient_id : 0;
$dcmt_od_in_tabs = !empty($dcmt_od_in_tabs);
$dcmt_od_tab_active = !empty($dcmt_od_tab_active);
$dcmt_od_hide_zonas = !empty($dcmt_od_hide_zonas);
$dcmt_od_hide_crosshair = !empty($dcmt_od_hide_crosshair);
$dcmt_od_show_legend = ($dcmt_od_chart_key === 'problem');
$dcmt_od_show_solution_legend = ($dcmt_od_chart_key === 'solution');
$dcmt_od_show_zonas = ($dcmt_od_chart_key === 'solution') && !$dcmt_od_hide_zonas;
$dcmt_od_help_key = $dcmt_od_chart_key === 'problem' ? 'odontogram_help_problem' : 'odontogram_help_solution';
// Tabbed problem/solution charts must use distinct suffixes; do not reuse a prior include's suffix.
if ($dcmt_od_in_tabs) {
    $dcmt_od_instance_suffix = $dcmt_od_chart_suffix;
} elseif (isset($dcmt_od_instance_suffix) && (string) $dcmt_od_instance_suffix !== '') {
    $dcmt_od_instance_suffix = preg_replace('/[^a-z0-9_-]/i', '', (string) $dcmt_od_instance_suffix);
} else {
    $dcmt_od_instance_suffix = $dcmt_od_chart_suffix;
}

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
if ($dcmt_od_in_tabs) {
    $dcmt_od_section_class .= ' dcmt-odontogram-tab-pane tab-pane fade' . ($dcmt_od_tab_active ? ' show active' : '');
}

if (!isset($dcmt_od_state_colors_json) || !is_string($dcmt_od_state_colors_json)) {
    $dcmt_od_state_colors_json = '{}';
}
$dcmt_od_state_colors_map = json_decode($dcmt_od_state_colors_json, true);
if (!is_array($dcmt_od_state_colors_map)) {
    $dcmt_od_state_colors_map = [];
}

if (!isset($dcmt_od_problems_json) || !is_string($dcmt_od_problems_json)) {
    $dcmt_od_problems_json = '[]';
}
$dcmt_od_problems_list = json_decode($dcmt_od_problems_json, true);
if (!is_array($dcmt_od_problems_list)) {
    $dcmt_od_problems_list = [];
}

if (!isset($dcmt_od_treatments_json) || !is_string($dcmt_od_treatments_json)) {
    $dcmt_od_treatments_json = '[]';
}
$dcmt_od_treatments_list = json_decode($dcmt_od_treatments_json, true);
if (!is_array($dcmt_od_treatments_list)) {
    $dcmt_od_treatments_list = [];
}

$dcmt_od_tab_id = 'dcmt-od-tab-' . $dcmt_od_chart_suffix;
?>
<div class="<?php echo $dcmt_od_section_class; ?>"
     <?php if ($dcmt_od_in_tabs): ?>
         id="<?php echo htmlspecialchars($dcmt_od_tab_id); ?>"
         role="tabpanel"
         aria-labelledby="dcmt-od-tab-<?php echo htmlspecialchars($dcmt_od_chart_suffix); ?>-btn"
         tabindex="0"
     <?php endif; ?>>

    <?php if (!$dcmt_od_in_tabs): ?>
        <h6 class="dcmt-odontogram-dual-heading mb-2">
            <?php if ($dcmt_od_chart_key === 'problem'): ?>
                <i class="fas fa-exclamation-circle text-warning me-2"></i>
            <?php else: ?>
                <i class="fas fa-check-circle text-success me-2"></i>
            <?php endif; ?>
            <?php echo htmlspecialchars($dcmt_od_chart_title); ?>
        </h6>
    <?php endif; ?>

    <div id="dcmtOdontogramRoot-<?php echo htmlspecialchars($dcmt_od_instance_suffix); ?>"
         class="dcmt-odontogram-root"
         data-chart-key="<?php echo htmlspecialchars($dcmt_od_chart_key); ?>"
         data-instance-suffix="<?php echo htmlspecialchars($dcmt_od_instance_suffix); ?>"
         data-readonly="<?php echo $dcmt_od_chart_readonly ? '1' : '0'; ?>"
         data-patient-id="<?php echo $dcmt_odontogram_patient_id > 0 ? (int) $dcmt_odontogram_patient_id : ''; ?>"
         data-trans="<?php echo htmlspecialchars($dcmt_od_trans_json, ENT_QUOTES, 'UTF-8'); ?>"
         data-treatments="<?php echo htmlspecialchars($dcmt_od_treatments_json, ENT_QUOTES, 'UTF-8'); ?>"
         data-state-colors="<?php echo htmlspecialchars($dcmt_od_state_colors_json, ENT_QUOTES, 'UTF-8'); ?>"
         data-problem-states="<?php echo htmlspecialchars($dcmt_od_problems_json, ENT_QUOTES, 'UTF-8'); ?>">

        <?php if (!$dcmt_od_chart_readonly): ?>
            <p class="dcmt-odontogram-help mb-2"><?php echo htmlspecialchars(trans('patient', $dcmt_od_help_key)); ?></p>
        <?php endif; ?>

        <?php if ($dcmt_od_show_legend): ?>
        <div class="dcmt-odontogram-legend" role="list" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_legend')); ?>">
            <?php if (!$dcmt_od_chart_readonly): ?>
                <button type="button"
                        class="dcmt-odontogram-legend-item dcmt-odontogram-legend-btn dcmt-odontogram-legend-btn--clear"
                        role="listitem"
                        data-problem-key="__clear__">
                    <span class="dcmt-odontogram-legend-swatch dcmt-odontogram-legend-swatch--clear">
                        <i class="fas fa-eraser"></i>
                    </span>
                    <?php echo htmlspecialchars(trans('common', 'clear')); ?>
                </button>
            <?php endif; ?>
            <?php foreach ($dcmt_od_problems_list as $leg):
                $leg_key = (string) ($leg['key'] ?? '');
                if ($leg_key === '') {
                    continue;
                }
                $leg_label = (string) ($leg['name'] ?? $leg_key);
                $leg_colors = $dcmt_od_state_colors_map[$leg_key] ?? null;
                $leg_fill = is_array($leg_colors) && !empty($leg_colors['fill'])
                    ? dcmt_sanitize_odontogram_hex_color((string) $leg_colors['fill'])
                    : '';
                ?>
                <?php if (!$dcmt_od_chart_readonly): ?>
                    <button type="button"
                            class="dcmt-odontogram-legend-item dcmt-odontogram-legend-btn"
                            role="listitem"
                            data-problem-key="<?php echo htmlspecialchars($leg_key); ?>">
                        <span class="dcmt-odontogram-legend-swatch" data-legend="<?php echo htmlspecialchars($leg_key); ?>"
                              <?php if ($leg_fill !== ''): ?>style="background: <?php echo htmlspecialchars($leg_fill); ?>;"<?php endif; ?>></span>
                        <?php echo htmlspecialchars($leg_label); ?>
                    </button>
                <?php else: ?>
                    <span class="dcmt-odontogram-legend-item" role="listitem">
                        <span class="dcmt-odontogram-legend-swatch" data-legend="<?php echo htmlspecialchars($leg_key); ?>"
                              <?php if ($leg_fill !== ''): ?>style="background: <?php echo htmlspecialchars($leg_fill); ?>;"<?php endif; ?>></span>
                        <?php echo htmlspecialchars($leg_label); ?>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if ($dcmt_od_show_solution_legend && !empty($dcmt_od_treatments_list)): ?>
        <div class="dcmt-odontogram-legend dcmt-odontogram-legend--solution" role="list" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_solution_legend')); ?>">
            <?php if (!$dcmt_od_chart_readonly): ?>
                <button type="button"
                        class="dcmt-odontogram-legend-item dcmt-odontogram-legend-btn dcmt-odontogram-legend-btn--clear"
                        role="listitem"
                        data-treatment-name="__clear__">
                    <span class="dcmt-odontogram-legend-swatch dcmt-odontogram-legend-swatch--clear">
                        <i class="fas fa-eraser"></i>
                    </span>
                    <?php echo htmlspecialchars(trans('common', 'clear')); ?>
                </button>
            <?php endif; ?>
            <?php foreach ($dcmt_od_treatments_list as $leg):
                $leg_name = trim((string) ($leg['name'] ?? ''));
                if ($leg_name === '') {
                    continue;
                }
                $leg_fill = !empty($leg['color'])
                    ? dcmt_sanitize_odontogram_hex_color((string) $leg['color'])
                    : dcmt_odontogram_default_treatment_color();
                ?>
                <?php if (!$dcmt_od_chart_readonly): ?>
                    <button type="button"
                            class="dcmt-odontogram-legend-item dcmt-odontogram-legend-btn"
                            role="listitem"
                            data-treatment-name="<?php echo htmlspecialchars($leg_name); ?>">
                        <span class="dcmt-odontogram-legend-swatch dcmt-odontogram-legend-swatch--treatment"
                              style="background: <?php echo htmlspecialchars($leg_fill); ?>;"></span>
                        <?php echo htmlspecialchars($leg_name); ?>
                    </button>
                <?php else: ?>
                    <span class="dcmt-odontogram-legend-item" role="listitem">
                        <span class="dcmt-odontogram-legend-swatch dcmt-odontogram-legend-swatch--treatment"
                              style="background: <?php echo htmlspecialchars($leg_fill); ?>;"></span>
                        <?php echo htmlspecialchars($leg_name); ?>
                    </span>
                <?php endif; ?>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>

        <?php if (!$dcmt_od_hide_crosshair): ?>
        <div class="dcmt-crosshair-plate">
            <div class="dcmt-odontogram-arch" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_upper_arch')); ?>">
                <div class="dcmt-odontogram-row dcmt-odontogram-row-upper"></div>
            </div>
            <div class="dcmt-arch-divider" aria-hidden="true"></div>
            <div class="dcmt-odontogram-arch" aria-label="<?php echo htmlspecialchars(trans('patient', 'odontogram_lower_arch')); ?>">
                <div class="dcmt-odontogram-row dcmt-odontogram-row-lower"></div>
            </div>
        </div>
        <?php endif; ?>

        <?php if ($dcmt_od_show_zonas): ?>
        <div class="dcmt-odontogram-zonas">
            <div class="dcmt-zona-card dcmt-zona-card--posterior">
                <div class="dcmt-zona-label"><?php echo htmlspecialchars(trans('patient', 'odontogram_zona_posterior')); ?></div>
                <?php
                $dcmt_zona_zone_key = 'zonaPosterior';
                $dcmt_zona_zone_slug = 'posterior';
                $dcmt_zona_id_prefix = 'dcmtZonaPosterior_' . $dcmt_od_instance_suffix;
                $dcmt_zona_readonly = $dcmt_od_chart_readonly;
                include __DIR__ . '/odontogram_zona_grid.php';
                ?>
            </div>
            <div class="dcmt-zona-card dcmt-zona-card--anterior">
                <div class="dcmt-zona-label"><?php echo htmlspecialchars(trans('patient', 'odontogram_zona_anterior')); ?></div>
                <?php
                $dcmt_zona_zone_key = 'zonaAnterior';
                $dcmt_zona_zone_slug = 'anterior';
                $dcmt_zona_id_prefix = 'dcmtZonaAnterior_' . $dcmt_od_instance_suffix;
                $dcmt_zona_readonly = $dcmt_od_chart_readonly;
                include __DIR__ . '/odontogram_zona_grid.php';
                ?>
            </div>
        </div>
        <?php endif; ?>

    </div>

    <script type="application/json" id="dcmt-odontogram-initial-<?php echo htmlspecialchars($dcmt_od_instance_suffix); ?>"><?php echo htmlspecialchars($dcmt_od_chart_initial_json, ENT_NOQUOTES, 'UTF-8'); ?></script>
</div>
