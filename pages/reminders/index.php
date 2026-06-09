<?php
/**
 * Reminders List Page
 * Dental Clinic Management System
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

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$status_filter = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';
$assigned_filter = isset($_GET['assigned_user_id']) ? (int) $_GET['assigned_user_id'] : 0;

$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];

list($scope_sql, $scope_params) = dcmt_reminder_list_scope_sql($dcmt_current_user, 'r');
$where_conditions[] = $scope_sql;
$params = array_merge($params, $scope_params);

if ($search !== '') {
    $where_conditions[] = '(r.dcmt_title LIKE ? OR r.dcmt_description LIKE ?)';
    $params[] = "%{$search}%";
    $params[] = "%{$search}%";
}

if (in_array($status_filter, ['pending', 'completed', 'cancelled'], true)) {
    $where_conditions[] = 'r.dcmt_status = ?';
    $params[] = $status_filter;
}

if ($assigned_filter > 0 && dcmt_reminder_user_is_admin($dcmt_current_user)) {
    $where_conditions[] = 'r.dcmt_assigned_user_id = ?';
    $params[] = $assigned_filter;
}

$where_clause = 'WHERE ' . implode(' AND ', $where_conditions);

$total_records = 0;
$total_pages = 0;
$reminders = [];

try {
    $count_sql = "
        SELECT COUNT(*)
        FROM dcmt_reminders r
        {$where_clause}
    ";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = (int) ceil($total_records / $per_page);

    $list_sql = "
        SELECT r.*,
               u.dcmt_full_name AS assigned_user_name,
               c.dcmt_full_name AS created_by_name
        FROM dcmt_reminders r
        LEFT JOIN dcmt_users u ON u.dcmt_id = r.dcmt_assigned_user_id
        LEFT JOIN dcmt_users c ON c.dcmt_id = r.dcmt_created_by_user_id
        {$where_clause}
        ORDER BY r.dcmt_reminder_at ASC
        LIMIT ? OFFSET ?
    ";
    $list_params = $params;
    $list_params[] = $per_page;
    $list_params[] = $offset;

    $stmt = $dcmt_pdo->prepare($list_sql);
    $stmt->execute($list_params);
    $reminders = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Reminders list error: ' . $e->getMessage());
    dcmt_show_message(trans('reminder', 'database_error'), 'danger');
}

$assignable_users = [];
try {
    $assignable_users = dcmt_reminder_get_assignable_users($dcmt_pdo);
} catch (PDOException $e) {
    error_log('Reminders filter data error: ' . $e->getMessage());
}

$dcmt_is_admin = dcmt_reminder_user_is_admin($dcmt_current_user);
$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<link href="../../assets/css/select2.min.css" rel="stylesheet">
<meta name="csrf-token" content="<?php echo $csrf_token; ?>">

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-2">
                <label for="status" class="form-label"><?php echo trans('reminder', 'filter_status'); ?></label>
                <select class="form-select dcmt-filter-field" id="status" name="status">
                    <option value=""><?php echo trans('reminder', 'all_statuses'); ?></option>
                    <option value="pending" <?php echo $status_filter === 'pending' ? 'selected' : ''; ?>><?php echo trans('reminder', 'status_pending'); ?></option>
                    <option value="completed" <?php echo $status_filter === 'completed' ? 'selected' : ''; ?>><?php echo trans('reminder', 'status_completed'); ?></option>
                    <option value="cancelled" <?php echo $status_filter === 'cancelled' ? 'selected' : ''; ?>><?php echo trans('reminder', 'status_cancelled'); ?></option>
                </select>
            </div>
            <?php if ($dcmt_is_admin): ?>
            <div class="col-md-3">
                <label for="assigned_user_id" class="form-label"><?php echo trans('reminder', 'assigned_to'); ?></label>
                <select class="form-select dcmt-filter-field" id="assigned_user_id" name="assigned_user_id">
                    <option value=""><?php echo trans('reminder', 'all_assignees'); ?></option>
                    <?php foreach ($assignable_users as $u): ?>
                        <option value="<?php echo (int) $u['dcmt_id']; ?>" <?php echo $assigned_filter === (int) $u['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($u['dcmt_full_name'] ?: $u['dcmt_username']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <?php endif; ?>
            <div class="col-md-<?php echo $dcmt_is_admin ? '4' : '7'; ?>">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('reminder', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="index.php" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('reminder', 'reminders'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('reminder', 'showing'); ?>:
                        <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                        <?php echo trans('reminder', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link">
                    <?php echo trans('reminder', 'add_reminder'); ?>
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="border py-2 px-3 small mb-3">
            <div class="d-flex gap-2 mb-2">
                <i class="fas fa-search text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                <div><?php echo trans('reminder', 'index_help_search'); ?></div>
            </div>
            <div class="d-flex gap-2 mb-0">
                <i class="fas fa-bell text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                <div><?php echo trans('reminder', 'index_help_actions'); ?></div>
            </div>
        </div>

        <?php if (empty($reminders)): ?>
            <div class="text-center py-4">
                <i class="fas fa-bell fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('reminder', 'no_reminders_found'); ?></h5>
                <p class="text-muted"><?php echo trans('reminder', 'start_adding_reminder'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo trans('reminder', 'reminder_datetime'); ?></th>
                            <th><?php echo trans('reminder', 'title'); ?></th>
                            <th><?php echo trans('reminder', 'assigned_to'); ?></th>
                            <th><?php echo trans('reminder', 'filter_status'); ?></th>
                            <th><?php echo trans('reminder', 'notify_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($reminders as $row): ?>
                            <?php
                            $status = $row['dcmt_status'] ?? 'pending';
                            $status_safe = in_array($status, ['pending', 'completed', 'cancelled'], true) ? $status : 'pending';
                            $can_manage = dcmt_reminder_user_can_manage($row, $dcmt_current_user);
                            $title_esc = htmlspecialchars($row['dcmt_title'] ?? '', ENT_QUOTES);
                            ?>
                            <tr>
                                <td>
                                    <span title="<?php echo htmlspecialchars($row['dcmt_reminder_at'] ?? ''); ?>">
                                        <?php echo dcmt_reminder_format_datetime_display($row['dcmt_reminder_at'] ?? ''); ?>
                                    </span>
                                </td>
                                <td>
                                    <strong><?php echo htmlspecialchars($row['dcmt_title'] ?? ''); ?></strong>
                                    <?php if (!empty($row['dcmt_notification_sent']) && empty($row['dcmt_notification_dismissed']) && $status_safe === 'pending'): ?>
                                        <span class="badge bg-info ms-1"><?php echo trans('reminder', 'advance_hours_label'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($row['assigned_user_name'] ?? '-'); ?></td>
                                <td>
                                    <span class="text-<?php echo $status_safe === 'completed' ? 'success' : ($status_safe === 'cancelled' ? 'secondary' : 'warning'); ?>">
                                        <?php echo trans('reminder', 'status_' . $status_safe); ?>
                                    </span>
                                </td>
                                <td>
                                    <span title="<?php echo htmlspecialchars($row['dcmt_notify_at'] ?? ''); ?>">
                                        <?php echo dcmt_reminder_format_datetime_display($row['dcmt_notify_at'] ?? ''); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo (int) $row['dcmt_id']; ?>" class="btn"
                                           title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <?php if ($can_manage): ?>
                                            <a href="edit.php?id=<?php echo (int) $row['dcmt_id']; ?>" class="btn"
                                               title="<?php echo trans('common', 'edit'); ?>">
                                                <img src="../../assets/images/edit.svg" alt="Edit">
                                            </a>
                                            <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                    onclick="dcmtConfirmDeleteReminder(<?php echo (int) $row['dcmt_id']; ?>, '<?php echo $title_esc; ?>')">
                                                <i class="fas fa-trash text-danger"></i>
                                            </button>
                                            <?php if ($status_safe === 'pending'): ?>
                                                <button type="button" class="btn"
                                                        title="<?php echo trans('reminder', 'mark_complete'); ?>"
                                                        onclick="dcmtCompleteReminder(<?php echo (int) $row['dcmt_id']; ?>)">
                                                    <i class="fas fa-check text-success"></i>
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('reminder', 'reminders'); ?> pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => 1]))); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                    <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page - 1]))); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                    <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $i]))); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $page + 1]))); ?>" title="<?php echo trans('common', 'next'); ?>">
                                    <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo htmlspecialchars(http_build_query(array_merge($_GET, ['page' => $total_pages]))); ?>" title="<?php echo trans('common', 'last_page'); ?>">
                                    <?php echo trans('common', 'last_page'); ?> <i class="fas fa-angle-double-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script src="../../assets/js/select2.min.js"></script>
<script>
const dcmtReminderTranslations = {
    confirm_deletion: <?php echo json_encode(trans('common', 'confirm_deletion')); ?>,
    warning: <?php echo json_encode(trans('common', 'warning')); ?>,
    delete_confirmation_message: <?php echo json_encode(trans('reminder', 'delete_confirmation_message')); ?>,
    cancel: <?php echo json_encode(trans('common', 'cancel')); ?>,
    yes_delete: <?php echo json_encode(trans('common', 'yes_delete') ?: trans('common', 'delete')); ?>,
    confirm_complete: <?php echo json_encode(trans('reminder', 'confirm_complete')); ?>
};
const dcmtReminderCsrf = <?php echo json_encode($csrf_token); ?>;
let dcmtCurrentReminderId = null;

document.addEventListener('DOMContentLoaded', function() {
    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('#assigned_user_id').select2({
            width: '100%',
            minimumResultsForSearch: 0
        });
        $('#assigned_user_id').next('.select2-container').addClass('dcmt-filter-select2');

        $(document).on('select2:open', function() {
            const input = document.querySelector('.select2-container--open .select2-search__field');
            if (input) input.focus();
        });
    }
});

function dcmtConfirmDeleteReminder(reminderId, reminderTitle) {
    dcmtCurrentReminderId = reminderId;
    const existingModal = document.getElementById('dcmtReminderDeleteModal');
    if (existingModal) existingModal.remove();

    const modalHtml = `
        <div class="modal fade" id="dcmtReminderDeleteModal" tabindex="-1" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i>${dcmtReminderTranslations.confirm_deletion}</h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0">
                            <h6 class="alert-heading"><i class="fas fa-exclamation-triangle"></i> ${dcmtReminderTranslations.warning}!</h6>
                            <p class="mb-0">${dcmtReminderTranslations.delete_confirmation_message}</p>
                        </div>
                        <p class="fw-semibold mt-3 mb-0">${reminderTitle || ''}</p>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">${dcmtReminderTranslations.cancel}</button>
                        <button type="button" class="btn btn-danger" id="dcmtConfirmReminderDeleteBtn">
                            <i class="fas fa-trash me-1"></i>${dcmtReminderTranslations.yes_delete}
                        </button>
                    </div>
                </div>
            </div>
        </div>`;
    document.body.insertAdjacentHTML('beforeend', modalHtml);
    const modal = new bootstrap.Modal(document.getElementById('dcmtReminderDeleteModal'));
    document.getElementById('dcmtConfirmReminderDeleteBtn').addEventListener('click', function() {
        dcmtDeleteReminder(modal);
    });
    modal.show();
}

function dcmtNotifyReminderHeaderRefresh() {
    if (window.dcmtAppointmentSync && typeof window.dcmtAppointmentSync.notifyReminderChanged === 'function') {
        window.dcmtAppointmentSync.notifyReminderChanged();
    } else if (typeof window.dcmtRefreshReminderNotifications === 'function') {
        window.dcmtRefreshReminderNotifications();
    }
}

function dcmtDeleteReminder(modal) {
    if (!dcmtCurrentReminderId) return;
    const fd = new FormData();
    fd.append('id', dcmtCurrentReminderId);
    fd.append('csrf_token', dcmtReminderCsrf);
    fetch('delete_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                dcmtNotifyReminderHeaderRefresh();
                if (modal) modal.hide();
                window.location.reload();
            } else {
                alert(data.message || 'Error');
            }
        });
}

function dcmtCompleteReminder(id) {
    if (!confirm(dcmtReminderTranslations.confirm_complete)) return;
    const fd = new FormData();
    fd.append('id', id);
    fd.append('csrf_token', dcmtReminderCsrf);
    fetch('complete_ajax.php', { method: 'POST', body: fd })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                dcmtNotifyReminderHeaderRefresh();
                window.location.reload();
            } else {
                alert(data.message || 'Error');
            }
        })
        .catch(function() {
            alert('Error');
        });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
