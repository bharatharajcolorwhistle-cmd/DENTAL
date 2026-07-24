<?php
/**
 * Add Patient Checklist (Asana-style composer)
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_checklist_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$errors = [];
$preselected_patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$form_data = [
    'patient_id' => $preselected_patient_id,
    'items' => [
        ['title' => '', 'description' => ''],
    ],
];

$all_patients = [];
try {
    dcmt_patient_checklist_ensure_table($dcmt_pdo);
    $stmt = $dcmt_pdo->query("
        SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_status
        FROM dcmt_patients
        WHERE dcmt_status = 'active'
        ORDER BY dcmt_patient_name
    ");
    $all_patients = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('Patient checklist add patients: ' . $e->getMessage());
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient_checklist', 'invalid_token');
    } else {
        $form_data['patient_id'] = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
        $posted_items = $_POST['items'] ?? [];
        $form_data['items'] = [];

        if (is_array($posted_items)) {
            foreach ($posted_items as $row) {
                if (!is_array($row)) {
                    continue;
                }
                $form_data['items'][] = [
                    'title' => trim((string) ($row['title'] ?? '')),
                    'description' => trim((string) ($row['description'] ?? '')),
                ];
            }
        }

        if (empty($form_data['items'])) {
            $form_data['items'] = [['title' => '', 'description' => '']];
        }

        $result = dcmt_patient_checklist_create_many(
            $dcmt_pdo,
            $form_data['patient_id'],
            $form_data['items'],
            $dcmt_current_user
        );

        if ($result['success']) {
            dcmt_log_activity(
                'Patient checklist created',
                'Patient ID: ' . $form_data['patient_id'] . ', Items: ' . (int) ($result['created_count'] ?? 0)
            );
            dcmt_show_message($result['message'], 'success');
            dcmt_redirect('view.php?patient_id=' . $form_data['patient_id']);
            exit();
        }

        if (!empty($result['errors']) && is_array($result['errors'])) {
            $errors = $result['errors'];
        } else {
            $errors[] = $result['message'] ?? trans('patient_checklist', 'database_error');
        }
    }
}

$page_title = $preselected_patient_id > 0
    ? trans('patient_checklist', 'add_more_items')
    : trans('patient_checklist', 'add_checklist');

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">
<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/patient-checklist.css', '../../'); ?>">
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
            <h1 class="dcmt-add-form-page-title"><?php echo $page_title; ?></h1>
            <?php if ($form_data['patient_id'] > 0): ?>
                <a href="view.php?patient_id=<?php echo (int) $form_data['patient_id']; ?>" class="dcmt-add-form-view-all-link">
                    <?php echo trans('patient_checklist', 'view_checklist'); ?>
                </a>
            <?php else: ?>
                <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient_checklist', 'back_to_checklists'); ?></a>
            <?php endif; ?>
        </div>
    </div>

    <form method="POST" action="" id="dcmtChecklistForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="patient_id" class="form-label">
                        <?php echo trans('patient', 'patient'); ?> <span class="text-danger">*</span>
                    </label>
                    <select class="form-select" id="patient_id" name="patient_id" required>
                        <option value=""><?php echo trans('patient_checklist', 'select_patient'); ?></option>
                        <?php foreach ($all_patients as $pat): ?>
                            <option value="<?php echo (int) $pat['dcmt_id']; ?>"
                                <?php echo $form_data['patient_id'] === (int) $pat['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($pat['dcmt_patient_name'] ?? ''); ?>
                                <?php if (!empty($pat['dcmt_phone'])): ?>
                                    - <?php echo htmlspecialchars($pat['dcmt_phone']); ?>
                                <?php endif; ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <?php if (empty($all_patients)): ?>
                        <div class="form-text text-warning">
                            <i class="fas fa-exclamation-triangle"></i> <?php echo trans('patient_note', 'no_patients_found'); ?>
                        </div>
                    <?php endif; ?>
                </div>
            </div>
        </div>

        <div class="dcmt-asana-checklist">
            <div class="dcmt-asana-checklist__header">
                <h2 class="dcmt-asana-checklist__title">
                    <span class="dcmt-asana-checklist__title-icon"><i class="fas fa-check"></i></span>
                    <?php echo trans('patient_checklist', 'checklist_items'); ?>
                </h2>
            </div>

            <div class="dcmt-asana-composer" id="dcmtChecklistItems">
                <?php foreach ($form_data['items'] as $idx => $item): ?>
                    <div class="dcmt-asana-composer-item dcmt-checklist-row" data-row-index="<?php echo (int) $idx; ?>">
                        <span class="dcmt-asana-check" aria-hidden="true"><i class="fas fa-check"></i></span>
                        <div class="dcmt-asana-composer-fields">
                            <input type="text"
                                   class="dcmt-asana-composer-title"
                                   name="items[<?php echo (int) $idx; ?>][title]"
                                   maxlength="255"
                                   value="<?php echo htmlspecialchars($item['title'] ?? ''); ?>"
                                   placeholder="<?php echo trans('patient_checklist', 'type_item_placeholder'); ?>">
                            <input type="text"
                                   class="dcmt-asana-composer-desc"
                                   name="items[<?php echo (int) $idx; ?>][description]"
                                   value="<?php echo htmlspecialchars($item['description'] ?? ''); ?>"
                                   placeholder="<?php echo trans('patient_checklist', 'type_desc_placeholder'); ?>">
                        </div>
                        <button type="button"
                                class="dcmt-asana-item__action is-danger dcmt-asana-composer-remove dcmt-remove-checklist-row"
                                title="<?php echo trans('patient_checklist', 'remove_item'); ?>">
                            <i class="fas fa-times"></i>
                        </button>
                    </div>
                <?php endforeach; ?>
            </div>

            <button type="button" class="dcmt-asana-add-trigger" id="dcmtAddChecklistRowBtn">
                <i class="fas fa-plus"></i>
                <span><?php echo trans('patient_checklist', 'add_item'); ?></span>
            </button>
        </div>

        <div class="dcmt-form-actions mt-4">
            <button type="button" class="btn dcmt-btn-reset" id="dcmtResetChecklistBtn">
                <i class="fas fa-undo"></i><?php echo trans('common', 'reset'); ?>
            </button>
            <a href="<?php echo $form_data['patient_id'] > 0 ? 'view.php?patient_id=' . (int) $form_data['patient_id'] : 'index.php'; ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-check"></i><?php echo trans('patient_checklist', 'save_checklist'); ?>
            </button>
        </div>
    </form>
</div>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
(function() {
    let nextIndex = <?php echo count($form_data['items']); ?>;
    const itemsWrap = document.getElementById('dcmtChecklistItems');
    const titlePh = <?php echo json_encode(trans('patient_checklist', 'type_item_placeholder')); ?>;
    const descPh = <?php echo json_encode(trans('patient_checklist', 'type_desc_placeholder')); ?>;
    const removeTitle = <?php echo json_encode(trans('patient_checklist', 'remove_item')); ?>;

    function updateRemoveButtons() {
        const rows = itemsWrap.querySelectorAll('.dcmt-checklist-row');
        rows.forEach(function(row) {
            const btn = row.querySelector('.dcmt-remove-checklist-row');
            if (btn) btn.disabled = rows.length <= 1;
        });
    }

    function createRow(index) {
        const row = document.createElement('div');
        row.className = 'dcmt-asana-composer-item dcmt-checklist-row';
        row.setAttribute('data-row-index', String(index));
        row.innerHTML =
            '<span class="dcmt-asana-check" aria-hidden="true"><i class="fas fa-check"></i></span>' +
            '<div class="dcmt-asana-composer-fields">' +
                '<input type="text" class="dcmt-asana-composer-title" name="items[' + index + '][title]" maxlength="255" placeholder="' + titlePh + '">' +
                '<input type="text" class="dcmt-asana-composer-desc" name="items[' + index + '][description]" placeholder="' + descPh + '">' +
            '</div>' +
            '<button type="button" class="dcmt-asana-item__action is-danger dcmt-asana-composer-remove dcmt-remove-checklist-row" title="' + removeTitle + '">' +
                '<i class="fas fa-times"></i>' +
            '</button>';
        return row;
    }

    function focusTitle(row) {
        const input = row && row.querySelector('.dcmt-asana-composer-title');
        if (input) input.focus();
    }

    function addRowAndFocus() {
        const row = createRow(nextIndex++);
        itemsWrap.appendChild(row);
        updateRemoveButtons();
        focusTitle(row);
    }

    document.getElementById('dcmtAddChecklistRowBtn').addEventListener('click', addRowAndFocus);

    itemsWrap.addEventListener('click', function(e) {
        const btn = e.target.closest('.dcmt-remove-checklist-row');
        if (!btn) return;
        const rows = itemsWrap.querySelectorAll('.dcmt-checklist-row');
        if (rows.length <= 1) return;
        const row = btn.closest('.dcmt-checklist-row');
        if (row) row.remove();
        updateRemoveButtons();
    });

    itemsWrap.addEventListener('keydown', function(e) {
        if (e.key !== 'Enter') return;
        const target = e.target;
        if (!target.classList.contains('dcmt-asana-composer-title') && !target.classList.contains('dcmt-asana-composer-desc')) {
            return;
        }
        e.preventDefault();
        if (target.classList.contains('dcmt-asana-composer-title') && target.value.trim() !== '') {
            addRowAndFocus();
        } else if (target.classList.contains('dcmt-asana-composer-desc')) {
            addRowAndFocus();
        }
    });

    function dcmt_resetChecklistForm() {
        const form = document.getElementById('dcmtChecklistForm');
        if (!form) return;
        if (!confirm(<?php echo json_encode(trans('patient_checklist', 'confirm_reset')); ?>)) return;
        form.reset();
        if (typeof $ !== 'undefined') {
            $('#patient_id').val('').trigger('change');
        }
        const submitBtn = document.getElementById('submitBtn');
        if (submitBtn) {
            submitBtn.disabled = false;
            submitBtn.innerHTML = '<i class="fas fa-check"></i><?php echo htmlspecialchars(trans('patient_checklist', 'save_checklist'), ENT_QUOTES); ?>';
        }
    }

    document.addEventListener('DOMContentLoaded', function() {
        updateRemoveButtons();
        const firstTitle = itemsWrap.querySelector('.dcmt-asana-composer-title');
        if (firstTitle && !firstTitle.value) firstTitle.focus();

        const form = document.getElementById('dcmtChecklistForm');
        const submitBtn = document.getElementById('submitBtn');
        const resetBtn = document.getElementById('dcmtResetChecklistBtn');

        if (form && submitBtn) {
            form.addEventListener('submit', function() {
                submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo trans('common', 'processing'); ?>...';
                submitBtn.disabled = true;
            });
        }
        if (resetBtn) resetBtn.addEventListener('click', dcmt_resetChecklistForm);

        if (typeof $ !== 'undefined' && $.fn.select2) {
            $('#patient_id').select2({
                placeholder: <?php echo json_encode(trans('patient_checklist', 'select_patient')); ?>,
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
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
