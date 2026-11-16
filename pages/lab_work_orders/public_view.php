<?php
/**
 * Lab Work Orders - Public view (no login required).
 *
 * Reached through a random token generated when a work order is stored for an
 * unconnected lab. Price / payment information is intentionally NOT displayed.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../includes/lab_functions.php';

$token = trim((string) ($_GET['token'] ?? ''));

$order = null;
try {
    dcmt_ensure_lab_tables($dcmt_pdo);

    if ($token !== '' && preg_match('/^[a-f0-9]{32,64}$/i', $token)) {
        $stmt = $dcmt_pdo->prepare("
            SELECT w.*
            FROM dcmt_lab_work_orders w
            INNER JOIN dcmt_lab_connections c ON c.dcmt_id = w.dcmt_lab_connection_id
            WHERE w.dcmt_public_token = ?
            LIMIT 1
        ");
        $stmt->execute([$token]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($row) {
            $order = $row;
        }
    }
} catch (Throwable $e) {
    error_log('Public lab work order view error: ' . $e->getMessage());
    $order = null;
}

if (!$order) {
    http_response_code(404);
}

if (!headers_sent()) {
    header('X-Robots-Tag: noindex, nofollow');
}

$site_name = dcmt_get_site_name();
$logo_path = dcmt_get_logo_path();
$logo_exists = $logo_path !== '' && file_exists(__DIR__ . '/../../' . ltrim($logo_path, '/'));

if ($order) {
    $created_at = trim((string) ($order['dcmt_created_at'] ?? ''));
    $created_display = $created_at !== '' && strtotime($created_at)
        ? date('M j, Y g:i A', (int) strtotime($created_at))
        : '—';
    $delivery_raw = trim((string) ($order['dcmt_delivery_date'] ?? ''));
    $delivery_display = $delivery_raw !== '' && strtotime($delivery_raw)
        ? date('M j, Y', (int) strtotime($delivery_raw))
        : '—';
    $status_raw = trim((string) ($order['dcmt_remote_status'] ?? ''));
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title><?php echo htmlspecialchars(trans('lab', 'public_page_title')); ?> — <?php echo htmlspecialchars($site_name); ?></title>
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/css/bootstrap.min.css" rel="stylesheet">
    <link href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css" rel="stylesheet">
    <style>
        :root {
            --dcmt-public-primary: #0f766e;
            --dcmt-public-primary-dark: #115e59;
        }
        body {
            background: linear-gradient(160deg, #f1f5f9 0%, #e2e8f0 100%);
            min-height: 100vh;
            color: #1f2937;
            font-size: 0.95rem;
        }
        .dcmt-public-wrap {
            max-width: 900px;
            margin: 0 auto;
            padding: 2rem 1rem 3rem;
        }
        .dcmt-public-card {
            background: #fff;
            border: 0;
            border-radius: 16px;
            box-shadow: 0 18px 45px rgba(15, 23, 42, 0.10);
            overflow: hidden;
        }
        .dcmt-public-head {
            background: linear-gradient(135deg, var(--dcmt-public-primary) 0%, var(--dcmt-public-primary-dark) 100%);
            color: #fff;
            padding: 1.75rem 1.75rem 1.5rem;
        }
        .dcmt-public-head h1 {
            font-size: 1.35rem;
            font-weight: 600;
            margin: 0;
        }
        .dcmt-public-head p {
            margin: 0.25rem 0 0;
            color: rgba(255, 255, 255, 0.85);
            font-size: 0.85rem;
        }
        .dcmt-public-folio {
            font-size: 1.6rem;
            font-weight: 700;
            letter-spacing: 0.01em;
        }
        .dcmt-public-body {
            padding: 1.75rem;
        }
        .dcmt-public-section-title {
            font-size: 0.75rem;
            letter-spacing: 0.08em;
            text-transform: uppercase;
            color: #64748b;
            font-weight: 700;
            margin-bottom: 0.75rem;
        }
        .dcmt-public-label {
            font-size: 0.75rem;
            text-transform: uppercase;
            letter-spacing: 0.04em;
            color: #6b7280;
            margin-bottom: 0.15rem;
        }
        .dcmt-public-value {
            font-weight: 600;
            color: #111827;
            word-break: break-word;
        }
        .dcmt-public-muted {
            color: #9ca3af;
            font-weight: 500;
        }
        .dcmt-public-note {
            white-space: pre-line;
            background: #f8fafc;
            border: 1px solid #e2e8f0;
            border-radius: 10px;
            padding: 0.9rem 1rem;
            color: #374151;
        }
        .dcmt-public-footer {
            border-top: 1px solid #e5e7eb;
            padding: 1rem 1.75rem;
            font-size: 0.8rem;
            color: #6b7280;
            display: flex;
            flex-wrap: wrap;
            gap: 0.75rem;
            justify-content: space-between;
            align-items: center;
        }
        .dcmt-public-empty {
            max-width: 520px;
            margin: 4rem auto;
        }
        @media print {
            body { background: #fff; }
            .dcmt-public-wrap { padding: 0; }
            .dcmt-public-card { box-shadow: none; border: 1px solid #ddd; }
            .dcmt-no-print { display: none !important; }
        }
    </style>
</head>
<body>
<div class="dcmt-public-wrap">
    <?php if (!$order): ?>
        <div class="dcmt-public-card dcmt-public-empty text-center">
            <div class="dcmt-public-body py-5">
                <i class="fas fa-link-slash fa-3x text-muted mb-3"></i>
                <h1 class="h5 mb-2"><?php echo htmlspecialchars(trans('lab', 'public_link_invalid_title')); ?></h1>
                <p class="text-muted mb-0"><?php echo htmlspecialchars(trans('lab', 'public_link_invalid_message')); ?></p>
            </div>
        </div>
    <?php else: ?>
        <div class="dcmt-public-card">
            <div class="dcmt-public-head">
                <div class="d-flex flex-wrap justify-content-between align-items-start gap-3">
                    <div>
                        <div class="d-flex align-items-center gap-2 mb-2">
                            <?php if ($logo_exists): ?>
                                <img src="../../<?php echo htmlspecialchars(ltrim($logo_path, '/')); ?>"
                                     alt="" style="height: 38px; width: auto; background: #fff; border-radius: 8px; padding: 3px;">
                            <?php else: ?>
                                <i class="fas fa-tooth fa-lg"></i>
                            <?php endif; ?>
                            <strong><?php echo htmlspecialchars($site_name); ?></strong>
                        </div>
                        <h1><?php echo htmlspecialchars(trans('lab', 'public_page_title')); ?></h1>
                        <p><?php echo htmlspecialchars(trans('lab', 'public_page_subtitle')); ?></p>
                    </div>
                    <div class="text-md-end">
                        <div class="dcmt-public-label text-white-50"><?php echo htmlspecialchars(trans('lab', 'folio_number')); ?></div>
                        <div class="dcmt-public-folio"><?php echo htmlspecialchars(($order['dcmt_folio_number'] ?? '') !== '' ? $order['dcmt_folio_number'] : '—'); ?></div>
                        <?php if ($status_raw !== ''): ?>
                            <span class="badge bg-light text-dark mt-1"><?php echo htmlspecialchars($status_raw); ?></span>
                        <?php endif; ?>
                    </div>
                </div>
            </div>

            <div class="dcmt-public-body">
                <div class="dcmt-public-section-title"><?php echo htmlspecialchars(trans('lab', 'public_work_order_details')); ?></div>
                <div class="row g-3">
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'patient_name')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars(($order['dcmt_patient_name'] ?? '') !== '' ? $order['dcmt_patient_name'] : '—'); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'doctor_name')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars(($order['dcmt_doctor_name'] ?? '') !== '' ? $order['dcmt_doctor_name'] : '—'); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'prosthesis_type')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars(($order['dcmt_prosthesis_type_name'] ?? '') !== '' ? $order['dcmt_prosthesis_type_name'] : (($order['dcmt_prosthesis_type_id'] ?? '') !== '' ? $order['dcmt_prosthesis_type_id'] : '—')); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'delivery_date')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars($delivery_display); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'box_number')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars(($order['dcmt_box_number'] ?? '') !== '' ? $order['dcmt_box_number'] : '—'); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'file_number')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars(($order['dcmt_file_number'] ?? '') !== '' ? $order['dcmt_file_number'] : '—'); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'color')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars(($order['dcmt_color'] ?? '') !== '' ? $order['dcmt_color'] : '—'); ?></div>
                    </div>
                    <div class="col-sm-6 col-lg-4">
                        <div class="dcmt-public-label"><?php echo htmlspecialchars(trans('lab', 'created_at')); ?></div>
                        <div class="dcmt-public-value"><?php echo htmlspecialchars($created_display); ?></div>
                    </div>
                </div>

                <?php if (!empty($order['dcmt_specification'])): ?>
                    <div class="mt-4">
                        <div class="dcmt-public-label mb-2"><?php echo htmlspecialchars(trans('lab', 'specification')); ?></div>
                        <div class="dcmt-public-note"><?php echo nl2br(htmlspecialchars($order['dcmt_specification'])); ?></div>
                    </div>
                <?php endif; ?>

                <?php if (!empty($order['dcmt_notes'])): ?>
                    <div class="mt-3">
                        <div class="dcmt-public-label mb-2"><?php echo htmlspecialchars(trans('lab', 'work_order_notes')); ?></div>
                        <div class="dcmt-public-note"><?php echo nl2br(htmlspecialchars($order['dcmt_notes'])); ?></div>
                    </div>
                <?php endif; ?>

                <p class="small text-muted mt-4 mb-0">
                    <i class="fas fa-eye-slash me-1"></i><?php echo htmlspecialchars(trans('lab', 'public_no_price_notice')); ?>
                </p>
            </div>

            <div class="dcmt-public-footer">
                <span><?php echo htmlspecialchars(trans('lab', 'public_footer_note')); ?></span>
                <button type="button" class="btn btn-sm btn-outline-secondary dcmt-no-print" onclick="window.print();">
                    <i class="fas fa-print me-1"></i><?php echo htmlspecialchars(trans('lab', 'print')); ?>
                </button>
            </div>
        </div>
    <?php endif; ?>
</div>
</body>
</html>
