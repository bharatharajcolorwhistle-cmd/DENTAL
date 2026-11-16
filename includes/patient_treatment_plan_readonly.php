<?php
/**
 * Read-only treatment plan block (summary / lines / notes).
 *
 * Expected variables:
 *  - $dcmt_plan_view (array):
 *      summary            => dcmt_patient_odontogram_solution_summary() result
 *      cell_headers       => dcmt_patient_odontogram_summary_cell_headers() result
 *      groups             => dcmt_treatment_plan_group_lines() result (selected lines only)
 *      additional         => additional service lines (selected only)
 *      notes              => treatment plan notes (string)
 *      total              => grand total (float)
 *      quadrant_totals    => dcmt_treatment_plan_clinical_quadrant_pair_totals() result
 *      additional_total   => additional services total (float)
 *      doctors            => map of doctor id => name
 *      excluded_count     => number of lines not selected (int)
 *      is_saved           => whether the plan was loaded from the database (bool)
 *  - $dcmt_plan_view_mode ('full'|'summary'|'lines'|'notes')
 */

$dcmt_plan_view_mode = isset($dcmt_plan_view_mode) ? (string) $dcmt_plan_view_mode : 'full';
if (!in_array($dcmt_plan_view_mode, ['full', 'summary', 'lines', 'notes'], true)) {
    $dcmt_plan_view_mode = 'full';
}

$dcmt_pv = (isset($dcmt_plan_view) && is_array($dcmt_plan_view)) ? $dcmt_plan_view : [];
$dcmt_pv_summary = isset($dcmt_pv['summary']) && is_array($dcmt_pv['summary']) ? $dcmt_pv['summary'] : ['matrix' => [], 'has_data' => false];
$dcmt_pv_headers = isset($dcmt_pv['cell_headers']) && is_array($dcmt_pv['cell_headers']) ? $dcmt_pv['cell_headers'] : [];
$dcmt_pv_groups = isset($dcmt_pv['groups']) && is_array($dcmt_pv['groups']) ? $dcmt_pv['groups'] : [];
$dcmt_pv_additional = isset($dcmt_pv['additional']) && is_array($dcmt_pv['additional']) ? $dcmt_pv['additional'] : [];
$dcmt_pv_notes = isset($dcmt_pv['notes']) ? trim((string) $dcmt_pv['notes']) : '';
$dcmt_pv_total = isset($dcmt_pv['total']) ? (float) $dcmt_pv['total'] : 0.0;
$dcmt_pv_quadrant_totals = isset($dcmt_pv['quadrant_totals']) && is_array($dcmt_pv['quadrant_totals']) ? $dcmt_pv['quadrant_totals'] : [];
$dcmt_pv_additional_total = isset($dcmt_pv['additional_total']) ? (float) $dcmt_pv['additional_total'] : 0.0;
$dcmt_pv_doctors = isset($dcmt_pv['doctors']) && is_array($dcmt_pv['doctors']) ? $dcmt_pv['doctors'] : [];
$dcmt_pv_excluded = isset($dcmt_pv['excluded_count']) ? (int) $dcmt_pv['excluded_count'] : 0;

$dcmt_pv_show_summary = in_array($dcmt_plan_view_mode, ['full', 'summary'], true);
$dcmt_pv_show_lines = in_array($dcmt_plan_view_mode, ['full', 'lines'], true);
$dcmt_pv_show_notes = in_array($dcmt_plan_view_mode, ['full', 'notes'], true);

/**
 * Resolve a readable doctor name for a plan line.
 */
if (!function_exists('dcmt_plan_view_doctor_name')) {
    function dcmt_plan_view_doctor_name(array $line, array $doctors): string
    {
        $doctor_id = (int) ($line['doctor_id'] ?? 0);
        if ($doctor_id <= 0) {
            return '';
        }
        return (string) ($doctors[$doctor_id] ?? '');
    }
}
?>

<?php if ($dcmt_pv_show_summary): ?>
    <div class="dcmt-ro-plan-block dcmt-ro-plan-block--summary">
        <?php if (empty($dcmt_pv_summary['matrix'])): ?>
            <p class="dcmt-ro-plan-empty mb-0"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_treatments')); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-sm table-bordered align-middle dcmt-ro-plan-table mb-0">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_treatment')); ?></th>
                            <?php foreach ($dcmt_pv_headers as $dcmt_pv_header): ?>
                                <th class="text-center" title="<?php echo htmlspecialchars((string) ($dcmt_pv_header['title'] ?? $dcmt_pv_header['label'])); ?>">
                                    <?php echo htmlspecialchars((string) $dcmt_pv_header['label']); ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center"><?php echo htmlspecialchars(trans('common', 'total')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($dcmt_pv_summary['matrix'] as $dcmt_pv_row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars((string) $dcmt_pv_row['treatment']); ?></strong></td>
                                <?php foreach ($dcmt_pv_headers as $dcmt_pv_header): ?>
                                    <?php $dcmt_pv_qty = (int) ($dcmt_pv_row['cells'][$dcmt_pv_header['key']] ?? 0); ?>
                                    <td class="text-center"><?php echo $dcmt_pv_qty > 0 ? $dcmt_pv_qty : '—'; ?></td>
                                <?php endforeach; ?>
                                <td class="text-center fw-semibold"><?php echo (int) $dcmt_pv_row['total']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="dcmt-ro-plan-footnote mb-0"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_summary_footnote')); ?></p>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($dcmt_pv_show_lines): ?>
    <div class="dcmt-ro-plan-block dcmt-ro-plan-block--lines">
        <?php if ($dcmt_pv_excluded > 0): ?>
            <p class="dcmt-ro-plan-note">
                <i class="fas fa-circle-info me-1" aria-hidden="true"></i>
                <?php echo htmlspecialchars(str_replace('{count}', (string) $dcmt_pv_excluded, trans('patient', 'chart_plan_excluded_notice'))); ?>
            </p>
        <?php endif; ?>

        <?php if (empty($dcmt_pv_groups) && empty($dcmt_pv_additional)): ?>
            <p class="dcmt-ro-plan-empty mb-0"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_treatments')); ?></p>
        <?php else: ?>
            <?php if (!empty($dcmt_pv_groups)): ?>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle dcmt-ro-plan-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_treatment')); ?></th>
                                <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_location')); ?></th>
                                <th class="text-center"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_qty')); ?></th>
                                <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_doctor')); ?></th>
                                <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_service')); ?></th>
                                <th class="text-end"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_unit_price')); ?></th>
                                <th class="text-end"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_subtotal')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dcmt_pv_groups as $dcmt_pv_group): ?>
                                <tr class="dcmt-ro-plan-group-row">
                                    <td colspan="7">
                                        <div class="dcmt-ro-plan-group-head">
                                            <span class="dcmt-ro-plan-group-name">
                                                <i class="fas fa-layer-group me-1" aria-hidden="true"></i>
                                                <?php echo htmlspecialchars((string) $dcmt_pv_group['name']); ?>
                                            </span>
                                            <?php if (!empty($dcmt_pv_group['confirmed'])): ?>
                                                <span class="dcmt-ro-plan-group-badge">
                                                    <i class="fas fa-check me-1" aria-hidden="true"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_group_confirm')); ?>
                                                </span>
                                            <?php endif; ?>
                                            <span class="dcmt-ro-plan-group-subtotal">
                                                <?php echo htmlspecialchars(trans('patient', 'treatment_plan_group_subtotal')); ?>:
                                                <strong><?php echo dcmt_format_currency((float) $dcmt_pv_group['subtotal']); ?></strong>
                                            </span>
                                        </div>
                                    </td>
                                </tr>
                                <?php foreach ($dcmt_pv_group['lines'] as $dcmt_pv_line): ?>
                                    <?php $dcmt_pv_doctor = dcmt_plan_view_doctor_name($dcmt_pv_line, $dcmt_pv_doctors); ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars((string) ($dcmt_pv_line['treatment'] ?? '')); ?></td>
                                        <td class="text-muted small">
                                            <?php echo htmlspecialchars(trim((string) ($dcmt_pv_line['zone_label'] ?? '') . ' · ' . (string) ($dcmt_pv_line['quadrant_label'] ?? ''), ' ·')); ?>
                                        </td>
                                        <td class="text-center"><?php echo (int) ($dcmt_pv_line['quantity'] ?? 1); ?></td>
                                        <td><?php echo htmlspecialchars($dcmt_pv_doctor !== '' ? $dcmt_pv_doctor : '—'); ?></td>
                                        <td><?php echo htmlspecialchars((string) ($dcmt_pv_line['service_name'] ?? '') !== '' ? (string) $dcmt_pv_line['service_name'] : '—'); ?></td>
                                        <td class="text-end"><?php echo dcmt_format_currency((float) ($dcmt_pv_line['unit_price'] ?? 0)); ?></td>
                                        <td class="text-end fw-semibold"><?php echo dcmt_format_currency((float) ($dcmt_pv_line['subtotal'] ?? 0)); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($dcmt_pv_additional)): ?>
                <h4 class="dcmt-ro-plan-subtitle">
                    <i class="fas fa-plus-circle me-1" aria-hidden="true"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_additional_services')); ?>
                </h4>
                <div class="table-responsive">
                    <table class="table table-sm table-bordered align-middle dcmt-ro-plan-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_service')); ?></th>
                                <th class="text-center"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_qty')); ?></th>
                                <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_doctor')); ?></th>
                                <th class="text-end"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_unit_price')); ?></th>
                                <th class="text-end"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_subtotal')); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($dcmt_pv_additional as $dcmt_pv_line): ?>
                                <?php
                                $dcmt_pv_doctor = dcmt_plan_view_doctor_name($dcmt_pv_line, $dcmt_pv_doctors);
                                $dcmt_pv_service = trim((string) ($dcmt_pv_line['service_name'] ?? $dcmt_pv_line['treatment'] ?? ''));
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($dcmt_pv_service !== '' ? $dcmt_pv_service : '—'); ?></td>
                                    <td class="text-center"><?php echo (int) ($dcmt_pv_line['quantity'] ?? 1); ?></td>
                                    <td><?php echo htmlspecialchars($dcmt_pv_doctor !== '' ? $dcmt_pv_doctor : '—'); ?></td>
                                    <td class="text-end"><?php echo dcmt_format_currency((float) ($dcmt_pv_line['unit_price'] ?? 0)); ?></td>
                                    <td class="text-end fw-semibold"><?php echo dcmt_format_currency((float) ($dcmt_pv_line['subtotal'] ?? 0)); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>

            <?php if (!empty($dcmt_pv_quadrant_totals) || !empty($dcmt_pv_additional)): ?>
                <div class="dcmt-ro-plan-totals">
                    <?php foreach ($dcmt_pv_quadrant_totals as $dcmt_pv_qt): ?>
                        <div class="dcmt-ro-plan-totals-row">
                            <span class="dcmt-ro-plan-totals-label"><?php echo htmlspecialchars((string) $dcmt_pv_qt['label']); ?></span>
                            <span class="dcmt-ro-plan-totals-value"><?php echo dcmt_format_currency((float) $dcmt_pv_qt['total']); ?></span>
                        </div>
                    <?php endforeach; ?>
                    <?php if (!empty($dcmt_pv_additional)): ?>
                        <div class="dcmt-ro-plan-totals-row dcmt-ro-plan-totals-row--additional">
                            <span class="dcmt-ro-plan-totals-label"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_additional_services')); ?></span>
                            <span class="dcmt-ro-plan-totals-value"><?php echo dcmt_format_currency($dcmt_pv_additional_total); ?></span>
                        </div>
                    <?php endif; ?>
                    <div class="dcmt-ro-plan-totals-row dcmt-ro-plan-totals-row--grand">
                        <span class="dcmt-ro-plan-totals-label"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_grand_total')); ?></span>
                        <span class="dcmt-ro-plan-totals-value"><?php echo dcmt_format_currency($dcmt_pv_total); ?></span>
                    </div>
                </div>
            <?php endif; ?>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php if ($dcmt_pv_show_notes): ?>
    <div class="dcmt-ro-plan-block dcmt-ro-plan-block--notes">
        <?php if ($dcmt_pv_notes === ''): ?>
            <p class="dcmt-ro-plan-empty mb-0"><?php echo htmlspecialchars(trans('patient', 'chart_notes_empty')); ?></p>
        <?php else: ?>
            <div class="dcmt-ro-plan-notes"><?php echo nl2br(htmlspecialchars($dcmt_pv_notes)); ?></div>
        <?php endif; ?>
    </div>
<?php endif; ?>
