<?php
/**
 * Lab Work Orders - View
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$user = dcmt_get_current_user();
$role = $user['dcmt_role'] ?? '';
if (!in_array($role, ['admin', 'doctor'], true) && !dcmt_is_admin()) {
    dcmt_show_message('Access denied.', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$order_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($order_id <= 0) {
    dcmt_show_message(trans('lab', 'invalid_work_order_id'), 'error');
    dcmt_redirect('index.php');
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("
        SELECT w.*, c.dcmt_name AS lab_connection_name, c.dcmt_lab_remote_name, c.dcmt_lab_remote_code, c.dcmt_lab_organization
        FROM dcmt_lab_work_orders w
        INNER JOIN dcmt_lab_connections c ON c.dcmt_id = w.dcmt_lab_connection_id
        WHERE w.dcmt_id = ?
    ");
    $stmt->execute([$order_id]);
    $order = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $order = false;
}

if (!$order) {
    dcmt_show_message(trans('lab', 'work_order_not_found'), 'error');
    dcmt_redirect('index.php');
    exit();
}

$lab_status = null;
$lab_processes = [];
$lab_status_error = '';
$status_api = null;

$connection = dcmt_lab_get_connection($dcmt_pdo, (int) ($order['dcmt_lab_connection_id'] ?? 0));
$remote_work_order_id = trim((string) ($order['dcmt_remote_work_order_id'] ?? ''));

if ($connection && $remote_work_order_id !== '') {
    $status_api = dcmt_lab_fetch_work_order_status(
        (string) ($connection['dcmt_lab_base_url'] ?? ''),
        (string) ($connection['dcmt_api_key'] ?? ''),
        $remote_work_order_id
    );

    if ($status_api['success'] && is_array($status_api['data'])) {
        $lab_status = $status_api['data']['status'] ?? null;
        $lab_processes = dcmt_lab_normalize_processes(
            is_array($status_api['data']['processes'] ?? null) ? $status_api['data']['processes'] : []
        );

        $api_doctor_id = trim((string) ($status_api['data']['doctorId'] ?? ''));
        if ($api_doctor_id !== '') {
            $order['dcmt_remote_doctor_id'] = $api_doctor_id;
        }

        try {
            $update = $dcmt_pdo->prepare("
                UPDATE dcmt_lab_work_orders
                SET dcmt_remote_status = ?,
                    dcmt_remote_doctor_id = COALESCE(NULLIF(?, ''), dcmt_remote_doctor_id)
                WHERE dcmt_id = ?
            ");
            $update->execute([
                (string) ($status_api['data']['status'] ?? ''),
                $api_doctor_id,
                $order_id,
            ]);
        } catch (PDOException $e) {
            error_log('Lab work order status cache update error: ' . $e->getMessage());
        }
    } else {
        $lab_status_error = dcmt_lab_extract_error_message($status_api, 'Failed to fetch lab status');
    }
}

if ($lab_status === null) {
    $lab_status = $order['dcmt_remote_status'] ?? null;
}

$csrf_token = dcmt_generate_csrf_token();
$verification_started = !empty($order['dcmt_verification_started_at']);
$verification_requested = dcmt_lab_has_active_verification_request($dcmt_pdo, $order_id);

$status_data_for_doctor = is_array($status_api) && !empty($status_api['success']) && is_array($status_api['data'] ?? null)
    ? $status_api['data']
    : null;
$remote_doctor_id = dcmt_lab_resolve_remote_doctor_id($dcmt_pdo, $order, $status_data_for_doctor);
if ($remote_doctor_id !== '' && trim((string) ($order['dcmt_remote_doctor_id'] ?? '')) === '') {
    $order['dcmt_remote_doctor_id'] = $remote_doctor_id;
    try {
        $sync = $dcmt_pdo->prepare("UPDATE dcmt_lab_work_orders SET dcmt_remote_doctor_id = ? WHERE dcmt_id = ?");
        $sync->execute([$remote_doctor_id, $order_id]);
    } catch (PDOException $e) {
        error_log('Lab work order doctor id resolve save error: ' . $e->getMessage());
    }
}

$can_verify = $connection
    && ($connection['dcmt_status'] ?? '') === 'active'
    && $remote_work_order_id !== ''
    && $remote_doctor_id !== '';

// If lab already requested verification but process flag is missing, still allow actions
$has_pending_verification_process = false;
foreach ($lab_processes as $process) {
    if (dcmt_lab_is_verification_process($process)) {
        $status = strtoupper(trim((string) ($process['status'] ?? '')));
        if (!in_array($status, ['COMPLETED', 'DONE', 'FINISHED', 'CANCELLED', 'SKIPPED', 'SUCCESS', 'VERIFIED'], true)) {
            $has_pending_verification_process = true;
            break;
        }
    }
}
$show_verification_actions = $can_verify && ($has_pending_verification_process || $verification_requested || $verification_started);

$clinic_url_for_verify = trim((string) ($connection['dcmt_clinic_url'] ?? ''));
if ($clinic_url_for_verify === '') {
    $clinic_url_for_verify = dcmt_lab_default_clinic_url();
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">
<style>
.dcmt-lab-process-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
}
.dcmt-lab-process-count {
    color: #6c757d;
    font-size: 0.875rem;
    font-weight: 500;
}
.dcmt-lab-process-scroll {
    max-height: 28rem;
    overflow-y: auto;
}
</style>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('lab', 'view_work_order'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'view_all_work_orders'); ?></a>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'folio_number'); ?>:</span>
                <div class="dcmt-view-field-value"><strong><?php echo htmlspecialchars($order['dcmt_folio_number'] ?: '—'); ?></strong></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'select_lab'); ?>:</span>
                <div class="dcmt-view-field-value">
                    <?php echo htmlspecialchars(dcmt_lab_connection_display_name($order)); ?>
                    <?php if (!empty($order['dcmt_lab_remote_code'])): ?>
                        <span class="text-muted">(<?php echo htmlspecialchars($order['dcmt_lab_remote_code']); ?>)</span>
                    <?php endif; ?>
                </div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'doctor_name'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo htmlspecialchars($order['dcmt_doctor_name']); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'doctor_email'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo htmlspecialchars($order['dcmt_doctor_email'] ?: '—'); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'patient_name'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo htmlspecialchars($order['dcmt_patient_name']); ?></div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'prosthesis_type'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo htmlspecialchars($order['dcmt_prosthesis_type_name'] ?: $order['dcmt_prosthesis_type_id']); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'box_number'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo htmlspecialchars($order['dcmt_box_number'] ?: '—'); ?></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'color'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo htmlspecialchars($order['dcmt_color'] ?: '—'); ?></div>
            </div>
        </div>
    </div>

    <div class="row mb-3">
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'remote_id'); ?>:</span>
                <div class="dcmt-view-field-value"><code><?php echo htmlspecialchars($order['dcmt_remote_work_order_id'] ?: '—'); ?></code></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'remote_doctor_id'); ?>:</span>
                <div class="dcmt-view-field-value"><code><?php echo htmlspecialchars($order['dcmt_remote_doctor_id'] ?: '—'); ?></code></div>
            </div>
        </div>
        <div class="col-md-4">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'created_at'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo dcmt_format_date($order['dcmt_created_at'], DCMT_DATETIME_FORMAT); ?></div>
            </div>
        </div>
    </div>

    <?php if (!empty($order['dcmt_specification'])): ?>
        <div class="mb-3">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'specification'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($order['dcmt_specification'])); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if (!empty($order['dcmt_notes'])): ?>
        <div class="mb-3">
            <div class="dcmt-view-field">
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'work_order_notes'); ?>:</span>
                <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($order['dcmt_notes'])); ?></div>
            </div>
        </div>
    <?php endif; ?>

    <?php if ($lab_status_error !== ''): ?>
        <div class="alert alert-warning mb-3" id="labStatusError">
            <?php echo htmlspecialchars($lab_status_error); ?>
        </div>
    <?php else: ?>
        <div class="alert alert-warning mb-3 d-none" id="labStatusError"></div>
    <?php endif; ?>

    <div class="alert alert-success mb-3 d-none" id="labVerificationMessage"></div>

    <div class="card mb-4">
        <div class="card-header">
            <div class="dcmt-lab-process-header">
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <strong><?php echo trans('lab', 'processes'); ?></strong>
                    <span class="dcmt-lab-process-count" id="labProcessCount">
                        <?php
                        echo htmlspecialchars(str_replace(
                            '{count}',
                            (string) count($lab_processes),
                            trans('lab', 'process_count')
                        ));
                        ?>
                    </span>
                </div>
                <button type="button" class="btn btn-sm btn-outline-primary" id="refreshLabStatusBtn">
                    <i class="fas fa-rotate-right me-1"></i><?php echo trans('lab', 'refresh_status'); ?>
                </button>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive<?php echo count($lab_processes) > 10 ? ' dcmt-lab-process-scroll' : ''; ?>" id="labProcessList">
                <?php if (empty($lab_processes)): ?>
                    <div class="p-3 text-muted"><?php echo htmlspecialchars(trans('lab', 'no_process_updates')); ?></div>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<!-- Verification outcome modal -->
<div class="modal fade" id="labVerificationModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog">
        <div class="modal-content">
            <form id="labVerificationForm">
                <div class="modal-header">
                    <h5 class="modal-title"><?php echo trans('lab', 'verification_outcome'); ?></h5>
                    <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
                </div>
                <div class="modal-body">
                    <div class="alert alert-danger d-none" id="labVerificationModalError"></div>

                    <div class="mb-3">
                        <label class="form-label d-block"><?php echo trans('lab', 'verification_outcome'); ?> <span class="text-danger">*</span></label>
                        <div class="btn-group w-100" role="group">
                            <input type="radio" class="btn-check" name="outcome" id="outcomeSuccess" value="SUCCESS" autocomplete="off">
                            <label class="btn btn-outline-success" for="outcomeSuccess"><?php echo trans('lab', 'outcome_success'); ?></label>

                            <input type="radio" class="btn-check" name="outcome" id="outcomeRepetition" value="REPETITION" autocomplete="off">
                            <label class="btn btn-outline-warning" for="outcomeRepetition"><?php echo trans('lab', 'outcome_repetition'); ?></label>

                            <input type="radio" class="btn-check" name="outcome" id="outcomeRework" value="REWORK" autocomplete="off">
                            <label class="btn btn-outline-danger" for="outcomeRework"><?php echo trans('lab', 'outcome_rework'); ?></label>
                        </div>
                    </div>

                    <div class="mb-3 d-none" id="reworkProcessesGroup">
                        <label class="form-label"><?php echo trans('lab', 'rework_processes'); ?> <span class="text-danger">*</span></label>
                        <div id="reworkProcessesList" class="border rounded p-2" style="max-height: 12rem; overflow-y: auto;"></div>
                    </div>

                    <div class="mb-0">
                        <label class="form-label" for="verificationNotes"><?php echo trans('lab', 'verification_notes'); ?></label>
                        <textarea class="form-control" id="verificationNotes" name="notes" rows="3"></textarea>
                    </div>
                </div>
                <div class="modal-footer">
                    <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('lab', 'cancel'); ?></button>
                    <button type="submit" class="btn btn-primary" id="labVerificationSubmitBtn">
                        <i class="fas fa-check me-1"></i><?php echo trans('lab', 'submit_verification'); ?>
                    </button>
                </div>
            </form>
        </div>
    </div>
</div>

<script>
(function () {
    const refreshBtn = document.getElementById('refreshLabStatusBtn');
    const statusError = document.getElementById('labStatusError');
    const verificationMessage = document.getElementById('labVerificationMessage');
    const processList = document.getElementById('labProcessList');
    const processCount = document.getElementById('labProcessCount');
    const orderId = <?php echo (int) $order_id; ?>;
    const csrfToken = <?php echo json_encode($csrf_token); ?>;
    const canVerify = <?php echo $can_verify ? 'true' : 'false'; ?>;
    let verificationStarted = <?php echo $verification_started ? 'true' : 'false'; ?>;
    let verificationRequested = <?php echo $verification_requested ? 'true' : 'false'; ?>;
    let lastProcesses = <?php echo json_encode(array_values($lab_processes)); ?>;
    let showVerificationActions = <?php echo !empty($show_verification_actions) ? 'true' : 'false'; ?>;
    const verificationContext = {
        clinicUrl: <?php echo json_encode($clinic_url_for_verify); ?>,
        doctorId: <?php echo json_encode($remote_doctor_id); ?>,
        workOrderId: <?php echo json_encode($remote_work_order_id); ?>
    };

    const processCountTemplate = <?php echo json_encode(trans('lab', 'process_count')); ?>;
    const emptyProcessesLabel = <?php echo json_encode(trans('lab', 'no_process_updates')); ?>;
    const refreshingLabel = <?php echo json_encode(trans('lab', 'refreshing_status')); ?>;
    const processNameLabel = <?php echo json_encode(trans('lab', 'process_name')); ?>;
    const processStatusLabel = <?php echo json_encode(trans('lab', 'process_status')); ?>;
    const technicianLabel = <?php echo json_encode(trans('lab', 'technician')); ?>;
    const actionLabel = <?php echo json_encode(trans('lab', 'action')); ?>;
    const startLabel = <?php echo json_encode(trans('lab', 'start_verification')); ?>;
    const endLabel = <?php echo json_encode(trans('lab', 'end_verification')); ?>;
    const outcomeRequiredMessage = <?php echo json_encode(trans('lab', 'verification_outcome_required')); ?>;
    const reworkRequiredMessage = <?php echo json_encode(trans('lab', 'rework_processes_required')); ?>;
    const genericErrorMessage = <?php echo json_encode(trans('lab', 'verification_request_failed')); ?>;

    const modalEl = document.getElementById('labVerificationModal');
    const modalForm = document.getElementById('labVerificationForm');
    const modalError = document.getElementById('labVerificationModalError');
    const modalSubmitBtn = document.getElementById('labVerificationSubmitBtn');
    const reworkGroup = document.getElementById('reworkProcessesGroup');
    const reworkList = document.getElementById('reworkProcessesList');
    const notesInput = document.getElementById('verificationNotes');
    let verificationModal = null;

    const doneStatuses = ['COMPLETED', 'DONE', 'FINISHED', 'CANCELLED', 'SKIPPED', 'SUCCESS', 'VERIFIED'];

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function isVerificationProcess(process) {
        if (!process) return false;
        if (process.isVerification === true || process.isVerification === 1 || process.isVerification === '1' || process.isVerification === 'true') {
            return true;
        }
        if (process.isExternalVerification === true || process.isExternalVerification === 1 || process.isExternalVerification === '1') {
            return true;
        }
        const type = String(process.processType || process.type || '').toUpperCase();
        if (type && (type.indexOf('VERIFICATION') !== -1 || type.indexOf('EXTERNAL') !== -1)) {
            return true;
        }
        const name = String(process.processName || process.name || '').toLowerCase();
        return name.indexOf('verification') !== -1;
    }

    function isPendingStatus(status) {
        return doneStatuses.indexOf(String(status || '').toUpperCase()) === -1;
    }

    function hasPendingVerification(processes) {
        return Array.isArray(processes) && processes.some(function (process) {
            return isVerificationProcess(process) && isPendingStatus(process.status);
        });
    }

    function shouldShowVerificationActions(processes) {
        if (!canVerify) {
            return false;
        }
        return hasPendingVerification(processes) || verificationRequested || verificationStarted;
    }

    function updateProcessCount(count) {
        if (!processCount) {
            return;
        }
        processCount.textContent = String(processCountTemplate).replace('{count}', String(count));
    }

    function applyProcessScroll(count) {
        if (!processList) {
            return;
        }
        if (count > 10) {
            processList.classList.add('dcmt-lab-process-scroll');
        } else {
            processList.classList.remove('dcmt-lab-process-scroll');
        }
    }

    function findVerificationProcess(processes) {
        if (!Array.isArray(processes)) return null;
        for (let i = 0; i < processes.length; i++) {
            if (isVerificationProcess(processes[i])) {
                return processes[i];
            }
        }
        return null;
    }

    function renderProcesses(processes) {
        if (!processList) {
            return;
        }
        lastProcesses = Array.isArray(processes) ? processes : [];

        if (lastProcesses.length === 0) {
            processList.innerHTML = '<div class="p-3 text-muted">' + escapeHtml(emptyProcessesLabel) + '</div>';
            updateProcessCount(0);
            applyProcessScroll(0);
            return;
        }

        showVerificationActions = shouldShowVerificationActions(lastProcesses);
        const verificationProcess = findVerificationProcess(lastProcesses);
        const verificationPending = verificationProcess
            ? isPendingStatus(verificationProcess.status)
            : (verificationRequested || verificationStarted);

        let html = ''
            + '<table class="table mb-0 align-middle">'
            + '<thead><tr>'
            + '<th>' + escapeHtml(processNameLabel) + '</th>'
            + '<th>' + escapeHtml(processStatusLabel) + '</th>'
            + '<th>' + escapeHtml(technicianLabel) + '</th>'
            + (showVerificationActions ? '<th>' + escapeHtml(actionLabel) + '</th>' : '')
            + '</tr></thead><tbody>';

        lastProcesses.forEach(function (process) {
            html += '<tr>'
                + '<td>' + escapeHtml(process.processName || process.name || '—') + '</td>'
                + '<td>' + escapeHtml(process.status || '—') + '</td>'
                + '<td>' + escapeHtml(process.technicianName || '—') + '</td>';

            if (showVerificationActions) {
                let actionHtml = '—';
                const isThisVerification = isVerificationProcess(process);
                const showOnThisRow = isThisVerification
                    || (!verificationProcess && verificationPending && String(process.processName || '').toLowerCase().indexOf('verification') !== -1);

                if (showOnThisRow && (isPendingStatus(process.status) || verificationRequested || verificationStarted)) {
                    if (verificationStarted) {
                        actionHtml = '<button type="button" class="btn btn-sm btn-danger dcmt-verif-end-btn">'
                            + '<i class="fas fa-stop me-1"></i>' + escapeHtml(endLabel) + '</button>';
                    } else {
                        actionHtml = '<button type="button" class="btn btn-sm btn-success dcmt-verif-start-btn">'
                            + '<i class="fas fa-play me-1"></i>' + escapeHtml(startLabel) + '</button>';
                    }
                }
                html += '<td>' + actionHtml + '</td>';
            }

            html += '</tr>';
        });

        html += '</tbody></table>';
        processList.innerHTML = html;
        updateProcessCount(lastProcesses.length);
        applyProcessScroll(lastProcesses.length);
    }

    function setError(message) {
        if (!statusError) {
            return;
        }
        if (message) {
            statusError.textContent = message;
            statusError.classList.remove('d-none');
        } else {
            statusError.textContent = '';
            statusError.classList.add('d-none');
        }
    }

    function setSuccessMessage(message) {
        if (!verificationMessage) {
            return;
        }
        if (message) {
            verificationMessage.textContent = message;
            verificationMessage.classList.remove('d-none');
        } else {
            verificationMessage.textContent = '';
            verificationMessage.classList.add('d-none');
        }
    }

    function setModalError(message) {
        if (!modalError) {
            return;
        }
        if (message) {
            modalError.textContent = message;
            modalError.classList.remove('d-none');
        } else {
            modalError.textContent = '';
            modalError.classList.add('d-none');
        }
    }

    function refreshStatus() {
        if (!refreshBtn) {
            return;
        }
        const originalHtml = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + escapeHtml(refreshingLabel);
        setError('');

        fetch('status_ajax.php?id=' + encodeURIComponent(orderId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : 'Unable to refresh lab status.');
                }
                if (typeof data.verification_started !== 'undefined') {
                    verificationStarted = !!data.verification_started;
                }
                if (typeof data.verification_requested !== 'undefined') {
                    verificationRequested = !!data.verification_requested;
                }
                if (data.clinic_url) {
                    verificationContext.clinicUrl = data.clinic_url;
                }
                if (data.remote_doctor_id) {
                    verificationContext.doctorId = data.remote_doctor_id;
                }
                if (data.remote_work_order_id) {
                    verificationContext.workOrderId = data.remote_work_order_id;
                }
                renderProcesses(data.processes || []);
            })
            .catch(function (error) {
                setError(error && error.message ? error.message : 'Unable to refresh lab status.');
            })
            .finally(function () {
                refreshBtn.disabled = false;
                refreshBtn.innerHTML = originalHtml;
            });
    }

    function postVerification(fields) {
        const body = new FormData();
        body.append('id', String(orderId));
        body.append('csrf_token', csrfToken);
        body.append('clinicUrl', verificationContext.clinicUrl || '');
        body.append('doctorId', verificationContext.doctorId || '');
        body.append('workOrderId', verificationContext.workOrderId || '');
        Object.keys(fields).forEach(function (key) {
            const value = fields[key];
            if (Array.isArray(value)) {
                value.forEach(function (item) {
                    body.append(key + '[]', item);
                });
            } else {
                body.append(key, value);
            }
        });
        return fetch('verification_ajax.php', {
            method: 'POST',
            headers: { 'X-Requested-With': 'XMLHttpRequest' },
            body: body
        }).then(function (response) { return response.json(); });
    }

    function handleStartClick(button) {
        const originalHtml = button.innerHTML;
        button.disabled = true;
        button.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + escapeHtml(startLabel);
        setError('');
        setSuccessMessage('');

        postVerification({ action: 'start' })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : genericErrorMessage);
                }
                verificationStarted = true;
                verificationRequested = false;
                renderProcesses(lastProcesses);
                setSuccessMessage(data.message || '');
            })
            .catch(function (error) {
                setError(error && error.message ? error.message : genericErrorMessage);
                button.disabled = false;
                button.innerHTML = originalHtml;
            });
    }

    function getReworkableProcesses(processes) {
        const list = Array.isArray(processes) ? processes.slice() : [];
        let verificationSequence = null;
        let verificationIndex = -1;

        list.forEach(function (process, index) {
            if (!isVerificationProcess(process)) {
                return;
            }
            if (verificationIndex === -1) {
                verificationIndex = index;
                const seq = parseInt(process.sequence, 10);
                verificationSequence = isNaN(seq) ? index : seq;
            }
        });

        if (verificationIndex < 0) {
            return [];
        }

        const seen = {};
        const reworkable = [];
        list.forEach(function (process, index) {
            if (isVerificationProcess(process)) {
                return;
            }
            const seq = parseInt(process.sequence, 10);
            const effectiveSeq = isNaN(seq) ? index : seq;
            if (effectiveSeq >= verificationSequence) {
                return;
            }
            const name = String(process.processName || process.name || '').trim();
            if (name === '' || seen[name]) {
                return;
            }
            seen[name] = true;
            reworkable.push(process);
        });
        return reworkable;
    }

    function openVerificationModal() {
        if (!modalEl || typeof bootstrap === 'undefined') {
            return;
        }
        if (modalForm) {
            modalForm.reset();
        }
        setModalError('');
        if (reworkGroup) {
            reworkGroup.classList.add('d-none');
        }
        if (reworkList) {
            let listHtml = '';
            getReworkableProcesses(lastProcesses).forEach(function (process, index) {
                const name = String(process.processName || process.name || '').trim();
                const inputId = 'reworkProcess' + index;
                listHtml += '<div class="form-check">'
                    + '<input class="form-check-input" type="checkbox" name="rework_process_names" value="' + escapeHtml(name) + '" id="' + inputId + '">'
                    + '<label class="form-check-label" for="' + inputId + '">' + escapeHtml(name) + '</label>'
                    + '</div>';
            });
            reworkList.innerHTML = listHtml;
        }
        verificationModal = bootstrap.Modal.getOrCreateInstance(modalEl);
        verificationModal.show();
    }

    if (processList) {
        processList.addEventListener('click', function (event) {
            const startBtn = event.target.closest('.dcmt-verif-start-btn');
            if (startBtn) {
                handleStartClick(startBtn);
                return;
            }
            const endBtn = event.target.closest('.dcmt-verif-end-btn');
            if (endBtn) {
                openVerificationModal();
            }
        });
    }

    if (modalEl) {
        modalEl.addEventListener('change', function (event) {
            if (event.target && event.target.name === 'outcome' && reworkGroup) {
                reworkGroup.classList.toggle('d-none', event.target.value !== 'REWORK');
            }
        });
    }

    if (modalForm) {
        modalForm.addEventListener('submit', function (event) {
            event.preventDefault();
            setModalError('');

            const outcomeInput = modalForm.querySelector('input[name="outcome"]:checked');
            if (!outcomeInput) {
                setModalError(outcomeRequiredMessage);
                return;
            }
            const outcome = outcomeInput.value;

            const reworkNames = [];
            modalForm.querySelectorAll('input[name="rework_process_names"]:checked').forEach(function (input) {
                reworkNames.push(input.value);
            });
            if (outcome === 'REWORK' && reworkNames.length === 0) {
                setModalError(reworkRequiredMessage);
                return;
            }

            const originalHtml = modalSubmitBtn ? modalSubmitBtn.innerHTML : '';
            if (modalSubmitBtn) {
                modalSubmitBtn.disabled = true;
                modalSubmitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>' + escapeHtml(refreshingLabel);
            }

            postVerification({
                action: 'end',
                outcome: outcome,
                notes: notesInput ? notesInput.value : '',
                rework_process_names: reworkNames
            })
                .then(function (data) {
                    if (!data || !data.success) {
                        throw new Error((data && data.message) ? data.message : genericErrorMessage);
                    }
                    verificationStarted = false;
                    verificationRequested = false;
                    if (verificationModal) {
                        verificationModal.hide();
                    }
                    setSuccessMessage(data.message || '');
                    refreshStatus();
                })
                .catch(function (error) {
                    setModalError(error && error.message ? error.message : genericErrorMessage);
                })
                .finally(function () {
                    if (modalSubmitBtn) {
                        modalSubmitBtn.disabled = false;
                        modalSubmitBtn.innerHTML = originalHtml;
                    }
                });
        });
    }

    if (refreshBtn) {
        refreshBtn.addEventListener('click', refreshStatus);
    }

    renderProcesses(lastProcesses);
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
