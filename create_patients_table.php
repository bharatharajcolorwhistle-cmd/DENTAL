<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    $sql = "
        CREATE TABLE IF NOT EXISTS dcmt_patients (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_first_name VARCHAR(100) NULL,
            dcmt_fathers_last_name VARCHAR(100) NULL,
            dcmt_mothers_last_name VARCHAR(100) NULL,
            dcmt_patient_name VARCHAR(200) NOT NULL,
            dcmt_gender ENUM('male', 'female', 'other') DEFAULT 'other',
            dcmt_date_of_birth DATE NULL,
            dcmt_age INT NULL,
            dcmt_height_cm DECIMAL(6,2) NULL,
            dcmt_weight_kg DECIMAL(6,2) NULL,
            dcmt_email VARCHAR(150) NULL,
            dcmt_phone VARCHAR(25) NOT NULL,
            dcmt_address TEXT,
            dcmt_medications TEXT NULL,
            dcmt_allergies TEXT NULL,
            dcmt_emergency_contact_name VARCHAR(150) NULL,
            dcmt_emergency_contact_relation VARCHAR(100) NULL,
            dcmt_emergency_contact_phone VARCHAR(25) NULL,
            dcmt_notes TEXT NULL,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_patient_name (dcmt_patient_name),
            INDEX idx_patient_phone (dcmt_phone),
            INDEX idx_patient_email (dcmt_email)
        )
    ";
    $dcmt_pdo->exec($sql);
    echo "Ensured dcmt_patients table exists.\n";
} catch (PDOException $e) {
    echo "Error creating dcmt_patients table: " . $e->getMessage() . "\n";
    exit(1);
}

echo "Done.\n";
?>
