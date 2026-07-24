<?php
/**
 * Print treatment plan for patient (summary + priced services).
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';
require_once __DIR__ . '/../../includes/patient_odontogram_summary.php';
require_once __DIR__ . '/../../includes/patient_treatment_plan.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
    exit();
}

$dcmt_db = new Dcmt_Database();
$dcmt_db->migratePatientTreatmentPlanSchema();

try {
    $patient_cols = dcmt_patient_select_columns_without_odontogram('p', $dcmt_pdo);
    $stmt = $dcmt_pdo->prepare("SELECT {$patient_cols} FROM dcmt_patients p WHERE p.dcmt_id = ?");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$patient) {
        dcmt_show_message(trans('patient', 'not_found'), 'danger');
        dcmt_redirect('../patient_notes/index.php');
        exit();
    }
    dcmt_audit('print', 'patient_treatment_plan', $patient_id);
} catch (PDOException $e) {
    error_log('Treatment plan print — patient: ' . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('../patient_notes/index.php');
    exit();
}

$odontogram_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
$summary = dcmt_patient_odontogram_solution_summary($odontogram_json);
$saved_plan = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id);

$doctor_id = $saved_plan['dcmt_doctor_user_id'] ?? 0;
$plan_lines = [];
$plan_notes = '';
$plan_total = 0.0;

global $dcmt_current_user;
$sync_result = dcmt_sync_treatment_plan_from_odontogram(
    $dcmt_pdo,
    $patient_id,
    (int) $doctor_id,
    false,
    $dcmt_current_user['dcmt_username'] ?? null
);
if (is_array($sync_result)) {
    $summary = $sync_result['summary'] ?? $summary;
    if (!empty($sync_result['synced']) || !empty($sync_result['rebuilt'])) {
        $plan_lines = $sync_result['lines'] ?? [];
        $saved_plan = dcmt_fetch_patient_treatment_plan($dcmt_pdo, $patient_id) ?: $saved_plan;
    }
}

if ($saved_plan) {
    $plan_notes = (string) ($saved_plan['dcmt_notes'] ?? '');
    $doctor_id = (int) ($saved_plan['dcmt_doctor_user_id'] ?? $doctor_id);
}

if (empty($plan_lines)) {
    if ($saved_plan && !empty($saved_plan['lines'])) {
        $plan_lines = dcmt_normalize_treatment_plan_lines($saved_plan['lines'], $dcmt_pdo, $doctor_id);
    } else {
        $document = dcmt_patient_odontogram_decode_document($odontogram_json);
        $solution = $document['solution'] ?? dcmt_patient_odontogram_empty_chart();
        $plan_lines = dcmt_build_treatment_plan_lines($dcmt_pdo, $solution, $doctor_id);
    }
}
$plan_lines = dcmt_filter_treatment_plan_lines_by_show_flag($dcmt_pdo, $plan_lines);
$plan_total = dcmt_treatment_plan_calculate_total($plan_lines);

$doctors_by_id = [];
try {
    $dstmt = $dcmt_pdo->query("
        SELECT dcmt_id, dcmt_full_name
        FROM dcmt_users
        WHERE dcmt_role = 'doctor'
    ");
    $rows = $dstmt ? $dstmt->fetchAll(PDO::FETCH_ASSOC) : [];
    foreach ($rows as $row) {
        $doctors_by_id[(int) $row['dcmt_id']] = (string) ($row['dcmt_full_name'] ?? '');
    }
} catch (PDOException $e) {
    error_log('Treatment plan print — doctors: ' . $e->getMessage());
}

$cell_headers = array_map(static function (array $header): array {
    return [
        'key' => $header['key'],
        'short' => $header['label'],
        'zone' => $header['title'],
    ];
}, dcmt_patient_odontogram_summary_cell_headers());

$selected_lines = array_values(array_filter($plan_lines, static function (array $line): bool {
    return !empty($line['selected']);
}));
$split_lines = dcmt_treatment_plan_split_lines($selected_lines);
$odontogram_selected_lines = dcmt_sort_treatment_plan_odontogram_lines($split_lines['odontogram']);
$additional_selected_lines = $split_lines['additional'];
$clinical_quadrant_totals = dcmt_treatment_plan_clinical_quadrant_pair_totals($odontogram_selected_lines);
$additional_services_total = 0.0;
foreach ($additional_selected_lines as $line) {
    if (!is_array($line)) {
        continue;
    }
    $additional_services_total += (float) ($line['subtotal'] ?? 0);
}
$additional_services_total = round($additional_services_total, 2);

$clinic_name = function_exists('dcmt_get_site_name') ? dcmt_get_site_name() : 'Dental Clinic';
$patient_name = $patient['dcmt_patient_name'] ?? '';
$patient_phone = $patient['dcmt_phone'] ?? '';
$printed_at = dcmt_format_date(dcmt_get_current_date());
$back_url = 'treatment_plan.php?patient_id=' . $patient_id;
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(substr(defined('DCMT_LANG') ? DCMT_LANG : 'en', 0, 2)); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(trans('patient', 'treatment_plan_print_title')); ?> — <?php echo htmlspecialchars($patient_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo dcmt_asset('assets/css/main.css', '../../'); ?>" rel="stylesheet">
    <style>
        body.dcmt-plan-print-page { background: #f4f6f8; font-family: 'Roboto', sans-serif; color: #212529; }
        .dcmt-plan-print-toolbar {
            position: sticky; top: 0; z-index: 20; background: #fff;
            border-bottom: 1px solid #dee2e6; padding: 0.85rem 1.25rem;
            display: flex; flex-wrap: wrap; align-items: center; justify-content: space-between; gap: 0.75rem;
        }
        .dcmt-plan-print-sheet { max-width: 900px; margin: 1.25rem auto 2rem; padding: 0 1rem; }
        .dcmt-plan-print-header, .dcmt-plan-print-section {
            background: #fff; border: 1px solid #dee2e6; border-radius: 8px;
            padding: 1.25rem 1.5rem; margin-bottom: 1rem; box-shadow: 0 1px 3px rgba(0,0,0,.06);
        }
        .dcmt-plan-print-header h1 { font-size: 1.35rem; margin: 0 0 0.5rem; }
        .dcmt-plan-print-meta { font-size: 0.9rem; color: #495057; }
        .dcmt-plan-print-section h2,
        .dcmt-plan-print-subsection-title {
            font-size: 1.05rem;
            margin: 0 0 1rem;
            color: #000;
            font-weight: 700;
        }
        .dcmt-plan-print-subsection-title {
            margin-top: 1rem !important;
        }
        .dcmt-plan-summary-table th, .dcmt-plan-summary-table td { font-size: 0.8rem; vertical-align: middle; }
        .dcmt-plan-summary-table th { white-space: nowrap; }
        .dcmt-plan-lines-table th, .dcmt-plan-lines-table td { font-size: 0.9rem; vertical-align: middle; }
        .dcmt-plan-notes { white-space: pre-wrap; font-size: 0.9rem; color: #495057; }
        .dcmt-plan-clinical-quadrant-totals {
            margin-top: 1rem;
            padding-top: 0.85rem;
            border-top: 1px solid #dee2e6;
        }
        .dcmt-plan-clinical-quadrant-totals-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 1rem;
            padding: 0.35rem 0;
            font-size: 0.92rem;
        }
        .dcmt-plan-clinical-quadrant-totals-row + .dcmt-plan-clinical-quadrant-totals-row {
            border-top: 1px dashed #e9ecef;
        }
        .dcmt-plan-clinical-quadrant-totals-row--additional {
            margin-top: 0.15rem;
            padding-top: 0.55rem;
            border-top: 1px solid #dee2e6 !important;
        }
        .dcmt-plan-clinical-quadrant-totals-label {
            font-weight: 600;
            color: #334155;
        }
        .dcmt-plan-clinical-quadrant-totals-value {
            font-weight: 700;
            color: #2979C9;
            white-space: nowrap;
        }
        @media print {
            body.dcmt-plan-print-page { background: #fff; }
            .dcmt-plan-print-toolbar { display: none !important; }
            .dcmt-plan-print-sheet { max-width: none; margin: 0; padding: 0; }
            .dcmt-plan-print-header, .dcmt-plan-print-section { box-shadow: none; break-inside: avoid; }
        }
    </style>
</head>
<body class="dcmt-plan-print-page">
    <div class="dcmt-plan-print-toolbar dcmt-no-print">
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="dcmt-add-form-view-all-link">
            <i class="fas fa-arrow-left me-1"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_title')); ?>
        </a>
        <button type="button" class="btn btn-primary btn-sm" onclick="window.print()">
            <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_print')); ?>
        </button>
    </div>

    <div class="dcmt-plan-print-sheet">
        <header class="dcmt-plan-print-header">
            <div class="text-muted small mb-1"><?php echo htmlspecialchars($clinic_name); ?></div>
            <h1><?php echo htmlspecialchars(trans('patient', 'treatment_plan_print_title')); ?></h1>
            <div class="dcmt-plan-print-meta">
                <strong><?php echo htmlspecialchars(trans('patient', 'patient')); ?>:</strong>
                <?php echo htmlspecialchars($patient_name); ?>
                <?php if ($patient_phone !== ''): ?>
                    &nbsp;|&nbsp;<strong><?php echo htmlspecialchars(trans('patient', 'phone')); ?>:</strong>
                    <?php echo htmlspecialchars($patient_phone); ?>
                <?php endif; ?>
                &nbsp;|&nbsp;<strong><?php echo htmlspecialchars(trans('common', 'date')); ?>:</strong>
                <?php echo htmlspecialchars($printed_at); ?>
            </div>
        </header>

        <?php if (!empty($summary['matrix'])): ?>
        <section class="dcmt-plan-print-section">
            <h2><i class="fas fa-table me-2"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_summary_title')); ?></h2>
            <div class="table-responsive">
                <table class="table table-bordered table-sm mb-0 dcmt-plan-summary-table">
                    <thead class="table-light">
                        <tr>
                            <th><?php echo htmlspecialchars(trans('patient', 'treatment_plan_col_treatment')); ?></th>
                            <?php foreach ($cell_headers as $header): ?>
                                <th class="text-center" title="<?php echo htmlspecialchars($header['zone']); ?>">
                                    <?php echo htmlspecialchars($header['short']); ?>
                                </th>
                            <?php endforeach; ?>
                            <th class="text-center"><?php echo htmlspecialchars(trans('common', 'total')); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($summary['matrix'] as $row): ?>
                            <tr>
                                <td><strong><?php echo htmlspecialchars($row['treatment']); ?></strong></td>
                                <?php foreach ($cell_headers as $header): ?>
                                    <?php $qty = (int) ($row['cells'][$header['key']] ?? 0); ?>
                                    <td class="text-center"><?php echo $qty > 0 ? $qty : '—'; ?></td>
                                <?php endforeach; ?>
                                <td class="text-center fw-semibold"><?php echo (int) $row['total']; ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <p class="text-muted small mt-2 mb-0"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_summary_footnote')); ?></p>
        </section>
        <?php endif; ?>

        <section class="dcmt-plan-print-section">
            <h2><i class="fas fa-clipboard-list me-2"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_lines_title')); ?></h2>
            <?php if (empty($odontogram_selected_lines) && empty($additional_selected_lines)): ?>
                <p class="text-muted mb-0"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_no_treatments')); ?></p>
            <?php else: ?>
                <?php if (!empty($odontogram_selected_lines)): ?>
                <div class="table-responsive">
                    <table class="table table-bordered dcmt-plan-lines-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo trans('patient', 'treatment_plan_col_treatment'); ?></th>
                                <th><?php echo trans('patient', 'treatment_plan_col_location'); ?></th>
                                <th class="text-center"><?php echo trans('patient', 'treatment_plan_col_qty'); ?></th>
                                <th><?php echo trans('patient', 'treatment_plan_doctor'); ?></th>
                                <th><?php echo trans('patient', 'treatment_plan_col_service'); ?></th>
                                <th class="text-end"><?php echo trans('patient', 'treatment_plan_col_unit_price'); ?></th>
                                <th class="text-end"><?php echo trans('patient', 'treatment_plan_col_subtotal'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($odontogram_selected_lines as $line): ?>
                                <?php
                                $line_doctor_id = (int) ($line['doctor_id'] ?? 0);
                                $line_doctor_name = $line_doctor_id > 0
                                    ? ($doctors_by_id[$line_doctor_id] ?? '')
                                    : '';
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($line['treatment']); ?></td>
                                    <td class="text-muted small">
                                        <?php echo htmlspecialchars(($line['zone_label'] ?? '') . ' · ' . ($line['quadrant_label'] ?? '')); ?>
                                    </td>
                                    <td class="text-center"><?php echo (int) $line['quantity']; ?></td>
                                    <td><?php echo htmlspecialchars($line_doctor_name !== '' ? $line_doctor_name : '—'); ?></td>
                                    <td><?php echo htmlspecialchars($line['service_name'] ?: '—'); ?></td>
                                    <td class="text-end"><?php echo dcmt_format_currency($line['unit_price']); ?></td>
                                    <td class="text-end fw-semibold"><?php echo dcmt_format_currency($line['subtotal']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($additional_selected_lines)): ?>
                <h3 class="dcmt-plan-print-subsection-title mt-4 mb-2">
                    <i class="fas fa-plus-circle me-1"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_additional_services')); ?>
                </h3>
                <div class="table-responsive">
                    <table class="table table-bordered dcmt-plan-lines-table mb-0">
                        <thead class="table-light">
                            <tr>
                                <th><?php echo trans('patient', 'treatment_plan_col_service'); ?></th>
                                <th class="text-center"><?php echo trans('patient', 'treatment_plan_col_qty'); ?></th>
                                <th><?php echo trans('patient', 'treatment_plan_doctor'); ?></th>
                                <th class="text-end"><?php echo trans('patient', 'treatment_plan_col_unit_price'); ?></th>
                                <th class="text-end"><?php echo trans('patient', 'treatment_plan_col_subtotal'); ?></th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($additional_selected_lines as $line): ?>
                                <?php
                                $line_doctor_id = (int) ($line['doctor_id'] ?? 0);
                                $line_doctor_name = $line_doctor_id > 0
                                    ? ($doctors_by_id[$line_doctor_id] ?? '')
                                    : '';
                                $service_label = trim((string) ($line['service_name'] ?? $line['treatment'] ?? ''));
                                ?>
                                <tr>
                                    <td><?php echo htmlspecialchars($service_label !== '' ? $service_label : '—'); ?></td>
                                    <td class="text-center"><?php echo (int) $line['quantity']; ?></td>
                                    <td><?php echo htmlspecialchars($line_doctor_name !== '' ? $line_doctor_name : '—'); ?></td>
                                    <td class="text-end"><?php echo dcmt_format_currency($line['unit_price']); ?></td>
                                    <td class="text-end fw-semibold"><?php echo dcmt_format_currency($line['subtotal']); ?></td>
                                </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>

                <?php if (!empty($odontogram_selected_lines) || !empty($additional_selected_lines)): ?>
                <div class="dcmt-plan-clinical-quadrant-totals">
                    <?php if (!empty($odontogram_selected_lines)): ?>
                        <?php foreach ($clinical_quadrant_totals as $quadrant_total): ?>
                            <div class="dcmt-plan-clinical-quadrant-totals-row">
                                <span class="dcmt-plan-clinical-quadrant-totals-label">
                                    <?php echo htmlspecialchars($quadrant_total['label']); ?>
                                </span>
                                <span class="dcmt-plan-clinical-quadrant-totals-value">
                                    <?php echo dcmt_format_currency($quadrant_total['total']); ?>
                                </span>
                            </div>
                        <?php endforeach; ?>
                    <?php endif; ?>
                    <?php if (!empty($additional_selected_lines)): ?>
                        <div class="dcmt-plan-clinical-quadrant-totals-row dcmt-plan-clinical-quadrant-totals-row--additional">
                            <span class="dcmt-plan-clinical-quadrant-totals-label">
                                <?php echo htmlspecialchars(trans('patient', 'treatment_plan_additional_services')); ?>
                            </span>
                            <span class="dcmt-plan-clinical-quadrant-totals-value">
                                <?php echo dcmt_format_currency($additional_services_total); ?>
                            </span>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>

                <div class="d-flex justify-content-end align-items-center mt-3 pt-2 border-top">
                    <span class="fw-semibold me-3"><?php echo htmlspecialchars(trans('patient', 'treatment_plan_grand_total')); ?>:</span>
                    <span class="fw-bold"><?php echo dcmt_format_currency($plan_total); ?></span>
                </div>
            <?php endif; ?>
        </section>

        <?php if ($plan_notes !== ''): ?>
        <section class="dcmt-plan-print-section">
            <h2><i class="fas fa-sticky-note me-2"></i><?php echo htmlspecialchars(trans('patient', 'treatment_plan_notes')); ?></h2>
            <div class="dcmt-plan-notes"><?php echo htmlspecialchars($plan_notes); ?></div>
        </section>
        <?php endif; ?>

        <p class="text-muted small text-center mt-3">
            <?php echo htmlspecialchars(trans('patient', 'treatment_plan_print_disclaimer')); ?>
        </p>
    </div>
</body>
</html>
