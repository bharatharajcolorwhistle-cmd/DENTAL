<?php
/**
 * Global floating messaging widget (all authenticated pages)
 */

if (!isset($current_user) || !is_array($current_user)) {
    $current_user = dcmt_get_current_user();
}

if (!function_exists('dcmt_messaging_user_can_access')) {
    require_once __DIR__ . '/messaging_functions.php';
}

if (!$current_user || !dcmt_messaging_user_can_access($current_user)) {
    return;
}

if (!isset($base_path)) {
    if (!function_exists('get_base_path')) {
        function get_base_path() {
            $dir = dirname($_SERVER['PHP_SELF'] ?? '');
            if (strpos($dir, '/pages/') !== false) {
                return '../../';
            }
            if (strpos($dir, '/auth/') !== false) {
                return '../';
            }
            return './';
        }
    }
    $base_path = get_base_path();
}

$dcmt_msg_csrf = dcmt_generate_csrf_token();
?>
<div id="dcmtMsgWidgetRoot" class="dcmt-msg-widget-root" aria-live="polite">
    <button type="button"
            class="dcmt-msg-fab"
            id="dcmtMsgFab"
            title="<?php echo htmlspecialchars(trans('messaging', 'messages')); ?>"
            aria-expanded="false"
            aria-controls="dcmtMsgPanel">
        <i class="fas fa-comments" aria-hidden="true"></i>
        <span class="dcmt-msg-fab-badge d-none" id="dcmtMsgFabBadge">0</span>
    </button>

    <div class="dcmt-msg-panel d-none" id="dcmtMsgPanel" role="dialog" aria-label="<?php echo htmlspecialchars(trans('messaging', 'messages')); ?>">
        <div class="dcmt-msg-panel-view" id="dcmtMsgViewPicker">
            <header class="dcmt-msg-panel-header">
                <div class="dcmt-msg-panel-head-text">
                    <h2 class="dcmt-msg-panel-title mb-0"><?php echo trans('messaging', 'messages'); ?></h2>
                    <p class="dcmt-msg-chat-head-help mb-0"><?php echo htmlspecialchars(trans('messaging', 'chat_retention_notice')); ?></p>
                </div>
                <button type="button" class="dcmt-msg-icon-btn" id="dcmtMsgCloseBtn" title="<?php echo htmlspecialchars(trans('messaging', 'close')); ?>">
                    <i class="fas fa-times"></i>
                </button>
            </header>
            <div class="dcmt-msg-panel-search">
                <i class="fas fa-search"></i>
                <input type="search"
                       id="dcmtMsgUserSearch"
                       placeholder="<?php echo htmlspecialchars(trans('messaging', 'search_staff')); ?>"
                       autocomplete="off">
            </div>
            <div class="dcmt-msg-panel-body dcmt-msg-scroll">
                <div class="dcmt-msg-section-label" id="dcmtMsgRecentLabel"><?php echo trans('messaging', 'recent_chats'); ?></div>
                <div class="dcmt-msg-conv-list" id="dcmtMsgConvList"></div>
                <div class="dcmt-msg-section-label mt-2" id="dcmtMsgTeamLabel"><?php echo trans('messaging', 'team_members'); ?></div>
                <div class="dcmt-msg-user-list" id="dcmtMsgUserList"></div>
            </div>
        </div>

        <div class="dcmt-msg-panel-view d-none" id="dcmtMsgViewChat">
            <header class="dcmt-msg-panel-header">
                <button type="button" class="dcmt-msg-icon-btn" id="dcmtMsgBackBtn" title="<?php echo htmlspecialchars(trans('messaging', 'back')); ?>">
                    <i class="fas fa-arrow-left"></i>
                </button>
                <div class="dcmt-msg-chat-head-text">
                    <h2 class="dcmt-msg-panel-title mb-0" id="dcmtMsgChatTitle">—</h2>
                    <p class="dcmt-msg-chat-head-help mb-0"><?php echo htmlspecialchars(trans('messaging', 'chat_retention_notice')); ?></p>
                </div>
                <button type="button" class="dcmt-msg-icon-btn" id="dcmtMsgCloseBtn2" title="<?php echo htmlspecialchars(trans('messaging', 'close')); ?>">
                    <i class="fas fa-times"></i>
                </button>
            </header>
            <div class="dcmt-msg-chat-messages dcmt-msg-scroll" id="dcmtMsgChatMessages"></div>
            <form class="dcmt-msg-chat-composer" id="dcmtMsgComposerForm">
                <textarea id="dcmtMsgChatInput"
                          rows="1"
                          placeholder="<?php echo htmlspecialchars(trans('messaging', 'type_message')); ?>"
                          maxlength="4000"></textarea>
                <button type="submit" class="dcmt-msg-send-btn" title="<?php echo htmlspecialchars(trans('messaging', 'send')); ?>">
                    <i class="fas fa-paper-plane"></i>
                </button>
            </form>
        </div>
    </div>
</div>
<script>
window.dcmtMessagingWidget = {
    basePath: <?php echo json_encode($base_path); ?>,
    apiUrl: <?php echo json_encode($base_path . 'pages/messaging/api.php'); ?>,
    csrfToken: <?php echo json_encode($dcmt_msg_csrf); ?>,
    soundUrl: <?php echo json_encode($base_path . 'assets/audio/messaging-notification.mp3'); ?>,
    labels: {
        noChats: <?php echo json_encode(trans('messaging', 'no_chats')); ?>,
        noSearchResults: <?php echo json_encode(trans('messaging', 'no_search_results')); ?>,
        pickSomeone: <?php echo json_encode(trans('messaging', 'pick_someone')); ?>,
        sendFailed: <?php echo json_encode(trans('messaging', 'send_failed')); ?>
    }
};
</script>
<script src="<?php echo dcmt_asset('assets/js/dcmt-messaging-widget.js', $base_path); ?>"></script>
