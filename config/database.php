<?php
/**
 * Database Configuration and Connection
 * Dental Clinic Management System
 */

if (!defined('DCMT_DB_HOST')) {
    require_once __DIR__ . '/config.php';
}

require_once __DIR__ . '/../includes/db_security.php';

class Dcmt_Database
{
    private $host;
    private $dbname;
    private $username;
    private $password;
    private $pdo;

    public function __construct()
    {
        $this->host = DCMT_DB_HOST;
        $this->dbname = DCMT_DB_NAME;
        $this->username = DCMT_DB_USER;
        $this->password = DCMT_DB_PASS;

        dcmt_db_validate_connection_params($this->host, $this->dbname);
        dcmt_db_validate_production_config();
        dcmt_db_enforce_ssl_requirement();

        $pdoOptions = [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ];
        if (defined('PDO::MYSQL_ATTR_LOCAL_INFILE')) {
            $pdoOptions[PDO::MYSQL_ATTR_LOCAL_INFILE] = false;
        }
        $pdoOptions = $this->applyMysqlSslOptions($pdoOptions);

        try {
            if (defined('DCMT_IS_PRODUCTION') && DCMT_IS_PRODUCTION) {
                $this->pdo = new PDO(
                    "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                    $this->username,
                    $this->password,
                    $pdoOptions
                );
            } else {
                $this->pdo = new PDO(
                    "mysql:host={$this->host};charset=utf8mb4",
                    $this->username,
                    $this->password,
                    $pdoOptions
                );

                $this->createDatabase();

                $this->pdo = new PDO(
                    "mysql:host={$this->host};dbname={$this->dbname};charset=utf8mb4",
                    $this->username,
                    $this->password,
                    $pdoOptions
                );
            }

            dcmt_db_apply_connection_hardening($this->pdo);
            dcmt_db_check_app_privileges($this->pdo);

            // Ensure consistent connection collation for string comparisons (LIKE, =, etc.)
            try {
                $this->pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci");
                $this->pdo->exec("SET collation_connection = 'utf8mb4_unicode_ci'");
            } catch (PDOException $e) {
                // Non-fatal; proceed with defaults if server restricts these settings
            }

        } catch (PDOException $e) {
            error_log('Database connection failed: ' . $e->getMessage());
            if (defined('DCMT_IS_PRODUCTION') && DCMT_IS_PRODUCTION) {
                die('Database connection failed. Please contact the administrator.');
            }
            die('Database connection failed: ' . $e->getMessage());
        }
    }

    private function createDatabase()
    {
        try {
            $dbname = $this->dbname;
            if (!dcmt_db_is_safe_identifier($dbname)) {
                throw new InvalidArgumentException('Invalid database name.');
            }
            $this->pdo->exec("CREATE DATABASE IF NOT EXISTS `{$dbname}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
        } catch (PDOException $e) {
            error_log('Failed to create database: ' . $e->getMessage());
            if (defined('DCMT_IS_PRODUCTION') && DCMT_IS_PRODUCTION) {
                die('Database setup failed. Please contact the administrator.');
            }
            die('Failed to create database: ' . $e->getMessage());
        }
    }

    private function applyMysqlSslOptions(array $pdoOptions): array
    {
        $sslCa = trim((string) dcmt_env('DCMT_DB_SSL_CA', ''));
        if ($sslCa === '' || !is_file($sslCa)) {
            return $pdoOptions;
        }

        if (defined('PDO::MYSQL_ATTR_SSL_CA')) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_CA] = $sslCa;
        }

        $verify = strtolower(trim((string) dcmt_env('DCMT_DB_SSL_VERIFY', '1')));
        if ($verify !== '0' && defined('PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT')) {
            $pdoOptions[PDO::MYSQL_ATTR_SSL_VERIFY_SERVER_CERT] = true;
        }

        return $pdoOptions;
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
            dcmt_role ENUM('admin', 'staff', 'doctor', 'assistant') DEFAULT 'staff',
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_phone VARCHAR(20),
            dcmt_address TEXT,
            dcmt_notes TEXT,
            dcmt_qualification VARCHAR(255) NULL,
            dcmt_specialization_id INT NULL,
            dcmt_color_code VARCHAR(7) NULL,
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

        -- Odontogram clinical treatments (applied under a tooth condition, not surface colors)
        CREATE TABLE IF NOT EXISTS dcmt_odontogram_treatments (
            dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
            dcmt_name VARCHAR(100) NOT NULL,
            dcmt_description TEXT,
            dcmt_color VARCHAR(7) NULL,
            dcmt_status ENUM('active', 'inactive') DEFAULT 'active',
            dcmt_sort_order INT NOT NULL DEFAULT 0,
            dcmt_created_by VARCHAR(50) NOT NULL,
            dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
            UNIQUE KEY unique_odontogram_treatment_name (dcmt_name)
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
            dcmt_goal_metric ENUM('income','appointments') NOT NULL DEFAULT 'income',
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
            dcmt_actual_start_at DATETIME NULL,
            dcmt_actual_end_at DATETIME NULL,
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
            dcmt_status ENUM('open', 'closed') NOT NULL DEFAULT 'open',
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
            $this->addRemindersTable();
            $this->addMessagingTables();
            $this->addDoctorCashFields();
            $this->addAppointmentTables();
            $this->addAppointmentOperatoryFields();
            $this->addAppointmentActualTimeFields();
            $this->ensureOperatoriesAreGlobal();
            $this->addDoctorGoalMetricField();
            $this->ensureOwnerDoctorUserIdsSetting();
            return true;
        } catch (PDOException $e) {
            error_log("Table creation failed: " . $e->getMessage());
            return false;
        }
    }

    public function addDoctorGoalMetricField()
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_doctor_goals LIKE 'dcmt_goal_metric'");
            if ($stmt && $stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_doctor_goals ADD COLUMN dcmt_goal_metric ENUM('income','appointments') NOT NULL DEFAULT 'income' AFTER dcmt_goal_amount");
                error_log("Added dcmt_goal_metric to dcmt_doctor_goals");
            }
        } catch (PDOException $e) {
            error_log("Failed to add dcmt_goal_metric: " . $e->getMessage());
        }
    }

    /**
     * Ensure dcmt_settings row for clinic owner doctors (JSON array of user IDs; admin-level access).
     * Application code: includes/dcmt_owner_doctor.php and pages/users/index.php (user-tie toggle).
     */
    public function ensureOwnerDoctorUserIdsSetting()
    {
        try {
            $chk = $this->pdo->query("SELECT 1 FROM dcmt_settings WHERE dcmt_setting_key = 'owner_doctor_user_ids' LIMIT 1");
            if ($chk && $chk->fetchColumn()) {
                return;
            }
            $stmt = $this->pdo->prepare("
                INSERT INTO dcmt_settings (dcmt_setting_key, dcmt_setting_name, dcmt_setting_value, dcmt_setting_description, dcmt_setting_type, dcmt_category, dcmt_created_by)
                VALUES (?, ?, ?, ?, 'text', 'Doctor', ?)
            ");
            $stmt->execute([
                'owner_doctor_user_ids',
                'Owner Doctor Users',
                '[]',
                'JSON array of doctor user IDs with clinic owner (admin-level) access. Managed from Users list (user-tie icon).',
                'system',
            ]);
            error_log('Ensured dcmt_settings row: owner_doctor_user_ids');
        } catch (PDOException $e) {
            error_log('ensureOwnerDoctorUserIdsSetting failed: ' . $e->getMessage());
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

            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_cashflows LIKE 'dcmt_status'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_cashflows ADD COLUMN dcmt_status ENUM('open', 'closed') NOT NULL DEFAULT 'open' AFTER dcmt_difference");
                error_log("Added dcmt_status field to dcmt_cashflows table");
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
                    dcmt_actual_start_at DATETIME NULL,
                    dcmt_actual_end_at DATETIME NULL,
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

    /**
     * Operatories (treatment rooms): appointments block a single operatory for utilization tracking.
     */
    public function addAppointmentOperatoryFields()
    {
        try {
            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS dcmt_operatories (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_name VARCHAR(120) NOT NULL,
                    dcmt_sort_order INT NOT NULL DEFAULT 0,
                    dcmt_is_active TINYINT(1) NOT NULL DEFAULT 1,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    INDEX idx_operatories_active (dcmt_is_active)
                )
            ");

            $cnt = (int)$this->pdo->query("SELECT COUNT(*) FROM dcmt_operatories")->fetchColumn();
            if ($cnt === 0) {
                $this->pdo->exec("
                    INSERT INTO dcmt_operatories (dcmt_name, dcmt_sort_order, dcmt_is_active) VALUES
                    ('Chair 1', 1, 1),
                    ('Chair 2', 2, 1),
                    ('Chair 3', 3, 1)
                ");
            }

            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_appointments LIKE 'dcmt_operatory_id'");
            if ($stmt && $stmt->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE dcmt_appointments ADD COLUMN dcmt_operatory_id INT NULL AFTER dcmt_doctor_id");
                $firstId = (int)$this->pdo->query("SELECT dcmt_id FROM dcmt_operatories ORDER BY dcmt_sort_order ASC, dcmt_id ASC LIMIT 1")->fetchColumn();
                if ($firstId > 0) {
                    $this->pdo->exec("UPDATE dcmt_appointments SET dcmt_operatory_id = " . $firstId . " WHERE dcmt_operatory_id IS NULL");
                }
                $this->pdo->exec("ALTER TABLE dcmt_appointments MODIFY COLUMN dcmt_operatory_id INT NOT NULL");
                $this->pdo->exec("ALTER TABLE dcmt_appointments ADD INDEX idx_appointments_operatory_start (dcmt_operatory_id, dcmt_start_at)");
            }

            try {
                $this->pdo->exec("ALTER TABLE dcmt_appointments ADD CONSTRAINT fk_appointments_operatory FOREIGN KEY (dcmt_operatory_id) REFERENCES dcmt_operatories(dcmt_id) ON DELETE RESTRICT");
            } catch (PDOException $e) {
                // Constraint may already exist
            }
        } catch (PDOException $e) {
            error_log("addAppointmentOperatoryFields: " . $e->getMessage());
        }
    }

    public function addAppointmentActualTimeFields()
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_appointments LIKE 'dcmt_actual_start_at'");
            if ($stmt && $stmt->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE dcmt_appointments ADD COLUMN dcmt_actual_start_at DATETIME NULL AFTER dcmt_end_at");
            }

            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_appointments LIKE 'dcmt_actual_end_at'");
            if ($stmt && $stmt->rowCount() === 0) {
                $this->pdo->exec("ALTER TABLE dcmt_appointments ADD COLUMN dcmt_actual_end_at DATETIME NULL AFTER dcmt_actual_start_at");
            }

            try {
                $this->pdo->exec("ALTER TABLE dcmt_appointments ADD INDEX idx_appointments_actual_range (dcmt_actual_start_at, dcmt_actual_end_at)");
            } catch (PDOException $e) {
                // Index may already exist.
            }
        } catch (PDOException $e) {
            error_log("addAppointmentActualTimeFields: " . $e->getMessage());
        }
    }

    /**
     * Scope operatories to each doctor; seed defaults per doctor.
     */
    public function addOperatoryDoctorScope()
    {
        try {
            $tbl = $this->pdo->query("SHOW TABLES LIKE 'dcmt_operatories'");
            if (!$tbl || $tbl->rowCount() === 0) {
                return;
            }
            $col = $this->pdo->query("SHOW COLUMNS FROM dcmt_operatories LIKE 'dcmt_doctor_id'");
            if ($col && $col->rowCount() > 0) {
                $this->seedOperatoriesForDoctorsWithoutRows();
                return;
            }

            $this->pdo->exec("ALTER TABLE dcmt_operatories ADD COLUMN dcmt_doctor_id INT NULL AFTER dcmt_id");

            $firstDoctor = $this->pdo->query("SELECT dcmt_id FROM dcmt_users WHERE dcmt_role = 'doctor' AND dcmt_status = 'active' ORDER BY dcmt_id ASC LIMIT 1")->fetchColumn();
            if ($firstDoctor) {
                $this->pdo->exec("UPDATE dcmt_operatories SET dcmt_doctor_id = " . (int)$firstDoctor . " WHERE dcmt_doctor_id IS NULL");
            } else {
                $this->pdo->exec("DELETE FROM dcmt_operatories WHERE dcmt_doctor_id IS NULL");
            }

            $this->seedOperatoriesForDoctorsWithoutRows();

            $nullLeft = (int)$this->pdo->query("SELECT COUNT(*) FROM dcmt_operatories WHERE dcmt_doctor_id IS NULL")->fetchColumn();
            if ($nullLeft > 0 && $firstDoctor) {
                $this->pdo->exec("UPDATE dcmt_operatories SET dcmt_doctor_id = " . (int)$firstDoctor . " WHERE dcmt_doctor_id IS NULL");
            }
            if ((int)$this->pdo->query("SELECT COUNT(*) FROM dcmt_operatories WHERE dcmt_doctor_id IS NULL")->fetchColumn() > 0) {
                $this->pdo->exec("DELETE FROM dcmt_operatories WHERE dcmt_doctor_id IS NULL");
            }

            $this->pdo->exec("ALTER TABLE dcmt_operatories MODIFY COLUMN dcmt_doctor_id INT NOT NULL");
            $this->pdo->exec("ALTER TABLE dcmt_operatories ADD INDEX idx_operatories_doctor (dcmt_doctor_id)");
            try {
                $this->pdo->exec("ALTER TABLE dcmt_operatories ADD CONSTRAINT fk_operatories_doctor FOREIGN KEY (dcmt_doctor_id) REFERENCES dcmt_users(dcmt_id) ON DELETE RESTRICT");
            } catch (PDOException $e) {
                // May already exist
            }
        } catch (PDOException $e) {
            error_log("addOperatoryDoctorScope: " . $e->getMessage());
        }
    }

    /**
     * Operatories are clinic-wide (shared across doctors).
     * If an older install has doctor-scoped operatories, remove that scope.
     */
    public function ensureOperatoriesAreGlobal()
    {
        try {
            $tbl = $this->pdo->query("SHOW TABLES LIKE 'dcmt_operatories'");
            if (!$tbl || $tbl->rowCount() === 0) {
                return;
            }

            $col = $this->pdo->query("SHOW COLUMNS FROM dcmt_operatories LIKE 'dcmt_doctor_id'");
            if (!$col || $col->rowCount() === 0) {
                return;
            }

            // Drop FK/index if they exist; ignore errors when already removed.
            try {
                $this->pdo->exec("ALTER TABLE dcmt_operatories DROP FOREIGN KEY fk_operatories_doctor");
            } catch (PDOException $e) {
                // ignore
            }
            try {
                $this->pdo->exec("ALTER TABLE dcmt_operatories DROP INDEX idx_operatories_doctor");
            } catch (PDOException $e) {
                // ignore
            }

            // Drop the doctor scope column.
            $this->pdo->exec("ALTER TABLE dcmt_operatories DROP COLUMN dcmt_doctor_id");
        } catch (PDOException $e) {
            error_log("ensureOperatoriesAreGlobal: " . $e->getMessage());
        }
    }

    private function seedOperatoriesForDoctorsWithoutRows()
    {
        // Operatories are added per doctor via Doctor Duty Hours ("Add operatory"); do not auto-seed.
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

    public function addRemindersTable()
    {
        try {
            $tableCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_reminders'");
            if ($tableCheck->rowCount() == 0) {
                $this->createRemindersTableV2();
                return;
            }

            $legacyCol = $this->pdo->query("SHOW COLUMNS FROM dcmt_reminders LIKE 'dcmt_target_datetime'");
            $modernCol = $this->pdo->query("SHOW COLUMNS FROM dcmt_reminders LIKE 'dcmt_reminder_at'");
            if ($legacyCol && $legacyCol->rowCount() > 0 && (!$modernCol || $modernCol->rowCount() === 0)) {
                $backupName = 'dcmt_reminders_legacy_' . date('Ymd');
                $backupExists = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote($backupName));
                if (!$backupExists || $backupExists->rowCount() === 0) {
                    $this->pdo->exec("RENAME TABLE dcmt_reminders TO `{$backupName}`");
                    error_log("Renamed legacy dcmt_reminders table to {$backupName}");
                }
                $this->createRemindersTableV2();
                return;
            }

            $this->ensureRemindersTableColumns();
            $this->ensureRemindersPatientColumnRemoved();
        } catch (PDOException $e) {
            error_log("Error adding reminders table: " . $e->getMessage());
        }
    }

    public function addMessagingTables(): void
    {
        try {
            $convCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_conversations'");
            if ($convCheck && $convCheck->rowCount() > 0) {
                return;
            }

            $this->pdo->exec("
                CREATE TABLE IF NOT EXISTS dcmt_conversations (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_type ENUM('direct', 'group', 'role', 'system') NOT NULL DEFAULT 'direct',
                    dcmt_title VARCHAR(255) NULL,
                    dcmt_slug VARCHAR(64) NULL,
                    dcmt_direct_key VARCHAR(64) NULL,
                    dcmt_role_target ENUM('admin', 'staff', 'doctor', 'assistant') NULL,
                    dcmt_appointment_id INT NULL,
                    dcmt_patient_id INT NULL,
                    dcmt_created_by_user_id INT NOT NULL DEFAULT 0,
                    dcmt_is_archived TINYINT(1) NOT NULL DEFAULT 0,
                    dcmt_last_message_id INT NULL,
                    dcmt_last_message_at DATETIME NULL,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                    UNIQUE KEY uq_conversations_direct_key (dcmt_direct_key),
                    UNIQUE KEY uq_conversations_slug (dcmt_slug),
                    INDEX idx_conversations_last_message_at (dcmt_last_message_at)
                );

                CREATE TABLE IF NOT EXISTS dcmt_conversation_participants (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_conversation_id INT NOT NULL,
                    dcmt_user_id INT NOT NULL,
                    dcmt_role_in_conversation ENUM('owner', 'member', 'observer') NOT NULL DEFAULT 'member',
                    dcmt_last_read_message_id INT NULL,
                    dcmt_last_read_at DATETIME NULL,
                    dcmt_unread_count INT NOT NULL DEFAULT 0,
                    dcmt_is_muted TINYINT(1) NOT NULL DEFAULT 0,
                    dcmt_joined_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_left_at DATETIME NULL,
                    UNIQUE KEY uq_participant_conversation_user (dcmt_conversation_id, dcmt_user_id),
                    INDEX idx_participants_user_unread (dcmt_user_id, dcmt_unread_count)
                );

                CREATE TABLE IF NOT EXISTS dcmt_messages (
                    dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                    dcmt_conversation_id INT NOT NULL,
                    dcmt_sender_user_id INT NULL,
                    dcmt_message_type ENUM('text', 'system', 'appointment', 'task', 'priority') NOT NULL DEFAULT 'text',
                    dcmt_body TEXT NOT NULL,
                    dcmt_metadata JSON NULL,
                    dcmt_priority ENUM('normal', 'high') NOT NULL DEFAULT 'normal',
                    dcmt_idempotency_key VARCHAR(64) NULL,
                    dcmt_reply_to_message_id INT NULL,
                    dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                    dcmt_edited_at DATETIME NULL,
                    dcmt_deleted_at DATETIME NULL,
                    UNIQUE KEY uq_messages_idempotency (dcmt_idempotency_key),
                    INDEX idx_messages_conversation_id (dcmt_conversation_id, dcmt_id)
                );
            ");
            error_log('Created messaging tables');
        } catch (PDOException $e) {
            error_log('Error adding messaging tables: ' . $e->getMessage());
        }
    }

    private function ensureRemindersPatientColumnRemoved(): void
    {
        try {
            $tableCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_reminders'");
            if (!$tableCheck || $tableCheck->rowCount() === 0) {
                return;
            }

            $colCheck = $this->pdo->query("SHOW COLUMNS FROM dcmt_reminders LIKE 'dcmt_patient_id'");
            if (!$colCheck || $colCheck->rowCount() === 0) {
                return;
            }

            try {
                $this->pdo->exec("ALTER TABLE dcmt_reminders DROP INDEX idx_reminders_patient");
            } catch (PDOException $e) {
                // ignore when index already removed
            }

            $this->pdo->exec("ALTER TABLE dcmt_reminders DROP COLUMN dcmt_patient_id");
            error_log("Removed dcmt_patient_id from dcmt_reminders");
        } catch (PDOException $e) {
            error_log("Error removing reminders patient column: " . $e->getMessage());
        }
    }

    private function createRemindersTableV2(): void
    {
        $this->pdo->exec("
            CREATE TABLE IF NOT EXISTS dcmt_reminders (
                dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                dcmt_assigned_user_id INT NOT NULL,
                dcmt_title VARCHAR(255) NOT NULL,
                dcmt_description TEXT NULL,
                dcmt_reminder_at DATETIME NOT NULL,
                dcmt_notify_at DATETIME NOT NULL,
                dcmt_status ENUM('pending', 'completed', 'cancelled') NOT NULL DEFAULT 'pending',
                dcmt_notification_sent TINYINT(1) NOT NULL DEFAULT 0,
                dcmt_notification_sent_at DATETIME NULL,
                dcmt_notification_dismissed TINYINT(1) NOT NULL DEFAULT 0,
                dcmt_completed_at DATETIME NULL,
                dcmt_created_by_user_id INT NOT NULL,
                dcmt_created_by VARCHAR(50) NOT NULL,
                dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                INDEX idx_reminders_assigned_user (dcmt_assigned_user_id),
                INDEX idx_reminders_reminder_at (dcmt_reminder_at),
                INDEX idx_reminders_notify_pending (dcmt_status, dcmt_notification_sent, dcmt_notify_at),
                INDEX idx_reminders_assigned_notifications (dcmt_assigned_user_id, dcmt_notification_sent, dcmt_notification_dismissed, dcmt_status)
            );
        ");
        error_log("Created dcmt_reminders table (v2 schema)");
    }

    private function ensureRemindersTableColumns(): void
    {
        $columns = [
            'dcmt_assigned_user_id' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_assigned_user_id INT NOT NULL DEFAULT 0 AFTER dcmt_id",
            'dcmt_description' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_description TEXT NULL AFTER dcmt_title",
            'dcmt_reminder_at' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_reminder_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER dcmt_description",
            'dcmt_notify_at' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_notify_at DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP AFTER dcmt_reminder_at",
            'dcmt_notification_sent' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_notification_sent TINYINT(1) NOT NULL DEFAULT 0 AFTER dcmt_status",
            'dcmt_notification_sent_at' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_notification_sent_at DATETIME NULL AFTER dcmt_notification_sent",
            'dcmt_notification_dismissed' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_notification_dismissed TINYINT(1) NOT NULL DEFAULT 0 AFTER dcmt_notification_sent_at",
            'dcmt_completed_at' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_completed_at DATETIME NULL AFTER dcmt_notification_dismissed",
            'dcmt_created_by_user_id' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_created_by_user_id INT NOT NULL DEFAULT 0 AFTER dcmt_completed_at",
            'dcmt_created_by' => "ALTER TABLE dcmt_reminders ADD COLUMN dcmt_created_by VARCHAR(50) NOT NULL DEFAULT 'system' AFTER dcmt_created_by_user_id",
        ];

        foreach ($columns as $col => $sql) {
            $check = $this->pdo->query("SHOW COLUMNS FROM dcmt_reminders LIKE " . $this->pdo->quote($col));
            if (!$check || $check->rowCount() === 0) {
                $this->pdo->exec($sql);
                error_log("Added {$col} to dcmt_reminders");
            }
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
                'dcmt_emergency_contact_phone' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_emergency_contact_phone VARCHAR(25) NULL AFTER dcmt_emergency_contact_relation",
                'dcmt_referral_source' => "ALTER TABLE dcmt_patients ADD COLUMN dcmt_referral_source VARCHAR(50) NULL AFTER dcmt_notes"
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
                        dcmt_payment_method_id INT NULL,
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

                // Ensure required columns exist
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

    public function addAssistantRoleToUsers()
    {
        try {
            $this->pdo->exec("ALTER TABLE dcmt_users MODIFY COLUMN dcmt_role ENUM('admin', 'staff', 'doctor', 'assistant') DEFAULT 'staff'");
        } catch (PDOException $e) {
            error_log("Failed to add assistant role to users table: " . $e->getMessage());
        }
    }

    public function addDoctorColorCodeField()
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM dcmt_users LIKE 'dcmt_color_code'");
            if ($stmt->rowCount() == 0) {
                $this->pdo->exec("ALTER TABLE dcmt_users ADD COLUMN dcmt_color_code VARCHAR(7) NULL AFTER dcmt_specialization_id");
                error_log("Added dcmt_color_code field to dcmt_users table");
            }
        } catch (PDOException $e) {
            error_log("Failed to add dcmt_color_code field to users table: " . $e->getMessage());
        }
    }

    public function insertDefaultData()
    {
        try {
            // Default admin only on fresh non-production installs (see config.php DCMT_IS_PRODUCTION).
            if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
                $adminPassword = password_hash('admin@123', PASSWORD_DEFAULT);
                $stmt = $this->pdo->prepare("
                    INSERT IGNORE INTO dcmt_users (dcmt_username, dcmt_email, dcmt_password, dcmt_full_name, dcmt_role, dcmt_created_by) 
                    VALUES (?, ?, ?, ?, ?, ?)
                ");
                $stmt->execute(['admin', 'admin@dentalclinic.com', $adminPassword, 'Admin User', 'admin', 'system']);
            }

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
                ['clinic_phone', '', 'Phone number of the dental clinic', 'Phone number of the dental clinic'],
                ['owner_doctor_user_ids', 'Owner Doctor Users', '[]', 'JSON array of doctor user IDs with clinic owner (admin-level) access. Managed from Users list (user-tie icon).']
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
            'dcmt_audit_log',
            'dcmt_login_attempts',
            'dcmt_patient_notes',
            'dcmt_reminders',
            'dcmt_conversations',
            'dcmt_conversation_participants',
            'dcmt_messages',
            'dcmt_doctor_duty_hours',
            'dcmt_appointments',
            'dcmt_operatories',
            'dcmt_odontogram_treatments',
            'dcmt_odontogram_problem_states',
            'dcmt_patient_odontogram',
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
                $this->addRemindersTable();
                $this->addMessagingTables();

                return true;
            }

            return false;
        } catch (PDOException $e) {
            error_log("Error checking tables: " . $e->getMessage());
            return false;
        }
    }

    public function getSchemaVersion(): string
    {
        if (!defined('DCMT_SCHEMA_VERSION_SETTING_KEY')) {
            return '0';
        }
        try {
            $stmt = $this->pdo->prepare(
                'SELECT dcmt_setting_value FROM dcmt_settings WHERE dcmt_setting_key = ? LIMIT 1'
            );
            $stmt->execute([DCMT_SCHEMA_VERSION_SETTING_KEY]);
            $value = $stmt->fetchColumn();
            return $value !== false && $value !== '' ? (string) $value : '0';
        } catch (PDOException $e) {
            return '0';
        }
    }

    public function setSchemaVersion(string $version): void
    {
        if (!defined('DCMT_SCHEMA_VERSION_SETTING_KEY')) {
            return;
        }
        try {
            $chk = $this->pdo->prepare(
                'SELECT dcmt_id FROM dcmt_settings WHERE dcmt_setting_key = ? LIMIT 1'
            );
            $chk->execute([DCMT_SCHEMA_VERSION_SETTING_KEY]);
            if ($chk->fetchColumn()) {
                $upd = $this->pdo->prepare(
                    'UPDATE dcmt_settings SET dcmt_setting_value = ?, dcmt_updated_at = CURRENT_TIMESTAMP WHERE dcmt_setting_key = ?'
                );
                $upd->execute([$version, DCMT_SCHEMA_VERSION_SETTING_KEY]);
            } else {
                $ins = $this->pdo->prepare("
                    INSERT INTO dcmt_settings (
                        dcmt_setting_key, dcmt_setting_name, dcmt_setting_value,
                        dcmt_setting_type, dcmt_category, dcmt_created_by
                    ) VALUES (?, 'Database Schema Version', ?, 'text', 'System', 'system')
                ");
                $ins->execute([DCMT_SCHEMA_VERSION_SETTING_KEY, $version]);
            }
        } catch (PDOException $e) {
            error_log('setSchemaVersion failed: ' . $e->getMessage());
        }
    }

    private function ensureIndex(string $indexName, string $createSql): void
    {
        try {
            $parts = explode(' ', $createSql, 4);
            $onPos = array_search('ON', array_map('strtoupper', $parts), true);
            $table = $onPos !== false && isset($parts[$onPos + 1]) ? trim($parts[$onPos + 1], '`') : '';
            if ($table === '') {
                $this->pdo->exec($createSql);
                return;
            }
            $check = $this->pdo->query("SHOW INDEX FROM `{$table}` WHERE Key_name = " . $this->pdo->quote($indexName));
            if ($check && $check->rowCount() === 0) {
                $this->pdo->exec($createSql);
                error_log("Added index {$indexName} on {$table}");
            }
        } catch (PDOException $e) {
            error_log("ensureIndex {$indexName}: " . $e->getMessage());
        }
    }

    private function ensureForeignKey(string $constraintName, string $alterSql): void
    {
        try {
            $table = 'dcmt_income';
            if (preg_match('/ALTER TABLE\s+`?(\w+)`?/i', $alterSql, $m)) {
                $table = $m[1];
            }
            $chk = $this->pdo->prepare("
                SELECT CONSTRAINT_NAME FROM information_schema.TABLE_CONSTRAINTS
                WHERE CONSTRAINT_SCHEMA = DATABASE()
                  AND TABLE_NAME = ?
                  AND CONSTRAINT_NAME = ?
                  AND CONSTRAINT_TYPE = 'FOREIGN KEY'
            ");
            $chk->execute([$table, $constraintName]);
            if ($chk->fetchColumn()) {
                return;
            }
            $this->pdo->exec($alterSql);
            error_log("Added foreign key {$constraintName} on {$table}");
        } catch (PDOException $e) {
            error_log("ensureForeignKey {$constraintName}: " . $e->getMessage());
        }
    }

    public function applyPerformanceIndexes(): void
    {
        $this->ensureIndex('idx_activity_log_created', 'CREATE INDEX idx_activity_log_created ON dcmt_activity_log (dcmt_created_at)');
        $this->ensureIndex('idx_income_tx_user', 'CREATE INDEX idx_income_tx_user ON dcmt_income (dcmt_transaction_date, dcmt_user_id)');
        $this->ensureIndex('idx_income_tx_patient', 'CREATE INDEX idx_income_tx_patient ON dcmt_income (dcmt_transaction_date, dcmt_patient_id)');
        $this->ensureIndex(
            'idx_payment_history_paid_range',
            'CREATE INDEX idx_payment_history_paid_range ON dcmt_income_payment_history (dcmt_paid_on, dcmt_income_id)'
        );
        $this->ensureIndex(
            'idx_payment_history_method',
            'CREATE INDEX idx_payment_history_method ON dcmt_income_payment_history (dcmt_payment_method_id)'
        );
        $this->ensureIndex('idx_patients_status_created', 'CREATE INDEX idx_patients_status_created ON dcmt_patients (dcmt_status, dcmt_created_at)');

        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_birthday_mmdd'");
            if (!$col || $col->rowCount() === 0) {
                $this->pdo->exec("
                    ALTER TABLE dcmt_patients
                    ADD COLUMN dcmt_birthday_mmdd CHAR(5) NULL AFTER dcmt_date_of_birth
                ");
                $this->pdo->exec("
                    UPDATE dcmt_patients
                    SET dcmt_birthday_mmdd = DATE_FORMAT(dcmt_date_of_birth, '%m-%d')
                    WHERE dcmt_date_of_birth IS NOT NULL
                ");
                error_log('Added dcmt_birthday_mmdd column to dcmt_patients');
            }
            $this->ensureIndex(
                'idx_patients_birthday_mmdd',
                'CREATE INDEX idx_patients_birthday_mmdd ON dcmt_patients (dcmt_birthday_mmdd, dcmt_status)'
            );
        } catch (PDOException $e) {
            error_log('applyPerformanceIndexes birthday_mmdd: ' . $e->getMessage());
        }
    }

    public function applyReferentialIntegrityForeignKeys(): void
    {
        $this->ensureForeignKey(
            'fk_income_patient',
            'ALTER TABLE dcmt_income ADD CONSTRAINT fk_income_patient FOREIGN KEY (dcmt_patient_id) REFERENCES dcmt_patients(dcmt_id) ON DELETE SET NULL'
        );
        $this->ensureForeignKey(
            'fk_income_user',
            'ALTER TABLE dcmt_income ADD CONSTRAINT fk_income_user FOREIGN KEY (dcmt_user_id) REFERENCES dcmt_users(dcmt_id) ON DELETE SET NULL'
        );
        $this->ensureForeignKey(
            'fk_patient_notes_patient',
            'ALTER TABLE dcmt_patient_notes ADD CONSTRAINT fk_patient_notes_patient FOREIGN KEY (dcmt_patient_id) REFERENCES dcmt_patients(dcmt_id) ON DELETE CASCADE'
        );

        try {
            $this->pdo->exec("
                UPDATE dcmt_reminders
                SET dcmt_assigned_user_id = (SELECT dcmt_id FROM dcmt_users WHERE dcmt_role = 'admin' ORDER BY dcmt_id ASC LIMIT 1)
                WHERE dcmt_assigned_user_id = 0 OR dcmt_assigned_user_id IS NULL
            ");
            $this->pdo->exec("
                UPDATE dcmt_reminders
                SET dcmt_created_by_user_id = dcmt_assigned_user_id
                WHERE dcmt_created_by_user_id = 0 OR dcmt_created_by_user_id IS NULL
            ");
        } catch (PDOException $e) {
            error_log('applyReferentialIntegrityForeignKeys reminder user cleanup: ' . $e->getMessage());
        }

        $this->ensureForeignKey(
            'fk_reminders_assigned_user',
            'ALTER TABLE dcmt_reminders ADD CONSTRAINT fk_reminders_assigned_user FOREIGN KEY (dcmt_assigned_user_id) REFERENCES dcmt_users(dcmt_id) ON DELETE RESTRICT'
        );
        $this->ensureForeignKey(
            'fk_reminders_created_by_user',
            'ALTER TABLE dcmt_reminders ADD CONSTRAINT fk_reminders_created_by_user FOREIGN KEY (dcmt_created_by_user_id) REFERENCES dcmt_users(dcmt_id) ON DELETE RESTRICT'
        );
        $this->ensureForeignKey(
            'fk_income_payment_history_method',
            'ALTER TABLE dcmt_income_payment_history ADD CONSTRAINT fk_income_payment_history_method FOREIGN KEY (dcmt_payment_method_id) REFERENCES dcmt_income_payment_methods(dcmt_id) ON DELETE SET NULL'
        );

        $this->addMessagingForeignKeys();
    }

    public function addSecurityTables(): void
    {
        try {
            $auditCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_audit_log'");
            if (!$auditCheck || $auditCheck->rowCount() === 0) {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS dcmt_audit_log (
                        dcmt_id BIGINT AUTO_INCREMENT PRIMARY KEY,
                        dcmt_user_id INT NULL,
                        dcmt_username VARCHAR(50) NULL,
                        dcmt_action VARCHAR(32) NOT NULL,
                        dcmt_resource_type VARCHAR(50) NULL,
                        dcmt_resource_id INT NULL,
                        dcmt_ip VARCHAR(45) NULL,
                        dcmt_user_agent VARCHAR(255) NULL,
                        dcmt_created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_audit_resource (dcmt_resource_type, dcmt_resource_id),
                        INDEX idx_audit_user_time (dcmt_user_id, dcmt_created_at),
                        INDEX idx_audit_created (dcmt_created_at)
                    )
                ");
                error_log('Created dcmt_audit_log table');
            }

            $loginCheck = $this->pdo->query("SHOW TABLES LIKE 'dcmt_login_attempts'");
            if (!$loginCheck || $loginCheck->rowCount() === 0) {
                $this->pdo->exec("
                    CREATE TABLE IF NOT EXISTS dcmt_login_attempts (
                        dcmt_id INT AUTO_INCREMENT PRIMARY KEY,
                        dcmt_ip VARCHAR(45) NOT NULL,
                        dcmt_username VARCHAR(100) NULL,
                        dcmt_attempted_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                        INDEX idx_login_attempts_ip_time (dcmt_ip, dcmt_attempted_at)
                    )
                ");
                error_log('Created dcmt_login_attempts table');
            }
        } catch (PDOException $e) {
            error_log('addSecurityTables: ' . $e->getMessage());
        }
    }

    public function addComplianceSchema(): void
    {
        try {
            if (!$this->columnExists('dcmt_patients', 'dcmt_privacy_notice_accepted_at')) {
                $this->pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_privacy_notice_accepted_at DATETIME NULL AFTER dcmt_status");
            }
            if (!$this->columnExists('dcmt_patients', 'dcmt_consent_marketing')) {
                $this->pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_consent_marketing TINYINT(1) NOT NULL DEFAULT 0 AFTER dcmt_privacy_notice_accepted_at");
            }
            if (!$this->columnExists('dcmt_patients', 'dcmt_anonymized_at')) {
                $this->pdo->exec("ALTER TABLE dcmt_patients ADD COLUMN dcmt_anonymized_at DATETIME NULL AFTER dcmt_consent_marketing");
            }
            if (!$this->columnExists('dcmt_users', 'dcmt_must_change_password')) {
                $this->pdo->exec("ALTER TABLE dcmt_users ADD COLUMN dcmt_must_change_password TINYINT(1) NOT NULL DEFAULT 0 AFTER dcmt_status");
            }
        } catch (PDOException $e) {
            error_log('addComplianceSchema: ' . $e->getMessage());
        }
    }

    private function columnExists(string $table, string $column): bool
    {
        try {
            $stmt = $this->pdo->query("SHOW COLUMNS FROM `{$table}` LIKE " . $this->pdo->quote($column));
            return $stmt && $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            return false;
        }
    }

    public function addMessagingForeignKeys(): void
    {
        $this->ensureForeignKey(
            'fk_participants_conversation',
            'ALTER TABLE dcmt_conversation_participants ADD CONSTRAINT fk_participants_conversation FOREIGN KEY (dcmt_conversation_id) REFERENCES dcmt_conversations(dcmt_id) ON DELETE CASCADE'
        );
        $this->ensureForeignKey(
            'fk_participants_user',
            'ALTER TABLE dcmt_conversation_participants ADD CONSTRAINT fk_participants_user FOREIGN KEY (dcmt_user_id) REFERENCES dcmt_users(dcmt_id) ON DELETE CASCADE'
        );
        $this->ensureForeignKey(
            'fk_messages_conversation',
            'ALTER TABLE dcmt_messages ADD CONSTRAINT fk_messages_conversation FOREIGN KEY (dcmt_conversation_id) REFERENCES dcmt_conversations(dcmt_id) ON DELETE CASCADE'
        );
        $this->ensureForeignKey(
            'fk_messages_sender_user',
            'ALTER TABLE dcmt_messages ADD CONSTRAINT fk_messages_sender_user FOREIGN KEY (dcmt_sender_user_id) REFERENCES dcmt_users(dcmt_id) ON DELETE SET NULL'
        );
    }

    public function migratePatientOdontogramToDedicatedTable(): void
    {
        try {
            $tbl = $this->pdo->query("SHOW TABLES LIKE 'dcmt_patient_odontogram'");
            if (!$tbl || $tbl->rowCount() === 0) {
                $this->pdo->exec("
                    CREATE TABLE dcmt_patient_odontogram (
                        dcmt_patient_id INT NOT NULL PRIMARY KEY,
                        dcmt_data LONGTEXT NULL,
                        dcmt_updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
                        INDEX idx_patient_odontogram_updated (dcmt_updated_at)
                    )
                ");
                error_log('Created dcmt_patient_odontogram table');
            }

            $legacyCol = $this->pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_odontogram_data'");
            if ($legacyCol && $legacyCol->rowCount() > 0) {
                $this->pdo->exec("
                    INSERT INTO dcmt_patient_odontogram (dcmt_patient_id, dcmt_data)
                    SELECT dcmt_id, dcmt_odontogram_data
                    FROM dcmt_patients
                    WHERE dcmt_odontogram_data IS NOT NULL
                      AND TRIM(dcmt_odontogram_data) <> ''
                      AND dcmt_odontogram_data <> '{}'
                    ON DUPLICATE KEY UPDATE
                        dcmt_data = IF(
                            dcmt_patient_odontogram.dcmt_data IS NULL OR TRIM(dcmt_patient_odontogram.dcmt_data) = '' OR dcmt_patient_odontogram.dcmt_data = '{}',
                            VALUES(dcmt_data),
                            dcmt_patient_odontogram.dcmt_data
                        )
                ");
            }

            $this->ensureForeignKey(
                'fk_patient_odontogram_patient',
                'ALTER TABLE dcmt_patient_odontogram ADD CONSTRAINT fk_patient_odontogram_patient FOREIGN KEY (dcmt_patient_id) REFERENCES dcmt_patients(dcmt_id) ON DELETE CASCADE'
            );

            $this->dropPatientsOdontogramLegacyColumn();
        } catch (PDOException $e) {
            error_log('migratePatientOdontogramToDedicatedTable: ' . $e->getMessage());
        }
    }

    /**
     * Remove dcmt_odontogram_data from dcmt_patients after data lives in dcmt_patient_odontogram.
     */
    public function dropPatientsOdontogramLegacyColumn(): void
    {
        try {
            $legacyCol = $this->pdo->query("SHOW COLUMNS FROM dcmt_patients LIKE 'dcmt_odontogram_data'");
            if (!$legacyCol || $legacyCol->rowCount() === 0) {
                return;
            }

            $tbl = $this->pdo->query("SHOW TABLES LIKE 'dcmt_patient_odontogram'");
            if (!$tbl || $tbl->rowCount() === 0) {
                error_log('dropPatientsOdontogramLegacyColumn: skipped — dcmt_patient_odontogram missing');
                return;
            }

            $this->pdo->exec("
                INSERT INTO dcmt_patient_odontogram (dcmt_patient_id, dcmt_data)
                SELECT dcmt_id, dcmt_odontogram_data
                FROM dcmt_patients
                WHERE dcmt_odontogram_data IS NOT NULL
                  AND TRIM(dcmt_odontogram_data) <> ''
                  AND dcmt_odontogram_data <> '{}'
                ON DUPLICATE KEY UPDATE
                    dcmt_data = IF(
                        dcmt_patient_odontogram.dcmt_data IS NULL OR TRIM(dcmt_patient_odontogram.dcmt_data) = '' OR dcmt_patient_odontogram.dcmt_data = '{}',
                        VALUES(dcmt_data),
                        dcmt_patient_odontogram.dcmt_data
                    )
            ");

            $this->pdo->exec('ALTER TABLE dcmt_patients DROP COLUMN dcmt_odontogram_data');
            error_log('Dropped legacy dcmt_odontogram_data column from dcmt_patients');
        } catch (PDOException $e) {
            error_log('dropPatientsOdontogramLegacyColumn: ' . $e->getMessage());
        }
    }

    public function addIncomePaymentHistoryPaymentMethodColumn(): void
    {
        try {
            $col = $this->pdo->query("SHOW COLUMNS FROM dcmt_income_payment_history LIKE 'dcmt_payment_method_id'");
            if (!$col || $col->rowCount() === 0) {
                $this->pdo->exec("
                    ALTER TABLE dcmt_income_payment_history
                    ADD COLUMN dcmt_payment_method_id INT NULL AFTER dcmt_paid_on
                ");
                error_log('Added dcmt_payment_method_id to dcmt_income_payment_history');
            }

            $this->pdo->exec("
                UPDATE dcmt_income_payment_history iph
                INNER JOIN dcmt_income i ON i.dcmt_id = iph.dcmt_income_id
                SET iph.dcmt_payment_method_id = i.dcmt_payment_method_id
                WHERE iph.dcmt_payment_method_id IS NULL
                  AND i.dcmt_payment_method_id IS NOT NULL
            ");

            $this->pdo->exec("
                UPDATE dcmt_income_payment_history
                SET dcmt_payment_method_id = CAST(JSON_UNQUOTE(JSON_EXTRACT(dcmt_notes, '$.payment_method_id')) AS UNSIGNED)
                WHERE dcmt_payment_method_id IS NULL
                  AND dcmt_notes IS NOT NULL
                  AND JSON_VALID(dcmt_notes)
                  AND JSON_EXTRACT(dcmt_notes, '$.payment_method_id') IS NOT NULL
            ");
        } catch (PDOException $e) {
            error_log('addIncomePaymentHistoryPaymentMethodColumn: ' . $e->getMessage());
        }
    }

    public function migrateOdontogramConfigSchema(): void
    {
        try {
            require_once __DIR__ . '/../includes/odontogram_treatments.php';
            dcmt_ensure_odontogram_treatments_table($this->pdo);

            if ($this->columnExists('dcmt_odontogram_treatments', 'dcmt_zone')) {
                $this->pdo->exec('ALTER TABLE dcmt_odontogram_treatments DROP COLUMN dcmt_zone');
                error_log('Dropped dcmt_zone from dcmt_odontogram_treatments');
            }
            if ($this->columnExists('dcmt_odontogram_treatments', 'dcmt_tooth_state')) {
                $this->pdo->exec('ALTER TABLE dcmt_odontogram_treatments DROP COLUMN dcmt_tooth_state');
                error_log('Dropped dcmt_tooth_state from dcmt_odontogram_treatments');
            }
            if ($this->columnExists('dcmt_odontogram_problem_states', 'dcmt_dimmed')) {
                $this->pdo->exec('ALTER TABLE dcmt_odontogram_problem_states DROP COLUMN dcmt_dimmed');
                error_log('Dropped dcmt_dimmed from dcmt_odontogram_problem_states');
            }
        } catch (PDOException $e) {
            error_log('migrateOdontogramConfigSchema: ' . $e->getMessage());
        }
    }

    public function applySchemaUpgrades(): void
    {
        if (!defined('DCMT_SCHEMA_VERSION')) {
            require_once __DIR__ . '/schema_version.php';
        }
        $stored = $this->getSchemaVersion();
        if (version_compare($stored, DCMT_SCHEMA_VERSION, '>=')) {
            return;
        }

        $this->addIncomePaymentHistoryPaymentMethodColumn();
        $this->addSecurityTables();
        $this->addComplianceSchema();
        $this->migratePatientOdontogramToDedicatedTable();
        $this->dropPatientsOdontogramLegacyColumn();
        $this->addMessagingTables();
        $this->applyReferentialIntegrityForeignKeys();
        $this->migrateOdontogramConfigSchema();
        $this->setSchemaVersion(DCMT_SCHEMA_VERSION);
        error_log('Schema upgraded to ' . DCMT_SCHEMA_VERSION . ' (from ' . $stored . ')');
    }

    /**
     * Apply incremental schema changes (columns, tables, indexes).
     * Run from CLI only: php migrations/run_schema_migrations.php
     * Not executed on normal web requests.
     */
    public function runSchemaMigrations(): bool
    {
        if (!defined('DCMT_SCHEMA_VERSION')) {
            require_once __DIR__ . '/schema_version.php';
        }

        try {
            $this->checkAndCreateMissingTables();

            $this->addPaymentAmountFields();
            $this->addSeparatePaymentFields();
            $this->addServiceIdField();
            $this->addServiceAmountFields();
            $this->addProductAmountFields();
            $this->addProductTypeField();
            $this->addInventoryBrandField();
            $this->addIncomePaymentHistoryTable();
            $this->addDashboardSummaryToggleField();
            $this->addAssistantRoleToUsers();
            $this->addDoctorColorCodeField();
            $this->addCashflowExpenseFields();
            $this->addCashflowDenominationTypeField();
            $this->ensurePatientsTable();
            $this->addPatientNotesTable();
            $this->addRemindersTable();
            $this->addMessagingTables();
            $this->addSecurityTables();
            $this->addComplianceSchema();
            $this->addPatientColumns();
            $this->addIncomeNoteField();
            $this->addIncomePatientIdField();
            $this->addDoctorCashFields();
            $this->addAppointmentTables();
            $this->addAppointmentOperatoryFields();
            $this->addAppointmentActualTimeFields();
            $this->ensureOperatoriesAreGlobal();
            $this->addDoctorGoalMetricField();
            $this->ensureOwnerDoctorUserIdsSetting();
            $this->createIndexes();
            $this->applySchemaUpgrades();
            $this->migrateOdontogramConfigSchema();
            $this->applyPerformanceIndexes();
            $this->dropPatientsOdontogramLegacyColumn();

            return true;
        } catch (PDOException $e) {
            error_log('runSchemaMigrations failed: ' . $e->getMessage());
            return false;
        }
    }
}

// Initialize database (connection only; schema changes via CLI migrations).
$dcmt_db = new Dcmt_Database();
$dcmt_pdo = $dcmt_db->getConnection();

require_once __DIR__ . '/../includes/dcmt_owner_doctor.php';

if (defined('DCMT_RUN_SCHEMA_MIGRATIONS') && DCMT_RUN_SCHEMA_MIGRATIONS) {
    return;
}

try {
    $dcmt_pdo->query('SELECT 1 FROM dcmt_users LIMIT 1');
} catch (PDOException $e) {
    $dcmt_db->createTables();
    $dcmt_db->insertDefaultData();
    $dcmt_db->createIndexes();
}

// Ensure newer feature tables exist on existing installs (header reminders, messaging).
try {
    $dcmt_db->addRemindersTable();
    $dcmt_db->addMessagingTables();
    $dcmt_db->addSecurityTables();
    $dcmt_db->addComplianceSchema();
} catch (PDOException $e) {
    error_log('Feature table ensure failed: ' . $e->getMessage());
}
?>
