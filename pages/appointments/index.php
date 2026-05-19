<?php
/**
 * Appointments Calendar Page
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/appointment_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$current_user = dcmt_get_current_user();
$current_role = $current_user['dcmt_role'] ?? '';
$can_manage = dcmt_is_admin() || in_array($current_role, ['staff', 'assistant'], true);
$is_doctor = $current_role === 'doctor';

if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_search') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $term = trim((string)($_GET['term'] ?? ''));
        $term = dcmt_sanitize_input($term);
        $limit = isset($_GET['limit']) ? (int)$_GET['limit'] : 20;
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $whereSql = '';
        $params = [];
        if ($term !== '') {
            $whereSql = "WHERE (dcmt_patient_name LIKE ? OR dcmt_phone LIKE ?)";
            $likeTerm = '%' . $term . '%';
            $params[] = $likeTerm;
            $params[] = $likeTerm;
        }

        $stmt = $dcmt_pdo->prepare("
            SELECT dcmt_id, dcmt_patient_name, dcmt_phone
            FROM dcmt_patients
            {$whereSql}
            ORDER BY dcmt_patient_name ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $results = [];
        foreach ($patients as $patient) {
            $displayText = (string)($patient['dcmt_patient_name'] ?? '');
            if (!empty($patient['dcmt_phone'])) {
                $displayText .= ' - ' . $patient['dcmt_phone'];
            }
            $results[] = [
                'id' => (int)$patient['dcmt_id'],
                'text' => $displayText
            ];
        }

        echo json_encode([
            'results' => $results,
            'pagination' => ['more' => false]
        ]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode([
            'results' => [],
            'pagination' => ['more' => false]
        ]);
    }
    exit();
}

if (!$can_manage && !$is_doctor) {
    dcmt_show_message('Access denied.', 'danger');
    $dcmt_deny_redirect = ($current_role === 'assistant')
        ? DCMT_APP_URL . '/pages/patients/index.php'
        : DCMT_APP_URL . '/pages/dashboard/index.php';
    dcmt_redirect($dcmt_deny_redirect);
    exit();
}

$csrf_token = dcmt_generate_csrf_token();

$doctors = [];
$patients = [];
$default_doctor_user_id = null;
$calendar_slot_min_time = '09:00:00';
$calendar_slot_max_time = '18:00:00';
$calendar_business_hours_initial = [];

try {
    $doctor_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_full_name, COALESCE(dcmt_color_code, '') AS dcmt_color_code FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name ASC");
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);

    $patient_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_patient_name, dcmt_phone FROM dcmt_patients ORDER BY dcmt_patient_name ASC LIMIT 200");
    $patients = $patient_stmt->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'default_doctor_user_id'");
    $stmt->execute();
    $default_doctor_user_setting = $stmt->fetch(PDO::FETCH_ASSOC);
    if ($default_doctor_user_setting && isset($default_doctor_user_setting['dcmt_setting_value'])) {
        $default_doctor_user_id = $default_doctor_user_setting['dcmt_setting_value'];
    }
    if (!$default_doctor_user_id) {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = 'default_doctor_id'");
        $stmt->execute();
        $default_doctor_setting = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($default_doctor_setting && isset($default_doctor_setting['dcmt_setting_value'])) {
            $default_doctor_candidate = $default_doctor_setting['dcmt_setting_value'];
            $match_stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_users WHERE dcmt_id = ? AND dcmt_role = 'doctor' AND dcmt_status = 'active' LIMIT 1");
            $match_stmt->execute([$default_doctor_candidate]);
            $matched_user = $match_stmt->fetch(PDO::FETCH_ASSOC);
            if ($matched_user) {
                $default_doctor_user_id = $matched_user['dcmt_id'];
            }
        }
    }
} catch (PDOException $e) {
    error_log('Appointment index load error: ' . $e->getMessage());
}

$doctor_filter_id = $is_doctor ? (int)$current_user['dcmt_id'] : 0;

try {
    $clinic_cal = dcmt_load_clinic_calendar_config($dcmt_pdo);
    $calendar_slot_min_time = $clinic_cal['slot_min_time'];
    $calendar_slot_max_time = $clinic_cal['slot_max_time'];
    $calendar_business_hours_initial = dcmt_fc_business_hours_for_doctor_filter(
        $dcmt_pdo,
        ($is_doctor && $doctor_filter_id > 0) ? [$doctor_filter_id] : []
    );
} catch (Throwable $e) {
    error_log('Appointment calendar config load error: ' . $e->getMessage());
}
$auto_open_add = isset($_GET['action']) && $_GET['action'] === 'add' && $can_manage;
$auto_prefill_date = '';
$auto_prefill_start = '';
$auto_prefill_end = '';
if ($auto_open_add) {
    $requested_date = trim((string)($_GET['date'] ?? ''));
    $requested_start = trim((string)($_GET['start'] ?? ''));
    $requested_end = trim((string)($_GET['end'] ?? ''));
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $requested_date)) {
        $auto_prefill_date = $requested_date;
    }
    if (preg_match('/^\d{2}:\d{2}$/', $requested_start)) {
        $auto_prefill_start = $requested_start;
    }
    if (preg_match('/^\d{2}:\d{2}$/', $requested_end)) {
        $auto_prefill_end = $requested_end;
    }
}
require_once __DIR__ . '/../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<link href="../../assets/css/select2.min.css" rel="stylesheet">
<script src="../../assets/js/select2.min.js"></script>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <div class="row g-3 align-items-end">
            <div class="col-lg-5 col-md-6">
                <label class="form-label"><?php echo trans('appointment', 'doctor'); ?></label>
                <select id="doctorFilter" class="form-select dcmt-filter-field" <?php echo !$is_doctor ? 'multiple' : ''; ?> <?php echo $is_doctor ? 'disabled' : ''; ?>>
                    <?php foreach ($doctors as $doctor): ?>
                        <option value="<?php echo (int)$doctor['dcmt_id']; ?>" data-color="<?php echo htmlspecialchars((string)($doctor['dcmt_color_code'] ?? '')); ?>" <?php echo ($is_doctor && (int)$doctor['dcmt_id'] === $doctor_filter_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doctor['dcmt_full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-lg-7 col-md-6">
                <div class="d-flex flex-column flex-sm-row flex-sm-wrap align-items-sm-center justify-content-lg-end gap-2 gap-sm-3">
                    <span class="text-muted small fw-semibold mb-0"><?php echo trans('appointment', 'calendar_status_legend'); ?></span>
                    <div class="d-flex flex-wrap gap-2 justify-content-sm-end">
                        <button type="button" class="btn btn-sm rounded-pill px-3 py-2 js-status-pill is-active" data-status="scheduled" aria-pressed="true">
                            <?php echo trans('appointment', 'scheduled'); ?>
                        </button>
                        <button type="button" class="btn btn-sm rounded-pill px-3 py-2 js-status-pill is-active" data-status="completed" aria-pressed="true">
                            <?php echo trans('appointment', 'completed'); ?>
                        </button>
                        <button type="button" class="btn btn-sm rounded-pill px-3 py-2 js-status-pill" data-status="cancelled" aria-pressed="false">
                            <?php echo trans('appointment', 'cancelled'); ?>
                        </button>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <i class="fas fa-calendar-alt me-2"></i><?php echo trans('appointment', 'appointment_calendar'); ?>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="list.php" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-list me-1"></i><?php echo trans('appointment', 'created_appointments'); ?>
                </a>
                <?php if ($can_manage): ?>
                    <a href="duty_hours.php" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-user-clock me-1"></i>Working Hours
                    </a>
                    <button type="button" class="dcmt-add-form-view-all-link" id="addAppointmentBtn">
                        <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'add_appointment'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
    <div class="card-body">
        <div id="appointmentCalendar" class="appointment-calendar-fc"></div>
    </div>
</div>

<?php if ($can_manage): ?>
<div class="modal fade" id="appointmentActionModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-sm modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h6 class="modal-title"><?php echo trans('common', 'actions'); ?></h6>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo htmlspecialchars(trans('common', 'close')); ?>"></button>
            </div>
            <div class="modal-body">
                <div class="dcmt-appt-action-icon-row">
                    <a href="#"
                       class="btn btn-outline-primary btn-sm dcmt-appt-action-icon-btn"
                       id="appointmentActionViewBtn"
                       title="<?php echo htmlspecialchars(trans('common', 'view')); ?>"
                       aria-label="<?php echo htmlspecialchars(trans('common', 'view')); ?>">
                        <i class="fas fa-eye"></i>
                    </a>
                    <button type="button"
                            class="btn btn-primary btn-sm dcmt-appt-action-icon-btn"
                            id="appointmentActionEditBtn"
                            title="<?php echo htmlspecialchars(trans('common', 'edit')); ?>"
                            aria-label="<?php echo htmlspecialchars(trans('common', 'edit')); ?>">
                        <i class="fas fa-edit"></i>
                    </button>
                    <button type="button"
                            class="btn btn-outline-danger btn-sm dcmt-appt-action-icon-btn"
                            id="appointmentActionCancelBtn"
                            title="<?php echo htmlspecialchars(trans('common', 'cancel')); ?>"
                            aria-label="<?php echo htmlspecialchars(trans('common', 'cancel')); ?>">
                        <i class="fas fa-times-circle"></i>
                    </button>
                    <a href="#"
                       class="btn btn-outline-success btn-sm dcmt-appt-action-icon-btn"
                       id="appointmentActionMessageBtn"
                       target="_blank"
                       rel="noopener noreferrer"
                       title="<?php echo htmlspecialchars(trans('common', 'message')); ?>"
                       aria-label="<?php echo htmlspecialchars(trans('common', 'message')); ?>">
                        <i class="fab fa-whatsapp"></i>
                    </a>
                    <button type="button"
                            class="btn btn-outline-primary btn-sm dcmt-appt-action-icon-btn"
                            id="appointmentActionCloneBtn"
                            title="<?php echo htmlspecialchars(trans('common', 'clone')); ?>"
                            aria-label="<?php echo htmlspecialchars(trans('common', 'clone')); ?>">
                        <i class="fas fa-clone"></i>
                    </button>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'close'); ?></button>
            </div>
        </div>
    </div>
</div>
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentModalTitle"><?php echo trans('appointment', 'add_appointment'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="appointmentAlert" class="alert d-none" role="alert" data-persistent="true"></div>
                <form id="appointmentForm">
                    <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
                    <input type="hidden" name="appointment_id" id="appointment_id">
                    <input type="hidden" name="action" id="form_action" value="create">
                    <div class="row g-3">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'doctor'); ?></label>
                            <select name="doctor_id" id="doctor_id" class="form-select" required>
                                <option value=""><?php echo trans('appointment', 'select'); ?></option>
                                <?php foreach ($doctors as $doctor): ?>
                                    <option value="<?php echo (int)$doctor['dcmt_id']; ?>" data-color="<?php echo htmlspecialchars((string)($doctor['dcmt_color_code'] ?? '')); ?>">
                                        <?php echo htmlspecialchars($doctor['dcmt_full_name']); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" id="doctor_id_error"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'patient'); ?></label>
                            <select name="patient_id" id="patient_id" class="form-select" required>
                                <option value=""><?php echo trans('appointment', 'select'); ?></option>
                                <?php foreach ($patients as $patient): ?>
                                    <option value="<?php echo (int)$patient['dcmt_id']; ?>">
                                        <?php echo htmlspecialchars($patient['dcmt_patient_name'] . (!empty($patient['dcmt_phone']) ? ' - ' . $patient['dcmt_phone'] : '')); ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" id="patient_id_error"></div>
                            <button class="btn btn-link btn-sm px-0 mt-1" type="button" id="showNewPatientBox">+ <?php echo trans('appointment', 'add_new_patient'); ?></button>
                        </div>

                        <div id="newPatientBox" class="col-12 d-none">
                            <div class="border rounded p-3 bg-light">
                                <div class="row g-2">
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('appointment', 'first_name'); ?></label>
                                        <input type="text" class="form-control" id="new_first_name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('appointment', 'fathers_last_name'); ?></label>
                                        <input type="text" class="form-control" id="new_fathers_last_name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('appointment', 'mothers_last_name'); ?></label>
                                        <input type="text" class="form-control" id="new_mothers_last_name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('appointment', 'phone'); ?></label>
                                        <div class="input-group">
                                            <span class="input-group-text">+52</span>
                                            <input type="text" class="form-control" id="new_phone" inputmode="numeric">
                                        </div>
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('patient', 'emergency_guardian_name'); ?></label>
                                        <input type="text" class="form-control" id="new_emergency_contact_name">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('appointment', 'gender'); ?></label>
                                        <select id="new_gender" class="form-select">
                                            <option value="male" selected><?php echo trans('appointment', 'male'); ?></option>
                                            <option value="female"><?php echo trans('appointment', 'female'); ?></option>
                                            <option value="other"><?php echo trans('appointment', 'other'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <button type="button" id="saveNewPatientBtn" class="btn btn-sm btn-outline-primary mt-2"><?php echo trans('appointment', 'save_patient'); ?></button>
                            </div>
                        </div>

                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'operatory'); ?></label>
                            <select name="operatory_id" id="operatory_id" class="form-select">
                                <option value=""><?php echo trans('appointment', 'select'); ?></option>
                            </select>
                            <div class="invalid-feedback" id="operatory_id_error"></div>
                            <div class="form-text"><?php echo trans('appointment', 'operatory_help_doctor'); ?></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'date'); ?></label>
                            <input type="date" name="appointment_date" id="appointment_date" class="form-control" value="<?php echo htmlspecialchars(dcmt_get_current_date()); ?>" required>
                            <div class="invalid-feedback" id="appointment_date_error"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'start_time'); ?></label>
                            <input type="time" name="start_time" id="start_time" class="form-control" step="60" required>
                            <div class="invalid-feedback" id="start_time_error"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'end_time'); ?></label>
                            <input type="time" name="end_time" id="end_time" class="form-control" step="60" required>
                            <div class="invalid-feedback" id="end_time_error"></div>
                        </div>
                        <div class="col-12 d-none" id="appointmentAvailabilityPanel">
                            <div class="border rounded-3 bg-light p-3">
                                <div class="fw-semibold small mb-2 text-body-secondary"><?php echo htmlspecialchars(trans('appointment', 'availability_panel_title')); ?></div>
                                <div class="row g-3">
                                    <div class="col-md-6">
                                        <div class="small text-success fw-semibold mb-1"><?php echo htmlspecialchars(trans('appointment', 'availability_available')); ?></div>
                                        <div id="availabilityAvailableList" class="small"></div>
                                    </div>
                                    <div class="col-md-6">
                                        <div class="small text-danger fw-semibold mb-1"><?php echo htmlspecialchars(trans('appointment', 'availability_booked')); ?></div>
                                        <div id="availabilityBookedList" class="small"></div>
                                    </div>
                                </div>
                            </div>
                        </div>
                        <div class="col-12 d-none" id="appointmentActualTimesRow">
                            <div class="row g-3">
                                <div class="col-md-6">
                                    <label class="form-label" for="actual_start_time"><?php echo trans('appointment', 'actual_start_time'); ?></label>
                                    <input type="time" name="actual_start_time" id="actual_start_time" class="form-control" step="60">
                                    <div class="invalid-feedback" id="actual_start_time_error"></div>
                                </div>
                                <div class="col-md-6">
                                    <label class="form-label" for="actual_end_time"><?php echo trans('appointment', 'actual_end_time'); ?></label>
                                    <input type="time" name="actual_end_time" id="actual_end_time" class="form-control" step="60">
                                    <div class="invalid-feedback" id="actual_end_time_error"></div>
                                </div>
                            </div>
                        </div>
                        <input type="hidden" name="status" id="status" value="scheduled">
                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'reason'); ?></label>
                            <input type="text" name="reason" id="reason" class="form-control" maxlength="255">
                        </div>
                        <div class="col-12">
                            <label class="form-label"><?php echo trans('appointment', 'notes'); ?></label>
                            <textarea name="notes" id="notes" class="form-control" rows="3"></textarea>
                        </div>
                    </div>
                </form>
            </div>
            <div class="modal-footer">
                <div id="calendarExportLinks" class="me-auto d-none">
                    <a href="#" id="googleCalendarBtn" target="_blank" rel="noopener noreferrer" class="btn btn-outline-info btn-sm me-2">
                        <i class="fas fa-calendar-plus me-1"></i><?php echo trans('appointment', 'google_calendar'); ?>
                    </a>
                    <a href="#" id="icsCalendarBtn" class="btn btn-outline-secondary btn-sm">
                        <i class="fas fa-file-download me-1"></i><?php echo trans('appointment', 'download_ics'); ?>
                    </a>
                </div>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('appointment', 'close'); ?></button>
                <button type="button" id="saveAppointmentBtn" class="btn btn-primary"><?php echo trans('appointment', 'save'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
#appointmentCalendar.appointment-calendar-fc {
    min-height: 0;
}
@media (max-width: 768px) {
    #appointmentCalendar.appointment-calendar-fc {
        min-height: 0;
    }
}
#appointmentCalendar .fc .fc-view-harness {
    min-height: 0 !important;
}
.select2-container .select2-selection.is-invalid {
    border-color: #dc3545 !important;
}
.dcmt-appt-action-icon-row {
    display: flex;
    align-items: center;
    justify-content: center;
    gap: 0.5rem;
    flex-wrap: nowrap;
}
.dcmt-appt-action-icon-btn {
    width: 42px;
    height: 42px;
    padding: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
}

/* FullCalendar toolbar: prev/next/today + day/week/month — light default, primary when active */
#appointmentCalendar .fc .fc-toolbar.fc-header-toolbar {
    margin-bottom: 1rem;
    padding: 0.5rem 0;
    flex-wrap: wrap;
    gap: 0.75rem;
    row-gap: 0.5rem;
}
#appointmentCalendar .fc .fc-toolbar-title {
    font-size: 1.125rem;
    font-weight: 600;
    color: #212529;
}
#appointmentCalendar .fc .fc-button {
    font-weight: 500;
    font-size: 0.8125rem;
    padding: 0.35em 0.75em;
    text-shadow: none;
    box-shadow: none;
}
#appointmentCalendar .fc .fc-button.fc-button-primary:not(.fc-button-active) {
    background-color: #e8f2fc;
    border: 1px solid #9ec5ee;
    color: #0b5ed7;
}
#appointmentCalendar .fc .fc-button.fc-button-primary:not(.fc-button-active):hover:not(:disabled) {
    background-color: #d0e4f9;
    border-color: #6ea8e0;
    color: #084298;
}
#appointmentCalendar .fc .fc-button.fc-button-primary.fc-button-active {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
#appointmentCalendar .fc .fc-button.fc-button-primary.fc-button-active:hover:not(:disabled) {
    background-color: #0b5ed7;
    border-color: #0a58ca;
    color: #fff;
}
#appointmentCalendar .fc .fc-button:focus {
    box-shadow: 0 0 0 0.2rem rgba(13, 110, 253, 0.25);
}
#appointmentCalendar .fc .fc-button:disabled {
    opacity: 0.45;
}
#appointmentCalendar .fc .fc-button-group > .fc-button {
    margin: 0;
}
#appointmentCalendar .fc .fc-button-group > .fc-button:not(:first-child) {
    margin-left: -1px;
    border-top-left-radius: 0;
    border-bottom-left-radius: 0;
}
#appointmentCalendar .fc .fc-button-group > .fc-button:not(:last-child) {
    border-top-right-radius: 0;
    border-bottom-right-radius: 0;
}
.dcmt-filter-select2 .select2-selection--multiple .select2-selection__choice {
    display: none !important;
}
.dcmt-filter-select2 .select2-selection--multiple {
    min-height: 38px;
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
.dcmt-doctor-select2 .select2-results__option .dcmt-select2-option {
    display: flex;
    align-items: center;
    gap: 0.5rem;
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
    max-height: 280px !important; /* show about 5 doctor rows before scrolling */
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
.js-status-pill {
    border: 1px solid #ced4da;
    background-color: #fff;
    color: #495057;
    font-weight: 600;
    transition: all 0.15s ease;
}
.js-status-pill:hover {
    border-color: #0d6efd;
    color: #0d6efd;
    background-color: #f8fbff;
}
.js-status-pill.is-active {
    background-color: #0d6efd;
    border-color: #0d6efd;
    color: #fff;
}
.js-status-pill:not(.is-active) {
    opacity: 0.75;
}

/* Weekly/day time grid readability */
#appointmentCalendar .fc .fc-timegrid-event-harness {
    margin-right: 2px;
}
#appointmentCalendar .fc .fc-timegrid-event {
    border-radius: 6px;
    padding: 3px 6px;
    overflow: hidden;
}
#appointmentCalendar .fc .fc-timegrid-event .fc-event-main {
    overflow: hidden;
}
#appointmentCalendar .fc .fc-timegrid-event .fc-event-title {
    white-space: nowrap;
    overflow: hidden;
    text-overflow: ellipsis;
    line-height: 1.25;
    font-size: 0.85rem;
}
#appointmentCalendar .fc .fc-timegrid-event .fc-event-time {
    font-size: 0.78rem;
    line-height: 1.15;
}
</style>

<script>
const isDoctor = <?php echo $is_doctor ? 'true' : 'false'; ?>;
const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
const canEditClosedAppointments = <?php echo dcmt_is_admin() ? 'true' : 'false'; ?>;
const currentDoctorId = <?php echo (int)$doctor_filter_id; ?>;
const todayDate = <?php echo json_encode(dcmt_get_current_date('Y-m-d')); ?>;
const defaultDoctorId = <?php echo $default_doctor_user_id ? json_encode((int)$default_doctor_user_id) : 'null'; ?>;
let calendarBusinessHours = <?php echo json_encode($calendar_business_hours_initial); ?>;
const autoOpenAddAppointment = <?php echo $auto_open_add ? 'true' : 'false'; ?>;
const autoPrefillAppointmentDate = <?php echo json_encode($auto_prefill_date); ?>;
const autoPrefillAppointmentStart = <?php echo json_encode($auto_prefill_start); ?>;
const autoPrefillAppointmentEnd = <?php echo json_encode($auto_prefill_end); ?>;
const t = {
    addAppointment: <?php echo json_encode(trans('appointment', 'add_appointment')); ?>,
    editAppointment: <?php echo json_encode(trans('appointment', 'edit_appointment')); ?>,
    loadSlotsFailed: <?php echo json_encode(trans('appointment', 'load_slots_failed')); ?>,
    loadAppointmentFailed: <?php echo json_encode(trans('appointment', 'load_appointment_failed')); ?>,
    addPatientFailed: <?php echo json_encode(trans('appointment', 'add_patient_failed')); ?>,
    processing: <?php echo json_encode(trans('common', 'processing')); ?>,
    slotChanged: <?php echo json_encode(trans('appointment', 'slot_changed')); ?>,
    outsideHoursConfirm: <?php echo json_encode(trans('appointment', 'outside_hours_confirm_prompt')); ?>,
    startBeforeEnd: <?php echo json_encode(trans('appointment', 'start_before_end')); ?>,
    requiredFields: <?php echo json_encode(trans('appointment', 'required_fields')); ?>,
    select: <?php echo json_encode(trans('appointment', 'select')); ?>,
    loadOperatoriesFailed: <?php echo json_encode(trans('appointment', 'load_operatories_failed')); ?>,
    operatoriesEmpty: <?php echo json_encode(trans('appointment', 'operatories_empty')); ?>,
    operatoryRequired: <?php echo json_encode(trans('appointment', 'operatory_required')); ?>,
    availabilityNoFree: <?php echo json_encode(trans('appointment', 'availability_no_free')); ?>,
    availabilityNoneBooked: <?php echo json_encode(trans('appointment', 'availability_none_booked')); ?>
};

function getSelectedDoctorIds() {
    if (isDoctor && currentDoctorId) return [String(currentDoctorId)];
    const doctorFilter = document.getElementById('doctorFilter');
    if (!doctorFilter) return [];
    if (doctorFilter.multiple) {
        return Array.from(doctorFilter.selectedOptions || [])
            .map((o) => String(o.value || '').trim())
            .filter((v) => v !== '' && v !== '0');
    }
    const v = String(doctorFilter.value || '').trim();
    return (v !== '' && v !== '0') ? [v] : [];
}

function loadOperatoriesGlobal(prefillOperatoryId, prefillOperatoryName) {
    return new Promise((resolve) => {
        const sel = document.getElementById('operatory_id');
        if (!sel) {
            resolve();
            return;
        }
        fetch('get_operatories_ajax.php')
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showAlert(data.message || t.loadOperatoriesFailed);
                    sel.innerHTML = '<option value="">' + t.select + '</option>';
                    sel.required = false;
                    resolve();
                    return;
                }
                const ops = (data.operatories || []).filter(o => parseInt(o.is_active, 10) === 1);
                sel.innerHTML = '';
                if (ops.length === 0) {
                    const opt = document.createElement('option');
                    opt.value = '';
                    opt.textContent = t.operatoriesEmpty;
                    sel.appendChild(opt);
                    sel.required = false;
                    resolve();
                    return;
                }
                const placeholder = document.createElement('option');
                placeholder.value = '';
                placeholder.textContent = t.select;
                sel.appendChild(placeholder);
                ops.forEach(o => {
                    const opt = document.createElement('option');
                    opt.value = String(o.id);
                    opt.textContent = o.name;
                    sel.appendChild(opt);
                });
                sel.required = true;
                const prefill = prefillOperatoryId != null && prefillOperatoryId !== '' ? String(prefillOperatoryId) : '';
                if (prefill) {
                    let opt = sel.querySelector(`option[value="${prefill}"]`);
                    if (!opt) {
                        opt = document.createElement('option');
                        opt.value = prefill;
                        opt.textContent = (prefillOperatoryName && String(prefillOperatoryName)) || prefill;
                        sel.appendChild(opt);
                    }
                    sel.value = prefill;
                } else if (sel.options.length > 1) {
                    sel.selectedIndex = 0;
                }
                resolve();
            })
            .catch(() => {
                showAlert(t.loadOperatoriesFailed);
                sel.innerHTML = '<option value="">' + t.select + '</option>';
                sel.required = false;
                resolve();
            });
    });
}
let calendar;
let availableSlots = [];
let editingOriginalSlot = { start: '', end: '' };
const visibleCalendarStatuses = new Set(['scheduled', 'completed']);

const clinicTimezone = <?php echo json_encode(date_default_timezone_get()); ?>;

function buildGoogleCalendarUrl(title, details, dateStr, startTime, endTime) {
    const toGoogleDateTime = (d, t) => {
        const ymd = String(d || '').replace(/-/g, '');
        const hm = String(t || '').replace(':', '');
        if (ymd.length !== 8 || hm.length !== 4) {
            return '';
        }
        return `${ymd}T${hm}00`;
    };

    const start = toGoogleDateTime(dateStr, startTime);
    const end = toGoogleDateTime(dateStr, endTime);
    if (!start || !end) {
        return '#';
    }

    const dates = `${start}/${end}`;
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        + '&text=' + encodeURIComponent(title)
        + '&dates=' + encodeURIComponent(dates)
        + '&details=' + encodeURIComponent(details)
        + '&ctz=' + encodeURIComponent(clinicTimezone);
}

function toggleCalendarExportLinks(show, googleUrl = '', icsUrl = '') {
    const box = document.getElementById('calendarExportLinks');
    const gBtn = document.getElementById('googleCalendarBtn');
    const iBtn = document.getElementById('icsCalendarBtn');
    if (!box || !gBtn || !iBtn) return;
    if (!show) {
        box.classList.add('d-none');
        gBtn.setAttribute('href', '#');
        iBtn.setAttribute('href', '#');
        return;
    }
    gBtn.setAttribute('href', googleUrl || '#');
    iBtn.setAttribute('href', icsUrl || '#');
    box.classList.remove('d-none');
}

function getSelectedOptionText(selectId) {
    const el = document.getElementById(selectId);
    if (!el || !el.options || el.selectedIndex < 0) return '';
    return (el.options[el.selectedIndex].text || '').trim();
}

function refreshCalendarExportLinksForForm() {
    const appointmentId = (document.getElementById('appointment_id')?.value || '').trim();
    const status = (document.getElementById('status')?.value || '').trim();
    if (!appointmentId || status !== 'scheduled') {
        toggleCalendarExportLinks(false);
        return;
    }

    const patientName = getSelectedOptionText('patient_id');
    const doctorName = getSelectedOptionText('doctor_id');
    const operatoryName = getSelectedOptionText('operatory_id');
    const date = (document.getElementById('appointment_date')?.value || '').trim();
    const startTime = (document.getElementById('start_time')?.value || '').trim();
    const endTime = (document.getElementById('end_time')?.value || '').trim();
    const reason = (document.getElementById('reason')?.value || '').trim();
    const notes = (document.getElementById('notes')?.value || '').trim();

    const title = `Appointment - ${patientName}` + (reason ? ` (${reason})` : '');
    let details = `Patient: ${patientName}\nDoctor: ${doctorName}`;
    if (operatoryName) details += `\nOperatory: ${operatoryName}`;
    if (reason) details += `\nReason: ${reason}`;
    if (notes) details += `\nNotes: ${notes}`;

    const googleUrl = buildGoogleCalendarUrl(title, details, date, startTime, endTime);
    const icsUrl = `export_ics.php?id=${encodeURIComponent(appointmentId)}`;
    toggleCalendarExportLinks(true, googleUrl, icsUrl);
}

function statusColor(status) {
    const map = {
        scheduled: '#0d6efd',
        completed: '#6c757d',
        cancelled: '#dc3545'
    };
    return map[status] || '#0d6efd';
}

function compactEventTitleForGrid(eventData, fallbackTitle) {
    const patientName = String(eventData?.patient_name || '').trim();
    if (patientName) {
        return patientName;
    }

    const rawTitle = String(fallbackTitle || '').trim();
    if (!rawTitle) return '';

    // Keep the first meaningful segment when legacy titles contain multiple parts.
    const parts = rawTitle.split(' - ').map((p) => p.trim()).filter(Boolean);
    return parts.length > 0 ? parts[0] : rawTitle;
}

function isHexColor(value) {
    return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(value || '').trim());
}

function doctorAccentColor(doctorId, doctorName) {
    const seed = String(doctorId || doctorName || '').trim();
    if (!seed) return '#198754';

    let hash = 0;
    for (let i = 0; i < seed.length; i += 1) {
        hash = ((hash << 5) - hash) + seed.charCodeAt(i);
        hash |= 0;
    }
    const hue = Math.abs(hash) % 360;
    return `hsl(${hue} 75% 42%)`;
}

function resolveDoctorColor(eventData) {
    const direct = String(eventData?.doctor_color || '').trim();
    if (isHexColor(direct)) {
        return direct;
    }
    return doctorAccentColor(eventData?.doctor_id, eventData?.doctor_name);
}

function showAlert(message, type = 'danger') {
    const box = document.getElementById('appointmentAlert');
    if (!box) return;
    box.className = 'alert alert-' + type;
    box.textContent = message;
    box.classList.remove('d-none');
}

function hideAlert() {
    const box = document.getElementById('appointmentAlert');
    if (!box) return;
    box.classList.add('d-none');
}

function clearFieldErrors() {
    const fields = ['doctor_id', 'patient_id', 'operatory_id', 'appointment_date', 'start_time', 'end_time', 'actual_start_time', 'actual_end_time', 'status'];
    fields.forEach((fieldId) => {
        const el = document.getElementById(fieldId);
        const err = document.getElementById(fieldId + '_error');
        if (el) {
            el.classList.remove('is-invalid');
            el.setCustomValidity('');
        }
        if (err) {
            err.textContent = '';
        }
        if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
            const $el = $('#' + fieldId);
            if ($el.hasClass('select2-hidden-accessible')) {
                const $container = $el.next('.select2');
                $container.find('.select2-selection').removeClass('is-invalid');
            }
        }
    });
}

function showFieldError(fieldId, message) {
    const el = document.getElementById(fieldId);
    const err = document.getElementById(fieldId + '_error');
    if (el) {
        el.classList.add('is-invalid');
        el.setCustomValidity(message || '');
    }
    if (err) {
        err.textContent = message || '';
    }
    if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
        const $el = $('#' + fieldId);
        if ($el.hasClass('select2-hidden-accessible')) {
            const $container = $el.next('.select2');
            $container.find('.select2-selection').addClass('is-invalid');
        }
    }
}

function escapeHtml(str) {
    const d = document.createElement('div');
    d.textContent = str == null ? '' : String(str);
    return d.innerHTML;
}

function renderAvailabilityPanel(slots, busy) {
    const panel = document.getElementById('appointmentAvailabilityPanel');
    const availEl = document.getElementById('availabilityAvailableList');
    const busyEl = document.getElementById('availabilityBookedList');
    if (!panel || !availEl || !busyEl) return;
    if (slots === null && busy === null) {
        panel.classList.add('d-none');
        return;
    }
    panel.classList.remove('d-none');
    const startInput = document.getElementById('start_time');
    const endInput = document.getElementById('end_time');
    const startVal = ((startInput && startInput.value) ? startInput.value : '').trim();
    const endVal = ((endInput && endInput.value) ? endInput.value : '').trim();
    if (slots && slots.length) {
        availEl.innerHTML = '<div class="row g-2">' + slots.map(function(s) {
            const isActive = startVal === String(s.start) && endVal === String(s.end);
            const cls = isActive
                ? 'btn btn-sm w-100 rounded-pill py-1 px-2 btn-success dcmt-slot-pill is-active'
                : 'btn btn-sm w-100 rounded-pill py-1 px-2 btn-outline-success dcmt-slot-pill';
            return '<div class="col-6 col-md-4">' +
                '<button type="button" class="' + cls + '" data-slot-start="' + escapeHtml(s.start) + '" data-slot-end="' + escapeHtml(s.end) + '">' +
                '<span class="small">' + escapeHtml(s.start) + '–' + escapeHtml(s.end) + '</span>' +
                '</button>' +
                '</div>';
        }).join('') + '</div>';
    } else {
        availEl.innerHTML = '<span class="text-muted">' + escapeHtml(t.availabilityNoFree) + '</span>';
    }
    if (busy && busy.length) {
        busyEl.innerHTML = busy.map(function(s) {
            return '<span class="badge rounded-pill bg-secondary me-1 mb-1">' +
                escapeHtml(s.start) + '–' + escapeHtml(s.end) + '</span>';
        }).join(' ');
    } else {
        busyEl.innerHTML = '<span class="text-muted">' + escapeHtml(t.availabilityNoneBooked) + '</span>';
    }
}

function loadSlots(prefillStart, prefillEnd) {
    const doctorId = document.getElementById('doctor_id').value;
    const operatoryId = document.getElementById('operatory_id').value;
    const appointmentDate = document.getElementById('appointment_date').value;
    const startInput = document.getElementById('start_time');
    const endInput = document.getElementById('end_time');

    let preserveStart;
    let preserveEnd;
    if (arguments.length === 0) {
        preserveStart = startInput.value;
        preserveEnd = endInput.value;
    } else {
        preserveStart = prefillStart;
        preserveEnd = prefillEnd;
    }

    availableSlots = [];
    if (!preserveStart) startInput.value = '';
    if (!preserveEnd) endInput.value = '';
    if (preserveStart) startInput.value = preserveStart;
    if (preserveEnd) endInput.value = preserveEnd;

    if (!doctorId || !appointmentDate || !operatoryId) {
        renderAvailabilityPanel(null, null);
        validateAppointmentTimes(false);
        return;
    }

    fetch(`available_slots_ajax.php?doctor_id=${encodeURIComponent(doctorId)}&operatory_id=${encodeURIComponent(operatoryId)}&date=${encodeURIComponent(appointmentDate)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showAlert(data.message || t.loadSlotsFailed);
                if (preserveStart) startInput.value = preserveStart;
                if (preserveEnd) endInput.value = preserveEnd;
                renderAvailabilityPanel(null, null);
                validateAppointmentTimes(false);
                return;
            }
            availableSlots = Array.isArray(data.slots) ? data.slots : [];
            const busy = Array.isArray(data.busy) ? data.busy : [];
            if (preserveStart) startInput.value = preserveStart;
            if (preserveEnd) endInput.value = preserveEnd;
            renderAvailabilityPanel(availableSlots, busy);
            validateAppointmentTimes(false);
        })
        .catch(function() {
            showAlert(t.loadSlotsFailed);
            if (preserveStart) startInput.value = preserveStart;
            if (preserveEnd) endInput.value = preserveEnd;
            renderAvailabilityPanel(null, null);
        });
}

function validateAppointmentTimes(showMessage = true) {
    clearFieldErrors();
    const startInput = document.getElementById('start_time');
    const endInput = document.getElementById('end_time');
    const start = startInput.value;
    const end = endInput.value;

    startInput.setCustomValidity('');
    endInput.setCustomValidity('');
    if (!start || !end) {
        return true;
    }

    if (start >= end) {
        showFieldError('start_time', t.startBeforeEnd);
        showFieldError('end_time', t.startBeforeEnd);
        if (showMessage) showAlert(t.startBeforeEnd);
        return false;
    }

    hideAlert();
    return true;
}

function formatLocalDate(d) {
    const y = d.getFullYear();
    const m = String(d.getMonth() + 1).padStart(2, '0');
    const day = String(d.getDate()).padStart(2, '0');
    return `${y}-${m}-${day}`;
}

function formatLocalTimeHM(d) {
    const h = String(d.getHours()).padStart(2, '0');
    const min = String(d.getMinutes()).padStart(2, '0');
    return `${h}:${min}`;
}

function setNewPatientLinkVisible(isVisible) {
    const showNewPatientBoxBtn = document.getElementById('showNewPatientBox');
    if (showNewPatientBoxBtn) {
        showNewPatientBoxBtn.classList.toggle('d-none', !isVisible);
    }
    if (!isVisible) {
        const newPatientBox = document.getElementById('newPatientBox');
        if (newPatientBox) {
            newPatientBox.classList.add('d-none');
        }
    }
}

function resetFormForCreate(dateStr = '', startStr = '', endStr = '') {
    document.getElementById('appointmentModalTitle').textContent = t.addAppointment;
    document.getElementById('form_action').value = 'create';
    setNewPatientLinkVisible(true);
    document.getElementById('appointment_id').value = '';
    editingOriginalSlot = { start: '', end: '' };
    document.getElementById('appointmentForm').reset();
    const actualTimesRow = document.getElementById('appointmentActualTimesRow');
    if (actualTimesRow) {
        actualTimesRow.classList.add('d-none');
    }
    const actualStartEl = document.getElementById('actual_start_time');
    const actualEndEl = document.getElementById('actual_end_time');
    if (actualStartEl) actualStartEl.value = '';
    if (actualEndEl) actualEndEl.value = '';
    document.getElementById('patient_id').value = '';
    const opSel = document.getElementById('operatory_id');
    if (opSel) {
        opSel.innerHTML = '<option value="">' + t.select + '</option>';
        opSel.required = false;
    }
    const selectedDoctorIds = getSelectedDoctorIds();
    const filterDoctorId = selectedDoctorIds.length === 1 ? String(selectedDoctorIds[0]) : '';
    const preferredDoctorId = filterDoctorId || (defaultDoctorId ? String(defaultDoctorId) : '');

    document.getElementById('appointment_date').value = dateStr || todayDate;
    const cancelAppointmentBtn = document.getElementById('cancelAppointmentBtn');
    if (cancelAppointmentBtn) {
        cancelAppointmentBtn.classList.add('d-none');
    }
    toggleCalendarExportLinks(false);
    hideAlert();
    clearFieldErrors();

    if (preferredDoctorId) {
        document.getElementById('doctor_id').value = preferredDoctorId;
        if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
            $('#doctor_id').val(preferredDoctorId).trigger('change.select2');
        }
        loadOperatoriesGlobal().then(function() {
            loadSlots(startStr, endStr);
        });
    } else {
        document.getElementById('doctor_id').value = '';
        if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
            $('#doctor_id').val(null).trigger('change.select2');
        }
        loadOperatoriesGlobal();
        loadSlots(startStr, endStr);
    }
    if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
        $('#patient_id').val(null).trigger('change.select2');
    }
}

function openEdit(appointmentId) {
    fetch(`get_ajax.php?id=${encodeURIComponent(appointmentId)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showAlert(data.message || t.loadAppointmentFailed);
                return;
            }
            const a = data.appointment;
            document.getElementById('appointmentModalTitle').textContent = t.editAppointment;
            document.getElementById('form_action').value = 'update';
            setNewPatientLinkVisible(false);
            document.getElementById('appointment_id').value = a.id;

            const doctorSelect = document.getElementById('doctor_id');
            const patientSelect = document.getElementById('patient_id');

            if (doctorSelect) {
                const doctorValue = String(a.doctor_id);
                let doctorOption = doctorSelect.querySelector(`option[value="${doctorValue}"]`);
                if (!doctorOption) {
                    doctorOption = document.createElement('option');
                    doctorOption.value = doctorValue;
                    doctorOption.textContent = a.doctor_name || doctorValue;
                    doctorSelect.appendChild(doctorOption);
                }
                doctorSelect.value = doctorValue;
                if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
                    $('#doctor_id').val(doctorValue).trigger('change.select2');
                }
            }

            if (patientSelect) {
                const patientValue = String(a.patient_id);
                let patientOption = patientSelect.querySelector(`option[value="${patientValue}"]`);
                if (!patientOption) {
                    patientOption = document.createElement('option');
                    patientOption.value = patientValue;
                    patientOption.textContent = a.patient_name || patientValue;
                    patientSelect.appendChild(patientOption);
                }
                patientSelect.value = patientValue;
                if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
                    $('#patient_id').val(patientValue).trigger('change.select2');
                }
            }

            document.getElementById('appointment_date').value = a.date;
            document.getElementById('status').value = a.status;
            const actualTimesRowEdit = document.getElementById('appointmentActualTimesRow');
            if (actualTimesRowEdit) {
                actualTimesRowEdit.classList.toggle('d-none', a.status !== 'completed');
            }
            document.getElementById('actual_start_time').value = a.actual_start_time || '';
            document.getElementById('actual_end_time').value = a.actual_end_time || '';
            document.getElementById('reason').value = a.reason || '';
            document.getElementById('notes').value = a.notes || '';
            const cancelAppointmentBtn = document.getElementById('cancelAppointmentBtn');
            if (cancelAppointmentBtn) {
                cancelAppointmentBtn.classList.remove('d-none');
            }
            refreshCalendarExportLinksForForm();
            hideAlert();
            clearFieldErrors();
            loadOperatoriesGlobal(a.operatory_id, a.operatory_name || '').then(() => {
                editingOriginalSlot = { start: a.start_time, end: a.end_time };
                loadSlots(a.start_time, a.end_time);
                refreshCalendarExportLinksForForm();
            });
        })
        .catch(() => showAlert(t.loadAppointmentFailed));
}

function openClone(appointmentId) {
    fetch(`get_ajax.php?id=${encodeURIComponent(appointmentId)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showAlert(data.message || t.loadAppointmentFailed);
                return;
            }
            const a = data.appointment;
            document.getElementById('appointmentModalTitle').textContent = t.addAppointment;
            document.getElementById('form_action').value = 'create';
            setNewPatientLinkVisible(true);
            document.getElementById('appointment_id').value = '';
            editingOriginalSlot = { start: '', end: '' };
            document.getElementById('appointmentForm').reset();
            const actualTimesRow = document.getElementById('appointmentActualTimesRow');
            if (actualTimesRow) {
                actualTimesRow.classList.add('d-none');
            }
            const actualStartEl = document.getElementById('actual_start_time');
            const actualEndEl = document.getElementById('actual_end_time');
            if (actualStartEl) actualStartEl.value = '';
            if (actualEndEl) actualEndEl.value = '';
            const cancelAppointmentBtn = document.getElementById('cancelAppointmentBtn');
            if (cancelAppointmentBtn) {
                cancelAppointmentBtn.classList.add('d-none');
            }
            toggleCalendarExportLinks(false);
            hideAlert();
            clearFieldErrors();

            const doctorSelect = document.getElementById('doctor_id');
            const patientSelect = document.getElementById('patient_id');

            if (doctorSelect) {
                const doctorValue = String(a.doctor_id);
                let doctorOption = doctorSelect.querySelector(`option[value="${doctorValue}"]`);
                if (!doctorOption) {
                    doctorOption = document.createElement('option');
                    doctorOption.value = doctorValue;
                    doctorOption.textContent = a.doctor_name || doctorValue;
                    doctorSelect.appendChild(doctorOption);
                }
                doctorSelect.value = doctorValue;
                if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
                    $('#doctor_id').val(doctorValue).trigger('change.select2');
                }
            }

            if (patientSelect) {
                const patientValue = String(a.patient_id);
                let patientOption = patientSelect.querySelector(`option[value="${patientValue}"]`);
                if (!patientOption) {
                    patientOption = document.createElement('option');
                    patientOption.value = patientValue;
                    patientOption.textContent = a.patient_name || patientValue;
                    patientSelect.appendChild(patientOption);
                }
                patientSelect.value = patientValue;
                if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
                    $('#patient_id').val(patientValue).trigger('change.select2');
                }
            }

            document.getElementById('appointment_date').value = a.date;
            document.getElementById('status').value = 'scheduled';
            document.getElementById('reason').value = a.reason || '';
            document.getElementById('notes').value = a.notes || '';
            refreshCalendarExportLinksForForm();

            loadOperatoriesGlobal(a.operatory_id, a.operatory_name || '').then(() => {
                loadSlots(a.start_time, a.end_time);
                refreshCalendarExportLinksForForm();
            });
        })
        .catch(() => showAlert(t.loadAppointmentFailed));
}

function bindSelectChange(selectId, callback) {
    const element = document.getElementById(selectId);
    if (!element || typeof callback !== 'function') return;
    if (typeof $ !== 'undefined') {
        $(element).on('change', callback);
    } else {
        element.addEventListener('change', callback);
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const calendarEl = document.getElementById('appointmentCalendar');
    const doctorFilter = document.getElementById('doctorFilter');
    const appointmentModalElement = document.getElementById('appointmentModal');
    const appointmentModal = appointmentModalElement ? new bootstrap.Modal(appointmentModalElement) : null;
    const appointmentActionModalElement = document.getElementById('appointmentActionModal');
    const appointmentActionModal = appointmentActionModalElement ? new bootstrap.Modal(appointmentActionModalElement) : null;
    let clickedAppointmentId = null;
    let clickedAppointmentData = null;
    let lastHorizontalNavigateAt = 0;

    function initTopDoctorFilterSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $doctorFilter = $('#doctorFilter');
        if ($doctorFilter.length === 0) return;
        if ($doctorFilter.hasClass('select2-hidden-accessible')) {
            $doctorFilter.select2('destroy');
        }
        const isMultiple = !!$doctorFilter.prop('multiple');
        const allDoctorsText = <?php echo json_encode(trans('appointment', 'all_doctors')); ?>;
        const selectText = <?php echo json_encode(trans('appointment', 'select')); ?>;

        function updateDoctorFilterSummary() {
            if (!isMultiple) return;
            const s2 = $doctorFilter.data('select2');
            if (!s2 || !s2.$container) return;
            const $rendered = s2.$container.find('.select2-selection__rendered');
            if ($rendered.length === 0) return;
            $rendered.find('.dcmt-select2-multi-summary').remove();
            const selectedTexts = $doctorFilter.find('option:selected').map(function() {
                return (this.text || '').trim();
            }).get().filter(Boolean);

            if (selectedTexts.length === 0) return;
            const label = selectedTexts.length === 1 ? selectedTexts[0] : (selectedTexts.length + ' selected');

            const $li = $('<li class="dcmt-select2-multi-summary"></li>');
            $li.text(label);
            $rendered.prepend($li);
        }

        function doctorOptionTemplate(data) {
            if (!data.id) return data.text;
            const label = String(data.text || '');
            const $row = $('<span class="dcmt-select2-option"></span>');
            if (isMultiple) {
                $row.append($('<span class="dcmt-option-check" aria-hidden="true"></span>'));
            }
            $row.append($('<span></span>').text(label));
            return $row;
        }

        function doctorSelectionTemplate(data) {
            if (!data.id) return data.text;
            return $('<span></span>').text(String(data.text || ''));
        }

        $doctorFilter.select2({
            width: '100%',
            placeholder: isMultiple
                ? allDoctorsText
                : selectText,
            allowClear: isMultiple,
            closeOnSelect: !isMultiple,
            templateResult: doctorOptionTemplate,
            templateSelection: doctorSelectionTemplate
        });
        const s2 = $doctorFilter.data('select2');
        if (s2 && s2.$container) {
            s2.$container.addClass('dcmt-filter-select2');
        }
        $doctorFilter.off('select2:open.dcmtDoctorFilterHeight').on('select2:open.dcmtDoctorFilterHeight', function() {
            const openDropdown = document.querySelector('.select2-container--open .select2-dropdown');
            if (openDropdown) {
                openDropdown.classList.add('dcmt-doctor-filter-dropdown');
            }
        });
        if (isMultiple) {
            $doctorFilter.off('.dcmtDoctorSummary');
            $doctorFilter.on('change.dcmtDoctorSummary', updateDoctorFilterSummary);
            updateDoctorFilterSummary();
        }
    }
    initTopDoctorFilterSelect2();

    if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
        $(document).on('select2:open', function() {
            const searchInput = document.querySelector('.select2-container--open .select2-search__field');
            if (searchInput) {
                searchInput.focus();
            }
        });
    }

    let lastCalendarSlotOpenAt = 0;
    function openCreateFromCalendarSlot(dateStr, startStr, endStr) {
        const now = Date.now();
        if (now - lastCalendarSlotOpenAt < 120) return;
        lastCalendarSlotOpenAt = now;
        resetFormForCreate(dateStr, startStr, endStr);
        if (appointmentModal) appointmentModal.show();
    }

    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 768 ? 'timeGridDay' : 'timeGridWeek',
        height: 'auto',
        expandRows: false,
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' },
        slotDuration: '00:30:00',
        slotMinTime: <?php echo json_encode($calendar_slot_min_time); ?>,
        slotMaxTime: <?php echo json_encode($calendar_slot_max_time); ?>,
        scrollTime: <?php echo json_encode($calendar_slot_min_time); ?>,
        allDaySlot: false,
        nowIndicator: true,
        slotEventOverlap: false,
        eventMaxStack: 4,
        eventMinHeight: 32,
        eventShortHeight: 26,
        businessHours: calendarBusinessHours,
        selectable: canManage,
        selectMirror: true,
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: true },
        events: function(fetchInfo, success, failure) {
            const params = new URLSearchParams({
                start: fetchInfo.startStr,
                end: fetchInfo.endStr
            });
            getSelectedDoctorIds().forEach((id) => params.append('doctor_ids[]', id));
            fetch(`list_ajax.php?${params.toString()}`).then(r => r.json()).then(data => {
                if (!data.success) {
                    failure(data.message || 'Failed to load events');
                    return;
                }
                const filteredEvents = (data.events || []).filter((e) => visibleCalendarStatuses.has((e.status || '').trim()));
                success(filteredEvents.map(e => ({
                    id: e.id,
                    title: e.title,
                    start: e.start,
                    end: e.end,
                    backgroundColor: resolveDoctorColor(e),
                    borderColor: resolveDoctorColor(e),
                    extendedProps: e
                })));
            }).catch(failure);
        },
        dateClick: function(info) {
            if (!canManage) return;
            const startDate = new Date(info.date);
            const endDate = new Date(startDate.getTime() + (30 * 60 * 1000));
            openCreateFromCalendarSlot(
                formatLocalDate(startDate),
                formatLocalTimeHM(startDate),
                formatLocalTimeHM(endDate)
            );
        },
        select: function(info) {
            if (!canManage) return;
            const start = info.start;
            const end = info.end;
            if (!start || !end) {
                calendar.unselect();
                return;
            }
            if (info.view.type === 'dayGridMonth') {
                const d0 = new Date(start);
                d0.setHours(9, 0, 0, 0);
                const d1 = new Date(d0.getTime() + 30 * 60 * 1000);
                openCreateFromCalendarSlot(formatLocalDate(d0), formatLocalTimeHM(d0), formatLocalTimeHM(d1));
            } else {
                openCreateFromCalendarSlot(
                    formatLocalDate(start),
                    formatLocalTimeHM(start),
                    formatLocalTimeHM(end)
                );
            }
            calendar.unselect();
        },
        eventClick: function(info) {
            if (!canManage) return;
            clickedAppointmentId = info.event.id;
            clickedAppointmentData = info.event.extendedProps || {};
            updateAppointmentActionModalButtons();
            if (appointmentActionModal) appointmentActionModal.show();
        },
        eventDidMount: function(info) {
            const eventData = info.event.extendedProps || {};
            const statusText = (eventData.status || '').replace('_', ' ');
            const reasonText = eventData.reason ? `\nReason: ${eventData.reason}` : '';
            const opText = eventData.operatory_name ? `\nOperatory: ${eventData.operatory_name}` : '';
            const timeText = `${info.event.start ? info.event.start.toLocaleString() : ''} - ${info.event.end ? info.event.end.toLocaleString() : ''}`;
            info.el.setAttribute('title', `Status: ${statusText}${reasonText}${opText}\n${timeText}`);
            const doctorColor = resolveDoctorColor(eventData);
            info.el.style.setProperty('background-color', doctorColor);
            info.el.style.setProperty('border-color', doctorColor);
            info.el.style.setProperty('color', '#fff', 'important');
            info.el.querySelectorAll('.fc-event-title, .fc-event-time, .fc-event-title-container').forEach((el) => {
                el.style.setProperty('color', '#fff', 'important');
            });

            if (info.view && (info.view.type === 'timeGridWeek' || info.view.type === 'timeGridDay')) {
                const compactTitle = compactEventTitleForGrid(eventData, info.event.title);
                const titleEl = info.el.querySelector('.fc-event-title');
                if (titleEl && compactTitle) {
                    titleEl.textContent = compactTitle;
                }
            }

            if (info.view && info.view.type === 'dayGridMonth') {
                info.el.style.setProperty('color', '#fff', 'important');
            }
        }
    });

    calendar.render();

    function handleCalendarHorizontalScroll(event) {
        if (!calendar) return;

        const deltaX = Number(event.deltaX || 0);
        const deltaY = Number(event.deltaY || 0);
        const effectiveHorizontal = Math.abs(deltaX) > Math.abs(deltaY)
            ? deltaX
            : (event.shiftKey ? deltaY : 0);

        if (Math.abs(effectiveHorizontal) < 24) {
            return;
        }

        const now = Date.now();
        if (now - lastHorizontalNavigateAt < 250) {
            return;
        }
        lastHorizontalNavigateAt = now;

        event.preventDefault();
        if (effectiveHorizontal > 0) {
            calendar.next();
        } else {
            calendar.prev();
        }
    }

    if (calendarEl) {
        calendarEl.addEventListener('wheel', handleCalendarHorizontalScroll, { passive: false });
    }

    bindSelectChange('doctorFilter', function() {
        if (!calendar) {
            return;
        }
        if (canManage && !isDoctor) {
            const params = new URLSearchParams();
            getSelectedDoctorIds().forEach((id) => params.append('doctor_ids[]', id));
            const qs = params.toString();
            const url = qs ? `calendar_business_hours_ajax.php?${qs}` : 'calendar_business_hours_ajax.php';
            fetch(url)
                .then((r) => r.json())
                .then((data) => {
                    if (data.success && Array.isArray(data.business_hours)) {
                        calendarBusinessHours = data.business_hours;
                        calendar.setOption('businessHours', calendarBusinessHours);
                    }
                    calendar.refetchEvents();
                })
                .catch(() => {
                    calendar.refetchEvents();
                });
        } else {
            calendar.refetchEvents();
        }
    });
    document.querySelectorAll('.js-status-pill').forEach((pillBtn) => {
        pillBtn.addEventListener('click', function() {
            const status = (this.getAttribute('data-status') || '').trim();
            if (!status) return;
            if (visibleCalendarStatuses.has(status)) {
                visibleCalendarStatuses.delete(status);
                this.classList.remove('is-active');
                this.setAttribute('aria-pressed', 'false');
            } else {
                visibleCalendarStatuses.add(status);
                this.classList.add('is-active');
                this.setAttribute('aria-pressed', 'true');
            }
            calendar.refetchEvents();
        });
    });

    if (!canManage) return;

    function buildWhatsappLink() {
        if (!clickedAppointmentData || typeof clickedAppointmentData !== 'object') return '#';
        const phoneDigits = String(clickedAppointmentData.patient_phone || '').replace(/\D+/g, '');
        if (!phoneDigits) return '#';
        const patientLabel = String(clickedAppointmentData.title || '').split(' - ')[0] || 'Patient';
        const startLabel = clickedAppointmentData.start
            ? new Date(clickedAppointmentData.start).toLocaleString()
            : '';
        const msg = `Hello ${patientLabel}, this is a reminder for your appointment${startLabel ? ` at ${startLabel}` : ''}.`;
        return 'https://wa.me/' + phoneDigits + '?text=' + encodeURIComponent(msg);
    }

    function updateAppointmentActionModalButtons() {
        const status = String((clickedAppointmentData && clickedAppointmentData.status) || '').trim();
        const isScheduled = status === 'scheduled';
        const isCompleted = status === 'completed';
        const isCancelled = status === 'cancelled';

        const viewBtn = document.getElementById('appointmentActionViewBtn');
        const editBtn = document.getElementById('appointmentActionEditBtn');
        const cancelBtn = document.getElementById('appointmentActionCancelBtn');
        const cloneBtn = document.getElementById('appointmentActionCloneBtn');
        const messageBtn = document.getElementById('appointmentActionMessageBtn');

        if (viewBtn) {
            viewBtn.href = clickedAppointmentId ? ('view.php?id=' + encodeURIComponent(String(clickedAppointmentId))) : '#';
            viewBtn.classList.remove('d-none');
        }
        if (cloneBtn) cloneBtn.classList.remove('d-none');
        if (messageBtn) {
            const wa = buildWhatsappLink();
            messageBtn.href = wa;
            const disabled = wa === '#';
            messageBtn.classList.toggle('disabled', disabled);
            if (disabled) {
                messageBtn.setAttribute('aria-disabled', 'true');
                messageBtn.setAttribute('tabindex', '-1');
            } else {
                messageBtn.removeAttribute('aria-disabled');
                messageBtn.removeAttribute('tabindex');
            }
        }

        if (editBtn) editBtn.classList.toggle('d-none', !(isScheduled || canEditClosedAppointments));
        if (cancelBtn) cancelBtn.classList.toggle('d-none', !isScheduled);

        if (isCompleted) {
            // Completed: clone, view, message only.
            if (editBtn && !canEditClosedAppointments) editBtn.classList.add('d-none');
            if (cancelBtn) cancelBtn.classList.add('d-none');
        }
        if (isCancelled) {
            // Cancelled falls back to read/clone actions.
            if (editBtn && !canEditClosedAppointments) editBtn.classList.add('d-none');
            if (cancelBtn) cancelBtn.classList.add('d-none');
        }
    }

    const editBtn = document.getElementById('appointmentActionEditBtn');
    if (editBtn) {
        editBtn.addEventListener('click', function() {
            if (!clickedAppointmentId) return;
            if (appointmentActionModal) appointmentActionModal.hide();
            openEdit(clickedAppointmentId);
            if (appointmentModal) appointmentModal.show();
        });
    }
    const cloneBtn = document.getElementById('appointmentActionCloneBtn');
    if (cloneBtn) {
        cloneBtn.addEventListener('click', function() {
            if (!clickedAppointmentId) return;
            if (appointmentActionModal) appointmentActionModal.hide();
            openClone(clickedAppointmentId);
            if (appointmentModal) appointmentModal.show();
        });
    }
    const cancelBtn = document.getElementById('appointmentActionCancelBtn');
    if (cancelBtn) {
        cancelBtn.addEventListener('click', function() {
            if (!clickedAppointmentId) return;
            if (!window.confirm(<?php echo json_encode(trans('appointment', 'cancel_appointment_confirm')); ?>)) return;
            const fd = new FormData();
            fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
            fd.append('appointment_id', String(clickedAppointmentId));
            fd.append('action', 'cancel');
            cancelBtn.disabled = true;
            fetch('save_ajax.php', { method: 'POST', body: fd })
                .then(function(r) { return r.json(); })
                .then(function(data) {
                    if (!data || !data.success) {
                        window.alert((data && data.message) ? data.message : 'Unable to cancel appointment.');
                        return;
                    }
                    if (appointmentActionModal) appointmentActionModal.hide();
                    if (calendar) calendar.refetchEvents();
                })
                .catch(function() {
                    window.alert('Unable to cancel appointment.');
                })
                .finally(function() {
                    cancelBtn.disabled = false;
                });
        });
    }

    function initDoctorSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $doctor = $('#doctor_id');
        if ($doctor.hasClass('select2-hidden-accessible')) {
            $doctor.select2('destroy');
        }
        function doctorOptionTemplate(data) {
            if (!data.id) return data.text;
            const $row = $('<span class="dcmt-select2-option"></span>');
            $row.append($('<span></span>').text(data.text || ''));
            return $row;
        }
        function doctorSelectionTemplate(data) {
            if (!data.id) return data.text;
            return $('<span></span>').text(String(data.text || ''));
        }
        $doctor.select2({
            dropdownParent: $('#appointmentModal'),
            width: '100%',
            placeholder: <?php echo json_encode(trans('appointment', 'select')); ?>,
            allowClear: true,
            templateResult: doctorOptionTemplate,
            templateSelection: doctorSelectionTemplate
        });
        $doctor.off('select2:open.dcmtDoctorDropdown').on('select2:open.dcmtDoctorDropdown', function() {
            const openDropdown = document.querySelector('.select2-container--open .select2-dropdown');
            if (openDropdown) {
                openDropdown.classList.add('dcmt-doctor-select2');
            }
        });
    }

    function initPatientSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $patient = $('#patient_id');
        if ($patient.hasClass('select2-hidden-accessible')) {
            $patient.select2('destroy');
        }
        $patient.select2({
            dropdownParent: $('#appointmentModal'),
            width: '100%',
            placeholder: <?php echo json_encode(trans('appointment', 'select')); ?>,
            allowClear: true,
            ajax: {
                url: 'index.php?ajax=patient_search',
                dataType: 'json',
                delay: 250,
                data: function(params) {
                    return { term: params.term || '', limit: 20 };
                },
                processResults: function(data) {
                    return data;
                },
                cache: true
            },
            minimumInputLength: 0
        });
    }

    initDoctorSelect2();
    initPatientSelect2();
    loadOperatoriesGlobal();

    appointmentModalElement.addEventListener('shown.bs.modal', function() {
        if (typeof $ !== 'undefined') {
            $('#doctor_id').trigger('focus');
        }
    });

    document.getElementById('addAppointmentBtn').addEventListener('click', function() {
        resetFormForCreate();
        appointmentModal.show();
    });
    if (autoOpenAddAppointment) {
        resetFormForCreate(autoPrefillAppointmentDate, autoPrefillAppointmentStart, autoPrefillAppointmentEnd);
        appointmentModal.show();
    }

    bindSelectChange('doctor_id', function() {
        loadSlots();
    });
    bindSelectChange('operatory_id', function() { loadSlots(); });
    bindSelectChange('appointment_date', function() { loadSlots(); });
    bindSelectChange('status', function() { refreshCalendarExportLinksForForm(); });
    bindSelectChange('doctor_id', function() { refreshCalendarExportLinksForForm(); });
    bindSelectChange('patient_id', function() { refreshCalendarExportLinksForForm(); });
    bindSelectChange('operatory_id', function() { refreshCalendarExportLinksForForm(); });
    bindSelectChange('appointment_date', function() { refreshCalendarExportLinksForForm(); });
    document.getElementById('start_time').addEventListener('change', function() { refreshCalendarExportLinksForForm(); });
    document.getElementById('end_time').addEventListener('change', function() { refreshCalendarExportLinksForForm(); });
    document.getElementById('reason').addEventListener('input', function() { refreshCalendarExportLinksForForm(); });
    document.getElementById('notes').addEventListener('input', function() { refreshCalendarExportLinksForForm(); });
    const availList = document.getElementById('availabilityAvailableList');
    if (availList) {
        availList.addEventListener('click', function(e) {
            const btn = e.target && e.target.closest ? e.target.closest('button[data-slot-start][data-slot-end]') : null;
            if (!btn) return;
            const s = btn.getAttribute('data-slot-start') || '';
            const en = btn.getAttribute('data-slot-end') || '';
            document.getElementById('start_time').value = s;
            document.getElementById('end_time').value = en;
            availList.querySelectorAll('button.dcmt-slot-pill.is-active').forEach(function(b) {
                b.classList.remove('is-active', 'bg-success', 'text-white', 'border-success');
                b.classList.add('bg-light', 'text-success', 'border-success-subtle');
            });
            btn.classList.add('is-active', 'bg-success', 'text-white', 'border-success');
            btn.classList.remove('bg-light', 'text-success', 'border-success-subtle');
            validateAppointmentTimes(false);
        });
    }
    document.getElementById('start_time').addEventListener('change', function() {
        validateAppointmentTimes(false);
        const list = document.getElementById('availabilityAvailableList');
        if (!list) return;
        const s = (document.getElementById('start_time').value || '').trim();
        const en = (document.getElementById('end_time').value || '').trim();
        list.querySelectorAll('button.dcmt-slot-pill').forEach(function(b) {
            const bs = (b.getAttribute('data-slot-start') || '').trim();
            const be = (b.getAttribute('data-slot-end') || '').trim();
            const isActive = bs === s && be === en;
            if (isActive) {
                b.classList.add('is-active', 'bg-success', 'text-white', 'border-success');
                b.classList.remove('bg-light', 'text-success', 'border-success-subtle');
            } else {
                b.classList.remove('is-active', 'bg-success', 'text-white', 'border-success');
                b.classList.add('bg-light', 'text-success', 'border-success-subtle');
            }
        });
    });
    document.getElementById('end_time').addEventListener('change', function() {
        validateAppointmentTimes(false);
        const list = document.getElementById('availabilityAvailableList');
        if (!list) return;
        const s = (document.getElementById('start_time').value || '').trim();
        const en = (document.getElementById('end_time').value || '').trim();
        list.querySelectorAll('button.dcmt-slot-pill').forEach(function(b) {
            const bs = (b.getAttribute('data-slot-start') || '').trim();
            const be = (b.getAttribute('data-slot-end') || '').trim();
            const isActive = bs === s && be === en;
            if (isActive) {
                b.classList.add('is-active', 'bg-success', 'text-white', 'border-success');
                b.classList.remove('bg-light', 'text-success', 'border-success-subtle');
            } else {
                b.classList.remove('is-active', 'bg-success', 'text-white', 'border-success');
                b.classList.add('bg-light', 'text-success', 'border-success-subtle');
            }
        });
    });

    document.getElementById('saveAppointmentBtn').addEventListener('click', function() {
        clearFieldErrors();
        const operatoryVal = document.getElementById('operatory_id').value;
        if (!operatoryVal) {
            showFieldError('operatory_id', t.operatoryRequired);
            showAlert(t.operatoryRequired);
            return;
        }
        if (!validateAppointmentTimes(true)) {
            return;
        }
        const actualStart = (document.getElementById('actual_start_time').value || '').trim();
        const actualEnd = (document.getElementById('actual_end_time').value || '').trim();
        if ((actualStart && !actualEnd) || (!actualStart && actualEnd)) {
            showFieldError('actual_start_time', t.requiredFields);
            showFieldError('actual_end_time', t.requiredFields);
            showAlert(t.requiredFields);
            return;
        }
        if (actualStart && actualEnd && actualStart >= actualEnd) {
            showFieldError('actual_start_time', t.startBeforeEnd);
            showFieldError('actual_end_time', t.startBeforeEnd);
            showAlert(t.startBeforeEnd);
            return;
        }
        const form = document.getElementById('appointmentForm');
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }
        const saveBtn = document.getElementById('saveAppointmentBtn');
        const defaultBtnHtml = saveBtn.innerHTML;
        function restoreSaveBtn() {
            saveBtn.disabled = false;
            saveBtn.innerHTML = defaultBtnHtml;
        }
        function applySaveResponse(data, formData) {
            if (!data.success) {
                if (Array.isArray(data.fields)) {
                    data.fields.forEach((fieldId) => showFieldError(fieldId, data.message || <?php echo json_encode(trans('appointment', 'save_failed')); ?>));
                } else if (data.field) {
                    showFieldError(data.field, data.message || <?php echo json_encode(trans('appointment', 'save_failed')); ?>);
                }
                showAlert(data.message || <?php echo json_encode(trans('appointment', 'save_failed')); ?>);
                return;
            }
            const savedDoctorId = String(formData.get('doctor_id') || '');
            if (savedDoctorId) {
                if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
                    const $filter = $('#doctorFilter');
                    if ($filter.prop('multiple')) {
                        $filter.val([savedDoctorId]).trigger('change');
                    } else {
                        $filter.val(savedDoctorId).trigger('change');
                    }
                } else {
                    const df = document.getElementById('doctorFilter');
                    if (df) {
                        if (df.multiple) {
                            Array.from(df.options || []).forEach((opt) => {
                                opt.selected = String(opt.value) === savedDoctorId;
                            });
                            df.dispatchEvent(new Event('change', { bubbles: true }));
                        } else {
                            df.value = savedDoctorId;
                        }
                    }
                }
            }
            showAlert(data.message, 'success');
            calendar.refetchEvents();
            setTimeout(() => appointmentModal.hide(), 700);
        }
        function postSave(confirmed) {
            const formData = new FormData(form);
            if (confirmed) {
                formData.append('confirm_outside_hours', '1');
            }
            saveBtn.disabled = true;
            saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + t.processing + '...';
            fetch('save_ajax.php', { method: 'POST', body: formData })
                .then((r) => r.json())
                .then((data) => {
                    restoreSaveBtn();
                    if (!data.success && data.needs_outside_hours_confirm && !confirmed) {
                        if (window.confirm(data.message || t.outsideHoursConfirm)) {
                            postSave(true);
                        }
                        return;
                    }
                    applySaveResponse(data, formData);
                })
                .catch(() => {
                    restoreSaveBtn();
                    showAlert(<?php echo json_encode(trans('appointment', 'save_failed')); ?>);
                });
        }
        postSave(false);
    });

    const cancelAppointmentBtn = document.getElementById('cancelAppointmentBtn');
    if (cancelAppointmentBtn) {
        cancelAppointmentBtn.addEventListener('click', function() {
            const id = document.getElementById('appointment_id').value;
            if (!id) return;
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo htmlspecialchars($csrf_token); ?>');
            formData.append('action', 'cancel');
            formData.append('appointment_id', id);
            fetch('save_ajax.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showAlert(data.message || <?php echo json_encode(trans('appointment', 'save_failed')); ?>);
                        return;
                    }
                    showAlert(data.message, 'success');
                    calendar.refetchEvents();
                    setTimeout(() => appointmentModal.hide(), 700);
                })
                .catch(() => showAlert(<?php echo json_encode(trans('appointment', 'save_failed')); ?>));
        });
    }

    const showNewPatientBoxBtn = document.getElementById('showNewPatientBox');
    if (showNewPatientBoxBtn) {
        showNewPatientBoxBtn.addEventListener('click', function() {
            document.getElementById('newPatientBox').classList.toggle('d-none');
        });
    }

    document.getElementById('saveNewPatientBtn').addEventListener('click', function() {
        const fd = new FormData();
        fd.append('csrf_token', '<?php echo htmlspecialchars($csrf_token); ?>');
        fd.append('first_name', document.getElementById('new_first_name').value);
        fd.append('fathers_last_name', document.getElementById('new_fathers_last_name').value);
        fd.append('mothers_last_name', document.getElementById('new_mothers_last_name').value);
        fd.append('phone', document.getElementById('new_phone').value);
        fd.append('emergency_contact_name', document.getElementById('new_emergency_contact_name').value);
        fd.append('gender', document.getElementById('new_gender').value);

        fetch('../patients/quick_add_ajax.php', { method: 'POST', body: fd })
            .then(r => r.json())
            .then(data => {
                if (!data.success) {
                    showAlert(data.message || t.addPatientFailed);
                    return;
                }
                const select = document.getElementById('patient_id');
                const option = document.createElement('option');
                option.value = data.patient.id;
                option.textContent = data.patient.name + (data.patient.phone ? (' - ' + data.patient.phone) : '');
                option.selected = true;
                select.appendChild(option);
                if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
                    $('#patient_id').val(String(data.patient.id)).trigger('change.select2');
                }
                showAlert(data.message, 'success');
                document.getElementById('newPatientBox').classList.add('d-none');
            })
            .catch(() => showAlert(t.addPatientFailed));
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
