<?php
/**
 * Deployment diagnostic for the lab integration API. Safe to call with GET.
 * Delete this file once /api/integration/notifications works on the server.
 */

header('Content-Type: application/json; charset=utf-8');

echo json_encode([
    'success' => true,
    'message' => 'Integration API directory is reachable and PHP is executing.',
    'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? ''),
    'uri' => (string) ($_SERVER['REQUEST_URI'] ?? ''),
    'notifications_handler_exists' => is_file(__DIR__ . '/notifications.php'),
    'legacy_notifications_dir_exists' => is_dir(__DIR__ . '/notifications'),
    'server_software' => (string) ($_SERVER['SERVER_SOFTWARE'] ?? ''),
    'time' => date('c'),
], JSON_UNESCAPED_SLASHES);
