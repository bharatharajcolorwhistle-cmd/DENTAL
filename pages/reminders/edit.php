<?php
/**
 * Edit Reminder Page
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
if (!$reminder) {
    dcmt_show_message(trans('reminder', 'not_found'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

if (!dcmt_reminder_user_can_manage($reminder, $dcmt_current_user)) {
    dcmt_show_message(trans('reminder', 'no_permission'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$reminder_ts = strtotime($reminder['dcmt_reminder_at'] ?? '');
$form_data = [
    'assigned_user_id' => (int) ($reminder['dcmt_assigned_user_id'] ?? 0),
    'reminder_date' => $reminder_ts ? date('Y-m-d', $reminder_ts) : dcmt_get_current_date(),
    'reminder_time' => $reminder_ts ? date('H:i', $reminder_ts) : date('H:i'),
    'title' => $reminder['dcmt_title'] ?? '',
    'description' => $reminder['dcmt_description'] ?? '',
];

$errors = [];
$assignable_users = dcmt_reminder_get_assignable_users($dcmt_pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('reminder', 'invalid_token');
    } else {
        $form_data = [
            'assigned_user_id' => (int) ($_POST['assigned_user_id'] ?? 0),
            'reminder_date' => dcmt_sanitize_input($_POST['reminder_date'] ?? ''),
            'reminder_time' => trim((string) ($_POST['reminder_time'] ?? '')),
            'title' => trim(dcmt_sanitize_input($_POST['title'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
        ];

        $result = dcmt_reminder_update($dcmt_pdo, $reminder_id, $form_data, $dcmt_current_user);
        if ($result['success']) {
            dcmt_log_activity('Reminder updated', "Reminder ID: {$reminder_id}");
            dcmt_show_message(trans('reminder', 'update_success'), 'success');
            dcmt_redirect('view.php?id=' . $reminder_id);
            exit();
        }
        $errors = $result['errors'] ?? [trans('reminder', 'database_error')];
    }
}

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">
<link href="<?php echo dcmt_asset('assets/css/select2.min.css', '../../'); ?>" rel="stylesheet">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('reminder', 'edit_reminder'); ?></h1>
            <a href="view.php?id=<?php echo $reminder_id; ?>" class="dcmt-add-form-view-all-link"><?php echo trans('reminder', 'view_reminder'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="dcmtReminderEditForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <?php require __DIR__ . '/form_fields.php'; ?>
        <div class="dcmt-form-actions">
            <a href="view.php?id=<?php echo $reminder_id; ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('reminder', 'update_reminder_record'); ?>
            </button>
        </div>
    </form>
</div>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dcmtReminderEditForm');
    const submitBtn = document.getElementById('submitBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
            submitBtn.disabled = true;
            submitBtn.setAttribute('data-original-text', originalText);
        });
    }

    if (typeof $ !== 'undefined' && $.fn.select2) {
        $('#assigned_user_id').select2({
            placeholder: <?php echo json_encode(trans('reminder', 'select_assignee')); ?>,
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0
        });
        $(document).on('select2:open', function() {
            const input = document.querySelector('.select2-container--open .select2-search__field');
            if (input) input.focus();
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
