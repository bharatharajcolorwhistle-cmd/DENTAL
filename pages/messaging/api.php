<?php
/**
 * Unified messaging API for global chat widget
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/messaging_functions.php';

header('Content-Type: application/json; charset=utf-8');

if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

$user_id = (int) ($dcmt_current_user['dcmt_id'] ?? 0);
if (!dcmt_messaging_user_can_access($dcmt_current_user)) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('messaging', 'access_denied')]);
    exit();
}

$action = trim((string) ($_GET['action'] ?? $_POST['action'] ?? ''));

try {
    switch ($action) {
        case 'list_conversations':
            $search = trim((string) ($_GET['q'] ?? ''));
            echo json_encode([
                'success' => true,
                'conversations' => dcmt_messaging_list_conversations($dcmt_pdo, $user_id, $search),
                'unread_total' => dcmt_messaging_count_unread_total($dcmt_pdo, $user_id),
            ]);
            break;

        case 'search_users':
            $user_q = trim((string) ($_GET['q'] ?? ''));
            $user_limit = $user_q !== '' ? 50 : 25;
            echo json_encode([
                'success' => true,
                'users' => dcmt_messaging_search_users($dcmt_pdo, $dcmt_current_user, $user_q, $user_limit),
            ]);
            break;

        case 'start_conversation':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
                break;
            }
            $recipient = (int) ($_POST['recipient_user_id'] ?? 0);
            $result = dcmt_messaging_get_or_create_direct($dcmt_pdo, $dcmt_current_user, $recipient);
            if (empty($result['success'])) {
                echo json_encode(['success' => false, 'message' => implode(' ', $result['errors'] ?? [])]);
                break;
            }
            $cid = (int) $result['conversation_id'];
            echo json_encode([
                'success' => true,
                'conversation_id' => $cid,
                'conversation' => dcmt_messaging_get_conversation_header($dcmt_pdo, $cid, $user_id),
            ]);
            break;

        case 'get_thread':
            $cid = (int) ($_GET['conversation_id'] ?? 0);
            $before = (int) ($_GET['before_id'] ?? 0);
            $header = dcmt_messaging_get_conversation_header($dcmt_pdo, $cid, $user_id);
            if (!$header) {
                http_response_code(403);
                echo json_encode(['success' => false, 'message' => trans('messaging', 'access_denied')]);
                break;
            }
            $messages = dcmt_messaging_get_thread_messages($dcmt_pdo, $cid, $user_id, $before);
            dcmt_messaging_mark_read($dcmt_pdo, $cid, $user_id);
            $last_id = 0;
            if ($messages !== []) {
                $last_id = (int) $messages[count($messages) - 1]['id'];
            }
            echo json_encode([
                'success' => true,
                'conversation' => $header,
                'messages' => $messages,
                'last_message_id' => $last_id,
                'unread_total' => dcmt_messaging_count_unread_total($dcmt_pdo, $user_id),
            ]);
            break;

        case 'poll_thread':
            $cid = (int) ($_GET['conversation_id'] ?? 0);
            $after = (int) ($_GET['after_id'] ?? 0);
            $messages = dcmt_messaging_poll_new_messages($dcmt_pdo, $cid, $user_id, $after);
            if ($messages !== []) {
                dcmt_messaging_mark_read($dcmt_pdo, $cid, $user_id);
            }
            $last_id = $after;
            if ($messages !== []) {
                $last_id = (int) $messages[count($messages) - 1]['id'];
            }
            echo json_encode([
                'success' => true,
                'messages' => $messages,
                'last_message_id' => $last_id,
                'unread_total' => dcmt_messaging_count_unread_total($dcmt_pdo, $user_id),
            ]);
            break;

        case 'send':
            if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
                echo json_encode(['success' => false, 'message' => trans('common', 'invalid_token')]);
                break;
            }
            $cid = (int) ($_POST['conversation_id'] ?? 0);
            $recipient = (int) ($_POST['recipient_user_id'] ?? 0);
            if ($cid <= 0 && $recipient > 0) {
                $created = dcmt_messaging_get_or_create_direct($dcmt_pdo, $dcmt_current_user, $recipient);
                if (empty($created['success'])) {
                    echo json_encode(['success' => false, 'message' => implode(' ', $created['errors'] ?? [])]);
                    break;
                }
                $cid = (int) $created['conversation_id'];
            }
            $priority = ($_POST['priority'] ?? '') === 'high' ? 'high' : 'normal';
            $result = dcmt_messaging_send_message(
                $dcmt_pdo,
                $dcmt_current_user,
                $cid,
                (string) ($_POST['body'] ?? ''),
                $priority
            );
            if (empty($result['success'])) {
                echo json_encode(['success' => false, 'message' => implode(' ', $result['errors'] ?? [])]);
                break;
            }
            echo json_encode([
                'success' => true,
                'message' => $result['message'],
                'conversation_id' => $cid,
                'conversation' => dcmt_messaging_get_conversation_header($dcmt_pdo, $cid, $user_id),
                'unread_total' => dcmt_messaging_count_unread_total($dcmt_pdo, $user_id),
            ]);
            break;

        case 'unread_count':
            echo json_encode([
                'success' => true,
                'count' => dcmt_messaging_count_unread_total($dcmt_pdo, $user_id),
            ]);
            break;

        default:
            echo json_encode(['success' => false, 'message' => 'Invalid action']);
    }
} catch (PDOException $e) {
    error_log('messaging api: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('messaging', 'database_error')]);
}
