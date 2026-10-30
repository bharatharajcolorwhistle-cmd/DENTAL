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
$can_delete_appointment = dcmt_can_delete_appointments();
$csrf_token = dcmt_generate_csrf_token();

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
               u_creator.dcmt_full_name AS created_by_name,
               u_started.dcmt_full_name AS started_by_name,
               u_ended.dcmt_full_name AS ended_by_name
        FROM dcmt_appointments a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        INNER JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
        INNER JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
        LEFT JOIN dcmt_users u_creator ON u_creator.dcmt_id = a.dcmt_created_by
        LEFT JOIN dcmt_users u_started ON u_started.dcmt_id = a.dcmt_started_by
        LEFT JOIN dcmt_users u_ended ON u_ended.dcmt_id = a.dcmt_ended_by
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

// Permanent deletion is a Super Admin action and is posted back to this page.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && (string)($_POST['action'] ?? '') === 'delete') {
    if (!dcmt_verify_csrf_token((string)($_POST['csrf_token'] ?? ''))) {
        dcmt_show_message(trans('common', 'invalid_token'), 'danger');
        dcmt_redirect('view.php?id=' . $appointment_id);
        exit();
    }
    if (!$can_delete_appointment) {
        dcmt_show_message(trans('appointment', 'delete_unauthorized'), 'danger');
        dcmt_redirect('view.php?id=' . $appointment_id);
        exit();
    }
    $delete_result = dcmt_appointment_delete($dcmt_pdo, $appointment_id);
    if (!$delete_result['ok']) {
        dcmt_show_message((string)$delete_result['message'], 'danger');
        dcmt_redirect('view.php?id=' . $appointment_id);
        exit();
    }
    dcmt_redirect('list.php');
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

$started_by_name = trim((string)($appointment['started_by_name'] ?? ''));
$ended_by_name = trim((string)($appointment['ended_by_name'] ?? ''));
$appointment_audit = dcmt_appointment_audit_fetch($dcmt_pdo, $appointment_id, 100);
$appointment_incomes = dcmt_appointment_income_fetch($dcmt_pdo, $appointment_id);
$appointment_billing = dcmt_appointment_billing_totals($dcmt_pdo, $appointment_id, $appointment_incomes);

/**
 * Translate an appointment audit action into a readable label.
 */
if (!function_exists('dcmt_appointment_audit_label')) {
    function dcmt_appointment_audit_label(string $action): string
    {
        $action = strtolower(trim($action));
        $map = [
            'created' => 'audit_action_created',
            'rescheduled' => 'audit_action_rescheduled',
            'started' => 'audit_action_started',
            'ended' => 'audit_action_ended',
            'cancelled' => 'audit_action_cancelled',
            'deleted' => 'audit_action_deleted',
        ];
        if (isset($map[$action])) {
            return trans('appointment', $map[$action]);
        }
        return ucfirst(str_replace('_', ' ', $action));
    }
}

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
                        <a href="<?php echo DCMT_APP_URL; ?>/pages/income/add.php?appointment_id=<?php echo (int)$appointment_id; ?>" class="dcmt-add-form-view-all-link">
                            <i class="fas fa-file-invoice-dollar me-1"></i><?php echo trans('appointment', 'create_income'); ?>
                        </a>
                        <?php if ($can_manage): ?>
                            <a href="edit.php?id=<?php echo $appointment_id; ?>" class="dcmt-add-form-view-all-link">
                                <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                            </a>
                        <?php endif; ?>
                        <?php if ($can_delete_appointment): ?>
                            <form method="post"
                                  action="view.php?id=<?php echo (int)$appointment_id; ?>"
                                  class="d-inline"
                                  onsubmit="return confirm(<?php echo htmlspecialchars(json_encode(trans('appointment', 'delete_appointment_confirm')), ENT_QUOTES, 'UTF-8'); ?>);">
                                <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                                <input type="hidden" name="action" value="delete">
                                <button type="submit" class="dcmt-add-form-view-all-link text-danger border-0 bg-transparent">
                                    <i class="fas fa-trash-alt me-1"></i><?php echo trans('appointment', 'delete_appointment'); ?>
                                </button>
                            </form>
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

                <div class="row mt-2">
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'started_by'); ?>:</span>
                            <div class="dcmt-view-field-value" id="dcmtApptViewStartedBy">
                                <?php echo $started_by_name !== '' ? htmlspecialchars($started_by_name) : '<span class="text-muted">—</span>'; ?>
                            </div>
                        </div>
                    </div>
                    <div class="col-md-4">
                        <div class="dcmt-view-field">
                            <span class="dcmt-view-field-label"><?php echo trans('appointment', 'ended_by'); ?>:</span>
                            <div class="dcmt-view-field-value" id="dcmtApptViewEndedBy">
                                <?php echo $ended_by_name !== '' ? htmlspecialchars($ended_by_name) : '<span class="text-muted">—</span>'; ?>
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

<div class="row mt-4">
    <div class="col-12">
        <div class="card dcmt-records-table no-print">
            <div class="card-header dcmt-view-card-header">
                <div class="dcmt-view-card-header-content">
                    <h6 class="dcmt-view-card-title mb-0">
                        <i class="fas fa-file-invoice-dollar dcmt-view-card-title-icon"></i><?php echo trans('appointment', 'billing'); ?>
                    </h6>
                    <a href="<?php echo DCMT_APP_URL; ?>/pages/income/add.php?appointment_id=<?php echo (int)$appointment_id; ?>" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'create_income'); ?>
                    </a>
                </div>
            </div>
            <div class="card-body">
                <?php if (!empty($appointment_incomes)): ?>
                    <div class="table-responsive">
                        <table class="table table-sm align-middle mb-3">
                            <thead>
                                <tr>
                                    <th><?php echo trans('income', 'transaction_date'); ?></th>
                                    <th><?php echo trans('common', 'description'); ?></th>
                                    <th class="text-end"><?php echo trans('common', 'amount'); ?></th>
                                    <th class="text-end"><?php echo trans('income', 'total_paid_amount'); ?></th>
                                    <th class="text-end"><?php echo trans('income', 'pending_amount'); ?></th>
                                    <th><?php echo trans('income', 'payment_status'); ?></th>
                                    <th></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($appointment_incomes as $income_row): ?>
                                    <tr>
                                        <td><?php echo htmlspecialchars(date('M j, Y', strtotime((string)($income_row['dcmt_transaction_date'] ?? '')))); ?></td>
                                        <td class="small text-muted">
                                            <?php
                                            $income_desc = trim((string)($income_row['dcmt_description'] ?? ''));
                                            echo $income_desc !== '' ? htmlspecialchars($income_desc) : '&mdash;';
                                            ?>
                                        </td>
                                        <td class="text-end"><?php echo htmlspecialchars(dcmt_format_currency((float)($income_row['dcmt_amount'] ?? 0))); ?></td>
                                        <td class="text-end"><?php echo htmlspecialchars(dcmt_format_currency((float)($income_row['dcmt_total_paid_amount'] ?? 0))); ?></td>
                                        <td class="text-end"><?php echo htmlspecialchars(dcmt_format_currency((float)($income_row['dcmt_total_pending_amount'] ?? 0))); ?></td>
                                        <td class="small"><?php echo htmlspecialchars((string)($income_row['payment_status_name'] ?? '&mdash;')); ?></td>
                                        <td class="text-end">
                                            <a href="<?php echo DCMT_APP_URL; ?>/pages/income/view.php?id=<?php echo (int)($income_row['dcmt_id'] ?? 0); ?>" class="dcmt-add-form-view-all-link">
                                                <?php echo trans('common', 'view'); ?>
                                            </a>
                                        </td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                            <tfoot>
                                <tr class="fw-semibold">
                                    <td colspan="2"><?php echo trans('appointment', 'billing_totals'); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars(dcmt_format_currency($appointment_billing['billed'])); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars(dcmt_format_currency($appointment_billing['paid'])); ?></td>
                                    <td class="text-end"><?php echo htmlspecialchars(dcmt_format_currency($appointment_billing['pending'])); ?></td>
                                    <td colspan="2"></td>
                                </tr>
                            </tfoot>
                        </table>
                    </div>
                <?php else: ?>
                    <p class="text-muted mb-0"><?php echo trans('appointment', 'billing_empty'); ?></p>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<div class="row mt-4">
    <div class="col-12">
        <div class="card dcmt-records-table no-print">
            <div class="card-header dcmt-view-card-header">
                <h6 class="dcmt-view-card-title mb-0">
                    <i class="fas fa-history dcmt-view-card-title-icon"></i><?php echo trans('appointment', 'audit_trail'); ?>
                </h6>
            </div>
            <div class="card-body">
                <?php if (!empty($appointment_audit)): ?>
                    <div class="timeline">
                        <?php foreach ($appointment_audit as $index => $entry): ?>
                            <?php
                            $audit_action = strtolower((string)($entry['dcmt_action'] ?? ''));
                            $audit_icon = 'info-circle';
                            $audit_tone = 'text-muted';
                            if ($audit_action === 'started') {
                                $audit_icon = 'play';
                                $audit_tone = 'text-primary';
                            } elseif ($audit_action === 'ended') {
                                $audit_icon = 'stop';
                                $audit_tone = 'text-success';
                            } elseif ($audit_action === 'cancelled') {
                                $audit_icon = 'ban';
                                $audit_tone = 'text-danger';
                            } elseif ($audit_action === 'created') {
                                $audit_icon = 'plus';
                            } elseif ($audit_action === 'rescheduled') {
                                $audit_icon = 'calendar-day';
                            }
                            $audit_actor = trim((string)($entry['dcmt_user_name'] ?? ''));
                            if ($audit_actor === '') {
                                $audit_actor = trans('appointment', 'system_actor');
                            }
                            ?>
                            <div class="timeline-item <?php echo $index === 0 ? 'timeline-item-first' : ''; ?>">
                                <div class="timeline-marker">
                                    <i class="fas fa-<?php echo htmlspecialchars($audit_icon); ?>"></i>
                                </div>
                                <div class="timeline-content">
                                    <div class="d-flex justify-content-between align-items-start flex-wrap gap-2">
                                        <div>
                                            <h6 class="mb-1 <?php echo htmlspecialchars($audit_tone); ?>">
                                                <?php echo htmlspecialchars(dcmt_appointment_audit_label($audit_action)); ?>
                                            </h6>
                                            <?php if (!empty($entry['dcmt_new_value'])): ?>
                                                <p class="text-muted mb-1 small">
                                                    <i class="fas fa-clock me-1"></i>
                                                    <?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$entry['dcmt_new_value']))); ?>
                                                </p>
                                            <?php endif; ?>
                                            <?php if (!empty($entry['dcmt_source'])): ?>
                                                <p class="text-muted mb-1 small">
                                                    <i class="fas fa-desktop me-1"></i>
                                                    <?php echo htmlspecialchars(ucfirst(str_replace('_', ' ', (string)$entry['dcmt_source']))); ?>
                                                </p>
                                            <?php endif; ?>
                                        </div>
                                        <div class="text-end">
                                            <small class="text-muted d-block">
                                                <i class="fas fa-user me-1"></i><?php echo htmlspecialchars($audit_actor); ?>
                                            </small>
                                            <?php if (!empty($entry['dcmt_user_role'])): ?>
                                                <small class="text-muted d-block"><?php echo htmlspecialchars(ucfirst((string)$entry['dcmt_user_role'])); ?></small>
                                            <?php endif; ?>
                                            <small class="text-muted d-block">
                                                <i class="fas fa-clock me-1"></i><?php echo htmlspecialchars(date('M j, Y g:i A', strtotime((string)$entry['dcmt_created_at']))); ?>
                                            </small>
                                        </div>
                                    </div>
                                </div>
                            </div>
                        <?php endforeach; ?>
                    </div>
                <?php else: ?>
                    <div class="text-center text-muted py-4">
                        <i class="fas fa-info-circle fa-2x mb-3"></i>
                        <p class="mb-0"><?php echo trans('appointment', 'audit_empty'); ?></p>
                        <small><?php echo trans('appointment', 'audit_empty_hint'); ?></small>
                    </div>
                <?php endif; ?>
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
    const startedByEl = document.getElementById('dcmtApptViewStartedBy');
    const endedByEl = document.getElementById('dcmtApptViewEndedBy');
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
            appt.started_by_name || '',
            appt.ended_by_name || '',
            appt.status_class || ''
        ].join('|');
        if (snapshot === lastSnapshot) return;
        lastSnapshot = snapshot;

        setTimeField(actualStartEl, appt.actual_start_display);
        setTimeField(actualEndEl, appt.actual_end_display);
        setTimeField(startedByEl, appt.started_by_name);
        setTimeField(endedByEl, appt.ended_by_name);

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
