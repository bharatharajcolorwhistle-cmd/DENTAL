<?php
/**
 * Patient privacy, export, and anonymization (LFPDPPP / ARCO).
 */

if (!function_exists('dcmt_schema_has_column')) {
    function dcmt_schema_has_column(PDO $pdo, string $table, string $column): bool
    {
        static $cache = [];
        $key = $table . '.' . $column;
        if (array_key_exists($key, $cache)) {
            return $cache[$key];
        }
        try {
            $stmt = $pdo->prepare("SHOW COLUMNS FROM `{$table}` LIKE ?");
            $stmt->execute([$column]);
            $cache[$key] = $stmt->rowCount() > 0;
        } catch (PDOException $e) {
            $cache[$key] = false;
        }
        return $cache[$key];
    }
}

if (!function_exists('dcmt_patient_retention_years')) {
    function dcmt_patient_retention_years(): int
    {
        $years = (int) dcmt_env('DCMT_PATIENT_RETENTION_YEARS', '5');
        return $years > 0 ? $years : 5;
    }
}

if (!function_exists('dcmt_patient_is_anonymized')) {
    function dcmt_patient_is_anonymized(array $patient): bool
    {
        return !empty($patient['dcmt_anonymized_at']);
    }
}

if (!function_exists('dcmt_anonymize_patient')) {
    /**
     * Replace identifiable patient data while retaining the record ID for financial/clinical linkage.
     *
     * @return array{success: bool, message: string}
     */
    function dcmt_anonymize_patient(PDO $pdo, int $patient_id): array
    {
        if ($patient_id <= 0) {
            return ['success' => false, 'message' => trans('patient', 'invalid_id')];
        }

        if (!dcmt_schema_has_column($pdo, 'dcmt_patients', 'dcmt_anonymized_at')) {
            return ['success' => false, 'message' => trans('patient', 'compliance_not_ready')];
        }

        $stmt = $pdo->prepare('SELECT * FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1');
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$patient) {
            return ['success' => false, 'message' => trans('patient', 'not_found')];
        }
        if (dcmt_patient_is_anonymized($patient)) {
            return ['success' => false, 'message' => trans('patient', 'already_anonymized')];
        }

        $token = 'ANON-' . $patient_id . '-' . substr(bin2hex(random_bytes(4)), 0, 8);
        $label = 'Anonymized Patient #' . $patient_id;

        try {
            $pdo->beginTransaction();

            $update_sql = "
                UPDATE dcmt_patients SET
                    dcmt_first_name = ?,
                    dcmt_fathers_last_name = NULL,
                    dcmt_mothers_last_name = NULL,
                    dcmt_patient_name = ?,
                    dcmt_email = NULL,
                    dcmt_phone = ?,
                    dcmt_address = NULL,
                    dcmt_medications = NULL,
                    dcmt_allergies = NULL,
                    dcmt_emergency_contact_name = NULL,
                    dcmt_emergency_contact_relation = NULL,
                    dcmt_emergency_contact_phone = NULL,
                    dcmt_notes = NULL,
                    dcmt_date_of_birth = NULL,
                    dcmt_age = NULL,
                    dcmt_height_cm = NULL,
                    dcmt_weight_kg = NULL,
                    dcmt_status = 'inactive',
                    dcmt_anonymized_at = NOW(),
                    dcmt_updated_at = CURRENT_TIMESTAMP
            ";
            if (dcmt_schema_has_column($pdo, 'dcmt_patients', 'dcmt_birthday_mmdd')) {
                $update_sql = str_replace(
                    "dcmt_weight_kg = NULL,",
                    "dcmt_weight_kg = NULL,\n                    dcmt_birthday_mmdd = NULL,",
                    $update_sql
                );
            }
            $update_sql .= ' WHERE dcmt_id = ?';

            $update = $pdo->prepare($update_sql);
            $update->execute([$label, $label, '0000000000', $patient_id]);

            $pdo->prepare("
                UPDATE dcmt_patient_notes
                SET dcmt_topic = '[redacted]', dcmt_note_text = '[redacted clinical note removed during anonymization]'
                WHERE dcmt_patient_id = ?
            ")->execute([$patient_id]);

            if (dcmt_schema_has_column($pdo, 'dcmt_patient_odontogram', 'dcmt_data')) {
                $pdo->prepare("UPDATE dcmt_patient_odontogram SET dcmt_data = '{}' WHERE dcmt_patient_id = ?")
                    ->execute([$patient_id]);
            }

            $pdo->commit();

            dcmt_audit('anonymize', 'patient', $patient_id);
            dcmt_log_activity('Patient anonymized', "Patient ID: {$patient_id}, token: {$token}");

            return ['success' => true, 'message' => trans('patient', 'anonymize_success')];
        } catch (PDOException $e) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }
            error_log('dcmt_anonymize_patient: ' . $e->getMessage());
            return ['success' => false, 'message' => trans('patient', 'anonymize_failed')];
        }
    }
}

if (!function_exists('dcmt_build_patient_export_bundle')) {
    /**
     * Build a portable JSON export for a single patient (ARCO access request).
     */
    function dcmt_build_patient_export_bundle(PDO $pdo, int $patient_id): ?array
    {
        if ($patient_id <= 0) {
            return null;
        }

        require_once __DIR__ . '/patient_odontogram.php';

        $stmt = $pdo->prepare('SELECT * FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1');
        $stmt->execute([$patient_id]);
        $patient = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$patient) {
            return null;
        }

        unset($patient['dcmt_odontogram_data']);

        $notes = [];
        $notes_stmt = $pdo->prepare('SELECT * FROM dcmt_patient_notes WHERE dcmt_patient_id = ? ORDER BY dcmt_created_at DESC');
        $notes_stmt->execute([$patient_id]);
        $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);

        $appointments = [];
        $appt_stmt = $pdo->prepare('
            SELECT a.dcmt_id, a.dcmt_start_at, a.dcmt_end_at, a.dcmt_status, a.dcmt_notes,
                   d.dcmt_full_name AS doctor_name, o.dcmt_name AS operatory_name
            FROM dcmt_appointments a
            LEFT JOIN dcmt_users d ON d.dcmt_id = a.dcmt_doctor_id
            LEFT JOIN dcmt_operatories o ON o.dcmt_id = a.dcmt_operatory_id
            WHERE a.dcmt_patient_id = ?
            ORDER BY a.dcmt_start_at DESC
        ');
        $appt_stmt->execute([$patient_id]);
        $appointments = $appt_stmt->fetchAll(PDO::FETCH_ASSOC);

        $income = [];
        if (dcmt_schema_has_column($pdo, 'dcmt_income', 'dcmt_patient_id')) {
            $income_stmt = $pdo->prepare('
                SELECT dcmt_id, dcmt_patient_name, dcmt_type, dcmt_amount, dcmt_paid_amount,
                       dcmt_pending_amount, dcmt_transaction_date, dcmt_created_at
                FROM dcmt_income
                WHERE dcmt_patient_id = ?
                ORDER BY dcmt_transaction_date DESC
            ');
            $income_stmt->execute([$patient_id]);
            $income = $income_stmt->fetchAll(PDO::FETCH_ASSOC);
        }

        $odontogram_json = dcmt_load_patient_odontogram_json($pdo, $patient_id);

        return [
            'exported_at' => dcmt_get_current_datetime(DCMT_DATETIME_FORMAT),
            'export_format_version' => '1.0',
            'patient' => $patient,
            'clinical_notes' => $notes,
            'odontogram' => $odontogram_json !== '' ? json_decode($odontogram_json, true) : null,
            'appointments' => $appointments,
            'income_records' => $income,
            'retention_policy_years' => dcmt_patient_retention_years(),
        ];
    }
}
