<?php
/**
 * Edit Appointment Page
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

if (!$can_manage) {
    dcmt_show_message('Access denied.', 'danger');
    $dcmt_deny_redirect = ($current_role === 'doctor')
        ? DCMT_APP_URL . '/pages/appointments/index.php'
        : DCMT_APP_URL . '/pages/dashboard/index.php';
    dcmt_redirect($dcmt_deny_redirect);
    exit();
}

$appointment_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($appointment_id <= 0) {
    dcmt_show_message(trans('appointment', 'invalid_appointment_id'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/pages/appointments/list.php');
    exit();
}

$appointment = null;
try {
    $appt_stmt = $dcmt_pdo->prepare('SELECT * FROM dcmt_appointments WHERE dcmt_id = ? LIMIT 1');
    $appt_stmt->execute([$appointment_id]);
    $appointment = $appt_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Edit appointment load error: ' . $e->getMessage());
}

if (!$appointment) {
    dcmt_show_message(trans('appointment', 'appointment_not_found'), 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/appointments/list.php');
    exit();
}

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

$csrf_token = dcmt_generate_csrf_token();
$doctors = [];
$patients = [];
$operatories = [];
$prefill_patient_id = (int)($appointment['dcmt_patient_id'] ?? 0);
$appt_doctor_id = (int)($appointment['dcmt_doctor_id'] ?? 0);
$appt_operatory_id = (int)($appointment['dcmt_operatory_id'] ?? 0);
$appt_date = !empty($appointment['dcmt_start_at']) ? date('Y-m-d', strtotime($appointment['dcmt_start_at'])) : '';
$appt_start = !empty($appointment['dcmt_start_at']) ? date('H:i', strtotime($appointment['dcmt_start_at'])) : '';
$appt_end = !empty($appointment['dcmt_end_at']) ? date('H:i', strtotime($appointment['dcmt_end_at'])) : '';
$appt_actual_start = !empty($appointment['dcmt_actual_start_at']) ? date('H:i', strtotime($appointment['dcmt_actual_start_at'])) : '';
$appt_actual_end = !empty($appointment['dcmt_actual_end_at']) ? date('H:i', strtotime($appointment['dcmt_actual_end_at'])) : '';
$appt_reason = (string)($appointment['dcmt_reason'] ?? '');
$appt_notes = (string)($appointment['dcmt_notes'] ?? '');
$appt_status = dcmt_normalize_appointment_status((string)($appointment['dcmt_status'] ?? 'scheduled'));

try {
    $doctor_stmt = $dcmt_pdo->prepare("
        SELECT dcmt_id, dcmt_full_name, COALESCE(dcmt_color_code, '') AS dcmt_color_code
        FROM dcmt_users
        WHERE dcmt_role = 'doctor' AND (dcmt_status = 'active' OR dcmt_id = ?)
        ORDER BY dcmt_full_name ASC
    ");
    $doctor_stmt->execute([$appt_doctor_id]);
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);

    $patient_stmt = $dcmt_pdo->query("
        SELECT dcmt_id, dcmt_patient_name, dcmt_phone
        FROM dcmt_patients
        ORDER BY dcmt_patient_name ASC
        LIMIT 300
    ");
    $patients = $patient_stmt->fetchAll(PDO::FETCH_ASSOC);

    $operatory_stmt = $dcmt_pdo->prepare("
        SELECT dcmt_id, dcmt_name
        FROM dcmt_operatories
        WHERE dcmt_is_active = 1 OR dcmt_id = ?
        ORDER BY dcmt_name ASC
    ");
    $operatory_stmt->execute([$appt_operatory_id]);
    $operatories = $operatory_stmt->fetchAll(PDO::FETCH_ASSOC);

    if ($prefill_patient_id > 0) {
        $has_prefill_patient = false;
        foreach ($patients as $patient_row) {
            if ((int)($patient_row['dcmt_id'] ?? 0) === $prefill_patient_id) {
                $has_prefill_patient = true;
                break;
            }
        }

        if (!$has_prefill_patient) {
            $prefill_stmt = $dcmt_pdo->prepare("
                SELECT dcmt_id, dcmt_patient_name, dcmt_phone
                FROM dcmt_patients
                WHERE dcmt_id = ?
                LIMIT 1
            ");
            $prefill_stmt->execute([$prefill_patient_id]);
            $prefill_patient_row = $prefill_stmt->fetch(PDO::FETCH_ASSOC);
            if ($prefill_patient_row) {
                $patients[] = $prefill_patient_row;
            }
        }
    }
} catch (PDOException $e) {
    error_log('Edit appointment page load error: ' . $e->getMessage());
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">
<link href="<?php echo dcmt_asset('assets/css/select2.min.css', '../../'); ?>" rel="stylesheet">
<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('appointment', 'edit_appointment'); ?></h1>
            <a href="list.php" class="dcmt-add-form-view-all-link"><?php echo trans('appointment', 'created_appointments'); ?></a>
        </div>
    </div>

    <div id="formAlert" class="alert d-none" role="alert" data-persistent="true"></div>

    <form id="appointmentEditForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">
        <input type="hidden" name="appointment_id" value="<?php echo (int)$appointment_id; ?>">
        <input type="hidden" name="action" value="update">

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="doctor_id" class="form-label"><?php echo trans('appointment', 'doctor'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="doctor_id" name="doctor_id" required>
                        <option value=""><?php echo trans('appointment', 'select'); ?></option>
                        <?php foreach ($doctors as $doctor): ?>
                            <option value="<?php echo (int)$doctor['dcmt_id']; ?>" data-color="<?php echo htmlspecialchars((string)($doctor['dcmt_color_code'] ?? '')); ?>" <?php echo ($appt_doctor_id === (int)$doctor['dcmt_id']) ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$doctor['dcmt_full_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback" id="doctor_id_error"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="patient_id" class="form-label"><?php echo trans('appointment', 'patient'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="patient_id" name="patient_id" required>
                        <option value=""><?php echo trans('appointment', 'select'); ?></option>
                        <?php foreach ($patients as $patient): ?>
                            <?php
                            $patient_text = (string)$patient['dcmt_patient_name'];
                            if (!empty($patient['dcmt_phone'])) {
                                $patient_text .= ' - ' . (string)$patient['dcmt_phone'];
                            }
                            ?>
                            <option value="<?php echo (int)$patient['dcmt_id']; ?>" <?php echo $prefill_patient_id === (int)$patient['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($patient_text); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback" id="patient_id_error"></div>
                </div>
            </div>
        </div>

        <div id="newPatientBox" class="row d-none">
            <div class="col-12">
                <div class="border rounded p-3 bg-light mb-3">
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
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="operatory_id" class="form-label"><?php echo trans('appointment', 'operatory'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="operatory_id" name="operatory_id" required>
                        <option value=""><?php echo trans('appointment', 'select'); ?></option>
                        <?php foreach ($operatories as $operatory): ?>
                            <option value="<?php echo (int)$operatory['dcmt_id']; ?>" <?php echo $appt_operatory_id === (int)$operatory['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars((string)$operatory['dcmt_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="invalid-feedback" id="operatory_id_error"></div>
                    <div class="form-text"><?php echo trans('appointment', 'operatory_help_doctor'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="appointment_date" class="form-label"><?php echo trans('appointment', 'date'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="appointment_date" name="appointment_date" value="<?php echo htmlspecialchars($appt_date); ?>" required>
                    <div class="invalid-feedback" id="appointment_date_error"></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="start_time" class="form-label"><?php echo trans('appointment', 'start_time'); ?> <span class="text-danger">*</span></label>
                    <input type="time" class="form-control" id="start_time" name="start_time" step="60" value="<?php echo htmlspecialchars($appt_start); ?>" required>
                    <div class="invalid-feedback" id="start_time_error"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="end_time" class="form-label"><?php echo trans('appointment', 'end_time'); ?> <span class="text-danger">*</span></label>
                    <input type="time" class="form-control" id="end_time" name="end_time" step="60" value="<?php echo htmlspecialchars($appt_end); ?>" required>
                    <div class="invalid-feedback" id="end_time_error"></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-12 d-none" id="appointmentAvailabilityPanel">
                <div class="border rounded-3 bg-light p-3 mb-3">
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
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="actual_start_time" class="form-label"><?php echo trans('appointment', 'actual_start_time'); ?></label>
                    <input type="time" class="form-control" id="actual_start_time" name="actual_start_time" step="60" value="<?php echo htmlspecialchars($appt_actual_start); ?>">
                    <div class="invalid-feedback" id="actual_start_time_error"></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="actual_end_time" class="form-label"><?php echo trans('appointment', 'actual_end_time'); ?></label>
                    <input type="time" class="form-control" id="actual_end_time" name="actual_end_time" step="60" value="<?php echo htmlspecialchars($appt_actual_end); ?>">
                    <div class="invalid-feedback" id="actual_end_time_error"></div>
                </div>
            </div>
        </div>

        <input type="hidden" name="status" id="status" value="<?php echo htmlspecialchars($appt_status); ?>">

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="reason" class="form-label"><?php echo trans('appointment', 'reason'); ?></label>
                    <input type="text" class="form-control" id="reason" name="reason" maxlength="255" value="<?php echo htmlspecialchars($appt_reason); ?>">
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="notes" class="form-label"><?php echo trans('appointment', 'notes'); ?></label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($appt_notes); ?></textarea>
                </div>
            </div>
        </div>

        <div class="dcmt-form-actions">
            <a href="view.php?id=<?php echo (int)$appointment_id; ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('appointment', 'update_appointment'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const form = document.getElementById('appointmentEditForm');
    const submitBtn = document.getElementById('submitBtn');
    const alertBox = document.getElementById('formAlert');
    const t = {
        loadSlotsFailed: <?php echo json_encode(trans('appointment', 'load_slots_failed')); ?>,
        addPatientFailed: <?php echo json_encode(trans('appointment', 'add_patient_failed')); ?>,
        processing: <?php echo json_encode(trans('common', 'processing')); ?>,
        startBeforeEnd: <?php echo json_encode(trans('appointment', 'start_before_end')); ?>,
        select: <?php echo json_encode(trans('appointment', 'select')); ?>,
        operatoryRequired: <?php echo json_encode(trans('appointment', 'operatory_required')); ?>,
        availabilityNoFree: <?php echo json_encode(trans('appointment', 'availability_no_free')); ?>,
        availabilityNoneBooked: <?php echo json_encode(trans('appointment', 'availability_none_booked')); ?>,
        outsideHoursConfirm: <?php echo json_encode(trans('appointment', 'outside_hours_confirm_prompt')); ?>
    };
    let availableSlots = [];
    let busySlots = [];

    function showAlert(message, type) {
        alertBox.className = 'alert alert-' + type;
        alertBox.textContent = message || '';
        alertBox.classList.remove('d-none');
    }

    function clearAlert() {
        alertBox.classList.add('d-none');
        alertBox.textContent = '';
    }

    function clearFieldErrors() {
        ['doctor_id', 'patient_id', 'operatory_id', 'appointment_date', 'start_time', 'end_time', 'actual_start_time', 'actual_end_time', 'status'].forEach(function(fieldId) {
            const field = document.getElementById(fieldId);
            const err = document.getElementById(fieldId + '_error');
            if (field) {
                field.classList.remove('is-invalid');
                field.setCustomValidity('');
            }
            if (err) {
                err.textContent = '';
            }
        });
    }

    function showFieldError(fieldId, message) {
        const field = document.getElementById(fieldId);
        const err = document.getElementById(fieldId + '_error');
        if (field) {
            field.classList.add('is-invalid');
            field.setCustomValidity(message || '');
        }
        if (err) {
            err.textContent = message || '';
        }
    }

    function isHexColor(value) {
        return /^#([0-9a-f]{3}|[0-9a-f]{6})$/i.test(String(value || '').trim());
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
            width: '100%',
            placeholder: <?php echo json_encode(trans('appointment', 'select')); ?>,
            allowClear: true,
            templateResult: doctorOptionTemplate,
            templateSelection: doctorSelectionTemplate
        });
    }

    function initPatientSelect2() {
        if (typeof $ === 'undefined' || !$.fn || typeof $.fn.select2 !== 'function') return;
        const $patient = $('#patient_id');
        if ($patient.hasClass('select2-hidden-accessible')) {
            $patient.select2('destroy');
        }
        $patient.select2({
            width: '100%',
            placeholder: <?php echo json_encode(trans('appointment', 'select')); ?>,
            allowClear: true,
            ajax: {
                url: 'edit.php?ajax=patient_search',
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

    if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
        $(document).on('select2:open', function() {
            const searchInput = document.querySelector('.select2-container--open .select2-search__field');
            if (searchInput) {
                searchInput.focus();
            }
        });
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
        const startVal = (document.getElementById('start_time').value || '').trim();
        const endVal = (document.getElementById('end_time').value || '').trim();
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
                return '<span class="badge rounded-pill bg-secondary me-1 mb-1">' + escapeHtml(s.start) + '–' + escapeHtml(s.end) + '</span>';
            }).join(' ');
        } else {
            busyEl.innerHTML = '<span class="text-muted">' + escapeHtml(t.availabilityNoneBooked) + '</span>';
        }
    }

    function loadSlots() {
        const doctorId = document.getElementById('doctor_id').value;
        const operatoryId = document.getElementById('operatory_id').value;
        const appointmentDate = document.getElementById('appointment_date').value;
        if (!doctorId || !appointmentDate || !operatoryId) {
            availableSlots = [];
            busySlots = [];
            renderAvailabilityPanel(null, null);
            return;
        }
        fetch('available_slots_ajax.php?doctor_id=' + encodeURIComponent(doctorId) + '&operatory_id=' + encodeURIComponent(operatoryId) + '&date=' + encodeURIComponent(appointmentDate) + '&exclude_appointment_id=' + encodeURIComponent(<?php echo json_encode((string)$appointment_id); ?>))
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    showAlert(data.message || t.loadSlotsFailed, 'danger');
                    renderAvailabilityPanel(null, null);
                    return;
                }
                availableSlots = Array.isArray(data.slots) ? data.slots : [];
                busySlots = Array.isArray(data.busy) ? data.busy : [];
                renderAvailabilityPanel(availableSlots, busySlots);
            })
            .catch(function() {
                showAlert(t.loadSlotsFailed, 'danger');
                renderAvailabilityPanel(null, null);
            });
    }

    document.getElementById('doctor_id').addEventListener('change', loadSlots);
    document.getElementById('operatory_id').addEventListener('change', loadSlots);
    document.getElementById('appointment_date').addEventListener('change', loadSlots);
    document.getElementById('start_time').addEventListener('change', function() {
        renderAvailabilityPanel(availableSlots, busySlots);
    });
    document.getElementById('end_time').addEventListener('change', function() {
        renderAvailabilityPanel(availableSlots, busySlots);
    });
    const showNewPatientBoxBtn = document.getElementById('showNewPatientBox');
    if (showNewPatientBoxBtn) {
        showNewPatientBoxBtn.addEventListener('click', function() {
            document.getElementById('newPatientBox').classList.toggle('d-none');
        });
    }
    const saveNewPatientBtn = document.getElementById('saveNewPatientBtn');
    if (saveNewPatientBtn) {
        saveNewPatientBtn.addEventListener('click', function() {
        const fd = new FormData();
        fd.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
        fd.append('first_name', document.getElementById('new_first_name').value);
        fd.append('fathers_last_name', document.getElementById('new_fathers_last_name').value);
        fd.append('mothers_last_name', document.getElementById('new_mothers_last_name').value);
        fd.append('phone', document.getElementById('new_phone').value);
        fd.append('emergency_contact_name', document.getElementById('new_emergency_contact_name').value);
        fd.append('gender', document.getElementById('new_gender').value);
        fetch('../patients/quick_add_ajax.php', { method: 'POST', body: fd })
            .then(function(r) { return r.json(); })
            .then(function(data) {
                if (!data.success) {
                    showAlert(data.message || t.addPatientFailed, 'danger');
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
            .catch(function() {
                showAlert(t.addPatientFailed, 'danger');
            });
        });
    }
    document.getElementById('availabilityAvailableList').addEventListener('click', function(e) {
        const btn = e.target && e.target.closest ? e.target.closest('button[data-slot-start][data-slot-end]') : null;
        if (!btn) return;
        document.getElementById('start_time').value = btn.getAttribute('data-slot-start') || '';
        document.getElementById('end_time').value = btn.getAttribute('data-slot-end') || '';
        renderAvailabilityPanel(availableSlots, busySlots);
    });

    form.addEventListener('submit', function(event) {
        event.preventDefault();
        clearAlert();
        clearFieldErrors();

        const start = (document.getElementById('start_time').value || '').trim();
        const end = (document.getElementById('end_time').value || '').trim();
        const operatoryVal = (document.getElementById('operatory_id').value || '').trim();
        if (!operatoryVal) {
            showFieldError('operatory_id', t.operatoryRequired);
            showAlert(t.operatoryRequired, 'warning');
            return;
        }
        if (start && end && start >= end) {
            showFieldError('start_time', t.startBeforeEnd);
            showFieldError('end_time', t.startBeforeEnd);
            showAlert(t.startBeforeEnd, 'warning');
            return;
        }

        if (typeof form.reportValidity === 'function' && !form.reportValidity()) {
            return;
        }

        const defaultLabel = submitBtn.innerHTML;
        function restoreSubmitBtn() {
            submitBtn.disabled = false;
            submitBtn.innerHTML = defaultLabel;
        }
        function applyAddResponse(data) {
            if (!data.success) {
                if (Array.isArray(data.fields)) {
                    data.fields.forEach(function(fieldId) {
                        showFieldError(fieldId, data.message || <?php echo json_encode(trans('appointment', 'save_failed')); ?>);
                    });
                } else if (data.field) {
                    showFieldError(data.field, data.message || <?php echo json_encode(trans('appointment', 'save_failed')); ?>);
                }
                showAlert(data.message || <?php echo json_encode(trans('appointment', 'save_failed')); ?>, 'danger');
                return;
            }
            showAlert(data.message || <?php echo json_encode(trans('appointment', 'update_success')); ?>, 'success');
            setTimeout(function() {
                window.location.href = 'view.php?id=<?php echo (int)$appointment_id; ?>';
            }, 700);
        }
        function postSave(confirmed) {
            const formData = new FormData(form);
            if (confirmed) {
                formData.append('confirm_outside_hours', '1');
            }
            submitBtn.disabled = true;
            submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + t.processing + '...';
            fetch('save_ajax.php', {
                method: 'POST',
                body: formData
            })
                .then(function(response) { return response.json(); })
                .then(function(data) {
                    restoreSubmitBtn();
                    if (!data.success && data.needs_outside_hours_confirm && !confirmed) {
                        if (window.confirm(data.message || t.outsideHoursConfirm)) {
                            postSave(true);
                        }
                        return;
                    }
                    applyAddResponse(data);
                })
                .catch(function() {
                    restoreSubmitBtn();
                    showAlert(<?php echo json_encode(trans('appointment', 'save_failed')); ?>, 'danger');
                });
        }
        postSave(false);
    });

    initDoctorSelect2();
    initPatientSelect2();
    const prefillPatientId = <?php echo json_encode($prefill_patient_id > 0 ? (string)$prefill_patient_id : ''); ?>;
    if (prefillPatientId && typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
        $('#patient_id').val(prefillPatientId).trigger('change.select2');
    }
    loadSlots();
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
