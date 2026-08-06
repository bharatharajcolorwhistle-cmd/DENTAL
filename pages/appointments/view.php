<?php
/**
 * View Appointment Page
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$current_user = dcmt_get_current_user();
$current_role = $current_user['dcmt_role'] ?? '';
$can_manage = dcmt_is_admin() || in_array($current_role, ['staff', 'assistant'], true);
$is_doctor = $current_role === 'doctor';
$is_owner_doctor = $is_doctor && dcmt_is_admin();
$is_limited_doctor = $is_doctor && !$is_owner_doctor;

if (!$can_manage && !$is_doctor) {
    dcmt_show_message('Access denied.', 'danger');
    $dcmt_deny_redirect = ($current_role === 'assistant')
        ? DCMT_APP_URL . '/pages/patients/index.php'
        : DCMT_APP_URL . '/pages/dashboard/index.php';
    dcmt_redirect($dcmt_deny_redirect);
    exit();
}

$appointment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($appointment_id <= 0) {
    dcmt_show_message(trans('appointment', 'invalid_appointment_id'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/pages/appointments/list.php');
    exit();
}

$appointment = null;
try {
    $sql = "
        SELECT a.*,
               p.dcmt_patient_name,
               p.dcmt_phone,
               d.dcmt_full_name AS doctor_name,
               COALESCE(d.dcmt_color_code, '') AS doctor_color,
               o.dcmt_name AS operatory_name,
               u_creator.dcmt_full_name AS created_by_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        LEFT JOIN dcmt_users u_creator ON u_creator.dcmt_id = a.dcmt_created_by
        WHERE a.dcmt_id = ?
        LIMIT 1
    ";
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$appointment_id]);
    $appointment = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Appointment view load error: ' . $e->getMessage());
}

if (!$appointment) {
    dcmt_show_message(trans('appointment', 'appointment_not_found'), 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/appointments/list.php');
    exit();
}

if ($is_limited_doctor && (int)$appointment['dcmt_doctor_id'] !== (int)$current_user['dcmt_id']) {
    dcmt_show_message(trans('appointment', 'unauthorized'), 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/appointments/index.php');
    exit();
}

$normalized_status = dcmt_normalize_appointment_status((string)($appointment['dcmt_status'] ?? 'scheduled'));
$status_class = 'text-primary';
if ($normalized_status === 'completed') {
    $status_class = 'text-success';
} elseif ($normalized_status === 'cancelled') {
    $status_class = 'text-danger';
}

$doctor_color = strtoupper(trim((string)($appointment['doctor_color'] ?? '')));
$doctor_color_valid = preg_match('/^#([0-9A-F]{6})$/', $doctor_color) === 1;

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="row">
    <div class="col-12">
        <div class="card dcmt-records-table">
            <div class="card-header dcmt-view-card-header">
                <div class="dcmt-view-card-header-content">
                    <div>
                        <h6 class="dcmt-view-card-title mb-0">
                            <i class="fas fa-calendar-check dcmt-view-card-title-icon"></i><?php echo trans('appointment', 'appointment_details'); ?>
                        </h6>
                    </div>
                    <div class="ms-3 d-flex flex-wrap align-items-center gap-2">
                        <?php if ($can_manage): ?>
                            <a href="edit.php?id=<?php echo $appointment_id; ?>" class="dcmt-add-form-view-all-link">
                                <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                            </a>
                        <?php endif; ?>
                        <a href="list.php" class="dcmt-add-form-view-all-link">
                            <i class="fas fa-list me-1"></i><?php echo trans('appointment', 'created_appointments'); ?>
                        </a>
                        <a href="index.php" class="dcmt-add-form-view-all-link">
                            <i class="fas fa-calendar-alt me-1"></i><?php echo trans('appointment', 'appointment_calendar'); ?>
                        </a>
                    </div>
                </div>
            </div>
            <div class="card-body">
                <div class="row">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'patient'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo htmlspecialchars((string)($appointment['dcmt_patient_name'] ?? '')); ?>
                                <?php if (!empty($appointment['dcmt_phone'])): ?>
                                    <div class="text-muted small"><?php echo htmlspecialchars((string)$appointment['dcmt_phone']); ?></div>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'doctor'); ?>:</span>
                            <div class="dcmt-view-field-value" <?php echo $doctor_color_valid ? ('style="color:' . htmlspecialchars($doctor_color) . ';"') : ''; ?>>
                                <?php echo htmlspecialchars((string)($appointment['doctor_name'] ?? '')); ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'operatory'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo htmlspecialchars((string)($appointment['operatory_name'] ?? '')); ?></div>
                        </div>
                    </div>
                </div>

                <div class="row mt-2">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'date'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo dcmt_format_date($appointment['dcmt_start_at']); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'start_time'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo date('h:i A', strtotime((string)$appointment['dcmt_start_at'])); ?></div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'end_time'); ?>:</span>
                            <div class="dcmt-view-field-value"><?php echo date('h:i A', strtotime((string)$appointment['dcmt_end_at'])); ?></div>
                        </div>
                    </div>
                </div>

                <div class="row mt-2">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'actual_start_time'); ?>:</span>
                            <div class="dcmt-view-field-value" id="dcmtApptViewActualStart" data-empty-label="—">
                                <?php echo !empty($appointment['dcmt_actual_start_at']) ? htmlspecialchars(date('h:i A', strtotime((string)$appointment['dcmt_actual_start_at']))) : '<span class="text-muted">—</span>'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'actual_end_time'); ?>:</span>
                            <div class="dcmt-view-field-value" id="dcmtApptViewActualEnd" data-empty-label="—">
                                <?php echo !empty($appointment['dcmt_actual_end_at']) ? htmlspecialchars(date('h:i A', strtotime((string)$appointment['dcmt_actual_end_at']))) : '<span class="text-muted">—</span>'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'status'); ?>:</span>
                            <div class="dcmt-view-field-value" id="dcmtApptViewStatus">
                                <span class="<?php echo $status_class; ?>" id="dcmtApptViewStatusText"><?php echo trans('appointment', $normalized_status); ?></span>
                            </div>
                        </div>
                    </div>
                </div>

                <?php if (trim((string)($appointment['dcmt_reason'] ?? '')) !== ''): ?>
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <div class="dcmt-view-field">
                                <span class="dcmt-view-field-label"><?php echo trans('appointment', 'reason'); ?>:</span>
                                <div class="dcmt-view-field-value"><?php echo htmlspecialchars((string)$appointment['dcmt_reason']); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <?php if (trim((string)($appointment['dcmt_notes'] ?? '')) !== ''): ?>
                    <div class="row mt-2">
                        <div class="col-md-12">
                            <div class="dcmt-view-field">
                                <span class="dcmt-view-field-label"><?php echo trans('appointment', 'notes'); ?>:</span>
                                <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars((string)$appointment['dcmt_notes'])); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <div class="row mt-2">
                    <div class="col-md-6">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'created_by'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php
                                $creator = trim((string)($appointment['created_by_name'] ?? ''));
                                echo $creator !== '' ? htmlspecialchars($creator) : '<span class="text-muted">—</span>';
                                ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-6">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('common', 'created_at'); ?>:</span>
                            <div class="dcmt-view-field-value">
                                <?php echo !empty($appointment['dcmt_created_at']) ? htmlspecialchars(date('Y-m-d H:i', strtotime((string)$appointment['dcmt_created_at']))) : '<span class="text-muted">—</span>'; ?>
                            </div>
                        </div>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function() {
    const cfg = {
        appointmentId: <?php echo (int)$appointment_id; ?>,
        pollUrl: <?php echo json_encode($base_path . 'pages/appointments/get_ajax.php?id=' . (int)$appointment_id); ?>,
        emptyHtml: '<span class="text-muted">—</span>'
    };

    const actualStartEl = document.getElementById('dcmtApptViewActualStart');
    const actualEndEl = document.getElementById('dcmtApptViewActualEnd');
    const statusTextEl = document.getElementById('dcmtApptViewStatusText');
    let lastSnapshot = '';

    function setTimeField(el, displayValue) {
        if (!el) return;
        const value = String(displayValue || '').trim();
        if (value !== '') {
            el.textContent = value;
        } else {
            el.innerHTML = cfg.emptyHtml;
        }
    }

    function applyAppointmentState(appt) {
        if (!appt) return;
        const snapshot = [
            appt.status || '',
            appt.actual_start_display || '',
            appt.actual_end_display || '',
            appt.status_class || ''
        ].join('|');
        if (snapshot === lastSnapshot) return;
        lastSnapshot = snapshot;

        setTimeField(actualStartEl, appt.actual_start_display);
        setTimeField(actualEndEl, appt.actual_end_display);

        if (statusTextEl) {
            statusTextEl.textContent = String(appt.status_label || appt.status || '');
            statusTextEl.className = String(appt.status_class || 'text-primary');
        }
    }

    async function pollAppointmentView() {
        if (!cfg.pollUrl) return;
        try {
            const res = await fetch(cfg.pollUrl, { method: 'GET', cache: 'no-store' });
            const data = await res.json();
            if (!data || !data.success || !data.appointment) return;
            applyAppointmentState(data.appointment);
        } catch (e) {
            // Ignore transient polling errors.
        }
    }

    window.dcmtRefreshAppointmentView = function() {
        lastSnapshot = '';
        return pollAppointmentView();
    };

    function startViewPolling() {
        const sync = window.dcmtAppointmentSync;
        if (sync && typeof sync.createPollScheduler === 'function') {
            const scheduler = sync.createPollScheduler(pollAppointmentView);
            scheduler.start();
            if (typeof sync.bindVisibilityRefresh === 'function') {
                sync.bindVisibilityRefresh(function() {
                    scheduler.runNow();
                });
            }
            window.addEventListener('dcmt:appointment-changed', function() {
                scheduler.runNow();
            });
            return;
        }

        pollAppointmentView();
        window.setInterval(pollAppointmentView, 5000);
        document.addEventListener('visibilitychange', function() {
            if (document.visibilityState === 'visible') {
                pollAppointmentView();
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', startViewPolling);
    } else {
        startViewPolling();
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
