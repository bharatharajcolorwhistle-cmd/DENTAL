                <!-- Page content ends here -->
            </div>
        </div>
    </div>

    <!-- Bootstrap JS -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>
    
    <!-- Chart.js -->
    <script src="https://cdn.jsdelivr.net/npm/chart.js@3.9.1/dist/chart.min.js"></script>
    
    <?php
    if (!function_exists('dcmt_messaging_user_can_access') && file_exists(__DIR__ . '/messaging_functions.php')) {
        require_once __DIR__ . '/messaging_functions.php';
    }
    if (function_exists('dcmt_get_current_user') && function_exists('dcmt_messaging_user_can_access')) {
        $dcmt_footer_user = dcmt_get_current_user();
        if ($dcmt_footer_user && dcmt_messaging_user_can_access($dcmt_footer_user)) {
            include __DIR__ . '/messaging_widget.php';
        }
    }
    ?>

    <!-- Custom JavaScript -->
    <script src="<?php echo $base_path; ?>assets/js/main.js?v=<?php echo time(); ?>"></script>
    <script src="<?php echo $base_path; ?>assets/js/dcmt-appointment-sync.js?v=<?php echo time(); ?>"></script>
    <script src="<?php echo $base_path; ?>assets/js/dcmt-reminder-notifications.js?v=<?php echo time(); ?>"></script>
    <script>
        // Auto-hide transient alerts after 5 seconds (skip persistent alerts like Start Cash notice)
        document.addEventListener('DOMContentLoaded', function() {
            const alerts = document.querySelectorAll('.alert');
            alerts.forEach(function(alert) {
                if (alert.getAttribute('data-persistent') === 'true' || alert.id === 'dcmtStartCashHeaderAlert') {
                    return;
                }
                // Do not auto-close hidden placeholder alerts (JS shows messages later; closing removes them from DOM).
                if (alert.classList.contains('d-none')) {
                    return;
                }
                setTimeout(function() {
                    try {
                        const bsAlert = bootstrap.Alert.getOrCreateInstance(alert);
                        bsAlert.close();
                    } catch (e) {
                        if (alert.parentNode) {
                            alert.parentNode.removeChild(alert);
                        }
                    }
                }, 5000);
            });
        });

        // Format currency
        function formatCurrency(amount) {
            const currency = '<?php echo dcmt_get_current_currency(); ?>';
            return new Intl.NumberFormat('en-US', {
                style: 'currency',
                currency: currency
            }).format(amount);
        }

        // Format date
        function formatDate(dateString) {
            const date = new Date(dateString);
            return date.toLocaleDateString('en-US', {
                year: 'numeric',
                month: 'short',
                day: 'numeric'
            });
        }

        // Initialize tooltips
        var tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        var tooltipList = tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });

        // Initialize popovers
        var popoverTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="popover"]'));
        var popoverList = popoverTriggerList.map(function (popoverTriggerEl) {
            return new bootstrap.Popover(popoverTriggerEl);
        });
    </script>
</body>
</html>
