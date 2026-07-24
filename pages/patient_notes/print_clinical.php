<?php
/**
 * Print clinical history: problem + solution odontogram, zona details, and all patient notes.
 * Dental Clinic Management System
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/odontogram_treatments.php';
require_once __DIR__ . '/../../includes/patient_odontogram.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

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
    dcmt_audit('print', 'patient', $patient_id);
} catch (PDOException $e) {
    error_log('Clinical print — patient: ' . $e->getMessage());
    dcmt_show_message(trans('patient_note', 'database_error'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$notes = [];
try {
    $notes_stmt = $dcmt_pdo->prepare("
        SELECT pn.*, u.dcmt_full_name AS created_by_name
        FROM dcmt_patient_notes pn
        LEFT JOIN dcmt_users u ON pn.dcmt_created_by = u.dcmt_username
        WHERE pn.dcmt_patient_id = ?
        ORDER BY pn.dcmt_created_at DESC
    ");
    $notes_stmt->execute([$patient_id]);
    $notes = $notes_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log('Clinical print — notes: ' . $e->getMessage());
}

$dcmt_odontogram_patient_id = $patient_id;
$dcmt_odontogram_initial_json = dcmt_load_patient_odontogram_json($dcmt_pdo, $patient_id);
if ($dcmt_odontogram_initial_json === '') {
    $dcmt_odontogram_initial_json = '{}';
}
$dcmt_odontogram_document = dcmt_patient_odontogram_decode_document($dcmt_odontogram_initial_json);
$dcmt_od_problem_has_data = dcmt_patient_odontogram_chart_slice_has_data($dcmt_odontogram_document['problem'] ?? []);
$dcmt_od_solution_has_data = dcmt_patient_odontogram_chart_slice_has_data($dcmt_odontogram_document['solution'] ?? []);
$dcmt_od_odontogram_has_chart_data = $dcmt_od_problem_has_data || $dcmt_od_solution_has_data;

dcmt_ensure_odontogram_treatments_table($dcmt_pdo);
$dcmt_od_treatments_json = dcmt_odontogram_treatments_json_for_chart($dcmt_pdo);
$dcmt_od_state_colors_json = dcmt_odontogram_problem_states_json_for_chart($dcmt_pdo);
$dcmt_od_problems_json = dcmt_odontogram_problems_json_for_chart($dcmt_pdo);

$dcmt_od_trans = [
    'confirmReset' => trans('patient', 'odontogram_confirm_reset'),
    'stateDefault' => trans('patient', 'odontogram_state_default'),
    'stateDamaged' => trans('patient', 'odontogram_state_damaged'),
    'stateFilling' => trans('patient', 'odontogram_state_filling'),
    'stateMissing' => trans('patient', 'odontogram_state_missing'),
    'stateCrown' => trans('patient', 'odontogram_state_crown'),
    'stateImplant' => trans('patient', 'odontogram_state_implant'),
    'zonaAnterior' => trans('patient', 'odontogram_zona_anterior'),
    'zonaPosterior' => trans('patient', 'odontogram_zona_posterior'),
    'selectTreatment' => trans('patient', 'odontogram_select_treatment'),
    'noTreatments' => trans('patient', 'odontogram_no_treatments_zone'),
    'selectConditionFirst' => trans('patient', 'odontogram_select_condition_first'),
    'treatmentsUnderState' => trans('patient', 'odontogram_treatments_under_state'),
    'noAppliedTreatments' => trans('patient', 'odontogram_no_applied_treatments'),
    'toothCondition' => trans('patient', 'odontogram_tooth_condition'),
    'clinicalTreatments' => trans('patient', 'odontogram_clinical_treatments'),
    'addTreatment' => trans('patient', 'odontogram_add_treatment'),
    'chooseTreatment' => trans('patient', 'odontogram_choose_treatment'),
    'quadrantEmpty' => trans('patient', 'odontogram_quadrant_empty'),
    'toothLabel' => trans('patient', 'odontogram_tooth_label'),
    'sectionTop' => trans('patient', 'odontogram_section_top'),
    'sectionBottom' => trans('patient', 'odontogram_section_bottom'),
    'sectionLeft' => trans('patient', 'odontogram_section_left'),
    'sectionRight' => trans('patient', 'odontogram_section_right'),
    'sectionCenter' => trans('patient', 'odontogram_section_center'),
    'sectionRootLeft' => trans('patient', 'odontogram_section_root_left'),
    'sectionRootRight' => trans('patient', 'odontogram_section_root_right'),
];
$dcmt_od_trans_json = json_encode($dcmt_od_trans, JSON_UNESCAPED_UNICODE);

$dcmt_od_solution_chart_json = json_encode(
    $dcmt_odontogram_document['solution'] ?? dcmt_patient_odontogram_empty_chart(),
    JSON_UNESCAPED_UNICODE
);
$dcmt_od_problem_chart_json = json_encode(
    $dcmt_odontogram_document['problem'] ?? dcmt_patient_odontogram_empty_chart(),
    JSON_UNESCAPED_UNICODE
);

$dcmt_od_chart_readonly = true;
$dcmt_od_in_tabs = false;
$dcmt_od_tab_active = false;

$back_url = 'index.php?patient_id=' . $patient_id;
$printed_at = dcmt_format_date(dcmt_get_current_date());
$patient_name = $patient['dcmt_patient_name'] ?? '';
$patient_phone = $patient['dcmt_phone'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?php echo htmlspecialchars(substr(defined('DCMT_LANG') ? DCMT_LANG : 'en', 0, 2)); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo htmlspecialchars(trans('patient_note', 'print_clinical_history')); ?> — <?php echo htmlspecialchars($patient_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <link href="<?php echo dcmt_asset('assets/css/main.css', '../../'); ?>" rel="stylesheet">
    <link href="<?php echo dcmt_asset('assets/css/odontogram.css', '../../'); ?>" rel="stylesheet">
    <style>
        body.dcmt-clinical-print-page {
            background: #f4f6f8;
            font-family: 'Roboto', sans-serif;
            color: #212529;
        }
        .dcmt-clinical-print-toolbar {
            position: sticky;
            top: 0;
            z-index: 20;
            background: #fff;
            border-bottom: 1px solid #dee2e6;
            padding: 0.85rem 1.25rem;
            display: flex;
            flex-wrap: wrap;
            align-items: center;
            justify-content: space-between;
            gap: 0.75rem;
        }
        .dcmt-clinical-print-sheet {
            max-width: 1100px;
            margin: 1.25rem auto 2rem;
            padding: 0 1rem;
        }
        .dcmt-clinical-print-header {
            background: #fff;
            border: 1px solid #e3e8ec;
            border-radius: 12px;
            padding: 1.25rem 1.5rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        }
        .dcmt-clinical-print-header h1 {
            font-size: 1.35rem;
            font-weight: 700;
            color: #2979C9;
            margin: 0 0 0.35rem;
        }
        .dcmt-clinical-print-meta {
            color: #6c757d;
            font-size: 0.9rem;
        }
        .dcmt-clinical-print-section {
            background: #fff;
            border: 1px solid #e3e8ec;
            border-radius: 12px;
            padding: 1.25rem 1.5rem 1.5rem;
            margin-bottom: 1.25rem;
            box-shadow: 0 1px 4px rgba(0, 0, 0, 0.05);
        }
        .dcmt-clinical-print-section-title {
            font-size: 1.05rem;
            font-weight: 600;
            color: #2979C9;
            margin: 0 0 1rem;
            padding-bottom: 0.5rem;
            border-bottom: 2px solid #e9ecef;
        }
        .dcmt-clinical-print-odontogram .dcmt-odontogram-section-wrap {
            box-shadow: none;
            border: 0;
            padding: 0;
            margin: 0;
        }
        .dcmt-clinical-print-odontogram .dcmt-odontogram-section-header,
        .dcmt-clinical-print-odontogram .dcmt-odontogram-tab-section,
        .dcmt-clinical-print-odontogram .dcmt-odontogram-tab-content {
            display: none !important;
        }
        .dcmt-clinical-print-page-charts {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .dcmt-clinical-print-page-charts .dcmt-odontogram-dual-block--solution {
            margin-top: 1rem;
        }
        .dcmt-clinical-print-page-zonas {
            page-break-before: always;
            break-before: page;
        }
        .dcmt-clinical-print-page-notes {
            page-break-before: always;
            break-before: page;
        }
        .dcmt-clinical-print-odontogram .dcmt-odontogram-help {
            display: none;
        }
        .dcmt-clinical-print-odontogram .dcmt-odontogram-root,
        .dcmt-clinical-print-zonas .dcmt-odontogram-root {
            padding: 0.75rem 0;
            margin: 0;
            border: 0;
            box-shadow: none;
            background: transparent;
        }
        .dcmt-clinical-print-odontogram .dcmt-crosshair-plate {
            break-inside: avoid;
            page-break-inside: avoid;
            margin-bottom: 0;
        }
        .dcmt-clinical-print-zonas .dcmt-odontogram-zonas {
            display: block;
            margin-top: 0;
        }
        .dcmt-clinical-print-zonas .dcmt-zona-card {
            break-inside: avoid;
            page-break-inside: avoid;
            min-height: 0;
            margin-bottom: 1rem;
        }
        .dcmt-clinical-print-zonas .dcmt-zona-card:last-child {
            margin-bottom: 0;
        }
        .dcmt-clinical-print-zonas .dcmt-zona-quadrant {
            min-height: 0;
            height: auto;
        }
        .dcmt-clinical-print-zonas .dcmt-zona-q-list {
            max-height: none;
            overflow: visible;
        }
        .dcmt-clinical-print-zonas .dcmt-odontogram-dual-heading {
            display: none;
        }
        .dcmt-clinical-print-notes .dcmt-note-card {
            break-inside: avoid;
            page-break-inside: avoid;
        }
        .dcmt-clinical-print-notes .dcmt-note-card-body {
            max-height: none !important;
            overflow: visible !important;
        }
        .dcmt-clinical-print-notes .dcmt-note-card-footer {
            display: none;
        }
        .dcmt-clinical-print-empty {
            color: #6c757d;
            font-style: italic;
            margin: 0;
        }
        @media print {
            body.dcmt-clinical-print-page {
                background: #fff;
            }
            .dcmt-clinical-print-toolbar {
                display: none !important;
            }
            .dcmt-clinical-print-sheet {
                max-width: none;
                margin: 0;
                padding: 0;
            }
            .dcmt-clinical-print-header,
            .dcmt-clinical-print-section {
                box-shadow: none;
                border-color: #dee2e6;
            }
            .dcmt-clinical-print-page-zonas,
            .dcmt-clinical-print-page-notes {
                page-break-before: always;
                break-before: page;
            }
            .dcmt-clinical-print-zonas .dcmt-zona-card {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            .dcmt-clinical-print-zonas .dcmt-zona-quadrant {
                min-height: 0;
            }
            .dcmt-clinical-print-zonas .dcmt-zona-q-list {
                max-height: none;
                overflow: visible;
            }
            .dcmt-clinical-print-page-charts .dcmt-crosshair-plate {
                break-inside: avoid;
                page-break-inside: avoid;
            }
            * {
                -webkit-print-color-adjust: exact;
                print-color-adjust: exact;
            }
        }
    </style>
</head>
<body class="dcmt-clinical-print-page">
    <div class="dcmt-clinical-print-toolbar dcmt-no-print">
        <a href="<?php echo htmlspecialchars($back_url); ?>" class="dcmt-add-form-view-all-link">
            <i class="fas fa-arrow-left me-1"></i><?php echo htmlspecialchars(trans('patient_note', 'back_to_notes')); ?>
        </a>
        <button type="button" class="btn btn-primary btn-sm" id="dcmtClinicalPrintBtn">
            <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('patient', 'odontogram_print')); ?>
        </button>
    </div>

    <div class="dcmt-clinical-print-sheet">
        <header class="dcmt-clinical-print-header">
            <h1><?php echo htmlspecialchars(trans('patient_note', 'print_clinical_history')); ?></h1>
            <div class="dcmt-clinical-print-meta">
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

        <div id="dcmtOdontogramPrintWrap">
            <section class="dcmt-clinical-print-section dcmt-clinical-print-odontogram dcmt-clinical-print-page-charts">
                <h2 class="dcmt-clinical-print-section-title">
                    <i class="fas fa-tooth me-2"></i>
                    <?php echo htmlspecialchars(trans('patient', 'odontogram_title')); ?>
                </h2>
                <?php if ($dcmt_od_odontogram_has_chart_data): ?>
                    <?php if ($dcmt_od_problem_has_data): ?>
                        <?php
                        $dcmt_od_chart_key = 'problem';
                        $dcmt_od_chart_title = trans('patient', 'odontogram_section_problem');
                        $dcmt_od_chart_initial_json = $dcmt_od_problem_chart_json;
                        $dcmt_od_hide_zonas = false;
                        $dcmt_od_hide_crosshair = false;
                        unset($dcmt_od_instance_suffix);
                        include __DIR__ . '/../patients/odontogram_chart_inc.php';
                        ?>
                    <?php endif; ?>
                    <?php if ($dcmt_od_solution_has_data): ?>
                        <?php
                        $dcmt_od_chart_key = 'solution';
                        $dcmt_od_chart_title = trans('patient', 'odontogram_section_solution');
                        $dcmt_od_chart_initial_json = $dcmt_od_solution_chart_json;
                        $dcmt_od_hide_zonas = true;
                        $dcmt_od_hide_crosshair = false;
                        unset($dcmt_od_instance_suffix);
                        include __DIR__ . '/../patients/odontogram_chart_inc.php';
                        ?>
                    <?php endif; ?>
                <?php else: ?>
                    <p class="dcmt-clinical-print-empty"><?php echo htmlspecialchars(trans('patient', 'odontogram_no_data')); ?></p>
                <?php endif; ?>
            </section>

            <?php if ($dcmt_od_solution_has_data): ?>
            <section class="dcmt-clinical-print-section dcmt-clinical-print-zonas dcmt-clinical-print-page-zonas">
                <?php
                $dcmt_od_chart_key = 'solution';
                $dcmt_od_chart_title = trans('patient', 'odontogram_section_solution');
                $dcmt_od_chart_initial_json = $dcmt_od_solution_chart_json;
                $dcmt_od_hide_zonas = false;
                $dcmt_od_hide_crosshair = true;
                $dcmt_od_instance_suffix = 'solution-zonas';
                include __DIR__ . '/../patients/odontogram_chart_inc.php';
                unset($dcmt_od_instance_suffix, $dcmt_od_hide_zonas, $dcmt_od_hide_crosshair);
                ?>
            </section>
            <?php endif; ?>
        </div>

        <section class="dcmt-clinical-print-section dcmt-clinical-print-notes dcmt-clinical-print-page-notes">
            <h2 class="dcmt-clinical-print-section-title">
                <i class="fas fa-sticky-note me-2"></i>
                <?php echo htmlspecialchars(trans('patient_note', 'patient_notes')); ?>
                <span class="text-muted fw-normal" style="font-size: 0.9rem;">(<?php echo count($notes); ?>)</span>
            </h2>
            <?php if (empty($notes)): ?>
                <p class="dcmt-clinical-print-empty"><?php echo htmlspecialchars(trans('patient_note', 'no_notes_found')); ?></p>
            <?php else: ?>
                <div class="dcmt-note-list">
                    <?php foreach ($notes as $note): ?>
                        <?php
                        $topic = trim((string) ($note['dcmt_topic'] ?? ''));
                        $title_fallback = trim(substr((string) ($note['dcmt_note_text'] ?? ''), 0, 40));
                        $title_text = $topic !== '' ? $topic : ($title_fallback !== '' ? $title_fallback : trans('patient_note', 'note'));
                        $date_value = $note['dcmt_note_date'] ?? $note['dcmt_created_at'];
                        $date_display = dcmt_format_date($date_value);
                        $note_text = (string) ($note['dcmt_note_text'] ?? '');
                        $created_by = trim((string) ($note['created_by_name'] ?? ''));
                        ?>
                        <article class="dcmt-note-card">
                            <div class="dcmt-note-card-header">
                                <div class="dcmt-note-card-title"><?php echo htmlspecialchars($title_text); ?></div>
                                <div class="dcmt-note-card-date">
                                    <?php echo trans('common', 'date'); ?>: <?php echo $date_display; ?>
                                </div>
                            </div>
                            <div class="dcmt-note-card-body dcmt-note-card-body--expanded"><?php echo nl2br(htmlspecialchars($note_text)); ?></div>
                            <?php if ($created_by !== ''): ?>
                                <div class="px-3 pb-2 text-muted small">
                                    <?php echo htmlspecialchars(trans('common', 'created_by')); ?>: <?php echo htmlspecialchars($created_by); ?>
                                </div>
                            <?php endif; ?>
                        </article>
                    <?php endforeach; ?>
                </div>
            <?php endif; ?>
        </section>
    </div>

    <script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
    <script src="<?php echo dcmt_asset('assets/js/odontogram.js', '../../'); ?>"></script>
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        var printBtn = document.getElementById('dcmtClinicalPrintBtn');
        if (printBtn) {
            printBtn.addEventListener('click', function() {
                window.print();
            });
        }
    });
    </script>
</body>
</html>
