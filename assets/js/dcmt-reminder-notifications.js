/**
 * Header reminder bell — polling, quick add, dismiss/complete actions.
 */
(function (global) {
    'use strict';

    const cfg = global.dcmtReminderNotifications || {};
    const pollUrl = cfg.pollUrl || '';
    const basePath = cfg.basePath || '';
    const csrfToken = cfg.csrfToken || '';
    const labels = cfg.labels || {};
    const sync = global.dcmtAppointmentSync || {};

    const wrap = document.getElementById('dcmtReminderNotificationsWrap');
    if (!wrap || !pollUrl) {
        return;
    }

    const badge = document.getElementById('dcmtReminderBellBadge');
    const bellBtn = document.getElementById('dcmtReminderBellBtn');
    const listEl = document.getElementById('dcmtReminderNotificationsList');
    const quickBtn = document.getElementById('dcmtQuickReminderBtn');
    const quickModalEl = document.getElementById('dcmtQuickReminderModal');
    const quickTitleEl = document.getElementById('dcmtQuickReminderTitle');
    const quickDateEl = document.getElementById('dcmtQuickReminderDate');
    const quickTimeEl = document.getElementById('dcmtQuickReminderTime');
    const quickSaveEl = document.getElementById('dcmtQuickReminderSaveBtn');
    const quickErrorEl = document.getElementById('dcmtQuickReminderError');

    let quickModal = null;
    let pollScheduler = null;
    const seenIds = new Set();

    function esc(str) {
        return String(str || '').replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]);
        });
    }

    function updateBadge(count) {
        if (!badge) return;
        const n = parseInt(count, 10) || 0;
        badge.textContent = String(n);
        badge.classList.toggle('d-none', n <= 0);
    }

    function trackSeen(items) {
        items.forEach(function (item) {
            const id = String(item.id || '');
            if (id) seenIds.add(id);
        });
    }

    function renderList(items) {
        if (!listEl) return;
        if (!items || !items.length) {
            listEl.innerHTML = '<span class="dropdown-item-text text-muted small">' + esc(labels.empty || '') + '</span>';
            return;
        }

        listEl.innerHTML = '';
        items.forEach(function (item) {
            const li = document.createElement('li');
            li.className = 'dcmt-reminder-notification-item';
            li.innerHTML =
                '<div class="dropdown-item dcmt-reminder-notification-entry">' +
                    '<div class="fw-semibold">' + esc(item.title || '') + '</div>' +
                    '<div class="small text-info">' + esc(item.message || labels.advance || '') + ' — ' + esc(item.reminder_at_display || '') + '</div>' +
                    '<div class="d-flex gap-1 mt-2 justify-content-end">' +
                        '<a class="btn btn-sm dcmt-reminder-action-icon" href="' + esc(item.view_url || '#') + '" title="' + esc(labels.view || '') + '">' +
                            '<img src="' + esc(basePath + 'assets/images/view-filled.svg') + '" alt="' + esc(labels.view || '') + '">' +
                        '</a>' +
                        '<button type="button" class="btn btn-sm dcmt-reminder-action-icon dcmt-complete-reminder-btn" data-id="' + item.id + '" title="' + esc(labels.complete || '') + '">' +
                            '<i class="fas fa-check text-success"></i>' +
                        '</button>' +
                        '<button type="button" class="btn btn-sm dcmt-reminder-action-icon dcmt-dismiss-reminder-btn" data-id="' + item.id + '" title="' + esc(labels.dismiss || '') + '">' +
                            '<i class="fas fa-times text-secondary"></i>' +
                        '</button>' +
                    '</div>' +
                '</div>';
            listEl.appendChild(li);
        });

        listEl.querySelectorAll('.dcmt-complete-reminder-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                completeNotification(btn.getAttribute('data-id'));
            });
        });
        listEl.querySelectorAll('.dcmt-dismiss-reminder-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                dismissNotification(btn.getAttribute('data-id'));
            });
        });
    }

    async function fetchReminders() {
        const res = await fetch(pollUrl, {
            method: 'GET',
            credentials: 'same-origin',
            cache: 'no-store'
        });
        const data = await res.json();
        if (!data || !data.success) return;

        updateBadge(data.count);
        const items = Array.isArray(data.notifications) ? data.notifications : [];
        trackSeen(items);
        renderList(items);
    }

    function refreshReminders() {
        if (pollScheduler && typeof pollScheduler.runNow === 'function') {
            pollScheduler.runNow();
            return;
        }
        fetchReminders().catch(function () {});
    }

    function postReminderAction(url, id) {
        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('id', String(id));
        return fetch(url, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        });
    }

    function dismissNotification(id) {
        if (!id) return;
        postReminderAction(basePath + 'pages/reminders/dismiss_notification_ajax.php', id)
            .then(function (data) {
                if (data && data.success) refreshReminders();
            });
    }

    function completeNotification(id) {
        if (!id) return;
        postReminderAction(basePath + 'pages/reminders/complete_ajax.php', id)
            .then(function (data) {
                if (data && data.success) refreshReminders();
            });
    }

    function formatDefaultDateTime() {
        const now = new Date();
        now.setHours(now.getHours() + 2);
        const year = now.getFullYear();
        const month = String(now.getMonth() + 1).padStart(2, '0');
        const day = String(now.getDate()).padStart(2, '0');
        const hour = String(now.getHours()).padStart(2, '0');
        const minute = String(now.getMinutes()).padStart(2, '0');
        return {
            date: year + '-' + month + '-' + day,
            time: hour + ':' + minute
        };
    }

    function openQuickAddModal() {
        if (!quickModalEl) return;
        if (!quickModal) {
            quickModal = new bootstrap.Modal(quickModalEl);
        }
        const defaults = formatDefaultDateTime();
        if (quickTitleEl) quickTitleEl.value = '';
        if (quickDateEl) quickDateEl.value = defaults.date;
        if (quickTimeEl) quickTimeEl.value = defaults.time;
        if (quickErrorEl) {
            quickErrorEl.classList.add('d-none');
            quickErrorEl.textContent = '';
        }
        quickModal.show();
        if (quickTitleEl) quickTitleEl.focus();
    }

    function submitQuickAdd() {
        if (!quickTitleEl || !quickDateEl || !quickTimeEl) return;

        const title = (quickTitleEl.value || '').trim();
        const date = quickDateEl.value || '';
        const time = quickTimeEl.value || '';
        if (!title || !date || !time) {
            if (quickErrorEl) {
                quickErrorEl.textContent = labels.quickValidation || '';
                quickErrorEl.classList.remove('d-none');
            }
            return;
        }

        const body = new URLSearchParams();
        body.set('csrf_token', csrfToken);
        body.set('title', title);
        body.set('reminder_date', date);
        body.set('reminder_time', time);

        fetch(basePath + 'pages/reminders/quick_add_ajax.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (data && data.success) {
                    if (quickModal) quickModal.hide();
                    refreshReminders();
                } else if (quickErrorEl) {
                    quickErrorEl.textContent = (data && data.message) ? data.message : (labels.quickFailed || '');
                    quickErrorEl.classList.remove('d-none');
                }
            });
    }

    function startPolling() {
        if (typeof sync.createVisibilityPollScheduler === 'function') {
            pollScheduler = sync.createVisibilityPollScheduler(fetchReminders, {
                visibleMs: sync.REMINDER_POLL_VISIBLE_MS,
                hiddenMs: sync.REMINDER_POLL_HIDDEN_MS
            });
        } else if (typeof sync.createPollScheduler === 'function') {
            pollScheduler = sync.createPollScheduler(fetchReminders, 30000);
            pollScheduler.start();
        } else {
            pollScheduler = {
                runNow: function () {
                    fetchReminders().catch(function () {});
                }
            };
            pollScheduler.runNow();
            global.setInterval(function () {
                pollScheduler.runNow();
            }, 30000);
        }

        global.dcmtRefreshReminderNotifications = refreshReminders;

        if (bellBtn) {
            bellBtn.addEventListener('show.bs.dropdown', refreshReminders);
        }

        global.addEventListener('dcmt:reminder-changed', refreshReminders);
    }

    function init() {
        if (quickBtn) quickBtn.addEventListener('click', openQuickAddModal);
        if (quickSaveEl) quickSaveEl.addEventListener('click', submitQuickAdd);
        startPolling();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
