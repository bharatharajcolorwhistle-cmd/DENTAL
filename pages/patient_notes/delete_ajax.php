<?php
/**
 * AJAX Delete Patient Note
 * Dental Clinic Management System
 */


require_once __DIR__ . '/../../includes/ajax_bootstrap.php';
// Set JSON header
// Enhanced session validation
if (!dcmt_can_delete_records()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}


// Permission: assistant cannot delete notes
if (($dcmt_current_user['dcmt_role'] ?? '') === 'assistant') {
    echo json_encode(['success' => false, 'message' => trans('patient_note', 'no_delete_permission')]);
    exit();
}

// Check if request is POST
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

// Get and validate input
$note_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
$csrf_token = $_POST['csrf_token'] ?? '';

// Validate CSRF token
if (!dcmt_verify_csrf_token($csrf_token)) {
    echo json_encode(['success' => false, 'message' => 'Invalid security token.']);
    exit();
}

// Validate note ID
if ($note_id <= 0) {
    echo json_encode(['success' => false, 'message' => 'Invalid note ID.']);
    exit();
}

try {
    // Get note data
    $stmt = $dcmt_pdo->prepare("SELECT * FROM dcmt_patient_notes WHERE dcmt_id = ?");
    $stmt->execute([$note_id]);
    $note = $stmt->fetch();
    
    if (!$note) {
        echo json_encode(['success' => false, 'message' => trans('patient_note', 'not_found')]);
        exit();
    }
    
    // Delete the note
    $stmt = $dcmt_pdo->prepare("DELETE FROM dcmt_patient_notes WHERE dcmt_id = ?");
    $stmt->execute([$note_id]);
    
    // Log activity
    dcmt_log_activity('Patient note deleted', "Note ID: $note_id, Patient ID: {$note['dcmt_patient_id']}");
    
    echo json_encode([
        'success' => true, 
        'message' => trans('patient_note', 'delete_success'),
        'note_id' => $note_id
    ]);
    
} catch (PDOException $e) {
    error_log("Error deleting patient note: " . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('patient_note', 'database_error')]);
}
?>

