<?php
/**
 * Lab Work Orders - Create
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

$current_user = dcmt_get_current_user();
$role = $current_user['dcmt_role'] ?? '';
$dcmt_is_doctor_user = $role === 'doctor';
$dcmt_is_owner_doctor = $dcmt_is_doctor_user && dcmt_is_owner_doctor_user($current_user);
$dcmt_is_restricted_doctor = $dcmt_is_doctor_user && !$dcmt_is_owner_doctor;
if (!in_array($role, ['admin', 'doctor'], true) && !dcmt_is_admin()) {
    dcmt_show_message('Access denied.', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
    exit();
}

dcmt_ensure_lab_tables($dcmt_pdo);

$labs = dcmt_lab_get_active_connections($dcmt_pdo);

try {
    if ($dcmt_is_restricted_doctor) {
        $stmt = $dcmt_pdo->prepare("
            SELECT dcmt_id, dcmt_full_name, dcmt_email, dcmt_phone, dcmt_address
            FROM dcmt_users
            WHERE dcmt_id = ? AND dcmt_role = 'doctor' AND dcmt_status = 'active'
            LIMIT 1
        ");
        $stmt->execute([(int) ($current_user['dcmt_id'] ?? 0)]);
        $doctors = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $doctors = $dcmt_pdo->query("
            SELECT dcmt_id, dcmt_full_name, dcmt_email, dcmt_phone, dcmt_address
            FROM dcmt_users
            WHERE dcmt_role = 'doctor' AND dcmt_status = 'active'
            ORDER BY dcmt_full_name
        ")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    $doctors = [];
}

try {
    $patients = $dcmt_pdo->query("
        SELECT dcmt_id, dcmt_patient_name
        FROM dcmt_patients
        WHERE dcmt_status = 'active'
        ORDER BY dcmt_patient_name
        LIMIT 1000
    ")->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patients = [];
}

$preselected_lab = isset($_GET['lab_id']) ? (int) $_GET['lab_id'] : 0;
$errors = [];
$form_data = [
    'lab_connection_id' => $preselected_lab,
    'doctor_user_id' => $dcmt_is_doctor_user ? (int) ($current_user['dcmt_id'] ?? 0) : 0,
    'patient_id' => 0,
    'patient_name' => '',
    'doctor_name' => $dcmt_is_doctor_user ? ($current_user['dcmt_full_name'] ?? '') : '',
    'doctor_email' => $dcmt_is_doctor_user ? ($current_user['dcmt_email'] ?? '') : '',
    'doctor_phone' => $dcmt_is_doctor_user ? ($current_user['dcmt_phone'] ?? '') : '',
    'doctor_address' => $dcmt_is_doctor_user ? ($current_user['dcmt_address'] ?? '') : '',
    'prosthesis_type_id' => '',
    'prosthesis_type_name' => '',
    'box_number' => '',
    'color' => '',
    'specification' => '',
    'notes' => '',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('common', 'invalid_token');
    } else {
        $form_data = [
            'lab_connection_id' => (int) ($_POST['lab_connection_id'] ?? 0),
            'doctor_user_id' => (int) ($_POST['doctor_user_id'] ?? 0),
            'patient_id' => (int) ($_POST['patient_id'] ?? 0),
            'patient_name' => dcmt_sanitize_input($_POST['patient_name'] ?? ''),
            'doctor_name' => dcmt_sanitize_input($_POST['doctor_name'] ?? ''),
            'doctor_email' => dcmt_sanitize_input($_POST['doctor_email'] ?? ''),
            'doctor_phone' => dcmt_sanitize_input($_POST['doctor_phone'] ?? ''),
            'doctor_address' => dcmt_sanitize_input($_POST['doctor_address'] ?? ''),
            'prosthesis_type_id' => dcmt_sanitize_input($_POST['prosthesis_type_id'] ?? ''),
            'prosthesis_type_name' => dcmt_sanitize_input($_POST['prosthesis_type_name'] ?? ''),
            'box_number' => dcmt_sanitize_input($_POST['box_number'] ?? ''),
            'color' => dcmt_sanitize_input($_POST['color'] ?? ''),
            'specification' => isset($_POST['specification']) ? dcmt_sanitize_input($_POST['specification']) : '',
            'notes' => isset($_POST['notes']) ? dcmt_sanitize_input($_POST['notes']) : '',
        ];

        if ($dcmt_is_restricted_doctor) {
            $form_data['doctor_user_id'] = (int) ($current_user['dcmt_id'] ?? 0);
            $form_data['doctor_name'] = (string) ($current_user['dcmt_full_name'] ?? '');
            $form_data['doctor_email'] = (string) ($current_user['dcmt_email'] ?? '');
            $form_data['doctor_phone'] = (string) ($current_user['dcmt_phone'] ?? '');
            $form_data['doctor_address'] = (string) ($current_user['dcmt_address'] ?? '');
        }

        // Resolve names from selected doctor/patient when hidden fields are empty
        if ($form_data['doctor_user_id'] > 0 && $form_data['doctor_name'] === '') {
            foreach ($doctors as $doc) {
                if ((int) $doc['dcmt_id'] === $form_data['doctor_user_id']) {
                    $form_data['doctor_name'] = $doc['dcmt_full_name'];
                    if ($form_data['doctor_email'] === '') {
                        $form_data['doctor_email'] = (string) ($doc['dcmt_email'] ?? '');
                    }
                    if ($form_data['doctor_phone'] === '') {
                        $form_data['doctor_phone'] = (string) ($doc['dcmt_phone'] ?? '');
                    }
                    if ($form_data['doctor_address'] === '') {
                        $form_data['doctor_address'] = (string) ($doc['dcmt_address'] ?? '');
                    }
                    break;
                }
            }
        }
        if ($form_data['patient_id'] > 0 && $form_data['patient_name'] === '') {
            foreach ($patients as $patient) {
                if ((int) $patient['dcmt_id'] === $form_data['patient_id']) {
                    $form_data['patient_name'] = $patient['dcmt_patient_name'];
                    break;
                }
            }
        }

        if ($form_data['lab_connection_id'] <= 0) {
            $errors[] = trans('lab', 'lab_required');
        }
        if ($form_data['doctor_user_id'] <= 0 || $form_data['doctor_name'] === '') {
            $errors[] = trans('lab', 'doctor_name_required');
        }
        if ($form_data['patient_id'] <= 0 || $form_data['patient_name'] === '') {
            $errors[] = trans('lab', 'patient_required');
        }
        if ($form_data['prosthesis_type_id'] === '') {
            $errors[] = trans('lab', 'prosthesis_type_required');
        }

        $connection = null;
        if (empty($errors)) {
            $connection = dcmt_lab_get_connection($dcmt_pdo, $form_data['lab_connection_id']);
            if (!$connection || $connection['dcmt_status'] !== 'active') {
                $errors[] = trans('lab', 'inactive_lab');
            }
        }

        if (empty($errors) && $connection) {
            $payload = [
                'clinicUrl' => $connection['dcmt_clinic_url'],
                'doctorName' => $form_data['doctor_name'],
                'patient' => $form_data['patient_name'],
                'prosthesisTypeId' => $form_data['prosthesis_type_id'],
            ];
            if ($form_data['doctor_email'] !== '') {
                $payload['doctorEmail'] = $form_data['doctor_email'];
            }
            if ($form_data['doctor_phone'] !== '') {
                $payload['doctorPhone'] = $form_data['doctor_phone'];
            }
            if ($form_data['doctor_address'] !== '') {
                $payload['doctorAddress'] = $form_data['doctor_address'];
            }
            if ($form_data['box_number'] !== '') {
                $payload['boxNumber'] = $form_data['box_number'];
            }
            if ($form_data['color'] !== '') {
                $payload['color'] = $form_data['color'];
            }
            if ($form_data['specification'] !== '') {
                $payload['specification'] = $form_data['specification'];
            }
            if ($form_data['notes'] !== '') {
                $payload['notes'] = $form_data['notes'];
            }
            $payload['totalQuote'] = 0.01;
            $payload['initialPayment'] = 0.01;

            $api = dcmt_lab_create_work_order(
                $connection['dcmt_lab_base_url'],
                $connection['dcmt_api_key'],
                $payload
            );

            if (!$api['success']) {
                $errors[] = trans('lab', 'work_order_api_failed') . ': ' . dcmt_lab_extract_error_message($api);
            } else {
                $data = is_array($api['data']) ? $api['data'] : [];
                try {
                    $stmt = $dcmt_pdo->prepare("
                        INSERT INTO dcmt_lab_work_orders (
                            dcmt_lab_connection_id, dcmt_patient_id, dcmt_doctor_user_id,
                            dcmt_patient_name, dcmt_doctor_name, dcmt_doctor_email, dcmt_doctor_phone, dcmt_doctor_address,
                            dcmt_prosthesis_type_id, dcmt_prosthesis_type_name, dcmt_box_number, dcmt_color,
                            dcmt_specification, dcmt_notes, dcmt_total_quote, dcmt_initial_payment,
                            dcmt_folio_number, dcmt_remote_work_order_id,
                            dcmt_remote_status, dcmt_qr_token, dcmt_api_response, dcmt_created_by
                        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)
                    ");
                    $stmt->execute([
                        $form_data['lab_connection_id'],
                        $form_data['patient_id'] > 0 ? $form_data['patient_id'] : null,
                        $form_data['doctor_user_id'] > 0 ? $form_data['doctor_user_id'] : null,
                        $form_data['patient_name'],
                        $form_data['doctor_name'],
                        $form_data['doctor_email'] !== '' ? $form_data['doctor_email'] : null,
                        $form_data['doctor_phone'] !== '' ? $form_data['doctor_phone'] : null,
                        $form_data['doctor_address'] !== '' ? $form_data['doctor_address'] : null,
                        $form_data['prosthesis_type_id'],
                        $form_data['prosthesis_type_name'] !== '' ? $form_data['prosthesis_type_name'] : null,
                        $form_data['box_number'] !== '' ? $form_data['box_number'] : null,
                        $form_data['color'] !== '' ? $form_data['color'] : null,
                        $form_data['specification'] !== '' ? $form_data['specification'] : null,
                        $form_data['notes'] !== '' ? $form_data['notes'] : null,
                        0.01,
                        0.01,
                        $data['folioNumber'] ?? null,
                        $data['id'] ?? null,
                        $data['status'] ?? 'CREATED',
                        $data['qrToken'] ?? null,
                        $api['raw'],
                        $current_user['dcmt_username'],
                    ]);

                    $new_id = (int) $dcmt_pdo->lastInsertId();
                    dcmt_log_activity(
                        'Lab work order created: ' . ($data['folioNumber'] ?? $new_id),
                        'lab_work_order_created'
                    );
                    dcmt_show_message(trans('lab', 'work_order_success'), 'success');
                    dcmt_redirect('view.php?id=' . $new_id);
                    exit();
                } catch (PDOException $e) {
                    error_log('Lab work order insert error: ' . $e->getMessage());
                    $detail = '';
                    if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
                        $detail = ': ' . $e->getMessage();
                    }
                    $errors[] = trans('lab', 'database_error') . $detail;
                }
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();
$doctors_json = [];
foreach ($doctors as $doc) {
    $doctors_json[(int) $doc['dcmt_id']] = [
        'name' => $doc['dcmt_full_name'],
        'email' => $doc['dcmt_email'] ?? '',
        'phone' => $doc['dcmt_phone'] ?? '',
        'address' => $doc['dcmt_address'] ?? '',
    ];
}
$patients_json = [];
foreach ($patients as $patient) {
    $patients_json[(int) $patient['dcmt_id']] = $patient['dcmt_patient_name'];
}

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

<?php if (empty($labs)): ?>
    <div class="alert alert-warning">
        <?php echo htmlspecialchars(trans('lab', 'no_active_labs')); ?>
        <?php if (in_array($role, ['admin', 'staff'], true) || dcmt_is_admin()): ?>
            <a href="../lab_connections/add.php" class="alert-link"><?php echo trans('lab', 'add_connection'); ?></a>
        <?php endif; ?>
    </div>
<?php endif; ?>

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('lab', 'add_work_order'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'view_all_work_orders'); ?></a>
        </div>
    </div>

    <form method="POST" action="" id="labWorkOrderForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">
        <input type="hidden" name="prosthesis_type_name" id="prosthesis_type_name" value="<?php echo htmlspecialchars($form_data['prosthesis_type_name']); ?>">

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="lab_connection_id" class="form-label"><?php echo trans('lab', 'select_lab'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="lab_connection_id" name="lab_connection_id" required <?php echo empty($labs) ? 'disabled' : ''; ?>>
                        <option value=""><?php echo trans('lab', 'select_lab'); ?></option>
                        <?php foreach ($labs as $lab): ?>
                            <option value="<?php echo (int) $lab['dcmt_id']; ?>"
                                <?php echo (int) $form_data['lab_connection_id'] === (int) $lab['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars(dcmt_lab_connection_display_name($lab)); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <div class="form-text"><?php echo trans('lab', 'select_lab_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label class="form-label"><?php echo trans('lab', 'next_folio'); ?></label>
                    <input type="text" class="form-control" id="next_folio" readonly value="" placeholder="—">
                    <div class="form-text" id="setupStatus"></div>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="doctor_user_id" class="form-label"><?php echo trans('lab', 'doctor'); ?> <span class="text-danger">*</span></label>
                    <?php if ($dcmt_is_restricted_doctor): ?>
                        <input type="hidden" id="doctor_user_id" name="doctor_user_id" value="<?php echo (int) $form_data['doctor_user_id']; ?>">
                        <input type="text" class="form-control" value="<?php echo htmlspecialchars($form_data['doctor_name']); ?>" readonly>
                    <?php else: ?>
                        <select class="form-select" id="doctor_user_id" name="doctor_user_id" required>
                            <option value=""><?php echo trans('lab', 'select_doctor'); ?></option>
                            <?php foreach ($doctors as $doc): ?>
                                <option value="<?php echo (int) $doc['dcmt_id']; ?>"
                                    <?php echo (int) $form_data['doctor_user_id'] === (int) $doc['dcmt_id'] ? 'selected' : ''; ?>>
                                    <?php echo htmlspecialchars($doc['dcmt_full_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                    <input type="hidden" id="doctor_name" name="doctor_name" value="<?php echo htmlspecialchars($form_data['doctor_name']); ?>">
                    <div class="form-text"><?php echo trans('lab', 'doctor_help'); ?></div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="patient_id" class="form-label"><?php echo trans('lab', 'patient'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="patient_id" name="patient_id" required>
                        <option value=""><?php echo trans('lab', 'select_patient'); ?></option>
                        <?php foreach ($patients as $patient): ?>
                            <option value="<?php echo (int) $patient['dcmt_id']; ?>"
                                <?php echo (int) $form_data['patient_id'] === (int) $patient['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($patient['dcmt_patient_name']); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                    <input type="hidden" id="patient_name" name="patient_name" value="<?php echo htmlspecialchars($form_data['patient_name']); ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="doctor_email" class="form-label"><?php echo trans('lab', 'doctor_email'); ?></label>
                    <input type="email" class="form-control" id="doctor_email" name="doctor_email" maxlength="150"
                           value="<?php echo htmlspecialchars($form_data['doctor_email']); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="doctor_phone" class="form-label"><?php echo trans('lab', 'doctor_phone'); ?></label>
                    <input type="text" class="form-control" id="doctor_phone" name="doctor_phone" maxlength="50"
                           value="<?php echo htmlspecialchars($form_data['doctor_phone']); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="doctor_address" class="form-label"><?php echo trans('lab', 'doctor_address'); ?></label>
                    <input type="text" class="form-control" id="doctor_address" name="doctor_address"
                           value="<?php echo htmlspecialchars($form_data['doctor_address']); ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="prosthesis_type_id" class="form-label"><?php echo trans('lab', 'prosthesis_type'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="prosthesis_type_id" name="prosthesis_type_id" required>
                        <option value=""><?php echo trans('lab', 'select_prosthesis_type'); ?></option>
                    </select>
                    <div class="form-text"><?php echo trans('lab', 'prosthesis_type_help'); ?></div>
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="box_number" class="form-label"><?php echo trans('lab', 'box_number'); ?></label>
                    <input type="text" class="form-control" id="box_number" name="box_number" maxlength="100"
                           value="<?php echo htmlspecialchars($form_data['box_number']); ?>">
                </div>
            </div>
            <div class="col-md-4">
                <div class="mb-3">
                    <label for="color" class="form-label"><?php echo trans('lab', 'color'); ?></label>
                    <input type="text" class="form-control" id="color" name="color" maxlength="50"
                           value="<?php echo htmlspecialchars($form_data['color']); ?>">
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="specification" class="form-label"><?php echo trans('lab', 'specification'); ?></label>
                    <textarea class="form-control" id="specification" name="specification" rows="3"
                              placeholder="<?php echo htmlspecialchars(trans('lab', 'specification_placeholder')); ?>"><?php echo htmlspecialchars($form_data['specification']); ?></textarea>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="notes" class="form-label"><?php echo trans('lab', 'work_order_notes'); ?></label>
                    <textarea class="form-control" id="notes" name="notes" rows="3"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
                </div>
            </div>
        </div>

        <div class="dcmt-form-actions">
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit" id="submitBtn" <?php echo empty($labs) ? 'disabled' : ''; ?>>
                <i class="fas fa-plus"></i><?php echo trans('lab', 'create_work_order_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
(function () {
    const doctors = <?php echo json_encode($doctors_json, JSON_UNESCAPED_UNICODE); ?>;
    const patients = <?php echo json_encode($patients_json, JSON_UNESCAPED_UNICODE); ?>;
    const selectedProsthesis = <?php echo json_encode($form_data['prosthesis_type_id']); ?>;
    const labSelect = document.getElementById('lab_connection_id');
    const prosthesisSelect = document.getElementById('prosthesis_type_id');
    const prosthesisNameField = document.getElementById('prosthesis_type_name');
    const nextFolio = document.getElementById('next_folio');
    const setupStatus = document.getElementById('setupStatus');
    const doctorSelect = document.getElementById('doctor_user_id');
    const patientSelect = document.getElementById('patient_id');

    function fillDoctorFields(id) {
        const doc = doctors[String(id)] || doctors[id];
        if (!doc) {
            document.getElementById('doctor_name').value = '';
            return;
        }
        document.getElementById('doctor_name').value = doc.name || '';
        document.getElementById('doctor_email').value = doc.email || '';
        document.getElementById('doctor_phone').value = doc.phone || '';
        document.getElementById('doctor_address').value = doc.address || '';
    }

    function fillPatientName(id) {
        const name = patients[String(id)] || patients[id] || '';
        document.getElementById('patient_name').value = name;
    }

    function loadSetup(labId) {
        prosthesisSelect.innerHTML = '<option value=""><?php echo addslashes(trans('lab', 'select_prosthesis_type')); ?></option>';
        nextFolio.value = '';
        prosthesisNameField.value = '';
        if (!labId) {
            setupStatus.textContent = '';
            return;
        }
        setupStatus.textContent = '<?php echo addslashes(trans('lab', 'setup_loading')); ?>';
        fetch('setup_ajax.php?lab_id=' + encodeURIComponent(labId), {
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        })
            .then(function (r) { return r.json(); })
            .then(function (data) {
                if (!data.success) {
                    setupStatus.textContent = data.message || '<?php echo addslashes(trans('lab', 'setup_load_failed')); ?>';
                    return;
                }
                setupStatus.textContent = '';
                nextFolio.value = data.nextFolioNumber || '';
                (data.prosthesisTypes || []).forEach(function (type) {
                    const opt = document.createElement('option');
                    opt.value = type.id || '';
                    opt.textContent = type.name || type.id || '';
                    opt.setAttribute('data-name', type.name || '');
                    if (selectedProsthesis && selectedProsthesis === opt.value) {
                        opt.selected = true;
                        prosthesisNameField.value = type.name || '';
                    }
                    prosthesisSelect.appendChild(opt);
                });
            })
            .catch(function () {
                setupStatus.textContent = '<?php echo addslashes(trans('lab', 'setup_load_failed')); ?>';
            });
    }

    document.addEventListener('DOMContentLoaded', function () {
        const doctorSelectIsDropdown = doctorSelect && doctorSelect.tagName === 'SELECT';

        if (typeof $ !== 'undefined' && $.fn && typeof $.fn.select2 === 'function') {
            if (doctorSelectIsDropdown) {
                $('#doctor_user_id').select2({
                    placeholder: <?php echo json_encode(trans('lab', 'select_doctor')); ?>,
                    allowClear: true,
                    width: '100%',
                    minimumResultsForSearch: 0
                });
            }
            $('#patient_id').select2({
                placeholder: <?php echo json_encode(trans('lab', 'select_patient')); ?>,
                allowClear: true,
                width: '100%',
                minimumResultsForSearch: 0
            });

            $(document).on('select2:open', function () {
                setTimeout(function () {
                    const input = document.querySelector('.select2-container--open .select2-search__field');
                    if (input) {
                        input.focus();
                    }
                }, 0);
            });

            if (doctorSelectIsDropdown) {
                $('#doctor_user_id').on('change', function () {
                    fillDoctorFields(this.value);
                });
            }
            $('#patient_id').on('change', function () {
                fillPatientName(this.value);
            });
        } else {
            if (doctorSelectIsDropdown) {
                doctorSelect.addEventListener('change', function () {
                    fillDoctorFields(this.value);
                });
            }
            if (patientSelect) {
                patientSelect.addEventListener('change', function () {
                    fillPatientName(this.value);
                });
            }
        }

        if (doctorSelect && doctorSelect.value) {
            fillDoctorFields(doctorSelect.value);
        }
        if (patientSelect && patientSelect.value) {
            fillPatientName(patientSelect.value);
        }

        if (prosthesisSelect) {
            prosthesisSelect.addEventListener('change', function () {
                const opt = this.options[this.selectedIndex];
                prosthesisNameField.value = opt ? (opt.getAttribute('data-name') || opt.textContent || '') : '';
            });
        }
        if (labSelect) {
            labSelect.addEventListener('change', function () {
                loadSetup(this.value);
            });
            if (labSelect.value) {
                loadSetup(labSelect.value);
            }
        }
    });
})();
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
