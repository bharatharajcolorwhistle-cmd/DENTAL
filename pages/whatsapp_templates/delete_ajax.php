<?php
require_once __DIR__ . '/../../includes/ajax_bootstrap.php';

if (!dcmt_can_delete_records() || !dcmt_is_admin()) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => trans('common', 'staff_cannot_delete')]);
    exit();
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    echo json_encode(['success' => false, 'message' => 'Invalid request method.']);
    exit();
}

$template_id = isset($_POST['id']) ? (int)$_POST['id'] : 0;
if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '') || $template_id <= 0) {
    echo json_encode(['success' => false, 'message' => trans('whatsapp_template', 'invalid_token')]);
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare('DELETE FROM dcmt_whatsapp_templates WHERE dcmt_id = ?');
    $stmt->execute([$template_id]);
    dcmt_log_activity('WhatsApp template deleted', 'Template ID: ' . $template_id);
    echo json_encode(['success' => true, 'message' => trans('whatsapp_template', 'template_deleted_successfully')]);
} catch (PDOException $e) {
    error_log('WhatsApp template delete error: ' . $e->getMessage());
    echo json_encode(['success' => false, 'message' => trans('whatsapp_template', 'database_error')]);
}
