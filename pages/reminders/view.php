<?php
/**
 * View Reminder Page
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/reminder_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$reminder_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($reminder_id <= 0) {
    dcmt_show_message(trans('reminder', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$reminder = dcmt_reminder_fetch_by_id($dcmt_pdo, $reminder_id);
if (!$reminder || !dcmt_reminder_user_can_view($reminder, $dcmt_current_user)) {
    dcmt_show_message(trans('reminder', 'not_found'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$can_manage = dcmt_reminder_user_can_manage($reminder, $dcmt_current_user);
$status = $reminder['dcmt_status'] ?? 'pending';
$status_safe = in_array($status, ['pending', 'completed', 'cancelled'], true) ? $status : 'pending';
$priority = $reminder['dcmt_priority'] ?? 'medium';
$google_calendar_url = dcmt_reminder_build_google_calendar_url($reminder);
$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-bell dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('reminder', 'reminder_details'); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($reminder['dcmt_title'] ?? ''); ?></small>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <?php if ($can_manage && $status_safe === 'pending'): ?>
                <button type="button" class="dcmt-add-form-view-all-link btn btn-link p-0 me-3 border-0"
                        onclick="dcmtCompleteReminder(<?php echo $reminder_id; ?>)">
                    <i class="fas fa-check me-1"></i><?php echo trans('reminder', 'mark_complete'); ?>
                </button>
            <?php endif; ?>
            <?php if ($can_manage): ?>
                <a href="edit.php?id=<?php echo $reminder_id; ?>" class="dcmt-add-form-view-all-link me-3">
                    <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
                </a>
            <?php endif; ?>
            <?php if ($status_safe === 'pending'): ?>
                <a href="<?php echo htmlspecialchars($google_calendar_url); ?>" target="_blank" rel="noopener" class="dcmt-add-form-view-all-link me-3">
                    <i class="fas fa-calendar-plus me-1"></i><?php echo trans('reminder', 'google_calendar'); ?>
                </a>
                <a href="export_ics.php?id=<?php echo $reminder_id; ?>" class="dcmt-add-form-view-all-link me-3">
                    <i class="fas fa-file-download me-1"></i><?php echo trans('reminder', 'download_ics'); ?>
                </a>
            <?php endif; ?>
            <a href="calendar.php" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-calendar-alt me-1"></i><?php echo trans('reminder', 'calendar_view'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('reminder', 'back_to_reminders'); ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'reminder_datetime'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_reminder_format_datetime_display($reminder['dcmt_reminder_at'] ?? ''); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'notify_at'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_reminder_format_datetime_display($reminder['dcmt_notify_at'] ?? ''); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'filter_status'); ?>:</span>
                    <div class="dcmt-view-field-value">
                        <span class="text-<?php echo $status_safe === 'completed' ? 'success' : ($status_safe === 'cancelled' ? 'secondary' : 'warning'); ?>">
                            <?php echo trans('reminder', 'status_' . $status_safe); ?>
                        </span>
                        <?php if (!empty($reminder['dcmt_notification_sent']) && empty($reminder['dcmt_notification_dismissed']) && $status_safe === 'pending'): ?>
                            <span class="badge bg-info ms-1"><?php echo trans('reminder', 'advance_hours_label'); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'assigned_to'); ?>:</span>
                    <div class="dcmt-view-field-value">
                        <?php if (!empty($reminder['_assignees'])): ?>
                            <?php foreach ($reminder['_assignees'] as $assignee): ?>
                                <span class="badge bg-secondary me-1 mb-1">
                                    <?php echo htmlspecialchars($assignee['dcmt_full_name'] ?? $assignee['dcmt_username'] ?? ''); ?>
                                </span>
                            <?php endforeach; ?>
                        <?php else: ?>
                            <?php echo htmlspecialchars($reminder['assigned_user_name'] ?? '-'); ?>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'created_by'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($reminder['created_by_full_name'] ?? $reminder['dcmt_created_by'] ?? '-'); ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'priority'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo trans('reminder', 'priority_' . $priority); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'category'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($reminder['dcmt_category'] ?? '-'); ?></div>
                </div>
            </div>
            <?php if (!empty($reminder['dcmt_is_recurring'])): ?>
            <div class="col-md-8">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'recurrence'); ?>:</span>
                    <div class="dcmt-view-field-value">
                        <span class="badge bg-info"><?php echo trans('reminder', 'recurring_indicator'); ?></span>
                        <div class="mt-1"><?php echo htmlspecialchars(dcmt_reminder_format_recurrence_summary(dcmt_reminder_rule_from_row($reminder), substr((string) ($reminder['dcmt_reminder_at'] ?? ''), 0, 10))); ?></div>
                    </div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('common', 'created_on'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_format_date($reminder['dcmt_created_at'] ?? '', DCMT_DATETIME_FORMAT); ?></div>
                </div>
            </div>
            <?php if (!empty($reminder['dcmt_completed_at'])): ?>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('reminder', 'completed_at'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_reminder_format_datetime_display($reminder['dcmt_completed_at']); ?></div>
                </div>
            </div>
            <?php endif; ?>
        </div>

        <?php if (!empty($reminder['dcmt_description'])): ?>
        <div class="dcmt-view-field mt-2">
            <span class="dcmt-view-field-label"><?php echo trans('reminder', 'description'); ?>:</span>
            <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($reminder['dcmt_description'])); ?></div>
        </div>
        <?php endif; ?>

        <div class="border py-2 px-3 small mt-3 mb-0">
            <i class="fas fa-info-circle text-secondary me-1"></i>
            <?php echo trans('reminder', 'notify_at_help'); ?>
        </div>
    </div>
</div>

<script>
const dcmtReminderCsrf = <?php echo json_encode($csrf_token); ?>;
function dcmtNotifyReminderHeaderRefresh() {
    if (window.dcmtAppointmentSync && typeof window.dcmtAppointmentSync.notifyReminderChanged === 'function') {
        window.dcmtAppointmentSync.notifyReminderChanged();
    } else if (typeof window.dcmtRefreshReminderNotifications === 'function') {
        window.dcmtRefreshReminderNotifications();
    }
}
function dcmtCompleteReminder(id) {
    if (!confirm(<?php echo json_encode(trans('reminder', 'confirm_complete')); ?>)) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', dcmtReminderCsrf);
    fetch('complete_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                dcmtNotifyReminderHeaderRefresh();
                window.location.reload();
            }
            else alert(data.message || 'Error');
        });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
