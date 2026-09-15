<?php
/**
 * Record a patient advance (not revenue).
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

if (isset($_GET['ajax']) && $_GET['ajax'] === 'patient_search') {
    header('Content-Type: application/json; charset=utf-8');
    try {
        $term = dcmt_sanitize_input(trim((string) ($_GET['term'] ?? '')));
        $limit = isset($_GET['limit']) ? (int) $_GET['limit'] : 20;
        if ($limit < 1) {
            $limit = 20;
        }
        if ($limit > 50) {
            $limit = 50;
        }

        $whereSql = "WHERE dcmt_status = 'active'";
        $params = [];
        if ($term !== '') {
            $whereSql .= " AND (dcmt_patient_name LIKE ? OR dcmt_phone LIKE ?)";
            $likeTerm = '%' . $term . '%';
            $params[] = $likeTerm;
            $params[] = $likeTerm;
        }

        $stmt = $dcmt_pdo->prepare("
            SELECT dcmt_id, dcmt_patient_name, dcmt_phone
            FROM dcmt_patients
            {$whereSql}
            ORDER BY dcmt_patient_name ASC
            LIMIT {$limit}
        ");
        $stmt->execute($params);
        $patients = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $results = [];
        foreach ($patients as $patient) {
            $name = (string) ($patient['dcmt_patient_name'] ?? '');
            $phone = (string) ($patient['dcmt_phone'] ?? '');
            $displayText = $name;
            if ($phone !== '') {
                $displayText .= ' - ' . $phone;
            }
            $results[] = [
                'id' => (int) ($patient['dcmt_id'] ?? 0),
                'text' => $displayText,
            ];
        }
        echo json_encode(['results' => $results, 'pagination' => ['more' => false]]);
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['results' => [], 'pagination' => ['more' => false]]);
    }
    exit();
}

$errors = [];
$form_data = [
    'patient_id' => isset($_GET['patient_id']) ? (int) $_GET['patient_id'] : 0,
    'amount' => '',
    'reason' => '',
    'notes' => '',
    'received_on' => dcmt_get_current_date(),
    'payment_method_id' => 0,
];
$selected_patient_text = '';

$income_payment_methods = [];
$default_cash_method_id = null;
try {
    $stmt = $dcmt_pdo->query("SELECT dcmt_id, dcmt_name FROM dcmt_income_payment_methods WHERE dcmt_status = 'active' ORDER BY dcmt_name");
    $income_payment_methods = $stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
    foreach ($income_payment_methods as $method) {
        if (strtolower((string) $method['dcmt_name']) === 'cash') {
            $default_cash_method_id = (int) $method['dcmt_id'];
            break;
        }
    }
} catch (PDOException $e) {
    error_log('Error fetching payment methods for advance: ' . $e->getMessage());
}

if ($form_data['payment_method_id'] <= 0 && $default_cash_method_id) {
    $form_data['payment_method_id'] = $default_cash_method_id;
}

if ($form_data['patient_id'] > 0) {
    try {
        $stmt = $dcmt_pdo->prepare("SELECT dcmt_id, dcmt_patient_name, dcmt_phone FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1");
        $stmt->execute([$form_data['patient_id']]);
        $prefill = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($prefill) {
            $selected_patient_text = (string) ($prefill['dcmt_patient_name'] ?? '');
            if (!empty($prefill['dcmt_phone'])) {
                $selected_patient_text .= ' - ' . $prefill['dcmt_phone'];
            }
        } else {
            $form_data['patient_id'] = 0;
        }
    } catch (PDOException $e) {
        $form_data['patient_id'] = 0;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $errors[] = trans('patient_advance', 'invalid_token');
    } else {
        $form_data['patient_id'] = isset($_POST['patient_id']) ? (int) $_POST['patient_id'] : 0;
        $form_data['amount'] = trim((string) ($_POST['amount'] ?? ''));
        $form_data['reason'] = trim(dcmt_sanitize_input($_POST['reason'] ?? ''));
        $form_data['notes'] = trim((string) ($_POST['notes'] ?? ''));
        $form_data['received_on'] = dcmt_sanitize_input($_POST['received_on'] ?? '');
        $form_data['payment_method_id'] = isset($_POST['payment_method_id']) ? (int) $_POST['payment_method_id'] : 0;

        if ($form_data['patient_id'] > 0) {
            try {
                $stmt = $dcmt_pdo->prepare("SELECT dcmt_patient_name, dcmt_phone FROM dcmt_patients WHERE dcmt_id = ? LIMIT 1");
                $stmt->execute([$form_data['patient_id']]);
                $prefill = $stmt->fetch(PDO::FETCH_ASSOC);
                if ($prefill) {
                    $selected_patient_text = (string) ($prefill['dcmt_patient_name'] ?? '');
                    if (!empty($prefill['dcmt_phone'])) {
                        $selected_patient_text .= ' - ' . $prefill['dcmt_phone'];
                    }
                }
            } catch (PDOException $e) {
                // keep posted id
            }
        }

        $result = dcmt_patient_advance_create($dcmt_pdo, $form_data, $dcmt_current_user);
        if (!empty($result['success'])) {
            dcmt_log_activity('Patient advance recorded', 'Advance ID: ' . ($result['id'] ?? '') . ' | Patient ID: ' . $form_data['patient_id']);
            dcmt_audit('create', 'patient_advance', (int) ($result['id'] ?? 0));
            dcmt_show_message(trans('patient_advance', 'add_success'), 'success');
            dcmt_redirect('index.php');
            exit();
        }
        $errors = $result['errors'] ?? [trans('patient_advance', 'database_error')];
    }
}

$csrf_token = dcmt_generate_csrf_token();
$dcmt_currency_symbol = dcmt_get_current_currency();
require_once __DIR__ . '/../../includes/header.php';
?>

<link rel="stylesheet" href="<?php echo dcmt_asset('assets/css/add-income.css', '../../'); ?>">
<link href="<?php echo dcmt_asset('assets/css/select2.min.css', '../../'); ?>" rel="stylesheet">

<?php if (!empty($errors)): ?>
    <div class="alert alert-danger">
        <ul class="mb-0">
            <?php foreach ($errors as $error): ?>
                <li><?php echo htmlspecialchars($error); ?></li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars(trans('patient_advance', 'not_revenue_notice')); ?>
</div>

<div class="dcmt-add-form-container">
    <div class="dcmt-add-form-header">
        <div class="dcmt-add-form-header-content">
            <h1 class="dcmt-add-form-page-title"><?php echo trans('patient_advance', 'record_advance'); ?></h1>
            <a href="index.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient_advance', 'view_all_advances'); ?></a>
        </div>
    </div>
    <form method="POST" action="" id="dcmtPatientAdvanceForm">
        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="patient_id" class="form-label"><?php echo trans('patient_advance', 'patient'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="patient_id" name="patient_id" required>
                        <option value=""><?php echo trans('patient_advance', 'select_patient'); ?></option>
                        <?php if ($form_data['patient_id'] > 0 && $selected_patient_text !== ''): ?>
                            <option value="<?php echo (int) $form_data['patient_id']; ?>" selected>
                                <?php echo htmlspecialchars($selected_patient_text); ?>
                            </option>
                        <?php endif; ?>
                    </select>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="received_on" class="form-label"><?php echo trans('patient_advance', 'received_on'); ?> <span class="text-danger">*</span></label>
                    <input type="date" class="form-control" id="received_on" name="received_on"
                           value="<?php echo htmlspecialchars($form_data['received_on']); ?>" required>
                </div>
            </div>
        </div>

        <div class="row">
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="amount" class="form-label"><?php echo trans('patient_advance', 'amount'); ?> <span class="text-danger">*</span></label>
                    <div class="dcmt-amount-input-wrapper">
                        <span class="dcmt-currency-symbol"><?php echo htmlspecialchars($dcmt_currency_symbol); ?></span>
                        <input type="number" step="0.01" min="0.01" class="form-control dcmt-amount-input" id="amount" name="amount"
                               value="<?php echo htmlspecialchars((string) $form_data['amount']); ?>" required>
                    </div>
                </div>
            </div>
            <div class="col-md-6">
                <div class="mb-3">
                    <label for="payment_method_id" class="form-label"><?php echo trans('patient_advance', 'payment_method'); ?> <span class="text-danger">*</span></label>
                    <select class="form-select" id="payment_method_id" name="payment_method_id" required>
                        <option value=""><?php echo trans('patient_advance', 'select_payment_method'); ?></option>
                        <?php foreach ($income_payment_methods as $method): ?>
                            <?php
                            $methodName = (string) ($method['dcmt_name'] ?? '');
                            $translated = trans('income_payment_method', $methodName);
                            $display = ($translated !== $methodName) ? $translated : $methodName;
                            ?>
                            <option value="<?php echo (int) $method['dcmt_id']; ?>" <?php echo (int) $form_data['payment_method_id'] === (int) $method['dcmt_id'] ? 'selected' : ''; ?>>
                                <?php echo htmlspecialchars($display); ?>
                            </option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <div class="mb-3">
            <label for="reason" class="form-label"><?php echo trans('patient_advance', 'reason'); ?> <span class="text-danger">*</span></label>
            <input type="text" class="form-control" id="reason" name="reason" maxlength="255"
                   value="<?php echo htmlspecialchars($form_data['reason']); ?>"
                   placeholder="<?php echo htmlspecialchars(trans('patient_advance', 'reason_placeholder')); ?>" required>
            <div class="form-text"><?php echo htmlspecialchars(trans('patient_advance', 'reason_help')); ?></div>
        </div>

        <div class="mb-3">
            <label for="notes" class="form-label"><?php echo trans('patient_advance', 'notes'); ?></label>
            <textarea class="form-control" id="notes" name="notes" rows="3"
                      placeholder="<?php echo htmlspecialchars(trans('patient_advance', 'notes_placeholder')); ?>"><?php echo htmlspecialchars($form_data['notes']); ?></textarea>
        </div>

        <div class="d-flex gap-2">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-save me-1"></i><?php echo trans('patient_advance', 'save_advance'); ?>
            </button>
            <a href="index.php" class="btn btn-outline-secondary"><?php echo trans('common', 'cancel'); ?></a>
        </div>
    </form>
</div>

<script src="<?php echo dcmt_asset('assets/js/select2.min.js', '../../'); ?>"></script>
<script>
$(function () {
    $('#patient_id').select2({
        placeholder: <?php echo json_encode(trans('patient_advance', 'select_patient')); ?>,
        allowClear: true,
        width: '100%',
        minimumInputLength: 0,
        ajax: {
            url: 'add.php?ajax=patient_search',
            dataType: 'json',
            delay: 250,
            data: function (params) {
                return { term: params.term || '', limit: 20 };
            },
            processResults: function (data) {
                return data;
            },
            cache: true
        }
    });
});
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
