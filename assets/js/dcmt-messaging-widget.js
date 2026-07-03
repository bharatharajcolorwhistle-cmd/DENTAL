/**
 * Global floating messaging widget — bottom-right on all pages
 */
(function (global) {
    'use strict';

    const cfg = global.dcmtMessagingWidget || {};
    const apiUrl = cfg.apiUrl || '';
    const csrfToken = cfg.csrfToken || '';
    const labels = cfg.labels || {};

    let panelOpen = false;
    let activeConversationId = 0;
    let lastMessageId = 0;
    let pollTimer = null;
    let unreadTimer = null;

    const CHAT_POLL_MS = 5000;
    const UNREAD_PICKER_MS = 5000;
    const UNREAD_VISIBLE_MS = 20000;
    const UNREAD_HIDDEN_MS = 60000;

    const fab = document.getElementById('dcmtMsgFab');
    const panel = document.getElementById('dcmtMsgPanel');
    const badge = document.getElementById('dcmtMsgFabBadge');
    const viewPicker = document.getElementById('dcmtMsgViewPicker');
    const viewChat = document.getElementById('dcmtMsgViewChat');
    const convList = document.getElementById('dcmtMsgConvList');
    const userList = document.getElementById('dcmtMsgUserList');
    const userSearch = document.getElementById('dcmtMsgUserSearch');
    const recentLabel = document.getElementById('dcmtMsgRecentLabel');
    const teamLabel = document.getElementById('dcmtMsgTeamLabel');
    const chatTitle = document.getElementById('dcmtMsgChatTitle');
    const chatMessages = document.getElementById('dcmtMsgChatMessages');
    const chatInput = document.getElementById('dcmtMsgChatInput');
    const composerForm = document.getElementById('dcmtMsgComposerForm');

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]);
        });
    }

    function initials(name) {
        const p = String(name || '?').trim().split(/\s+/).filter(Boolean);
        if (!p.length) return '?';
        return p.length === 1 ? p[0].charAt(0).toUpperCase() : (p[0].charAt(0) + p[p.length - 1].charAt(0)).toUpperCase();
    }

    function apiGet(action, params) {
        const q = new URLSearchParams(params || {});
        q.set('action', action);
        return fetch(apiUrl + '?' + q.toString(), { credentials: 'same-origin' }).then(function (r) {
            return r.json();
        });
    }

    function apiPost(action, params) {
        const body = new URLSearchParams(params || {});
        body.set('action', action);
        body.set('csrf_token', csrfToken);
        return fetch(apiUrl, {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
            body: body.toString(),
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        });
    }

    function setBadge(count) {
        if (!badge) return;
        const n = parseInt(count, 10) || 0;
        badge.textContent = String(n > 99 ? '99+' : n);
        badge.classList.toggle('d-none', n <= 0);
    }

    function isPickerVisible() {
        return panelOpen && viewPicker && !viewPicker.classList.contains('d-none');
    }

    function refreshConversationUnread() {
        const q = userSearch ? userSearch.value.trim() : '';
        return apiGet('list_conversations', { q: q }).then(function (data) {
            if (data && data.success) {
                renderConversations(data.conversations || [], q);
                setBadge(data.unread_total);
            }
        }).catch(function () {});
    }

    function isChatActive() {
        return panelOpen && activeConversationId > 0 && viewChat && !viewChat.classList.contains('d-none');
    }

    function unreadPollIntervalMs() {
        if (panelOpen && isPickerVisible()) {
            return UNREAD_PICKER_MS;
        }
        if (document.visibilityState === 'hidden') {
            return UNREAD_HIDDEN_MS;
        }
        return UNREAD_VISIBLE_MS;
    }

    function pollUnreadNow() {
        pollUnread();
    }

    function pollUnread() {
        if (isPickerVisible()) {
            refreshConversationUnread();
            return;
        }
        apiGet('unread_count').then(function (data) {
            if (data && data.success) setBadge(data.count);
        }).catch(function () {});
    }

    function scheduleUnreadPoll() {
        if (unreadTimer) {
            global.clearInterval(unreadTimer);
            unreadTimer = null;
        }
        unreadTimer = global.setInterval(pollUnread, unreadPollIntervalMs());
    }

    function bindMessagingVisibilityRefresh() {
        document.addEventListener('visibilitychange', function () {
            scheduleUnreadPoll();
            if (document.visibilityState === 'visible') {
                pollUnreadNow();
                if (isChatActive()) {
                    pollActiveThreadNow();
                }
            }
        });
    }

    function openPanel() {
        if (!panel || !fab) return;
        panel.classList.remove('d-none');
        fab.setAttribute('aria-expanded', 'true');
        panelOpen = true;
        loadPicker();
        pollUnreadNow();
        scheduleUnreadPoll();
    }

    function closePanel() {
        if (!panel || !fab) return;
        panel.classList.add('d-none');
        fab.setAttribute('aria-expanded', 'false');
        panelOpen = false;
        stopPoll();
        showPickerView();
        scheduleUnreadPoll();
    }

    function togglePanel() {
        if (panelOpen) closePanel();
        else openPanel();
    }

    function showPickerView() {
        activeConversationId = 0;
        lastMessageId = 0;
        stopPoll();
        if (viewPicker) viewPicker.classList.remove('d-none');
        if (viewChat) viewChat.classList.add('d-none');
        scheduleUnreadPoll();
        if (panelOpen) {
            refreshConversationUnread();
        }
    }

    function showChatView() {
        if (viewPicker) viewPicker.classList.add('d-none');
        if (viewChat) viewChat.classList.remove('d-none');
        scheduleUnreadPoll();
    }

    function renderConversations(items, q) {
        if (!convList) return;
        if (!items || !items.length) {
            convList.innerHTML = '<div class="dcmt-msg-empty">' + esc(q ? labels.noSearchResults : labels.noChats) + '</div>';
            return;
        }
        convList.innerHTML = items.map(function (c) {
            const unread = parseInt(c.unread_count, 10) || 0;
            const unreadBadge = unread > 0
                ? '<span class="dcmt-msg-list-unread">' + esc(unread > 99 ? '99+' : String(unread)) + '</span>'
                : '';
            const itemCls = 'dcmt-msg-list-item' + (unread > 0 ? ' dcmt-msg-list-item--unread' : '');
            const avatarCls = 'dcmt-msg-list-avatar' + (unread > 0 ? ' dcmt-msg-list-avatar--unread' : '');
            const name = String(c.full_name || c.title || '').trim();
            const role = String(c.role_label || '').trim();
            const rolePart = role ? ' (' + role + ')' : '';
            const avatarSource = c.full_name || c.title;
            const nameRow = '<span class="dcmt-msg-list-title">' +
                '<span class="dcmt-msg-list-name">' + esc(name) + '</span>' +
                (rolePart ? '<span class="dcmt-msg-list-preview dcmt-msg-list-role">' + esc(rolePart) + '</span>' : '') +
                '</span>';
            return '<button type="button" class="' + itemCls + '" data-conv-id="' + c.id + '">' +
                '<span class="' + avatarCls + '">' + esc(initials(avatarSource)) + '</span>' +
                '<span class="dcmt-msg-list-body">' +
                '<span class="dcmt-msg-list-top">' + nameRow +
                '<span class="dcmt-msg-list-time">' + esc(c.last_message_at_display) + '</span></span>' +
                '<span class="dcmt-msg-list-preview">' + esc(c.preview) + '</span></span>' +
                unreadBadge +
                '</button>';
        }).join('');
        convList.querySelectorAll('[data-conv-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                openChat(parseInt(btn.getAttribute('data-conv-id'), 10));
            });
        });
    }

    function userDisplayName(u) {
        if (u && u.display_name) {
            return String(u.display_name);
        }
        const name = String(u && u.full_name ? u.full_name : '').trim();
        const role = String(u && (u.role_label || u.role) ? (u.role_label || u.role) : '').trim();
        if (!name) return role;
        if (!role) return name;
        return name + ' (' + role + ')';
    }

    function filterUsersClient(users, q) {
        if (!q) return users || [];
        const needle = q.toLowerCase();
        return (users || []).filter(function (u) {
            const name = String(u.full_name || '').toLowerCase();
            const display = userDisplayName(u).toLowerCase();
            const user = String(u.username || '').toLowerCase();
            const role = String(u.role_label || u.role || '').toLowerCase();
            return name.indexOf(needle) >= 0 || display.indexOf(needle) >= 0
                || user.indexOf(needle) >= 0 || role.indexOf(needle) >= 0;
        });
    }

    function renderUsers(users, q) {
        if (!userList) return;
        const filtered = filterUsersClient(users, q);
        if (!filtered.length) {
            userList.innerHTML = '<div class="dcmt-msg-empty">' + esc(q ? labels.noSearchResults : labels.pickSomeone) + '</div>';
            return;
        }
        users = filtered;
        userList.innerHTML = users.map(function (u) {
            const name = String(u.full_name || '').trim();
            const role = String(u.role_label || u.role || '').trim();
            const rolePart = role ? ' (' + role + ')' : '';
            return '<button type="button" class="dcmt-msg-list-item" data-user-id="' + u.id + '">' +
                '<span class="dcmt-msg-list-avatar">' + esc(initials(u.full_name)) + '</span>' +
                '<span class="dcmt-msg-list-body">' +
                '<span class="dcmt-msg-list-title">' +
                '<span class="dcmt-msg-list-name">' + esc(name) + '</span>' +
                (rolePart ? '<span class="dcmt-msg-list-preview dcmt-msg-list-role">' + esc(rolePart) + '</span>' : '') +
                '</span></span></button>';
        }).join('');
        userList.querySelectorAll('[data-user-id]').forEach(function (btn) {
            btn.addEventListener('click', function () {
                startChatWithUser(parseInt(btn.getAttribute('data-user-id'), 10));
            });
        });
    }

    function loadPicker() {
        const q = userSearch ? userSearch.value.trim() : '';
        const hasQuery = q.length > 0;

        apiGet('list_conversations', { q: q }).then(function (data) {
            if (data && data.success) {
                const convs = data.conversations || [];
                if (recentLabel) {
                    recentLabel.classList.toggle('d-none', hasQuery && convs.length === 0);
                }
                renderConversations(convs, q);
                setBadge(data.unread_total);
            }
        });

        apiGet('search_users', { q: q }).then(function (data) {
            if (!data || !data.success) return;
            const users = data.users || [];
            if (teamLabel) {
                teamLabel.classList.toggle('d-none', hasQuery && users.length === 0);
            }
            renderUsers(users, q);
        });
    }

    function startChatWithUser(userId) {
        if (userId <= 0) return;
        apiPost('start_conversation', { recipient_user_id: String(userId) }).then(function (data) {
            if (data && data.success && data.conversation_id) {
                openChat(data.conversation_id, data.conversation);
            } else {
                alert((data && data.message) ? data.message : labels.sendFailed);
            }
        });
    }

    function renderMessages(messages, append) {
        if (!chatMessages || !messages || !messages.length) return;
        const html = messages.map(function (m) {
            const mine = m.is_mine;
            const sys = m.message_type === 'appointment' || m.message_type === 'system';
            const rowCls = sys ? 'system' : (mine ? 'mine' : 'other');
            let inner = '';
            if (!mine && !sys && m.sender_name) {
                inner += '<div class="dcmt-msg-bubble-sender">' + esc(m.sender_name) + '</div>';
            }
            inner += esc(m.body).replace(/\n/g, '<br>');
            inner += '<div class="dcmt-msg-bubble-time">' + esc(m.created_at_display) + '</div>';
            return '<div class="dcmt-msg-bubble-row ' + rowCls + '"><div class="dcmt-msg-bubble">' + inner + '</div></div>';
        }).join('');
        if (append) {
            chatMessages.insertAdjacentHTML('beforeend', html);
            chatMessages.scrollTop = chatMessages.scrollHeight;
        } else {
            chatMessages.innerHTML = html;
            chatMessages.scrollTop = chatMessages.scrollHeight;
        }
        messages.forEach(function (m) {
            const id = parseInt(m.id, 10) || 0;
            if (id > lastMessageId) lastMessageId = id;
        });
    }

    function openChat(conversationId, header) {
        activeConversationId = conversationId;
        showChatView();
        if (chatMessages) chatMessages.innerHTML = '';
        lastMessageId = 0;

        apiGet('get_thread', { conversation_id: String(conversationId) }).then(function (data) {
            if (!data || !data.success) return;
            const conv = header || data.conversation || {};
            if (chatTitle) {
                chatTitle.textContent = conv.full_name || conv.title || '—';
            }
            renderMessages(data.messages || [], false);
            lastMessageId = parseInt(data.last_message_id, 10) || lastMessageId;
            setBadge(data.unread_total);
            startPoll();
        });
    }

    function pollActiveThreadNow() {
        if (!panelOpen || activeConversationId <= 0) return;
        apiGet('poll_thread', {
            conversation_id: String(activeConversationId),
            after_id: String(lastMessageId)
        }).then(function (data) {
            if (!data || !data.success) return;
            if (data.messages && data.messages.length) {
                renderMessages(data.messages, true);
            }
            if (typeof data.unread_total !== 'undefined') {
                setBadge(data.unread_total);
            }
        }).catch(function () {});
    }

    function startPoll() {
        stopPoll();
        pollActiveThreadNow();
        pollTimer = global.setInterval(function () {
            pollActiveThreadNow();
        }, CHAT_POLL_MS);
    }

    function stopPoll() {
        if (pollTimer) {
            global.clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function sendMessage(e) {
        if (e) e.preventDefault();
        const body = chatInput ? chatInput.value.trim() : '';
        if (!body || activeConversationId <= 0) return;

        apiPost('send', {
            conversation_id: String(activeConversationId),
            body: body
        }).then(function (data) {
            if (data && data.success && data.message) {
                if (chatInput) chatInput.value = '';
                renderMessages([data.message], true);
                setBadge(data.unread_total);
                pollActiveThreadNow();
            } else {
                alert((data && data.message) ? data.message : labels.sendFailed);
            }
        });
    }

    function init() {
        if (!fab || !apiUrl) return;

        fab.addEventListener('click', function (ev) {
            ev.stopPropagation();
            togglePanel();
        });
        if (panel) {
            panel.addEventListener('click', function (ev) {
                ev.stopPropagation();
            });
        }
        ['dcmtMsgCloseBtn', 'dcmtMsgCloseBtn2'].forEach(function (id) {
            const el = document.getElementById(id);
            if (el) el.addEventListener('click', closePanel);
        });
        const backBtn = document.getElementById('dcmtMsgBackBtn');
        if (backBtn) backBtn.addEventListener('click', function () {
            showPickerView();
            loadPicker();
        });

        if (userSearch) {
            let t;
            userSearch.addEventListener('input', function () {
                clearTimeout(t);
                t = setTimeout(loadPicker, 280);
            });
        }

        if (composerForm) composerForm.addEventListener('submit', sendMessage);
        if (chatInput) {
            chatInput.addEventListener('keydown', function (ev) {
                if (ev.key === 'Enter' && !ev.shiftKey) {
                    ev.preventDefault();
                    sendMessage(ev);
                }
            });
        }

        document.addEventListener('click', function (ev) {
            if (!panelOpen || !panel) return;
            const root = document.getElementById('dcmtMsgWidgetRoot');
            if (root && !root.contains(ev.target)) {
                closePanel();
            }
        });

        pollUnreadNow();
        scheduleUnreadPoll();
        bindMessagingVisibilityRefresh();
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', init);
    } else {
        init();
    }
})(window);
