<?php
/**
 * Lab Connections - Add
 *
 * A lab can be stored in two ways:
 *  - Connected:   Lab API base URL + API key are provided and verified.
 *  - Unconnected: only contact details are stored. The lab is saved with
 *                 status "inactive" and work orders can still be created
 *                 manually (folio / prosthesis type / price are typed in).
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

$clinic_url = dcmt_lab_default_clinic_url();
$clinic_name = dcmt_lab_default_clinic_name();

/**
 * Trim a posted text field without HTML-encoding it (output is escaped on render).
 */
if (!function_exists('dcmt_lab_connection_clean_text')) {
    function dcmt_lab_connection_clean_text($value): string
    {
        return trim(strip_tags((string) $value));
    }
}

$errors = [];
$form_data = [
    'name' => '',
    'lab_url' => '',
    'contact_person' => '',
    'contact_number' => '',
    'contact_email' => '',
    'address' => '',
    'notes' => '',
    'lab_base_url' => '',
    'api_key' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        // Always use application settings — never take clinic URL/name from POST
        $form_data = [
            'name' => dcmt_lab_connection_clean_text($_POST['lab_name'] ?? ''),
            'lab_url' => dcmt_lab_normalize_base_url(dcmt_lab_connection_clean_text($_POST['lab_url'] ?? '')),
            'contact_person' => dcmt_lab_connection_clean_text($_POST['contact_person'] ?? ''),
            'contact_number' => dcmt_lab_connection_clean_text($_POST['contact_number'] ?? ''),
            'contact_email' => dcmt_lab_connection_clean_text($_POST['contact_email'] ?? ''),
            'address' => dcmt_lab_connection_clean_text($_POST['address'] ?? ''),
            'notes' => dcmt_lab_connection_clean_text($_POST['notes'] ?? ''),
            'lab_base_url' => dcmt_lab_normalize_base_url(dcmt_lab_connection_clean_text($_POST['lab_base_url'] ?? '')),
            'api_key' => trim((string) ($_POST['api_key'] ?? '')),
        ];

        $has_api_credentials = $form_data['lab_base_url'] !== '' || $form_data['api_key'] !== '';

        if ($has_api_credentials) {
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
        } elseif ($form_data['name'] === '') {
            // Unconnected labs are identified by their name only.
            $errors[] = trans('lab', 'lab_name_required');
        }

        if ($form_data['contact_email'] !== '' && !filter_var($form_data['contact_email'], FILTER_VALIDATE_EMAIL)) {
            $errors[] = trans('lab', 'invalid_contact_email');
        }

        $lab_meta = [
            'name' => $form_data['name'],
            'lab_remote_id' => null,
            'lab_remote_name' => null,
            'lab_remote_code' => null,
            'lab_organization' => null,
            'clinic_remote_id' => null,
            'status' => 'inactive',
        ];

        if (empty($errors) && $has_api_credentials) {
            $api = dcmt_lab_configure_integration(
                $form_data['lab_base_url'],
                $form_data['api_key'],
                $clinic_url,
                $clinic_name
            );

            $data = is_array($api['data'] ?? null) ? $api['data'] : [];
            // Prefer explicit response "success": true/false when present
            $response_ok = false;
            if (array_key_exists('success', $data)) {
                $response_ok = filter_var($data['success'], FILTER_VALIDATE_BOOLEAN);
            } else {
                $response_ok = !empty($api['success']);
            }

            if (!$response_ok) {
                $errors[] = trans('lab', 'sync_failed') . ': ' . dcmt_lab_extract_error_message($api);
            } else {
                $lab = is_array($data['lab'] ?? null) ? $data['lab'] : [];
                $clinic = is_array($data['clinic'] ?? null) ? $data['clinic'] : [];

                $lab_name = trim((string) ($lab['name'] ?? ''));
                $lab_code = trim((string) ($lab['code'] ?? ''));
                $lab_organization = trim((string) ($lab['organization'] ?? ''));
                // Prefer organization as the clinic-facing lab name (no branch).
                $connection_name = $lab_organization !== ''
                    ? $lab_organization
                    : ($lab_name !== '' ? $lab_name : $lab_code);

                $fallback_name = $form_data['name'];
                if ($connection_name === '') {
                    $connection_name = $fallback_name;
                }

                $lab_meta = [
                    'name' => $connection_name,
                    'lab_remote_id' => isset($lab['id']) ? (string) $lab['id'] : null,
                    'lab_remote_name' => $lab_name !== '' ? $lab_name : null,
                    'lab_remote_code' => $lab_code !== '' ? $lab_code : null,
                    'lab_organization' => $lab_organization !== '' ? $lab_organization : null,
                    'clinic_remote_id' => isset($clinic['id']) ? (string) $clinic['id'] : null,
                    // Active when API response success is true
                    'status' => 'active',
                ];

                if (empty($lab_meta['lab_remote_id']) && empty($lab_meta['lab_remote_name'])) {
                    $errors[] = trans('lab', 'sync_failed') . ': invalid lab payload';
                }
            }
        }

        if (empty($errors) && $lab_meta['name'] === '') {
            // Unconnected lab without a name: fall back to the website host.
            $host = '';
            if ($form_data['lab_url'] !== '') {
                $host = (string) parse_url($form_data['lab_url'], PHP_URL_HOST);
            }
            $lab_meta['name'] = $host !== '' ? $host : trans('lab', 'unconnected_lab_default_name');
        }

        if (empty($errors)) {
            try {
                $final_name = $lab_meta['name'];
                $check = $dcmt_pdo->prepare('SELECT COUNT(*) FROM dcmt_lab_connections WHERE dcmt_name = ?');
                $check->execute([$final_name]);
                if ((int) $check->fetchColumn() > 0) {
                    if ($has_api_credentials) {
                        // Avoid unique name collision if reconnecting same lab branch
                        $suffix = $lab_meta['lab_remote_code'] ?: substr((string) $lab_meta['lab_remote_id'], 0, 8);
                        $final_name = $final_name . ($suffix ? ' (' . $suffix . ')' : ' (' . time() . ')');
                    } else {
                        $errors[] = trans('lab', 'name_exists');
                    }
                }

                if (empty($errors)) {
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_lab_connections (
                            dcmt_name, dcmt_lab_base_url, dcmt_api_key, dcmt_clinic_url, dcmt_clinic_name,
                            dcmt_lab_remote_id, dcmt_lab_remote_name, dcmt_lab_remote_code, dcmt_lab_organization,
                            dcmt_clinic_remote_id, dcmt_status, dcmt_last_synced_at, dcmt_notes,
                            dcmt_lab_url, dcmt_contact_person, dcmt_contact_number, dcmt_contact_email, dcmt_address,
                            dcmt_created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $final_name,
                        $form_data['lab_base_url'],
                        $form_data['api_key'],
                        $clinic_url,
                        $clinic_name,
                        $lab_meta['lab_remote_id'],
                        $lab_meta['lab_remote_name'],
                        $lab_meta['lab_remote_code'],
                        $lab_meta['lab_organization'],
                        $lab_meta['clinic_remote_id'],
                        $lab_meta['status'],
                        $has_api_credentials ? date('Y-m-d H:i:s') : null,
                        $form_data['notes'] !== '' ? $form_data['notes'] : null,
                        $form_data['lab_url'] !== '' ? $form_data['lab_url'] : null,
                        $form_data['contact_person'] !== '' ? $form_data['contact_person'] : null,
                        $form_data['contact_number'] !== '' ? $form_data['contact_number'] : null,
                        $form_data['contact_email'] !== '' ? $form_data['contact_email'] : null,
                        $form_data['address'] !== '' ? $form_data['address'] : null,
                        (string) ($user['dcmt_username'] ?? ''),
                    ]);

                    dcmt_log_activity('Lab connection added: ' . $final_name, 'lab_connection_added');
                    dcmt_show_message(
                        $lab_meta['status'] === 'active'
                            ? trans('lab', 'add_success')
                            : trans('lab', 'add_manual_success'),
                        'success'
                    );
                    dcmt_redirect('index.php');
                    exit();
                }
            } catch (PDOException $e) {
                error_log('Lab connection insert error: ' . $e->getMessage());
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('lab', 'add_connection'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'view_all_connections'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="labConnectionForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <h6 class="mb-3"><i class="fas fa-flask me-1"></i><?php echo trans('lab', 'lab_contact_details'); ?></h6>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="lab_name" class="form-label"><?php echo trans('lab', 'lab_name'); ?> <span class="text-danger">*</span></label>
                    <input type="text" class="form-control" id="lab_name" name="lab_name" maxlength="150"
                           placeholder="<?php echo htmlspecialchars(trans('lab', 'lab_name_placeholder')); ?>"
                           value="<?php echo htmlspecialchars($form_data['name']); ?>">
                    <div class="form-text"><?php echo trans('lab', 'lab_name_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="lab_url" class="form-label"><?php echo trans('lab', 'lab_url'); ?></label>
                    <input type="url" class="form-control" id="lab_url" name="lab_url" maxlength="255"
                           placeholder="<?php echo htmlspecialchars(trans('lab', 'lab_url_placeholder')); ?>"
                           value="<?php echo htmlspecialchars($form_data['lab_url']); ?>">
                    <div class="form-text"><?php echo trans('lab', 'lab_url_help'); ?></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="contact_person" class="form-label"><?php echo trans('lab', 'contact_person'); ?></label>
                    <input type="text" class="form-control" id="contact_person" name="contact_person" maxlength="150"
                           value="<?php echo htmlspecialchars($form_data['contact_person']); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="contact_number" class="form-label"><?php echo trans('lab', 'contact_number'); ?></label>
                    <input type="text" class="form-control" id="contact_number" name="contact_number" maxlength="50"
                           value="<?php echo htmlspecialchars($form_data['contact_number']); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="contact_email" class="form-label"><?php echo trans('lab', 'contact_email'); ?></label>
                    <input type="email" class="form-control" id="contact_email" name="contact_email" maxlength="150"
                           value="<?php echo htmlspecialchars($form_data['contact_email']); ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-8">
                <div class="mb-3">
                    <label for="address" class="form-label"><?php echo trans('lab', 'lab_address'); ?></label>
                    <textarea class="form-control" id="address" name="address" rows="2"><?php echo htmlspecialchars($form_data['address']); ?></textarea>
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="notes" class="form-label"><?php echo trans('lab', 'notes'); ?></label>
                    <textarea class="form-control" id="notes" name="notes" rows="2"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                </div>
            </div>
        </div>

        <hr class="my-4">

        <h6 class="mb-2"><i class="fas fa-plug me-1"></i><?php echo trans('lab', 'api_connection_section'); ?></h6>
        <p class="text-muted small"><?php echo trans('lab', 'api_connection_help_inline'); ?></p>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="lab_base_url" class="form-label"><?php echo trans('lab', 'lab_base_url'); ?></label>
                    <input type="url" class="form-control" id="lab_base_url" name="lab_base_url" maxlength="255"
                           placeholder="<?php echo htmlspecialchars(trans('lab', 'lab_base_url_placeholder')); ?>"
                           value="<?php echo htmlspecialchars($form_data['lab_base_url']); ?>">
                    <div class="form-text"><?php echo trans('lab', 'lab_base_url_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="api_key" class="form-label"><?php echo trans('lab', 'api_key'); ?></label>
                    <input type="text" class="form-control" id="api_key" name="api_key" maxlength="255" autocomplete="off"
                           placeholder="<?php echo htmlspecialchars(trans('lab', 'api_key_placeholder')); ?>"
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

        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn">
                <i class="fas fa-plus"></i><?php echo trans('lab', 'add_connection'); ?>
            </button>
        </div>
    </form>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
    const form = document.getElementById('labConnectionForm');
    const submitBtn = document.getElementById('submitBtn');
    if (!form || !submitBtn) {
        return;
    }

    form.addEventListener('submit', function () {
        if (submitBtn.disabled) {
            return;
        }
        const originalText = submitBtn.innerHTML;
        submitBtn.innerHTML = '<i class="fas fa-spinner fa-spin me-1"></i><?php echo addslashes(trans('common', 'processing')); ?>...';
        submitBtn.disabled = true;
        submitBtn.setAttribute('data-original-text', originalText);
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
