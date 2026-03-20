<?php
/**
 * View Patient Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_patients WHERE dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching patient: " . $e->getMessage());
    dcmt_show_message(trans('patient', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

// Safe field defaults to avoid notices on missing keys
$status_safe = ($patient['dcmt_status'] ?? '') === 'active' ? 'active' : 'inactive';
$patient_full_name = $patient['dcmt_patient_name'] ?? '';

// Patient notes search and pagination
$notes_search = isset($_GET['notes_search']) ? dcmt_sanitize_input($_GET['notes_search']) : '';
$notes_per_page = 10;
$notes_page = isset($_GET['notes_page']) ? max(1, (int) $_GET['notes_page']) : 1;
$patient_notes = [];
$notes_total = 0;
$notes_total_pages = 1;
$patient_notes_paginated = [];

try {
    $where = "pn.dcmt_patient_id = ?";
    $params = [$patient_id];
    if ($notes_search !== '') {
        $where .= " AND (pn.dcmt_note_text LIKE ? OR pn.dcmt_topic LIKE ?)";
        $search_param = '%' . $notes_search . '%';
        $params[] = $search_param;
        $params[] = $search_param;
    }

    $count_sql = "
        SELECT COUNT(*)
        FROM dcmt_patient_notes pn
        WHERE $where
    ";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $notes_total = (int) $count_stmt->fetchColumn();
    $notes_total_pages = max(1, (int) ceil($notes_total / $notes_per_page));
    if ($notes_page > $notes_total_pages) {
        $notes_page = $notes_total_pages;
    }
    $notes_offset = ($notes_page - 1) * $notes_per_page;

    $list_sql = "
        SELECT pn.*, u.dcmt_full_name as created_by_name
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_users u ON pn.dcmt_created_by = u.dcmt_username
        WHERE $where
        ORDER BY pn.dcmt_note_date DESC, pn.dcmt_created_at DESC
        LIMIT ? OFFSET ?
    ";
    $list_params = $params;
    $list_params[] = $notes_per_page;
    $list_params[] = $notes_offset;

    $stmt = $dcmt_pdo->prepare($list_sql);
    $stmt->execute($list_params);
    $patient_notes_paginated = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Error fetching patient notes for view: " . $e->getMessage());
}

// Patient income statistics
$patient_total_income = 0;
$patient_total_visits = 0;

try {
    $stats_sql = "
        SELECT 
            COUNT(*) as visits,
            COALESCE(SUM(COALESCE(i.dcmt_total_paid_amount, i.dcmt_paid_amount, 0)), 0) as total_income
        FROM dcmt_income i
        WHERE (i.dcmt_patient_id = ? OR (i.dcmt_patient_id IS NULL AND i.dcmt_patient_name = ?))
    ";
    $stats_stmt = $dcmt_pdo->prepare($stats_sql);
    $stats_stmt->execute([$patient_id, $patient_full_name]);
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    if ($stats) {
        $patient_total_visits = (int) ($stats['visits'] ?? 0);
        $patient_total_income = (float) ($stats['total_income'] ?? 0);
    }
} catch (PDOException $e) {
    error_log("Error fetching patient income statistics: " . $e->getMessage());
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-user-injured dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('patient', 'patient_profile'); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?></small>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <a href="../patient_notes/index.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-sticky-note me-1"></i><?php echo trans('patient_note', 'view_all_notes'); ?>
            </a>
            <a href="edit.php?id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('patient', 'back_to_patients'); ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="mb-4">
            <h5 class="mb-3"><i class="fas fa-user me-2"></i><?php echo trans('patient', 'section_personal'); ?></h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'full_name') ?: 'Full Name'; ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'gender'); ?>:</span>
                        <div class="dcmt-view-field-value text-capitalize"><?php echo htmlspecialchars($patient['dcmt_gender'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo ucfirst((string) trans('common', 'status')); ?>:</span>
                        <div class="dcmt-view-field-value text-<?php echo $status_safe === 'active' ? 'success' : 'secondary'; ?>">
                            <?php echo trans('common', $status_safe); ?>
                        </div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'date_of_birth'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo !empty($patient['dcmt_date_of_birth']) ? dcmt_format_date($patient['dcmt_date_of_birth']) : '-'; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'age'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo isset($patient['dcmt_age']) && $patient['dcmt_age'] !== null ? htmlspecialchars($patient['dcmt_age']) : '-'; ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'height'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo $patient['dcmt_height_cm'] !== null ? htmlspecialchars($patient['dcmt_height_cm']) . ' cm' : '-'; ?></div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'weight'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo $patient['dcmt_weight_kg'] !== null ? htmlspecialchars($patient['dcmt_weight_kg']) . ' kg' : '-'; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h5 class="mb-3"><i class="fas fa-address-book me-2"></i><?php echo trans('patient', 'section_contact'); ?></h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'email'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_email'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'phone'); ?>:</span>
                        <div class="dcmt-view-field-value">
                            <?php
                            $phone = $patient['dcmt_phone'] ?? '';
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
                        </div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('common', 'updated_on'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo dcmt_format_date($patient['dcmt_updated_at'], DCMT_DATETIME_FORMAT); ?></div>
                    </div>
                </div>
            </div>
            <div class="row">
                <div class="col-md-12">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'address'); ?>:</span>
                        <div class="dcmt-view-field-value">
                            <?php echo htmlspecialchars($patient['dcmt_address'] ?? '-'); ?>
                        </div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h5 class="mb-3"><i class="fas fa-notes-medical me-2"></i><?php echo trans('patient', 'section_medical'); ?></h5>
            <div class="row">
                <div class="col-md-6">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'allergies'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_allergies'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-6">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'medications'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_medications'] ?? '-'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <h5 class="mb-3"><i class="fas fa-phone-alt me-2"></i><?php echo trans('patient', 'section_emergency'); ?></h5>
            <div class="row">
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'emergency_contact_name'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_emergency_contact_name'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'emergency_contact_relation'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_emergency_contact_relation'] ?? '-'); ?></div>
                    </div>
                </div>
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'emergency_contact_phone'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo htmlspecialchars($patient['dcmt_emergency_contact_phone'] ?? '-'); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-2">
            <h5 class="mb-3"><i class="fas fa-sticky-note me-2"></i><?php echo trans('patient', 'section_other'); ?></h5>
            <div class="row">
                <div class="col-md-12">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'notes'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo !empty($patient['dcmt_notes']) ? nl2br(htmlspecialchars($patient['dcmt_notes'])) : '-'; ?></div>
                    </div>
                </div>
            </div>
            <div class="row mt-2">
                <div class="col-md-4">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('common', 'created_on'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo dcmt_format_date($patient['dcmt_created_at'], DCMT_DATETIME_FORMAT); ?></div>
                    </div>
                </div>
            </div>
        </div>

        <hr>

        <div class="mb-4">
            <h5 class="mb-3">
                <i class="fas fa-chart-line me-2"></i><?php echo trans('patient', 'patient_statistics'); ?>
            </h5>
            <div class="row">
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'total_income'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo dcmt_format_currency($patient_total_income); ?></div>
                    </div>
                </div>
                <div class="col-md-3">
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'total_visits'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo (int) $patient_total_visits; ?></div>
                    </div>
                </div>
            </div>
        </div>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-sticky-note me-2"></i><?php echo trans('patient_note', 'patient_notes'); ?>
                    <span class="badge bg-secondary ms-2"><?php echo (int) $notes_total; ?></span>
                </h5>
                <a href="../patient_notes/add.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-plus me-1"></i><?php echo trans('patient_note', 'add_note'); ?>
                </a>
            </div>

            <form method="GET" class="row g-2 align-items-end mb-3">
                <input type="hidden" name="id" value="<?php echo $patient_id; ?>">
                <div class="col-md-6">
                    <label for="notes_search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                    <input type="text"
                           class="form-control"
                           id="notes_search"
                           name="notes_search"
                           value="<?php echo htmlspecialchars($notes_search); ?>"
                           placeholder="<?php echo trans('patient_note', 'search_placeholder'); ?>">
                </div>
                <div class="col-md-3">
                    <button type="submit" class="btn btn-primary">
                        <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                    </button>
                </div>
            </form>

            <?php if (empty($patient_notes_paginated)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i><?php echo trans('patient_note', 'no_notes_found'); ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th><?php echo trans('patient_note', 'note_date'); ?></th>
                                <th><?php echo trans('patient_note', 'topic'); ?></th>
                                <th><?php echo trans('common', 'created_by'); ?></th>
                                <th><?php echo trans('common', 'actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patient_notes_paginated as $note): ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($note['dcmt_note_date'] ?? $note['dcmt_created_at']); ?></td>
                                    <td>
                                        <?php if (!empty($note['dcmt_topic'])): ?>
                                            <strong><?php echo htmlspecialchars($note['dcmt_topic']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($note['created_by_name'] ?? $note['dcmt_created_by'] ?? '-'); ?></td>
                                    <td>
                                        <a href="../patient_notes/view.php?id=<?php echo $note['dcmt_id']; ?>"
                                           class="dcmt-add-form-view-all-link"
                                           title="<?php echo trans('common', 'view'); ?>">
                                            <i class="fas fa-eye me-1"></i><?php echo trans('common', 'view'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php if ($notes_total_pages > 1): ?>
                    <nav aria-label="Patient notes pagination">
                        <ul class="pagination pagination-sm mb-0 mt-2 justify-content-center">
                            <?php if ($notes_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                       href="?id=<?php echo $patient_id; ?>&notes_page=1&notes_search=<?php echo urlencode($notes_search); ?>">
                                        <i class="fas fa-angle-double-left"></i> <?php echo trans('common', 'first_page'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php if ($notes_page > 1): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                       href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page - 1; ?>&notes_search=<?php echo urlencode($notes_search); ?>">
                                        <i class="fas fa-chevron-left"></i> <?php echo trans('common', 'previous'); ?>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php for ($p = max(1, $notes_page - 2); $p <= min($notes_total_pages, $notes_page + 2); $p++): ?>
                                <li class="page-item <?php echo $p === $notes_page ? 'active' : ''; ?>">
                                    <a class="page-link"
                                       href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $p; ?>&notes_search=<?php echo urlencode($notes_search); ?>">
                                        <?php echo $p; ?>
                                    </a>
                                </li>
                            <?php endfor; ?>

                            <?php if ($notes_page < $notes_total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                       href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_page + 1; ?>&notes_search=<?php echo urlencode($notes_search); ?>">
                                        <?php echo trans('common', 'next'); ?> <i class="fas fa-chevron-right"></i>
                                    </a>
                                </li>
                            <?php endif; ?>

                            <?php if ($notes_page < $notes_total_pages): ?>
                                <li class="page-item">
                                    <a class="page-link"
                                       href="?id=<?php echo $patient_id; ?>&notes_page=<?php echo $notes_total_pages; ?>&notes_search=<?php echo urlencode($notes_search); ?>">
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
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

