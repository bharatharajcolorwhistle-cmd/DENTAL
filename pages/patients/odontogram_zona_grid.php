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
        </div>
    <?php endforeach; ?>
</div>
