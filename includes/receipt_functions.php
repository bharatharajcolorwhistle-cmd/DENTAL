<?php
/**
 * Patient payment receipt (ticket) helpers.
 *
 * Builds normalized receipt data from the actual payment history and renders
 * a standalone, printable receipt page (A4/Letter or 80mm thermal).
 */

require_once __DIR__ . '/income_payment_history.php';
require_once __DIR__ . '/patient_advance_functions.php';

if (!function_exists('dcmt_receipt_h')) {
    function dcmt_receipt_h($value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
    }
}

if (!function_exists('dcmt_receipt_number')) {
    function dcmt_receipt_number(string $prefix, int $id): string
    {
        return $prefix . '-' . str_pad((string) max(0, $id), 6, '0', STR_PAD_LEFT);
    }
}

if (!function_exists('dcmt_receipt_clinic_setting')) {
    /**
     * Read a clinic setting for the receipt header.
     *
     * Some installs seeded the setting description (e.g. "Address of the dental
     * clinic") into the value column. Those placeholder texts must never be
     * printed on a patient ticket, so they are treated as empty.
     */
    function dcmt_receipt_clinic_setting(string $key): string
    {
        global $dcmt_pdo;

        if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
            return '';
        }

        try {
            $stmt = $dcmt_pdo->prepare("
                SELECT dcmt_setting_value, dcmt_setting_description
                FROM dcmt_settings
                WHERE dcmt_setting_key = ?
                LIMIT 1
            ");
            $stmt->execute([$key]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('dcmt_receipt_clinic_setting: ' . $e->getMessage());
            return '';
        }

        if (!$row) {
            return '';
        }

        $value = trim((string) ($row['dcmt_setting_value'] ?? ''));
        if ($value === '') {
            return '';
        }

        $description = trim((string) ($row['dcmt_setting_description'] ?? ''));
        if ($description !== '' && strcasecmp($value, $description) === 0) {
            return '';
        }

        return $value;
    }
}

if (!function_exists('dcmt_receipt_clinic')) {
    function dcmt_receipt_clinic(): array
    {
        $logo_path = function_exists('dcmt_get_logo_path') ? (string) dcmt_get_logo_path() : '';
        $logo_url = '';
        if ($logo_path !== '') {
            $logo_file = dirname(__DIR__) . DIRECTORY_SEPARATOR . str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $logo_path);
            if (is_file($logo_file) && defined('DCMT_APP_URL')) {
                $logo_url = rtrim(DCMT_APP_URL, '/') . '/' . ltrim(str_replace('\\', '/', $logo_path), '/');
            }
        }

        $clinic_name = function_exists('dcmt_get_site_name') ? trim((string) dcmt_get_site_name()) : '';
        if ($clinic_name === '') {
            $clinic_name = defined('DCMT_APP_NAME') ? DCMT_APP_NAME : 'Dental Clinic';
        }

        return [
            'name' => $clinic_name,
            'address' => dcmt_receipt_clinic_setting('clinic_address'),
            'phone' => dcmt_receipt_clinic_setting('clinic_phone'),
            'logo_url' => $logo_url,
        ];
    }
}

if (!function_exists('dcmt_receipt_method_name')) {
    function dcmt_receipt_method_name(PDO $pdo, ?int $methodId, string $fallback = ''): string
    {
        if ($methodId === null || $methodId <= 0) {
            return $fallback;
        }
        try {
            $stmt = $pdo->prepare("SELECT dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_id = ? LIMIT 1");
            $stmt->execute([$methodId]);
            $name = $stmt->fetchColumn();
            if ($name !== false && trim((string) $name) !== '') {
                return (string) $name;
            }
        } catch (PDOException $e) {
            error_log('dcmt_receipt_method_name: ' . $e->getMessage());
        }
        return $fallback;
    }
}

if (!function_exists('dcmt_receipt_translated_method')) {
    function dcmt_receipt_translated_method(string $name): string
    {
        if ($name === '') {
            return trans('common', 'not_available');
        }
        $translated = trans('income_payment_method', $name);
        return ($translated !== $name) ? $translated : $name;
    }
}

if (!function_exists('dcmt_receipt_payment_data')) {
    /**
     * Normalized receipt for a single collected payment.
     */
    function dcmt_receipt_payment_data(PDO $pdo, int $paymentId): ?array
    {
        if ($paymentId <= 0) {
            return null;
        }

        $sql = "
            SELECT iph.*,
                   i.dcmt_patient_name, i.dcmt_patient_id, i.dcmt_appointment_id,
                   i.dcmt_description, i.dcmt_type, i.dcmt_amount AS income_amount,
                   i.dcmt_total_paid_amount, i.dcmt_total_pending_amount,
                   i.dcmt_transaction_date,
                   p.dcmt_phone AS patient_phone,
                   ru.dcmt_full_name AS recorded_by_name
            FROM dcmt_income_payment_history iph
            LEFT JOIN dcmt_income i ON iph.dcmt_income_id = i.dcmt_id
            LEFT JOIN dcmt_patients p ON i.dcmt_patient_id = p.dcmt_id
            LEFT JOIN dcmt_users ru ON iph.dcmt_recorded_by COLLATE utf8mb4_general_ci = ru.dcmt_username
            WHERE iph.dcmt_id = ?
            LIMIT 1
        ";

        try {
            $stmt = $pdo->prepare($sql);
            $stmt->execute([$paymentId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('dcmt_receipt_payment_data: ' . $e->getMessage());
            return null;
        }

        if (!$row) {
            return null;
        }

        $incomeId = (int) ($row['dcmt_income_id'] ?? 0);
        $incomeTotal = (float) ($row['income_amount'] ?? 0);
        $methodId = dcmt_payment_history_resolve_method_id($row);
        $methodName = dcmt_receipt_method_name($pdo, $methodId, trim((string) ($row['payment_method_name'] ?? '')));

        $cumulative = (float) ($row['dcmt_amount'] ?? 0);
        try {
            $sumStmt = $pdo->prepare("
                SELECT COALESCE(SUM(dcmt_amount), 0)
                FROM dcmt_income_payment_history
                WHERE dcmt_income_id = ? AND dcmt_id <= ?
            ");
            $sumStmt->execute([$incomeId, $paymentId]);
            $cumulative = (float) $sumStmt->fetchColumn();
        } catch (PDOException $e) {
            error_log('dcmt_receipt_payment_data sum: ' . $e->getMessage());
        }

        $isAdvance = function_exists('dcmt_payment_history_is_advance_application')
            && dcmt_payment_history_is_advance_application($row['dcmt_notes'] ?? null);

        $methodLabel = $isAdvance && $methodName === ''
            ? trans('receipt', 'advance_applied')
            : dcmt_receipt_translated_method($methodName);

        return [
            'type' => 'payment',
            'id' => $paymentId,
            'income_id' => $incomeId,
            'appointment_id' => (int) ($row['dcmt_appointment_id'] ?? 0),
            'receipt_no' => dcmt_receipt_number('R', $paymentId),
            'title' => trans('receipt', 'payment_title'),
            'payment_date' => (string) ($row['dcmt_paid_on'] ?? ''),
            'patient_name' => (string) ($row['dcmt_patient_name'] ?? ''),
            'patient_phone' => (string) ($row['patient_phone'] ?? ''),
            'payment_method' => $methodLabel,
            'amount' => (float) ($row['dcmt_amount'] ?? 0),
            'amount_label' => $isAdvance ? trans('receipt', 'advance_applied') : trans('receipt', 'amount_received'),
            'income_total' => $incomeTotal,
            'balance_after' => max($incomeTotal - $cumulative, 0),
            'description' => trim((string) ($row['dcmt_description'] ?? '')),
            'recorded_by' => (string) ($row['recorded_by_name'] ?? ($row['dcmt_recorded_by'] ?? '')),
            'is_advance' => $isAdvance,
            'payments' => [],
        ];
    }
}

if (!function_exists('dcmt_receipt_income_data')) {
    /**
     * Normalized receipt for an income record and its collected payments.
     */
    function dcmt_receipt_income_data(PDO $pdo, int $incomeId): ?array
    {
        if ($incomeId <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT i.*, p.dcmt_phone AS patient_phone,
                       u.dcmt_full_name AS created_by_name
                FROM dcmt_income i
                LEFT JOIN dcmt_patients p ON i.dcmt_patient_id = p.dcmt_id
                LEFT JOIN dcmt_users u ON i.dcmt_created_by COLLATE utf8mb4_unicode_ci = u.dcmt_username COLLATE utf8mb4_unicode_ci
                WHERE i.dcmt_id = ?
                LIMIT 1
            ");
            $stmt->execute([$incomeId]);
            $income = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('dcmt_receipt_income_data: ' . $e->getMessage());
            return null;
        }

        if (!$income) {
            return null;
        }

        $payments = [];
        $cashCollected = 0.0;
        $advanceCollected = 0.0;
        try {
            $stmt = $pdo->prepare("
                SELECT iph.*, ru.dcmt_full_name AS recorded_by_name
                FROM dcmt_income_payment_history iph
                LEFT JOIN dcmt_users ru ON iph.dcmt_recorded_by COLLATE utf8mb4_general_ci = ru.dcmt_username
                WHERE iph.dcmt_income_id = ?
                ORDER BY iph.dcmt_paid_on ASC, iph.dcmt_id ASC
            ");
            $stmt->execute([$incomeId]);
            $rows = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];

            foreach ($rows as $row) {
                $methodId = dcmt_payment_history_resolve_method_id($row);
                $methodName = dcmt_receipt_method_name($pdo, $methodId);
                $isAdvance = function_exists('dcmt_payment_history_is_advance_application')
                    && dcmt_payment_history_is_advance_application($row['dcmt_notes'] ?? null);
                $amount = (float) ($row['dcmt_amount'] ?? 0);
                if ($isAdvance) {
                    $advanceCollected += $amount;
                } else {
                    $cashCollected += $amount;
                }
                $payments[] = [
                    'id' => (int) ($row['dcmt_id'] ?? 0),
                    'date' => (string) ($row['dcmt_paid_on'] ?? ''),
                    'method' => $isAdvance ? trans('receipt', 'advance_applied') : dcmt_receipt_translated_method($methodName),
                    'amount' => $amount,
                    'is_advance' => $isAdvance,
                ];
            }
        } catch (PDOException $e) {
            error_log('dcmt_receipt_income_data payments: ' . $e->getMessage());
        }

        $totalCollected = $cashCollected + $advanceCollected;
        $incomeTotal = (float) ($income['dcmt_amount'] ?? 0);
        $pending = array_key_exists('dcmt_total_pending_amount', $income) && $income['dcmt_total_pending_amount'] !== null
            ? max((float) $income['dcmt_total_pending_amount'], 0)
            : max($incomeTotal - $totalCollected, 0);

        return [
            'type' => 'income',
            'id' => $incomeId,
            'income_id' => $incomeId,
            'appointment_id' => (int) ($income['dcmt_appointment_id'] ?? 0),
            'receipt_no' => dcmt_receipt_number('I', $incomeId),
            'title' => trans('receipt', 'income_title'),
            'payment_date' => (string) ($income['dcmt_transaction_date'] ?? ''),
            'patient_name' => (string) ($income['dcmt_patient_name'] ?? ''),
            'patient_phone' => (string) ($income['patient_phone'] ?? ''),
            'payment_method' => '',
            'amount' => $cashCollected,
            'amount_label' => trans('receipt', 'amount_received'),
            'advance_applied' => $advanceCollected,
            'total_collected' => $totalCollected,
            'income_total' => $incomeTotal,
            'balance_after' => $pending,
            'description' => trim((string) ($income['dcmt_description'] ?? '')),
            'recorded_by' => (string) ($income['created_by_name'] ?? ($income['dcmt_created_by'] ?? '')),
            'is_advance' => false,
            'payments' => $payments,
        ];
    }
}

if (!function_exists('dcmt_receipt_advance_data')) {
    /**
     * Normalized receipt for a patient advance/deposit.
     */
    function dcmt_receipt_advance_data(PDO $pdo, int $advanceId): ?array
    {
        if ($advanceId <= 0) {
            return null;
        }

        try {
            $stmt = $pdo->prepare("
                SELECT a.*, p.dcmt_patient_name, p.dcmt_phone AS patient_phone,
                       u.dcmt_full_name AS created_by_name
                FROM dcmt_patient_advances a
                LEFT JOIN dcmt_patients p ON a.dcmt_patient_id = p.dcmt_id
                LEFT JOIN dcmt_users u ON a.dcmt_created_by COLLATE utf8mb4_general_ci = u.dcmt_username
                WHERE a.dcmt_id = ?
                LIMIT 1
            ");
            $stmt->execute([$advanceId]);
            $row = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            error_log('dcmt_receipt_advance_data: ' . $e->getMessage());
            return null;
        }

        if (!$row) {
            return null;
        }

        $methodName = dcmt_receipt_method_name($pdo, isset($row['dcmt_payment_method_id']) ? (int) $row['dcmt_payment_method_id'] : null);

        return [
            'type' => 'advance',
            'id' => $advanceId,
            'advance_id' => $advanceId,
            'patient_id' => (int) ($row['dcmt_patient_id'] ?? 0),
            'receipt_no' => dcmt_receipt_number('A', $advanceId),
            'title' => trans('receipt', 'advance_title'),
            'payment_date' => (string) ($row['dcmt_received_on'] ?? ''),
            'patient_name' => (string) ($row['dcmt_patient_name'] ?? ''),
            'patient_phone' => (string) ($row['patient_phone'] ?? ''),
            'payment_method' => dcmt_receipt_translated_method($methodName),
            'amount' => (float) ($row['dcmt_amount'] ?? 0),
            'amount_label' => trans('receipt', 'amount_received'),
            'income_total' => 0.0,
            'balance_after' => (float) ($row['dcmt_remaining_amount'] ?? 0),
            'balance_label' => trans('receipt', 'advance_remaining_balance'),
            'description' => trim((string) ($row['dcmt_reason'] ?? '')),
            'recorded_by' => (string) ($row['created_by_name'] ?? ($row['dcmt_created_by'] ?? '')),
            'is_advance' => true,
            'payments' => [],
        ];
    }
}

if (!function_exists('dcmt_receipt_back_url')) {
    function dcmt_receipt_back_url(array $data): string
    {
        if (($data['type'] ?? '') === 'advance') {
            $patientId = (int) ($data['patient_id'] ?? 0);
            return $patientId > 0
                ? 'patient.php?patient_id=' . $patientId
                : 'index.php';
        }

        if (!empty($data['appointment_id'])) {
            return '../appointments/view.php?id=' . (int) $data['appointment_id'];
        }

        $incomeId = (int) ($data['income_id'] ?? $data['id'] ?? 0);
        return $incomeId > 0 ? 'view.php?id=' . $incomeId : 'index.php';
    }
}

if (!function_exists('dcmt_receipt_render')) {
    /**
     * Render a standalone printable receipt page.
     *
     * @param array $data    Normalized receipt data from the helpers above.
     * @param array $options format (a4|thermal), auto_print, asset_base, format_urls.
     */
    function dcmt_receipt_render(array $data, array $options = []): void
    {
        $format = (($options['format'] ?? 'a4') === 'thermal') ? 'thermal' : 'a4';
        $autoPrint = !empty($options['auto_print']);
        $assetBase = (string) ($options['asset_base'] ?? '../../');
        $formatUrls = is_array($options['format_urls'] ?? null) ? $options['format_urls'] : [];
        $backUrl = (string) ($data['back_url'] ?? '');
        $clinic = $data['clinic'] ?? dcmt_receipt_clinic();
        $lang = defined('DCMT_LANG') ? substr((string) DCMT_LANG, 0, 2) : 'es';
        $pageSize = $format === 'thermal' ? '80mm auto' : 'A4 portrait';
        $pageMargin = $format === 'thermal' ? '4mm' : '12mm';
        $title = (string) ($data['title'] ?? trans('receipt', 'title'));
        $payments = is_array($data['payments'] ?? null) ? $data['payments'] : [];
        $balanceAfter = $data['balance_after'] ?? null;
        $balanceLabel = (string) ($data['balance_label'] ?? trans('receipt', 'remaining_balance'));
        $printedAt = function_exists('dcmt_get_current_datetime')
            ? dcmt_get_current_datetime('Y-m-d H:i')
            : date('Y-m-d H:i');
        ?>
<!DOCTYPE html>
<html lang="<?php echo dcmt_receipt_h($lang); ?>">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title><?php echo dcmt_receipt_h($title); ?> - <?php echo dcmt_receipt_h($data['receipt_no'] ?? ''); ?></title>
    <link href="<?php echo dcmt_receipt_h(dcmt_asset('assets/css/receipt.css', $assetBase)); ?>" rel="stylesheet">
    <style>
        @page { size: <?php echo dcmt_receipt_h($pageSize); ?>; margin: <?php echo dcmt_receipt_h($pageMargin); ?>; }
    </style>
</head>
<body class="dcmt-receipt-body dcmt-receipt-<?php echo dcmt_receipt_h($format); ?>">
    <div class="dcmt-receipt-toolbar dcmt-no-print">
        <button type="button" class="dcmt-receipt-btn dcmt-receipt-btn-primary" id="dcmtReceiptPrintBtn">
            <?php echo dcmt_receipt_h(trans('receipt', 'print')); ?>
        </button>
        <?php if (!empty($formatUrls['a4'])): ?>
            <a class="dcmt-receipt-btn<?php echo $format === 'a4' ? ' is-active' : ''; ?>" href="<?php echo dcmt_receipt_h($formatUrls['a4']); ?>"><?php echo dcmt_receipt_h(trans('receipt', 'format_a4')); ?></a>
        <?php endif; ?>
        <?php if (!empty($formatUrls['thermal'])): ?>
            <a class="dcmt-receipt-btn<?php echo $format === 'thermal' ? ' is-active' : ''; ?>" href="<?php echo dcmt_receipt_h($formatUrls['thermal']); ?>"><?php echo dcmt_receipt_h(trans('receipt', 'format_thermal')); ?></a>
        <?php endif; ?>
        <?php if ($backUrl !== ''): ?>
            <a class="dcmt-receipt-btn dcmt-receipt-btn-back" href="<?php echo dcmt_receipt_h($backUrl); ?>"><?php echo dcmt_receipt_h(trans('receipt', 'back')); ?></a>
        <?php endif; ?>
    </div>

    <div class="dcmt-receipt-sheet">
        <header class="dcmt-receipt-clinic-header">
            <?php if (!empty($clinic['logo_url'])): ?>
                <img src="<?php echo dcmt_receipt_h($clinic['logo_url']); ?>" alt="<?php echo dcmt_receipt_h($clinic['name'] ?? ''); ?>" class="dcmt-receipt-logo">
            <?php endif; ?>
            <div class="dcmt-receipt-clinic-text">
                <div class="dcmt-receipt-clinic-name"><?php echo dcmt_receipt_h($clinic['name'] ?? ''); ?></div>
                <?php if (!empty($clinic['address']) || !empty($clinic['phone'])): ?>
                    <div class="dcmt-receipt-clinic-meta">
                        <?php if (!empty($clinic['address'])): ?><span><?php echo dcmt_receipt_h($clinic['address']); ?></span><?php endif; ?>
                        <?php if (!empty($clinic['phone'])): ?><span><?php echo dcmt_receipt_h($clinic['phone']); ?></span><?php endif; ?>
                    </div>
                <?php endif; ?>
            </div>
        </header>

        <div class="dcmt-receipt-title-row">
            <h1 class="dcmt-receipt-title"><?php echo dcmt_receipt_h($title); ?></h1>
            <div class="dcmt-receipt-meta">
                <div><span><?php echo dcmt_receipt_h(trans('receipt', 'receipt_no')); ?>:</span> <?php echo dcmt_receipt_h($data['receipt_no'] ?? ''); ?></div>
                <div><span><?php echo dcmt_receipt_h(trans('receipt', 'date')); ?>:</span> <?php echo dcmt_receipt_h(dcmt_format_date($data['payment_date'] ?? '')); ?></div>
            </div>
        </div>

        <div class="dcmt-receipt-patient">
            <div><span><?php echo dcmt_receipt_h(trans('receipt', 'patient')); ?>:</span> <strong><?php echo dcmt_receipt_h($data['patient_name'] ?? ''); ?></strong></div>
            <?php if (!empty($data['patient_phone'])): ?>
                <div><span><?php echo dcmt_receipt_h(trans('receipt', 'phone')); ?>:</span> <?php echo dcmt_receipt_h($data['patient_phone']); ?></div>
            <?php endif; ?>
        </div>

        <table class="dcmt-receipt-table">
            <thead>
                <tr>
                    <th><?php echo dcmt_receipt_h(trans('receipt', 'concept')); ?></th>
                    <th><?php echo dcmt_receipt_h(trans('receipt', 'payment_method')); ?></th>
                    <th class="dcmt-receipt-right"><?php echo dcmt_receipt_h(trans('receipt', 'amount')); ?></th>
                </tr>
            </thead>
            <tbody>
                <?php if (!empty($payments)): ?>
                    <?php foreach ($payments as $payment): ?>
                        <tr>
                            <td>
                                <?php echo dcmt_receipt_h(trans('receipt', 'payment')); ?>
                                <span class="dcmt-receipt-muted"><?php echo dcmt_receipt_h(dcmt_format_date($payment['date'] ?? '')); ?></span>
                            </td>
                            <td><?php echo dcmt_receipt_h($payment['method'] ?? ''); ?></td>
                            <td class="dcmt-receipt-right"><?php echo dcmt_receipt_h(dcmt_format_currency((float) ($payment['amount'] ?? 0))); ?></td>
                        </tr>
                    <?php endforeach; ?>
                <?php else: ?>
                    <tr>
                        <td>
                            <?php
                            $description = trim((string) ($data['description'] ?? ''));
                            echo dcmt_receipt_h($description !== '' ? $description : trans('receipt', 'payment'));
                            ?>
                        </td>
                        <td><?php echo dcmt_receipt_h($data['payment_method'] ?? ''); ?></td>
                        <td class="dcmt-receipt-right"><?php echo dcmt_receipt_h(dcmt_format_currency((float) ($data['amount'] ?? 0))); ?></td>
                    </tr>
                <?php endif; ?>
            </tbody>
        </table>

        <div class="dcmt-receipt-summary">
            <div class="dcmt-receipt-amount-box">
                <span class="dcmt-receipt-amount-label"><?php echo dcmt_receipt_h($data['amount_label'] ?? trans('receipt', 'amount_received')); ?></span>
                <span class="dcmt-receipt-amount-value"><?php echo dcmt_receipt_h(dcmt_format_currency((float) ($data['amount'] ?? 0))); ?></span>
            </div>

            <?php if (($data['type'] ?? '') === 'income'): ?>
                <div class="dcmt-receipt-totals">
                    <div><span><?php echo dcmt_receipt_h(trans('receipt', 'income_total')); ?>:</span> <?php echo dcmt_receipt_h(dcmt_format_currency((float) ($data['income_total'] ?? 0))); ?></div>
                    <div><span><?php echo dcmt_receipt_h(trans('receipt', 'total_collected')); ?>:</span> <?php echo dcmt_receipt_h(dcmt_format_currency((float) ($data['total_collected'] ?? $data['amount'] ?? 0))); ?></div>
                    <?php if ((float) ($data['advance_applied'] ?? 0) > 0.009): ?>
                        <div><span><?php echo dcmt_receipt_h(trans('receipt', 'advance_applied')); ?>:</span> <?php echo dcmt_receipt_h(dcmt_format_currency((float) $data['advance_applied'])); ?></div>
                    <?php endif; ?>
                    <div><span><?php echo dcmt_receipt_h(trans('receipt', 'remaining_balance')); ?>:</span> <?php echo dcmt_receipt_h(dcmt_format_currency((float) ($balanceAfter ?? 0))); ?></div>
                </div>
            <?php elseif ($balanceAfter !== null): ?>
                <div class="dcmt-receipt-totals">
                    <div><span><?php echo dcmt_receipt_h($balanceLabel); ?>:</span> <?php echo dcmt_receipt_h(dcmt_format_currency((float) $balanceAfter)); ?></div>
                </div>
            <?php endif; ?>
        </div>

        <div class="dcmt-receipt-footer-info">
            <div><span><?php echo dcmt_receipt_h(trans('receipt', 'received_by')); ?>:</span> <?php echo dcmt_receipt_h($data['recorded_by'] ?? ''); ?></div>
            <div><span><?php echo dcmt_receipt_h(trans('receipt', 'printed_at')); ?>:</span> <?php echo dcmt_receipt_h($printedAt); ?></div>
        </div>

        <div class="dcmt-receipt-signature">
            <span><?php echo dcmt_receipt_h(trans('receipt', 'authorized_signature')); ?></span>
        </div>

        <p class="dcmt-receipt-note">
            <?php echo dcmt_receipt_h(trans('receipt', 'thank_you')); ?><br>
            <?php echo dcmt_receipt_h(trans('receipt', 'non_fiscal_note')); ?>
        </p>
    </div>

    <script>
        (function () {
            var printBtn = document.getElementById('dcmtReceiptPrintBtn');
            if (printBtn) {
                printBtn.addEventListener('click', function () {
                    window.print();
                });
            }
            <?php if ($autoPrint): ?>
            window.addEventListener('load', function () {
                setTimeout(function () { window.print(); }, 350);
            });
            <?php endif; ?>
        })();
    </script>
</body>
</html>
        <?php
    }
}
