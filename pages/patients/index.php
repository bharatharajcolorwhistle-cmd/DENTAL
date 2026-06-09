<?php
/**
 * Patients List Page
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
$status = isset($_GET['status']) ? dcmt_sanitize_input($_GET['status']) : '';
$today = new DateTime();
$birthday_mmdd = $today->format('m-d');

// Pagination
$page = max(1, intval($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$where_conditions = [];
$params = [];

if (!empty($search)) {
    $where_conditions[] = "(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ? OR p.dcmt_email LIKE ?)";
    $params[] = "%$search%";
    $params[] = "%$search%";
    $params[] = "%$search%";
}

if (!empty($status)) {
    $where_conditions[] = "p.dcmt_status = ?";
    $params[] = $status;
}

$where_clause = !empty($where_conditions) ? 'WHERE ' . implode(' AND ', $where_conditions) : '';

try {
    $count_sql = "SELECT COUNT(*) FROM dcmt_patients p $where_clause";
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
    $patient_cols = dcmt_patient_select_columns_without_odontogram('p', $dcmt_pdo);
    $birthday_order_sql = "DATE_FORMAT(p.dcmt_date_of_birth, '%m-%d') = ?";
    try {
        $bday_col = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_birthday_mmdd'");
        if ($bday_col && $bday_col->rowCount() > 0) {
            $birthday_order_sql = 'p.dcmt_birthday_mmdd = ?';
        }
    } catch (PDOException $e) {
        // keep DATE_FORMAT fallback
    }

    $has_income_table = false;
    $has_income_patient_id = false;
    $has_income_patient_name = false;
    $has_patient_notes_table = false;
    $has_patient_notes_patient_id = false;
    $has_patient_odontogram_table = false;
    $has_patient_odontogram_patient_id = false;

    try {
        $income_table = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_income'");
        $has_income_table = ($income_table && $income_table->rowCount() > 0);
        if ($has_income_table) {
            $income_pid_col = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_patient_id'");
            $income_pname_col = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_patient_name'");
            $has_income_patient_id = ($income_pid_col && $income_pid_col->rowCount() > 0);
            $has_income_patient_name = ($income_pname_col && $income_pname_col->rowCount() > 0);
        }

        $notes_table = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_patient_notes'");
        $has_patient_notes_table = ($notes_table && $notes_table->rowCount() > 0);
        if ($has_patient_notes_table) {
            $notes_pid_col = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_patient_notes LIKE 'dcmt_patient_id'");
            $has_patient_notes_patient_id = ($notes_pid_col && $notes_pid_col->rowCount() > 0);
        }

        $odont_table = $dcmt_pdo->query("SHOW TABLES LIKE 'dcmt_patient_odontogram'");
        $has_patient_odontogram_table = ($odont_table && $odont_table->rowCount() > 0);
        if ($has_patient_odontogram_table) {
            $odont_pid_col = $dcmt_pdo->query("SHOW COLUMNS FROM dcmt_patient_odontogram LIKE 'dcmt_patient_id'");
            $has_patient_odontogram_patient_id = ($odont_pid_col && $odont_pid_col->rowCount() > 0);
        }
    } catch (PDOException $e) {
        error_log("Patients schema check error: " . $e->getMessage());
    }

    $has_records_checks = [];
    if ($has_income_table && ($has_income_patient_id || $has_income_patient_name)) {
        if ($has_income_patient_id && $has_income_patient_name) {
            $has_records_checks[] = "EXISTS (
                SELECT 1 FROM dcmt_income i
                WHERE i.dcmt_patient_id = p.dcmt_id
                   OR (i.dcmt_patient_id IS NULL AND i.dcmt_patient_name = p.dcmt_patient_name)
                LIMIT 1
            )";
        } elseif ($has_income_patient_id) {
            $has_records_checks[] = "EXISTS (
                SELECT 1 FROM dcmt_income i
                WHERE i.dcmt_patient_id = p.dcmt_id
                LIMIT 1
            )";
        } else {
            $has_records_checks[] = "EXISTS (
                SELECT 1 FROM dcmt_income i
                WHERE i.dcmt_patient_name = p.dcmt_patient_name
                LIMIT 1
            )";
        }
    }

    if ($has_patient_notes_table && $has_patient_notes_patient_id) {
        $has_records_checks[] = "EXISTS (
            SELECT 1 FROM dcmt_patient_notes n
            WHERE n.dcmt_patient_id = p.dcmt_id
            LIMIT 1
        )";
    }

    if ($has_patient_odontogram_table && $has_patient_odontogram_patient_id) {
        $has_records_checks[] = "EXISTS (
            SELECT 1 FROM dcmt_patient_odontogram o
            WHERE o.dcmt_patient_id = p.dcmt_id
            LIMIT 1
        )";
    }

    $has_records_sql = !empty($has_records_checks)
        ? '(' . implode(' OR ', $has_records_checks) . ')'
        : '0';

    $list_sql = "SELECT {$patient_cols},
        {$has_records_sql} AS has_records
        FROM dcmt_patients p
        $where_clause
        ORDER BY
        CASE
            WHEN p.dcmt_date_of_birth IS NOT NULL
             AND {$birthday_order_sql} THEN 0
            ELSE 1
        END ASC,
        p.dcmt_created_at DESC
        LIMIT ? OFFSET ?";
    $list_params = $params;
    $list_params[] = $birthday_mmdd;
    $list_params[] = $per_page;
    $list_params[] = $offset;

    $stmt = $dcmt_pdo->prepare($list_sql);
    $stmt->execute($list_params);
    $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($patients as &$patient) {
        $patient['has_records'] = !empty($patient['has_records']);
        $patient['is_birthday'] = false;
        if (!empty($patient['dcmt_date_of_birth'])) {
            $dob = new DateTime($patient['dcmt_date_of_birth']);
            $patient['is_birthday'] = ($dob->format('m-d') === $birthday_mmdd);
        }
    }
    unset($patient);
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
            <div class="col-md-6">
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
                    <i class="fas fa-upload me-1"></i><?php echo trans('patient', 'import'); ?>
                </a>
                <a href="import_dentalink.php" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-file-upload me-1"></i><?php echo trans('patient', 'import_dentalink_short'); ?>
                </a>
                <button type="button" class="dcmt-add-form-view-all-link" onclick="exportPatientsToCSV()">
                    <i class="fas fa-download me-1"></i><?php echo trans('common', 'export'); ?>
                </button>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div class="border py-2 px-3 small mb-3">
            <div class="d-flex gap-2 mb-2">
                <i class="fas fa-search text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                <div><?php echo trans('patient', 'patients_index_help_search'); ?></div>
            </div>
            <div class="d-flex gap-2 mb-0">
                <i class="fas fa-user-plus text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                <div><?php echo trans('patient', 'patients_index_help_actions'); ?></div>
            </div>
        </div>
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
                                <td class="dcmt-text-capitalize">
                                    <?php
                                    if ($gender !== '') {
                                        $gender_key = strtolower(trim((string) $gender));
                                        if (in_array($gender_key, ['male', 'female', 'other'], true)) {
                                            echo htmlspecialchars(trans('patient', $gender_key));
                                        } else {
                                            echo htmlspecialchars($gender);
                                        }
                                    } else {
                                        echo '-';
                                    }
                                    ?>
                                </td>
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
                                    <div class="d-inline-flex align-items-center gap-2">
                                        <a href="../patient_notes/index.php?patient_id=<?php echo $patient['dcmt_id']; ?>"
                                            title="<?php echo trans('patient_note', 'view_all_notes'); ?>">
                                            <i class="far fa-file-alt text-info" style="font-size: 1.2rem;"></i>
                                        </a>
                                        <a href="../patient_odontogram/edit.php?patient_id=<?php echo $patient['dcmt_id']; ?>"
                                            title="<?php echo trans('patient_note', 'add_odontogram'); ?>">
                                            <i class="fas fa-tooth text-info" style="font-size: 1.2rem;"></i>
                                        </a>
                                        <a href="../patient_notes/add.php?patient_id=<?php echo $patient['dcmt_id']; ?>"
                                            title="<?php echo trans('patient_note', 'add_note'); ?>">
                                            <i class="fas fa-plus text-primary" style="font-size: 1.2rem;"></i>
                                        </a>
                                    </div>
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
                                        <?php if (!empty($patient['is_birthday'])): ?>
                                            <button type="button" class="btn"
                                                onclick="sendBirthdayWish(<?php echo $patient['dcmt_id']; ?>, '<?php echo htmlspecialchars($patient['dcmt_patient_name'], ENT_QUOTES); ?>', '<?php echo htmlspecialchars($patient['dcmt_phone'], ENT_QUOTES); ?>')"
                                                title="<?php echo trans('patient', 'send_birthday_wish'); ?>">
                                                <i class="fas fa-birthday-cake text-warning"></i>
                                            </button>
                                        <?php endif; ?>
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

    function sendBirthdayWish(patientId, patientName, phone) {
        if (!phone) {
            dcmtShowPatientAlert('warning', '<?php echo trans('patient', 'no_phone_for_birthday'); ?>');
            return;
        }

        const cleanPhone = phone.replace(/\D/g, '');
        if (!cleanPhone) {
            dcmtShowPatientAlert('warning', '<?php echo trans('patient', 'invalid_phone_for_birthday'); ?>');
            return;
        }

        const birthdayMessage = '<?php echo trans('patient', 'birthday_wish_message'); ?>';
        const personalizedMessage = birthdayMessage.replace('{patient_name}', patientName);
        
        const whatsappUrl = `https://web.whatsapp.com/send?phone=${cleanPhone}&text=${encodeURIComponent(personalizedMessage)}`;
        
        window.open(whatsappUrl, '_blank');
        
        dcmtShowPatientAlert('success', '<?php echo trans('patient', 'birthday_wish_sent'); ?>');
    }

    function exportPatientsToCSV() {
        const params = new URLSearchParams(window.location.search);
        const link = document.createElement('a');
        link.href = 'export.php?' + params.toString();
        link.download = 'patients.csv';
        document.body.appendChild(link);
        link.click();
        document.body.removeChild(link);
    }
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
