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
    'assignee_ids' => [(int) ($dcmt_current_user['dcmt_id'] ?? 0)],
    'reminder_date' => $_GET['date'] ?? dcmt_get_current_date(),
    'reminder_time' => date('H:i'),
    'title' => '',
    'description' => '',
    'priority' => 'medium',
    'category' => '',
    'recurrence_type' => 'none',
    'recurrence_end_date' => '',
];

$assignable_users = dcmt_reminder_get_assignable_users($dcmt_pdo);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('reminder', 'invalid_token');
    } else {
        $form_data = [
            'assigned_user_id' => (int) ($_POST['assigned_user_id'] ?? 0),
            'assignee_ids' => array_map('intval', $_POST['assignee_ids'] ?? []),
            'reminder_date' => dcmt_sanitize_input($_POST['reminder_date'] ?? ''),
            'reminder_time' => trim((string) ($_POST['reminder_time'] ?? '')),
            'title' => trim(dcmt_sanitize_input($_POST['title'] ?? '')),
            'description' => trim((string) ($_POST['description'] ?? '')),
            'priority' => dcmt_sanitize_input($_POST['priority'] ?? 'medium'),
            'category' => trim(dcmt_sanitize_input($_POST['category'] ?? '')),
            'recurrence_type' => dcmt_sanitize_input($_POST['recurrence_type'] ?? 'none'),
            'recurrence_end_date' => dcmt_sanitize_input($_POST['recurrence_end_date'] ?? ''),
        ];

        if (empty($form_data['assignee_ids']) && $form_data['assigned_user_id'] > 0) {
            $form_data['assignee_ids'] = [$form_data['assigned_user_id']];
        }
        if (!empty($form_data['assignee_ids'])) {
            $form_data['assigned_user_id'] = (int) $form_data['assignee_ids'][0];
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
<style>
.dcmt-filter-select2 .select2-selection--multiple .select2-selection__choice {
    display: none !important;
}
.dcmt-filter-select2.select2-container--default .select2-selection--multiple,
.dcmt-filter-select2 .select2-selection--multiple {
    min-height: 50px !important;
    height: 50px !important;
    display: flex;
    align-items: center;
}
.dcmt-filter-select2 .select2-selection--multiple .select2-selection__rendered {
    display: flex;
    flex-wrap: nowrap;
    align-items: center;
    gap: 0.25rem;
    padding-right: 1.75rem;
    width: 100%;
}
.dcmt-filter-select2 .select2-selection--multiple .dcmt-select2-multi-summary {
    margin: 0.25rem 0;
    padding: 0;
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    max-width: 100%;
    line-height: 1.25rem;
}
.dcmt-filter-select2 .select2-selection--multiple .select2-search--inline {
    flex: 1 1 auto;
}
.dcmt-filter-select2 .select2-selection--multiple .select2-search__field {
    width: 100% !important;
    margin-top: 0 !important;
}
.dcmt-filter-select2 .select2-results__option .dcmt-select2-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
    min-height: 18px;
}
.dcmt-filter-select2 .select2-results__option .dcmt-select2-option > span:last-child {
    display: inline-flex;
    align-items: center;
    line-height: 1.2;
}
.select2-results__option .dcmt-option-check {
    width: 16px;
    height: 16px;
    border: 1px solid #ced4da;
    border-radius: 3px;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    background: #fff;
    flex: 0 0 16px;
    margin-top: 0;
    margin-bottom: 0;
    vertical-align: middle;
}
.dcmt-filter-select2 .select2-results__option {
    padding-top: 0.45rem;
    padding-bottom: 0.45rem;
    transition: background-color 0.12s ease;
}
.dcmt-filter-select2 .select2-results__option--highlighted[aria-selected] {
    background-color: #e8f2ff !important;
    color: #0a58ca !important;
}
.dcmt-filter-select2 .select2-results__option--highlighted[aria-selected] .dcmt-option-check {
    border-color: #0d6efd;
}
.dcmt-doctor-filter-dropdown .select2-results__options {
    max-height: 280px !important;
}
.select2-results__option[aria-selected="true"] .dcmt-option-check {
    border-color: #0d6efd;
    background: #0d6efd;
}
.select2-results__option[aria-selected="true"] .dcmt-option-check::after {
    content: "";
    width: 6px;
    height: 10px;
    border: solid #fff;
    border-width: 0 2px 2px 0;
    transform: rotate(45deg);
}
</style>

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
    const recurrenceType = document.getElementById('recurrence_type');
    const recurrenceEndGroup = document.getElementById('recurrenceEndGroup');
    const assigneeSelect = document.getElementById('assignee_ids');
    const assignedHidden = document.getElementById('assigned_user_id');

    function syncPrimaryAssignee() {
        if (!assigneeSelect || !assignedHidden) return;
        const selected = Array.from(assigneeSelect.selectedOptions).map(o => o.value).filter(Boolean);
        assignedHidden.value = selected[0] || '';
    }

    const allAssigneesText = <?php echo json_encode(trans('reminder', 'all_assignees')); ?>;

    function initAssigneeSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $assignee = $('#assignee_ids');
        if ($assignee.length === 0) return;
        if ($assignee.hasClass('select2-hidden-accessible')) {
            $assignee.select2('destroy');
        }
        const isMultiple = !!$assignee.prop('multiple');

        function updateAssigneeSummary() {
            if (!isMultiple) return;
            const s2 = $assignee.data('select2');
            if (!s2 || !s2.$container) return;
            const $rendered = s2.$container.find('.select2-selection__rendered');
            if ($rendered.length === 0) return;
            $rendered.find('.dcmt-select2-multi-summary').remove();
            const selectedTexts = $assignee.find('option:selected').map(function() {
                return (this.text || '').trim();
            }).get().filter(Boolean);
            const totalOptions = $assignee.find('option').length;

            if (selectedTexts.length === 0) return;
            let label;
            if (totalOptions > 0 && selectedTexts.length === totalOptions) {
                label = allAssigneesText;
            } else if (selectedTexts.length === 1) {
                label = selectedTexts[0];
            } else {
                label = selectedTexts.length + ' selected';
            }

            const $li = $('<li class="dcmt-select2-multi-summary"></li>');
            $li.text(label);
            $rendered.prepend($li);
        }

        function assigneeOptionTemplate(data) {
            if (!data.id) return data.text;
            const label = String(data.text || '');
            const $row = $('<span class="dcmt-select2-option"></span>');
            if (isMultiple) {
                $row.append($('<span class="dcmt-option-check" aria-hidden="true"></span>'));
            }
            $row.append($('<span></span>').text(label));
            return $row;
        }

        function assigneeSelectionTemplate(data) {
            if (!data.id) return data.text;
            return $('<span></span>').text(String(data.text || ''));
        }

        $assignee.select2({
            width: '100%',
            placeholder: allAssigneesText,
            allowClear: isMultiple,
            closeOnSelect: !isMultiple,
            templateResult: assigneeOptionTemplate,
            templateSelection: assigneeSelectionTemplate
        });
        const s2 = $assignee.data('select2');
        if (s2 && s2.$container) {
            s2.$container.addClass('dcmt-filter-select2');
        }
        $assignee.off('select2:open.dcmtAssigneeFilterHeight').on('select2:open.dcmtAssigneeFilterHeight', function() {
            const openDropdown = document.querySelector('.select2-container--open .select2-dropdown');
            if (openDropdown) {
                openDropdown.classList.add('dcmt-doctor-filter-dropdown');
            }
        });
        if (isMultiple) {
            $assignee.off('.dcmtAssigneeSummary');
            $assignee.on('change.dcmtAssigneeSummary', function() {
                syncPrimaryAssignee();
                updateAssigneeSummary();
            });
            updateAssigneeSummary();
        }
    }

    if (form && submitBtn) {
        form.addEventListener('submit', function() {
            syncPrimaryAssignee();
            const originalText = submitBtn.innerHTML;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
            submitBtn.disabled = true;
            submitBtn.setAttribute('data-original-text', originalText);
        });
    }

    if (resetBtn) resetBtn.addEventListener('click', dcmt_resetReminderForm);

    if (recurrenceType && recurrenceEndGroup) {
        recurrenceType.addEventListener('change', function() {
            recurrenceEndGroup.style.display = this.value === 'none' ? 'none' : '';
        });
    }

    if (typeof $ !== 'undefined' && $.fn.select2) {
        initAssigneeSelect2();
        $(document).on('select2:open', function() {
            const input = document.querySelector('.select2-container--open .select2-search__field');
            if (input) input.focus();
        });
    } else if (assigneeSelect) {
        assigneeSelect.addEventListener('change', syncPrimaryAssignee);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
