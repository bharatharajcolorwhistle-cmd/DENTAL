<?php
/**
 * CLI tests for patient advance remaining balance and FIFO drawdown.
 *
 * Run: php tests/patient_advance_test.php
 */

declare(strict_types=1);

if (PHP_SAPI !== 'cli') {
    fwrite(STDERR, "Run this test from CLI only.\n");
    exit(1);
}

$root = dirname(__DIR__);
require_once $root . '/config/config.php';
require_once $root . '/config/database.php';
require_once $root . '/includes/income_payment_history.php';
require_once $root . '/includes/patient_advance_functions.php';

$failures = 0;

function dcmt_test_assert(bool $condition, string $message): void
{
    global $failures;
    if ($condition) {
        fwrite(STDOUT, "  OK: {$message}\n");
        return;
    }
    $failures++;
    fwrite(STDERR, "  FAIL: {$message}\n");
}

fwrite(STDOUT, "Patient advance tests\n\n");

dcmt_test_assert(
    dcmt_payment_history_is_advance_application('{"source":"patient_advance"}'),
    'notes JSON is detected as an advance application'
);
dcmt_test_assert(
    !dcmt_payment_history_is_advance_application('cash payment'),
    'plain notes are not treated as an advance application'
);
dcmt_test_assert(
    dcmt_patient_advance_money(10.555) === 10.56,
    'money helper rounds to two decimals'
);

if (!isset($dcmt_pdo) || !($dcmt_pdo instanceof PDO)) {
    fwrite(STDERR, "No database connection; skipping integration tests.\n");
    exit($failures > 0 ? 1 : 0);
}

dcmt_patient_advance_ensure_tables($dcmt_pdo);
dcmt_test_assert(dcmt_patient_advance_tables_exist($dcmt_pdo), 'advance tables exist after ensure');

$dcmt_pdo->beginTransaction();
try {
    $stmt = $dcmt_pdo->prepare("
        INSERT INTO dcmt_patients (dcmt_patient_name, dcmt_phone, dcmt_status, dcmt_created_by)
        VALUES (?, ?, 'active', 'test')
    ");
    $stmt->execute(['Advance Test Patient ' . uniqid('', true), '0000000000']);
    $patientId = (int) $dcmt_pdo->lastInsertId();
    dcmt_test_assert($patientId > 0, 'test patient inserted');

    $methodId = 0;
    try {
        $methodStmt = $dcmt_pdo->query("SELECT dcmt_id FROM dcmt_income_payment_methods WHERE dcmt_status = 'active' LIMIT 1");
        $methodId = (int) $methodStmt->fetchColumn();
    } catch (PDOException $e) {
        $methodId = 0;
    }

    $created = dcmt_patient_advance_create($dcmt_pdo, [
        'patient_id' => $patientId,
        'amount' => 100,
        'reason' => 'Upcoming implant',
        'notes' => '',
        'received_on' => date('Y-m-d'),
        'payment_method_id' => $methodId,
    ], ['dcmt_username' => 'test']);

    if ($methodId <= 0) {
        dcmt_test_assert(empty($created['success']), 'create fails without a payment method');
        $dcmt_pdo->rollBack();
        fwrite(STDOUT, "\nSkipped FIFO tests (no payment methods in database).\n");
        exit($failures > 0 ? 1 : 0);
    }

    dcmt_test_assert(!empty($created['success']), 'advance create succeeds');
    dcmt_test_assert(dcmt_patient_advance_remaining($dcmt_pdo, $patientId) === 100.00, 'remaining equals deposit');

    $second = dcmt_patient_advance_create($dcmt_pdo, [
        'patient_id' => $patientId,
        'amount' => 50,
        'reason' => 'Second deposit',
        'notes' => '',
        'received_on' => date('Y-m-d', strtotime('+1 day')),
        'payment_method_id' => $methodId,
    ], ['dcmt_username' => 'test']);
    dcmt_test_assert(!empty($second['success']), 'second advance create succeeds');
    dcmt_test_assert(dcmt_patient_advance_remaining($dcmt_pdo, $patientId) === 150.00, 'remaining sums both deposits');

    $planned = dcmt_patient_advance_planned_drawdown($dcmt_pdo, $patientId, 120.00, 20.00, null);
    dcmt_test_assert($planned === 100.00, 'planned drawdown is min(remaining, unpaid)');

    $incomeStmt = $dcmt_pdo->prepare("
        INSERT INTO dcmt_income (
            dcmt_patient_name, dcmt_patient_id, dcmt_type, dcmt_amount,
            dcmt_payment_mode, dcmt_payment_status, dcmt_transaction_date, dcmt_created_by
        ) VALUES (?, ?, 'consultation', 120.00, 'cash', 'pending', ?, 'test')
    ");
    $incomeStmt->execute(['Advance Test Patient', $patientId, date('Y-m-d')]);
    $incomeId = (int) $dcmt_pdo->lastInsertId();
    dcmt_test_assert($incomeId > 0, 'test income inserted');

    $applied = dcmt_patient_advance_apply_to_income(
        $dcmt_pdo,
        $patientId,
        $incomeId,
        120.00,
        date('Y-m-d'),
        'test',
        'consultation'
    );
    dcmt_test_assert($applied === 120.00, 'FIFO applies 120 from 100 + 20 of the second deposit');
    dcmt_test_assert(dcmt_patient_advance_remaining($dcmt_pdo, $patientId) === 30.00, '30 remains after drawdown');

    $hist = $dcmt_pdo->prepare("SELECT dcmt_notes, dcmt_amount FROM dcmt_income_payment_history WHERE dcmt_income_id = ?");
    $hist->execute([$incomeId]);
    $histRow = $hist->fetch(PDO::FETCH_ASSOC);
    dcmt_test_assert(
        $histRow && dcmt_payment_history_is_advance_application($histRow['dcmt_notes'] ?? null),
        'drawdown writes a tagged payment-history row'
    );

    $restored = dcmt_patient_advance_reverse_for_income($dcmt_pdo, $incomeId);
    dcmt_test_assert($restored === 120.00, 'reverse restores the applied amount');
    dcmt_test_assert(dcmt_patient_advance_remaining($dcmt_pdo, $patientId) === 150.00, 'remaining is fully restored after reverse');

    $dcmt_pdo->rollBack();
    fwrite(STDOUT, "Rolled back test data.\n");
} catch (Throwable $e) {
    if ($dcmt_pdo->inTransaction()) {
        $dcmt_pdo->rollBack();
    }
    fwrite(STDERR, 'Integration test error: ' . $e->getMessage() . "\n");
    $failures++;
}

fwrite(STDOUT, $failures === 0 ? "\nAll tests passed.\n" : "\n{$failures} test(s) failed.\n");
exit($failures > 0 ? 1 : 0);
