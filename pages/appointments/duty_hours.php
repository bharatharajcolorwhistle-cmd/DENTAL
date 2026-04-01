<?php
/**
 * Doctor Duty Hours Page
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

if (!dcmt_is_admin()) {
    dcmt_show_message(trans('appointment', 'unauthorized'), 'danger');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/index.php');
    exit();
}

$csrf_token = dcmt_generate_csrf_token();
$doctors = [];
try {
    $doctor_stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_full_name FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_full_name ASC");
    $doctors = $doctor_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Duty hours page error: ' . $e->getMessage());
}

require_once __DIR__ . '/../../includes/header.php';
?>

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
                <button type="button" id="saveDutyBtn" class="btn btn-outline-primary">
                    <?php echo trans('appointment', 'save_duty_hours'); ?>
                </button>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-bordered">
                <thead>
                    <tr>
                        <th><?php echo trans('appointment', 'day'); ?></th>
                        <th><?php echo trans('appointment', 'active'); ?></th>
                        <th><?php echo trans('appointment', 'start'); ?></th>
                        <th><?php echo trans('appointment', 'end'); ?></th>
                    </tr>
                </thead>
                <tbody id="dutyTableBody"></tbody>
            </table>
        </div>
    </div>
</div>

<script>
const dutyText = {
    loadDutyFailed: <?php echo json_encode(trans('appointment', 'load_duty_failed')); ?>,
    saveDutyFailed: <?php echo json_encode(trans('appointment', 'save_duty_failed')); ?>,
    processing: <?php echo json_encode(trans('common', 'processing')); ?>,
    sunday: <?php echo json_encode(trans('appointment', 'sunday')); ?>,
    monday: <?php echo json_encode(trans('appointment', 'monday')); ?>,
    tuesday: <?php echo json_encode(trans('appointment', 'tuesday')); ?>,
    wednesday: <?php echo json_encode(trans('appointment', 'wednesday')); ?>,
    thursday: <?php echo json_encode(trans('appointment', 'thursday')); ?>,
    friday: <?php echo json_encode(trans('appointment', 'friday')); ?>,
    saturday: <?php echo json_encode(trans('appointment', 'saturday')); ?>
};
const weekdays = [dutyText.sunday, dutyText.monday, dutyText.tuesday, dutyText.wednesday, dutyText.thursday, dutyText.friday, dutyText.saturday];

function showDutyAlert(message, type = 'danger') {
    const box = document.getElementById('dutyAlert');
    if (!box) return;
    box.className = 'alert alert-' + type;
    box.textContent = message;
    box.classList.remove('d-none');
}

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
    const doctorId = document.getElementById('dutyDoctorId').value;
    fetch(`get_duty_hours_ajax.php?doctor_id=${encodeURIComponent(doctorId)}`)
        .then(r => r.json())
        .then(data => {
            if (!data.success) {
                showDutyAlert(data.message || dutyText.loadDutyFailed);
                return;
            }
            renderDutyTable(data.duty_hours || []);
        })
        .catch(() => showDutyAlert(dutyText.loadDutyFailed));
}

document.addEventListener('DOMContentLoaded', function() {
    const dutyDoctorSelect = document.getElementById('dutyDoctorId');
    const saveDutyBtn = document.getElementById('saveDutyBtn');

    if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
        const $dutyDoctor = $('#dutyDoctorId');
        $dutyDoctor.select2({ width: '100%', allowClear: false });
        $dutyDoctor.on('change', loadDutyHours);
    } else if (dutyDoctorSelect) {
        dutyDoctorSelect.addEventListener('change', loadDutyHours);
    }

    loadDutyHours();

    if (saveDutyBtn) {
        saveDutyBtn.addEventListener('click', function() {
            const originalHtml = saveDutyBtn.innerHTML;
            saveDutyBtn.disabled = true;
            saveDutyBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + dutyText.processing + '...';

            const doctorId = document.getElementById('dutyDoctorId').value;
            const formData = new FormData();
            formData.append('csrf_token', <?php echo json_encode($csrf_token); ?>);
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
                        showDutyAlert(data.message || dutyText.saveDutyFailed);
                        return;
                    }
                    showDutyAlert(data.message, 'success');
                    loadDutyHours();
                })
                .catch(() => showDutyAlert(dutyText.saveDutyFailed))
                .finally(() => {
                    saveDutyBtn.disabled = false;
                    saveDutyBtn.innerHTML = originalHtml;
                });
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
