<?php
/**
 * Add Patient Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

// Ensure patients table exists with correct structure
$dcmt_db = new Dcmt_Database();
$dcmt_db->ensurePatientsTable();
$dcmt_db->addPatientColumns();

// Validate session
if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$errors = [];
$phone_error = '';
$form_data = [
    'first_name' => '',
    'fathers_last_name' => '',
    'mothers_last_name' => '',
    'patient_name' => '',
    'email' => '',
    'phone' => '',
    'gender' => 'male',
    'date_of_birth' => '',
    'age' => '',
    'height_cm' => '',
    'weight_kg' => '',
    'address' => '',
    'medications' => '',
    'allergies' => '',
    'emergency_contact_name' => '',
    'emergency_contact_relation' => '',
    'emergency_contact_phone' => '',
    'notes' => '',
    'status' => 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient', 'invalid_token');
    } else {
        // Sanitize input
        foreach ($form_data as $key => $default) {
            $form_data[$key] = isset($_POST[$key]) ? dcmt_sanitize_input($_POST[$key]) : $default;
        }

        if (!empty($form_data['phone'])) {
            $phone = preg_replace('/\s+/', '', $form_data['phone']);
            if (strpos($phone, '+') !== 0) {
                $digits = preg_replace('/\D+/', '', $phone);
                if ($digits !== '') {
                    $phone = '+52' . $digits;
                }
            }
            $form_data['phone'] = $phone;
        }

        if (!empty($form_data['emergency_contact_phone'])) {
            $emergency_phone = preg_replace('/\s+/', '', $form_data['emergency_contact_phone']);
            if (strpos($emergency_phone, '+') !== 0) {
                $digits = preg_replace('/\D+/', '', $emergency_phone);
                if ($digits !== '') {
                    $emergency_phone = '+52' . $digits;
                }
            }
            $form_data['emergency_contact_phone'] = $emergency_phone;
        }

        $full_first_name = trim($form_data['first_name']);
        $full_last_name = trim(
            trim($form_data['fathers_last_name'] ?? '') . ' ' . trim($form_data['mothers_last_name'] ?? '')
        );
        $form_data['patient_name'] = trim($full_first_name . ' ' . $full_last_name);

        // Required fields
        $required_fields = ['first_name', 'date_of_birth', 'phone', 'emergency_contact_name', 'status'];
        $validation_result = dcmt_validate_required_fields($form_data, $required_fields);
        if (!$validation_result['valid']) {
            $errors = array_merge($errors, $validation_result['errors']);
        }

        // Gender validation
        $allowed_genders = ['male', 'female', 'other'];
        if (!in_array($form_data['gender'], $allowed_genders, true)) {
            $errors[] = trans('patient', 'gender');
        }

        // Status validation
        if (!in_array($form_data['status'], ['active', 'inactive'], true)) {
            $errors[] = trans('patient', 'status');
        }

        // Email validation
        if (!empty($form_data['email']) && !dcmt_validate_email($form_data['email'])) {
            $errors[] = trans('patient', 'invalid_email');
        }

        // Date of birth validation
        $date_of_birth = null;
        if (!empty($form_data['date_of_birth'])) {
            $date_of_birth = $form_data['date_of_birth'];
            // Validate date format
            $date_parts = explode('-', $date_of_birth);
            if (count($date_parts) !== 3 || !checkdate((int)$date_parts[1], (int)$date_parts[2], (int)$date_parts[0])) {
                $errors[] = trans('patient', 'invalid_dob');
            } else {
                $dob_dt = DateTime::createFromFormat('Y-m-d', $date_of_birth);
                $today_dt = new DateTime();
                if ($dob_dt && $dob_dt > $today_dt) {
                    $errors[] = trans('patient', 'dob_in_future');
                } else {
                    $age_check = $dob_dt ? $today_dt->diff($dob_dt)->y : null;
                    if ($age_check !== null && ($age_check < 0 || $age_check > 150)) {
                        $errors[] = trans('patient', 'age_out_of_range');
                    }
                }
            }
        }

        // Calculate age from date of birth if provided
        $calculated_age = null;
        if (!empty($date_of_birth)) {
            $dob = new DateTime($date_of_birth);
            $today = new DateTime();
            $calculated_age = $today->diff($dob)->y;
        }
        
        // Use calculated age if DOB provided, otherwise use manual age entry
        $final_age = $calculated_age !== null ? $calculated_age : ($form_data['age'] !== '' ? (int)$form_data['age'] : null);

        // Numeric validations
        if ($calculated_age === null) {
            $age_error = $form_data['age'] !== '' ? dcmt_validate_numeric_field($form_data['age'], trans('patient', 'age'), 0) : null;
            if ($form_data['age'] !== '' && (int)$form_data['age'] > 150) {
                $errors[] = trans('patient', 'age_out_of_range');
            }
            if ($age_error) $errors[] = $age_error;
        }
        $height_error = $form_data['height_cm'] !== '' ? dcmt_validate_numeric_field($form_data['height_cm'], trans('patient', 'height'), 0) : null;
        if ($height_error) $errors[] = $height_error;
        $weight_error = $form_data['weight_kg'] !== '' ? dcmt_validate_numeric_field($form_data['weight_kg'], trans('patient', 'weight'), 0) : null;
        if ($weight_error) $errors[] = $weight_error;

        if (!empty($form_data['phone']) && !empty($form_data['patient_name'])) {
            try {
                $existing_patient = dcmt_find_patient_by_name_and_phone($dcmt_pdo, $form_data['patient_name'], $form_data['phone']);
                if ($existing_patient) {
                    $phone_error = trans('patient', 'patient_already_exists');
                    $errors[] = $phone_error;
                }
            } catch (Throwable $e) {
                error_log("Error checking duplicate patient (name+phone): " . $e->getMessage());
            }
        }

        if (empty($errors)) {
            try {
                $sql = "INSERT INTO dcmt_patients (
                    dcmt_first_name, dcmt_fathers_last_name, dcmt_mothers_last_name, dcmt_patient_name, dcmt_gender, dcmt_date_of_birth, dcmt_age, dcmt_height_cm, dcmt_weight_kg,
                    dcmt_email, dcmt_phone, dcmt_address,
                    dcmt_medications, dcmt_allergies,
                    dcmt_emergency_contact_name, dcmt_emergency_contact_relation, dcmt_emergency_contact_phone,
                    dcmt_notes, dcmt_status, dcmt_created_by
                ) VALUES (
                    ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?
                )";

                $stmt = $dcmt_pdo->prepare($sql);
                $stmt->execute([
                    $form_data['first_name'],
                    !empty($form_data['fathers_last_name']) ? $form_data['fathers_last_name'] : null,
                    !empty($form_data['mothers_last_name']) ? $form_data['mothers_last_name'] : null,
                    $form_data['patient_name'],
                    $form_data['gender'],
                    !empty($date_of_birth) ? $date_of_birth : null,
                    $final_age,
                    $form_data['height_cm'] !== '' ? (float)$form_data['height_cm'] : null,
                    $form_data['weight_kg'] !== '' ? (float)$form_data['weight_kg'] : null,
                    !empty($form_data['email']) ? $form_data['email'] : null,
                    $form_data['phone'],
                    !empty($form_data['address']) ? $form_data['address'] : null,
                    !empty($form_data['medications']) ? $form_data['medications'] : null,
                    !empty($form_data['allergies']) ? $form_data['allergies'] : null,
                    !empty($form_data['emergency_contact_name']) ? $form_data['emergency_contact_name'] : null,
                    !empty($form_data['emergency_contact_relation']) ? $form_data['emergency_contact_relation'] : null,
                    !empty($form_data['emergency_contact_phone']) ? $form_data['emergency_contact_phone'] : null,
                    !empty($form_data['notes']) ? $form_data['notes'] : null,
                    $form_data['status'],
                    dcmt_get_current_user()['dcmt_username']
                ]);

                dcmt_log_activity('Patient created', "Name: {$form_data['patient_name']}");
                dcmt_show_message(trans('patient', 'add_success'), 'success');
                dcmt_redirect('index.php');
                exit();
            } catch (PDOException $e) {
                error_log("Error adding patient: " . $e->getMessage());
                if (isset($stmt) && $stmt !== false) {
                    error_log("SQL Error Info: " . print_r($stmt->errorInfo(), true));
                }
                $errors[] = trans('patient', 'database_error') . ': ' . $e->getMessage();
            }
        }
    }
}

$csrf_token = dcmt_generate_csrf_token();

require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="../../assets/css/add-income.css">

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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('patient', 'add_patient'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient', 'view_all_patients'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="dcmtPatientForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <?php include __DIR__ . '/form_fields.php'; ?>

        <div class="dcmt-form-actions">
            <button type="button" class="btn dcmt-btn-reset" id="dcmtResetPatientBtn">
                <i class="fas fa-undo"></i><?php echo trans('common', 'reset'); ?>
            </button>
            <a href="index.php" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit">
                <i class="fas fa-plus"></i><?php echo trans('patient', 'add_patient_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
function dcmt_resetPatientForm() {
    const form = document.getElementById('dcmtPatientForm');
    if (!form) return;
    if (confirm('<?php echo trans('patient', 'confirm_reset'); ?>')) {
        form.reset();
        const helper = document.getElementById('dob_age_helper');
        if (helper) {
            const defaultText = helper.getAttribute('data-default-text') || '';
            helper.textContent = defaultText;
        }
    }
}

function calculateAgeFromDOB() {
    const dobField = document.getElementById('date_of_birth');
    const helper = document.getElementById('dob_age_helper');
    if (!dobField || !helper) return;
    
    const dobValue = dobField.value;
    if (!dobValue) {
        const defaultText = helper.getAttribute('data-default-text') || '';
        helper.textContent = defaultText;
        return;
    }
    
    const dob = new Date(dobValue);
    const today = new Date();
    let age = today.getFullYear() - dob.getFullYear();
    const monthDiff = today.getMonth() - dob.getMonth();
    
    if (monthDiff < 0 || (monthDiff === 0 && today.getDate() < dob.getDate())) {
        age--;
    }
    
    if (age >= 0 && age <= 150) {
        const suffix = helper.getAttribute('data-years-suffix') || '';
        helper.textContent = suffix ? age + ' ' + suffix : String(age);
    } else {
        const defaultText = helper.getAttribute('data-default-text') || '';
        helper.textContent = defaultText;
    }
}

document.addEventListener('DOMContentLoaded', function() {
    const resetBtn = document.getElementById('dcmtResetPatientBtn');
    if (resetBtn) {
        resetBtn.addEventListener('click', dcmt_resetPatientForm);
    }
    
    // Calculate age when DOB changes
    const dobField = document.getElementById('date_of_birth');
    if (dobField) {
        dobField.addEventListener('change', calculateAgeFromDOB);
        // Also calculate on page load if DOB is already set
        if (dobField.value) {
            calculateAgeFromDOB();
        }
    }
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>


