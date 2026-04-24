<?php
/**
 * Appointment "today" board panel (embedded on main dashboard).
 * Expects: $csrf_token, $doctor_id, $appointments, $doctors, $can_manage, $is_doctor
 */
$dcmt_dashboard_all_label = trans('common', 'all');
if ($dcmt_dashboard_all_label !== '') {
    $dcmt_dashboard_all_label = mb_strtoupper(mb_substr($dcmt_dashboard_all_label, 0, 1, 'UTF-8'), 'UTF-8')
        . mb_strtolower(mb_substr($dcmt_dashboard_all_label, 1, null, 'UTF-8'), 'UTF-8');
}
?>
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="get" action="index.php" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="appointment">
            <?php if (!$is_doctor): ?>
                <div class="col-md-3">
                    <label class="form-label"><?php echo trans('appointment', 'doctor'); ?></label>
                    <select class="form-select dcmt-filter-field" name="doctor_id">
                        <option value=""><?php echo htmlspecialchars($dcmt_dashboard_all_label); ?></option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?php echo (int)$doctor['dcmt_id']; ?>" <?php echo $doctor_id === (int)$doctor['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$doctor['dcmt_full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            <?php endif; ?>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="index.php?tab=appointment" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card mb-4 dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <i class="fas fa-calendar-day me-2"></i><?php echo trans('appointment', 'appointments_today_board'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        <?php
                        $appointment_total_count = (int)($appointment_status_counts['scheduled'] ?? 0)
                            + (int)($appointment_status_counts['completed'] ?? 0)
                            + (int)($appointment_status_counts['cancelled'] ?? 0);
                        ?>
                        <?php echo trans('common', 'total'); ?>: <span style="color: #111827; font-weight: 700;"><?php echo number_format($appointment_total_count); ?></span>
                        | <?php echo trans('appointment', 'scheduled'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format((int)($appointment_status_counts['scheduled'] ?? 0)); ?></span>
                        | <?php echo trans('appointment', 'completed'); ?>: <span style="color: #198754; font-weight: 600;"><?php echo number_format((int)($appointment_status_counts['completed'] ?? 0)); ?></span>
                        | <?php echo trans('appointment', 'cancelled'); ?>: <span style="color: #dc3545; font-weight: 600;"><?php echo number_format((int)($appointment_status_counts['cancelled'] ?? 0)); ?></span>
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="../appointments/add.php" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'add_appointment'); ?>
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($appointments)): ?>
            <p class="text-muted mb-0"><?php echo trans('appointment', 'no_appointments_today'); ?></p>
        <?php else: ?>
            <div class="dcmt-appointment-board">
                <?php foreach ($appointments as $appointment): ?>
                    <?php
                    $appointment_id = (int)$appointment['dcmt_id'];
                    $status = dcmt_normalize_appointment_status((string)$appointment['dcmt_status']);
                    $has_actual_start = !empty($appointment['dcmt_actual_start_at']);
                    $has_actual_end = !empty($appointment['dcmt_actual_end_at']);
                    $is_cancelled = $status === 'cancelled';
                    $is_completed = $status === 'completed';
                    $can_start = ($can_manage || $is_doctor) && !$is_cancelled && !$is_completed && !$has_actual_start;
                    $can_end = ($can_manage || $is_doctor) && !$is_cancelled && !$is_completed && $has_actual_start && !$has_actual_end;
                    $time_start = date('H:i', strtotime((string)$appointment['dcmt_start_at']));
                    $time_end = date('H:i', strtotime((string)$appointment['dcmt_end_at']));
                    $wa_phone = preg_replace('/\D+/', '', (string)($appointment['dcmt_phone'] ?? ''));
                    $wa_message = rawurlencode('Hello ' . (string)$appointment['dcmt_patient_name'] . ', this is a reminder for your appointment at ' . $time_start . '.');
                    $wa_link = $wa_phone !== '' ? ('https://wa.me/' . $wa_phone . '?text=' . $wa_message) : '#';
                    $action_label = $can_start ? trans('appointment', 'appointment_start') : trans('appointment', 'appointment_end');
                    $action_btn_class = $can_start ? 'dcmt-pill-btn dcmt-pill-btn-start' : 'dcmt-pill-btn dcmt-pill-btn-end';
                    $doctor_chip_color = strtoupper(trim((string)($appointment['doctor_color'] ?? '')));
                    $doctor_chip_color_valid = preg_match('/^#([0-9A-F]{6})$/', $doctor_chip_color) === 1;
                    ?>
                    <div class="dcmt-appointment-row">
                        <div class="dcmt-chip dcmt-chip-time">
                            <div><?php echo htmlspecialchars($time_start); ?></div>
                            <div><?php echo htmlspecialchars($time_end); ?></div>
                        </div>
                        <div class="dcmt-chip dcmt-chip-name"><?php echo htmlspecialchars((string)$appointment['dcmt_patient_name']); ?></div>
                        <div class="dcmt-chip dcmt-chip-doctor" <?php echo $doctor_chip_color_valid ? ('style="color:' . htmlspecialchars($doctor_chip_color) . ';"') : ''; ?>><?php echo htmlspecialchars((string)$appointment['doctor_name']); ?></div>

                        <a class="dcmt-icon-btn" href="../patient_notes/index.php?patient_id=<?php echo (int)$appointment['dcmt_patient_id']; ?>" title="<?php echo htmlspecialchars(trans('appointment', 'view_clinical_history')); ?>">
                            <i class="fas fa-clipboard-list"></i>
                        </a>
                        <a class="dcmt-icon-btn" href="../appointments/add.php?patient_id=<?php echo (int)$appointment['dcmt_patient_id']; ?>&date=<?php echo urlencode(dcmt_get_current_date()); ?>&start=<?php echo urlencode($time_start); ?>&end=<?php echo urlencode($time_end); ?>" title="<?php echo htmlspecialchars(trans('appointment', 'add_appointment')); ?>">
                            <i class="fas fa-calendar-plus"></i>
                        </a>
                        <a class="dcmt-icon-btn dcmt-whatsapp <?php echo $wa_phone === '' ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars($wa_link); ?>" <?php echo $wa_phone === '' ? 'tabindex="-1" aria-disabled="true"' : 'target="_blank" rel="noopener noreferrer"'; ?> title="<?php echo htmlspecialchars(trans('common', 'message')); ?>">
                            <i class="fab fa-whatsapp"></i>
                        </a>

                        <form method="post" action="index.php?tab=appointment" class="d-inline">
                            <input type="hidden" name="dcmt_appointment_board" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                            <input type="hidden" name="action" value="<?php echo $can_start ? 'start' : 'end'; ?>">
                            <button type="submit" class="<?php echo $action_btn_class; ?>" <?php echo (!$can_start && !$can_end) ? 'disabled' : ''; ?>>
                                <?php echo $action_label; ?>
                            </button>
                        </form>
                        <form method="post" action="index.php?tab=appointment" class="d-inline" onsubmit="return confirm('Are you sure you want to cancel this appointment?');">
                            <input type="hidden" name="dcmt_appointment_board" value="1">
                            <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                            <input type="hidden" name="appointment_id" value="<?php echo $appointment_id; ?>">
                            <input type="hidden" name="action" value="cancel">
                            <button type="submit" class="dcmt-pill-btn dcmt-pill-btn-cancel" <?php echo $is_cancelled || $is_completed ? 'disabled' : ''; ?>>
                                <?php echo trans('common', 'cancel'); ?>
                            </button>
                        </form>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>

<style>
.dcmt-filter-form .form-label { font-weight: 600; }
.dcmt-appointment-board { display: flex; flex-direction: column; gap: 18px; }
.dcmt-appointment-row {
    display: grid;
    grid-template-columns: 74px minmax(260px, 1.65fr) minmax(160px, 1fr) 40px 40px 40px 108px 88px;
    align-items: center;
    column-gap: 12px;
    border: 2px solid #d6d6d6; border-radius: 28px; padding: 12px 14px; background: #fff;
    min-height: 76px;
}
.dcmt-chip {
    min-width: 0;
    width: 100%;
    text-align: center; padding: 11px 12px; border: 2px solid #d7d7d7;
    border-radius: 16px; font-weight: 600; background: #fff;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dcmt-chip-time { min-width: 84px; line-height: 1.4; }
.dcmt-chip-name,
.dcmt-chip-doctor {
    white-space: normal;
    overflow: visible;
    text-overflow: clip;
    line-height: 1.25;
    word-break: break-word;
}
.dcmt-icon-btn {
    width: 38px; height: 38px; border-radius: 50%; border: 1px solid #ced4da; color: #495057;
    display: inline-flex; align-items: center; justify-content: center; text-decoration: none; background: #fff;
    justify-self: center;
}
.dcmt-icon-btn:hover { border-color: #0d6efd; color: #0d6efd; }
.dcmt-whatsapp { color: #1fae4b; }
.dcmt-whatsapp.disabled { opacity: 0.5; pointer-events: none; }
.dcmt-pill-btn {
    width: 100%;
    border: 2px solid #343a40; border-radius: 999px; padding: 9px 12px; background: #fff; font-weight: 700;
    font-size: 0.92rem;
}
.dcmt-pill-btn:disabled { opacity: 0.45; cursor: not-allowed; }
.dcmt-pill-btn-start {
    border-color: #198754;
    background: #198754;
    color: #fff;
}
.dcmt-pill-btn-start:hover:not(:disabled) { background: #157347; border-color: #157347; }
.dcmt-pill-btn-end {
    border-color: #0d6efd;
    background: #0d6efd;
    color: #fff;
}
.dcmt-pill-btn-end:hover:not(:disabled) { background: #0b5ed7; border-color: #0b5ed7; }
.dcmt-pill-btn-cancel {
    border-color: #dc3545;
    background: #fff5f5;
    color: #b42332;
}
.dcmt-pill-btn-cancel:hover:not(:disabled) { background: #dc3545; color: #fff; }
@media (max-width: 1280px) {
    .dcmt-appointment-row {
        grid-template-columns: 74px minmax(220px, 1.4fr) minmax(150px, 1fr) 40px 40px 40px 108px 88px;
    }
}
@media (max-width: 992px) {
    .dcmt-appointment-row {
        grid-template-columns: 74px minmax(200px, 1.25fr) minmax(140px, 1fr) 40px 40px 40px 108px 88px;
    }
}
@media (max-width: 860px) {
    .dcmt-appointment-board { overflow-x: auto; padding-bottom: 4px; }
    .dcmt-appointment-row { min-width: 760px; }
}
</style>
