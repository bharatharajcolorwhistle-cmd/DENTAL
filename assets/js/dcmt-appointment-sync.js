/**
 * Shared appointment polling helpers (header, board, list, view).
 */
(function (global) {
    'use strict';

    const POLL_MS = 5000;

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
     * @returns {{run: function, runNow: function, start: function}}
     */
    function createPollScheduler(runPoll) {
        let busy = false;
        let queued = false;
        let intervalId = null;

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
                if (intervalId !== null) {
                    return;
                }
                intervalId = window.setInterval(run, POLL_MS);
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

    global.dcmtAppointmentSync = {
        POLL_MS: POLL_MS,
        bindVisibilityRefresh: bindVisibilityRefresh,
        createPollScheduler: createPollScheduler,
        notifyAppointmentChanged: notifyAppointmentChanged
    };
})(window);
