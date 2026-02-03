<?php
/**
 * Migration Script: Create Patient Records from Income Records
 * 
 * This is a one-time migration script that:
 * 1. Ensures the dcmt_patient_id column exists in dcmt_income table
 * 2. Creates patient records from unique patient names in income records
 * 3. Links income records to the newly created patient records
 * 
 * Usage: Run from command line or via browser
 * Command line: php config/migrate_patients_from_income.php
 * Browser: http://localhost/dev_dental/config/migrate_patients_from_income.php
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/database.php';

/**
 * Create patient records from income records
 * 
 * @param PDO $pdo Database connection
 * @return array Statistics about created and linked records
 */
function createPatientsFromIncomeRecords($pdo) {
    try {
        // Get distinct patient names from income records that don't have a patient_id
        $stmt = $pdo->prepare("
            SELECT DISTINCT dcmt_patient_name, dcmt_created_by
            FROM dcmt_income
            WHERE dcmt_patient_id IS NULL
            AND dcmt_patient_name IS NOT NULL
            AND TRIM(dcmt_patient_name) != ''
        ");
        $stmt->execute();
        $income_patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        if (empty($income_patients)) {
            echo "No income records found without patient_id\n";
            return ['created' => 0, 'linked' => 0];
        }
        
        $created_count = 0;
        $linked_count = 0;
        
        foreach ($income_patients as $income_patient) {
            $patient_name = trim($income_patient['dcmt_patient_name']);
            $created_by = $income_patient['dcmt_created_by'] ?? 'system';
            
            // Skip if patient already exists (check by name)
            $check_stmt = $pdo->prepare("
                SELECT dcmt_id FROM dcmt_patients
                WHERE dcmt_patient_name = ?
            ");
            $check_stmt->execute([$patient_name]);
            $existing_patient = $check_stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($existing_patient) {
                // Patient exists, link income records to this patient
                $link_stmt = $pdo->prepare("
                    UPDATE dcmt_income
                    SET dcmt_patient_id = ?
                    WHERE dcmt_patient_id IS NULL
                    AND dcmt_patient_name = ?
                ");
                $link_stmt->execute([$existing_patient['dcmt_id'], $patient_name]);
                $linked_count += $link_stmt->rowCount();
                continue;
            }
            
            if (empty(trim($patient_name))) {
                continue; // Skip if no valid patient name
            }
            
            // Create patient record
            // Phone is required, so we'll use a placeholder that can be updated later
            $phone_placeholder = '0000000000';
            
            try {
                $insert_stmt = $pdo->prepare("
                    INSERT INTO dcmt_patients (
                        dcmt_patient_name,
                        dcmt_gender,
                        dcmt_phone,
                        dcmt_status,
                        dcmt_created_by
                    ) VALUES (?, 'other', ?, 'active', ?)
                ");
                $insert_stmt->execute([
                    $patient_name,
                    $phone_placeholder,
                    $created_by
                ]);
                
                $new_patient_id = $pdo->lastInsertId();
                $created_count++;
                
                echo "  Created patient: $patient_name (ID: $new_patient_id)\n";
                
                // Link all income records with this patient name to the new patient
                $link_stmt = $pdo->prepare("
                    UPDATE dcmt_income
                    SET dcmt_patient_id = ?
                    WHERE dcmt_patient_id IS NULL
                    AND dcmt_patient_name = ?
                ");
                $link_stmt->execute([$new_patient_id, $patient_name]);
                $linked_count += $link_stmt->rowCount();
                
            } catch (PDOException $e) {
                echo "  ERROR: Failed to create patient '$patient_name': " . $e->getMessage() . "\n";
                continue;
            }
        }
        
        return ['created' => $created_count, 'linked' => $linked_count];
        
    } catch (PDOException $e) {
        throw new Exception("Error creating patients from income records: " . $e->getMessage());
    }
}

// Main execution
echo "Starting migration: Create Patient Records from Income Records\n";
echo str_repeat("=", 60) . "\n\n";

try {
    // Initialize database connection
    $dcmt_db = new Dcmt_Database();
    $pdo = $dcmt_db->getConnection();
    
    echo "Step 1: Ensuring dcmt_patient_id column exists in income table...\n";
    $dcmt_db->addIncomePatientIdField();
    echo "✓ Income table structure verified\n\n";
    
    echo "Step 2: Creating patient records from income records...\n";
    $stats = createPatientsFromIncomeRecords($pdo);
    echo "\n✓ Patient creation and linkage completed\n";
    echo "  - Created {$stats['created']} new patient records\n";
    echo "  - Linked {$stats['linked']} income records to patients\n\n";
    
    echo str_repeat("=", 60) . "\n";
    echo "Migration completed successfully!\n";
    
} catch (Exception $e) {
    echo "\n" . str_repeat("=", 60) . "\n";
    echo "ERROR: Migration failed!\n";
    echo "Error message: " . $e->getMessage() . "\n";
    echo "Please check the error logs for more details.\n";
    exit(1);
}

