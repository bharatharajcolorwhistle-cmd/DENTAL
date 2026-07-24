<?php
/**
 * View Patient Checklist Items (Asana-style)
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

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';
if (!in_array($status, ['', 'pending', 'completed'], true)) {
    $status = '';
}
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

if ($patient_id <= 0) {
    dcmt_show_message(trans('patient_checklist', 'patient_required'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$dcmt_current_user = dcmt_get_current_user();
$can_delete = dcmt_patient_checklist_can_delete($dcmt_current_user);

$patient = null;
$items = [];
$completed_count = 0;
$pending_count = 0;

try {
    dcmt_patient_checklist_ensure_table($dcmt_pdo);

    $pstmt = $dcmt_pdo->prepare("
        SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_email
        FROM dcmt_patients
        WHERE dcmt_id = ?
        LIMIT 1
    ");
    $pstmt->execute([$patient_id]);
    $patient = $pstmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient_checklist', 'patient_not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }

    $result = dcmt_patient_checklist_list($dcmt_pdo, [
        'patient_id' => $patient_id,
        'search' => $search,
        'status' => $status,
    ]);
    $items = $result['items'];

    $prog = $dcmt_pdo->prepare("
        SELECT
            SUM(CASE WHEN dcmt_is_completed = 1 THEN 1 ELSE 0 END) AS completed_count,
            SUM(CASE WHEN dcmt_is_completed = 0 THEN 1 ELSE 0 END) AS pending_count
        FROM dcmt_patient_checklist_items
        WHERE dcmt_patient_id = ?
    ");
    $prog->execute([$patient_id]);
    $prog_row = $prog->fetch(PDO::FETCH_ASSOC) ?: [];
    $completed_count = (int) ($prog_row['completed_count'] ?? 0);
    $pending_count = (int) ($prog_row['pending_count'] ?? 0);
} catch (PDOException $e) {
    error_log('Patient checklist view error: ' . $e->getMessage());
    dcmt_show_message(trans('patient_checklist', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$csrf_token = dcmt_generate_csrf_token();
$total_all = $completed_count + $pending_count;
$pct = $total_all > 0 ? (int) round(($completed_count / $total_all) * 100) : 0;
$progress_label = str_replace(
    ['{done}', '{total}'],
    [(string) $completed_count, (string) $total_all],
    trans('patient_checklist', 'completed_count_label')
);

require_once __DIR__ . '/../../includes/header.php';
?>

<meta name="csrf-token" content="<?php echo htmlspecialchars($csrf_token); ?>">
<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/patient-checklist.css', '../../'); ?>">

<div class="card dcmt-records-table mb-4">
    <div class="card-header dcmt-view-card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-tasks dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('patient_checklist', 'checklist_details'); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?></small>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <a href="../patients/view.php?id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-user me-1"></i><?php echo trans('patient', 'patient'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('patient_checklist', 'back_to_checklists'); ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient_checklist', 'patient'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient_checklist', 'phone'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_phone'] ?? '-'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient_checklist', 'progress'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($progress_label); ?></div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <input type="hidden" name="patient_id" value="<?php echo $patient_id; ?>">
            <div class="col-md-3">
                <label for="status" class="form-label"><?php echo trans('patient_checklist', 'filter_status'); ?></label>
                <select class="form-select dcmt-filter-field" id="status" name="status">
                    <option value=""><?php echo trans('patient_checklist', 'all_statuses'); ?></option>
                    <option value="pending" <?php echo $status === 'pending' ? 'selected' : ''; ?>><?php echo trans('patient_checklist', 'status_pending'); ?></option>
                    <option value="completed" <?php echo $status === 'completed' ? 'selected' : ''; ?>><?php echo trans('patient_checklist', 'status_completed'); ?></option>
                </select>
            </div>
            <div class="col-md-5">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('patient_checklist', 'search_items_placeholder'); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="view.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="dcmt-asana-checklist" id="dcmtAsanaChecklist">
    <div class="dcmt-asana-checklist__header">
        <h2 class="dcmt-asana-checklist__title">
            <span class="dcmt-asana-checklist__title-icon"><i class="fas fa-check"></i></span>
            <?php echo trans('patient_checklist', 'checklist'); ?>
        </h2>
        <div class="dcmt-asana-checklist__progress">
            <span class="dcmt-asana-checklist__progress-text" id="dcmtChecklistProgressText">
                <?php echo htmlspecialchars($progress_label); ?>
            </span>
            <div class="dcmt-asana-checklist__progress-bar" aria-hidden="true">
                <div class="dcmt-asana-checklist__progress-fill" id="dcmtChecklistProgressFill" style="width: <?php echo $pct; ?>%;"></div>
            </div>
        </div>
    </div>

    <div class="dcmt-asana-add-row dcmt-asana-add-row--top" id="dcmtQuickAddRow">
        <span class="dcmt-asana-check" aria-hidden="true"><i class="fas fa-check"></i></span>
        <div class="dcmt-asana-add-fields">
            <input type="text"
                   class="dcmt-asana-add-input"
                   id="dcmtQuickAddInput"
                   maxlength="255"
                   placeholder="<?php echo trans('patient_checklist', 'type_item_placeholder'); ?>"
                   autocomplete="off">
            <input type="text"
                   class="dcmt-asana-add-input dcmt-asana-add-input--desc"
                   id="dcmtQuickAddDesc"
                   placeholder="<?php echo trans('patient_checklist', 'type_desc_placeholder'); ?>"
                   autocomplete="off">
        </div>
    </div>

    <?php if (empty($items)): ?>
        <div class="dcmt-asana-empty" id="dcmtChecklistEmpty">
            <?php echo trans('patient_checklist', 'start_adding_item'); ?>
        </div>
        <ul class="dcmt-asana-list" id="dcmtChecklistList" hidden></ul>
    <?php else: ?>
        <ul class="dcmt-asana-list" id="dcmtChecklistList">
            <?php foreach ($items as $item): ?>
                <?php
                $is_done = (int) ($item['dcmt_is_completed'] ?? 0) === 1;
                $item_id = (int) $item['dcmt_id'];
                $title_esc = htmlspecialchars($item['dcmt_title'] ?? '', ENT_QUOTES);
                $desc_raw = trim((string) ($item['dcmt_description'] ?? ''));
                ?>
                <li class="dcmt-asana-item<?php echo $is_done ? ' is-completed' : ''; ?>" data-item-id="<?php echo $item_id; ?>">
                    <button type="button"
                            class="dcmt-asana-check<?php echo $is_done ? ' is-checked' : ''; ?>"
                            title="<?php echo $is_done ? trans('patient_checklist', 'mark_incomplete') : trans('patient_checklist', 'mark_complete'); ?>"
                            onclick="event.stopPropagation(); dcmtToggleChecklistItem(<?php echo $item_id; ?>, this)"
                            aria-pressed="<?php echo $is_done ? 'true' : 'false'; ?>">
                        <i class="fas fa-check"></i>
                    </button>
                    <div class="dcmt-asana-item__body is-editable"
                         title="<?php echo trans('patient_checklist', 'click_to_edit'); ?>"
                         onclick="dcmtStartEditChecklistItem(this)">
                        <div class="dcmt-asana-item__title"><?php echo htmlspecialchars($item['dcmt_title'] ?? ''); ?></div>
                        <?php if ($desc_raw !== ''): ?>
                            <div class="dcmt-asana-item__desc"><?php echo htmlspecialchars($desc_raw); ?></div>
                        <?php endif; ?>
                    </div>
                    <div class="dcmt-asana-item__actions">
                        <button type="button"
                                class="dcmt-asana-item__action is-edit"
                                title="<?php echo trans('patient_checklist', 'edit_item'); ?>"
                                onclick="event.stopPropagation(); dcmtStartEditChecklistItem(this.closest('.dcmt-asana-item').querySelector('.dcmt-asana-item__body'))">
                            <i class="fas fa-pencil-alt"></i>
                        </button>
                        <?php if ($can_delete): ?>
                            <button type="button"
                                    class="dcmt-asana-item__action is-danger"
                                    title="<?php echo trans('common', 'delete'); ?>"
                                    onclick="event.stopPropagation(); dcmtShowChecklistDeleteModal(<?php echo $item_id; ?>, '<?php echo $title_esc; ?>')">
                                <i class="fas fa-times"></i>
                            </button>
                        <?php endif; ?>
                    </div>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if ($can_delete): ?>
<div class="modal fade" id="dcmtChecklistDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('common', 'delete'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <p class="mb-2"><?php echo trans('patient_checklist', 'delete_confirmation_message'); ?></p>
                <p class="fw-semibold mb-0" id="dcmtChecklistDeleteTitle"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="dcmtConfirmChecklistDeleteBtn">
                    <i class="fas fa-trash me-1"></i><?php echo trans('common', 'yes_delete'); ?>
                </button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<script>
const dcmtChecklistCsrf = <?php echo json_encode($csrf_token); ?>;
const dcmtChecklistPatientId = <?php echo (int) $patient_id; ?>;
const dcmtCanDelete = <?php echo $can_delete ? 'true' : 'false'; ?>;
const dcmtProgressTemplate = <?php echo json_encode(trans('patient_checklist', 'completed_count_label')); ?>;
const dcmtClickToEdit = <?php echo json_encode(trans('patient_checklist', 'click_to_edit')); ?>;
const dcmtEditItemLabel = <?php echo json_encode(trans('patient_checklist', 'edit_item')); ?>;
const dcmtTitlePlaceholder = <?php echo json_encode(trans('patient_checklist', 'title_placeholder')); ?>;
const dcmtDescPlaceholder = <?php echo json_encode(trans('patient_checklist', 'type_desc_placeholder')); ?>;
const dcmtUpdateFailed = <?php echo json_encode(trans('patient_checklist', 'update_failed')); ?>;
let dcmtDoneCount = <?php echo (int) $completed_count; ?>;
let dcmtTotalCount = <?php echo (int) $total_all; ?>;
let dcmtCurrentChecklistId = 0;
let dcmtChecklistDeleteModal = null;
let dcmtQuickAddBusy = false;
let dcmtEditingRow = null;
let dcmtEditSnapshot = null;
let dcmtEditBusy = false;

document.addEventListener('DOMContentLoaded', function() {
    const confirmBtn = document.getElementById('dcmtConfirmChecklistDeleteBtn');
    if (confirmBtn) confirmBtn.addEventListener('click', dcmtDeleteChecklistItem);

    const quickInput = document.getElementById('dcmtQuickAddInput');
    const quickDesc = document.getElementById('dcmtQuickAddDesc');
    if (quickInput) {
        quickInput.addEventListener('keydown', function(e) {
            if (e.key !== 'Enter') return;
            e.preventDefault();
            if ((quickInput.value || '').trim() !== '') {
                dcmtQuickAddItem();
            }
        });
        setTimeout(function() { quickInput.focus(); }, 100);
    }
    if (quickDesc) {
        quickDesc.addEventListener('keydown', function(e) {
            if (e.key === 'Enter') {
                e.preventDefault();
                dcmtQuickAddItem();
            }
        });
    }

    document.addEventListener('click', function(e) {
        if (!dcmtEditingRow || dcmtEditBusy) return;
        if (dcmtEditingRow.contains(e.target)) return;
        dcmtSaveChecklistItemEdit(dcmtEditingRow);
    });
});

function dcmtUpdateProgress() {
    const text = dcmtProgressTemplate
        .replace('{done}', String(dcmtDoneCount))
        .replace('{total}', String(dcmtTotalCount));
    const textEl = document.getElementById('dcmtChecklistProgressText');
    const fillEl = document.getElementById('dcmtChecklistProgressFill');
    if (textEl) textEl.textContent = text;
    if (fillEl) {
        const pct = dcmtTotalCount > 0 ? Math.round((dcmtDoneCount / dcmtTotalCount) * 100) : 0;
        fillEl.style.width = pct + '%';
    }
}

function dcmtEnsureListVisible() {
    const empty = document.getElementById('dcmtChecklistEmpty');
    const list = document.getElementById('dcmtChecklistList');
    if (empty) empty.hidden = true;
    if (list) list.hidden = false;
}

function dcmtBuildItemBody(title, description) {
    const body = document.createElement('div');
    body.className = 'dcmt-asana-item__body is-editable';
    body.title = dcmtClickToEdit;
    body.addEventListener('click', function() {
        dcmtStartEditChecklistItem(body);
    });

    const titleEl = document.createElement('div');
    titleEl.className = 'dcmt-asana-item__title';
    titleEl.textContent = title || '';
    body.appendChild(titleEl);

    if (description) {
        const desc = document.createElement('div');
        desc.className = 'dcmt-asana-item__desc';
        desc.textContent = description;
        body.appendChild(desc);
    }
    return body;
}

function dcmtBuildItemActions(itemId, title) {
    const actions = document.createElement('div');
    actions.className = 'dcmt-asana-item__actions';

    const editBtn = document.createElement('button');
    editBtn.type = 'button';
    editBtn.className = 'dcmt-asana-item__action is-edit';
    editBtn.title = dcmtEditItemLabel;
    editBtn.innerHTML = '<i class="fas fa-pencil-alt"></i>';
    editBtn.addEventListener('click', function(e) {
        e.stopPropagation();
        const row = actions.closest('.dcmt-asana-item');
        const body = row ? row.querySelector('.dcmt-asana-item__body') : null;
        if (body) dcmtStartEditChecklistItem(body);
    });
    actions.appendChild(editBtn);

    if (dcmtCanDelete) {
        const del = document.createElement('button');
        del.type = 'button';
        del.className = 'dcmt-asana-item__action is-danger';
        del.title = <?php echo json_encode(trans('common', 'delete')); ?>;
        del.innerHTML = '<i class="fas fa-times"></i>';
        del.addEventListener('click', function(e) {
            e.stopPropagation();
            dcmtShowChecklistDeleteModal(itemId, title || '');
        });
        actions.appendChild(del);
    }
    return actions;
}

function dcmtGetItemText(row) {
    const titleEl = row.querySelector('.dcmt-asana-item__title');
    const descEl = row.querySelector('.dcmt-asana-item__desc');
    return {
        title: titleEl ? titleEl.textContent : '',
        description: descEl ? descEl.textContent : ''
    };
}

function dcmtRenderItemBody(body, title, description) {
    body.classList.remove('is-editing');
    body.classList.add('is-editable');
    body.title = dcmtClickToEdit;
    body.replaceChildren();

    const titleEl = document.createElement('div');
    titleEl.className = 'dcmt-asana-item__title';
    titleEl.textContent = title || '';
    body.appendChild(titleEl);

    if (description) {
        const desc = document.createElement('div');
        desc.className = 'dcmt-asana-item__desc';
        desc.textContent = description;
        body.appendChild(desc);
    }

    body.onclick = function() {
        dcmtStartEditChecklistItem(body);
    };
}

function dcmtStartEditChecklistItem(body) {
    if (!body || body.classList.contains('is-editing') || dcmtEditBusy) return;
    const row = body.closest('.dcmt-asana-item');
    if (!row) return;

    if (dcmtEditingRow && dcmtEditingRow !== row) {
        dcmtCancelChecklistItemEdit(dcmtEditingRow);
    }

    const current = dcmtGetItemText(row);
    dcmtEditSnapshot = { title: current.title, description: current.description };
    dcmtEditingRow = row;
    row.classList.add('is-editing');
    body.classList.remove('is-editable');
    body.classList.add('is-editing');
    body.onclick = null;

    const wrap = document.createElement('div');
    wrap.className = 'dcmt-asana-edit-fields';

    const titleInput = document.createElement('input');
    titleInput.type = 'text';
    titleInput.className = 'dcmt-asana-edit-input';
    titleInput.maxLength = 255;
    titleInput.value = current.title;
    titleInput.placeholder = dcmtTitlePlaceholder;

    const descInput = document.createElement('input');
    descInput.type = 'text';
    descInput.className = 'dcmt-asana-edit-input dcmt-asana-edit-input--desc';
    descInput.value = current.description;
    descInput.placeholder = dcmtDescPlaceholder;

    titleInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            descInput.focus();
        } else if (e.key === 'Escape') {
            e.preventDefault();
            dcmtCancelChecklistItemEdit(row);
        }
    });
    descInput.addEventListener('keydown', function(e) {
        if (e.key === 'Enter') {
            e.preventDefault();
            dcmtSaveChecklistItemEdit(row);
        } else if (e.key === 'Escape') {
            e.preventDefault();
            dcmtCancelChecklistItemEdit(row);
        }
    });

    wrap.appendChild(titleInput);
    wrap.appendChild(descInput);
    body.replaceChildren(wrap);
    titleInput.focus();
    titleInput.select();
}

function dcmtCancelChecklistItemEdit(row) {
    if (!row || !dcmtEditSnapshot) return;
    const body = row.querySelector('.dcmt-asana-item__body');
    if (!body) return;
    dcmtRenderItemBody(body, dcmtEditSnapshot.title, dcmtEditSnapshot.description);
    row.classList.remove('is-editing');
    dcmtEditingRow = null;
    dcmtEditSnapshot = null;
}

function dcmtSaveChecklistItemEdit(row) {
    if (!row || dcmtEditBusy) return;
    const body = row.querySelector('.dcmt-asana-item__body');
    const titleInput = body ? body.querySelector('.dcmt-asana-edit-input') : null;
    const descInput = body ? body.querySelector('.dcmt-asana-edit-input--desc') : null;
    if (!body || !titleInput) return;

    const title = (titleInput.value || '').trim();
    const description = descInput ? (descInput.value || '').trim() : '';
    if (!title) {
        alert(<?php echo json_encode(trans('patient_checklist', 'title_required')); ?>);
        titleInput.focus();
        return;
    }

    const unchanged = dcmtEditSnapshot
        && dcmtEditSnapshot.title === title
        && dcmtEditSnapshot.description === description;
    if (unchanged) {
        dcmtCancelChecklistItemEdit(row);
        return;
    }

    const itemId = parseInt(row.getAttribute('data-item-id') || '0', 10);
    if (!itemId) return;

    dcmtEditBusy = true;
    titleInput.disabled = true;
    if (descInput) descInput.disabled = true;

    const fd = new FormData();
    fd.append('id', String(itemId));
    fd.append('title', title);
    fd.append('description', description);
    fd.append('csrf_token', dcmtChecklistCsrf);

    fetch('update_ajax.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.item) {
                alert(data.message || dcmtUpdateFailed);
                return;
            }
            const savedTitle = data.item.title ? decodeHtml(data.item.title) : title;
            const savedDesc = data.item.description ? decodeHtml(data.item.description) : '';
            dcmtRenderItemBody(body, savedTitle, savedDesc);
            row.classList.remove('is-editing');
            dcmtEditingRow = null;
            dcmtEditSnapshot = null;
        })
        .catch(function() {
            alert(dcmtUpdateFailed);
        })
        .finally(function() {
            dcmtEditBusy = false;
        });
}

function dcmtBuildItemRow(item) {
    const li = document.createElement('li');
    li.className = 'dcmt-asana-item';
    li.setAttribute('data-item-id', String(item.id));

    const check = document.createElement('button');
    check.type = 'button';
    check.className = 'dcmt-asana-check';
    check.title = <?php echo json_encode(trans('patient_checklist', 'mark_complete')); ?>;
    check.setAttribute('aria-pressed', 'false');
    check.innerHTML = '<i class="fas fa-check"></i>';
    check.addEventListener('click', function(e) {
        e.stopPropagation();
        dcmtToggleChecklistItem(item.id, check);
    });

    const body = dcmtBuildItemBody(
        item.title || '',
        item.description || ''
    );

    li.appendChild(check);
    li.appendChild(body);
    li.appendChild(dcmtBuildItemActions(item.id, item.title || ''));
    return li;
}

function dcmtQuickAddItem() {
    const input = document.getElementById('dcmtQuickAddInput');
    const descInput = document.getElementById('dcmtQuickAddDesc');
    if (!input || dcmtQuickAddBusy) return;
    const title = (input.value || '').trim();
    const description = descInput ? (descInput.value || '').trim() : '';
    if (!title) return;

    dcmtQuickAddBusy = true;
    input.disabled = true;
    if (descInput) descInput.disabled = true;

    const fd = new FormData();
    fd.append('patient_id', String(dcmtChecklistPatientId));
    fd.append('title', title);
    fd.append('description', description);
    fd.append('csrf_token', dcmtChecklistCsrf);

    fetch('add_ajax.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success || !data.item) {
                alert(data.message || <?php echo json_encode(trans('patient_checklist', 'quick_add_failed')); ?>);
                return;
            }
            dcmtEnsureListVisible();
            const list = document.getElementById('dcmtChecklistList');
            const completedFirst = list.querySelector('.dcmt-asana-item.is-completed');
            const row = dcmtBuildItemRow({
                id: data.item.id,
                title: data.item.title ? decodeHtml(data.item.title) : title,
                description: data.item.description ? decodeHtml(data.item.description) : ''
            });
            if (completedFirst) {
                list.insertBefore(row, completedFirst);
            } else {
                list.appendChild(row);
            }
            dcmtTotalCount += 1;
            dcmtUpdateProgress();
            input.value = '';
            if (descInput) descInput.value = '';
        })
        .catch(function() {
            alert(<?php echo json_encode(trans('patient_checklist', 'quick_add_failed')); ?>);
        })
        .finally(function() {
            dcmtQuickAddBusy = false;
            input.disabled = false;
            if (descInput) descInput.disabled = false;
            input.focus();
        });
}

function decodeHtml(html) {
    const txt = document.createElement('textarea');
    txt.innerHTML = html;
    return txt.value;
}

function dcmtToggleChecklistItem(id, button) {
    const row = button.closest('.dcmt-asana-item');
    const wasCompleted = button.classList.contains('is-checked');
    const completed = wasCompleted ? 0 : 1;

    button.disabled = true;
    const fd = new FormData();
    fd.append('id', String(id));
    fd.append('completed', String(completed));
    fd.append('csrf_token', dcmtChecklistCsrf);

    fetch('toggle_ajax.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert(data.message || 'Error');
                return;
            }
            button.classList.toggle('is-checked', completed === 1);
            button.setAttribute('aria-pressed', completed === 1 ? 'true' : 'false');
            button.title = completed === 1
                ? <?php echo json_encode(trans('patient_checklist', 'mark_incomplete')); ?>
                : <?php echo json_encode(trans('patient_checklist', 'mark_complete')); ?>;
            if (row) {
                row.classList.toggle('is-completed', completed === 1);
                const list = document.getElementById('dcmtChecklistList');
                if (list && completed === 1) {
                    list.appendChild(row);
                } else if (list && completed === 0) {
                    const firstCompleted = list.querySelector('.dcmt-asana-item.is-completed');
                    if (firstCompleted) list.insertBefore(row, firstCompleted);
                    else list.prepend(row);
                }
            }
            dcmtDoneCount += completed === 1 ? 1 : -1;
            if (dcmtDoneCount < 0) dcmtDoneCount = 0;
            if (dcmtDoneCount > dcmtTotalCount) dcmtDoneCount = dcmtTotalCount;
            dcmtUpdateProgress();
        })
        .catch(function() {
            alert(<?php echo json_encode(trans('patient_checklist', 'database_error')); ?>);
        })
        .finally(function() {
            button.disabled = false;
        });
}

function dcmtShowChecklistDeleteModal(id, title) {
    dcmtCurrentChecklistId = id;
    const titleEl = document.getElementById('dcmtChecklistDeleteTitle');
    if (titleEl) titleEl.textContent = title || '';
    const modalEl = document.getElementById('dcmtChecklistDeleteModal');
    if (!modalEl) return;
    dcmtChecklistDeleteModal = bootstrap.Modal.getOrCreateInstance(modalEl);
    dcmtChecklistDeleteModal.show();
}

function dcmtDeleteChecklistItem() {
    if (!dcmtCurrentChecklistId) return;
    const fd = new FormData();
    fd.append('id', String(dcmtCurrentChecklistId));
    fd.append('csrf_token', dcmtChecklistCsrf);
    fetch('delete_ajax.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(data) {
            if (!data.success) {
                alert(data.message || 'Error');
                return;
            }
            const row = document.querySelector('.dcmt-asana-item[data-item-id="' + dcmtCurrentChecklistId + '"]');
            if (row) {
                const wasDone = row.classList.contains('is-completed');
                row.remove();
                dcmtTotalCount = Math.max(0, dcmtTotalCount - 1);
                if (wasDone) dcmtDoneCount = Math.max(0, dcmtDoneCount - 1);
                dcmtUpdateProgress();
            }
            if (dcmtChecklistDeleteModal) dcmtChecklistDeleteModal.hide();
            const list = document.getElementById('dcmtChecklistList');
            if (list && !list.querySelector('.dcmt-asana-item')) {
                window.location.reload();
            }
        })
        .catch(function() {
            alert(<?php echo json_encode(trans('patient_checklist', 'database_error')); ?>);
        });
}
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
