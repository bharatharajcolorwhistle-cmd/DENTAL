<?php
/**
 * View Patient Note Page
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

$note_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($note_id <= 0) {
    dcmt_show_message(trans('patient_note', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT pn.*, 
               p.dcmt_patient_name, 
               p.dcmt_phone,
               p.dcmt_email,
               u.dcmt_full_name as created_by_name
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_patients p ON pn.dcmt_patient_id = p.dcmt_id
        LEFT JOIN dcmt_users u ON pn.dcmt_created_by = u.dcmt_username
        WHERE pn.dcmt_id = ?
    ");
    $stmt->execute([$note_id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$note) {
        dcmt_show_message(trans('patient_note', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching patient note: " . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-sticky-note dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('patient_note', 'note_details'); ?></h6>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <a href="edit.php?id=<?php echo $note_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('patient_note', 'back_to_notes'); ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'patient'); ?>:</span>
                    <div class="dcmt-view-field-value">
                        <a href="../patients/view.php?id=<?php echo $note['dcmt_patient_id']; ?>" class="text-decoration-none">
                            <?php 
                            $patient_name = $note['dcmt_patient_name'] ?? '';
                            echo htmlspecialchars($patient_name ?: '-');
                            ?>
                        </a>
                    </div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'phone'); ?>:</span>
                    <div class="dcmt-view-field-value">
                        <?php
                        $phone = $note['dcmt_phone'] ?? '';
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
                    <span class="dcmt-view-field-label"><?php echo trans('patient', 'email'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($note['dcmt_email'] ?? '-'); ?></div>
                </div>
            </div>
        </div>
        
        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient_note', 'note_date'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_format_date($note['dcmt_note_date'] ?? $note['dcmt_created_at']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('common', 'created_by'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo htmlspecialchars($note['created_by_name'] ?? $note['dcmt_created_by']); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('common', 'created_at'); ?>:</span>
                    <div class="dcmt-view-field-value"><?php echo dcmt_format_date($note['dcmt_created_at'], DCMT_DATETIME_FORMAT); ?></div>
                </div>
            </div>
        </div>
        
        <?php if (!empty($note['dcmt_topic'])): ?>
        <div class="row">
            <div class="col-md-4">
                <div class="dcmt-view-field">
                    <span class="dcmt-view-field-label"><?php echo trans('patient_note', 'topic'); ?>:</span>
                    <div class="dcmt-view-field-value"><strong><?php echo htmlspecialchars($note['dcmt_topic']); ?></strong></div>
                </div>
            </div>
        </div>
        <?php endif; ?>
        
        <div class="dcmt-view-field">
            <span class="dcmt-view-field-label"><?php echo trans('patient_note', 'note'); ?>:</span>
            <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($note['dcmt_note_text'])); ?></div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

