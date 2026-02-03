<?php
require_once 'config/database.php';
try {
    $stmt = $dcmt_pdo->prepare('INSERT IGNORE INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_description, dcmt_setting_type, dcmt_category, dcmt_created_by) VALUES (?, ?, ?, ?, ?, ?, ?)');
    $stmt->execute(['default_doctor_id', 'Default Doctor', '', 'Default doctor to be selected in Add Income page', 'select', 'Doctor', 'system']);
    echo 'Default doctor setting added successfully!' . PHP_EOL;
} catch (Exception $e) {
    echo 'Error: ' . $e->getMessage() . PHP_EOL;
}
?>
