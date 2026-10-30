<?php
/**
 * Patients who currently have unused advance balances.
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/patient_advance_functions.php';
require_once __DIR__ . '/../../includes/whatsapp_template_functions.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

dcmt_patient_advance_require_access();
dcmt_patient_advance_ensure_tables($dcmt_pdo);

$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';
$page = max(1, (int) ($_GET['page'] ?? 1));
$per_page = DCMT_PER_PAGE;
$offset = ($page - 1) * $per_page;

$where = ["a.dcmt_status <> 'refunded'"];
$params = [];
if ($search !== '') {
    $where[] = '(p.dcmt_patient_name LIKE ? OR p.dcmt_phone LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$where_sql = 'WHERE ' . implode(' AND ', $where);

$patients = [];
$total_records = 0;
$total_pages = 0;

try {
    $count_sql = "
        SELECT COUNT(*) FROM (
            SELECT p.dcmt_id
            FROM dcmt_patient_advances a
            INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
            {$where_sql}
            GROUP BY p.dcmt_id
            HAVING SUM(a.dcmt_remaining_amount) > 0.009
        ) grouped_advances
    ";
    $count_stmt = $dcmt_pdo->prepare($count_sql);
    $count_stmt->execute($params);
    $total_records = (int) $count_stmt->fetchColumn();
    $total_pages = (int) ceil($total_records / $per_page);

    $list_sql = "
        SELECT
            p.dcmt_id,
            p.dcmt_patient_name,
            p.dcmt_phone,
            SUM(a.dcmt_amount) AS advance_amount,
            SUM(a.dcmt_remaining_amount) AS remaining_amount,
            MAX(a.dcmt_received_on) AS last_received_on
        FROM dcmt_patient_advances a
        INNER JOIN dcmt_patients p ON p.dcmt_id = a.dcmt_patient_id
        {$where_sql}
        GROUP BY p.dcmt_id, p.dcmt_patient_name, p.dcmt_phone
        HAVING SUM(a.dcmt_remaining_amount) > 0.009
        ORDER BY remaining_amount DESC, p.dcmt_patient_name ASC
        LIMIT {$per_page} OFFSET {$offset}
    ";
    $list_stmt = $dcmt_pdo->prepare($list_sql);
    $list_stmt->execute($params);
    $patients = $list_stmt->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (PDOException $e) {
    error_log('Patient advances list error: ' . $e->getMessage());
    $patients = [];
}

$dcmt_whatsapp_templates = dcmt_fetch_active_whatsapp_templates($dcmt_pdo);
$dcmt_whatsapp_templates_js = array_map(static function ($row) {
    return [
        'id' => (int) ($row['dcmt_id'] ?? 0),
        'name' => (string) ($row['dcmt_name'] ?? ''),
        'message' => (string) ($row['dcmt_message'] ?? ''),
    ];
}, $dcmt_whatsapp_templates);

require_once __DIR__ . '/../../includes/header.php';
?>

<div class="alert alert-info">
    <i class="fas fa-info-circle me-1"></i><?php echo htmlspecialchars(trans('patient_advance', 'not_revenue_notice')); ?>
</div>

<div class="card mb-3">
    <div class="card-body">
        <form method="GET" class="row g-3 align-items-end">
            <div class="col-md-6">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo htmlspecialchars(trans('patient_advance', 'search_placeholder')); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="index.php" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <div class="dcmt-view-card-header-content">
            <div>
                <h6 class="dcmt-view-card-title mb-0">
                    <?php echo trans('patient_advance', 'patients_with_advance'); ?>
                    <span class="ms-3 dcmt-view-card-title-total">
                        (<?php echo trans('patient_advance', 'showing'); ?>:
                        <span style="color: #007bff; font-weight: 600;"><?php echo number_format($total_records); ?></span>
                        <?php echo trans('patient_advance', 'records'); ?>)
                    </span>
                </h6>
            </div>
            <div class="ms-3 d-flex gap-2">
                <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('patient_advance', 'add_advance'); ?></a>
                <a href="export.php<?php echo $search !== '' ? '?search=' . urlencode($search) : ''; ?>" class="dcmt-add-form-view-all-link">
                    <i class="fas fa-download me-1"></i><?php echo trans('common', 'export'); ?>
                </a>
            </div>
        </div>
    </div>
    <div class="card-body">
        <?php if (empty($patients)): ?>
            <div class="text-center py-4">
                <i class="fas fa-hand-holding-usd fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('patient_advance', 'no_advances'); ?></h5>
                <p class="text-muted"><?php echo trans('patient_advance', 'start_adding_advance'); ?></p>
                <a href="add.php" class="btn btn-primary"><?php echo trans('patient_advance', 'add_advance'); ?></a>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table">
                    <thead>
                        <tr>
                            <th><?php echo trans('patient_advance', 'patient'); ?></th>
                            <th><?php echo trans('patient_advance', 'phone'); ?></th>
                            <th class="text-end"><?php echo trans('patient_advance', 'advance_amount'); ?></th>
                            <th class="text-end"><?php echo trans('patient_advance', 'remaining_balance'); ?></th>
                            <th><?php echo trans('patient_advance', 'last_received'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($patients as $row): ?>
                            <tr>
                                <td><?php echo htmlspecialchars($row['dcmt_patient_name'] ?? ''); ?></td>
                                <td><?php echo htmlspecialchars($row['dcmt_phone'] ?? '-') ?: '-'; ?></td>
                                <td class="text-end"><?php echo dcmt_format_currency($row['advance_amount'] ?? 0); ?></td>
                                <td class="text-end"><?php echo dcmt_format_currency($row['remaining_amount'] ?? 0); ?></td>
                                <td><?php echo !empty($row['last_received_on']) ? dcmt_format_date($row['last_received_on']) : '-'; ?></td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <a href="patient.php?patient_id=<?php echo (int) $row['dcmt_id']; ?>" class="btn"
                                            title="<?php echo trans('patient_advance', 'view_patient_advances'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="<?php echo htmlspecialchars(trans('common', 'view')); ?>">
                                        </a>
                                        <a href="add.php?patient_id=<?php echo (int) $row['dcmt_id']; ?>" class="btn"
                                            title="<?php echo trans('patient_advance', 'add_advance'); ?>">
                                            <i class="fas fa-plus text-primary"></i>
                                        </a>
                                        <?php
                                        $row_phone = (string) ($row['dcmt_phone'] ?? '');
                                        $row_phone_digits = preg_replace('/\D+/', '', $row_phone);
                                        ?>
                                        <button type="button" class="btn dcmt-whatsapp-template-btn"
                                            title="<?php echo htmlspecialchars(trans('whatsapp_template', 'send_whatsapp')); ?>"
                                            data-patient-name="<?php echo htmlspecialchars($row['dcmt_patient_name'] ?? '', ENT_QUOTES, 'UTF-8'); ?>"
                                            data-patient-phone="<?php echo htmlspecialchars($row_phone, ENT_QUOTES, 'UTF-8'); ?>"
                                            <?php echo $row_phone_digits === '' ? 'disabled' : ''; ?>>
                                            <i class="fab fa-whatsapp text-success"></i>
                                        </button>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <nav aria-label="<?php echo trans('patient_advance', 'patient_advances'); ?> pagination" class="mt-3">
                    <ul class="pagination justify-content-center">
                        <?php if ($page > 1): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page - 1])); ?>">
                                    <?php echo trans('common', 'previous'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                        <?php for ($i = max(1, $page - 2); $i <= min($total_pages, $page + 2); $i++): ?>
                            <li class="page-item <?php echo $i === $page ? 'active' : ''; ?>">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $i])); ?>"><?php echo $i; ?></a>
                            </li>
                        <?php endfor; ?>
                        <?php if ($page < $total_pages): ?>
                            <li class="page-item">
                                <a class="page-link" href="?<?php echo http_build_query(array_merge($_GET, ['page' => $page + 1])); ?>">
                                    <?php echo trans('common', 'next'); ?>
                                </a>
                            </li>
                        <?php endif; ?>
                    </ul>
                </nav>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</div>

<div class="modal fade" id="dcmtWhatsappTemplateModal" tabindex="-1" aria-labelledby="dcmtWhatsappTemplateModalLabel" aria-hidden="true">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="dcmtWhatsappTemplateModalLabel">
                    <i class="fab fa-whatsapp text-success me-2"></i><?php echo trans('whatsapp_template', 'send_whatsapp'); ?>
                </h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo trans('common', 'cancel'); ?>"></button>
            </div>
            <div class="modal-body">
                <p class="mb-1"><strong id="dcmtWhatsappPatientName"></strong></p>
                <p class="text-muted small mb-3" id="dcmtWhatsappPatientPhone"></p>
                <?php if (empty($dcmt_whatsapp_templates)): ?>
                    <div class="alert alert-warning mb-0">
                        <?php echo trans('whatsapp_template', 'no_templates_available'); ?>
                        <a href="../whatsapp_templates/index.php" class="alert-link"><?php echo trans('whatsapp_template', 'manage_templates'); ?></a>
                    </div>
                <?php else: ?>
                    <div class="mb-3">
                        <label class="form-label" for="dcmtWhatsappTemplateSelect"><?php echo trans('whatsapp_template', 'select_template'); ?></label>
                        <select class="form-select" id="dcmtWhatsappTemplateSelect" aria-describedby="dcmtWhatsappPreviewHelp">
                            <?php foreach ($dcmt_whatsapp_templates as $tpl): ?>
                                <option value="<?php echo (int) $tpl['dcmt_id']; ?>">
                                    <?php echo htmlspecialchars($tpl['dcmt_name']); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    <div class="mb-0">
                        <label class="form-label" for="dcmtWhatsappPreview"><?php echo trans('whatsapp_template', 'message_preview'); ?></label>
                        <textarea class="form-control" id="dcmtWhatsappPreview" rows="6" maxlength="4000"
                                  placeholder="<?php echo htmlspecialchars(trans('whatsapp_template', 'message_edit_placeholder')); ?>"></textarea>
                        <div id="dcmtWhatsappPreviewHelp" class="form-text"><?php echo trans('whatsapp_template', 'message_edit_help'); ?></div>
                    </div>
                <?php endif; ?>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal"><?php echo trans('common', 'cancel'); ?></button>
                <?php if (!empty($dcmt_whatsapp_templates)): ?>
                    <button type="button" class="btn btn-success" id="dcmtWhatsappSendBtn" onclick="dcmtSendWhatsappTemplate()">
                        <i class="fab fa-whatsapp me-1"></i><?php echo trans('whatsapp_template', 'send_whatsapp'); ?>
                    </button>
                <?php endif; ?>
            </div>
        </div>
    </div>
</div>

<script>
    const dcmtWhatsappTemplates = <?php echo json_encode($dcmt_whatsapp_templates_js, JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    const dcmtWhatsappSiteName = <?php echo json_encode(dcmt_get_site_name(), JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP); ?>;
    let dcmtWhatsappPatientContext = { name: '', phone: '' };

    function dcmtShowAdvanceAlert(type, message) {
        const alertDiv = document.createElement('div');
        alertDiv.className = `alert alert-${type} alert-dismissible fade show`;
        alertDiv.innerHTML = `
        ${message}
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    `;
        const mainContent = document.querySelector('.main-content');
        if (mainContent) {
            mainContent.insertBefore(alertDiv, mainContent.firstChild);
            setTimeout(() => {
                if (alertDiv.parentNode) {
                    alertDiv.remove();
                }
            }, 5000);
        }
    }

    function dcmtApplyWhatsappTemplate(message, patientName, patientPhone) {
        return String(message || '')
            .replace(/\{patient_name\}/g, patientName || '')
            .replace(/\{site_name\}/g, dcmtWhatsappSiteName || '')
            .replace(/\{phone\}/g, patientPhone || '');
    }

    function dcmtGetSelectedWhatsappTemplate() {
        const select = document.getElementById('dcmtWhatsappTemplateSelect');
        if (!select) return null;
        const id = String(select.value || '');
        return dcmtWhatsappTemplates.find((tpl) => String(tpl.id) === id) || null;
    }

    function dcmtUpdateWhatsappPreview() {
        const preview = document.getElementById('dcmtWhatsappPreview');
        const tpl = dcmtGetSelectedWhatsappTemplate();
        if (!preview || !tpl) return;
        preview.value = dcmtApplyWhatsappTemplate(
            tpl.message,
            dcmtWhatsappPatientContext.name,
            dcmtWhatsappPatientContext.phone
        );
    }

    function dcmtOpenWhatsappTemplateModal(patientName, phone) {
        const digits = String(phone || '').replace(/\D/g, '');
        if (!digits) {
            dcmtShowAdvanceAlert('warning', <?php echo json_encode(trans('whatsapp_template', 'no_phone')); ?>);
            return;
        }
        if (!dcmtWhatsappTemplates.length) {
            dcmtShowAdvanceAlert('warning', <?php echo json_encode(trans('whatsapp_template', 'no_templates_available')); ?>);
            return;
        }

        dcmtWhatsappPatientContext = { name: patientName || '', phone: phone || '' };
        const nameEl = document.getElementById('dcmtWhatsappPatientName');
        const phoneEl = document.getElementById('dcmtWhatsappPatientPhone');
        if (nameEl) nameEl.textContent = patientName || '';
        if (phoneEl) phoneEl.textContent = phone || '';

        const select = document.getElementById('dcmtWhatsappTemplateSelect');
        if (select && select.options.length > 0) {
            select.selectedIndex = 0;
        }
        dcmtUpdateWhatsappPreview();
        const modalEl = document.getElementById('dcmtWhatsappTemplateModal');
        if (!modalEl) return;
        if (typeof bootstrap === 'undefined' || !bootstrap.Modal) {
            dcmtShowAdvanceAlert('danger', '<?php echo addslashes(trans('whatsapp_template', 'database_error')); ?>');
            return;
        }
        bootstrap.Modal.getOrCreateInstance(modalEl).show();
    }

    function dcmtSendWhatsappTemplate() {
        const tpl = dcmtGetSelectedWhatsappTemplate();
        const digits = String(dcmtWhatsappPatientContext.phone || '').replace(/\D/g, '');
        const preview = document.getElementById('dcmtWhatsappPreview');
        const message = preview ? String(preview.value || '').trim() : '';
        if (!tpl || !digits) return;
        if (!message) {
            dcmtShowAdvanceAlert('warning', <?php echo json_encode(trans('whatsapp_template', 'message_required')); ?>);
            if (preview) preview.focus();
            return;
        }

        const url = 'https://wa.me/' + digits + '?text=' + encodeURIComponent(message);
        window.open(url, '_blank');

        const modalEl = document.getElementById('dcmtWhatsappTemplateModal');
        const modal = modalEl ? bootstrap.Modal.getInstance(modalEl) : null;
        if (modal) modal.hide();
    }

    document.addEventListener('DOMContentLoaded', function () {
        document.querySelectorAll('.dcmt-whatsapp-template-btn').forEach(function (btn) {
            btn.addEventListener('click', function () {
                dcmtOpenWhatsappTemplateModal(
                    this.getAttribute('data-patient-name') || '',
                    this.getAttribute('data-patient-phone') || ''
                );
            });
        });

        const templateSelect = document.getElementById('dcmtWhatsappTemplateSelect');
        if (templateSelect) {
            templateSelect.addEventListener('change', dcmtUpdateWhatsappPreview);
        }
    });
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
