<?php
/**
 * Lab Connections - Index
 */

require_once __DIR__ . '/../../config/config.php';
require_once __DIR__ . '/../../config/database.php';
require_once __DIR__ . '/../../auth/check_auth.php';
require_once __DIR__ . '/../../includes/lab_functions.php';
require_once __DIR__ . '/../../includes/dcmt_owner_doctor.php';

if (!dcmt_validate_session()) {
    dcmt_show_message(trans('login', 'session_expired'), 'warning');
    dcmt_redirect(DCMT_APP_URL . '/auth/login.php');
    exit();
}

$user = dcmt_get_current_user();
if (!dcmt_can_access_lab($user)) {
    dcmt_show_message('Access denied.', 'error');
    dcmt_redirect(DCMT_APP_URL . '/pages/dashboard/');
    exit();
}
$dcmt_can_delete = dcmt_can_delete_lab($user);

$load_error = '';
$connections = [];

try {
    dcmt_ensure_lab_tables($dcmt_pdo);
} catch (PDOException $e) {
    error_log('Lab tables ensure error: ' . $e->getMessage());
    $detail = '';
    if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
        $detail = ': ' . $e->getMessage();
    }
    $load_error = trans('lab', 'load_error') . ' (schema)' . $detail;
}

$csrf_token = dcmt_generate_csrf_token();
$search = isset($_GET['search']) ? dcmt_sanitize_input($_GET['search']) : '';

$where_conditions = [];
$params = [];
if ($search !== '') {
    $where_conditions[] = "(c.dcmt_lab_organization LIKE ? OR c.dcmt_name LIKE ? OR c.dcmt_lab_remote_name LIKE ? OR c.dcmt_clinic_url LIKE ? OR c.dcmt_lab_base_url LIKE ?)";
    $like = '%' . $search . '%';
    $params = [$like, $like, $like, $like, $like];
}
$where_clause = $where_conditions ? ('WHERE ' . implode(' AND ', $where_conditions)) : '';

if ($load_error === '') {
    try {
        // Subquery avoids ONLY_FULL_GROUP_BY issues with SELECT c.*
        $sql = "
            SELECT c.*,
                   (
                       SELECT COUNT(*)
                       FROM dcmt_lab_work_orders w
                       WHERE w.dcmt_lab_connection_id = c.dcmt_id
                   ) AS work_order_count
            FROM dcmt_lab_connections c
            $where_clause
            ORDER BY COALESCE(NULLIF(TRIM(c.dcmt_lab_organization), ''), c.dcmt_name)
        ";
        $stmt = $dcmt_pdo->prepare($sql);
        $stmt->execute($params);
        $connections = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!is_array($connections)) {
            $connections = [];
        }
    } catch (PDOException $e) {
        error_log('Lab connections fetch error: ' . $e->getMessage());
        $connections = [];
        $detail = '';
        if (!defined('DCMT_IS_PRODUCTION') || !DCMT_IS_PRODUCTION) {
            $detail = ': ' . $e->getMessage();
        }
        $load_error = trans('lab', 'load_error') . $detail;
    }
}

require_once __DIR__ . '/../../includes/header.php';
?>

<meta name="csrf-token" content="<?php echo $csrf_token; ?>">

<?php if ($load_error !== ''): ?>
    <div class="alert alert-danger alert-dismissible fade show" role="alert">
        <?php echo htmlspecialchars($load_error); ?>
        <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
    </div>
<?php endif; ?>

<div class="card mb-4 dcmt-filter-form">
    <div class="card-body">
        <form method="GET" action="" class="row g-3 align-items-end">
            <div class="col-md-4">
                <label for="search" class="form-label"><?php echo trans('common', 'search'); ?></label>
                <input type="text" class="form-control dcmt-filter-field" id="search" name="search"
                       value="<?php echo htmlspecialchars($search); ?>"
                       placeholder="<?php echo trans('lab', 'search_placeholder'); ?>">
            </div>
            <div class="col-md-auto d-flex flex-column gap-2 align-items-stretch">
                <button type="submit" class="dcmt-filter-btn">
                    <i class="fas fa-search me-1"></i><?php echo trans('common', 'search'); ?>
                </button>
                <a href="?" class="dcmt-add-form-view-all-link text-center">
                    <i class="fas fa-times me-1"></i><?php echo trans('common', 'clear'); ?>
                </a>
            </div>
        </form>
    </div>
</div>

<div class="card dcmt-records-table">
    <div class="card-header dcmt-view-card-header">
        <h6 class="dcmt-view-card-title">
            <i class="fas fa-flask dcmt-view-card-title-icon"></i><?php echo trans('lab', 'lab_connections'); ?>
        </h6>
        <a href="add.php" class="dcmt-add-form-view-all-link"><?php echo trans('lab', 'add_connection'); ?></a>
    </div>
    <div class="card-body">
        <?php if (empty($connections)): ?>
            <div class="text-center py-4">
                <i class="fas fa-flask fa-3x text-muted mb-3"></i>
                <h5 class="text-muted"><?php echo trans('lab', 'no_connections_found'); ?></h5>
                <p class="text-muted"><?php echo trans('lab', 'try_adjusting_search'); ?></p>
            </div>
        <?php else: ?>
            <div class="table-responsive">
                <table class="table table-striped table-hover">
                    <thead>
                        <tr>
                            <th><?php echo trans('lab', 'lab_remote_name'); ?></th>
                            <th><?php echo trans('lab', 'lab_base_url'); ?></th>
                            <th><?php echo trans('lab', 'work_orders_count'); ?></th>
                            <th><?php echo trans('lab', 'pending_payment'); ?></th>
                            <th><?php echo trans('lab', 'connection_status'); ?></th>
                            <th><?php echo trans('lab', 'last_synced_at'); ?></th>
                            <th><?php echo trans('common', 'actions'); ?></th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($connections as $connection): ?>
                            <?php $status_safe = ($connection['dcmt_status'] ?? '') === 'active' ? 'active' : 'inactive'; ?>
                            <tr>
                                <td><?php echo htmlspecialchars(dcmt_lab_connection_display_name($connection)); ?></td>
                                <td><small><?php echo htmlspecialchars($connection['dcmt_lab_base_url']); ?></small></td>
                                <td><?php echo (int) $connection['work_order_count']; ?></td>
                                <td class="dcmt-lab-pending-cell"
                                    data-pending-connection-id="<?php echo (int) $connection['dcmt_id']; ?>"
                                    data-pending-active="<?php echo $status_safe === 'active' ? '1' : '0'; ?>">
                                    <?php if ($status_safe === 'active'): ?>
                                        <span class="text-muted"><i class="fas fa-spinner fa-spin" aria-hidden="true"></i></span>
                                    <?php else: ?>
                                        <span class="text-muted">—</span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <span class="text-<?php echo $status_safe === 'active' ? 'success' : 'secondary'; ?>">
                                        <?php echo trans('common', $status_safe); ?>
                                    </span>
                                </td>
                                <td>
                                    <?php if (!empty($connection['dcmt_last_synced_at'])): ?>
                                        <?php echo dcmt_format_date($connection['dcmt_last_synced_at'], DCMT_DATETIME_FORMAT); ?>
                                    <?php else: ?>
                                        <span class="text-muted"><?php echo trans('lab', 'never_synced'); ?></span>
                                    <?php endif; ?>
                                </td>
                                <td>
                                    <div class="btn-group btn-group-sm btn-group-action" role="group">
                                        <button type="button" class="btn"
                                                title="<?php echo trans('lab', 'view_payments'); ?>"
                                                data-payment-view
                                                data-connection-id="<?php echo (int) $connection['dcmt_id']; ?>"
                                                data-lab-name="<?php echo htmlspecialchars(dcmt_lab_connection_display_name($connection), ENT_QUOTES, 'UTF-8'); ?>">
                                            <img src="../../assets/images/view-filled.svg" alt="View">
                                        </button>
                                        <a href="edit.php?id=<?php echo (int) $connection['dcmt_id']; ?>"
                                           class="btn" title="<?php echo trans('common', 'edit'); ?>">
                                            <img src="../../assets/images/edit.svg" alt="Edit">
                                        </a>
                                        <?php if ($dcmt_can_delete): ?>
                                            <?php if ((int) $connection['work_order_count'] > 0): ?>
                                                <button type="button" class="btn dcmt-btn-borderless" disabled
                                                        title="<?php echo trans('lab', 'cannot_delete_with_orders'); ?>">
                                                    <i class="fas fa-lock text-muted"></i>
                                                </button>
                                            <?php else: ?>
                                                <button type="button" class="btn" title="<?php echo trans('common', 'delete'); ?>"
                                                        onclick="confirmDelete(<?php echo (int) $connection['dcmt_id']; ?>, 'lab_connection')">
                                                    <img src="../../assets/images/delete.svg" alt="Delete">
                                                </button>
                                            <?php endif; ?>
                                        <?php endif; ?>
                                    </div>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php endif; ?>
    </div>
</div>

<!-- Work orders & payments modal -->
<div class="modal fade" id="labPaymentsModal" tabindex="-1" aria-hidden="true">
    <div class="modal-dialog modal-lg modal-dialog-scrollable">
        <div class="modal-content">
            <div class="modal-header">
                <h5 class="modal-title" id="labPaymentsModalTitle"><?php echo trans('lab', 'payment_summary'); ?></h5>
                <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="Close"></button>
            </div>
            <div class="modal-body">
                <div class="alert alert-danger py-2 px-3 small d-none" id="labPaymentsError" role="alert"></div>
                <div class="row g-2 mb-3" id="labPaymentsSummary"></div>
                <div class="table-responsive">
                    <table class="table table-striped table-hover align-middle mb-0">
                        <thead>
                            <tr>
                                <th><?php echo trans('lab', 'folio_number'); ?></th>
                                <th><?php echo trans('lab', 'patient_name'); ?></th>
                                <th><?php echo trans('lab', 'total_quote'); ?></th>
                                <th><?php echo trans('lab', 'collected_amount'); ?></th>
                                <th><?php echo trans('lab', 'pending_amount'); ?></th>
                            </tr>
                        </thead>
                        <tbody id="labPaymentsTableBody"></tbody>
                    </table>
                </div>
            </div>
        </div>
    </div>
</div>

<script>
(function () {
    'use strict';

    const labels = {
        viewPayments: <?php echo json_encode(trans('lab', 'view_payments')); ?>,
        paymentSummary: <?php echo json_encode(trans('lab', 'payment_summary')); ?>,
        totalQuote: <?php echo json_encode(trans('lab', 'total_quote')); ?>,
        collected: <?php echo json_encode(trans('lab', 'collected_amount')); ?>,
        pending: <?php echo json_encode(trans('lab', 'pending_amount')); ?>,
        totalOrders: <?php echo json_encode(trans('lab', 'total_orders')); ?>,
        noData: <?php echo json_encode(trans('lab', 'no_payment_work_orders')); ?>,
        loadFailed: <?php echo json_encode(trans('lab', 'payment_load_failed')); ?>
    };

    function esc(value) {
        return String(value == null ? '' : value).replace(/[&<>"']/g, function (m) {
            return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;' }[m]);
        });
    }

    function formatMoney(amount, currency) {
        const num = Number(amount) || 0;
        let formatted;
        try {
            formatted = num.toLocaleString(undefined, { minimumFractionDigits: 2, maximumFractionDigits: 2 });
        } catch (e) {
            formatted = num.toFixed(2);
        }
        return currency ? (formatted + ' ' + currency) : formatted;
    }

    // Load pending payment amount for each active connection.
    document.querySelectorAll('[data-pending-connection-id]').forEach(function (cell) {
        const active = cell.getAttribute('data-pending-active') === '1';
        if (!active) {
            cell.innerHTML = '<span class="text-muted">—</span>';
            return;
        }
        const id = cell.getAttribute('data-pending-connection-id');
        fetch('payments_ajax.php?action=pending&id=' + encodeURIComponent(id), {
            credentials: 'same-origin',
            headers: { 'X-Requested-With': 'XMLHttpRequest' }
        }).then(function (r) { return r.json(); })
          .then(function (data) {
              if (!data || !data.success) {
                  cell.innerHTML = '<span class="text-muted" title="' + esc(labels.loadFailed) + '">—</span>';
                  return;
              }
              cell.innerHTML = '<strong>' + esc(formatMoney(data.total_pending_amount, data.currency)) + '</strong>';
          })
          .catch(function () {
              cell.innerHTML = '<span class="text-muted" title="' + esc(labels.loadFailed) + '">—</span>';
          });
    });

    // View payments modal.
    const modalEl = document.getElementById('labPaymentsModal');
    const titleEl = document.getElementById('labPaymentsModalTitle');
    const errorEl = document.getElementById('labPaymentsError');
    const summaryEl = document.getElementById('labPaymentsSummary');
    const tbodyEl = document.getElementById('labPaymentsTableBody');

    function showError(message) {
        if (!errorEl) return;
        errorEl.textContent = message || '';
        errorEl.classList.toggle('d-none', !message);
    }

    function renderSummary(data) {
        if (!summaryEl) return;
        const currency = data.currency || '';
        const summary = data.summary || {};
        const items = [
            { label: labels.totalOrders, value: String(summary.total_orders != null ? summary.total_orders : '—') },
            { label: labels.totalQuote, value: formatMoney(summary.total_quote, currency) },
            { label: labels.collected, value: formatMoney(summary.total_collected, currency) },
            { label: labels.pending, value: formatMoney(summary.total_pending, currency) }
        ];
        summaryEl.innerHTML = items.map(function (item) {
            return '<div class="col-md-3 col-6">'
                + '<div class="border rounded p-2 h-100">'
                + '<small class="text-muted d-block text-nowrap">' + esc(item.label) + '</small>'
                + '<strong>' + esc(item.value) + '</strong>'
                + '</div></div>';
        }).join('');
    }

    function renderWorkOrders(data) {
        if (!tbodyEl) return;
        const currency = data.currency || '';
        const orders = Array.isArray(data.work_orders) ? data.work_orders : [];
        if (orders.length === 0) {
            tbodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3">' + esc(labels.noData) + '</td></tr>';
            return;
        }
        tbodyEl.innerHTML = orders.map(function (o) {
            return '<tr>'
                + '<td><strong>' + esc(o.folio_number || '—') + '</strong></td>'
                + '<td>' + esc(o.patient || '—') + '</td>'
                + '<td>' + esc(formatMoney(o.total_quote, currency)) + '</td>'
                + '<td>' + esc(formatMoney(o.collected_amount, currency)) + '</td>'
                + '<td>' + esc(formatMoney(o.pending_amount, currency)) + '</td>'
                + '</tr>';
        }).join('');
    }

    document.querySelectorAll('[data-payment-view]').forEach(function (btn) {
        btn.addEventListener('click', function () {
            const id = btn.getAttribute('data-connection-id');
            const labName = btn.getAttribute('data-lab-name') || '';
            if (titleEl) {
                titleEl.textContent = labels.viewPayments + (labName ? ' — ' + labName : '');
            }
            showError('');
            if (summaryEl) summaryEl.innerHTML = '';
            if (tbodyEl) {
                tbodyEl.innerHTML = '<tr><td colspan="5" class="text-center text-muted py-3"><i class="fas fa-spinner fa-spin me-1"></i></td></tr>';
            }

            if (typeof bootstrap !== 'undefined' && modalEl) {
                bootstrap.Modal.getOrCreateInstance(modalEl).show();
            }

            fetch('payments_ajax.php?action=work_orders&id=' + encodeURIComponent(id), {
                credentials: 'same-origin',
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            }).then(function (r) { return r.json(); })
              .then(function (data) {
                  if (!data || !data.success) {
                      showError((data && data.message) ? data.message : labels.loadFailed);
                      if (tbodyEl) tbodyEl.innerHTML = '';
                      return;
                  }
                  renderSummary(data);
                  renderWorkOrders(data);
              })
              .catch(function () {
                  showError(labels.loadFailed);
                  if (tbodyEl) tbodyEl.innerHTML = '';
              });
        });
    });
})();
</script>

<script>
window.translations = {
    confirm_deletion: '<?php echo addslashes(trans('common', 'confirm_deletion')); ?>',
    warning: '<?php echo addslashes(trans('common', 'warning')); ?>',
    delete_confirmation_message: '<?php echo addslashes(trans('lab', 'confirm_delete')); ?>',
    cancel: '<?php echo addslashes(trans('common', 'cancel')); ?>',
    yes_delete: '<?php echo addslashes(trans('common', 'yes_delete')); ?>',
    lab_connection: '<?php echo addslashes(trans('lab', 'lab_connections')); ?>'
};
</script>

<?php require_once __DIR__ . '/../../includes/footer.php'; ?>
