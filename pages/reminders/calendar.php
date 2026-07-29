<?php
/**
 * Reminders Calendar View
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

$assignable_users = dcmt_reminder_get_assignable_users($dcmt_pdo);
$dcmt_is_admin = dcmt_reminder_user_is_admin($dcmt_current_user);
$csrf_token = dcmt_generate_csrf_token();
$can_manage = !empty($dcmt_current_user);

require_once __DIR__ . '/../../includes/header.php';
?>

<link href="<?php echo dcmt_asset('assets/css/select2.min.css', '../../'); ?>" rel="stylesheet">
<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<meta name="csrf-token" content="<?php echo $csrf_token; ?>">

<style>
#reminderCalendar.reminder-calendar-fc {
    min-height: 720px;
}
@media (max-width: 768px) {
    #reminderCalendar.reminder-calendar-fc {
        min-height: 520px;
    }
}
#reminderCalendar .fc-event {
    cursor: pointer;
    font-size: 0.82rem;
}
.js-status-pill {
    border: 1px solid #ced4da;
    background-color: #fff;
    color: #495057;
    font-weight: 600;
    transition: all 0.15s ease;
}
.js-status-pill:hover {
    border-color: #0d6efd;
    color: #0d6efd;
    background-color: #f8fbff;
}
.js-status-pill.is-active {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
.js-status-pill:not(.is-active) {
    opacity: 0.75;
}
.dcmt-appt-action-dialog {
    max-width: 420px;
}
.dcmt-appt-action-sheet {
    border: 0;
    border-radius: 1.25rem;
    overflow: hidden;
    box-shadow: 0 24px 64px rgba(15, 23, 42, 0.18);
    background:
        radial-gradient(120% 80% at 0% 0%, rgba(13, 148, 136, 0.10), transparent 55%),
        #fff;
}
.dcmt-appt-action-sheet-head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: 0.75rem;
    padding: 1.15rem 1.15rem 1rem;
    border-bottom: 1px solid rgba(15, 23, 42, 0.06);
}
.dcmt-appt-action-sheet-identity {
    display: flex;
    align-items: center;
    gap: 0.85rem;
    min-width: 0;
}
.dcmt-appt-action-sheet-avatar {
    width: 48px;
    height: 48px;
    border-radius: 16px;
    flex: 0 0 auto;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    font-weight: 700;
    font-size: 1rem;
    letter-spacing: 0.02em;
    color: #0f766e;
    background: linear-gradient(145deg, #ccfbf1, #e0f2fe);
}
.dcmt-appt-action-sheet-copy {
    min-width: 0;
}
.dcmt-appt-action-sheet-name {
    font-size: 1.05rem;
    font-weight: 700;
    color: #0f172a;
    line-height: 1.25;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
}
.dcmt-appt-action-sheet-meta {
    margin-top: 0.2rem;
    font-size: 0.8125rem;
    color: #64748b;
    line-height: 1.35;
}
.dcmt-appt-action-sheet-head-tools {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    flex: 0 0 auto;
}
.dcmt-appt-action-status {
    display: inline-flex;
    align-items: center;
    min-height: 1.6rem;
    padding: 0.15rem 0.65rem;
    border-radius: 999px;
    font-size: 0.6875rem;
    font-weight: 700;
    letter-spacing: 0.03em;
    text-transform: uppercase;
    background: #e2e8f0;
    color: #475569;
}
.dcmt-appt-action-status[data-status="pending"] {
    background: #ffedd5;
    color: #c2410c;
}
.dcmt-appt-action-status[data-status="completed"] {
    background: #dcfce7;
    color: #15803d;
}
.dcmt-appt-action-status[data-status="cancelled"] {
    background: #fee2e2;
    color: #b91c1c;
}
.dcmt-appt-action-close {
    width: 2rem;
    height: 2rem;
    border: 0;
    border-radius: 999px;
    background: rgba(15, 23, 42, 0.05);
    color: #64748b;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    transition: background 0.15s ease, color 0.15s ease, transform 0.15s ease;
}
.dcmt-appt-action-close:hover {
    background: rgba(15, 23, 42, 0.1);
    color: #0f172a;
}
.dcmt-appt-action-sheet-body {
    padding: 0.85rem 0.85rem 1rem;
    display: flex;
    flex-direction: column;
    gap: 0.65rem;
}
.dcmt-appt-action-section {
    display: flex;
    flex-direction: column;
    gap: 0.35rem;
    padding: 0.35rem;
    border-radius: 1rem;
    background: rgba(248, 250, 252, 0.95);
}
.dcmt-appt-action-item {
    appearance: none;
    border: 0;
    background: transparent;
    width: 100%;
    display: flex;
    align-items: center;
    gap: 0.75rem;
    padding: 0.7rem 0.75rem;
    border-radius: 0.8rem;
    text-align: left;
    text-decoration: none;
    color: #0f172a;
    transition: background 0.15s ease, transform 0.15s ease;
}
.dcmt-appt-action-item:hover {
    background: #fff;
    color: #0f172a;
    text-decoration: none;
    transform: translateY(-1px);
    box-shadow: 0 8px 18px rgba(15, 23, 42, 0.06);
}
.dcmt-appt-action-item:focus-visible {
    outline: 2px solid #0d9488;
    outline-offset: 2px;
}
.dcmt-appt-action-item.disabled,
.dcmt-appt-action-item[aria-disabled="true"] {
    opacity: 0.45;
    pointer-events: none;
}
.dcmt-appt-action-item-icon {
    width: 2.25rem;
    height: 2.25rem;
    border-radius: 0.75rem;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    flex: 0 0 auto;
    font-size: 0.95rem;
}
.dcmt-appt-action-item-icon.is-view {
    background: #e0f2fe;
    color: #0284c7;
}
.dcmt-appt-action-item-icon.is-edit {
    background: #ecfeff;
    color: #0f766e;
}
.dcmt-appt-action-item-icon.is-reschedule {
    background: #ffedd5;
    color: #c2410c;
}
.dcmt-appt-action-item-icon.is-ics {
    background: #e2e8f0;
    color: #475569;
}
.dcmt-appt-action-item-icon.is-google {
    background: #fef3c7;
    color: #b45309;
}
.dcmt-appt-action-item-icon.is-complete {
    background: #dcfce7;
    color: #15803d;
}
.dcmt-appt-action-item-label {
    flex: 1 1 auto;
    min-width: 0;
    font-size: 0.9375rem;
    font-weight: 600;
    line-height: 1.3;
}
.dcmt-appt-action-item-chevron {
    flex: 0 0 auto;
    font-size: 0.7rem;
    color: #94a3b8;
}
#dcmtReminderActionModal.fade .modal-dialog {
    transform: translateY(12px) scale(0.98);
    transition: transform 0.2s ease;
}
#dcmtReminderActionModal.show .modal-dialog {
    transform: none;
}
.dcmt-reminder-legend-row {
    display: flex;
    flex-wrap: wrap;
    gap: 0.5rem;
    margin-top: 0.75rem;
}
.dcmt-reminder-legend-chip {
    display: inline-flex;
    align-items: center;
    gap: 0.4rem;
    padding: 0.3rem 0.65rem;
    border-radius: 999px;
    background: #f8f9fa;
    color: #495057;
    font-size: 0.8rem;
    font-weight: 600;
}
.dcmt-reminder-legend-swatch {
    width: 0.75rem;
    height: 0.75rem;
    border-radius: 999px;
    flex: 0 0 auto;
}
</style>

<div class="dcmt-information-panel mb-4">
    <div class="dcmt-information-panel-title">
        <i class="fas fa-info-circle me-2"></i><?php echo trans('reminder', 'calendar_information'); ?>
    </div>
    <ul class="dcmt-information-panel-list small mb-0">
        <li><?php echo trans('reminder', 'calendar_help_1'); ?></li>
        <li><?php echo trans('reminder', 'calendar_help_2'); ?></li>
        <li><?php echo trans('reminder', 'calendar_help_3'); ?></li>
    </ul>
    <div class="dcmt-reminder-legend-row">
        <span class="dcmt-reminder-legend-chip">
            <span class="dcmt-reminder-legend-swatch" style="background:#dc3545;"></span>
            <?php echo trans('reminder', 'priority_high'); ?>
        </span>
        <span class="dcmt-reminder-legend-chip">
            <span class="dcmt-reminder-legend-swatch" style="background:#fd7e14;"></span>
            <?php echo trans('reminder', 'priority_medium'); ?>
        </span>
        <span class="dcmt-reminder-legend-chip">
            <span class="dcmt-reminder-legend-swatch" style="background:#198754;"></span>
            <?php echo trans('reminder', 'priority_low'); ?>
        </span>
        <span class="dcmt-reminder-legend-chip">
            <span class="dcmt-reminder-legend-swatch" style="background:#6c757d;"></span>
            <?php echo trans('reminder', 'status_completed'); ?>
        </span>
    </div>
</div>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5 col-md-6">
                <label for="calendarAssigneeFilter" class="form-label mb-1"><?php echo trans('reminder', 'assigned_to'); ?></label>
                <select class="form-select dcmt-filter-field" id="calendarAssigneeFilter">
                    <option value=""><?php echo trans('reminder', 'all_assignees'); ?></option>
                    <?php foreach ($assignable_users as $u): ?>
                        <option value="<?php echo (int) $u['dcmt_id']; ?>">
                            <?php echo htmlspecialchars($u['dcmt_full_name'] ?: $u['dcmt_username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-7 col-md-6">
                <div class="d-flex flex-column flex-sm-row flex-sm-wrap align-items-sm-center justify-content-lg-end gap-2 gap-sm-3">
                    <span class="text-muted small fw-semibold mb-0"><?php echo trans('reminder', 'calendar_status_legend'); ?></span>
                    <div class="d-flex flex-wrap gap-2 justify-content-sm-end">
                        <button type="button" class="btn btn-sm rounded-pill px-3 py-2 js-status-pill is-active" data-status="pending" aria-pressed="true">
                            <?php echo trans('reminder', 'status_pending'); ?>
                        </button>
                        <button type="button" class="btn btn-sm rounded-pill px-3 py-2 js-status-pill is-active" data-status="completed" aria-pressed="true">
                            <?php echo trans('reminder', 'status_completed'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <i class="fas fa-calendar-alt me-2"></i><?php echo trans('reminder', 'reminder_calendar'); ?>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="index.php" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-list me-1"></i><?php echo trans('reminder', 'created_reminders'); ?>
                </a>
                <?php if ($can_manage): ?>
                    <a href="add.php" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-plus me-1"></i><?php echo trans('reminder', 'add_reminder'); ?>
                    </a>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div id="reminderCalendar" class="reminder-calendar-fc"></div>
    </div>
</div>

<div class="modal fade" id="dcmtReminderActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered dcmt-appt-action-dialog">
        <div class="modal-content dcmt-appt-action-sheet">
            <div class="dcmt-appt-action-sheet-head">
                <div class="dcmt-appt-action-sheet-identity">
                    <div class="dcmt-appt-action-sheet-avatar" id="reminderActionAvatar" aria-hidden="true">R</div>
                    <div class="dcmt-appt-action-sheet-copy">
                        <div class="dcmt-appt-action-sheet-name" id="reminderActionTitle">—</div>
                        <div class="dcmt-appt-action-sheet-meta" id="reminderActionMeta"></div>
                    </div>
                </div>
                <div class="dcmt-appt-action-sheet-head-tools">
                    <span class="dcmt-appt-action-status" id="reminderActionStatus" data-status=""></span>
                    <button type="button" class="dcmt-appt-action-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(trans('common', 'close')); ?>">
                        <i class="fas fa-times" aria-hidden="true"></i>
                    </button>
                </div>
            </div>
            <div class="dcmt-appt-action-sheet-body">
                <div class="dcmt-appt-action-section" role="group" aria-label="<?php echo htmlspecialchars(trans('common', 'actions')); ?>">
                    <a href="#" class="dcmt-appt-action-item" id="reminderActionViewBtn">
                        <span class="dcmt-appt-action-item-icon is-view"><i class="fas fa-eye" aria-hidden="true"></i></span>
                        <span class="dcmt-appt-action-item-label"><?php echo htmlspecialchars(trans('common', 'view_details')); ?></span>
                        <i class="fas fa-chevron-right dcmt-appt-action-item-chevron" aria-hidden="true"></i>
                    </a>
                    <?php if ($can_manage): ?>
                    <a href="#" class="dcmt-appt-action-item" id="reminderActionEditBtn">
                        <span class="dcmt-appt-action-item-icon is-edit"><i class="fas fa-pen" aria-hidden="true"></i></span>
                        <span class="dcmt-appt-action-item-label"><?php echo htmlspecialchars(trans('reminder', 'edit_reminder')); ?></span>
                        <i class="fas fa-chevron-right dcmt-appt-action-item-chevron" aria-hidden="true"></i>
                    </a>
                    <?php endif; ?>
                </div>
                <div class="dcmt-appt-action-section" role="group" aria-label="<?php echo htmlspecialchars(trans('reminder', 'calendar_view')); ?>">
                    <a href="#" class="dcmt-appt-action-item" id="reminderActionGoogleBtn" target="_blank" rel="noopener noreferrer">
                        <span class="dcmt-appt-action-item-icon is-google"><i class="fas fa-calendar-plus" aria-hidden="true"></i></span>
                        <span class="dcmt-appt-action-item-label"><?php echo htmlspecialchars(trans('reminder', 'google_calendar')); ?></span>
                        <i class="fas fa-external-link-alt dcmt-appt-action-item-chevron" aria-hidden="true"></i>
                    </a>
                    <a href="#" class="dcmt-appt-action-item" id="reminderActionIcsBtn">
                        <span class="dcmt-appt-action-item-icon is-ics"><i class="fas fa-file-download" aria-hidden="true"></i></span>
                        <span class="dcmt-appt-action-item-label"><?php echo htmlspecialchars(trans('reminder', 'download_ics')); ?></span>
                        <i class="fas fa-chevron-right dcmt-appt-action-item-chevron" aria-hidden="true"></i>
                    </a>
                </div>
                <?php if ($can_manage): ?>
                <div class="dcmt-appt-action-section" role="group" aria-label="<?php echo htmlspecialchars(trans('reminder', 'mark_complete')); ?>">
                    <button type="button" class="dcmt-appt-action-item" id="reminderActionCompleteBtn">
                        <span class="dcmt-appt-action-item-icon is-complete"><i class="fas fa-check" aria-hidden="true"></i></span>
                        <span class="dcmt-appt-action-item-label"><?php echo htmlspecialchars(trans('reminder', 'mark_complete')); ?></span>
                        <i class="fas fa-chevron-right dcmt-appt-action-item-chevron" aria-hidden="true"></i>
                    </button>
                </div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
const dcmtReminderCalCsrf = <?php echo json_encode($csrf_token); ?>;
const dcmtReminderCalTrans = {
    status: <?php echo json_encode(trans('reminder', 'filter_status')); ?>,
    priority: <?php echo json_encode(trans('reminder', 'priority')); ?>,
    assigned_to: <?php echo json_encode(trans('reminder', 'assigned_to')); ?>,
    category: <?php echo json_encode(trans('reminder', 'category')); ?>,
    recurring_indicator: <?php echo json_encode(trans('reminder', 'recurring_indicator')); ?>,
    status_pending: <?php echo json_encode(trans('reminder', 'status_pending')); ?>,
    status_completed: <?php echo json_encode(trans('reminder', 'status_completed')); ?>,
    status_cancelled: <?php echo json_encode(trans('reminder', 'status_cancelled')); ?>,
    view_reminder: <?php echo json_encode(trans('reminder', 'view_reminder')); ?>,
    edit_reminder: <?php echo json_encode(trans('reminder', 'edit_reminder')); ?>,
    mark_complete: <?php echo json_encode(trans('reminder', 'mark_complete')); ?>,
    google_calendar: <?php echo json_encode(trans('reminder', 'google_calendar')); ?>,
    download_ics: <?php echo json_encode(trans('reminder', 'download_ics')); ?>,
    confirm_complete: <?php echo json_encode(trans('reminder', 'confirm_complete')); ?>,
    view_details: <?php echo json_encode(trans('common', 'view_details')); ?>
};

const visibleCalendarStatuses = new Set(['pending', 'completed']);
let clickedReminderId = null;
let clickedReminderData = null;

function dcmtCompleteReminderFromCalendar(id) {
    if (!confirm(dcmtReminderCalTrans.confirm_complete)) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', dcmtReminderCalCsrf);
    fetch('complete_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) window.location.reload();
            else alert(data.message || 'Error');
        });
}

document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('reminderCalendar');
    if (!calendarEl || typeof FullCalendar === 'undefined') return;
    const reminderActionModalElement = document.getElementById('dcmtReminderActionModal');
    const reminderActionModal = reminderActionModalElement ? new bootstrap.Modal(reminderActionModalElement) : null;

    let assigneeFilter = '';

    const calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 768 ? 'listWeek' : 'dayGridMonth',
        headerToolbar: {
            left: 'prev,next today',
            center: 'title',
            right: 'dayGridMonth,timeGridWeek,listWeek'
        },
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: true },
        height: 'auto',
        navLinks: true,
        editable: false,
        dayMaxEvents: 4,
        eventDisplay: 'block',
        dateClick: function(info) {
            <?php if ($can_manage): ?>
            window.location.href = 'add.php?date=' + info.dateStr;
            <?php endif; ?>
        },
        eventClick: function(info) {
            info.jsEvent.preventDefault();
            const props = info.event.extendedProps || {};
            clickedReminderId = info.event.id;
            clickedReminderData = Object.assign({}, props, {
                id: info.event.id,
                title: info.event.title,
                start: info.event.startStr,
                end: info.event.endStr
            });
            updateReminderActionSheet();
            if (reminderActionModal) reminderActionModal.show();
        },
        events: function(info, successCallback, failureCallback) {
            const params = new URLSearchParams({
                start: info.startStr.substring(0, 10),
                end: info.endStr.substring(0, 10)
            });
            if (assigneeFilter) params.set('assignee', assigneeFilter);

            fetch('calendar_events_ajax.php?' + params.toString())
                .then(r => r.json())
                .then(data => {
                    const filtered = (Array.isArray(data) ? data : []).filter(function(eventItem) {
                        return visibleCalendarStatuses.has(String((eventItem.extendedProps && eventItem.extendedProps.status) || '').trim());
                    });
                    successCallback(filtered);
                })
                .catch(() => failureCallback());
        }
    });

    calendar.render();

    const assigneeEl = document.getElementById('calendarAssigneeFilter');
    if (assigneeEl) {
        assigneeEl.addEventListener('change', function() {
            assigneeFilter = this.value;
            calendar.refetchEvents();
        });
    }

    document.querySelectorAll('.js-status-pill').forEach(function(pillBtn) {
        pillBtn.addEventListener('click', function() {
            const status = String(this.getAttribute('data-status') || '').trim();
            if (!status) return;
            if (visibleCalendarStatuses.has(status)) {
                visibleCalendarStatuses.delete(status);
                this.classList.remove('is-active');
                this.setAttribute('aria-pressed', 'false');
            } else {
                visibleCalendarStatuses.add(status);
                this.classList.add('is-active');
                this.setAttribute('aria-pressed', 'true');
            }
            calendar.refetchEvents();
        });
    });

    function formatReminderActionMeta(data) {
        if (!data || typeof data !== 'object') return '';
        const bits = [];
        if (data.start) {
            const startDate = new Date(data.start);
            if (!Number.isNaN(startDate.getTime())) {
                bits.push(startDate.toLocaleString());
            }
        }
        if (data.assignees) bits.push(data.assignees);
        if (data.category) bits.push(data.category);
        if (data.is_recurring) bits.push(dcmtReminderCalTrans.recurring_indicator);
        return bits.join(' · ');
    }

    function statusLabel(status) {
        if (status === 'completed') return dcmtReminderCalTrans.status_completed;
        if (status === 'cancelled') return dcmtReminderCalTrans.status_cancelled;
        return dcmtReminderCalTrans.status_pending;
    }

    function updateReminderActionSheet() {
        if (!clickedReminderData || typeof clickedReminderData !== 'object') return;
        const titleEl = document.getElementById('reminderActionTitle');
        const metaEl = document.getElementById('reminderActionMeta');
        const avatarEl = document.getElementById('reminderActionAvatar');
        const statusEl = document.getElementById('reminderActionStatus');
        const viewBtn = document.getElementById('reminderActionViewBtn');
        const editBtn = document.getElementById('reminderActionEditBtn');
        const googleBtn = document.getElementById('reminderActionGoogleBtn');
        const icsBtn = document.getElementById('reminderActionIcsBtn');
        const completeBtn = document.getElementById('reminderActionCompleteBtn');

        const title = String(clickedReminderData.title || '').trim() || 'Reminder';
        const status = String(clickedReminderData.status || 'pending').trim();
        if (titleEl) titleEl.textContent = title;
        if (avatarEl) avatarEl.textContent = title.charAt(0).toUpperCase() || 'R';
        if (statusEl) {
            statusEl.dataset.status = status;
            statusEl.textContent = statusLabel(status);
        }
        if (metaEl) metaEl.textContent = formatReminderActionMeta(clickedReminderData);
        if (viewBtn) viewBtn.href = clickedReminderId ? ('view.php?id=' + encodeURIComponent(String(clickedReminderId))) : '#';
        if (editBtn) editBtn.href = clickedReminderId ? ('edit.php?id=' + encodeURIComponent(String(clickedReminderId))) : '#';
        if (icsBtn) icsBtn.href = clickedReminderId ? ('export_ics.php?id=' + encodeURIComponent(String(clickedReminderId))) : '#';
        if (googleBtn) googleBtn.href = clickedReminderData.google_calendar_url || '#';
        if (completeBtn) {
            completeBtn.classList.toggle('d-none', status !== 'pending');
        }

        document.querySelectorAll('#dcmtReminderActionModal .dcmt-appt-action-section').forEach(function(section) {
            const hasVisible = Array.from(section.querySelectorAll('.dcmt-appt-action-item')).some(function(item) {
                return !item.classList.contains('d-none');
            });
            section.classList.toggle('d-none', !hasVisible);
        });
    }

    const completeBtn = document.getElementById('reminderActionCompleteBtn');
    if (completeBtn) {
        completeBtn.addEventListener('click', function() {
            if (!clickedReminderId) return;
            if (reminderActionModal) reminderActionModal.hide();
            dcmtCompleteReminderFromCalendar(clickedReminderId);
        });
    }

    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('#calendarAssigneeFilter').select2({ width: '100%', minimumResultsForSearch: 0 });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
