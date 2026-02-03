<?php
/**
 * Get Doctor Services via AJAX
 * Dental Clinic Management System
 * 
 * This endpoint returns the services assigned to a specific doctor
 * Used in the add income form to populate the service dropdown
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';

// Set JSON header
header('Content-Type: application/json');

// Check if user is logged in
if (!dcmt_validate_session()) {
    echo json_encode([
        'success' => false,
        'message' => trans('login', 'session_expired'),
        'services' => []
    ]);
    exit();
}

// Check if it's a GET or POST request
// Now accepts user_id (doctor role user) instead of doctor_id
$doctor_user_id = 0;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $doctor_user_id = isset($_POST['doctor_id']) ? (int)$_POST['doctor_id'] : 0;
} else {
    $doctor_user_id = isset($_GET['doctor_id']) ? (int)$_GET['doctor_id'] : 0;
}

if ($doctor_user_id <= 0) {
    echo json_encode([
        'success' => false,
        'message' => trans('user', 'invalid_user_id'),
        'services' => []
    ]);
    exit();
}

try {
    // Fetch active services assigned to this user (doctor role) with their prices
    $sql = "
        SELECT 
            s.dcmt_id as service_id,
            s.dcmt_name as service_name,
            s.dcmt_description as service_description,
            ds.dcmt_price as service_price,
            ds.dcmt_status as assignment_status
        FROM dcmt_doctor_services ds
        INNER JOIN dcmt_services s ON ds.dcmt_service_id = s.dcmt_id
        WHERE ds.dcmt_user_id = ? 
            AND ds.dcmt_status = 'active' 
            AND s.dcmt_status = 'active'
        ORDER BY s.dcmt_name ASC
    ";
    
    $stmt = $dcmt_pdo->prepare($sql);
    $stmt->execute([$doctor_user_id]);
    $services = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Format services for easier consumption
    $formatted_services = [];
    foreach ($services as $service) {
        $formatted_services[] = [
            'id' => $service['service_id'],
            'name' => $service['service_name'],
            'description' => $service['service_description'],
            'price' => floatval($service['service_price']),
            'formatted_price' => dcmt_format_currency($service['service_price'])
        ];
    }
    
    echo json_encode([
        'success' => true,
        'message' => 'Services fetched successfully',
        'services' => $formatted_services,
        'count' => count($formatted_services)
    ]);
    
} catch (PDOException $e) {
    error_log("Error fetching doctor services: " . $e->getMessage());
    echo json_encode([
        'success' => false,
        'message' => trans('service', 'database_error'),
        'services' => []
    ]);
}
?>
