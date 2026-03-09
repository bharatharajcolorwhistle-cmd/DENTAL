<?php
/**
 * AJAX Quick Add Patient
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Enhanced session validation
if (!dcmt_validate_session()) {
    echo json_encode(['success' => false, 'message' => trans('login', 'session_expired')]);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get and validate input
$first_name = isset($_POST['first_name']) ? trim(dcmt_sanitize_input($_POST['first_name'])) : '';
$fathers_last_name = isset($_POST['fathers_last_name']) ? trim(dcmt_sanitize_input($_POST['fathers_last_name'])) : '';
$mothers_last_name = isset($_POST['mothers_last_name']) ? trim(dcmt_sanitize_input($_POST['mothers_last_name'])) : '';
$legacy_last_name = isset($_POST['last_name']) ? trim(dcmt_sanitize_input($_POST['last_name'])) : '';
$phone = isset($_POST['phone']) ? trim(dcmt_sanitize_input($_POST['phone'])) : '';
$email = isset($_POST['email']) ? trim(dcmt_sanitize_input($_POST['email'])) : '';
$gender = isset($_POST['gender']) ? trim(dcmt_sanitize_input($_POST['gender'])) : 'other';
$csrf_token = $_POST['csrf_token'] ?? '';

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

// Build combined last name (father + mother). Fallback to legacy last_name if provided.
$combined_last_name = trim(trim($fathers_last_name . ' ' . $mothers_last_name));
if ($combined_last_name === '' && $legacy_last_name !== '') {
    $combined_last_name = $legacy_last_name;
}

// Build full patient name
$patient_name = trim($first_name . ' ' . $combined_last_name);

// Validate required fields
if (empty($first_name)) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'first_name') . ' is required.']);
    exit();
}

if (empty($phone)) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'phone') . ' is required.']);
    exit();
}

if (!empty($phone)) {
    $normalized_phone = preg_replace('/\s+/', '', $phone);
    if (strpos($normalized_phone, '+') !== 0) {
        $digits = preg_replace('/\D+/', '', $normalized_phone);
        if ($digits !== '') {
            $normalized_phone = '+52' . $digits;
        }
    }
    $phone = $normalized_phone;
}

// Validate email if provided
if (!empty($email) && !dcmt_validate_email($email)) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'invalid_email')]);
    exit();
}

$allowed_genders = ['male', 'female', 'other'];
if (!in_array($gender, $allowed_genders, true)) {
    $gender = 'other';
}

// Ensure patients table exists
$dcmt_db = new Dcmt_Database();
$dcmt_db->ensurePatientsTable();
$dcmt_db->addPatientColumns();

try {
    // Check if patient with same name and phone already exists
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_patient_name, dcmt_phone FROM dcmt_patients WHERE dcmt_patient_name = ? AND dcmt_phone = ?");
    $stmt->execute([$patient_name, $phone]);
    $existing = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing) {
        // Patient already exists, return existing patient info
        echo json_encode([
            'success' => true,
            'message' => trans('patient', 'patient_already_exists'),
            'patient' => [
                'id' => (int)$existing['dcmt_id'],
                'name' => htmlspecialchars($existing['dcmt_patient_name']),
                'phone' => htmlspecialchars($existing['dcmt_phone'] ?? ''),
                'email' => ''
            ],
            'already_exists' => true
        ]);
        exit();
    }
    
    // Get current user
    $current_user = dcmt_get_current_user();
    $created_by = $current_user['dcmt_username'] ?? 'system';
    
    $sql = "INSERT INTO dcmt_patients (
        dcmt_first_name, dcmt_fathers_last_name, dcmt_mothers_last_name, dcmt_patient_name, dcmt_phone, dcmt_email, dcmt_gender, dcmt_status, dcmt_created_by
    ) VALUES (?, ?, ?, ?, ?, ?, ?, 'active', ?)";
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([
        $first_name !== '' ? $first_name : $patient_name,
        $fathers_last_name !== '' ? $fathers_last_name : null,
        $mothers_last_name !== '' ? $mothers_last_name : null,
        $patient_name,
        $phone,
        !empty($email) ? $email : null,
        $gender,
        $created_by
    ]);
    
    $patient_id = $dcmt_pdo->lastInsertId();
    
    // Get the created patient
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_patient_name, dcmt_phone, dcmt_email, dcmt_status FROM dcmt_patients WHERE dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log activity
    dcmt_log_activity('Patient created (quick add)', "Name: $patient_name, ID: $patient_id");
    
    echo json_encode([
        'success' => true,
        'message' => trans('patient', 'add_success'),
        'patient' => [
            'id' => (int)$patient['dcmt_id'],
            'name' => htmlspecialchars($patient['dcmt_patient_name']),
            'phone' => htmlspecialchars($patient['dcmt_phone'] ?? ''),
            'email' => htmlspecialchars($patient['dcmt_email'] ?? ''),
            'status' => htmlspecialchars($patient['dcmt_status'] ?? 'active')
        ],
        'already_exists' => false
    ]);
    
} catch (PDOException $e) {
    error_log("Error adding patient (quick add): " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient', 'database_error') . ': ' . $e->getMessage()]);
}
?>
