<?php
/**
 * Patients List Page
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
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(dcmt_patient_name LIKE ? OR dcmt_phone LIKE ? OR dcmt_email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status)) {
    $where_conditions[] = "dcmt_status = ?";
    $params[] = $status;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $count_sql = "SELECT COUNT(*) FROM dcmt_patients $where_clause";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = (int) ceil($total_records / $per_page);
} catch (PDOException $e) {
    error_log("Patients count error: " . $e->getMessage());
    $total_records = 0;
    $total_pages = 0;
}

try {
    $list_sql = "SELECT * FROM dcmt_patients $where_clause ORDER BY dcmt_created_at DESC LIMIT ? OFFSET ?";
    $list_params = $params;
    $list_params[] = $per_page;
    $list_params[] = $offset;

    $stmt = $dcmt_pdo->prepare($list_sql);
    $stmt->execute($list_params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    // Check if each patient has records (income or notes)
    foreach ($patients as &$patient) {
        $patient_id = $patient['dcmt_id'];
        $patient_name = $patient['dcmt_patient_name'] ?? '';
        $has_records = false;

        // Check for income records
        try {
            $income_check = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_income WHERE dcmt_patient_id = ? OR (dcmt_patient_id IS NULL AND dcmt_patient_name = ?)");
            $income_check->execute([$patient_id, $patient_name]);
            $income_count = (int) $income_check->fetchColumn();
            if ($income_count > 0) {
                $has_records = true;
            }
        } catch (PDOException $e) {
            error_log("Error checking income records for patient $patient_id: " . $e->getMessage());
        }

        // Check for patient notes if no income records found
        if (!$has_records) {
            try {
                $notes_check = $dcmt_pdo->prepare("SELECT COUNT(*) FROM dcmt_patient_notes WHERE dcmt_patient_id = ?");
                $notes_check->execute([$patient_id]);
                $notes_count = (int) $notes_check->fetchColumn();
                if ($notes_count > 0) {
                    $has_records = true;
                }
            } catch (PDOException $e) {
                error_log("Error checking patient notes for patient $patient_id: " . $e->getMessage());
            }
        }

        $patient['has_records'] = $has_records;
    }
    unset($patient); // Break reference
} catch (PDOException $e) {
    error_log("Patients fetch error: " . $e->getMessage());
    $patients = [];
    dcmt_show_message(trans('patient', 'database_error'), 'danger');
}

$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<meta name="csrf-token" content="<?php echo $csrf_token; ?>">

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-5">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                    value="<?php echo htmlspecialchars($search); ?>"
                    placeholder="<?php echo trans('patient', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-3">
                <label for="status" class="form-label"><?php echo trans('common', 'status'); ?></label>
                <select class="form-select dcmt-filter-field" id="status" name="status">
                    <option value=""><?php echo trans('patient', 'all_status'); ?></option>
                    <option value="active" <?php echo $status === 'active' ? 'selected' : ''; ?>>
                        <?php echo trans('common', 'active'); ?></option>
                    <option value="inactive" <?php echo $status === 'inactive' ? 'selected' : ''; ?>>
                        <?php echo trans('common', 'inactive'); ?></option>
                </select>
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
                    <?php echo trans('patient', 'patients'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('patient', 'showing'); ?>: <span
                            style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                        <?php echo trans('patient', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient', 'add_patient'); ?></a>
                <a href="import.php" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-upload me-1"></i><?php echo trans('patient', 'import_patients'); ?>
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($patients)): ?>
            <div class="text-center py-4">
                <i class="fas fa-user-injured fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('patient', 'no_patients_found'); ?></h5>
                <p class="text-muted"><?php echo trans('patient', 'start_adding_patient'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo trans('patient', 'patient'); ?></th>
                            <th><?php echo trans('patient', 'phone'); ?></th>
                            <th><?php echo trans('patient', 'gender'); ?></th>
                            <th><?php echo trans('patient', 'age'); ?></th>
                            <th><?php echo trans('common', 'status'); ?></th>
                            <th><?php echo trans('common', 'created_on'); ?></th>
                            <th><?php echo trans('patient', 'clinical_history'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $patient): ?>
                            <tr>
                                <td>
                                    <?php
                                    echo htmlspecialchars($patient['dcmt_patient_name'] ?? '-');
                                    ?>
                                </td>
                                <?php
                                $phone = $patient['dcmt_phone'] ?? '';
                                $email = $patient['dcmt_email'] ?? '';
                                $gender = $patient['dcmt_gender'] ?? '';
                                $age = $patient['dcmt_age'] ?? '';
                                $status_value = $patient['dcmt_status'] ?? '';
                                ?>
                                <td>
                                    <?php
                                    if ($phone) {
                                        $digits = preg_replace('/\D+/', '', $phone);
                                        if ($digits !== '') {
                                            $wa_link = 'https://wa.me/' . $digits;
                                            echo '<a href="' . htmlspecialchars($wa_link) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($phone) . '</a>';
                                        } else {
                                            echo htmlspecialchars($phone);
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
                                <td><?php echo htmlspecialchars($gender ?: '-'); ?></td>
                                <td><?php echo $age !== '' ? htmlspecialchars($age) : '-'; ?></td>
                                <td>
                                    <?php $status_safe = $status_value === 'active' ? 'active' : 'inactive'; ?>
                                    <span class="text-<?php echo $status_safe === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo trans('common', $status_safe); ?>
                                    </span>
                                </td>
                                <td>
                                    <span title="<?php echo $patient['dcmt_created_at']; ?>">
                                        <?php echo dcmt_format_date($patient['dcmt_created_at']); ?>
                                    </span>
                                </td>
                                <td>
                                    <a href="../patient_notes/index.php?patient_id=<?php echo $patient['dcmt_id']; ?>"
                                        title="<?php echo trans('patient_note', 'view_all_notes'); ?>">
                                        <i class="far fa-file-alt text-info" style="font-size: 1.2rem;"></i>
                                    </a>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="view.php?id=<?php echo $patient['dcmt_id']; ?>" class="btn"
                                            title="<?php echo trans('common', 'view'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </a>
                                        <a href="edit.php?id=<?php echo $patient['dcmt_id']; ?>" class="btn"
                                            title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php
                                        $patient_has_records = $patient['has_records'] ?? false;
                                        $patient_full_name = htmlspecialchars($patient['dcmt_patient_name'] ?? '', ENT_QUOTES);
                                        if ($patient_has_records): ?>
                                            <button type="button" class="btn dcmt-disabled-lock-btn"
                                                title="<?php echo trans('patient', 'cannot_delete_has_records'); ?>" disabled>
                                                <i class="fas fa-lock text-secondary"></i>
                                            </button>
                                        <?php else: ?>
                                            <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                onclick="dcmtConfirmDeletePatient(<?php echo $patient['dcmt_id']; ?>, '<?php echo $patient_full_name; ?>')">
                                                <i class="fas fa-trash text-danger"></i>
                                            </button>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('patient', 'patients'); ?> pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link"
                                    href="?page=<?php echo $i; ?>&search=<?php echo urlencode($search); ?>&status=<?php echo urlencode($status); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<script>
    const dcmtPatientTranslations = {
        confirm_deletion: '<?php echo trans('common', 'confirm_deletion'); ?>',
        warning: '<?php echo trans('common', 'warning'); ?>',
        delete_confirmation_message: '<?php echo trans('patient', 'delete_confirmation_message'); ?>',
        cancel: '<?php echo trans('common', 'cancel'); ?>',
        yes_delete: '<?php echo trans('common', 'yes_delete'); ?>' || '<?php echo trans('common', 'delete'); ?>',
        patient: '<?php echo trans('patient', 'patient'); ?>'
    };

    let dcmtCurrentPatientId = null;

    function dcmtConfirmDeletePatient(patientId, patientName) {
        dcmtCurrentPatientId = patientId;

        // Remove any existing modal
        const existingModal = document.getElementById('dcmtPatientDeleteModal');
        if (existingModal) {
            existingModal.remove();
        }

        const modalHtml = `
        <div class="modal fade" id="dcmtPatientDeleteModal" tabindex="-1" aria-labelledby="dcmtPatientDeleteModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="dcmtPatientDeleteModalLabel">
                            <i class="fas fa-exclamation-triangle me-2"></i>${dcmtPatientTranslations.confirm_deletion}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-circle me-2"></i>${dcmtPatientTranslations.warning}
                            </h6>
                            <p class="mb-1">${dcmtPatientTranslations.delete_confirmation_message}</p>
                            <p class="fw-semibold mb-0">${patientName}</p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times me-1"></i>${dcmtPatientTranslations.cancel}
                        </button>
                        <button type="button" class="btn btn-danger" id="dcmtConfirmPatientDeleteBtn">
                            <i class="fas fa-trash me-1"></i>${dcmtPatientTranslations.yes_delete}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;

        document.body.insertAdjacentHTML('beforeend', modalHtml);
        const modal = new bootstrap.Modal(document.getElementById('dcmtPatientDeleteModal'));
        modal.show();

        document.getElementById('dcmtPatientDeleteModal').addEventListener('hidden.bs.modal', function () {
            this.remove();
        });

        document.getElementById('dcmtConfirmPatientDeleteBtn').addEventListener('click', dcmtDeletePatient);
    }

    function dcmtDeletePatient() {
        if (!dcmtCurrentPatientId) return;

        const confirmBtn = document.getElementById('dcmtConfirmPatientDeleteBtn');
        const originalText = confirmBtn.innerHTML;
        confirmBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-2"></i><?php echo trans('common', 'delete'); ?>';
        confirmBtn.disabled = true;

        const csrfToken = document.querySelector('meta[name="csrf-token"]')?.getAttribute('content') || '';
        const formData = new FormData();
        formData.append('id', dcmtCurrentPatientId);
        formData.append('csrf_token', csrfToken);

        fetch('delete_ajax.php', {
            method: 'POST',
            body: formData
        })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    dcmtShowPatientAlert('success', data.message);
                    const row = document.querySelector(`button[onclick*="${dcmtCurrentPatientId}"]`)?.closest('tr');
                    if (row) row.remove();
                    const modalEl = document.getElementById('dcmtPatientDeleteModal');
                    const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
                    if (modal) modal.hide();
                } else {
                    dcmtShowPatientAlert('danger', data.message || '<?php echo trans('patient', 'delete_failed'); ?>');
                }
            })
            .catch(() => dcmtShowPatientAlert('danger', '<?php echo trans('patient', 'delete_failed'); ?>'))
            .finally(() => {
                confirmBtn.innerHTML = originalText;
                confirmBtn.disabled = false;
            });
    }

    function dcmtShowPatientAlert(type, message) {
        document.querySelectorAll('.alert-dismissible').forEach(alert => alert.remove());
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.insertBefore(alertDiv, mainContent.firstChild);
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
