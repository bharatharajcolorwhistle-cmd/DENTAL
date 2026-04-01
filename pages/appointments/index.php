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
$can_manage = in_array($current_role, ['admin', 'staff'], true);
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
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
    exit();
}

$csrf_token = dcmt_generate_csrf_token();

$doctors = [];
$patients = [];

try {
    $doctor_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_full_name FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name ASC");
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);

    $patient_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_patient_name, dcmt_phone FROM dcmt_patients ORDER BY dcmt_patient_name ASC LIMIT 200");
    $patients = $patient_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Appointment index load error: ' . $e->getMessage());
}

$doctor_filter_id = $is_doctor ? (int)$current_user['dcmt_id'] : (int)($doctors[0]['dcmt_id'] ?? 0);
$status_options = ['scheduled', 'completed', 'cancelled'];

require_once __DIR__ . '/../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<link href="../../assets/css/select2.min.css" rel="stylesheet">
<script src="../../assets/js/select2.min.js"></script>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i><?php echo trans('appointment', 'appointment_calendar'); ?></h6>
        <div class="d-flex align-items-center gap-2">
            <a href="list.php" class="btn btn-sm btn-outline-info">
                <i class="fas fa-list me-1"></i><?php echo trans('appointment', 'created_appointments'); ?>
            </a>
            <?php if ($current_role === 'admin'): ?>
                <a href="duty_hours.php" class="btn btn-sm btn-outline-secondary">
                    <i class="fas fa-user-clock me-1"></i><?php echo trans('appointment', 'doctor_duty_hours'); ?>
                </a>
            <?php endif; ?>
            <?php if ($can_manage): ?>
                <button type="button" class="btn btn-sm btn-primary" id="addAppointmentBtn">
                    <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'new_appointment'); ?>
                </button>
            <?php endif; ?>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-3">
            <div class="col-md-4">
                <label class="form-label"><?php echo trans('appointment', 'doctor'); ?></label>
                <select id="doctorFilter" class="form-select" <?php echo $is_doctor ? 'disabled' : ''; ?>>
                    <?php foreach ($doctors as $doctor): ?>
                        <option value="<?php echo (int)$doctor['dcmt_id']; ?>" <?php echo ((int)$doctor['dcmt_id'] === $doctor_filter_id) ? 'selected' : ''; ?>>
                            <?php echo htmlspecialchars($doctor['dcmt_full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
        </div>
        <div class="d-flex flex-wrap gap-2 mb-3">
            <span class="badge" style="background-color:#0d6efd;"><?php echo trans('appointment', 'scheduled'); ?></span>
            <span class="badge" style="background-color:#6c757d;"><?php echo trans('appointment', 'completed'); ?></span>
            <span class="badge" style="background-color:#dc3545;"><?php echo trans('appointment', 'cancelled'); ?></span>
        </div>
        <div id="appointmentCalendar"></div>
    </div>
</div>

<?php if ($can_manage): ?>
<div class="modal fade" id="appointmentModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="appointmentModalTitle"><?php echo trans('appointment', 'add_appointment'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <div id="appointmentAlert" class="alert d-none" role="alert"></div>
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
                                    <option value="<?php echo (int)$doctor['dcmt_id']; ?>">
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
                                        <input type="text" class="form-control" id="new_phone">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('appointment', 'email'); ?></label>
                                        <input type="email" class="form-control" id="new_email">
                                    </div>
                                    <div class="col-md-4">
                                        <label class="form-label"><?php echo trans('appointment', 'gender'); ?></label>
                                        <select id="new_gender" class="form-select">
                                            <option value="other"><?php echo trans('appointment', 'other'); ?></option>
                                            <option value="male"><?php echo trans('appointment', 'male'); ?></option>
                                            <option value="female"><?php echo trans('appointment', 'female'); ?></option>
                                        </select>
                                    </div>
                                </div>
                                <button type="button" id="saveNewPatientBtn" class="btn btn-sm btn-outline-primary mt-2"><?php echo trans('appointment', 'save_patient'); ?></button>
                            </div>
                        </div>

                        <div class="col-md-4">
                            <label class="form-label"><?php echo trans('appointment', 'date'); ?></label>
                            <input type="date" name="appointment_date" id="appointment_date" class="form-control" value="<?php echo htmlspecialchars(dcmt_get_current_date()); ?>" required>
                            <div class="invalid-feedback" id="appointment_date_error"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo trans('appointment', 'start_time'); ?></label>
                            <input type="time" name="start_time" id="start_time" class="form-control" step="60" required>
                            <div class="invalid-feedback" id="start_time_error"></div>
                        </div>
                        <div class="col-md-4">
                            <label class="form-label"><?php echo trans('appointment', 'end_time'); ?></label>
                            <input type="time" name="end_time" id="end_time" class="form-control" step="60" required>
                            <div class="invalid-feedback" id="end_time_error"></div>
                        </div>
                        <div class="col-md-6">
                            <label class="form-label"><?php echo trans('appointment', 'status'); ?></label>
                            <select name="status" id="status" class="form-select" required>
                                <?php foreach ($status_options as $status): ?>
                                    <option value="<?php echo $status; ?>"><?php echo trans('appointment', $status); ?></option>
                                <?php endforeach; ?>
                            </select>
                            <div class="invalid-feedback" id="status_error"></div>
                        </div>
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
                <button type="button" id="cancelAppointmentBtn" class="btn btn-outline-danger me-auto d-none"><?php echo trans('appointment', 'cancel_appointment'); ?></button>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('appointment', 'close'); ?></button>
                <button type="button" id="saveAppointmentBtn" class="btn btn-primary"><?php echo trans('appointment', 'save'); ?></button>
            </div>
        </div>
    </div>
</div>
<?php endif; ?>

<style>
#appointmentCalendar { min-height: 650px; }
@media (max-width: 768px) {
    #appointmentCalendar { min-height: 500px; }
}
.select2-container .select2-selection.is-invalid {
    border-color: #dc3545 !important;
}
</style>

<script>
const isDoctor = <?php echo $is_doctor ? 'true' : 'false'; ?>;
const canManage = <?php echo $can_manage ? 'true' : 'false'; ?>;
const currentDoctorId = <?php echo (int)$doctor_filter_id; ?>;
const todayDate = <?php echo json_encode(dcmt_get_current_date('Y-m-d')); ?>;
const t = {
    addAppointment: <?php echo json_encode(trans('appointment', 'add_appointment')); ?>,
    editAppointment: <?php echo json_encode(trans('appointment', 'edit_appointment')); ?>,
    loadSlotsFailed: <?php echo json_encode(trans('appointment', 'load_slots_failed')); ?>,
    loadAppointmentFailed: <?php echo json_encode(trans('appointment', 'load_appointment_failed')); ?>,
    addPatientFailed: <?php echo json_encode(trans('appointment', 'add_patient_failed')); ?>,
    processing: <?php echo json_encode(trans('common', 'processing')); ?>,
    slotChanged: <?php echo json_encode(trans('appointment', 'slot_changed')); ?>,
    outsideDutyHours: <?php echo json_encode(trans('appointment', 'outside_duty_hours')); ?>,
    startBeforeEnd: <?php echo json_encode(trans('appointment', 'start_before_end')); ?>,
    select: <?php echo json_encode(trans('appointment', 'select')); ?>
};
let calendar;
let availableSlots = [];
let editingOriginalSlot = { start: '', end: '' };

function buildGoogleCalendarUrl(title, details, startIso, endIso) {
    const formatUtc = (isoString) => {
        const dt = new Date(isoString);
        const y = dt.getUTCFullYear();
        const m = String(dt.getUTCMonth() + 1).padStart(2, '0');
        const d = String(dt.getUTCDate()).padStart(2, '0');
        const hh = String(dt.getUTCHours()).padStart(2, '0');
        const mm = String(dt.getUTCMinutes()).padStart(2, '0');
        const ss = String(dt.getUTCSeconds()).padStart(2, '0');
        return `${y}${m}${d}T${hh}${mm}${ss}Z`;
    };
    const dates = `${formatUtc(startIso)}/${formatUtc(endIso)}`;
    return 'https://calendar.google.com/calendar/render?action=TEMPLATE'
        + '&text=' + encodeURIComponent(title)
        + '&dates=' + encodeURIComponent(dates)
        + '&details=' + encodeURIComponent(details);
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

function statusColor(status) {
    const map = {
        scheduled: '#0d6efd',
        completed: '#6c757d',
        cancelled: '#dc3545'
    };
    return map[status] || '#0d6efd';
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
    const fields = ['doctor_id', 'patient_id', 'appointment_date', 'start_time', 'end_time', 'status'];
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

function loadSlots(prefillStart = '', prefillEnd = '') {
    const doctorId = document.getElementById('doctor_id').value;
    const appointmentDate = document.getElementById('appointment_date').value;
    const startInput = document.getElementById('start_time');
    const endInput = document.getElementById('end_time');
    availableSlots = [];
    if (!prefillStart) startInput.value = '';
    if (!prefillEnd) endInput.value = '';

    if (!doctorId || !appointmentDate) return;

    fetch(`available_slots_ajax.php?doctor_id=${encodeURIComponent(doctorId)}&date=${encodeURIComponent(appointmentDate)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showAlert(data.message || t.loadSlotsFailed);
                return;
            }
            availableSlots = Array.isArray(data.slots) ? data.slots : [];
            if (prefillStart) startInput.value = prefillStart;
            if (prefillEnd) endInput.value = prefillEnd;
            validateAppointmentTimes(false);
        })
        .catch(() => showAlert(t.loadSlotsFailed));
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

function resetFormForCreate(dateStr = '', startStr = '', endStr = '') {
    document.getElementById('appointmentModalTitle').textContent = t.addAppointment;
    document.getElementById('form_action').value = 'create';
    document.getElementById('appointment_id').value = '';
    editingOriginalSlot = { start: '', end: '' };
    document.getElementById('appointmentForm').reset();
    document.getElementById('doctor_id').value = '';
    document.getElementById('patient_id').value = '';
    if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
        $('#doctor_id').val(null).trigger('change.select2');
        $('#patient_id').val(null).trigger('change.select2');
    }
    document.getElementById('appointment_date').value = dateStr || todayDate;
    document.getElementById('cancelAppointmentBtn').classList.add('d-none');
    toggleCalendarExportLinks(false);
    hideAlert();
    clearFieldErrors();
    loadSlots(startStr, endStr);
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
            document.getElementById('reason').value = a.reason || '';
            document.getElementById('notes').value = a.notes || '';
            document.getElementById('cancelAppointmentBtn').classList.remove('d-none');
            const isScheduled = String(a.status || '') === 'scheduled';
            if (isScheduled) {
                const title = `Appointment - ${a.patient_name || ''}` + (a.reason ? ` (${a.reason})` : '');
                let details = `Patient: ${a.patient_name || ''}\nDoctor: ${a.doctor_name || ''}`;
                if (a.reason) details += `\nReason: ${a.reason}`;
                if (a.notes) details += `\nNotes: ${a.notes}`;
                const startIso = `${a.date}T${a.start_time}:00`;
                const endIso = `${a.date}T${a.end_time}:00`;
                const googleUrl = buildGoogleCalendarUrl(title, details, startIso, endIso);
                const icsUrl = `export_ics.php?id=${encodeURIComponent(a.id)}`;
                toggleCalendarExportLinks(true, googleUrl, icsUrl);
            } else {
                toggleCalendarExportLinks(false);
            }
            hideAlert();
            clearFieldErrors();
            editingOriginalSlot = { start: a.start_time, end: a.end_time };
            loadSlots(a.start_time, a.end_time);
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

    function initTopDoctorFilterSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $doctorFilter = $('#doctorFilter');
        if ($doctorFilter.length === 0) return;
        if ($doctorFilter.hasClass('select2-hidden-accessible')) {
            $doctorFilter.select2('destroy');
        }
        $doctorFilter.select2({
            width: '100%',
            placeholder: <?php echo json_encode(trans('appointment', 'select')); ?>,
            allowClear: false
        });
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

    calendar = new FullCalendar.Calendar(calendarEl, {
        initialView: window.innerWidth < 768 ? 'timeGridDay' : 'timeGridWeek',
        headerToolbar: { left: 'prev,next today', center: 'title', right: 'timeGridDay,timeGridWeek,dayGridMonth' },
        slotDuration: '00:30:00',
        allDaySlot: false,
        nowIndicator: true,
        selectable: canManage,
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: true },
        events: function(fetchInfo, success, failure) {
            const doctorId = doctorFilter.value || currentDoctorId;
            const url = `list_ajax.php?start=${encodeURIComponent(fetchInfo.startStr)}&end=${encodeURIComponent(fetchInfo.endStr)}&doctor_id=${encodeURIComponent(doctorId)}`;
            fetch(url).then(r => r.json()).then(data => {
                if (!data.success) {
                    failure(data.message || 'Failed to load events');
                    return;
                }
                success(data.events.map(e => ({
                    id: e.id,
                    title: e.title,
                    start: e.start,
                    end: e.end,
                    backgroundColor: statusColor(e.status),
                    borderColor: statusColor(e.status),
                    extendedProps: e
                })));
            }).catch(failure);
        },
        dateClick: function(info) {
            if (!canManage) return;
            const datePart = info.dateStr.substring(0, 10);
            const startPart = info.dateStr.substring(11, 16);
            const startDate = new Date(info.date);
            const endDate = new Date(startDate.getTime() + (30 * 60 * 1000));
            const endPart = endDate.toISOString().substring(11, 16);
            resetFormForCreate(datePart, startPart, endPart);
            if (appointmentModal) appointmentModal.show();
        },
        eventClick: function(info) {
            if (!canManage) return;
            openEdit(info.event.id);
            if (appointmentModal) appointmentModal.show();
        },
        eventDidMount: function(info) {
            const eventData = info.event.extendedProps || {};
            const statusText = (eventData.status || '').replace('_', ' ');
            const reasonText = eventData.reason ? `\nReason: ${eventData.reason}` : '';
            const timeText = `${info.event.start ? info.event.start.toLocaleString() : ''} - ${info.event.end ? info.event.end.toLocaleString() : ''}`;
            info.el.setAttribute('title', `Status: ${statusText}${reasonText}\n${timeText}`);
        }
    });

    calendar.render();

    bindSelectChange('doctorFilter', function() {
        calendar.refetchEvents();
    });

    if (!canManage) return;

    function initDoctorSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $doctor = $('#doctor_id');
        if ($doctor.hasClass('select2-hidden-accessible')) {
            $doctor.select2('destroy');
        }
        $doctor.select2({
            dropdownParent: $('#appointmentModal'),
            width: '100%',
            placeholder: <?php echo json_encode(trans('appointment', 'select')); ?>,
            allowClear: true
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

    appointmentModalElement.addEventListener('shown.bs.modal', function() {
        if (typeof $ !== 'undefined') {
            $('#doctor_id').trigger('focus');
        }
    });

    document.getElementById('addAppointmentBtn').addEventListener('click', function() {
        resetFormForCreate();
        appointmentModal.show();
    });

    bindSelectChange('doctor_id', function() { loadSlots(); });
    bindSelectChange('appointment_date', function() { loadSlots(); });
    document.getElementById('start_time').addEventListener('change', function() {
        validateAppointmentTimes(false);
    });
    document.getElementById('end_time').addEventListener('change', function() {
        validateAppointmentTimes(false);
    });

    document.getElementById('saveAppointmentBtn').addEventListener('click', function() {
        clearFieldErrors();
        if (!validateAppointmentTimes(true)) {
            return;
        }
        const form = document.getElementById('appointmentForm');
        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }
        const saveBtn = document.getElementById('saveAppointmentBtn');
        const defaultBtnHtml = saveBtn.innerHTML;
        saveBtn.disabled = true;
        saveBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + t.processing + '...';
        const formData = new FormData(form);
        fetch('save_ajax.php', { method: 'POST', body: formData })
            .then(r => r.json())
            .then(data => {
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
                        $('#doctorFilter').val(savedDoctorId).trigger('change');
                    } else {
                        document.getElementById('doctorFilter').value = savedDoctorId;
                    }
                }
                showAlert(data.message, 'success');
                calendar.refetchEvents();
                setTimeout(() => appointmentModal.hide(), 700);
            })
            .catch(() => showAlert(<?php echo json_encode(trans('appointment', 'save_failed')); ?>))
            .finally(() => {
                saveBtn.disabled = false;
                saveBtn.innerHTML = defaultBtnHtml;
            });
    });

    document.getElementById('cancelAppointmentBtn').addEventListener('click', function() {
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

    document.getElementById('showNewPatientBox').addEventListener('click', function() {
        document.getElementById('newPatientBox').classList.toggle('d-none');
    });

    document.getElementById('saveNewPatientBtn').addEventListener('click', function() {
        const fd = new FormData();
        fd.append('csrf_token', '<?php echo htmlspecialchars($csrf_token); ?>');
        fd.append('first_name', document.getElementById('new_first_name').value);
        fd.append('fathers_last_name', document.getElementById('new_fathers_last_name').value);
        fd.append('mothers_last_name', document.getElementById('new_mothers_last_name').value);
        fd.append('phone', document.getElementById('new_phone').value);
        fd.append('email', document.getElementById('new_email').value);
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
