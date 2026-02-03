<?php
/**
 * Patient Notes List Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Validate session
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

// Filters
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$patient_id = isset($_GET['patient_id']) ? (int)$_GET['patient_id'] : 0;
$date_from = isset($_GET['date_from']) ? dcmt_sanitize_input($_GET['date_from']) : '';
$date_to = isset($_GET['date_to']) ? dcmt_sanitize_input($_GET['date_to']) : '';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(pn.dcmt_note_text LIKE ? OR pn.dcmt_topic LIKE ? OR p.dcmt_patient_name LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if ($patient_id > 0) {
    $where_conditions[] = "pn.dcmt_patient_id = ?";
    $params[] = $patient_id;
}

if (!empty($date_from)) {
    $where_conditions[] = "DATE(pn.dcmt_created_at) >= ?";
    $params[] = $date_from;
}

if (!empty($date_to)) {
    $where_conditions[] = "DATE(pn.dcmt_created_at) <= ?";
    $params[] = $date_to;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $count_sql = "
        SELECT COUNT(*) 
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_patients p ON pn.dcmt_patient_id = p.dcmt_id
        $where_clause
    ";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int)$count_stmt->fetchColumn();
    $total_pages = (int)ceil($total_records / $per_page);
} catch (PDOException $e) {
    error_log("Patient notes count error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 0;
}

try {
    $list_sql = "
        SELECT pn.*, 
               p.dcmt_patient_name, 
               p.dcmt_phone,
               u.dcmt_full_name as created_by_name
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_patients p ON pn.dcmt_patient_id = p.dcmt_id
        LEFT JOIN dcmt_users u ON pn.dcmt_created_by = u.dcmt_username
        $where_clause
        ORDER BY pn.dcmt_created_at DESC
        LIMIT ? OFFSET ?
    ";
    $list_params = $params;
    $list_params[] = $per_page;
    $list_params[] = $offset;

    $stmt = $dcmt_pdo->prepare($list_sql);
    $stmt->execute($list_params);
    $notes = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Patient notes fetch error: " . $e->getMessage());
    $notes = [];
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
}

// Get all patients for filter dropdown
$all_patients = [];
try {
    $stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_status FROM dcmt_patients WHERE dcmt_status = 'active' ORDER BY dcmt_patient_name");
    $all_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching patients: " . $e->getMessage());
}

$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<link href="../../assets/css/select2.min.css" rel="stylesheet">
<meta name="csrf-token" content="<?php echo $csrf_token; ?>">

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-3">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('patient_note', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-3">
                <label for="patient_id" class="form-label"><?php echo trans('patient', 'patient'); ?></label>
                <select class="form-select dcmt-filter-field" id="patient_id" name="patient_id">
                    <option value=""><?php echo trans('patient_note', 'all_patients'); ?></option>
                    <?php foreach ($all_patients as $pat): ?>
                        <option value="<?php echo $pat['dcmt_id']; ?>" <?php echo $patient_id == $pat['dcmt_id'] ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($pat['dcmt_patient_name'] ?? ''); ?>
                            <?php if (!empty($pat['dcmt_phone'])): ?>
                                - <?php echo htmlspecialchars($pat['dcmt_phone']); ?>
                            <?php endif; ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-2">
                <label for="date_from" class="form-label"><?php echo trans('patient_note', 'date_from'); ?></label>
                <input type="date" class="form-control dcmt-filter-field" id="date_from" name="date_from"
                       value="<?php echo htmlspecialchars($date_from); ?>">
            </div>
            <div class="col-md-2">
                <label for="date_to" class="form-label"><?php echo trans('patient_note', 'date_to'); ?></label>
                <input type="date" class="form-control dcmt-filter-field" id="date_to" name="date_to"
                       value="<?php echo htmlspecialchars($date_to); ?>">
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
                    <i class="fas fa-sticky-note dcmt-view-card-title-icon"></i><?php echo trans('patient_note', 'patient_notes'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('patient_note', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span> <?php echo trans('patient_note', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient_note', 'add_note'); ?></a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($notes)): ?>
            <div class="text-center py-4">
                <i class="fas fa-sticky-note fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('patient_note', 'no_notes_found'); ?></h5>
                <p class="text-muted"><?php echo trans('patient_note', 'start_adding_note'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('patient', 'patient'); ?></th>
                            <th><?php echo trans('patient_note', 'note_date'); ?></th>
                            <th><?php echo trans('patient_note', 'topic'); ?></th>
                            <th><?php echo trans('patient_note', 'note'); ?></th>
                            <th><?php echo trans('common', 'created_by'); ?></th>
                            <th><?php echo trans('common', 'created_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($notes as $note): ?>
                            <tr>
                                <td>
                                    <a href="../patients/view.php?id=<?php echo $note['dcmt_patient_id']; ?>" class="text-decoration-none">
                                        <?php 
                                        $patient_name = $note['dcmt_patient_name'] ?? '';
                                        echo htmlspecialchars($patient_name ?: '-');
                                        ?>
                                    </a>
                                    <?php if (!empty($note['dcmt_phone'])): ?>
                                        <br><small class="text-muted"><?php echo htmlspecialchars($note['dcmt_phone']); ?></small>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span title="<?php echo $note['dcmt_note_date'] ?? $note['dcmt_created_at']; ?>">
                                        <?php echo dcmt_format_date($note['dcmt_note_date'] ?? $note['dcmt_created_at']); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($note['dcmt_topic'])): ?>
                                        <strong><?php echo htmlspecialchars($note['dcmt_topic']); ?></strong>
                                    <?php else: ?>
                                        <span class="text-muted">-</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div style="max-width: 400px; overflow: hidden; text-overflow: ellipsis; white-space: nowrap;" 
                                         title="<?php echo htmlspecialchars($note['dcmt_note_text']); ?>">
                                        <?php echo htmlspecialchars($note['dcmt_note_text']); ?>
                                    </div>
                                </td>
                                <td><?php echo htmlspecialchars($note['created_by_name'] ?? $note['dcmt_created_by']); ?></td>
                                <td>
                                    <span title="<?php echo $note['dcmt_created_at']; ?>">
                                        <?php echo dcmt_format_date($note['dcmt_created_at'], DCMT_DATETIME_FORMAT); ?>
                                    </span>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $note['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <a href="edit.php?id=<?php echo $note['dcmt_id']; ?>" 
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                onclick="dcmtShowNoteDeleteModal(<?php echo $note['dcmt_id']; ?>, '<?php echo htmlspecialchars(addslashes(substr($note['dcmt_note_text'], 0, 50))); ?>')">
                                            <img src="../../assets/images/delete.svg" alt="Delete">
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('patient_note', 'patient_notes'); ?> pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&patient_id=<?php echo $patient_id; ?>&date_from=<?php echo urlencode($date_from); ?>&date_to=<?php echo urlencode($date_to); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<!-- Delete Confirmation Modal -->
<div class="modal fade" id="dcmtNoteDeleteModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-danger text-white">
                <h5 class="modal-title"><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('common', 'delete'); ?></h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-warning mb-0">
                    <h6 class="alert-heading">
                        <i class="fas fa-exclamation-triangle"></i> <?php echo trans('common', 'warning'); ?>!
                    </h6>
                    <p class="mb-0"><?php echo trans('patient_note', 'delete_confirmation_message'); ?></p>
                </div>
                <p class="fw-semibold mt-3 mb-0" id="dcmtNotePreview"></p>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                <button type="button" class="btn btn-danger" id="dcmtConfirmNoteDeleteBtn">
                    <i class="fas fa-trash me-1"></i><?php echo trans('common', 'delete'); ?>
                </button>
            </div>
        </div>
    </div>
</div>

<script>
let dcmtNoteDeleteModal = null;
let dcmtCurrentNoteId = null;

document.addEventListener('DOMContentLoaded', function() {
    dcmtNoteDeleteModal = new bootstrap.Modal(document.getElementById('dcmtNoteDeleteModal'));
    document.getElementById('dcmtConfirmNoteDeleteBtn').addEventListener('click', dcmtDeleteNote);
});

function dcmtShowNoteDeleteModal(noteId, notePreview) {
    dcmtCurrentNoteId = noteId;
    const previewEl = document.getElementById('dcmtNotePreview');
    if (previewEl) {
        previewEl.textContent = notePreview + '...';
    }
    if (dcmtNoteDeleteModal) {
        dcmtNoteDeleteModal.show();
    }
}

function dcmtDeleteNote() {
    if (!dcmtCurrentNoteId) return;
    
    const confirmBtn = document.getElementById('dcmtConfirmNoteDeleteBtn');
    const originalText = confirmBtn.innerHTML;
    confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo trans('common', 'deleting'); ?>...';
    confirmBtn.disabled = true;
    
    const formData = new FormData();
    formData.append('id', dcmtCurrentNoteId);
    formData.append('csrf_token', document.querySelector('meta[name="csrf-token"]').getAttribute('content'));
    
    fetch('delete_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => response.json())
    .then(data => {
        if (data.success) {
            if (dcmtNoteDeleteModal) {
                dcmtNoteDeleteModal.hide();
            }
            location.reload();
        } else {
            alert(data.message || '<?php echo trans('patient_note', 'delete_failed'); ?>');
            confirmBtn.innerHTML = originalText;
            confirmBtn.disabled = false;
        }
    })
    .catch(error => {
        console.error('Error:', error);
        alert('<?php echo trans('common', 'error_occurred'); ?>');
        confirmBtn.innerHTML = originalText;
        confirmBtn.disabled = false;
    });
}
</script>

<script src="../../assets/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 on patient filter field
    if (typeof $ !== 'undefined') {
        $('#patient_id').select2({
            placeholder: '<?php echo addslashes(trans('patient_note', 'all_patients')); ?>',
            allowClear: true,
            width: '100%'
        });
        
        // Apply the reusable class to the Select2 container
        $('#patient_id').on('select2:select select2:clear', function() {
            $('#patient_id').next('.select2-container').addClass('dcmt-filter-select2');
        });
        
        // Apply class immediately after initialization
        setTimeout(function() {
            $('#patient_id').next('.select2-container').addClass('dcmt-filter-select2');
        }, 100);
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

