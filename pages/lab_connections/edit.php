<?php
/**
 * Lab Connections - Edit
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
if (!dcmt_can_access_lab($user)) {
    dcmt_show_message('Access denied.', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
    exit();
}
dcmt_ensure_lab_tables($dcmt_pdo);

$connection_id = isset($_GET['id']) ? (int) $_GET['id'] : 0;
if ($connection_id <= 0) {
    dcmt_show_message(trans('lab', 'invalid_id'), 'error');
    dcmt_redirect('index.php');
    exit();
}

$connection = dcmt_lab_get_connection($dcmt_pdo, $connection_id);
if (!$connection) {
    dcmt_show_message(trans('lab', 'not_found'), 'error');
    dcmt_redirect('index.php');
    exit();
}

$clinic_url = dcmt_lab_default_clinic_url();
$clinic_name = dcmt_lab_default_clinic_name();

$errors = [];
$form_data = [
    'lab_base_url' => $connection['dcmt_lab_base_url'],
    'api_key' => $connection['dcmt_api_key'],
    'status' => $connection['dcmt_status'],
    'notes' => $connection['dcmt_notes'] ?? '',
];

$force_resync = isset($_POST['force_resync']) && $_POST['force_resync'] === '1';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        $form_data = [
            'lab_base_url' => dcmt_lab_normalize_base_url(dcmt_sanitize_input($_POST['lab_base_url'] ?? '')),
            'api_key' => trim((string) ($_POST['api_key'] ?? '')),
            'status' => dcmt_sanitize_input($_POST['status'] ?? 'active'),
            'notes' => dcmt_sanitize_input($_POST['notes'] ?? ''),
        ];

        if ($form_data['lab_base_url'] === '') {
            $errors[] = trans('lab', 'lab_base_url_required');
        }
        if ($form_data['api_key'] === '') {
            $errors[] = trans('lab', 'api_key_required');
        }
        if ($clinic_url === '') {
            $errors[] = trans('lab', 'clinic_url_required');
        }
        if ($clinic_name === '') {
            $errors[] = trans('lab', 'clinic_name_required');
        }
        if (!in_array($form_data['status'], ['active', 'inactive'], true)) {
            $errors[] = trans('lab', 'invalid_status');
        }

        $needs_sync = $force_resync
            || $form_data['lab_base_url'] !== $connection['dcmt_lab_base_url']
            || $form_data['api_key'] !== $connection['dcmt_api_key']
            || $clinic_url !== ($connection['dcmt_clinic_url'] ?? '')
            || $clinic_name !== ($connection['dcmt_clinic_name'] ?? '');

        $lab_meta = [
            'name' => $connection['dcmt_name'],
            'lab_remote_id' => $connection['dcmt_lab_remote_id'],
            'lab_remote_name' => $connection['dcmt_lab_remote_name'],
            'lab_remote_code' => $connection['dcmt_lab_remote_code'],
            'lab_organization' => $connection['dcmt_lab_organization'],
            'clinic_remote_id' => $connection['dcmt_clinic_remote_id'],
            'synced' => false,
        ];

        if (empty($errors) && $needs_sync) {
            $api = dcmt_lab_configure_integration(
                $form_data['lab_base_url'],
                $form_data['api_key'],
                $clinic_url,
                $clinic_name
            );
            if (!$api['success']) {
                $errors[] = trans('lab', 'sync_failed') . ': ' . dcmt_lab_extract_error_message($api);
            } else {
                $data = is_array($api['data']) ? $api['data'] : [];
                $lab = is_array($data['lab'] ?? null) ? $data['lab'] : [];
                $clinic = is_array($data['clinic'] ?? null) ? $data['clinic'] : [];

                $lab_name = trim((string) ($lab['name'] ?? ''));
                $lab_code = trim((string) ($lab['code'] ?? ''));
                $lab_organization = trim((string) ($lab['organization'] ?? ''));
                $connection_name = $lab_organization !== ''
                    ? $lab_organization
                    : ($lab_name !== '' ? $lab_name : ($lab_code !== '' ? $lab_code : $lab_meta['name']));

                $lab_meta = [
                    'name' => $connection_name,
                    'lab_remote_id' => isset($lab['id']) ? (string) $lab['id'] : $lab_meta['lab_remote_id'],
                    'lab_remote_name' => $lab_name !== '' ? $lab_name : $lab_meta['lab_remote_name'],
                    'lab_remote_code' => $lab_code !== '' ? $lab_code : $lab_meta['lab_remote_code'],
                    'lab_organization' => $lab_organization !== '' ? $lab_organization : $lab_meta['lab_organization'],
                    'clinic_remote_id' => isset($clinic['id']) ? (string) $clinic['id'] : $lab_meta['clinic_remote_id'],
                    'synced' => true,
                ];
            }
        }

        if (empty($errors)) {
            try {
                $stmt = $dcmt_pdo->prepare("
                    UPDATE dcmt_lab_connections SET
                        dcmt_name = ?,
                        dcmt_lab_base_url = ?,
                        dcmt_api_key = ?,
                        dcmt_clinic_url = ?,
                        dcmt_clinic_name = ?,
                        dcmt_lab_remote_id = ?,
                        dcmt_lab_remote_name = ?,
                        dcmt_lab_remote_code = ?,
                        dcmt_lab_organization = ?,
                        dcmt_clinic_remote_id = ?,
                        dcmt_status = ?,
                        dcmt_notes = ?,
                        dcmt_last_synced_at = CASE WHEN ? = 1 THEN NOW() ELSE dcmt_last_synced_at END,
                        dcmt_updated_at = NOW()
                    WHERE dcmt_id = ?
                ");
                $stmt->execute([
                    $lab_meta['name'],
                    $form_data['lab_base_url'],
                    $form_data['api_key'],
                    $clinic_url,
                    $clinic_name,
                    $lab_meta['lab_remote_id'],
                    $lab_meta['lab_remote_name'],
                    $lab_meta['lab_remote_code'],
                    $lab_meta['lab_organization'],
                    $lab_meta['clinic_remote_id'],
                    $form_data['status'],
                    $form_data['notes'],
                    $lab_meta['synced'] ? 1 : 0,
                    $connection_id,
                ]);

                dcmt_log_activity('Lab connection updated: ' . $lab_meta['name'], 'lab_connection_updated');
                dcmt_show_message(
                    $lab_meta['synced'] ? trans('lab', 'sync_success') : trans('lab', 'update_success'),
                    'success'
                );
                dcmt_redirect('index.php');
                exit();
            } catch (PDOException $e) {
                error_log('Lab connection update error: ' . $e->getMessage());
                $errors[] = trans('lab', 'database_error');
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('lab', 'edit_connection'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'view_all_connections'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="labConnectionForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="force_resync" id="force_resync" value="0">

        <?php if (!empty($connection['dcmt_lab_remote_name']) || !empty($connection['dcmt_lab_remote_id'])): ?>
        <div class="alert alert-secondary mb-4">
            <div class="row g-2">
                <div class="col-md-4">
                    <strong><?php echo trans('lab', 'lab_remote_name'); ?>:</strong>
                    <?php echo htmlspecialchars(dcmt_lab_connection_display_name($connection)); ?>
                </div>
                <div class="col-md-4">
                    <strong><?php echo trans('lab', 'connection_status'); ?>:</strong>
                    <span class="text-<?php echo $connection['dcmt_status'] === 'active' ? 'success' : 'secondary'; ?>">
                        <?php echo ucfirst($connection['dcmt_status']); ?>
                    </span>
                </div>
            </div>
        </div>
        <?php endif; ?>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="lab_base_url" class="form-label"><?php echo trans('lab', 'lab_base_url'); ?> <span class="text-danger">*</span></label>
                    <input type="url" class="form-control" id="lab_base_url" name="lab_base_url" required maxlength="255"
                           value="<?php echo htmlspecialchars($form_data['lab_base_url']); ?>">
                    <div class="form-text"><?php echo trans('lab', 'lab_base_url_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="api_key" class="form-label"><?php echo trans('lab', 'api_key'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="api_key" name="api_key" required maxlength="255" autocomplete="off"
                           value="<?php echo htmlspecialchars($form_data['api_key']); ?>">
                    <div class="form-text"><?php echo trans('lab', 'api_key_help'); ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label"><?php echo trans('lab', 'clinic_url'); ?></label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($clinic_url); ?>" readonly disabled>
                    <div class="form-text"><?php echo trans('lab', 'clinic_url_readonly_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label"><?php echo trans('lab', 'clinic_name'); ?></label>
                    <input type="text" class="form-control" value="<?php echo htmlspecialchars($clinic_name); ?>" readonly disabled>
                    <div class="form-text"><?php echo trans('lab', 'clinic_name_readonly_help'); ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="status" class="form-label"><?php echo trans('lab', 'connection_status'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="status" name="status" required>
                        <option value="active" <?php echo $form_data['status'] === 'active' ? 'selected' : ''; ?>><?php echo trans('common', 'active'); ?></option>
                        <option value="inactive" <?php echo $form_data['status'] === 'inactive' ? 'selected' : ''; ?>><?php echo trans('common', 'inactive'); ?></option>
                    </select>
                    <div class="form-text"><?php echo trans('lab', 'status_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="notes" class="form-label"><?php echo trans('lab', 'notes'); ?></label>
                    <textarea class="form-control" id="notes" name="notes" rows="1"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn btn-outline-primary" id="resyncBtn">
                <i class="fas fa-sync"></i><?php echo trans('lab', 'reconnect'); ?>
            </button>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-save"></i><?php echo trans('lab', 'update_connection_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const resyncBtn = document.getElementById('resyncBtn');
    const forceField = document.getElementById('force_resync');
    if (resyncBtn && forceField) {
        resyncBtn.addEventListener('click', function () {
            forceField.value = '1';
        });
        document.getElementById('submitBtn').addEventListener('click', function () {
            forceField.value = '0';
        });
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
