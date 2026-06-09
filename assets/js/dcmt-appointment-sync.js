/**
 * Shared polling helpers (appointments, reminders, and other live UI).
 */
(function (global) {
    'use strict';

    const POLL_MS = 5000;
    const REMINDER_POLL_VISIBLE_MS = 30000;
    const REMINDER_POLL_HIDDEN_MS = 120000;

    function bindVisibilityRefresh(callback) {
        if (typeof callback !== 'function') {
            return function () {};
        }
        const handler = function () {
            if (document.visibilityState === 'visible') {
                callback();
            }
        };
        document.addEventListener('visibilitychange', handler);
        return function () {
            document.removeEventListener('visibilitychange', handler);
        };
    }

    /**
     * @param {function(): (void|Promise<void>)} runPoll
     * @param {number} [pollMs] Optional interval; defaults to POLL_MS.
     * @returns {{run: function, runNow: function, start: function, stop: function, setPollMs: function}}
     */
    function createPollScheduler(runPoll, pollMs) {
        let intervalMs = (typeof pollMs === 'number' && pollMs > 0) ? pollMs : POLL_MS;
        let busy = false;
        let queued = false;
        let intervalId = null;

        function clearPollInterval() {
            if (intervalId !== null) {
                window.clearInterval(intervalId);
                intervalId = null;
            }
        }

        function startPollInterval() {
            clearPollInterval();
            intervalId = window.setInterval(run, intervalMs);
        }

        async function run() {
            if (busy) {
                queued = true;
                return;
            }
            busy = true;
            try {
                await runPoll();
            } catch (e) {
                // Swallow poll errors; callers handle UI safety.
            } finally {
                busy = false;
                if (queued) {
                    queued = false;
                    run();
                }
            }
        }

        return {
            run: run,
            runNow: function () {
                return run();
            },
            start: function () {
                run();
                if (intervalId === null) {
                    startPollInterval();
                }
            },
            stop: function () {
                clearPollInterval();
            },
            setPollMs: function (ms) {
                if (typeof ms !== 'number' || ms <= 0) {
                    return;
                }
                intervalMs = ms;
                if (intervalId !== null) {
                    startPollInterval();
                }
            }
        };
    }

    /**
     * Poll on an interval that slows when the browser tab is hidden.
     *
     * @param {function(): (void|Promise<void>)} runPoll
     * @param {{visibleMs?: number, hiddenMs?: number}} [options]
     * @returns {{runNow: function, stop: function}}
     */
    function createVisibilityPollScheduler(runPoll, options) {
        const opts = options || {};
        const visibleMs = opts.visibleMs || REMINDER_POLL_VISIBLE_MS;
        const hiddenMs = opts.hiddenMs || REMINDER_POLL_HIDDEN_MS;

        function currentIntervalMs() {
            return document.visibilityState === 'visible' ? visibleMs : hiddenMs;
        }

        const scheduler = createPollScheduler(runPoll, currentIntervalMs());
        scheduler.start();

        document.addEventListener('visibilitychange', function () {
            scheduler.setPollMs(currentIntervalMs());
            if (document.visibilityState === 'visible') {
                scheduler.runNow();
            }
        });

        return {
            runNow: function () {
                return scheduler.runNow();
            },
            stop: function () {
                scheduler.stop();
            }
        };
    }

    function notifyAppointmentChanged() {
        try {
            global.dispatchEvent(new CustomEvent('dcmt:appointment-changed'));
        } catch (e) {
            // IE / very old browsers without CustomEvent constructor.
        }
        if (typeof global.dcmtRefreshOngoingAppointmentsHeader === 'function') {
            global.dcmtRefreshOngoingAppointmentsHeader();
        }
        if (typeof global.dcmtRefreshAppointmentBoard === 'function') {
            global.dcmtRefreshAppointmentBoard();
        }
        if (typeof global.dcmtRefreshAppointmentView === 'function') {
            global.dcmtRefreshAppointmentView();
        }
        if (typeof global.dcmtRefreshAppointmentList === 'function') {
            global.dcmtRefreshAppointmentList();
        }
    }

    function notifyReminderChanged() {
        try {
            global.dispatchEvent(new CustomEvent('dcmt:reminder-changed'));
        } catch (e) {
            // Ignore.
        }
        if (typeof global.dcmtRefreshReminderNotifications === 'function') {
            global.dcmtRefreshReminderNotifications();
        }
    }

    global.dcmtAppointmentSync = {
        POLL_MS: POLL_MS,
        REMINDER_POLL_VISIBLE_MS: REMINDER_POLL_VISIBLE_MS,
        REMINDER_POLL_HIDDEN_MS: REMINDER_POLL_HIDDEN_MS,
        bindVisibilityRefresh: bindVisibilityRefresh,
        createPollScheduler: createPollScheduler,
        createVisibilityPollScheduler: createVisibilityPollScheduler,
        notifyAppointmentChanged: notifyAppointmentChanged,
        notifyReminderChanged: notifyReminderChanged
    };
})(window);
