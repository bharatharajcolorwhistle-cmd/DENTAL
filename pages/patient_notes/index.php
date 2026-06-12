<?php
/**
 * Patient Notes List Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';

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
 

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

// Resolve a single patient from filter or search (for shortcuts and odontogram row).
$clinical_history_patient_id = $patient_id > 0 ? $patient_id : 0;
if ($clinical_history_patient_id <= 0 && $search !== '') {
    try {
        $match_stmt = $dcmt_pdo->prepare("
            SELECT dcmt_id
            FROM dcmt_patients
            WHERE dcmt_status = 'active'
              AND dcmt_patient_name LIKE ?
            ORDER BY dcmt_patient_name ASC
            LIMIT 2
        ");
        $match_stmt->execute(["%$search%"]);
        $matched_patient_ids = $match_stmt->fetchAll(PDO::FETCH_COLUMN);
        if (count($matched_patient_ids) === 1) {
            $clinical_history_patient_id = (int) $matched_patient_ids[0];
        }
    } catch (PDOException $e) {
        error_log("Patient match lookup error: " . $e->getMessage());
    }
}
$add_note_patient_id = $clinical_history_patient_id;

$dcmt_clinical_odontogram = null;
$dcmt_clinical_odontogram_patient = null;
$dcmt_clinical_odontogram_has_data = false;
$dcmt_show_odontogram_in_list = $clinical_history_patient_id > 0 && $page === 1;

if ($clinical_history_patient_id > 0) {
    try {
        $pstmt = $dcmt_pdo->prepare('SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_created_at FROM dcmt_patients WHERE dcmt_id = ?');
        $pstmt->execute([$clinical_history_patient_id]);
        $dcmt_clinical_odontogram_patient = $pstmt->fetch(PDO::FETCH_ASSOC) ?: null;
    } catch (PDOException $e) {
        error_log('Clinical history patient lookup: ' . $e->getMessage());
    }
    $dcmt_clinical_odontogram = dcmt_fetch_patient_odontogram_record($dcmt_pdo, $clinical_history_patient_id);
    $dcmt_clinical_odontogram_has_data = dcmt_patient_odontogram_has_saved_record($dcmt_pdo, $clinical_history_patient_id);
}

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

$display_total_records = $total_records;
if ($clinical_history_patient_id > 0) {
    $display_total_records = $total_records + 1;
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
$dcmt_is_assistant = (($dcmt_current_user['dcmt_role'] ?? '') === 'assistant');

require_once __DIR__ . '/../../includes/header.php';
?>

<link href="../../assets/css/select2.min.css" rel="stylesheet">
<meta name="csrf-token" content="<?php echo $csrf_token; ?>">
 

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
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
            <div class="col-md-3">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('patient_note', 'search_placeholder'); ?>">
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
                        (<?php echo trans('patient_note', 'showing'); ?>: <span style="color: #007bff; font-weight: 600;"><?php echo number_format($display_total_records); ?></span> <?php echo trans('patient_note', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex flex-wrap gap-2 align-items-center">
                <?php if ($clinical_history_patient_id > 0): ?>
                    <a href="print_clinical.php?patient_id=<?php echo $clinical_history_patient_id; ?>"
                       class="dcmt-add-form-view-all-link"
                       target="_blank" rel="noopener noreferrer">
                        <i class="fas fa-print me-1"></i><?php echo trans('patient_note', 'print_clinical_history'); ?>
                    </a>
                <?php endif; ?>
                <a href="../patient_odontogram/edit.php<?php echo $clinical_history_patient_id > 0 ? '?patient_id=' . $clinical_history_patient_id : ''; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-tooth me-1"></i><?php echo $dcmt_clinical_odontogram_has_data ? trans('patient_note', 'edit_odontogram') : trans('patient_note', 'add_odontogram'); ?>
                </a>
                <a href="add.php<?php echo $add_note_patient_id > 0 ? '?patient_id=' . $add_note_patient_id : ''; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-sticky-note me-1"></i><?php echo trans('patient_note', 'add_note'); ?>
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($notes) && !$dcmt_show_odontogram_in_list): ?>
            <div class="text-center py-4">
                <i class="fas fa-sticky-note fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('patient_note', 'no_notes_found'); ?></h5>
                <p class="text-muted"><?php echo trans('patient_note', 'start_adding_note'); ?></p>
            </div>
        <?php else: ?>
            <div class="dcmt-note-list">
                <?php if ($dcmt_show_odontogram_in_list): ?>
                    <?php
                    $dcmt_odontogram_card_patient_id = $clinical_history_patient_id;
                    $dcmt_odontogram_card_has_data = $dcmt_clinical_odontogram_has_data;
                    $dcmt_odontogram_card_record = $dcmt_clinical_odontogram;
                    $dcmt_odontogram_card_patient_name = $dcmt_clinical_odontogram_patient['dcmt_patient_name'] ?? '';
                    $dcmt_odontogram_card_patient_created_at = $dcmt_clinical_odontogram_patient['dcmt_created_at'] ?? null;
                    $dcmt_odontogram_card_show_patient_name = ($patient_id <= 0);
                    $dcmt_odontogram_card_show_when_empty = true;
                    include __DIR__ . '/../../includes/patient_odontogram_history_card.php';
                    ?>
                <?php endif; ?>
                <?php foreach ($notes as $note): ?>
                    <?php
                        $patient_name = $note['dcmt_patient_name'] ?? '';
                        $topic = trim((string)($note['dcmt_topic'] ?? ''));
                        $title_fallback = trim(substr((string)($note['dcmt_note_text'] ?? ''), 0, 40));
                        $title_text = $topic !== '' ? $topic : ($title_fallback !== '' ? $title_fallback : trans('patient_note', 'note'));
                        $date_value = $note['dcmt_note_date'] ?? $note['dcmt_created_at'];
                        $date_display = dcmt_format_date($date_value);
                        $note_text = (string)($note['dcmt_note_text'] ?? '');
                        $note_text_length = function_exists('mb_strlen') ? mb_strlen($note_text) : strlen($note_text);
                        $should_show_toggle = $note_text_length > 140;
                    ?>
                    <div class="dcmt-note-card">
                        <div class="dcmt-note-card-header">
                            <div>
                                <?php if (!empty($patient_name)): ?>
                                    <div class="text-muted" style="font-size: 13px; font-weight: 500;">
                                        <?php echo htmlspecialchars($patient_name); ?>
                                    </div>
                                <?php endif; ?>
                                <div class="dcmt-note-card-title">
                                    <?php echo htmlspecialchars($title_text); ?>
                                </div>
                            </div>
                            <div class="dcmt-note-card-date">
                                <?php echo trans('common', 'date'); ?>: <?php echo $date_display; ?>
                            </div>
                        </div>
                        <div class="dcmt-note-card-body" title="<?php echo htmlspecialchars($note_text); ?>"><?php echo nl2br(htmlspecialchars($note_text)); ?></div>
                        <div class="dcmt-note-card-footer">
                            <?php if ($should_show_toggle): ?>
                                <button
                                    type="button"
                                    class="dcmt-add-form-view-all-link dcmt-note-toggle"
                                    aria-expanded="false"
                                ><?php echo trans('patient_note', 'read_more'); ?></button>
                            <?php else: ?>
                                <span></span>
                            <?php endif; ?>
                            <div class="btn-group btn-group-sm btn-group-action dcmt-note-card-actions" role="group">
                                <a href="view.php?id=<?php echo $note['dcmt_id']; ?>" 
                                   class="btn" title="<?php echo trans('common', 'view'); ?>">
                                    <img src="../../assets/images/view-filled.svg" alt="View">
                                </a>
                                <a href="edit.php?id=<?php echo $note['dcmt_id']; ?>" 
                                   class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                    <img src="../../assets/images/edit.svg" alt="Edit">
                                </a>
                                <?php if (!$dcmt_is_assistant): ?>
                                    <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                            onclick="dcmtShowNoteDeleteModal(<?php echo (int) $note['dcmt_id']; ?>, <?php echo htmlspecialchars(json_encode(preg_replace('/\s+/u', ' ', mb_substr((string) ($note['dcmt_note_text'] ?? ''), 0, 50)), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT), ENT_QUOTES, 'UTF-8'); ?>)">
                                        <img src="../../assets/images/delete.svg" alt="Delete">
                                    </button>
                                <?php endif; ?>
                            </div>
                        </div>
                    </div>
                <?php endforeach; ?>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('patient_note', 'patient_notes'); ?> pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => 1])); ?>" title="<?php echo trans('common', 'first_page'); ?>">
                                    <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>" title="<?php echo trans('common', 'previous'); ?>">
                                    <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>">
                                    <?php echo $i; ?>
                                </a>
                            </li>
                        <?php endfor; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>" title="<?php echo trans('common', 'next'); ?>">
                                    <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                </a>
                            </li>
                        <?php endif; ?>

                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $total_pages])); ?>" title="<?php echo trans('common', 'last_page'); ?>">
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

<?php if (!$dcmt_is_assistant): ?>
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
<?php endif; ?>

<script src="../../assets/js/select2.min.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    // Initialize Select2 on patient filter field
    if (typeof $ !== 'undefined') {
        $('#patient_id').select2({
            placeholder: '<?php echo addslashes(trans('patient_note', 'all_patients')); ?>',
            allowClear: true,
            width: '100%',
            minimumResultsForSearch: 0
        });
        
        // Apply the reusable class to the Select2 container
        $('#patient_id').on('select2:select select2:clear', function() {
            $('#patient_id').next('.select2-container').addClass('dcmt-filter-select2');
        });
        
        // Apply class immediately after initialization
        setTimeout(function() {
            $('#patient_id').next('.select2-container').addClass('dcmt-filter-select2');
        }, 100);
        
        $(document).on('select2:open', function() {
            const input = document.querySelector('.select2-container--open .select2-search__field');
            if (input) input.focus();
        });
    }
});
</script>

<script>
document.addEventListener('click', function(e) {
    const toggleBtn = e.target.closest('.dcmt-note-toggle');
    if (!toggleBtn) return;

    const card = toggleBtn.closest('.dcmt-note-card');
    const body = card ? card.querySelector('.dcmt-note-card-body') : null;
    if (!body) return;

    const isExpanded = body.classList.toggle('dcmt-note-card-body--expanded');
    if (isExpanded) {
        body.style.maxHeight = body.scrollHeight + 'px';
    } else {
        body.style.maxHeight = '';
    }
    toggleBtn.setAttribute('aria-expanded', isExpanded ? 'true' : 'false');
    toggleBtn.textContent = isExpanded ? '<?php echo addslashes(trans('patient_note', 'read_less')); ?>' : '<?php echo addslashes(trans('patient_note', 'read_more')); ?>';
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

