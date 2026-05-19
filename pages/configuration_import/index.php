<?php
/**
 * Configuration Import - onboard clinic settings from Excel template
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_require_admin();

require_once __DIR__ . '/../../includes/configuration_import.php';
require_once __DIR__ . '/../../includes/header.php';

$csrf_token = dcmt_generate_csrf_token();
$import_errors = [];
$import_success = [];
$imported_count = 0;
$skipped_count = 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['import_configuration'])) {
    if (!dcmt_verify_csrf_token($_POST['csrf_token'] ?? '')) {
        $import_errors[] = trans('common', 'invalid_token');
    } elseif (!isset($_FILES['xlsx_file']) || $_FILES['xlsx_file']['error'] !== UPLOAD_ERR_OK) {
        $import_errors[] = trans('configuration_import', 'no_file_uploaded');
    } else {
        $extension = strtolower(pathinfo($_FILES['xlsx_file']['name'], PATHINFO_EXTENSION));
        if ($extension !== 'xlsx') {
            $import_errors[] = trans('configuration_import', 'invalid_file_type');
        } else {
            set_time_limit(300);
            ini_set('memory_limit', '256M');
            $result = dcmt_process_configuration_import($_FILES['xlsx_file']['tmp_name']);
            $import_errors = $result['errors'];
            $import_success = $result['success'];
            $imported_count = $result['imported_count'];
            $skipped_count = $result['skipped_count'];
        }
    }
}

$max_records = defined('DCMT_MAX_IMPORT_RECORDS') ? DCMT_MAX_IMPORT_RECORDS : 500;
?>

<div class="container-fluid">
    <div class="row">
        <div class="col-12 mb-2">
            <div class="dcmt-page-header">
                <h4 class="dcmt-page-title">
                    <i class="fas fa-file-import me-2"></i><?php echo trans('configuration_import', 'page_title'); ?>
                </h4>
            </div>
        </div>
    </div>

    <div class="row">
        <div class="col-lg-8">
            <div class="border py-2 px-3 small mb-3">
                <div class="d-flex gap-2 mb-2">
                    <i class="fas fa-download text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                    <div><?php echo trans('configuration_import', 'instruction_step_1'); ?></div>
                </div>
                <div class="d-flex gap-2 mb-2">
                    <i class="fas fa-file-excel text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                    <div><?php echo trans('configuration_import', 'instruction_step_2'); ?></div>
                </div>
                <div class="d-flex gap-2 mb-0">
                    <i class="fas fa-upload text-secondary mt-1 flex-shrink-0" aria-hidden="true"></i>
                    <div><?php echo trans('configuration_import', 'instruction_step_3'); ?></div>
                </div>
            </div>
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-file-excel me-2"></i><?php echo trans('configuration_import', 'upload_file'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <?php if (!empty($import_errors)): ?>
                        <div class="alert alert-danger">
                            <h6><i class="fas fa-exclamation-triangle me-2"></i><?php echo trans('configuration_import', 'import_errors'); ?></h6>
                            <ul class="mb-0">
                                <?php foreach (array_slice($import_errors, 0, 50) as $error): ?>
                                    <li><?php echo htmlspecialchars($error); ?></li>
                                <?php endforeach; ?>
                            </ul>
                            <?php if (count($import_errors) > 50): ?>
                                <p class="mb-0 mt-2"><small>… and <?php echo count($import_errors) - 50; ?> more</small></p>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($import_success) || ($imported_count + $skipped_count) > 0): ?>
                        <div class="alert alert-success">
                            <h6><i class="fas fa-check-circle me-2"></i><?php echo trans('configuration_import', 'import_success'); ?></h6>
                            <p class="mb-2"><?php echo sprintf(trans('configuration_import', 'import_summary'), $imported_count, $skipped_count); ?></p>
                            <?php if (count($import_success) <= 15): ?>
                                <ul class="mb-0">
                                    <?php foreach ($import_success as $msg): ?>
                                        <li><?php echo htmlspecialchars($msg); ?></li>
                                    <?php endforeach; ?>
                                </ul>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>

                    <form method="POST" enctype="multipart/form-data" class="needs-validation" novalidate>
                        <input type="hidden" name="csrf_token" value="<?php echo htmlspecialchars($csrf_token); ?>">

                        <div class="mb-4">
                            <label for="xlsx_file" class="form-label">
                                <?php echo trans('configuration_import', 'select_file'); ?> <span class="text-danger">*</span>
                            </label>
                            <input type="file" class="form-control" id="xlsx_file" name="xlsx_file" accept=".xlsx" required>
                            <div class="form-text"><?php echo trans('configuration_import', 'file_help'); ?></div>
                        </div>

                        <div class="d-flex gap-2 flex-wrap">
                            <button type="submit" name="import_configuration" class="btn btn-primary">
                                <i class="fas fa-upload me-2"></i><?php echo trans('configuration_import', 'import_data'); ?>
                            </button>
                            <a href="download_template.php" class="btn btn-outline-primary">
                                <i class="fas fa-download me-2"></i><?php echo trans('configuration_import', 'download_template'); ?>
                            </a>
                            <a href="<?php echo DCMT_APP_URL; ?>/pages/dashboard/" class="btn btn-secondary">
                                <i class="fas fa-arrow-left me-2"></i><?php echo trans('common', 'back'); ?>
                            </a>
                        </div>
                    </form>
                </div>
            </div>
        </div>

        <div class="col-lg-4">
            <div class="card">
                <div class="card-header">
                    <h5 class="card-title mb-0">
                        <i class="fas fa-info-circle me-2"></i><?php echo trans('configuration_import', 'import_instructions'); ?>
                    </h5>
                </div>
                <div class="card-body">
                    <p class="small text-muted"><?php echo trans('configuration_import', 'import_order_note'); ?></p>

                    <h6><?php echo trans('configuration_import', 'sheets_included'); ?>:</h6>
                    <ul class="list-unstyled small">
                        <li><i class="fas fa-graduation-cap text-warning me-2"></i><?php echo trans('configuration_import', 'specializations'); ?></li>
                        <li><i class="fas fa-tooth text-info me-2"></i><?php echo trans('configuration_import', 'services'); ?></li>
                        <li><i class="fas fa-users text-success me-2"></i><?php echo trans('configuration_import', 'users'); ?></li>
                        <li><i class="fas fa-credit-card text-warning me-2"></i><?php echo trans('configuration_import', 'income_payment_methods'); ?></li>
                        <li><i class="fas fa-check-circle text-info me-2"></i><?php echo trans('configuration_import', 'income_payment_status'); ?></li>
                        <li><i class="fas fa-tags text-warning me-2"></i><?php echo trans('configuration_import', 'expense_categories'); ?></li>
                        <li><i class="fas fa-credit-card text-secondary me-2"></i><?php echo trans('configuration_import', 'expense_payment_methods'); ?></li>
                        <li><i class="fas fa-boxes text-primary me-2"></i><?php echo trans('configuration_import', 'inventory_categories'); ?></li>
                    </ul>

                    <div class="alert alert-info mt-3">
                        <small><i class="fas fa-info-circle me-1"></i><?php echo trans('configuration_import', 'template_note'); ?></small>
                    </div>

                    <div class="alert alert-warning mt-2">
                        <small>
                            <i class="fas fa-exclamation-triangle me-1"></i>
                            <?php echo sprintf(trans('configuration_import', 'max_rows_per_sheet'), $max_records); ?>
                        </small>
                    </div>

                    <div class="mt-3">
                        <a href="download_template.php" class="btn btn-outline-primary btn-sm w-100">
                            <i class="fas fa-download me-2"></i><?php echo trans('configuration_import', 'download_template'); ?>
                        </a>
                    </div>
                </div>
            </div>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
