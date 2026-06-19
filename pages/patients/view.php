<?php
/**
 * View Patient Page
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_referral_source.php';
require_once __DIR__ . '/../../includes/patient_compliance.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    $login_url = DCMT_APP_URL . '/auth/login.php';
    dcmt_redirect($login_url);
    exit();
}

$patient_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$dcmt_current_user = dcmt_get_current_user();
$dcmt_is_assistant = ($dcmt_current_user['dcmt_role'] ?? '') === 'assistant';
$dcmt_is_limited_doctor = ($dcmt_current_user['dcmt_role'] ?? '') === 'doctor' && !dcmt_is_admin();
$dcmt_show_patient_income_stats = !$dcmt_is_assistant && !$dcmt_is_limited_doctor;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

require_once __DIR__ . '/../../includes/patient_odontogram.php';

try {
    $patient_cols = dcmt_patient_select_columns_without_odontogram('p', $dcmt_pdo);
    $stmt = $dcmt_pdo->prepare("SELECT {$patient_cols} FROM dcmt_patients p WHERE p.dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('index.php');
        exit();
    }
    dcmt_audit('view', 'patient', $patient_id);
} catch (PDOException $e) {
    error_log("Error fetching patient: " . $e->getMessage());
    dcmt_show_message(trans('patient', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

// Safe field defaults to avoid notices on missing keys
$status_safe = ($patient['dcmt_status'] ?? '') === 'active' ? 'active' : 'inactive';
$patient_full_name = $patient['dcmt_patient_name'] ?? '';

$dcmt_can_book_appointment = dcmt_is_admin() || in_array($dcmt_current_user['dcmt_role'] ?? '', ['staff', 'assistant'], true);
$dcmt_is_admin_user = dcmt_is_admin();
$dcmt_patient_anonymized = dcmt_patient_is_anonymized($patient);
$dcmt_export_csrf = dcmt_generate_csrf_token();

/** Age from date of birth (whole years); null if missing or invalid. */
$patient_age_from_dob = null;
$dob_raw = trim((string) ($patient['dcmt_date_of_birth'] ?? ''));
if ($dob_raw !== '') {
    try {
        $dob_part = substr($dob_raw, 0, 10);
        $birth = new DateTimeImmutable($dob_part);
        $today = new DateTimeImmutable('today');
        if ($birth <= $today) {
            $patient_age_from_dob = $birth->diff($today)->y;
        }
    } catch (Exception $e) {
        $patient_age_from_dob = null;
    }
}

if (!function_exists('dcmt_phone_local_display_digits')) {
    /**
     * Return national digits for display (no country code). WhatsApp still uses full digits.
     */
    function dcmt_phone_local_display_digits(string $digits): string {
        $digits = preg_replace('/\D+/', '', $digits);
        if ($digits === '' || strlen($digits) <= 10) {
            return $digits;
        }
        if (strlen($digits) > 10 && strpos($digits, '00') === 0) {
            return dcmt_phone_local_display_digits(substr($digits, 2));
        }
        if (preg_match('/^1(\d{10})$/', $digits, $m)) {
            return $m[1];
        }
        if (preg_match('/^52(\d{10})$/', $digits, $m)) {
            return $m[1];
        }
        if (preg_match('/^521(\d{10})$/', $digits, $m)) {
            return $m[1];
        }
        return $digits;
    }
}

// Patient notes
$patient_notes = [];
try {
    $stmt = $dcmt_pdo->prepare("
        SELECT pn.*, u.dcmt_full_name as created_by_name
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_users u ON pn.dcmt_created_by = u.dcmt_username
        WHERE pn.dcmt_patient_id = ?
        ORDER BY pn.dcmt_note_date DESC, pn.dcmt_created_at DESC
    ");
    $stmt->execute([$patient_id]);
    $notes_result = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach ($notes_result as $note) {
        $patient_notes[] = [
            'dcmt_id' => $note['dcmt_id'],
            'dcmt_topic' => $note['dcmt_topic'] ?? '',
            'dcmt_note_text' => $note['dcmt_note_text'] ?? '',
            'dcmt_note_date' => $note['dcmt_note_date'] ?? $note['dcmt_created_at'],
            'dcmt_created_at' => $note['dcmt_created_at'],
            'dcmt_created_by' => $note['dcmt_created_by'] ?? '',
            'created_by_name' => $note['created_by_name'] ?? $note['dcmt_created_by'] ?? ''
        ];
    }
} catch (PDOException $e) {
    error_log("Error fetching patient notes: " . $e->getMessage());
}

$notes_per_page = 10;
$notes_page = isset($_GET['notes_page']) ? max(1, (int) $_GET['notes_page']) : 1;
$notes_total = count($patient_notes);
$notes_total_pages = max(1, (int) ceil($notes_total / $notes_per_page));
if ($notes_page > $notes_total_pages) {
    $notes_page = $notes_total_pages;
}
$notes_offset = ($notes_page - 1) * $notes_per_page;
$patient_notes_paginated = array_slice($patient_notes, $notes_offset, $notes_per_page);

$next_appointment = null;
try {
    $next_stmt = $dcmt_pdo->prepare("
        SELECT
            a.dcmt_id,
            a.dcmt_start_at,
            a.dcmt_end_at,
            a.dcmt_status,
            a.dcmt_reason,
            u.dcmt_full_name AS doctor_name
        FROM dcmt_appointments a
        LEFT JOIN dcmt_users u ON a.dcmt_doctor_id = u.dcmt_id
        WHERE a.dcmt_patient_id = ?
          AND a.dcmt_end_at >= NOW()
          AND a.dcmt_status NOT IN ('cancelled', 'completed', 'no_show')
        ORDER BY a.dcmt_start_at ASC
        LIMIT 1
    ");
    $next_stmt->execute([$patient_id]);
    $next_appointment = $next_stmt->fetch(PDO::FETCH_ASSOC) ?: null;
} catch (PDOException $e) {
    error_log("Error fetching next appointment: " . $e->getMessage());
}

// Treatment history and statistics (hidden for assistant role)
$income_filter_sql = "(i.dcmt_patient_id = ? OR (i.dcmt_patient_id IS NULL AND i.dcmt_patient_name = ?))";
$income_filter_params = [$patient_id, $patient_full_name];

$patient_total_income = 0;
$patient_total_visits = 0;
$payment_methods_map = [];
$services_per_page = 10;
$services_page = isset($_GET['services_page']) ? max(1, (int) $_GET['services_page']) : 1;
$services_total = 0;
$services_total_pages = 1;
$services_offset = 0;
$service_details = [];
$products_per_page = 10;
$products_page = isset($_GET['products_page']) ? max(1, (int) $_GET['products_page']) : 1;
$products_total = 0;
$products_total_pages = 1;
$products_offset = 0;
$product_details = [];
$payments_per_page = 10;
$payments_page = isset($_GET['payments_page']) ? max(1, (int) $_GET['payments_page']) : 1;
$payments_total = 0;
$payments_total_pages = 1;
$payments_offset = 0;
$payment_history_rows = [];

if (!$dcmt_is_assistant) {
    if ($dcmt_show_patient_income_stats) {
        try {
            $stats_sql = "
                SELECT 
                    COUNT(*) as visits,
                    COALESCE(SUM(COALESCE(i.dcmt_total_paid_amount, i.dcmt_paid_amount, 0)), 0) as total_income
                FROM dcmt_income i
                WHERE (i.dcmt_patient_id = ? OR (i.dcmt_patient_id IS NULL AND i.dcmt_patient_name = ?))
            ";
            $stats_stmt = $dcmt_pdo->prepare($stats_sql);
            $stats_stmt->execute([$patient_id, $patient_full_name]);
            $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
            if ($stats) {
                $patient_total_visits = (int) ($stats['visits'] ?? 0);
                $patient_total_income = (float) ($stats['total_income'] ?? 0);
            }
        } catch (PDOException $e) {
            error_log("Error fetching patient income statistics: " . $e->getMessage());
        }
    }

    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active'");
        $stmt->execute();
        $payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($payment_methods as $method) {
            $payment_methods_map[(int)$method['dcmt_id']] = $method['dcmt_name'];
        }
    } catch (PDOException $e) {
        error_log("Error fetching payment methods: " . $e->getMessage());
    }

    try {
        $count_stmt = $dcmt_pdo->prepare("
            SELECT COUNT(*)
            FROM dcmt_income_breakdown ib
            INNER JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
            WHERE $income_filter_sql AND ib.dcmt_line_type = 'service'
        ");
        $count_stmt->execute($income_filter_params);
        $services_total = (int)$count_stmt->fetchColumn();
        $services_total_pages = max(1, (int)ceil($services_total / $services_per_page));
        if ($services_page > $services_total_pages) {
            $services_page = $services_total_pages;
        }
        $services_offset = ($services_page - 1) * $services_per_page;

        $stmt = $dcmt_pdo->prepare("
            SELECT
                i.dcmt_transaction_date,
                u.dcmt_full_name AS doctor_name,
                s.dcmt_name AS service_name,
                ib.dcmt_label,
                ib.dcmt_quantity,
                ib.dcmt_unit_price,
                ib.dcmt_line_total
            FROM dcmt_income_breakdown ib
            INNER JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
            LEFT JOIN dcmt_services s ON ib.dcmt_reference_id = s.dcmt_id
            LEFT JOIN dcmt_users u ON ib.dcmt_user_id = u.dcmt_id
            WHERE $income_filter_sql AND ib.dcmt_line_type = 'service'
            ORDER BY i.dcmt_transaction_date DESC, i.dcmt_created_at DESC, ib.dcmt_line_no ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($income_filter_params, [$services_per_page, $services_offset]));
        $service_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching service details: " . $e->getMessage());
    }

    try {
        $count_stmt = $dcmt_pdo->prepare("
            SELECT COUNT(*)
            FROM dcmt_income_breakdown ib
            INNER JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
            WHERE $income_filter_sql AND ib.dcmt_line_type = 'product'
        ");
        $count_stmt->execute($income_filter_params);
        $products_total = (int)$count_stmt->fetchColumn();
        $products_total_pages = max(1, (int)ceil($products_total / $products_per_page));
        if ($products_page > $products_total_pages) {
            $products_page = $products_total_pages;
        }
        $products_offset = ($products_page - 1) * $products_per_page;

        $stmt = $dcmt_pdo->prepare("
            SELECT
                i.dcmt_transaction_date,
                inv.dcmt_name AS product_name,
                ib.dcmt_label,
                ib.dcmt_quantity,
                ib.dcmt_unit_price,
                ib.dcmt_line_total
            FROM dcmt_income_breakdown ib
            INNER JOIN dcmt_income i ON ib.dcmt_id = i.dcmt_id
            LEFT JOIN dcmt_inventory inv ON ib.dcmt_inventory_id = inv.dcmt_id
            WHERE $income_filter_sql AND ib.dcmt_line_type = 'product'
            ORDER BY i.dcmt_transaction_date DESC, i.dcmt_created_at DESC, ib.dcmt_line_no ASC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($income_filter_params, [$products_per_page, $products_offset]));
        $product_details = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (PDOException $e) {
        error_log("Error fetching product details: " . $e->getMessage());
    }

    try {
        $count_stmt = $dcmt_pdo->prepare("
            SELECT COUNT(*)
            FROM dcmt_income_payment_history iph
            INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
            WHERE $income_filter_sql
        ");
        $count_stmt->execute($income_filter_params);
        $payments_total = (int)$count_stmt->fetchColumn();
        $payments_total_pages = max(1, (int)ceil($payments_total / $payments_per_page));
        if ($payments_page > $payments_total_pages) {
            $payments_page = $payments_total_pages;
        }
        $payments_offset = ($payments_page - 1) * $payments_per_page;

        $stmt = $dcmt_pdo->prepare("
            SELECT
                iph.dcmt_paid_on,
                iph.dcmt_amount,
                iph.dcmt_recorded_by,
                iph.dcmt_notes,
                ru.dcmt_full_name AS recorded_by_name
            FROM dcmt_income_payment_history iph
            INNER JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
            LEFT JOIN dcmt_users ru ON iph.dcmt_recorded_by COLLATE utf8mb4_general_ci = ru.dcmt_username
            WHERE $income_filter_sql
            ORDER BY iph.dcmt_paid_on DESC, iph.dcmt_id DESC
            LIMIT ? OFFSET ?
        ");
        $stmt->execute(array_merge($income_filter_params, [$payments_per_page, $payments_offset]));
        $payment_history_rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        foreach ($payment_history_rows as &$payment) {
            $payment_method_id = null;
            $payment_method_name = null;

            if (!empty($payment['dcmt_notes'])) {
                $notes_data = json_decode($payment['dcmt_notes'], true);
                if (is_array($notes_data) && isset($notes_data['payment_method_id'])) {
                    $payment_method_id = (int)$notes_data['payment_method_id'];
                    if (isset($payment_methods_map[$payment_method_id])) {
                        $payment_method_name = $payment_methods_map[$payment_method_id];
                    }
                }
            }

            $payment['payment_method_id'] = $payment_method_id;
            $payment['payment_method_name'] = $payment_method_name;
        }
        unset($payment);
    } catch (PDOException $e) {
        error_log("Error fetching payment history: " . $e->getMessage());
    }
}

$dcmt_odontogram_patient_id = $patient_id;
$dcmt_odontogram_initial_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
if ($dcmt_odontogram_initial_json === '') {
    $dcmt_odontogram_initial_json = '{}';
}
$dcmt_odontogram_has_data = dcmt_patient_odontogram_has_data($dcmt_odontogram_initial_json);
$dcmt_odontogram_record = $dcmt_odontogram_has_data
    ? dcmt_fetch_patient_odontogram_record($dcmt_pdo, $patient_id)
    : null;

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header d-flex justify-content-between align-items-center">
        <div class="d-flex align-items-center gap-2">
            <i class="fas fa-user-injured dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('patient', 'patient_profile'); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?></small>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <?php if ($dcmt_is_admin_user && !$dcmt_patient_anonymized): ?>
                <a href="export_data.php?id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3" title="<?php echo trans('patient', 'export_data'); ?>">
                    <i class="fas fa-file-export me-1"></i><?php echo trans('patient', 'export_data'); ?>
                </a>
                <button type="button" class="btn btn-link dcmt-add-form-view-all-link me-3 p-0 border-0" id="dcmtAnonymizePatientBtn"
                        data-patient-id="<?php echo $patient_id; ?>"
                        data-csrf="<?php echo htmlspecialchars($dcmt_export_csrf); ?>">
                    <i class="fas fa-user-slash me-1"></i><?php echo trans('patient', 'anonymize_patient'); ?>
                </button>
            <?php endif; ?>
            <a href="../patient_odontogram/edit.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-tooth me-1"></i><?php echo $dcmt_odontogram_has_data ? trans('patient_note', 'edit_odontogram') : trans('patient_note', 'add_odontogram'); ?>
            </a>
            <a href="edit.php?id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-edit me-1"></i><?php echo trans('common', 'edit'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('patient', 'back_to_patients'); ?>
            </a>
        </div>
    </div>
    <?php if ($dcmt_is_admin_user): ?>
        <p class="text-muted small px-3 pt-2 mb-0"><?php echo sprintf(trans('patient', 'retention_policy_note'), dcmt_patient_retention_years()); ?></p>
    <?php endif; ?>
    <div class="card-body">
        <div class="dcmt-patient-summary-grid mb-4">
            <div class="dcmt-patient-summary-main">
                <div class="dcmt-summary-card">
                    <div class="dcmt-summary-card-title"><i class="fas fa-id-card"></i> <?php echo trans('patient', 'section_personal'); ?></div>
                    <div class="dcmt-summary-kv">
                        <div><span><?php echo trans('patient', 'gender'); ?></span><strong class="text-capitalize"><?php echo htmlspecialchars($patient['dcmt_gender'] ?? '-'); ?></strong></div>
                        <div><span><?php echo trans('patient', 'date_of_birth'); ?></span><strong><?php echo !empty($patient['dcmt_date_of_birth']) ? dcmt_format_date($patient['dcmt_date_of_birth']) : '-'; ?></strong></div>
                        <div><span><?php echo trans('patient', 'age'); ?></span><strong><?php
                            if ($patient_age_from_dob !== null) {
                                echo (int) $patient_age_from_dob;
                            } elseif (isset($patient['dcmt_age']) && $patient['dcmt_age'] !== null && $patient['dcmt_age'] !== '') {
                                echo htmlspecialchars((string) $patient['dcmt_age']);
                            } else {
                                echo '-';
                            }
                        ?></strong></div>
                        <div><span><?php echo trans('patient', 'height'); ?></span><strong><?php echo $patient['dcmt_height_cm'] !== null ? htmlspecialchars($patient['dcmt_height_cm']) . ' cm' : '-'; ?></strong></div>
                        <div><span><?php echo trans('patient', 'weight'); ?></span><strong><?php echo $patient['dcmt_weight_kg'] !== null ? htmlspecialchars($patient['dcmt_weight_kg']) . ' kg' : '-'; ?></strong></div>
                        <div><span><?php echo ucfirst((string) trans('common', 'status')); ?></span><strong class="text-<?php echo $status_safe === 'active' ? 'success' : 'secondary'; ?>"><?php echo trans('common', $status_safe); ?></strong></div>
                    </div>
                </div>

                <div class="dcmt-summary-card dcmt-summary-card-alert">
                    <div class="dcmt-summary-card-title"><i class="fas fa-notes-medical"></i> <?php echo trans('patient', 'section_medical'); ?></div>
                    <div class="mb-2">
                        <div class="dcmt-summary-subtitle"><?php echo trans('patient', 'allergies'); ?></div>
                        <div class="dcmt-summary-text"><?php echo !empty($patient['dcmt_allergies']) ? htmlspecialchars($patient['dcmt_allergies']) : '-'; ?></div>
                    </div>
                    <div>
                        <div class="dcmt-summary-subtitle"><?php echo trans('patient', 'medications'); ?></div>
                        <div class="dcmt-summary-text"><?php echo !empty($patient['dcmt_medications']) ? htmlspecialchars($patient['dcmt_medications']) : '-'; ?></div>
                    </div>
                </div>

                <div class="dcmt-summary-card">
                    <div class="dcmt-summary-card-title"><i class="fas fa-address-book"></i> <?php echo trans('patient', 'section_contact'); ?></div>
                    <div class="dcmt-summary-kv">
                        <div><span><?php echo trans('patient', 'email'); ?></span><strong><?php echo htmlspecialchars($patient['dcmt_email'] ?? '-'); ?></strong></div>
                        <div>
                            <span><?php echo trans('patient', 'phone'); ?></span>
                            <strong>
                                <?php
                                $phone = $patient['dcmt_phone'] ?? '';
                                if ($phone) {
                                    $digits = preg_replace('/\D+/', '', $phone);
                                    if ($digits !== '') {
                                        $wa_link = 'https://wa.me/' . $digits;
                                        $phone_display = dcmt_phone_local_display_digits($digits);
                                        echo '<a href="' . htmlspecialchars($wa_link) . '" target="_blank" rel="noopener noreferrer">' . htmlspecialchars($phone_display) . '</a>';
                                    } else {
                                        echo htmlspecialchars($phone);
                                    }
                                } else {
                                    echo '-';
                                }
                                ?>
                            </strong>
                        </div>
                        <div><span><?php echo trans('patient', 'address'); ?></span><strong><?php echo !empty($patient['dcmt_address']) ? htmlspecialchars($patient['dcmt_address']) : '-'; ?></strong></div>
                        <?php
                        $referral_label = !empty($patient['dcmt_referral_source'])
                            ? dcmt_patient_referral_source_label((string) $patient['dcmt_referral_source'])
                            : '';
                        ?>
                        <div><span><?php echo trans('patient', 'referral_source'); ?></span><strong><?php echo $referral_label !== '' ? htmlspecialchars($referral_label) : '-'; ?></strong></div>
                        <div><span><?php echo trans('common', 'updated_on'); ?></span><strong><?php echo dcmt_format_date($patient['dcmt_updated_at'], DCMT_DATETIME_FORMAT); ?></strong></div>
                        <?php if (!empty($patient['dcmt_privacy_notice_accepted_at'])): ?>
                        <div><span><?php echo trans('patient', 'privacy_accepted_at'); ?></span><strong><?php echo dcmt_format_date($patient['dcmt_privacy_notice_accepted_at'], DCMT_DATETIME_FORMAT); ?></strong></div>
                        <?php endif; ?>
                        <?php if (isset($patient['dcmt_consent_marketing'])): ?>
                        <div><span><?php echo trans('patient', 'consent_marketing_label'); ?></span><strong><?php echo !empty($patient['dcmt_consent_marketing']) ? trans('common', 'yes') : trans('common', 'no'); ?></strong></div>
                        <?php endif; ?>
                        <?php if ($dcmt_patient_anonymized): ?>
                        <div><span><?php echo trans('patient', 'anonymized_at'); ?></span><strong><?php echo dcmt_format_date($patient['dcmt_anonymized_at'], DCMT_DATETIME_FORMAT); ?></strong></div>
                        <?php endif; ?>
                    </div>
                </div>

                <div class="dcmt-summary-card">
                    <div class="dcmt-summary-card-title"><i class="fas fa-user-shield"></i> <?php echo trans('patient', 'section_emergency'); ?></div>
                    <div class="dcmt-summary-kv">
                        <div><span><?php echo trans('patient', 'emergency_contact_name'); ?></span><strong><?php echo htmlspecialchars($patient['dcmt_emergency_contact_name'] ?? '-'); ?></strong></div>
                        <div><span><?php echo trans('patient', 'emergency_contact_relation'); ?></span><strong><?php echo htmlspecialchars($patient['dcmt_emergency_contact_relation'] ?? '-'); ?></strong></div>
                        <div><span><?php echo trans('patient', 'emergency_contact_phone'); ?></span><strong><?php echo htmlspecialchars($patient['dcmt_emergency_contact_phone'] ?? '-'); ?></strong></div>
                    </div>
                </div>
            </div>

            <div class="dcmt-patient-summary-side">
                <?php if ($dcmt_show_patient_income_stats): ?>
                    <div class="dcmt-summary-card dcmt-summary-stat-card">
                        <div class="dcmt-summary-card-title"><?php echo trans('patient', 'total_income'); ?></div>
                        <div class="dcmt-summary-stat-value"><?php echo dcmt_format_currency($patient_total_income); ?></div>
                    </div>
                    <div class="dcmt-summary-card dcmt-summary-stat-card">
                        <div class="dcmt-summary-card-title"><?php echo trans('patient', 'total_visits'); ?></div>
                        <div class="dcmt-summary-stat-value"><?php echo (int) $patient_total_visits; ?></div>
                    </div>
                <?php endif; ?>
                <div class="dcmt-summary-card dcmt-summary-stat-card">
                    <div class="dcmt-summary-card-title"><i class="fas fa-calendar-alt"></i> <?php echo trans('appointment', 'appointments'); ?></div>
                    <?php if ($next_appointment): ?>
                        <?php
                        $next_appt_start_ts = strtotime((string) $next_appointment['dcmt_start_at']);
                        $next_appt_end_ts = strtotime((string) $next_appointment['dcmt_end_at']);
                        $next_appt_in_progress = $next_appt_start_ts !== false
                            && $next_appt_end_ts !== false
                            && time() >= $next_appt_start_ts
                            && time() < $next_appt_end_ts;
                        ?>
                        <div class="dcmt-summary-next-date"><?php echo dcmt_format_date($next_appointment['dcmt_start_at'], 'M d, Y'); ?></div>
                        <div class="dcmt-summary-next-time">
                            <?php if ($next_appt_in_progress): ?>
                                <?php echo trans('appointment', 'end_time'); ?>: <?php echo date('h:i A', $next_appt_end_ts); ?>
                            <?php else: ?>
                                <?php echo date('h:i A', $next_appt_start_ts); ?> - <?php echo date('h:i A', $next_appt_end_ts); ?>
                            <?php endif; ?>
                        </div>
                        <div class="dcmt-summary-next-reason"><?php echo !empty($next_appointment['dcmt_reason']) ? htmlspecialchars($next_appointment['dcmt_reason']) : htmlspecialchars($next_appointment['doctor_name'] ?? '-'); ?></div>
                    <?php else: ?>
                        <div class="dcmt-summary-next-date">-</div>
                        <div class="dcmt-summary-next-time"><?php echo trans('appointment', 'no_upcoming_appointment'); ?></div>
                    <?php endif; ?>
                    <div class="mt-2 d-flex flex-wrap gap-2">
                        <?php if ($next_appointment && !empty($next_appointment['dcmt_id'])): ?>
                            <a href="../appointments/view.php?id=<?php echo (int) $next_appointment['dcmt_id']; ?>" class="btn btn-outline-primary btn-sm">
                                <i class="fas fa-eye me-1"></i><?php echo trans('appointment', 'view_appointment'); ?>
                            </a>
                        <?php elseif ($dcmt_can_book_appointment): ?>
                            <a href="../appointments/add.php?patient_id=<?php echo $patient_id; ?>" class="btn btn-primary btn-sm">
                                <i class="fas fa-calendar-plus me-1"></i><?php echo trans('appointment', 'add_appointment'); ?>
                            </a>
                        <?php else: ?>
                            <a href="../appointments/index.php" class="btn btn-outline-secondary btn-sm">
                                <i class="fas fa-calendar-alt me-1"></i><?php echo trans('appointment', 'appointments'); ?>
                            </a>
                        <?php endif; ?>
                    </div>
                </div>
            </div>
        </div>

        <?php if (!empty($patient['dcmt_notes'])): ?>
            <div class="mb-4">
                <div class="dcmt-summary-card">
                    <div class="dcmt-summary-card-title"><i class="fas fa-sticky-note"></i> <?php echo trans('patient', 'section_other'); ?></div>
                    <div class="dcmt-view-field">
                        <span class="dcmt-view-field-label"><?php echo trans('patient', 'notes'); ?>:</span>
                        <div class="dcmt-view-field-value"><?php echo nl2br(htmlspecialchars($patient['dcmt_notes'])); ?></div>
                    </div>
                </div>
            </div>
        <?php endif; ?>

        <?php if ($dcmt_odontogram_has_data): ?>
            <div class="mb-4">
                <div class="d-flex justify-content-between align-items-center mb-3">
                    <h5 class="mb-0">
                        <i class="fas fa-tooth me-2"></i><?php echo trans('patient_note', 'odontogram_record'); ?>
                    </h5>
                    <a href="../patient_odontogram/view.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link">
                        <i class="fas fa-eye me-1"></i><?php echo trans('common', 'view'); ?>
                    </a>
                </div>
                <div class="dcmt-note-list">
                    <?php
                    $dcmt_odontogram_card_patient_id = $patient_id;
                    $dcmt_odontogram_card_has_data = true;
                    $dcmt_odontogram_card_record = $dcmt_odontogram_record;
                    $dcmt_odontogram_card_patient_created_at = $patient['dcmt_created_at'] ?? null;
                    $dcmt_odontogram_card_show_patient_name = false;
                    $dcmt_odontogram_card_show_when_empty = false;
                    include __DIR__ . '/../../includes/patient_odontogram_history_card.php';
                    ?>
                </div>
            </div>
        <?php endif; ?>

        <div class="mb-4">
            <div class="d-flex justify-content-between align-items-center mb-3">
                <h5 class="mb-0">
                    <i class="fas fa-sticky-note me-2"></i><?php echo trans('patient_note', 'patient_notes'); ?>
                    <span class="badge bg-secondary ms-2"><?php echo count($patient_notes); ?></span>
                </h5>
                <a href="../patient_notes/index.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-list me-1"></i><?php echo trans('patient_note', 'view_all_notes'); ?>
                </a>
            </div>
            <?php if (empty($patient_notes_paginated)): ?>
                <div class="alert alert-info">
                    <i class="fas fa-info-circle me-2"></i><?php echo trans('patient_note', 'no_notes_found'); ?>
                </div>
            <?php else: ?>
                <div class="table-responsive">
                    <table class="table table-bordered table-hover">
                        <thead>
                            <tr>
                                <th><?php echo trans('patient_note', 'note_date'); ?></th>
                                <th><?php echo trans('patient_note', 'topic'); ?></th>
                                <th><?php echo trans('common', 'created_by'); ?></th>
                                <th><?php echo trans('common', 'actions'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($patient_notes_paginated as $note): ?>
                                <tr>
                                    <td><?php echo dcmt_format_date($note['dcmt_note_date']); ?></td>
                                    <td>
                                        <?php if (!empty($note['dcmt_topic'])): ?>
                                            <strong><?php echo htmlspecialchars($note['dcmt_topic']); ?></strong>
                                        <?php else: ?>
                                            <span class="text-muted">-</span>
                                        <?php endif; ?>
                                    </td>
                                    <td><?php echo htmlspecialchars($note['created_by_name'] ?? $note['dcmt_created_by'] ?? '-'); ?></td>
                                    <td>
                                        <a href="../patient_notes/view.php?id=<?php echo $note['dcmt_id']; ?>"
                                           class="dcmt-add-form-view-all-link"
                                           title="<?php echo trans('common', 'view'); ?>">
                                            <i class="fas fa-eye me-1"></i><?php echo trans('common', 'view'); ?>
                                        </a>
                                    </td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
            <?php endif; ?>
        </div>

        <?php if (!$dcmt_is_assistant): ?>
            <div class="mb-4">
                <h5 class="mb-3">
                    <i class="fas fa-file-medical me-2"></i><?php echo trans('patient', 'treatment_history'); ?>
                    <?php if ($dcmt_show_patient_income_stats): ?>
                        <span class="badge bg-secondary ms-2"><?php echo $patient_total_visits; ?></span>
                    <?php endif; ?>
                </h5>

            <div class="mb-4">
                <h6 class="mb-2">
                    <?php echo trans('patient', 'service_details'); ?>
                    <span class="badge bg-secondary ms-2"><?php echo $services_total; ?></span>
                </h6>
                <?php if (empty($service_details)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i><?php echo trans('common', 'no_records_found'); ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo trans('common', 'date'); ?></th>
                                    <th><?php echo trans('income', 'doctor'); ?></th>
                                    <th><?php echo trans('income', 'service'); ?></th>
                                    <th><?php echo trans('income', 'quantity'); ?></th>
                                    <th><?php echo trans('income', 'unit_price'); ?></th>
                                    <th><?php echo trans('common', 'total'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($service_details as $row): ?>
                                    <?php
                                        $service_name = $row['service_name'] ?? $row['dcmt_label'] ?? '-';
                                        $qty = (float)($row['dcmt_quantity'] ?? 0);
                                        $unit_price = (float)($row['dcmt_unit_price'] ?? 0);
                                        $line_total = $row['dcmt_line_total'] !== null ? (float)$row['dcmt_line_total'] : ($qty * $unit_price);
                                    ?>
                                    <tr>
                                        <td><?php echo dcmt_format_date($row['dcmt_transaction_date']); ?></td>
                                        <td><?php echo htmlspecialchars($row['doctor_name'] ?? '-'); ?></td>
                                        <td><?php echo htmlspecialchars($service_name); ?></td>
                                        <td><?php echo htmlspecialchars((string)($row['dcmt_quantity'] ?? '0')); ?></td>
                                        <td><?php echo dcmt_format_currency($unit_price); ?></td>
                                        <td><?php echo dcmt_format_currency($line_total); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mb-4">
                <h6 class="mb-2">
                    <?php echo trans('patient', 'product_details'); ?>
                    <span class="badge bg-secondary ms-2"><?php echo $products_total; ?></span>
                </h6>
                <?php if (empty($product_details)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i><?php echo trans('common', 'no_records_found'); ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo trans('common', 'date'); ?></th>
                                    <th><?php echo trans('income', 'product'); ?></th>
                                    <th><?php echo trans('income', 'quantity'); ?></th>
                                    <th><?php echo trans('income', 'unit_price'); ?></th>
                                    <th><?php echo trans('common', 'total'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($product_details as $row): ?>
                                    <?php
                                        $product_name = $row['product_name'] ?? $row['dcmt_label'] ?? '-';
                                        $qty = (float)($row['dcmt_quantity'] ?? 0);
                                        $unit_price = (float)($row['dcmt_unit_price'] ?? 0);
                                        $line_total = $row['dcmt_line_total'] !== null ? (float)$row['dcmt_line_total'] : ($qty * $unit_price);
                                    ?>
                                    <tr>
                                        <td><?php echo dcmt_format_date($row['dcmt_transaction_date']); ?></td>
                                        <td><?php echo htmlspecialchars($product_name); ?></td>
                                        <td><?php echo htmlspecialchars((string)($row['dcmt_quantity'] ?? '0')); ?></td>
                                        <td><?php echo dcmt_format_currency($unit_price); ?></td>
                                        <td><?php echo dcmt_format_currency($line_total); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>

            <div class="mb-0">
                <h6 class="mb-2">
                    <?php echo trans('income', 'payment_history'); ?>
                    <span class="badge bg-secondary ms-2"><?php echo $payments_total; ?></span>
                </h6>
                <?php if (empty($payment_history_rows)): ?>
                    <div class="alert alert-info mb-0">
                        <i class="fas fa-info-circle me-2"></i><?php echo trans('common', 'no_records_found'); ?>
                    </div>
                <?php else: ?>
                    <div class="table-responsive">
                        <table class="table table-bordered table-hover mb-0">
                            <thead>
                                <tr>
                                    <th><?php echo trans('income', 'payment_date'); ?></th>
                                    <th><?php echo trans('income', 'payment_method'); ?></th>
                                    <th><?php echo trans('income', 'recorded_by'); ?></th>
                                    <th><?php echo trans('income', 'payment_amount'); ?></th>
                                </tr>
                            </thead>
                            <tbody>
                                <?php foreach ($payment_history_rows as $payment): ?>
                                    <?php
                                        $recorded_name = $payment['recorded_by_name'] ?? $payment['dcmt_recorded_by'] ?? '-';
                                        $payment_method_display = $payment['payment_method_name'] ?? '-';
                                        $amount = (float)($payment['dcmt_amount'] ?? 0);
                                    ?>
                                    <tr>
                                        <td><?php echo dcmt_format_date($payment['dcmt_paid_on']); ?></td>
                                        <td><?php echo htmlspecialchars($payment_method_display); ?></td>
                                        <td><?php echo htmlspecialchars($recorded_name); ?></td>
                                        <td><?php echo dcmt_format_currency($amount); ?></td>
                                    </tr>
                                <?php endforeach; ?>
                            </tbody>
                        </table>
                    </div>
                <?php endif; ?>
            </div>
            </div>
        <?php endif; ?>

    </div>
</div>

<?php if ($dcmt_is_admin_user && !$dcmt_patient_anonymized): ?>
<script>
document.getElementById('dcmtAnonymizePatientBtn')?.addEventListener('click', function () {
    if (!confirm(<?php echo json_encode(trans('patient', 'anonymize_confirm')); ?>)) {
        return;
    }
    const btn = this;
    const formData = new FormData();
    formData.append('id', btn.getAttribute('data-patient-id'));
    formData.append('csrf_token', btn.getAttribute('data-csrf'));
    btn.disabled = true;
    fetch('anonymize_ajax.php', { method: 'POST', body: formData, credentials: 'same-origin' })
        .then(r => r.json())
        .then(data => {
            alert(data.message || '');
            if (data.success) {
                window.location.reload();
            } else {
                btn.disabled = false;
            }
        })
        .catch(() => {
            alert(<?php echo json_encode(trans('patient', 'anonymize_failed')); ?>);
            btn.disabled = false;
        });
});
</script>
<?php endif; ?>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>

