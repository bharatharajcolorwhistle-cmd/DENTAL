<?php
/**
 * Lab Work Orders - Index
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';
if (!in_array($role, ['admin', 'doctor'], true) && !dcmt_is_admin()) {
    dcmt_show_message('Access denied.', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$lab_id = isset($_GET['lab_id']) ? (int) $_GET['lab_id'] : 0;
$open_chat_id = isset($_GET['chat']) ? (int) $_GET['chat'] : 0;

$where = [];
$params = [];
if ($search !== '') {
    $where[] = "(w.dcmt_folio_number LIKE ? OR w.dcmt_patient_name LIKE ? OR w.dcmt_doctor_name LIKE ? OR c.dcmt_lab_organization LIKE ? OR c.dcmt_name LIKE ? OR w.dcmt_prosthesis_type_name LIKE ?)";
    $like = '%' . $search . '%';
    $params = array_merge($params, [$like, $like, $like, $like, $like, $like]);
}
if ($lab_id > 0) {
    $where[] = 'w.dcmt_lab_connection_id = ?';
    $params[] = $lab_id;
}
$where_clause = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT w.*, c.dcmt_name AS lab_connection_name, c.dcmt_lab_remote_name, c.dcmt_lab_organization
        FROM dcmt_lab_work_orders w
        INNER JOIN dcmt_lab_connections c ON c.dcmt_id = w.dcmt_lab_connection_id
        $where_clause
        ORDER BY w.dcmt_created_at DESC
        LIMIT 500
    ");
    $stmt->execute($params);
    $orders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Lab work orders fetch error: ' . $e->getMessage());
    $orders = [];
}

$chat_unread = [];
if (!empty($orders)) {
    $order_ids = array_map(static function ($row) {
        return (int) ($row['dcmt_id'] ?? 0);
    }, $orders);
    $chat_unread = dcmt_lab_chat_unread_counts_by_order($dcmt_pdo, (int) ($user['dcmt_id'] ?? 0), $order_ids);
}

$labs = dcmt_lab_get_active_connections($dcmt_pdo);
try {
    $all_labs_stmt = $dcmt_pdo->query('SELECT dcmt_id, dcmt_name, dcmt_lab_organization, dcmt_lab_remote_name FROM dcmt_lab_connections ORDER BY COALESCE(NULLIF(TRIM(dcmt_lab_organization), \'\'), dcmt_name)');
    $all_labs = $all_labs_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    $all_labs = $labs;
}

$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<style>
.dcmt-wo-chat-btn {
    position: relative;
    color: #334155;
    overflow: visible !important;
}
.dcmt-wo-chat-btn:hover {
    color: #0f172a;
}
.dcmt-wo-chat-btn.has-unread {
    color: #0f766e;
}
.dcmt-wo-chat-dot {
    position: absolute;
    top: 0;
    right: 2px;
    width: 10px;
    height: 10px;
    border-radius: 50%;
    background: #ef4444;
    border: 2px solid #fff;
    box-shadow: 0 0 0 1px rgba(239, 68, 68, 0.35);
    pointer-events: none;
    animation: dcmt-wo-chat-dot-pulse 1.6s ease-in-out infinite;
}
@keyframes dcmt-wo-chat-dot-pulse {
    0%, 100% { transform: scale(1); opacity: 1; }
    50% { transform: scale(1.15); opacity: 0.85; }
}
.dcmt-wo-chat-root {
    position: fixed;
    right: 1.25rem;
    bottom: 1.25rem;
    z-index: 1090;
}
.dcmt-wo-chat-panel {
    width: min(400px, calc(100vw - 1.5rem));
    height: min(560px, calc(100vh - 2rem));
    display: flex;
    flex-direction: column;
    background: #fff;
    border-radius: 12px;
    box-shadow: 0 12px 40px rgba(15, 23, 42, 0.22);
    overflow: hidden;
}
.dcmt-wo-chat-panel .dcmt-msg-panel-header {
    background: linear-gradient(135deg, #0f766e 0%, #0d9488 100%);
}
.dcmt-wo-chat-panel .dcmt-msg-chat-messages {
    flex: 1;
    min-height: 0;
}
.dcmt-wo-chat-subtitle {
    font-size: 0.72rem;
    color: rgba(255, 255, 255, 0.88);
    margin: 0.1rem 0 0;
    line-height: 1.25;
}
.dcmt-wo-chat-error {
    margin: 0.5rem 0.75rem 0;
}
</style>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('lab', 'work_order_search_placeholder'); ?>">
            </div>
            <div class="col-md-3">
                <label for="lab_id" class="form-label"><?php echo trans('lab', 'select_lab'); ?></label>
                <select class="form-select dcmt-filter-field" id="lab_id" name="lab_id">
                    <option value=""><?php echo trans('common', 'all') ?: 'All'; ?></option>
                    <?php foreach ($all_labs as $lab): ?>
                        <option value="<?php echo (int) $lab['dcmt_id']; ?>" <?php echo $lab_id === (int) $lab['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars(dcmt_lab_connection_display_name($lab)); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="?" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <i class="fas fa-clipboard-list dcmt-view-card-title-icon"></i><?php echo trans('lab', 'lab_work_orders'); ?>
        </h6>
        <a href="add.php<?php echo $lab_id ? ('?lab_id=' . $lab_id) : ''; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'add_work_order'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($orders)): ?>
            <div class="text-center py-4">
                <i class="fas fa-clipboard-list fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('lab', 'no_work_orders_found'); ?></h5>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('lab', 'folio_number'); ?></th>
                            <th><?php echo trans('lab', 'select_lab'); ?></th>
                            <th><?php echo trans('lab', 'patient_name'); ?></th>
                            <th><?php echo trans('lab', 'doctor_name'); ?></th>
                            <th><?php echo trans('lab', 'prosthesis_type'); ?></th>
                            <th><?php echo trans('lab', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($orders as $order): ?>
                            <?php
                            $oid = (int) $order['dcmt_id'];
                            $has_remote = trim((string) ($order['dcmt_remote_work_order_id'] ?? '')) !== '';
                            $unread = (int) ($chat_unread[$oid] ?? 0);
                            ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($order['dcmt_folio_number'] ?: '—'); ?></strong></td>
                                <td><?php echo htmlspecialchars(dcmt_lab_connection_display_name($order)); ?></td>
                                <td><?php echo htmlspecialchars($order['dcmt_patient_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['dcmt_doctor_name']); ?></td>
                                <td><?php echo htmlspecialchars($order['dcmt_prosthesis_type_name'] ?: $order['dcmt_prosthesis_type_id']); ?></td>
                                <td><?php echo dcmt_format_date($order['dcmt_created_at'], DCMT_DATETIME_FORMAT); ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $oid; ?>" class="btn"
                                           title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <?php if ($has_remote): ?>
                                            <button type="button"
                                                    class="btn dcmt-wo-chat-btn<?php echo $unread > 0 ? ' has-unread' : ''; ?>"
                                                    data-wo-chat-open
                                                    data-order-id="<?php echo $oid; ?>"
                                                    data-folio="<?php echo htmlspecialchars($order['dcmt_folio_number'] ?: '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-patient="<?php echo htmlspecialchars($order['dcmt_patient_name'] ?: '', ENT_QUOTES, 'UTF-8'); ?>"
                                                    data-lab="<?php echo htmlspecialchars(dcmt_lab_connection_display_name($order), ENT_QUOTES, 'UTF-8'); ?>"
                                                    title="<?php echo htmlspecialchars(trans('lab', 'chat_with_lab')); ?>">
                                                <i class="fas fa-comments" aria-hidden="true"></i>
                                                <span class="dcmt-wo-chat-dot<?php echo $unread > 0 ? '' : ' d-none'; ?>"
                                                      data-wo-chat-dot
                                                      aria-hidden="true"></span>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<div id="dcmtWoChatRoot" class="dcmt-wo-chat-root d-none" aria-live="polite">
    <div class="dcmt-wo-chat-panel" role="dialog" aria-label="<?php echo htmlspecialchars(trans('lab', 'work_order_chat')); ?>">
        <header class="dcmt-msg-panel-header">
            <div class="dcmt-msg-chat-head-text">
                <h2 class="dcmt-msg-panel-title mb-0" id="dcmtWoChatTitle"><?php echo htmlspecialchars(trans('lab', 'work_order_chat')); ?></h2>
                <p class="dcmt-wo-chat-subtitle mb-0" id="dcmtWoChatSubtitle"></p>
            </div>
            <button type="button" class="dcmt-msg-icon-btn" id="dcmtWoChatCloseBtn" title="<?php echo htmlspecialchars(trans('lab', 'chat_close')); ?>">
                <i class="fas fa-times"></i>
            </button>
        </header>
        <div class="alert alert-danger py-2 px-3 small d-none dcmt-wo-chat-error" id="dcmtWoChatError" role="alert"></div>
        <div class="dcmt-msg-chat-messages dcmt-msg-scroll" id="dcmtWoChatMessages">
            <div class="dcmt-msg-empty"><?php echo htmlspecialchars(trans('lab', 'chat_empty')); ?></div>
        </div>
        <form class="dcmt-msg-chat-composer" id="dcmtWoChatForm" autocomplete="off">
            <textarea id="dcmtWoChatInput"
                      rows="1"
                      placeholder="<?php echo htmlspecialchars(trans('lab', 'chat_placeholder')); ?>"
                      maxlength="4000"></textarea>
            <button type="submit" class="dcmt-msg-send-btn" id="dcmtWoChatSendBtn" title="<?php echo htmlspecialchars(trans('lab', 'chat_send')); ?>">
                <i class="fas fa-paper-plane"></i>
            </button>
        </form>
    </div>
</div>

<script>
(function () {
    'use strict';

    const csrfToken = <?php echo json_encode($csrf_token); ?>;
    const openChatId = <?php echo (int) $open_chat_id; ?>;
    const labels = {
        chatTitle: <?php echo json_encode(trans('lab', 'work_order_chat')); ?>,
        empty: <?php echo json_encode(trans('lab', 'chat_empty')); ?>,
        loadFailed: <?php echo json_encode(trans('lab', 'chat_load_failed')); ?>,
        sendFailed: <?php echo json_encode(trans('lab', 'chat_send_failed')); ?>,
        emptyMessage: <?php echo json_encode(trans('lab', 'chat_empty_message')); ?>
    };

    const root = document.getElementById('dcmtWoChatRoot');
    const titleEl = document.getElementById('dcmtWoChatTitle');
    const subtitleEl = document.getElementById('dcmtWoChatSubtitle');
    const messagesEl = document.getElementById('dcmtWoChatMessages');
    const errorEl = document.getElementById('dcmtWoChatError');
    const formEl = document.getElementById('dcmtWoChatForm');
    const inputEl = document.getElementById('dcmtWoChatInput');
    const sendBtn = document.getElementById('dcmtWoChatSendBtn');
    const closeBtn = document.getElementById('dcmtWoChatCloseBtn');

    let activeOrderId = 0;
    let pollTimer = null;
    let unreadPollTimer = null;
    let knownMessageIds = new Set();
    const POLL_MS = 8000;
    const UNREAD_POLL_MS = 12000;

    function esc(s) {
        return String(s || '').replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]);
        });
    }

    function showError(message) {
        if (!errorEl) return;
        if (message) {
            errorEl.textContent = message;
            errorEl.classList.remove('d-none');
        } else {
            errorEl.textContent = '';
            errorEl.classList.add('d-none');
        }
    }

    function setLoading(isLoading) {
        if (sendBtn) sendBtn.disabled = !!isLoading;
        if (inputEl) inputEl.disabled = !!isLoading;
    }

    function setChatDot(orderId, hasUnread) {
        const btn = document.querySelector('[data-wo-chat-open][data-order-id="' + orderId + '"]');
        if (!btn) return;
        // Never show unread while this chat is open
        const show = !!hasUnread && activeOrderId !== orderId;
        const dot = btn.querySelector('[data-wo-chat-dot]');
        if (dot) {
            dot.classList.toggle('d-none', !show);
        }
        btn.classList.toggle('has-unread', show);
    }

    function applyUnreadMap(unreadMap) {
        const map = unreadMap || {};
        document.querySelectorAll('[data-wo-chat-open]').forEach(function (btn) {
            const orderId = parseInt(btn.getAttribute('data-order-id'), 10) || 0;
            if (orderId <= 0) return;
            const count = parseInt(map[String(orderId)], 10) || 0;
            setChatDot(orderId, count > 0);
        });
    }

    function collectVisibleOrderIds() {
        const ids = [];
        document.querySelectorAll('[data-wo-chat-open]').forEach(function (btn) {
            const orderId = parseInt(btn.getAttribute('data-order-id'), 10) || 0;
            if (orderId > 0) ids.push(orderId);
        });
        return ids;
    }

    function pollUnreadDots() {
        const ids = collectVisibleOrderIds();
        if (!ids.length) return;
        fetch('messages_ajax.php?action=unread&ids=' + encodeURIComponent(ids.join(',')), {
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (data) {
            if (data && data.success) {
                applyUnreadMap(data.unread || {});
            }
        }).catch(function () {});
    }

    function startUnreadPoll() {
        if (unreadPollTimer) return;
        pollUnreadDots();
        unreadPollTimer = setInterval(function () {
            if (document.visibilityState === 'hidden') return;
            pollUnreadDots();
        }, UNREAD_POLL_MS);
    }

    function renderMessages(messages, append) {
        if (!messagesEl) return;
        const list = Array.isArray(messages) ? messages : [];
        if (!append) {
            knownMessageIds = new Set();
            messagesEl.innerHTML = '';
        }

        const fresh = list.filter(function (m) {
            const id = String(m.id || '');
            if (!id || knownMessageIds.has(id)) return false;
            knownMessageIds.add(id);
            return true;
        });

        if (!append && fresh.length === 0) {
            messagesEl.innerHTML = '<div class="dcmt-msg-empty">' + esc(labels.empty) + '</div>';
            return;
        }

        if (fresh.length === 0) return;

        const empty = messagesEl.querySelector('.dcmt-msg-empty');
        if (empty) empty.remove();

        const html = fresh.map(function (m) {
            const mine = !!m.is_mine;
            const rowCls = mine ? 'mine' : 'other';
            let inner = '';
            if (!mine && m.sender_name) {
                inner += '<div class="dcmt-msg-bubble-sender">' + esc(m.sender_name) + '</div>';
            }
            inner += esc(m.content || '').replace(/\n/g, '<br>');
            inner += '<div class="dcmt-msg-bubble-time">' + esc(m.created_at_display || '') + '</div>';
            return '<div class="dcmt-msg-bubble-row ' + rowCls + '" data-message-id="' + esc(m.id) + '">'
                + '<div class="dcmt-msg-bubble">' + inner + '</div></div>';
        }).join('');

        messagesEl.insertAdjacentHTML('beforeend', html);
        messagesEl.scrollTop = messagesEl.scrollHeight;
    }

    function clearChatBadge(orderId) {
        setChatDot(orderId, false);
    }

    function stopPoll() {
        if (pollTimer) {
            clearInterval(pollTimer);
            pollTimer = null;
        }
    }

    function startPoll() {
        stopPoll();
        pollTimer = setInterval(function () {
            if (activeOrderId > 0 && root && !root.classList.contains('d-none')) {
                loadMessages(activeOrderId, true);
            }
        }, POLL_MS);
    }

    function loadMessages(orderId, silent) {
        return fetch('messages_ajax.php?action=list&id=' + encodeURIComponent(String(orderId)), {
            credentials: 'same-origin'
        }).then(function (r) {
            return r.json();
        }).then(function (data) {
            if (!data || !data.success) {
                if (!silent) {
                    showError((data && data.message) ? data.message : labels.loadFailed);
                }
                return;
            }
            showError('');
            if (data.conversation && data.conversation.name && titleEl) {
                titleEl.textContent = data.conversation.name;
            }
            if (data.conversation && subtitleEl) {
                const names = data.conversation.participant_names
                    || formatParticipantNames(data.conversation.participants);
                subtitleEl.textContent = names || '';
            }
            renderMessages(data.messages || [], !!silent);
            clearChatBadge(orderId);
        }).catch(function () {
            if (!silent) showError(labels.loadFailed);
        });
    }

    function formatParticipantNames(participants) {
        if (!Array.isArray(participants) || participants.length === 0) {
            return '';
        }
        const names = [];
        participants.forEach(function (p) {
            if (!p) return;
            const first = (p.firstName || '').trim();
            const last = (p.lastName || '').trim();
            const name = (first + ' ' + last).trim();
            if (name && names.indexOf(name) === -1) {
                names.push(name);
            }
        });
        return names.join(', ');
    }

    function openChat(orderId, meta) {
        activeOrderId = orderId;
        clearChatBadge(orderId);
        if (root) root.classList.remove('d-none');
        if (titleEl) {
            titleEl.textContent = (meta && meta.folio)
                ? (labels.chatTitle + ' — ' + meta.folio)
                : labels.chatTitle;
        }
        if (subtitleEl) {
            subtitleEl.textContent = '';
        }
        showError('');
        if (messagesEl) {
            messagesEl.innerHTML = '<div class="dcmt-msg-empty"><i class="fas fa-spinner fa-spin me-1"></i></div>';
        }
        if (inputEl) {
            inputEl.value = '';
            inputEl.focus();
        }
        loadMessages(orderId, false).then(function () {
            startPoll();
        });
    }

    function closeChat() {
        stopPoll();
        activeOrderId = 0;
        knownMessageIds = new Set();
        if (root) root.classList.add('d-none');
        showError('');
        pollUnreadDots();
    }

    document.querySelectorAll('[data-wo-chat-open]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const orderId = parseInt(btn.getAttribute('data-order-id'), 10) || 0;
            if (orderId <= 0) return;
            openChat(orderId, {
                folio: btn.getAttribute('data-folio') || '',
                patient: btn.getAttribute('data-patient') || '',
                lab: btn.getAttribute('data-lab') || ''
            });
        });
    });

    if (closeBtn) {
        closeBtn.addEventListener('click', closeChat);
    }

    document.addEventListener('keydown', function (e) {
        if (e.key === 'Escape' && root && !root.classList.contains('d-none')) {
            closeChat();
        }
    });

    if (formEl) {
        formEl.addEventListener('submit', function (e) {
            e.preventDefault();
            const content = inputEl ? inputEl.value.trim() : '';
            if (!content) {
                showError(labels.emptyMessage);
                return;
            }
            if (activeOrderId <= 0) return;

            const body = new URLSearchParams();
            body.set('action', 'send');
            body.set('id', String(activeOrderId));
            body.set('content', content);
            body.set('csrf_token', csrfToken);

            setLoading(true);
            showError('');
            fetch('messages_ajax.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded; charset=UTF-8' },
                body: body.toString(),
                credentials: 'same-origin'
            }).then(function (r) {
                return r.json();
            }).then(function (data) {
                if (!data || !data.success) {
                    showError((data && data.message) ? data.message : labels.sendFailed);
                    return;
                }
                if (inputEl) inputEl.value = '';
                if (data.message) {
                    renderMessages([data.message], true);
                } else {
                    loadMessages(activeOrderId, true);
                }
            }).catch(function () {
                showError(labels.sendFailed);
            }).finally(function () {
                setLoading(false);
                if (inputEl) inputEl.focus();
            });
        });
    }

    if (inputEl) {
        inputEl.addEventListener('keydown', function (e) {
            if (e.key === 'Enter' && !e.shiftKey) {
                e.preventDefault();
                if (formEl) formEl.requestSubmit();
            }
        });
    }

    startUnreadPoll();

    if (openChatId > 0) {
        const autoBtn = document.querySelector('[data-wo-chat-open][data-order-id="' + openChatId + '"]');
        if (autoBtn) {
            autoBtn.click();
        }
    }
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
