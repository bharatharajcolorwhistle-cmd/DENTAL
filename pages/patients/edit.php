<?php
/**
 * Edit Patient Page
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

$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_patients WHERE dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
} catch (PDOException $e) {
    error_log("Error fetching patient: " . $e->getMessage());
    dcmt_show_message(trans('patient', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$errors = [];
$phone_error = '';
$full_name = $patient['dcmt_patient_name'] ?? '';

$stored_first_name = isset($patient['dcmt_first_name']) ? trim((string)$patient['dcmt_first_name']) : '';
$father_ln = isset($patient['dcmt_fathers_last_name']) ? trim((string)$patient['dcmt_fathers_last_name']) : '';
$mother_ln = isset($patient['dcmt_mothers_last_name']) ? trim((string)$patient['dcmt_mothers_last_name']) : '';

// If new fields are empty, derive from patient_name as a fallback
if ($stored_first_name === '' && is_string($full_name) && $full_name !== '') {
    $parts = preg_split('/\s+/', trim($full_name), 2);
    $stored_first_name = $parts[0] ?? '';
    $maybe_last = $parts[1] ?? '';
    if ($father_ln === '' && $mother_ln === '' && $maybe_last !== '') {
        $ln_parts2 = preg_split('/\s+/', $maybe_last);
        if (count($ln_parts2) === 1) {
            $father_ln = $ln_parts2[0];
        } elseif (count($ln_parts2) >= 2) {
            $father_ln = array_shift($ln_parts2);
            $mother_ln = trim(implode(' ', $ln_parts2));
        }
    }
}

$form_data = [
    'first_name' => $stored_first_name,
    'fathers_last_name' => $father_ln,
    'mothers_last_name' => $mother_ln,
    'patient_name' => $full_name,
    'email' => $patient['dcmt_email'] ?? '',
    'phone' => $patient['dcmt_phone'] ?? '',
    'gender' => $patient['dcmt_gender'] ?? 'other',
    'date_of_birth' => $patient['dcmt_date_of_birth'] ?? '',
    'age' => $patient['dcmt_age'] ?? '',
    'height_cm' => $patient['dcmt_height_cm'] ?? '',
    'weight_kg' => $patient['dcmt_weight_kg'] ?? '',
    'address' => $patient['dcmt_address'] ?? '',
    'medications' => $patient['dcmt_medications'] ?? '',
    'allergies' => $patient['dcmt_allergies'] ?? '',
    'emergency_contact_name' => $patient['dcmt_emergency_contact_name'] ?? '',
    'emergency_contact_relation' => $patient['dcmt_emergency_contact_relation'] ?? '',
    'emergency_contact_phone' => $patient['dcmt_emergency_contact_phone'] ?? '',
    'notes' => $patient['dcmt_notes'] ?? '',
    'status' => $patient['dcmt_status'] ?? 'active',
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient', 'invalid_token');
    } else {
        foreach ($form_data as $key => $value) {
            $form_data[$key] = isset($_POST[$key]) ? dcmt_sanitize_input($_POST[$key]) : '';
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

        $full_first_name = trim($form_data['first_name']);
        $full_last_name = trim(
            trim($form_data['fathers_last_name'] ?? '') . ' ' . trim($form_data['mothers_last_name'] ?? '')
        );
        $form_data['patient_name'] = trim($full_first_name . ' ' . $full_last_name);

        $required_fields = ['first_name', 'phone', 'status'];
        $validation_result = dcmt_validate_required_fields($form_data, $required_fields);
        if (!$validation_result['valid']) {
            $errors = array_merge($errors, $validation_result['errors']);
        }

        $allowed_genders = ['male', 'female', 'other'];
        if (!in_array($form_data['gender'], $allowed_genders, true)) {
            $errors[] = trans('patient', 'gender');
        }

        if (!in_array($form_data['status'], ['active', 'inactive'], true)) {
            $errors[] = trans('patient', 'status');
        }

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
            if ($age_error) $errors[] = $age_error;
        }
        $height_error = $form_data['height_cm'] !== '' ? dcmt_validate_numeric_field($form_data['height_cm'], trans('patient', 'height'), 0) : null;
        if ($height_error) $errors[] = $height_error;
        $weight_error = $form_data['weight_kg'] !== '' ? dcmt_validate_numeric_field($form_data['weight_kg'], trans('patient', 'weight'), 0) : null;
        if ($weight_error) $errors[] = $weight_error;

        if (!empty($form_data['phone']) && !empty($form_data['patient_name'])) {
            try {
                $existing_patient = dcmt_find_patient_by_name_and_phone($dcmt_pdo, $form_data['patient_name'], $form_data['phone'], $patient_id);
                if ($existing_patient) {
                    $phone_error = trans('patient', 'patient_already_exists');
                    $errors[] = $phone_error;
                }
            } catch (Throwable $e) {
                error_log("Error checking duplicate patient (name+phone) on edit: " . $e->getMessage());
            }
        }

        if (empty($errors)) {
            try {
                $update_sql = "UPDATE dcmt_patients SET
                    dcmt_first_name = ?, dcmt_fathers_last_name = ?, dcmt_mothers_last_name = ?, dcmt_patient_name = ?, dcmt_gender = ?, dcmt_date_of_birth = ?, dcmt_age = ?, dcmt_height_cm = ?, dcmt_weight_kg = ?, dcmt_email = ?,
                    dcmt_phone = ?, dcmt_address = ?, dcmt_medications = ?, dcmt_allergies = ?,
                    dcmt_emergency_contact_name = ?, dcmt_emergency_contact_relation = ?, dcmt_emergency_contact_phone = ?,
                    dcmt_notes = ?, dcmt_status = ?, dcmt_updated_at = CURRENT_TIMESTAMP
                    WHERE dcmt_id = ?";

                $stmt = $dcmt_pdo->prepare($update_sql);
                $stmt->execute([
                    $form_data['first_name'],
                    !empty($form_data['fathers_last_name']) ? $form_data['fathers_last_name'] : null,
                    !empty($form_data['mothers_last_name']) ? $form_data['mothers_last_name'] : null,
                    $form_data['patient_name'],
                    $form_data['gender'],
                    !empty($date_of_birth) ? $date_of_birth : null,
                    $final_age,
                    $form_data['height_cm'] !== '' ? $form_data['height_cm'] : null,
                    $form_data['weight_kg'] !== '' ? $form_data['weight_kg'] : null,
                    $form_data['email'],
                    $form_data['phone'],
                    $form_data['address'],
                    $form_data['medications'],
                    $form_data['allergies'],
                    $form_data['emergency_contact_name'],
                    $form_data['emergency_contact_relation'],
                    $form_data['emergency_contact_phone'],
                    $form_data['notes'],
                    $form_data['status'],
                    $patient_id
                ]);

                try {
                    $income_update_sql = "UPDATE dcmt_income SET dcmt_patient_name = ? WHERE dcmt_patient_id = ?";
                    $income_stmt = $dcmt_pdo->prepare($income_update_sql);
                    $income_stmt->execute([$form_data['patient_name'], $patient_id]);
                } catch (PDOException $e) {
                    error_log("Error updating income patient names for patient $patient_id: " . $e->getMessage());
                }

                dcmt_log_activity('Patient updated', "Patient ID: $patient_id");
                dcmt_show_message(trans('patient', 'update_success'), 'success');
                dcmt_redirect('view.php?id=' . $patient_id);
                exit();
            } catch (PDOException $e) {
                error_log("Error updating patient: " . $e->getMessage());
                $errors[] = trans('patient', 'database_error');
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
            <h1 class="dcmt-add-form-page-title"><?php echo trans('patient', 'edit_patient'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient', 'view_all_patients'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="dcmtPatientEditForm">
        <input type="hidden" name="csrf_token" value="<?php echo $csrf_token; ?>">

        <?php
        // Reuse form layout from add.php for consistency
        include __DIR__ . '/form_fields.php';
        ?>

        <div class="dcmt-form-actions">
            <a href="view.php?id=<?php echo $patient_id; ?>" class="btn dcmt-btn-cancel">
                <i class="fas fa-times"></i><?php echo trans('common', 'cancel'); ?>
            </a>
            <button type="submit" class="btn dcmt-btn-submit">
                <i class="fas fa-save"></i><?php echo trans('patient', 'update_patient_record'); ?>
            </button>
        </div>
    </form>
</div>

<script>
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

