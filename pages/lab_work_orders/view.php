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
        $lab_processes = is_array($status_api['data']['processes'] ?? null) ? $status_api['data']['processes'] : [];
        usort($lab_processes, static function ($a, $b) {
            $sa = is_array($a) ? (int) ($a['sequence'] ?? 0) : 0;
            $sb = is_array($b) ? (int) ($b['sequence'] ?? 0) : 0;
            return $sa <=> $sb;
        });
    } else {
        $lab_status_error = dcmt_lab_extract_error_message($status_api, 'Failed to fetch lab status');
    }
}

if ($lab_status === null) {
    $lab_status = $order['dcmt_remote_status'] ?? null;
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">
<style>
.dcmt-lab-status-meta {
    color: #6c757d;
    font-size: 0.9rem;
}
.dcmt-lab-process-header {
    display: flex;
    align-items: center;
    justify-content: space-between;
    gap: 0.75rem;
    flex-wrap: wrap;
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
                <span class="dcmt-view-field-label"><?php echo trans('lab', 'qr_token'); ?>:</span>
                <div class="dcmt-view-field-value"><code><?php echo htmlspecialchars($order['dcmt_qr_token'] ?: '—'); ?></code></div>
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

    <div class="card mb-4">
        <div class="card-header">
            <div class="dcmt-lab-process-header">
                <strong><?php echo trans('lab', 'processes'); ?></strong>
                <div class="d-flex align-items-center gap-2 flex-wrap">
                    <button type="button" class="btn btn-sm btn-outline-primary" id="refreshLabStatusBtn">
                        <i class="fas fa-rotate-right me-1"></i>Refresh Status
                    </button>
                </div>
            </div>
        </div>
        <div class="card-body p-0">
            <div class="table-responsive" id="labProcessList">
                <?php if (!empty($lab_processes)): ?>
                    <table class="table mb-0">
                        <thead>
                            <tr>
                                <th><?php echo trans('lab', 'process_name'); ?></th>
                                <th><?php echo trans('lab', 'process_status'); ?></th>
                                <th>Technician</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($lab_processes as $process): ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($process['processName'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($process['status'] ?? '—'); ?></td>
                                    <td><?php echo htmlspecialchars($process['technicianName'] ?? '—'); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                <?php else: ?>
                    <div class="p-3 text-muted">No process updates available.</div>
                <?php endif; ?>
            </div>
        </div>
    </div>

    <div class="dcmt-form-actions">
        <a href="add.php?lab_id=<?php echo (int) $order['dcmt_lab_connection_id']; ?>" class="btn dcmt-btn-submit">
            <i class="fas fa-plus"></i><?php echo trans('lab', 'add_work_order'); ?>
        </a>
        <a href="index.php" class="btn dcmt-btn-cancel">
            <i class="fas fa-arrow-left"></i><?php echo trans('common', 'back'); ?>
        </a>
    </div>
</div>

<script>
(function () {
    const refreshBtn = document.getElementById('refreshLabStatusBtn');
    const statusError = document.getElementById('labStatusError');
    const processList = document.getElementById('labProcessList');
    const orderId = <?php echo (int) $order_id; ?>;

    function escapeHtml(value) {
        return String(value == null ? '' : value)
            .replace(/&/g, '&amp;')
            .replace(/</g, '&lt;')
            .replace(/>/g, '&gt;')
            .replace(/"/g, '&quot;')
            .replace(/'/g, '&#039;');
    }

    function renderProcesses(processes) {
        if (!processList) {
            return;
        }
        if (!Array.isArray(processes) || processes.length === 0) {
            processList.innerHTML = '<div class="p-3 text-muted">No process updates available.</div>';
            return;
        }
        let html = ''
            + '<table class="table mb-0">'
            + '<thead><tr>'
            + '<th><?php echo addslashes(trans('lab', 'process_name')); ?></th>'
            + '<th><?php echo addslashes(trans('lab', 'process_status')); ?></th>'
            + '<th>Technician</th>'
            + '</tr></thead><tbody>';

        processes.forEach(function (process) {
            html += '<tr>'
                + '<td>' + escapeHtml(process.processName || '—') + '</td>'
                + '<td>' + escapeHtml(process.status || '—') + '</td>'
                + '<td>' + escapeHtml(process.technicianName || '—') + '</td>'
                + '</tr>';
        });

        html += '</tbody></table>';
        processList.innerHTML = html;
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

    if (!refreshBtn) {
        return;
    }

    refreshBtn.addEventListener('click', function () {
        const originalHtml = refreshBtn.innerHTML;
        refreshBtn.disabled = true;
        refreshBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i>Refreshing...';
        setError('');

        fetch('status_ajax.php?id=' + encodeURIComponent(orderId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (response) { return response.json(); })
            .then(function (data) {
                if (!data || !data.success) {
                    throw new Error((data && data.message) ? data.message : 'Unable to refresh lab status.');
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
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
