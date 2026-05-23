<?php
/**
 * Quadrant grid for odontogram zona (included by fields + view).
 */
$dcmt_zona_quadrants = [
    ['q' => 'tl', 'label' => 'Q1'],
    ['q' => 'tr', 'label' => 'Q2'],
    ['q' => 'bl', 'label' => 'Q3'],
    ['q' => 'br', 'label' => 'Q4'],
];
$dcmt_zona_is_view = !empty($dcmt_zona_readonly);
?>
<div class="dcmt-zona-grid">
    <?php foreach ($dcmt_zona_quadrants as $dcmt_q) : ?>
        <div class="dcmt-zona-quadrant"
             data-zone-key="<?php echo htmlspecialchars($dcmt_zona_zone_key); ?>"
             data-zone="<?php echo htmlspecialchars($dcmt_zona_zone_slug); ?>"
             data-quadrant="<?php echo htmlspecialchars($dcmt_q['q']); ?>"
             id="<?php echo htmlspecialchars($dcmt_zona_id_prefix . '_' . $dcmt_q['q']); ?>">
            <div class="dcmt-zona-q-head">
                <span class="dcmt-zona-q-badge"><?php echo htmlspecialchars($dcmt_q['label']); ?></span>
            </div>
            <div class="dcmt-zona-q-list" role="list"></div>
            <?php if (!$dcmt_zona_is_view) : ?>
                <div class="dcmt-zona-q-editor" hidden>
                    <div class="dcmt-zona-q-editor-title small fw-semibold mb-2"></div>
                    <label class="form-label small mb-1"><?php echo htmlspecialchars(trans('patient', 'odontogram_block_condition')); ?></label>
                    <select class="form-select form-select-sm dcmt-zona-condition-select mb-2"></select>
                    <label class="form-label small mb-1"><?php echo htmlspecialchars(trans('patient', 'odontogram_add_treatment')); ?></label>
                    <select class="form-select form-select-sm dcmt-zona-treatment-select mb-2">
                        <option value=""></option>
                    </select>
                    <div class="dcmt-zona-treatment-chips d-flex flex-wrap gap-1"></div>
                </div>
            <?php endif; ?>
        </div>
    <?php endforeach; ?>
</div>
