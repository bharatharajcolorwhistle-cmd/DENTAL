<?php
/**
 * AJAX Add Patient Note
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
$patient_id = isset($_POST['patient_id']) ? (int)$_POST['patient_id'] : 0;
$topic = isset($_POST['topic']) ? trim(dcmt_sanitize_input($_POST['topic'])) : '';
$note_text = isset($_POST['note_text']) ? trim($_POST['note_text']) : '';
$csrf_token = $_POST['csrf_token'] ?? '';

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

// Validate patient ID
if ($patient_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid patient ID.']);
    exit();
}

// Validate note text
if (empty($note_text)) {
    echo json_encode(['success' => false, 'message' => trans('patient', 'note_text_required')]);
    exit();
}

// Don't use htmlspecialchars for note_text - only trim, let PDO handle SQL injection
$note_text = trim($note_text);

try {
    // Verify patient exists
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id FROM dcmt_patients WHERE dcmt_id = ?");
    $stmt->execute([$patient_id]);
    if (!$stmt->fetch()) {
        echo json_encode(['success' => false, 'message' => trans('patient', 'not_found')]);
        exit();
    }
    
    // Get current user
    $current_user = dcmt_get_current_user();
    $created_by = $current_user['dcmt_username'] ?? 'system';
    
    // Insert note
    $note_date = isset($_POST['note_date']) ? dcmt_sanitize_input($_POST['note_date']) : dcmt_get_current_date();
    $stmt = $dcmt_pdo->prepare("
        INSERT INTO dcmt_patient_notes (dcmt_patient_id, dcmt_note_date, dcmt_topic, dcmt_note_text, dcmt_created_by, dcmt_created_at) 
        VALUES (?, ?, ?, ?, ?, NOW())
    ");
    $stmt->execute([$patient_id, $note_date, $topic ?: null, $note_text, $created_by]);
    
    $note_id = $dcmt_pdo->lastInsertId();
    
    // Get the created note with user info
    $stmt = $dcmt_pdo->prepare("
        SELECT pn.*, u.dcmt_full_name as created_by_name
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_users u ON pn.dcmt_created_by = u.dcmt_username
        WHERE pn.dcmt_id = ?
    ");
    $stmt->execute([$note_id]);
    $note = $stmt->fetch(PDO::FETCH_ASSOC);
    
    // Log activity
    dcmt_log_activity('Patient note added', "Patient ID: $patient_id, Note ID: $note_id");
    
    echo json_encode([
        'success' => true,
        'message' => trans('patient', 'note_added_success'),
        'note' => [
            'id' => $note['dcmt_id'],
            'topic' => htmlspecialchars($note['dcmt_topic'] ?? ''),
            'text' => htmlspecialchars($note['dcmt_note_text']),
            'created_by' => htmlspecialchars($note['created_by_name'] ?? $note['dcmt_created_by']),
            'created_at' => dcmt_format_date($note['dcmt_created_at'], DCMT_DATETIME_FORMAT)
        ]
    ]);
    
} catch (PDOException $e) {
    error_log("Error adding patient note: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient', 'database_error')]);
}
?>

