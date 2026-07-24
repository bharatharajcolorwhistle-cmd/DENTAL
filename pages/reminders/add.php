<?php
/**
 * Add Reminder Page
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

$errors = [];
$form_data = [
    'assigned_user_id' => (int) ($dcmt_current_user['dcmt_id'] ?? 0),
    'reminder_date' => dcmt_get_current_date(),
    'reminder_time' => date('H:i'),
    'title' => '',
    'description' => '',
];

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

        $assign_chk = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_users WHERE dcmt_id = ? AND dcmt_status = 'active'");
        $assign_chk->execute([$form_data['assigned_user_id']]);
        if (!$assign_chk->fetch()) {
            $errors[] = trans('reminder', 'assigned_user_required');
        }

        if (empty($errors)) {
            $result = dcmt_reminder_create($dcmt_pdo, $form_data, $dcmt_current_user);
            if ($result['success']) {
                dcmt_log_activity('Reminder created', 'Reminder ID: ' . ($result['id'] ?? ''));
                dcmt_show_message(trans('reminder', 'add_success'), 'success');
                dcmt_redirect('index.php');
                exit();
            }
            $errors = $result['errors'] ?? [trans('reminder', 'database_error')];
        }
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('reminder', 'add_reminder'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('reminder', 'view_all_reminders'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="dcmtReminderForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <?php require __DIR__ . '/form_fields.php'; ?>
        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="dcmtResetReminderBtn">
                <i class="fas fa-undo"></i><?php echo trans('common', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('reminder', 'add_reminder_record'); ?>
            </button>
        </div>
    </form>
</div>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
function dcmt_resetReminderForm() {
    const form = document.getElementById('dcmtReminderForm');
    if (!form) return;
    if (confirm(<?php echo json_encode(trans('reminder', 'confirm_reset')); ?>)) {
        form.reset();
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-plus"></i><?php echo trans('reminder', 'add_reminder_record'); ?>';
        }
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('dcmtReminderForm');
    const submitBtn = document.getElementById('submitBtn');
    const resetBtn = document.getElementById('dcmtResetReminderBtn');

    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
            submitBtn.disabled = true;
            submitBtn.setAttribute('data-original-text', originalText);
        });
    }

    if (resetBtn) resetBtn.addEventListener('click', dcmt_resetReminderForm);

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
