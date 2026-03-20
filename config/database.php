<?php
/**
 * Database Configuration and Connection
 * Dental Clinic Management System
 */

class Dcmt_Database
{
    private $host = 'localhost';
    private $dbname = 'u373759666_demo_dental';
    private $username = 'root';
    private $password = '';
    private $pdo;

    public function __construct()
    {
        try {
            // First connect without specifying database
            $this->pdo = new PDO(
                "mysql:host={$this->host};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

            // Create database if it doesn't exist
            $this->createDatabase();

            // Now connect to the specific database
            $this->pdo = new PDO(
                "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                $this->username,
                $this->password,
                [
                    PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                    PDO::ATTR_EMULATE_PREPARES => false
                ]
            );

            // Set timezone to Mexico (UTC-6)
            $this->pdo->exec("SET time_zone = '-06:00'");
            // Ensure consistent connection collation for string comparisons (LIKE, =, etc.)
            try {
                $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
            } catch (PDOException $e) {
                // Non-fatal; proceed with defaults if server restricts these settings
            }

        } catch (PDOException $e) {
            die("Database connection failed: " . $e->getMessage());
        }
    }

    private function createDatabase()
    {
        try {
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$this->dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            die("Failed to create database: " . $e->getMessage());
        }
    }

    public function getConnection()
    {
        return $this->pdo;
    }

    public function createTables()
    {
        $sql = "
        -- Users table
        CREATE TABLE IF NOT EXISTS dcmt_users (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_username VARCHAR(50) UNIQUE NOT NULL,
            dcmt_email VARCHAR(100) UNIQUE NOT NULL,
            dcmt_password VARCHAR(255) NOT NULL,
            dcmt_full_name VARCHAR(100) NOT NULL,
            dcmt_role ENUM('admin', 'staff', 'doctor') DEFAULT 'staff',
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_phone VARCHAR(20),
            dcmt_address TEXT,
            dcmt_notes TEXT,
            dcmt_qualification VARCHAR(255) NULL,
            dcmt_specialization_id INT NULL,
            dcmt_dashboard_summary_toggle TINYINT(1) DEFAULT 1,
            dcmt_last_login TIMESTAMP NULL,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        
        -- Doctor Specializations table
        CREATE TABLE IF NOT EXISTS dcmt_doctor_specializations (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_specialization_name (dcmt_name)
        );
        
        -- Patients table
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
        );
        
        -- Expense Categories table
        CREATE TABLE IF NOT EXISTS dcmt_expense_categories (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_expense_category_name (dcmt_name)
        );
        
        -- Inventory Categories table
        CREATE TABLE IF NOT EXISTS dcmt_inventory_categories (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_product_type ENUM('for_sale', 'for_use') DEFAULT 'for_sale',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_inventory_category_name (dcmt_name)
        );
        
        -- Inventory table
        CREATE TABLE IF NOT EXISTS dcmt_inventory (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_brand VARCHAR(100) NULL,
            dcmt_sku VARCHAR(50) UNIQUE NOT NULL,
            dcmt_description TEXT,
            dcmt_category_id INT NULL,
            dcmt_quantity INT NOT NULL DEFAULT 0,
            dcmt_min_quantity INT NOT NULL DEFAULT 10,
            dcmt_price DECIMAL(10,2) NOT NULL,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_supplier VARCHAR(100),
            dcmt_expiry_date DATE NULL,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        
        -- Income table
        CREATE TABLE IF NOT EXISTS dcmt_income (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_patient_name VARCHAR(100) NOT NULL,
            dcmt_type ENUM('consultation', 'product_sale', 'mixed') NOT NULL DEFAULT 'consultation',
            dcmt_description TEXT,
            dcmt_amount DECIMAL(10,2) NOT NULL,
            dcmt_paid_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_pending_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_consultation_paid_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_product_paid_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_total_paid_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_total_pending_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_consultation_fee DECIMAL(10,2) NULL,
            dcmt_service_id INT NULL,
            dcmt_service_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_service_paid_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_service_pending_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_product_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_product_pending_amount DECIMAL(10,2) DEFAULT 0.00,
            dcmt_payment_mode ENUM('cash', 'card', 'bank_transfer', 'online') NOT NULL,
            dcmt_payment_status ENUM('completed', 'pending', 'failed') NOT NULL,
            dcmt_payment_method_id INT NULL,
            dcmt_payment_status_id INT NULL,
            dcmt_user_id INT NULL,
            dcmt_transaction_date DATE NOT NULL,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        
        -- Income breakdown table (unified service/product line items)
        CREATE TABLE IF NOT EXISTS dcmt_income_breakdown (
            dcmt_id INT NOT NULL,
            dcmt_line_no INT NOT NULL,
            dcmt_line_type ENUM('service', 'product') NOT NULL,
            dcmt_reference_id INT NULL,
            dcmt_label VARCHAR(191) NOT NULL,
            dcmt_quantity DECIMAL(12,2) NOT NULL DEFAULT 0,
            dcmt_unit_price DECIMAL(12,2) NOT NULL DEFAULT 0,
            dcmt_line_total DECIMAL(12,2) NOT NULL DEFAULT 0,
            dcmt_user_id INT NULL,
            dcmt_inventory_id INT NULL,
            dcmt_metadata JSON NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            PRIMARY KEY (dcmt_id, dcmt_line_no),
            INDEX idx_breakdown_type (dcmt_line_type),
            INDEX idx_breakdown_inventory (dcmt_inventory_id)
        );

        -- Income payment history table
        CREATE TABLE IF NOT EXISTS dcmt_income_payment_history (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_income_id INT NOT NULL,
            dcmt_payment_type ENUM('consultation', 'product', 'general') NOT NULL DEFAULT 'general',
            dcmt_amount DECIMAL(10,2) NOT NULL,
            dcmt_paid_on DATE NOT NULL,
            dcmt_notes TEXT,
            dcmt_recorded_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        
        -- Expenses table
        CREATE TABLE IF NOT EXISTS dcmt_expenses (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_title VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_category_id INT NULL,
            dcmt_amount DECIMAL(10,2) NOT NULL,
            dcmt_payment_method VARCHAR(50) NOT NULL,
            dcmt_payment_status ENUM('paid', 'pending', 'overdue') NOT NULL,
            dcmt_expense_date DATE NOT NULL,
            dcmt_notes TEXT,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        
        -- Settings table
        CREATE TABLE IF NOT EXISTS dcmt_settings (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_setting_key VARCHAR(50) UNIQUE NOT NULL,
            dcmt_setting_name VARCHAR(100) NOT NULL,
            dcmt_setting_value TEXT NOT NULL,
            dcmt_setting_type ENUM('text', 'textarea', 'number', 'email', 'url', 'select', 'checkbox') DEFAULT 'text',
            dcmt_category VARCHAR(50) DEFAULT 'General',
            dcmt_required BOOLEAN DEFAULT FALSE,
            dcmt_setting_description TEXT,
            dcmt_max_length INT,
            dcmt_min_value DECIMAL(10,2),
            dcmt_max_value DECIMAL(10,2),
            dcmt_step_value DECIMAL(10,2),
            dcmt_setting_options TEXT,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );
        
        -- Income Payment Methods table
        CREATE TABLE IF NOT EXISTS dcmt_income_payment_methods (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_income_payment_method_name (dcmt_name)
        );
        
        -- Expense Payment Methods table
        CREATE TABLE IF NOT EXISTS dcmt_expense_payment_methods (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_expense_payment_method_name (dcmt_name)
        );
        
        -- Income Payment Status table
        CREATE TABLE IF NOT EXISTS dcmt_income_payment_status (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_income_payment_status_name (dcmt_name)
        );
        
        -- Services table
        CREATE TABLE IF NOT EXISTS dcmt_services (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_base_price DECIMAL(10,2) NOT NULL DEFAULT 0.00,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_service_name (dcmt_name),
            INDEX idx_service_status (dcmt_status)
        );
        
        -- Doctor Services mapping table (uses user_id for doctor role users)
        CREATE TABLE IF NOT EXISTS dcmt_doctor_services (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_user_id INT NOT NULL,
            dcmt_service_id INT NOT NULL,
            dcmt_price DECIMAL(10,2) NOT NULL,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_doctor_service (dcmt_user_id, dcmt_service_id),
            INDEX idx_user_id (dcmt_user_id),
            INDEX idx_service_id (dcmt_service_id),
            INDEX idx_status (dcmt_status)
        );
        
        -- Doctor Goals table
        CREATE TABLE IF NOT EXISTS dcmt_doctor_goals (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_user_id INT NOT NULL,
            dcmt_goal_month DATE NOT NULL,
            dcmt_goal_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            dcmt_currency VARCHAR(10) DEFAULT NULL,
            dcmt_notes TEXT NULL,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_updated_by VARCHAR(50) DEFAULT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_doctor_goal (dcmt_user_id, dcmt_goal_month),
            INDEX idx_goal_month (dcmt_goal_month),
            INDEX idx_goal_user (dcmt_user_id)
        );

        -- Doctor duty hours table
        CREATE TABLE IF NOT EXISTS dcmt_doctor_duty_hours (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_doctor_id INT NOT NULL,
            dcmt_weekday TINYINT NOT NULL,
            dcmt_start_time TIME NOT NULL,
            dcmt_end_time TIME NOT NULL,
            dcmt_is_active TINYINT(1) NOT NULL DEFAULT 1,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_duty_doctor_weekday (dcmt_doctor_id, dcmt_weekday),
            INDEX idx_duty_active (dcmt_is_active)
        );

        -- Appointments table
        CREATE TABLE IF NOT EXISTS dcmt_appointments (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_patient_id INT NOT NULL,
            dcmt_doctor_id INT NOT NULL,
            dcmt_start_at DATETIME NOT NULL,
            dcmt_end_at DATETIME NOT NULL,
            dcmt_status ENUM('scheduled', 'confirmed', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'scheduled',
            dcmt_reason VARCHAR(255) NULL,
            dcmt_notes TEXT NULL,
            dcmt_created_by INT NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            INDEX idx_appointments_doctor_start (dcmt_doctor_id, dcmt_start_at),
            INDEX idx_appointments_range (dcmt_start_at, dcmt_end_at),
            INDEX idx_appointments_patient_start (dcmt_patient_id, dcmt_start_at),
            INDEX idx_appointments_status (dcmt_status)
        );
        
        -- Daily Cashflow table
        -- Stores all Cash Reconciliation Report details:
        -- Opening Balance = dcmt_starting_amount
        -- Cash Inflow = dcmt_cash_income_total
        -- Cash Outflow = dcmt_cash_expense_total
        -- Net Cashflow = dcmt_net_cashflow (income - expenses)
        -- Closing Balance = dcmt_ending_amount
        CREATE TABLE IF NOT EXISTS dcmt_cashflows (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_record_date DATE NOT NULL UNIQUE,
            dcmt_starting_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Opening Balance
            dcmt_cash_income_total DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Cash Inflow
            dcmt_cash_expense_total DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Cash Outflow
            dcmt_net_cashflow DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Net Cashflow (income - expenses)
            dcmt_ending_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00, -- Closing Balance
            dcmt_difference DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            dcmt_notes TEXT,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_updated_by VARCHAR(50) DEFAULT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        );

        -- Cashflow denomination breakdown table
        CREATE TABLE IF NOT EXISTS dcmt_cashflow_denominations (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_cashflow_id INT NOT NULL,
            dcmt_denomination_type ENUM('start', 'end') NOT NULL DEFAULT 'end',
            dcmt_denomination_label VARCHAR(50) NOT NULL,
            dcmt_denomination_value DECIMAL(10,2) NOT NULL,
            dcmt_quantity INT NOT NULL DEFAULT 0,
            dcmt_total_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            INDEX idx_cashflow_denominations_cashflow (dcmt_cashflow_id),
            INDEX idx_cashflow_denominations_type (dcmt_denomination_type)
        );
        
        -- Activity log table
        CREATE TABLE IF NOT EXISTS dcmt_activity_log (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_user VARCHAR(50) NOT NULL,
            dcmt_activity VARCHAR(100) NOT NULL,
            dcmt_details TEXT,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
        );
        ";

        try {
            $this->pdo->exec($sql);

            $this->createForeignKeys();
            $this->addConsultationFeeField();
            $this->addPaymentMethodFields();
            $this->addPaymentAmountFields();
            $this->addSeparatePaymentFields();
            $this->addServiceIdField();
            $this->addServiceAmountFields();
            $this->addProductAmountFields();
            $this->addProductTypeField();
            $this->addIncomePaymentHistoryTable();
            $this->addCashflowExpenseFields();
            $this->addPatientNotesTable();
            $this->addDoctorCashFields();
            $this->addAppointmentTables();
            return true;
        } catch (PDOException $e) {
            error_log("Table creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function addDoctorCashFields()
    {
        try {
            // Add owner withdraw fields to cashflows table (replacement for legacy doctor_cash_total)
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_cashflows LIKE 'dcmt_owner_withdraw_name'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_cashflows ADD COLUMN dcmt_owner_withdraw_name VARCHAR(100) NULL AFTER dcmt_cash_expense_total");
                error_log("Added dcmt_owner_withdraw_name field to dcmt_cashflows table");
            }

            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_cashflows LIKE 'dcmt_owner_withdraw_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_cashflows ADD COLUMN dcmt_owner_withdraw_amount DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dcmt_owner_withdraw_name");
                error_log("Added dcmt_owner_withdraw_amount field to dcmt_cashflows table");
            }

            // Migrate any legacy dcmt_doctor_cash_total values into dcmt_owner_withdraw_amount and then drop the column
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_cashflows LIKE 'dcmt_doctor_cash_total'");
            if ($stmt->rowCount() > 0) {
                $this->pdo->exec("
                    UPDATE dcmt_cashflows
                    SET dcmt_owner_withdraw_amount = dcmt_doctor_cash_total
                    WHERE dcmt_owner_withdraw_amount = 0.00
                ");
                $this->pdo->exec("ALTER TABLE dcmt_cashflows DROP COLUMN dcmt_doctor_cash_total");
                error_log("Migrated dcmt_doctor_cash_total values and dropped dcmt_doctor_cash_total column");
            }

            // Drop legacy dcmt_doctor_cash table if it still exists
            $this->pdo->exec("DROP TABLE IF EXISTS dcmt_doctor_cash");
            error_log("Dropped legacy dcmt_doctor_cash table if it existed");
        } catch (PDOException $e) {
            error_log("Error updating owner withdraw fields: " . $e->getMessage());
        }
    }

    public function addAppointmentTables()
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS dcmt_doctor_duty_hours (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_doctor_id INT NOT NULL,
                    dcmt_weekday TINYINT NOT NULL,
                    dcmt_start_time TIME NOT NULL,
                    dcmt_end_time TIME NOT NULL,
                    dcmt_is_active TINYINT(1) NOT NULL DEFAULT 1,
                    dcmt_created_by VARCHAR(50) NOT NULL,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_duty_doctor_weekday (dcmt_doctor_id, dcmt_weekday),
                    INDEX idx_duty_active (dcmt_is_active)
                )
            ");

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS dcmt_appointments (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_patient_id INT NOT NULL,
                    dcmt_doctor_id INT NOT NULL,
                    dcmt_start_at DATETIME NOT NULL,
                    dcmt_end_at DATETIME NOT NULL,
                    dcmt_status ENUM('scheduled', 'confirmed', 'completed', 'cancelled', 'no_show') NOT NULL DEFAULT 'scheduled',
                    dcmt_reason VARCHAR(255) NULL,
                    dcmt_notes TEXT NULL,
                    dcmt_created_by INT NOT NULL,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_appointments_doctor_start (dcmt_doctor_id, dcmt_start_at),
                    INDEX idx_appointments_range (dcmt_start_at, dcmt_end_at),
                    INDEX idx_appointments_patient_start (dcmt_patient_id, dcmt_start_at),
                    INDEX idx_appointments_status (dcmt_status)
                )
            ");

            // Seed weekday templates for doctors with no duty rows
            $doctorStmt = $this->pdo->query("SELECT dcmt_id FROM dcmt_users WHERE dcmt_role = 'doctor'");
            $doctorIds = $doctorStmt->fetchAll(PDO::FETCH_COLUMN);
            foreach ($doctorIds as $doctorId) {
                $checkStmt = $this->pdo->prepare("SELECT COUNT(*) FROM dcmt_doctor_duty_hours WHERE dcmt_doctor_id = ?");
                $checkStmt->execute([(int)$doctorId]);
                if ((int)$checkStmt->fetchColumn() > 0) {
                    continue;
                }

                $insertStmt = $this->pdo->prepare("
                    INSERT INTO dcmt_doctor_duty_hours
                    (dcmt_doctor_id, dcmt_weekday, dcmt_start_time, dcmt_end_time, dcmt_is_active, dcmt_created_by)
                    VALUES (?, ?, '09:00:00', '17:00:00', 1, 'system')
                ");
                for ($weekday = 1; $weekday <= 5; $weekday++) {
                    $insertStmt->execute([(int)$doctorId, $weekday]);
                }
            }
        } catch (PDOException $e) {
            error_log("Error ensuring appointment tables: " . $e->getMessage());
        }
    }

    public function createForeignKeys()
    {
        $foreignKeys = [
            "ALTER TABLE dcmt_inventory ADD CONSTRAINT fk_inventory_category FOREIGN KEY (dcmt_category_id) REFERENCES dcmt_inventory_categories(dcmt_id) ON DELETE SET NULL",
            // Doctor module has been removed; all doctor references now use dcmt_users entries
            "ALTER TABLE dcmt_users ADD CONSTRAINT fk_users_specialization FOREIGN KEY (dcmt_specialization_id) REFERENCES dcmt_doctor_specializations(dcmt_id) ON DELETE SET NULL",
            "ALTER TABLE dcmt_income_breakdown ADD CONSTRAINT fk_income_breakdown_income FOREIGN KEY (dcmt_id) REFERENCES dcmt_income(dcmt_id) ON DELETE CASCADE",
            "ALTER TABLE dcmt_income_breakdown ADD CONSTRAINT fk_income_breakdown_inventory FOREIGN KEY (dcmt_inventory_id) REFERENCES dcmt_inventory(dcmt_id) ON DELETE SET NULL",
            // Removed foreign key constraint for dcmt_income_breakdown.dcmt_doctor_id
            "ALTER TABLE dcmt_expenses ADD CONSTRAINT fk_expenses_category FOREIGN KEY (dcmt_category_id) REFERENCES dcmt_expense_categories(dcmt_id) ON DELETE SET NULL",
            "ALTER TABLE dcmt_income ADD CONSTRAINT fk_income_payment_method FOREIGN KEY (dcmt_payment_method_id) REFERENCES dcmt_income_payment_methods(dcmt_id) ON DELETE SET NULL",
            "ALTER TABLE dcmt_expenses ADD CONSTRAINT fk_expenses_payment_method FOREIGN KEY (dcmt_payment_method_id) REFERENCES dcmt_expense_payment_methods(dcmt_id) ON DELETE SET NULL",
            "ALTER TABLE dcmt_income ADD CONSTRAINT fk_income_payment_status FOREIGN KEY (dcmt_payment_status_id) REFERENCES dcmt_income_payment_status(dcmt_id) ON DELETE SET NULL",
            "ALTER TABLE dcmt_income_payment_history ADD CONSTRAINT fk_income_payment_history_income FOREIGN KEY (dcmt_income_id) REFERENCES dcmt_income(dcmt_id) ON DELETE CASCADE",
            // Removed foreign key constraint for dcmt_doctor_services.dcmt_doctor_id as Doctor module has been removed
            // dcmt_doctor_services table still exists and is used by the system, but foreign key constraint removed
            "ALTER TABLE dcmt_doctor_services ADD CONSTRAINT fk_doctor_services_service FOREIGN KEY (dcmt_service_id) REFERENCES dcmt_services(dcmt_id) ON DELETE CASCADE",
            "ALTER TABLE dcmt_income ADD CONSTRAINT fk_income_service FOREIGN KEY (dcmt_service_id) REFERENCES dcmt_services(dcmt_id) ON DELETE SET NULL",
            "ALTER TABLE dcmt_doctor_goals ADD CONSTRAINT fk_doctor_goals_user FOREIGN KEY (dcmt_user_id) REFERENCES dcmt_users(dcmt_id) ON DELETE CASCADE",
            "ALTER TABLE dcmt_cashflow_denominations ADD CONSTRAINT fk_cashflow_denominations_cashflow FOREIGN KEY (dcmt_cashflow_id) REFERENCES dcmt_cashflows(dcmt_id) ON DELETE CASCADE",
            "ALTER TABLE dcmt_doctor_duty_hours ADD CONSTRAINT fk_duty_hours_doctor FOREIGN KEY (dcmt_doctor_id) REFERENCES dcmt_users(dcmt_id) ON DELETE CASCADE",
            "ALTER TABLE dcmt_appointments ADD CONSTRAINT fk_appointments_patient FOREIGN KEY (dcmt_patient_id) REFERENCES dcmt_patients(dcmt_id) ON DELETE RESTRICT",
            "ALTER TABLE dcmt_appointments ADD CONSTRAINT fk_appointments_doctor FOREIGN KEY (dcmt_doctor_id) REFERENCES dcmt_users(dcmt_id) ON DELETE RESTRICT",
            "ALTER TABLE dcmt_appointments ADD CONSTRAINT fk_appointments_created_by FOREIGN KEY (dcmt_created_by) REFERENCES dcmt_users(dcmt_id) ON DELETE RESTRICT"
        ];

        foreach ($foreignKeys as $fk) {
            try {
                $this->pdo->exec($fk);
            } catch (PDOException $e) {
                // Foreign key might already exist, continue
                error_log("Foreign key creation warning: " . $e->getMessage());
            }
        }
    }

    public function addConsultationFeeField()
    {
        try {
            // Check if the field already exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_consultation_fee'");
            if ($stmt->rowCount() == 0) {
                // Add the consultation fee field
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_consultation_fee DECIMAL(10,2) NULL AFTER dcmt_amount");
                error_log("Added dcmt_consultation_fee field to dcmt_income table");
            }
        } catch (PDOException $e) {
            error_log("Failed to add consultation fee field: " . $e->getMessage());
        }
    }

    public function addPaymentMethodFields()
    {
        try {
            // Add payment_method_id field to income table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_payment_method_id'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_payment_method_id INT NULL AFTER dcmt_payment_mode");
                error_log("Added dcmt_payment_method_id field to dcmt_income table");
            }

            // Add payment_status_id field to income table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_payment_status_id'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_payment_status_id INT NULL AFTER dcmt_payment_status");
                error_log("Added dcmt_payment_status_id field to dcmt_income table");
            }

            // Add payment_method_id field to expenses table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_expenses LIKE 'dcmt_payment_method_id'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_expenses ADD COLUMN dcmt_payment_method_id INT NULL AFTER dcmt_payment_method");
                error_log("Added dcmt_payment_method_id field to dcmt_expenses table");
            }
        } catch (PDOException $e) {
            error_log("Error adding payment method fields: " . $e->getMessage());
        }
    }

    public function addPaymentAmountFields()
    {
        try {
            // Add paid_amount field to income table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_paid_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_paid_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_amount");
                error_log("Added dcmt_paid_amount field to dcmt_income table");
            }

            // Add pending_amount field to income table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_pending_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_pending_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_paid_amount");
                error_log("Added dcmt_pending_amount field to dcmt_income table");
            }

            // Update existing records to set paid_amount = amount and pending_amount = 0
            // This assumes all existing records were fully paid
            $update_sql = "UPDATE dcmt_income SET dcmt_paid_amount = dcmt_amount, dcmt_pending_amount = 0.00 WHERE dcmt_paid_amount IS NULL OR dcmt_pending_amount IS NULL";
            $affected_rows = $this->pdo->exec($update_sql);
            if ($affected_rows > 0) {
                error_log("Updated $affected_rows existing income records with default payment values");
            }
        } catch (PDOException $e) {
            error_log("Error adding payment amount fields: " . $e->getMessage());
        }
    }

    public function addSeparatePaymentFields()
    {
        try {
            // Add consultation_paid_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_consultation_paid_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_consultation_paid_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_pending_amount");
                error_log("Added dcmt_consultation_paid_amount field to dcmt_income table");
            }

            // Add product_paid_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_product_paid_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_product_paid_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_consultation_paid_amount");
                error_log("Added dcmt_product_paid_amount field to dcmt_income table");
            }

            // Add total_paid_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_total_paid_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_total_paid_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_product_paid_amount");
                error_log("Added dcmt_total_paid_amount field to dcmt_income table");
            }

            // Add total_pending_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_total_pending_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_total_pending_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_total_paid_amount");
                error_log("Added dcmt_total_pending_amount field to dcmt_income table");
            }

            // Update existing records to populate new fields based on existing data
            $update_sql = "UPDATE dcmt_income SET 
                dcmt_consultation_paid_amount = CASE 
                    WHEN dcmt_type = 'consultation' THEN dcmt_paid_amount 
                    ELSE 0.00 
                END,
                dcmt_product_paid_amount = CASE 
                    WHEN dcmt_type = 'product_sale' THEN dcmt_paid_amount 
                    ELSE 0.00 
                END,
                dcmt_total_paid_amount = dcmt_paid_amount,
                dcmt_total_pending_amount = dcmt_pending_amount
                WHERE dcmt_consultation_paid_amount IS NULL OR dcmt_product_paid_amount IS NULL 
                OR dcmt_total_paid_amount IS NULL OR dcmt_total_pending_amount IS NULL";

            $affected_rows = $this->pdo->exec($update_sql);
            if ($affected_rows > 0) {
                error_log("Updated $affected_rows existing income records with separate payment values");
            }

        } catch (PDOException $e) {
            error_log("Error adding separate payment fields: " . $e->getMessage());
        }
    }

    public function addServiceIdField()
    {
        try {
            // Check if the field already exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_service_id'");
            if ($stmt->rowCount() == 0) {
                // Add the service_id field
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_service_id INT NULL AFTER dcmt_consultation_fee");
                error_log("Added dcmt_service_id field to dcmt_income table");
            }
        } catch (PDOException $e) {
            error_log("Failed to add service_id field: " . $e->getMessage());
        }
    }

    public function addServiceAmountFields()
    {
        try {
            // Add service_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_service_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_service_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_service_id");
                error_log("Added dcmt_service_amount field to dcmt_income table");
            }

            // Add service_paid_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_service_paid_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_service_paid_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_service_amount");
                error_log("Added dcmt_service_paid_amount field to dcmt_income table");
            }

            // Add service_pending_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_service_pending_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_service_pending_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_service_paid_amount");
                error_log("Added dcmt_service_pending_amount field to dcmt_income table");
            }

            // Update existing records to populate new fields based on existing data
            $update_sql = "UPDATE dcmt_income SET 
                dcmt_service_amount = CASE 
                    WHEN dcmt_service_id IS NOT NULL THEN 0.00 
                    ELSE 0.00 
                END,
                dcmt_service_paid_amount = 0.00,
                dcmt_service_pending_amount = 0.00
                WHERE dcmt_service_amount IS NULL OR dcmt_service_paid_amount IS NULL OR dcmt_service_pending_amount IS NULL";

            $affected_rows = $this->pdo->exec($update_sql);
            if ($affected_rows > 0) {
                error_log("Updated $affected_rows existing income records with service amount values");
            }

        } catch (PDOException $e) {
            error_log("Error adding service amount fields: " . $e->getMessage());
        }
    }

    public function addProductAmountFields()
    {
        try {
            // Add product_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_product_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_product_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_service_pending_amount");
                error_log("Added dcmt_product_amount field to dcmt_income table");
            }

            // Add product_pending_amount field
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_product_pending_amount'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_product_pending_amount DECIMAL(10,2) DEFAULT 0.00 AFTER dcmt_product_paid_amount");
                error_log("Added dcmt_product_pending_amount field to dcmt_income table");
            }

            // Update existing records to populate new fields based on existing data
            $update_sql = "UPDATE dcmt_income SET 
                dcmt_product_amount = CASE 
                    WHEN dcmt_type = 'product_sale' THEN dcmt_amount 
                    ELSE 0.00 
                END,
                dcmt_product_pending_amount = CASE 
                    WHEN dcmt_type = 'product_sale' THEN dcmt_pending_amount 
                    ELSE 0.00 
                END
                WHERE dcmt_product_amount IS NULL OR dcmt_product_pending_amount IS NULL";

            $affected_rows = $this->pdo->exec($update_sql);
            if ($affected_rows > 0) {
                error_log("Updated $affected_rows existing income records with product amount values");
            }

        } catch (PDOException $e) {
            error_log("Error adding product amount fields: " . $e->getMessage());
        }
    }

    public function addProductTypeField()
    {
        try {
            // Check if the field already exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_inventory_categories LIKE 'dcmt_product_type'");
            if ($stmt->rowCount() == 0) {
                // Add the product_type field
                $this->pdo->exec("ALTER TABLE dcmt_inventory_categories ADD COLUMN dcmt_product_type ENUM('for_sale', 'for_use') DEFAULT 'for_sale' AFTER dcmt_status");
                error_log("Added dcmt_product_type field to dcmt_inventory_categories table");

                // Update existing categories to 'for_sale' if dcmt_product_type is NULL
                $this->pdo->exec("UPDATE dcmt_inventory_categories SET dcmt_product_type = 'for_sale' WHERE dcmt_product_type IS NULL");
                error_log("Updated existing inventory categories to 'for_sale' type");
            }
        } catch (PDOException $e) {
            error_log("Failed to add product type field: " . $e->getMessage());
        }
    }

    public function addInventoryBrandField()
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_inventory LIKE 'dcmt_brand'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_inventory ADD COLUMN dcmt_brand VARCHAR(100) NULL AFTER dcmt_name");
                error_log("Added dcmt_brand field to dcmt_inventory table");
            }
        } catch (PDOException $e) {
            error_log("Failed to add inventory brand field: " . $e->getMessage());
        }
    }

    public function addCashflowExpenseFields()
    {
        try {
            // Add cash_expense_total field to cashflows table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_cashflows LIKE 'dcmt_cash_expense_total'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_cashflows ADD COLUMN dcmt_cash_expense_total DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dcmt_cash_income_total");
                error_log("Added dcmt_cash_expense_total field to dcmt_cashflows table");
            }

            // Add net_cashflow field to cashflows table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_cashflows LIKE 'dcmt_net_cashflow'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_cashflows ADD COLUMN dcmt_net_cashflow DECIMAL(12,2) NOT NULL DEFAULT 0.00 AFTER dcmt_cash_expense_total");
                error_log("Added dcmt_net_cashflow field to dcmt_cashflows table");
            }

            // Update existing records to calculate net cashflow
            // This will be calculated when records are viewed/edited, but we can set initial values
            $update_sql = "UPDATE dcmt_cashflows SET 
                dcmt_net_cashflow = dcmt_cash_income_total - dcmt_cash_expense_total 
                WHERE dcmt_net_cashflow = 0.00 AND dcmt_cash_expense_total = 0.00";
            $affected_rows = $this->pdo->exec($update_sql);
            if ($affected_rows > 0) {
                error_log("Updated $affected_rows existing cashflow records with net cashflow values");
            }
        } catch (PDOException $e) {
            error_log("Error adding cashflow expense fields: " . $e->getMessage());
        }
    }

    public function addCashflowDenominationTypeField()
    {
        try {
            // Add denomination_type field to cashflow_denominations table
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_cashflow_denominations LIKE 'dcmt_denomination_type'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_cashflow_denominations ADD COLUMN dcmt_denomination_type ENUM('start', 'end') NOT NULL DEFAULT 'end' AFTER dcmt_cashflow_id");
                $this->pdo->exec("ALTER TABLE dcmt_cashflow_denominations ADD INDEX idx_cashflow_denominations_type (dcmt_denomination_type)");
                error_log("Added dcmt_denomination_type field to dcmt_cashflow_denominations table");
            }
        } catch (PDOException $e) {
            error_log("Error adding cashflow denomination type field: " . $e->getMessage());
        }
    }

    public function addPatientNotesTable()
    {
        try {
            $tableCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_patient_notes'");
            if ($tableCheck->rowCount() == 0) {
                $this->pdo->exec("
                    CREATE TABLE dcmt_patient_notes (
                        dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                        dcmt_patient_id INT NOT NULL,
                        dcmt_note_date DATE NOT NULL,
                        dcmt_topic VARCHAR(255) NULL,
                        dcmt_note_text TEXT NOT NULL,
                        dcmt_created_by VARCHAR(50) NOT NULL,
                        dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_patient_notes_patient (dcmt_patient_id),
                        INDEX idx_patient_notes_date (dcmt_note_date),
                        INDEX idx_patient_notes_created_at (dcmt_created_at)
                    );
                ");
                error_log("Created dcmt_patient_notes table");
            } else {
                // Add note_date column if it doesn't exist
                $columnCheck = $this->pdo->query("SHOW COLUMNS FROM dcmt_patient_notes LIKE 'dcmt_note_date'");
                if ($columnCheck->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE dcmt_patient_notes ADD COLUMN dcmt_note_date DATE NOT NULL DEFAULT (CURRENT_DATE) AFTER dcmt_patient_id");
                    $this->pdo->exec("CREATE INDEX idx_patient_notes_date ON dcmt_patient_notes (dcmt_note_date)");
                    error_log("Added dcmt_note_date column to dcmt_patient_notes table");
                }

                // Add topic column if it doesn't exist
                $topicCheck = $this->pdo->query("SHOW COLUMNS FROM dcmt_patient_notes LIKE 'dcmt_topic'");
                if ($topicCheck->rowCount() == 0) {
                    $this->pdo->exec("ALTER TABLE dcmt_patient_notes ADD COLUMN dcmt_topic VARCHAR(255) NULL AFTER dcmt_note_date");
                    error_log("Added dcmt_topic column to dcmt_patient_notes table");
                }
            }
        } catch (PDOException $e) {
            error_log("Error adding patient notes table: " . $e->getMessage());
        }
    }

    public function addPatientColumns()
    {
        try {
            $columns = [
                'dcmt_first_name' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_first_name VARCHAR(100) NULL AFTER dcmt_id",
                'dcmt_fathers_last_name' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_fathers_last_name VARCHAR(100) NULL AFTER dcmt_first_name",
                'dcmt_mothers_last_name' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_mothers_last_name VARCHAR(100) NULL AFTER dcmt_fathers_last_name",
                'dcmt_date_of_birth' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_date_of_birth DATE NULL AFTER dcmt_gender",
                'dcmt_age' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_age INT NULL AFTER dcmt_date_of_birth",
                'dcmt_height_cm' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_height_cm DECIMAL(6,2) NULL AFTER dcmt_age",
                'dcmt_weight_kg' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_weight_kg DECIMAL(6,2) NULL AFTER dcmt_height_cm",
                'dcmt_emergency_contact_name' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_emergency_contact_name VARCHAR(150) NULL AFTER dcmt_allergies",
                'dcmt_emergency_contact_relation' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_emergency_contact_relation VARCHAR(100) NULL AFTER dcmt_emergency_contact_name",
                'dcmt_emergency_contact_phone' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_emergency_contact_phone VARCHAR(25) NULL AFTER dcmt_emergency_contact_relation"
            ];

            foreach ($columns as $col => $sql) {
                try {
                    // SHOW ... LIKE does not accept parameter markers; build a safe literal
                    $colLiteral = $this->pdo->quote($col);
                    $check = $this->pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE {$colLiteral}");
                    if (!$check || !$check->fetch()) {
                        $this->pdo->exec($sql);
                        error_log("Added column $col to dcmt_patients table");
                    }
                } catch (PDOException $e) {
                    error_log("Error adding patient column $col: " . $e->getMessage());
                }
            }
            // Backfill father/mother last names from legacy combined last name if present and new columns exist
            try {
                $hasFather = $this->pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_fathers_last_name'")->rowCount() > 0;
                $hasMother = $this->pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_mothers_last_name'")->rowCount() > 0;
                if ($hasFather && $hasMother) {
                    $this->pdo->exec("
                        UPDATE dcmt_patients
                        SET 
                            dcmt_fathers_last_name = TRIM(SUBSTRING_INDEX(COALESCE(dcmt_last_name, ''), ' ', 1)),
                            dcmt_mothers_last_name = NULLIF(TRIM(SUBSTRING(COALESCE(dcmt_last_name, ''), LENGTH(SUBSTRING_INDEX(COALESCE(dcmt_last_name, ''), ' ', 1)) + 2)), '')
                        WHERE (dcmt_fathers_last_name IS NULL OR dcmt_fathers_last_name = '')
                          AND (dcmt_mothers_last_name IS NULL)
                          AND dcmt_last_name IS NOT NULL
                    ");
                    // Drop legacy combined last name column if it exists
                    $checkLast = $this->pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_last_name'");
                    if ($checkLast && $checkLast->rowCount() > 0) {
                        $this->pdo->exec("ALTER TABLE dcmt_patients DROP COLUMN dcmt_last_name");
                        error_log("Dropped legacy dcmt_last_name column from dcmt_patients");
                    }
                }
            } catch (PDOException $e) {
                error_log("Backfill father/mother last names warning: " . $e->getMessage());
            }
        } catch (PDOException $e) {
            error_log("Error in addPatientColumns: " . $e->getMessage());
        }
    }

    public function addIncomeNoteField()
    {
        try {
            $check = $this->pdo->prepare("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_note'");
            $check->execute();
            if (!$check->fetch()) {
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_note TEXT NULL AFTER dcmt_description");
                error_log("Added dcmt_note column to dcmt_income table");
            }
        } catch (PDOException $e) {
            error_log("Error adding income note column: " . $e->getMessage());
        }
    }

    public function addIncomePatientIdField()
    {
        try {
            $check = $this->pdo->prepare("SHOW COLUMNS FROM dcmt_income LIKE 'dcmt_patient_id'");
            $check->execute();
            if (!$check->fetch()) {
                // Add patient_id column after patient_name
                $this->pdo->exec("ALTER TABLE dcmt_income ADD COLUMN dcmt_patient_id INT NULL AFTER dcmt_patient_name");
                // Add index for faster lookups
                $this->pdo->exec("CREATE INDEX idx_income_patient_id ON dcmt_income (dcmt_patient_id)");
                error_log("Added dcmt_patient_id column to dcmt_income table");

                // Try to link existing records by matching patient names
                try {
                    $link_stmt = $this->pdo->prepare("
                        UPDATE dcmt_income i
                        INNER JOIN dcmt_patients p ON i.dcmt_patient_name = p.dcmt_patient_name
                        SET i.dcmt_patient_id = p.dcmt_id
                        WHERE i.dcmt_patient_id IS NULL
                    ");
                    $link_stmt->execute();
                    $linked_count = $link_stmt->rowCount();
                    if ($linked_count > 0) {
                        error_log("Linked $linked_count existing income records to patients by name");
                    }
                } catch (PDOException $e) {
                    error_log("Error linking existing income records to patients: " . $e->getMessage());
                }
            }
        } catch (PDOException $e) {
            error_log("Error adding income patient_id column: " . $e->getMessage());
        }
    }

    public function ensurePatientsTable()
    {
        try {
            // Check if patients table exists
            $tableCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_patients'");
            if ($tableCheck->rowCount() == 0) {
                $this->pdo->exec("
                    CREATE TABLE dcmt_patients (
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
                ");
                error_log("Created dcmt_patients table with patient_name field");
            } else {
                // Table exists, check if it has patient_name column
                $columnCheck = $this->pdo->prepare("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_patient_name'");
                $columnCheck->execute();
                $patient_name_exists = $columnCheck->fetch();

                // Check if old first_name column exists
                $first_name_check = $this->pdo->prepare("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_first_name'");
                $first_name_check->execute();
                $first_name_exists = $first_name_check->fetch();

                // If patient_name doesn't exist but first_name does, migrate
                if (!$patient_name_exists && $first_name_exists) {
                    // Add patient_name column
                    $this->pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_patient_name VARCHAR(200) NOT NULL AFTER dcmt_id");

                    // Migrate existing data: combine first_name and last_name into patient_name
                    $this->pdo->exec("
                        UPDATE dcmt_patients 
                        SET dcmt_patient_name = TRIM(CONCAT(COALESCE(dcmt_first_name, ''), ' ', COALESCE(dcmt_last_name, '')))
                    ");

                    // Remove old index if it exists
                    try {
                        $this->pdo->exec("ALTER TABLE dcmt_patients DROP INDEX idx_patient_name");
                    } catch (PDOException $e) {
                        // Index might not exist, ignore
                    }

                    // Add new index on patient_name
                    $this->pdo->exec("CREATE INDEX idx_patient_name ON dcmt_patients (dcmt_patient_name)");

                    // Keep dcmt_first_name for current schema; drop legacy combined dcmt_last_name if present
                    try {
                        $this->pdo->exec("ALTER TABLE dcmt_patients DROP COLUMN dcmt_last_name");
                    } catch (PDOException $e) {
                        // Column might not exist; ignore
                    }

                    error_log("Migrated patient name fields: combined first_name and last_name into patient_name");
                } elseif (!$patient_name_exists) {
                    // Table exists but patient_name column doesn't exist and first_name doesn't exist either
                    // This shouldn't happen, but add patient_name column just in case
                    $this->pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_patient_name VARCHAR(200) NOT NULL AFTER dcmt_id");
                    $this->pdo->exec("UPDATE dcmt_patients SET dcmt_patient_name = 'Unknown' WHERE dcmt_patient_name IS NULL OR dcmt_patient_name = ''");
                    $this->pdo->exec("CREATE INDEX idx_patient_name ON dcmt_patients (dcmt_patient_name)");
                    error_log("Added dcmt_patient_name column to existing dcmt_patients table");
                }
            }
        } catch (PDOException $e) {
            error_log("Error ensuring patients table: " . $e->getMessage());
        }
    }

    public function addIncomePaymentHistoryTable()
    {
        try {
            $tableCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_income_payment_history'");
            if ($tableCheck->rowCount() == 0) {
                $this->pdo->exec("
                    CREATE TABLE dcmt_income_payment_history (
                        dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                        dcmt_income_id INT NOT NULL,
                        dcmt_payment_type ENUM('consultation', 'product', 'general') NOT NULL DEFAULT 'general',
                        dcmt_amount DECIMAL(10,2) NOT NULL,
                        dcmt_paid_on DATE NOT NULL,
                        dcmt_notes TEXT,
                        dcmt_recorded_by VARCHAR(50) NOT NULL,
                        dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
                    );
                ");
                $this->pdo->exec("CREATE INDEX idx_income_payment_history_income ON dcmt_income_payment_history (dcmt_income_id)");
                $this->pdo->exec("CREATE INDEX idx_income_payment_history_type ON dcmt_income_payment_history (dcmt_payment_type)");
                $this->pdo->exec("CREATE INDEX idx_income_payment_history_paid_on ON dcmt_income_payment_history (dcmt_paid_on)");
                error_log("Created dcmt_income_payment_history table");
            } else {
                // Ensure the legacy payment method column is removed
                try {
                    $fkCheck = $this->pdo->query("SELECT CONSTRAINT_NAME FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'dcmt_income_payment_history' AND CONSTRAINT_NAME = 'fk_income_payment_history_method'");
                    if ($fkCheck && $fkCheck->rowCount() > 0) {
                        $this->pdo->exec("ALTER TABLE dcmt_income_payment_history DROP FOREIGN KEY fk_income_payment_history_method");
                        error_log("Dropped fk_income_payment_history_method foreign key");
                    }
                } catch (PDOException $e) {
                    error_log("Attempted to drop legacy fk_income_payment_history_method: " . $e->getMessage());
                }

                $methodColumn = $this->pdo->query("SHOW COLUMNS FROM dcmt_income_payment_history LIKE 'dcmt_payment_method_id'");
                if ($methodColumn && $methodColumn->rowCount() > 0) {
                    $this->pdo->exec("ALTER TABLE dcmt_income_payment_history DROP COLUMN dcmt_payment_method_id");
                    error_log("Removed dcmt_payment_method_id column from dcmt_income_payment_history table");
                }

                // Ensure required columns exist (without payment method)
                $requiredColumns = [
                    'dcmt_payment_type' => "ALTER TABLE dcmt_income_payment_history ADD COLUMN dcmt_payment_type ENUM('consultation', 'product', 'general') NOT NULL DEFAULT 'general' AFTER dcmt_income_id",
                    'dcmt_amount' => "ALTER TABLE dcmt_income_payment_history ADD COLUMN dcmt_amount DECIMAL(10,2) NOT NULL AFTER dcmt_payment_type",
                    'dcmt_paid_on' => "ALTER TABLE dcmt_income_payment_history ADD COLUMN dcmt_paid_on DATE NOT NULL AFTER dcmt_amount",
                    'dcmt_notes' => "ALTER TABLE dcmt_income_payment_history ADD COLUMN dcmt_notes TEXT AFTER dcmt_paid_on",
                    'dcmt_recorded_by' => "ALTER TABLE dcmt_income_payment_history ADD COLUMN dcmt_recorded_by VARCHAR(50) NOT NULL AFTER dcmt_notes",
                    'dcmt_created_at' => "ALTER TABLE dcmt_income_payment_history ADD COLUMN dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP AFTER dcmt_recorded_by"
                ];

                foreach ($requiredColumns as $column => $alterSql) {
                    $columnCheck = $this->pdo->query("SHOW COLUMNS FROM dcmt_income_payment_history LIKE '{$column}'");
                    if ($columnCheck->rowCount() == 0) {
                        $this->pdo->exec($alterSql);
                        error_log("Added {$column} column to dcmt_income_payment_history table");
                    }
                }

                // Ensure indexes exist
                $indexes = [
                    'idx_income_payment_history_income' => "CREATE INDEX idx_income_payment_history_income ON dcmt_income_payment_history (dcmt_income_id)",
                    'idx_income_payment_history_type' => "CREATE INDEX idx_income_payment_history_type ON dcmt_income_payment_history (dcmt_payment_type)",
                    'idx_income_payment_history_paid_on' => "CREATE INDEX idx_income_payment_history_paid_on ON dcmt_income_payment_history (dcmt_paid_on)"
                ];

                foreach ($indexes as $indexName => $indexSql) {
                    $indexCheck = $this->pdo->query("SHOW INDEX FROM dcmt_income_payment_history WHERE Key_name = '{$indexName}'");
                    if ($indexCheck->rowCount() == 0) {
                        $this->pdo->exec($indexSql);
                        error_log("Added {$indexName} index to dcmt_income_payment_history table");
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Failed to ensure income payment history table: " . $e->getMessage());
        }
    }

    public function addDashboardSummaryToggleField()
    {
        try {
            // Check if the field already exists
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_dashboard_summary_toggle'");
            if ($stmt->rowCount() == 0) {
                // Add the dashboard_summary_toggle field (1 = ON, 0 = OFF, default = 1)
                $this->pdo->exec("ALTER TABLE dcmt_users ADD COLUMN dcmt_dashboard_summary_toggle TINYINT(1) DEFAULT 1 AFTER dcmt_last_login");
                error_log("Added dcmt_dashboard_summary_toggle field to dcmt_users table");

                // Update existing users to have summary toggle ON by default
                $this->pdo->exec("UPDATE dcmt_users SET dcmt_dashboard_summary_toggle = 1 WHERE dcmt_dashboard_summary_toggle IS NULL");
                error_log("Updated existing users to have dashboard summary toggle ON by default");
            }
        } catch (PDOException $e) {
            error_log("Failed to add dashboard summary toggle field: " . $e->getMessage());
        }
    }

    public function insertDefaultData()
    {
        try {
            // Insert default admin user
            $adminPassword = password_hash('admin@123', PASSWORD_DEFAULT);
            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_created_by) 
                VALUES (?, ?, ?, ?, ?, ?)
            ");
            $stmt->execute(['admin', 'admin@dentalclinic.com', $adminPassword, 'Admin User', 'admin', 'system']);

            // Insert default expense categories
            $expense_categories = [
                ['Office Supplies', 'General office supplies and stationery'],
                ['Equipment', 'Medical equipment and tools'],
                ['Utilities', 'Electricity, water, internet bills'],
                ['Rent', 'Office rent and maintenance'],
                ['Marketing', 'Advertising and promotional expenses'],
                ['Insurance', 'Medical and liability insurance'],
                ['Training', 'Staff training and education'],
                ['Other', 'Miscellaneous expenses']
            ];

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_expense_categories (dcmt_name, dcmt_description, dcmt_created_by) 
                VALUES (?, ?, ?)
            ");

            foreach ($expense_categories as $category) {
                $stmt->execute([$category[0], $category[1], 'system']);
            }

            // Insert default inventory categories
            $inventory_categories = [
                ['Dental Materials', 'Dental filling materials and composites'],
                ['Instruments', 'Dental instruments and tools'],
                ['Medications', 'Anesthetics and medications'],
                ['Disposables', 'Gloves, masks, and disposable items'],
                ['Equipment', 'Dental equipment and machinery'],
                ['Cleaning Supplies', 'Disinfectants and cleaning materials'],
                ['Office Supplies', 'General office and administrative supplies'],
                ['Other', 'Miscellaneous inventory items']
            ];

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_inventory_categories (dcmt_name, dcmt_description, dcmt_created_by) 
                VALUES (?, ?, ?)
            ");

            foreach ($inventory_categories as $category) {
                $stmt->execute([$category[0], $category[1], 'system']);
            }

            // Insert default income payment methods
            $income_payment_methods = [
                ['Cash', 'Cash payments'],
                ['Credit Card', 'Credit card payments'],
                ['Debit Card', 'Debit card payments'],
                ['Bank Transfer', 'Bank transfer payments'],
                ['Online Payment', 'Online payment gateway'],
                ['Check', 'Check payments']
            ];

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_income_payment_methods (dcmt_name, dcmt_description, dcmt_created_by) 
                VALUES (?, ?, ?)
            ");

            foreach ($income_payment_methods as $method) {
                $stmt->execute([$method[0], $method[1], 'system']);
            }

            // Insert default expense payment methods
            $expense_payment_methods = [
                ['Cash', 'Cash payments'],
                ['Credit Card', 'Credit card payments'],
                ['Debit Card', 'Debit card payments'],
                ['Bank Transfer', 'Bank transfer payments'],
                ['Check', 'Check payments'],
                ['Online Payment', 'Online payment gateway']
            ];

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_expense_payment_methods (dcmt_name, dcmt_description, dcmt_created_by) 
                VALUES (?, ?, ?)
            ");

            foreach ($expense_payment_methods as $method) {
                $stmt->execute([$method[0], $method[1], 'system']);
            }

            // Insert default income payment statuses
            $income_payment_statuses = [
                ['Completed', 'Payment has been completed successfully'],
                ['Pending', 'Payment is pending or in process'],
                ['Failed', 'Payment has failed or was declined'],
                ['Cancelled', 'Payment was cancelled'],
                ['Refunded', 'Payment has been refunded']
            ];

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_income_payment_status (dcmt_name, dcmt_description, dcmt_created_by) 
                VALUES (?, ?, ?)
            ");

            foreach ($income_payment_statuses as $status) {
                $stmt->execute([$status[0], $status[1], 'system']);
            }

            // Insert default services
            $default_services = [
                ['Root Canal Treatment', 'Endodontic treatment to save infected tooth', 2000.00],
                ['Teeth Cleaning', 'Professional dental cleaning and polishing', 500.00],
                ['Tooth Extraction', 'Simple tooth extraction procedure', 800.00],
                ['Dental Filling', 'Composite or amalgam filling for cavities', 600.00],
                ['Teeth Whitening', 'Professional teeth whitening treatment', 1500.00],
                ['Crown/Bridge', 'Dental crown or bridge installation', 3000.00],
                ['X-Ray (Full Mouth)', 'Complete dental X-ray examination', 400.00],
                ['X-Ray (Single)', 'Single tooth X-ray', 150.00],
                ['Dental Implant', 'Dental implant procedure', 5000.00],
                ['Orthodontic Consultation', 'Consultation for braces/aligners', 300.00],
                ['Scaling and Root Planing', 'Deep cleaning for gum disease', 1200.00],
                ['Emergency Treatment', 'Emergency dental care', 1000.00]
            ];

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_services (dcmt_name, dcmt_description, dcmt_base_price, dcmt_created_by) 
                VALUES (?, ?, ?, 'system')
            ");

            foreach ($default_services as $service) {
                $stmt->execute($service);
            }

            // Insert default settings
            $settings = [
                ['app_name', 'Dental Clinic Management System', 'Application name', 'Application name'],
                ['app_url', 'https://cwdemos.com/dev_dental', 'Application URL', 'Application URL'],
                ['currency_type', 'MXN', 'MXN', 'Currency type for the application'],
                ['low_stock_threshold', '10', 'Default minimum stock threshold for inventory alerts', 'Default minimum stock threshold for inventory alerts'],
                ['clinic_name', 'Dental Clinic', 'Name of the dental clinic', 'Name of the dental clinic'],
                ['clinic_address', '', 'Address of the dental clinic', 'Address of the dental clinic'],
                ['clinic_phone', '', 'Phone number of the dental clinic', 'Phone number of the dental clinic']
            ];

            $stmt = $this->pdo->prepare("
                INSERT IGNORE INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_description, dcmt_setting_type, dcmt_category, dcmt_created_by) 
                VALUES (?, ?, ?, ?, 'text', 'General', ?)
            ");

            foreach ($settings as $setting) {
                $stmt->execute([$setting[0], $setting[1], $setting[2], $setting[3], 'system']);
            }

            return true;
        } catch (PDOException $e) {
            error_log("Default data insertion failed: " . $e->getMessage());
            return false;
        }
    }

    public function createIndexes()
    {
        $indexes = [
            "CREATE INDEX IF NOT EXISTS idx_income_date ON dcmt_income(dcmt_transaction_date)",
            "CREATE INDEX IF NOT EXISTS idx_income_type ON dcmt_income(dcmt_type)",
            "CREATE INDEX IF NOT EXISTS idx_income_user ON dcmt_income(dcmt_user_id)",
            "CREATE INDEX IF NOT EXISTS idx_income_service ON dcmt_income(dcmt_service_id)",
            "CREATE INDEX IF NOT EXISTS idx_expense_date ON dcmt_expenses(dcmt_expense_date)",
            "CREATE INDEX IF NOT EXISTS idx_expense_category ON dcmt_expenses(dcmt_category_id)",
            "CREATE INDEX IF NOT EXISTS idx_inventory_category ON dcmt_inventory(dcmt_category_id)",
            "CREATE INDEX IF NOT EXISTS idx_inventory_status ON dcmt_inventory(dcmt_status)",
            "CREATE INDEX IF NOT EXISTS idx_inventory_expiry_date ON dcmt_inventory(dcmt_expiry_date)",
            "CREATE INDEX IF NOT EXISTS idx_users_email ON dcmt_users(dcmt_email)",
            "CREATE INDEX IF NOT EXISTS idx_users_status ON dcmt_users(dcmt_status)",
            "CREATE INDEX IF NOT EXISTS idx_expense_categories_status ON dcmt_expense_categories(dcmt_status)",
            "CREATE INDEX IF NOT EXISTS idx_inventory_categories_status ON dcmt_inventory_categories(dcmt_status)"
        ];

        try {
            foreach ($indexes as $index) {
                $this->pdo->exec($index);
            }
            $this->createForeignKeys();
            return true;
        } catch (PDOException $e) {
            error_log("Index creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function checkAndCreateMissingTables()
    {
        // List of all required tables
        $requiredTables = [
            'dcmt_users',
            'dcmt_doctor_specializations',
            'dcmt_patients',
            'dcmt_expense_categories',
            'dcmt_inventory_categories',
            'dcmt_inventory',
            'dcmt_income',
            'dcmt_income_breakdown',
            'dcmt_income_payment_history',
            'dcmt_expenses',
            'dcmt_settings',
            'dcmt_income_payment_methods',
            'dcmt_expense_payment_methods',
            'dcmt_income_payment_status',
            'dcmt_services',
            'dcmt_doctor_services',
            'dcmt_doctor_goals',
            'dcmt_cashflows',
            'dcmt_cashflow_denominations',
            'dcmt_activity_log',
            'dcmt_patient_notes',
            'dcmt_doctor_duty_hours',
            'dcmt_appointments'
        ];

        $missingTables = [];

        try {
            // Check each table
            foreach ($requiredTables as $table) {
                try {
                    $this->pdo->query("DESCRIBE `{$table}`");
                } catch (PDOException $e) {
                    // Table doesn't exist
                    $missingTables[] = $table;
                    error_log("Missing table detected: {$table}");
                }
            }

            // If any tables are missing, recreate them
            if (!empty($missingTables)) {
                error_log("Recreating " . count($missingTables) . " missing table(s): " . implode(', ', $missingTables));
                $this->createTables();

                // Also ensure additional tables that are created separately
                $this->addIncomePaymentHistoryTable();
                $this->addPatientNotesTable();

                return true;
            }

            return false;
        } catch (PDOException $e) {
            error_log("Error checking tables: " . $e->getMessage());
            return false;
        }
    }
}

// Initialize database
$dcmt_db = new Dcmt_Database();
$dcmt_pdo = $dcmt_db->getConnection();

// Always check for missing tables and recreate them if needed
$tablesRecreated = $dcmt_db->checkAndCreateMissingTables();

// Create tables and insert default data on first run
// Check if tables exist by trying to describe a table
try {
    $dcmt_pdo->query("DESCRIBE dcmt_users");
    // Table exists, check if it has data
    $test_stmt = $dcmt_pdo->query("SELECT COUNT(*) FROM dcmt_users");
    $user_count = $test_stmt->fetchColumn();

    // If no users exist or tables were recreated, insert default data
    if ($user_count == 0 || $tablesRecreated) {
        $dcmt_db->insertDefaultData();
        $dcmt_db->createIndexes();
    } else {
        // Always check for new fields even if data exists
        $dcmt_db->addPaymentAmountFields();
        $dcmt_db->addSeparatePaymentFields();
        $dcmt_db->addServiceIdField();
        $dcmt_db->addServiceAmountFields();
        $dcmt_db->addProductAmountFields();
        $dcmt_db->addProductTypeField();
        $dcmt_db->addInventoryBrandField();
        $dcmt_db->addIncomePaymentHistoryTable();
        $dcmt_db->addDashboardSummaryToggleField();
        $dcmt_db->addCashflowExpenseFields();
        $dcmt_db->addCashflowDenominationTypeField();
        $dcmt_db->ensurePatientsTable();
        $dcmt_db->addPatientNotesTable();
        $dcmt_db->addPatientColumns();
        $dcmt_db->addIncomeNoteField();
        $dcmt_db->addIncomePatientIdField();
        $dcmt_db->addDoctorCashFields();
        $dcmt_db->addAppointmentTables();
    }
} catch (PDOException $e) {
    // Tables don't exist, create everything
    $dcmt_db->createTables();
    $dcmt_db->insertDefaultData();
    $dcmt_db->createIndexes();
}
?>
