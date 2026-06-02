<?php
/**
 * Odontogram summary card (Clinical History style).
 *
 * Expects:
 *   $dcmt_odontogram_card_patient_id (int)
 *   $dcmt_odontogram_card_has_data (bool)
 * Optional:
 *   $dcmt_odontogram_card_record — row from dcmt_fetch_patient_odontogram_record
 *   $dcmt_odontogram_card_patient_name (string)
 *   $dcmt_odontogram_card_patient_created_at — fallback date when no chart row
 *   $dcmt_odontogram_card_show_patient_name (bool) default false
 *   $dcmt_odontogram_card_show_when_empty (bool) default true — if false, renders nothing without data
 */

$dcmt_odontogram_card_patient_id = isset($dcmt_odontogram_card_patient_id) ? (int) $dcmt_odontogram_card_patient_id : 0;
$dcmt_odontogram_card_has_data = !empty($dcmt_odontogram_card_has_data);
$dcmt_odontogram_card_show_patient_name = !empty($dcmt_odontogram_card_show_patient_name);
$dcmt_odontogram_card_show_when_empty = !isset($dcmt_odontogram_card_show_when_empty) || $dcmt_odontogram_card_show_when_empty;

if ($dcmt_odontogram_card_patient_id <= 0) {
    return;
}
if (!$dcmt_odontogram_card_show_when_empty && !$dcmt_odontogram_card_has_data) {
    return;
}

$dcmt_odontogram_card_record = $dcmt_odontogram_card_record ?? null;
$od_patient_name = isset($dcmt_odontogram_card_patient_name) ? (string) $dcmt_odontogram_card_patient_name : '';
$od_date_raw = is_array($dcmt_odontogram_card_record) ? ($dcmt_odontogram_card_record['dcmt_updated_at'] ?? null) : null;
if ($od_date_raw === null && !empty($dcmt_odontogram_card_patient_created_at)) {
    $od_date_raw = $dcmt_odontogram_card_patient_created_at;
}
$od_date_display = $od_date_raw ? dcmt_format_date($od_date_raw) : '—';
?>
<div class="dcmt-note-card dcmt-note-card--odontogram">
    <div class="dcmt-note-card-header">
        <div>
            <?php if ($dcmt_odontogram_card_show_patient_name && $od_patient_name !== ''): ?>
                <div class="text-muted" style="font-size: 13px; font-weight: 500;">
                    <?php echo htmlspecialchars($od_patient_name); ?>
                </div>
            <?php endif; ?>
            <div class="dcmt-note-card-title">
                <i class="fas fa-tooth me-1 text-info"></i>
                <?php echo htmlspecialchars(trans('patient_note', 'odontogram_record')); ?>
            </div>
        </div>
        <div class="dcmt-note-card-date">
            <?php echo trans('common', 'date'); ?>: <?php echo $od_date_display; ?>
        </div>
    </div>
    <div class="dcmt-note-card-body">
        <?php if ($dcmt_odontogram_card_has_data): ?>
            <?php echo htmlspecialchars(trans('patient_note', 'odontogram_record_summary')); ?>
        <?php else: ?>
            <?php echo htmlspecialchars(trans('patient', 'odontogram_no_data')); ?>
        <?php endif; ?>
    </div>
    <div class="dcmt-note-card-footer">
        <span></span>
        <div class="btn-group btn-group-sm btn-group-action dcmt-note-card-actions" role="group">
            <?php if ($dcmt_odontogram_card_has_data): ?>
                <a href="../patient_odontogram/view.php?patient_id=<?php echo $dcmt_odontogram_card_patient_id; ?>"
                   class="btn" title="<?php echo trans('common', 'view'); ?>">
                    <img src="../../assets/images/view-filled.svg" alt="View">
                </a>
            <?php endif; ?>
            <a href="../patient_odontogram/edit.php?patient_id=<?php echo $dcmt_odontogram_card_patient_id; ?>"
               class="btn" title="<?php echo $dcmt_odontogram_card_has_data ? trans('patient_note', 'edit_odontogram') : trans('patient_note', 'add_odontogram'); ?>">
                <img src="../../assets/images/edit.svg" alt="Edit">
            </a>
        </div>
    </div>
</div>
