<?php
/**
 * Advance ledger for a single patient.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_advance_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_patient_advance_require_access();
dcmt_patient_advance_ensure_tables($dcmt_pdo);

$patient_id = isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0;
if ($patient_id <= 0) {
    dcmt_show_message(trans('patient', 'invalid_id'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

try {
    $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_patient_name, dcmt_phone FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1");
    $stmt->execute([$patient_id]);
    $patient = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    $patient = false;
}

if (!$patient) {
    dcmt_show_message(trans('patient', 'not_found'), 'danger');
    dcmt_redirect('index.php');
    exit();
}

$remaining = dcmt_patient_advance_remaining($dcmt_pdo, $patient_id);
$advances = dcmt_patient_advance_list_for_patient($dcmt_pdo, $patient_id);
$applications = dcmt_patient_advance_applications_for_patient($dcmt_pdo, $patient_id);

require_once __DIR__ . '/../../includes/header.php';

$status_keys = [
    'active' => 'status_active',
    'depleted' => 'status_depleted',
    'refunded' => 'status_refunded',
];
?>

<div class="card dcmt-view-card mb-4">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-header-title">
            <i class="fas fa-hand-holding-usd dcmt-view-card-title-icon"></i>
            <div>
                <h6 class="dcmt-view-card-title mb-0"><?php echo trans('patient_advance', 'advance_details'); ?></h6>
                <small class="text-muted"><?php echo htmlspecialchars($patient['dcmt_patient_name'] ?? ''); ?></small>
            </div>
        </div>
        <div class="dcmt-view-header-links">
            <a href="add.php?patient_id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-plus me-1"></i><?php echo trans('patient_advance', 'add_advance'); ?>
            </a>
            <a href="../patients/view.php?id=<?php echo $patient_id; ?>" class="dcmt-add-form-view-all-link me-3">
                <i class="fas fa-user me-1"></i><?php echo trans('patient', 'patient_profile'); ?>
            </a>
            <a href="index.php" class="dcmt-add-form-view-all-link">
                <i class="fas fa-arrow-left me-1"></i><?php echo trans('patient_advance', 'view_all_advances'); ?>
            </a>
        </div>
    </div>
    <div class="card-body">
        <div class="row g-3 mb-4">
            <div class="col-md-4">
                <div class="dcmt-summary-card dcmt-summary-stat-card">
                    <div class="dcmt-summary-card-title"><?php echo trans('patient_advance', 'remaining_balance'); ?></div>
                    <div class="dcmt-summary-stat-value"><?php echo dcmt_format_currency($remaining); ?></div>
                </div>
            </div>
        </div>

        <h5 class="mb-3"><?php echo trans('patient_advance', 'patient_advances'); ?></h5>
        <?php if (empty($advances)): ?>
            <p class="text-muted"><?php echo trans('patient_advance', 'no_advances_for_patient'); ?></p>
        <?php else: ?>
            <div class="table-responsive mb-4">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo trans('patient_advance', 'received_on'); ?></th>
                            <th><?php echo trans('patient_advance', 'reason'); ?></th>
                            <th><?php echo trans('patient_advance', 'payment_method'); ?></th>
                            <th class="text-end"><?php echo trans('patient_advance', 'original_amount'); ?></th>
                            <th class="text-end"><?php echo trans('patient_advance', 'remaining_balance'); ?></th>
                            <th><?php echo trans('patient_advance', 'status'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($advances as $advance): ?>
                            <?php
                            $status = (string) ($advance['dcmt_status'] ?? 'active');
                            $status_label = trans('patient_advance', $status_keys[$status] ?? 'status_active');
                            $method_name = (string) ($advance['payment_method_name'] ?? '');
                            $translated_method = $method_name !== '' ? trans('income_payment_method', $method_name) : '';
                            $method_display = ($translated_method !== '' && $translated_method !== $method_name) ? $translated_method : ($method_name !== '' ? $method_name : '-');
                            ?>
                            <tr>
                                <td><?php echo dcmt_format_date($advance['dcmt_received_on'] ?? ''); ?></td>
                                <td>
                                    <?php echo htmlspecialchars($advance['dcmt_reason'] ?? ''); ?>
                                    <?php if (!empty($advance['dcmt_notes'])): ?>
                                        <div class="text-muted small"><?php echo nl2br(htmlspecialchars($advance['dcmt_notes'])); ?></div>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($method_display); ?></td>
                                <td class="text-end"><?php echo dcmt_format_currency($advance['dcmt_amount'] ?? 0); ?></td>
                                <td class="text-end"><?php echo dcmt_format_currency($advance['dcmt_remaining_amount'] ?? 0); ?></td>
                                <td><?php echo htmlspecialchars($status_label); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>

        <h5 class="mb-3"><?php echo trans('patient_advance', 'applications'); ?></h5>
        <?php if (empty($applications)): ?>
            <p class="text-muted mb-0"><?php echo trans('patient_advance', 'no_applications'); ?></p>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo trans('patient_advance', 'applied_on'); ?></th>
                            <th><?php echo trans('patient_advance', 'applied_to_income'); ?></th>
                            <th><?php echo trans('patient_advance', 'reason'); ?></th>
                            <th class="text-end"><?php echo trans('common', 'amount'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($applications as $app): ?>
                            <tr>
                                <td><?php echo dcmt_format_date($app['dcmt_applied_on'] ?? ''); ?></td>
                                <td>
                                    <?php if (!empty($app['dcmt_income_id'])): ?>
                                        <a href="../income/view.php?id=<?php echo (int) $app['dcmt_income_id']; ?>">
                                            #<?php echo (int) $app['dcmt_income_id']; ?>
                                            <?php if (!empty($app['dcmt_transaction_date'])): ?>
                                                (<?php echo dcmt_format_date($app['dcmt_transaction_date']); ?>)
                                            <?php endif; ?>
                                        </a>
                                    <?php else: ?>
                                        -
                                    <?php endif; ?>
                                </td>
                                <td><?php echo htmlspecialchars($app['advance_reason'] ?? ''); ?></td>
                                <td class="text-end"><?php echo dcmt_format_currency($app['dcmt_amount'] ?? 0); ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
