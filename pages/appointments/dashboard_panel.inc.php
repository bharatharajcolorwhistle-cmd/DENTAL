<?php
/**
 * Appointment "today" board panel (embedded on main dashboard).
 * Expects: $csrf_token, $doctor_id, $appointments, $doctors, $can_manage, $is_doctor
 */
$dcmt_panel_user = dcmt_get_current_user();
$dcmt_panel_role = (string)($dcmt_panel_user['dcmt_role'] ?? '');
$dcmt_panel_is_doctor = $dcmt_panel_role === 'doctor';
$dcmt_panel_can_manage = dcmt_is_admin() || in_array($dcmt_panel_role, ['staff', 'assistant'], true);
$csrf_token = isset($csrf_token) && is_string($csrf_token) && $csrf_token !== '' ? $csrf_token : dcmt_generate_csrf_token();
$doctor_id = isset($doctor_id) ? (int)$doctor_id : 0;
$appointments = isset($appointments) && is_array($appointments) ? $appointments : [];
$doctors = isset($doctors) && is_array($doctors) ? $doctors : [];
$appointment_status_counts = isset($appointment_status_counts) && is_array($appointment_status_counts) ? $appointment_status_counts : ['scheduled' => 0, 'completed' => 0, 'cancelled' => 0];
$appointment_period_counts = isset($appointment_period_counts) && is_array($appointment_period_counts) ? $appointment_period_counts : ['today' => 0, 'week' => 0, 'month' => 0];
$dcmt_dashboard_all_label = trans('common', 'all');
if ($dcmt_dashboard_all_label !== '') {
    $dcmt_dashboard_all_label = mb_strtoupper(mb_substr($dcmt_dashboard_all_label, 0, 1, 'UTF-8'), 'UTF-8')
        . mb_strtolower(mb_substr($dcmt_dashboard_all_label, 1, null, 'UTF-8'), 'UTF-8');
}
$appointment_total_today = (int)($appointment_status_counts['scheduled'] ?? 0)
    + (int)($appointment_status_counts['completed'] ?? 0)
    + (int)($appointment_status_counts['cancelled'] ?? 0);
?>
<div class="dcmt-appointment-period-cards mb-3">
    <div class="dcmt-appointment-period-card">
        <div class="dcmt-appointment-period-label">
            <i class="fas fa-calendar-day me-1"></i><?php echo htmlspecialchars(trans('appointment', 'appointments_today_label')); ?>
        </div>
        <div class="dcmt-appointment-period-value" id="dcmtAppointmentPeriodTotal" data-count="<?php echo (int)$appointment_total_today; ?>">
            <?php echo number_format((int)$appointment_total_today); ?>
        </div>
    </div>
    <div class="dcmt-appointment-period-card">
        <div class="dcmt-appointment-period-label">
            <i class="fas fa-calendar-week me-1"></i><?php echo htmlspecialchars(trans('appointment', 'appointments_this_week_label')); ?>
        </div>
        <div class="dcmt-appointment-period-value" id="dcmtAppointmentPeriodWeek" data-count="<?php echo (int)($appointment_period_counts['week'] ?? 0); ?>">
            <?php echo number_format((int)($appointment_period_counts['week'] ?? 0)); ?>
        </div>
    </div>
    <div class="dcmt-appointment-period-card">
        <div class="dcmt-appointment-period-label">
            <i class="fas fa-calendar-alt me-1"></i><?php echo htmlspecialchars(trans('appointment', 'appointments_this_month_label')); ?>
        </div>
        <div class="dcmt-appointment-period-value" id="dcmtAppointmentPeriodMonth" data-count="<?php echo (int)($appointment_period_counts['month'] ?? 0); ?>">
            <?php echo number_format((int)($appointment_period_counts['month'] ?? 0)); ?>
        </div>
    </div>
</div>
<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="get" action="index.php" class="row g-3 align-items-end">
            <input type="hidden" name="tab" value="appointment">
            <?php if (!$dcmt_panel_is_doctor): ?>
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
                        <?php echo trans('common', 'total'); ?>: <span id="dcmtAppointmentCountTotal" data-count="<?php echo (int)$appointment_total_today; ?>" style="color: #111827; font-weight: 700;"><?php echo number_format($appointment_total_today); ?></span>
                        | <?php echo trans('appointment', 'scheduled'); ?>: <span id="dcmtAppointmentCountScheduled" data-count="<?php echo (int)($appointment_status_counts['scheduled'] ?? 0); ?>" style="color: #007bff; font-weight: 600;"><?php echo number_format((int)($appointment_status_counts['scheduled'] ?? 0)); ?></span>
                        | <?php echo trans('appointment', 'completed'); ?>: <span id="dcmtAppointmentCountCompleted" data-count="<?php echo (int)($appointment_status_counts['completed'] ?? 0); ?>" style="color: #198754; font-weight: 600;"><?php echo number_format((int)($appointment_status_counts['completed'] ?? 0)); ?></span>
                        | <?php echo trans('appointment', 'cancelled'); ?>: <span id="dcmtAppointmentCountCancelled" data-count="<?php echo (int)($appointment_status_counts['cancelled'] ?? 0); ?>" style="color: #dc3545; font-weight: 600;"><?php echo number_format((int)($appointment_status_counts['cancelled'] ?? 0)); ?></span>
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
                    $can_start = !$is_cancelled && !$is_completed && !$has_actual_start;
                    $can_end = !$is_cancelled && !$is_completed && $has_actual_start && !$has_actual_end;
                    $time_start = date('H:i', strtotime((string)$appointment['dcmt_start_at']));
                    $time_end = date('H:i', strtotime((string)$appointment['dcmt_end_at']));
                    $wa_phone = preg_replace('/\D+/', '', (string)($appointment['dcmt_phone'] ?? ''));
                    $wa_message = rawurlencode('Hello ' . (string)$appointment['dcmt_patient_name'] . ', this is a reminder for your appointment at ' . $time_start . '.');
                    $wa_link = $wa_phone !== '' ? ('https://wa.me/' . $wa_phone . '?text=' . $wa_message) : '#';
                    $action_label = $can_start ? trans('appointment', 'appointment_start') : trans('appointment', 'appointment_end');
                    $action_btn_class = $can_start ? 'dcmt-pill-btn dcmt-pill-btn-start' : 'dcmt-pill-btn dcmt-pill-btn-end';
                    $doctor_chip_color = strtoupper(trim((string)($appointment['doctor_color'] ?? '')));
                    $doctor_chip_color_valid = preg_match('/^#([0-9A-F]{6})$/', $doctor_chip_color) === 1;
                    $status_label_map = [
                        'scheduled' => trans('appointment', 'scheduled'),
                        'completed' => trans('appointment', 'completed'),
                        'cancelled' => trans('appointment', 'cancelled')
                    ];
                    $status_label = $status_label_map[$status] ?? trans('appointment', 'scheduled');
                    ?>
                    <div class="dcmt-appointment-row" data-appointment-id="<?php echo $appointment_id; ?>">
                        <a href="../appointments/view.php?id=<?php echo $appointment_id; ?>"
                           class="dcmt-appointment-row-main-link"
                           title="<?php echo htmlspecialchars(trans('appointment', 'view_appointment')); ?>">
                            <div class="dcmt-time-box">
                                <div class="dcmt-time-box-day"><?php echo htmlspecialchars(trans('common', 'today')); ?></div>
                                <div class="dcmt-time-box-start"><?php echo htmlspecialchars($time_start); ?></div>
                                <div class="dcmt-time-box-duration"><?php echo max(1, (int)round((strtotime((string)$appointment['dcmt_end_at']) - strtotime((string)$appointment['dcmt_start_at'])) / 60)); ?> min</div>
                            </div>

                            <div class="dcmt-appointment-main">
                                <div class="dcmt-appointment-patient"><?php echo htmlspecialchars((string)$appointment['dcmt_patient_name']); ?></div>
                                <div class="dcmt-appointment-doctor-line">
                                    <span><?php echo htmlspecialchars(trans('appointment', 'doctor')); ?>: <span class="dcmt-doctor-name" <?php echo $doctor_chip_color_valid ? ('style="color:' . htmlspecialchars($doctor_chip_color) . ';"') : ''; ?>><?php echo htmlspecialchars((string)$appointment['doctor_name']); ?></span></span>
                                </div>
                                <?php if (trim((string)($appointment['dcmt_reason'] ?? '')) !== ''): ?>
                                    <div class="dcmt-appointment-doctor-line">
                                        <span><?php echo htmlspecialchars(trans('appointment', 'reason')); ?>: <?php echo htmlspecialchars((string)$appointment['dcmt_reason']); ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="dcmt-appointment-tags">
                                    <span class="dcmt-tag"><?php echo htmlspecialchars($status_label); ?></span>
                                </div>
                            </div>
                            <span class="dcmt-appointment-row-view-icon" aria-hidden="true"><i class="fas fa-chevron-right"></i></span>
                        </a>

                        <div class="dcmt-appointment-quick-actions">
                            <a class="dcmt-icon-btn" href="../patient_notes/index.php?patient_id=<?php echo (int)$appointment['dcmt_patient_id']; ?>" title="<?php echo htmlspecialchars(trans('appointment', 'view_clinical_history')); ?>">
                                <i class="far fa-file-alt"></i>
                            </a>
                            <a class="dcmt-icon-btn" href="../patient_notes/add.php?patient_id=<?php echo (int)$appointment['dcmt_patient_id']; ?>" title="<?php echo htmlspecialchars(trans('patient_note', 'add_note')); ?>">
                                <i class="fas fa-notes-medical"></i>
                            </a>
                            <a class="dcmt-icon-btn" href="../appointments/add.php?patient_id=<?php echo (int)$appointment['dcmt_patient_id']; ?>&date=<?php echo urlencode(dcmt_get_current_date()); ?>&start=<?php echo urlencode($time_start); ?>&end=<?php echo urlencode($time_end); ?>" title="<?php echo htmlspecialchars(trans('appointment', 'add_appointment')); ?>">
                                <i class="far fa-calendar-plus"></i>
                            </a>
                            <a class="dcmt-icon-btn dcmt-icon-btn-chat <?php echo $wa_phone === '' ? 'disabled' : ''; ?>" href="<?php echo htmlspecialchars($wa_link); ?>" <?php echo $wa_phone === '' ? 'tabindex="-1" aria-disabled="true"' : 'target="_blank" rel="noopener noreferrer"'; ?> title="<?php echo htmlspecialchars(trans('common', 'message')); ?>">
                                <i class="fab fa-whatsapp"></i>
                            </a>
                        </div>

                        <div class="dcmt-appointment-cta">
                            <button
                                type="button"
                                class="<?php echo $action_btn_class; ?> js-appointment-ajax-action"
                                data-appointment-id="<?php echo $appointment_id; ?>"
                                data-action="<?php echo $can_start ? 'start' : 'end'; ?>"
                                data-csrf-token="<?php echo htmlspecialchars($csrf_token); ?>"
                                <?php echo (!$can_start && !$can_end) ? 'disabled' : ''; ?>
                            >
                                    <?php echo $action_label; ?>
                            </button>
                            <button
                                type="button"
                                class="dcmt-pill-btn dcmt-pill-btn-cancel js-appointment-ajax-action"
                                data-appointment-id="<?php echo $appointment_id; ?>"
                                data-action="cancel"
                                data-csrf-token="<?php echo htmlspecialchars($csrf_token); ?>"
                                data-confirm-message="<?php echo htmlspecialchars('Are you sure you want to cancel this appointment?', ENT_QUOTES, 'UTF-8'); ?>"
                                <?php echo $is_cancelled || $is_completed ? 'disabled' : ''; ?>
                            >
                                <?php echo trans('common', 'cancel'); ?>
                            </button>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</div>
<script>
let dcmtAppointmentLiveSyncBusy = false;
let dcmtAppointmentLiveSyncSignature = '';

function dcmtUpdateAppointmentHeaderCounts(actionType) {
    const totalEl = document.getElementById('dcmtAppointmentCountTotal');
    const scheduledEl = document.getElementById('dcmtAppointmentCountScheduled');
    const completedEl = document.getElementById('dcmtAppointmentCountCompleted');
    const cancelledEl = document.getElementById('dcmtAppointmentCountCancelled');
    if (!totalEl || !scheduledEl || !completedEl || !cancelledEl) return;

    const readCount = function(el) {
        const raw = parseInt(el.getAttribute('data-count') || '0', 10);
        return Number.isNaN(raw) ? 0 : raw;
    };
    const writeCount = function(el, value) {
        const safe = value < 0 ? 0 : value;
        el.setAttribute('data-count', String(safe));
        el.textContent = String(safe);
    };

    const scheduled = readCount(scheduledEl);
    const completed = readCount(completedEl);
    const cancelled = readCount(cancelledEl);
    const total = readCount(totalEl);

    if (actionType === 'end') {
        writeCount(scheduledEl, scheduled - 1);
        writeCount(completedEl, completed + 1);
        writeCount(totalEl, total);
        return;
    }

    if (actionType === 'cancel') {
        writeCount(scheduledEl, scheduled - 1);
        writeCount(cancelledEl, cancelled + 1);
        writeCount(totalEl, total);
    }
}

function dcmtUpdateAppointmentPeriodCounts(actionType) {
    // Week/Month cards are total appointment counts.
    // End/Cancel actions change status but do not change total volume.
    return;
}

function dcmtRemoveAppointmentRow(rowEl) {
    if (!rowEl) return;
    const board = rowEl.closest('.dcmt-appointment-board');
    const cardBody = rowEl.closest('.card-body');

    rowEl.style.transition = 'opacity 0.2s ease, transform 0.2s ease, max-height 0.2s ease, margin 0.2s ease, padding 0.2s ease';
    rowEl.style.opacity = '0';
    rowEl.style.transform = 'translateY(-8px)';
    rowEl.style.maxHeight = rowEl.offsetHeight + 'px';

    window.setTimeout(function() {
        rowEl.style.maxHeight = '0px';
        rowEl.style.margin = '0';
        rowEl.style.paddingTop = '0';
        rowEl.style.paddingBottom = '0';
        rowEl.style.overflow = 'hidden';
    }, 20);

    window.setTimeout(function() {
        if (rowEl.parentNode) {
            rowEl.parentNode.removeChild(rowEl);
        }
        if (board && board.querySelectorAll('.dcmt-appointment-row').length === 0 && cardBody) {
            const emptyEl = document.createElement('p');
            emptyEl.className = 'text-muted mb-0';
            emptyEl.textContent = <?php echo json_encode(trans('appointment', 'no_appointments_today')); ?>;
            cardBody.innerHTML = '';
            cardBody.appendChild(emptyEl);
        }
    }, 240);
}

document.addEventListener('click', function(e) {
    const actionBtn = e.target.closest('.js-appointment-ajax-action');
    if (!actionBtn || actionBtn.disabled) return;

    const appointmentId = (actionBtn.getAttribute('data-appointment-id') || '').trim();
    const action = (actionBtn.getAttribute('data-action') || '').trim();
    const csrfToken = (actionBtn.getAttribute('data-csrf-token') || '').trim();
    const confirmMessage = (actionBtn.getAttribute('data-confirm-message') || '').trim();
    if (!appointmentId || !action || !csrfToken) return;
    if (confirmMessage && !window.confirm(confirmMessage)) return;

    const originalHtml = actionBtn.innerHTML;
    actionBtn.disabled = true;
    actionBtn.innerHTML = '<span class="spinner-border spinner-border-sm" role="status" aria-hidden="true"></span>';

    const payload = new FormData();
    payload.append('csrf_token', csrfToken);
    payload.append('appointment_id', appointmentId);
    payload.append('action', action);

    fetch('appointment_board_action_ajax.php', {
        method: 'POST',
        body: payload
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (!data || !data.success) {
            alert((data && data.message) ? data.message : 'Failed to update appointment');
            actionBtn.disabled = false;
            actionBtn.innerHTML = originalHtml;
            return;
        }
        const row = actionBtn.closest('.dcmt-appointment-row');
        if (!row) return;

        const statusTag = row.querySelector('.dcmt-appointment-tags .dcmt-tag:last-child');
        const startEndBtn = row.querySelector('.dcmt-appointment-cta .js-appointment-ajax-action[data-action="start"], .dcmt-appointment-cta .js-appointment-ajax-action[data-action="end"]');
        const patientEl = row.querySelector('.dcmt-appointment-patient');
        const doctorEl = row.querySelector('.dcmt-doctor-name');
        const patientName = patientEl ? (patientEl.textContent || '').trim() : '';
        const doctorName = doctorEl ? (doctorEl.textContent || '').trim() : '';

        const labels = {
            start: <?php echo json_encode(trans('appointment', 'appointment_end')); ?>
        };

        if (action === 'start' && startEndBtn) {
            if (typeof window.dcmtSetOngoingAppointment === 'function') {
                window.dcmtSetOngoingAppointment({
                    id: appointmentId,
                    patient_name: patientName,
                    doctor_name: doctorName
                });
            }
            startEndBtn.setAttribute('data-action', 'end');
            startEndBtn.classList.remove('dcmt-pill-btn-start');
            startEndBtn.classList.add('dcmt-pill-btn-end');
            startEndBtn.disabled = false;
            startEndBtn.textContent = labels.start;
            if (statusTag) statusTag.textContent = <?php echo json_encode(trans('appointment', 'scheduled')); ?>;
            return;
        }

        if (action === 'end') {
            if (typeof window.dcmtClearOngoingAppointment === 'function') {
                window.dcmtClearOngoingAppointment(appointmentId);
            }
            dcmtUpdateAppointmentHeaderCounts('end');
            dcmtRemoveAppointmentRow(row);
            return;
        }

        if (action === 'cancel') {
            if (typeof window.dcmtClearOngoingAppointment === 'function') {
                window.dcmtClearOngoingAppointment(appointmentId);
            }
            dcmtUpdateAppointmentHeaderCounts('cancel');
            dcmtRemoveAppointmentRow(row);
            return;
        }
    })
    .catch(function() {
        alert('Failed to update appointment');
        actionBtn.disabled = false;
        actionBtn.innerHTML = originalHtml;
    });
});

function dcmtSetAppointmentHeaderCounts(counts) {
    if (!counts || typeof counts !== 'object') return;
    const totalEl = document.getElementById('dcmtAppointmentCountTotal');
    const scheduledEl = document.getElementById('dcmtAppointmentCountScheduled');
    const completedEl = document.getElementById('dcmtAppointmentCountCompleted');
    const cancelledEl = document.getElementById('dcmtAppointmentCountCancelled');
    if (!totalEl || !scheduledEl || !completedEl || !cancelledEl) return;

    const scheduled = Number.parseInt(counts.scheduled || 0, 10) || 0;
    const completed = Number.parseInt(counts.completed || 0, 10) || 0;
    const cancelled = Number.parseInt(counts.cancelled || 0, 10) || 0;
    const total = scheduled + completed + cancelled;

    totalEl.setAttribute('data-count', String(total));
    totalEl.textContent = String(total);
    scheduledEl.setAttribute('data-count', String(scheduled));
    scheduledEl.textContent = String(scheduled);
    completedEl.setAttribute('data-count', String(completed));
    completedEl.textContent = String(completed);
    cancelledEl.setAttribute('data-count', String(cancelled));
    cancelledEl.textContent = String(cancelled);
}

function dcmtApplyLiveSyncState(payload) {
    const appointments = Array.isArray(payload.appointments) ? payload.appointments : [];
    const byId = {};
    appointments.forEach(function(item) {
        byId[String(item.id)] = item;
    });

    const rows = document.querySelectorAll('.dcmt-appointment-board .dcmt-appointment-row[data-appointment-id]');
    rows.forEach(function(row) {
        const id = row.getAttribute('data-appointment-id') || '';
        const state = byId[id];
        if (!state) {
            dcmtRemoveAppointmentRow(row);
            return;
        }

        const startEndBtn = row.querySelector('.dcmt-appointment-cta .js-appointment-ajax-action[data-action="start"], .dcmt-appointment-cta .js-appointment-ajax-action[data-action="end"]');
        if (!startEndBtn) return;

        if (state.has_actual_start && !state.has_actual_end && state.status !== 'cancelled' && state.status !== 'completed') {
            startEndBtn.setAttribute('data-action', 'end');
            startEndBtn.classList.remove('dcmt-pill-btn-start');
            startEndBtn.classList.add('dcmt-pill-btn-end');
            startEndBtn.disabled = false;
            startEndBtn.textContent = <?php echo json_encode(trans('appointment', 'appointment_end')); ?>;
        }
    });

    dcmtSetAppointmentHeaderCounts(payload.status_counts || {});
}

function dcmtPollAppointmentBoardState() {
    if (dcmtAppointmentLiveSyncBusy) return;
    dcmtAppointmentLiveSyncBusy = true;

    const params = new URLSearchParams();
    params.set('doctor_id', <?php echo (int)$doctor_id; ?>);
    fetch('appointment_board_state_ajax.php?' + params.toString(), {
        method: 'GET',
        cache: 'no-store'
    })
    .then(function(response) { return response.json(); })
    .then(function(data) {
        if (!data || !data.success) return;
        if (dcmtAppointmentLiveSyncSignature !== '' && data.signature === dcmtAppointmentLiveSyncSignature) {
            return;
        }
        dcmtApplyLiveSyncState(data);
        dcmtAppointmentLiveSyncSignature = data.signature || '';
    })
    .catch(function() {
        // Ignore transient polling errors to keep UI responsive.
    })
    .finally(function() {
        dcmtAppointmentLiveSyncBusy = false;
    });
}

document.addEventListener('DOMContentLoaded', function() {
    dcmtPollAppointmentBoardState();
    window.setInterval(dcmtPollAppointmentBoardState, 5000);
});
</script>
