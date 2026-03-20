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
$status_options = ['scheduled', 'confirmed', 'completed', 'cancelled', 'no_show'];

require_once __DIR__ . '/../../includes/header.php';
?>

<link href="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/main.min.css" rel="stylesheet">
<script src="https://cdn.jsdelivr.net/npm/fullcalendar@6.1.15/index.global.min.js"></script>
<link href="../../assets/css/select2.min.css" rel="stylesheet">
<script src="../../assets/js/select2.min.js"></script>

<div class="card mb-3">
    <div class="card-header d-flex justify-content-between align-items-center">
        <h6 class="mb-0"><i class="fas fa-calendar-alt me-2"></i><?php echo trans('appointment', 'appointment_calendar'); ?></h6>
        <?php if ($can_manage): ?>
            <button type="button" class="btn btn-sm btn-primary" id="addAppointmentBtn">
                <i class="fas fa-plus me-1"></i><?php echo trans('appointment', 'new_appointment'); ?>
            </button>
        <?php endif; ?>
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
        <div id="appointmentCalendar"></div>
    </div>
</div>

<?php if ($current_role === 'admin'): ?>
<div class="card mb-3">
    <div class="card-header">
        <h6 class="mb-0"><i class="fas fa-user-clock me-2"></i><?php echo trans('appointment', 'doctor_duty_hours'); ?></h6>
    </div>
    <div class="card-body">
        <div id="dutyAlert" class="alert d-none" role="alert"></div>
        <div class="row g-3 align-items-end mb-3">
            <div class="col-md-4">
                <label class="form-label"><?php echo trans('appointment', 'doctor'); ?></label>
                <select id="dutyDoctorId" class="form-select">
                    <?php foreach ($doctors as $doctor): ?>
                        <option value="<?php echo (int)$doctor['dcmt_id']; ?>">
                            <?php echo htmlspecialchars($doctor['dcmt_full_name']); ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="col-md-auto">
                <button type="button" id="saveDutyBtn" class="btn btn-outline-primary"><?php echo trans('appointment', 'save_duty_hours'); ?></button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr><th><?php echo trans('appointment', 'day'); ?></th><th><?php echo trans('appointment', 'active'); ?></th><th><?php echo trans('appointment', 'start'); ?></th><th><?php echo trans('appointment', 'end'); ?></th></tr>
                </thead>
                <tbody id="dutyTableBody"></tbody>
            </table>
        </div>
    </div>
</div>
<?php endif; ?>

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
const isAdmin = <?php echo $current_role === 'admin' ? 'true' : 'false'; ?>;
const currentDoctorId = <?php echo (int)$doctor_filter_id; ?>;
const todayDate = <?php echo json_encode(dcmt_get_current_date('Y-m-d')); ?>;
const t = {
    addAppointment: <?php echo json_encode(trans('appointment', 'add_appointment')); ?>,
    editAppointment: <?php echo json_encode(trans('appointment', 'edit_appointment')); ?>,
    loadSlotsFailed: <?php echo json_encode(trans('appointment', 'load_slots_failed')); ?>,
    loadAppointmentFailed: <?php echo json_encode(trans('appointment', 'load_appointment_failed')); ?>,
    loadDutyFailed: <?php echo json_encode(trans('appointment', 'load_duty_failed')); ?>,
    saveDutyFailed: <?php echo json_encode(trans('appointment', 'save_duty_failed')); ?>,
    addPatientFailed: <?php echo json_encode(trans('appointment', 'add_patient_failed')); ?>,
    processing: <?php echo json_encode(trans('common', 'processing')); ?>,
    slotChanged: <?php echo json_encode(trans('appointment', 'slot_changed')); ?>,
    outsideDutyHours: <?php echo json_encode(trans('appointment', 'outside_duty_hours')); ?>,
    startBeforeEnd: <?php echo json_encode(trans('appointment', 'start_before_end')); ?>,
    select: <?php echo json_encode(trans('appointment', 'select')); ?>,
    sunday: <?php echo json_encode(trans('appointment', 'sunday')); ?>,
    monday: <?php echo json_encode(trans('appointment', 'monday')); ?>,
    tuesday: <?php echo json_encode(trans('appointment', 'tuesday')); ?>,
    wednesday: <?php echo json_encode(trans('appointment', 'wednesday')); ?>,
    thursday: <?php echo json_encode(trans('appointment', 'thursday')); ?>,
    friday: <?php echo json_encode(trans('appointment', 'friday')); ?>,
    saturday: <?php echo json_encode(trans('appointment', 'saturday')); ?>
};
let calendar;
let availableSlots = [];
let editingOriginalSlot = { start: '', end: '' };

function statusColor(status) {
    const map = {
        scheduled: '#0d6efd',
        confirmed: '#198754',
        completed: '#6c757d',
        cancelled: '#dc3545',
        no_show: '#fd7e14'
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

function showDutyAlert(message, type = 'danger') {
    const box = document.getElementById('dutyAlert');
    if (!box) return;
    box.className = 'alert alert-' + type;
    box.textContent = message;
    box.classList.remove('d-none');
}

const weekdays = [t.sunday, t.monday, t.tuesday, t.wednesday, t.thursday, t.friday, t.saturday];
function renderDutyTable(rows = []) {
    const map = {};
    rows.forEach(r => { map[String(r.dcmt_weekday)] = r; });
    const tbody = document.getElementById('dutyTableBody');
    if (!tbody) return;
    tbody.innerHTML = '';
    for (let i = 0; i <= 6; i++) {
        const row = map[String(i)] || {};
        const active = Number(row.dcmt_is_active || 0) === 1;
        const start = (row.dcmt_start_time || '09:00:00').substring(0, 5);
        const end = (row.dcmt_end_time || '17:00:00').substring(0, 5);
        const tr = document.createElement('tr');
        tr.innerHTML = `
            <td>${weekdays[i]}</td>
            <td><input type="checkbox" class="form-check-input duty-active" data-day="${i}" ${active ? 'checked' : ''}></td>
            <td><input type="time" class="form-control duty-start" data-day="${i}" value="${start}"></td>
            <td><input type="time" class="form-control duty-end" data-day="${i}" value="${end}"></td>
        `;
        tbody.appendChild(tr);
    }
}

function loadDutyHours() {
    if (!isAdmin) return;
    const doctorId = document.getElementById('dutyDoctorId').value;
    fetch(`get_duty_hours_ajax.php?doctor_id=${encodeURIComponent(doctorId)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showDutyAlert(data.message || t.loadDutyFailed);
                return;
            }
            renderDutyTable(data.duty_hours || []);
        })
        .catch(() => showDutyAlert(t.loadDutyFailed));
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

    function initDutyDoctorSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $dutyDoctor = $('#dutyDoctorId');
        if ($dutyDoctor.length === 0) return;
        if ($dutyDoctor.hasClass('select2-hidden-accessible')) {
            $dutyDoctor.select2('destroy');
        }
        $dutyDoctor.select2({
            width: '100%',
            placeholder: <?php echo json_encode(trans('appointment', 'select')); ?>,
            allowClear: false
        });
    }
    initDutyDoctorSelect2();

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
        eventTimeFormat: { hour: '2-digit', minute: '2-digit', hour12: false },
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
        }
    });

    calendar.render();

    bindSelectChange('doctorFilter', function() {
        calendar.refetchEvents();
    });

    if (isAdmin) {
        loadDutyHours();
        bindSelectChange('dutyDoctorId', loadDutyHours);
        document.getElementById('saveDutyBtn').addEventListener('click', function() {
            const saveDutyBtn = document.getElementById('saveDutyBtn');
            const saveDutyOriginalHtml = saveDutyBtn.innerHTML;
            saveDutyBtn.disabled = true;
            saveDutyBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + t.processing + '...';

            const doctorId = document.getElementById('dutyDoctorId').value;
            const formData = new FormData();
            formData.append('csrf_token', '<?php echo htmlspecialchars($csrf_token); ?>');
            formData.append('doctor_id', doctorId);
            for (let i = 0; i <= 6; i++) {
                const active = document.querySelector(`.duty-active[data-day="${i}"]`);
                const start = document.querySelector(`.duty-start[data-day="${i}"]`);
                const end = document.querySelector(`.duty-end[data-day="${i}"]`);
                if (active && active.checked) {
                    formData.append(`duty[${i}][active]`, '1');
                }
                formData.append(`duty[${i}][start]`, start ? start.value : '09:00');
                formData.append(`duty[${i}][end]`, end ? end.value : '17:00');
            }
            fetch('save_duty_hours_ajax.php', { method: 'POST', body: formData })
                .then(r => r.json())
                .then(data => {
                    if (!data.success) {
                        showDutyAlert(data.message || t.saveDutyFailed);
                        return;
                    }
                    showDutyAlert(data.message, 'success');
                    loadDutyHours();
                })
                .catch(() => showDutyAlert(t.saveDutyFailed))
                .finally(() => {
                    saveDutyBtn.disabled = false;
                    saveDutyBtn.innerHTML = saveDutyOriginalHtml;
                });
        });
    }

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
