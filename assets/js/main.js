/* Main JavaScript File - Dental Clinic Management System */

// Common utility functions
function confirmDelete(itemId, itemType = 'item') {


    console.log('Function source:', confirmDelete.toString().substring(0, 100));
    // Create and show custom confirmation modal
    showDeleteConfirmation(itemId, itemType);
}

// Show custom delete confirmation modal
function showDeleteConfirmation(itemId, itemType) {

    // Remove existing modal if any
    const existingModal = document.getElementById('deleteConfirmationModal');
    if (existingModal) {
        existingModal.remove();
    }
    
    // Get translated text from global variables or use defaults
    const confirmDeletion = window.translations?.confirm_deletion || 'Confirm Deletion';
    const warning = window.translations?.warning || 'Warning!';
    const deleteMessage = window.translations?.delete_confirmation_message || 'Are you sure you want to delete this {item_type}? This action cannot be undone and will permanently remove all data associated with this {item_type}.';
    const cancel = window.translations?.cancel || 'Cancel';
    const yesDelete = window.translations?.yes_delete || 'Yes, Delete';
    
    // Translate item type if translation exists
    let translatedItemType = itemType;
    if (window.translations && window.translations[itemType]) {
        translatedItemType = window.translations[itemType];
    }
    
    // Replace {item_type} placeholder in the message
    const translatedMessage = deleteMessage.replace(/{item_type}/g, translatedItemType);
    
    // Create modal HTML
    const modalHTML = `
        <div class="modal fade" id="deleteConfirmationModal" tabindex="-1" aria-labelledby="deleteConfirmationModalLabel" aria-hidden="true">
            <div class="modal-dialog modal-dialog-centered">
                <div class="modal-content border-danger">
                    <div class="modal-header bg-danger text-white">
                        <h5 class="modal-title" id="deleteConfirmationModalLabel">
                            <i class="fas fa-exclamation-triangle"></i> ${confirmDeletion}
                        </h5>
                        <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal" aria-label="Close"></button>
                    </div>
                    <div class="modal-body">
                        <div class="alert alert-warning mb-0">
                            <h6 class="alert-heading">
                                <i class="fas fa-exclamation-triangle"></i> ${warning}
                            </h6>
                            <p class="mb-0">
                                ${translatedMessage}
                            </p>
                        </div>
                    </div>
                    <div class="modal-footer">
                        <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">
                            <i class="fas fa-times"></i> ${cancel}
                        </button>
                        <button type="button" class="btn btn-danger" onclick="proceedWithDelete(${itemId}, '${itemType}')">
                            <i class="fas fa-trash"></i> ${yesDelete}
                        </button>
                    </div>
                </div>
            </div>
        </div>
    `;
    
    // Add modal to body
    document.body.insertAdjacentHTML('beforeend', modalHTML);
    
    // Show modal
    const modal = new bootstrap.Modal(document.getElementById('deleteConfirmationModal'));
    modal.show();
    
    // Remove modal from DOM when hidden
    document.getElementById('deleteConfirmationModal').addEventListener('hidden.bs.modal', function() {
        this.remove();
    });
}

// Proceed with deletion after confirmation
function proceedWithDelete(itemId, itemType) {

    // Hide modal
    const modal = bootstrap.Modal.getInstance(document.getElementById('deleteConfirmationModal'));
    if (modal) {
        modal.hide();
    }
    
    // Get current page path to determine the correct delete URL
    const currentPath = window.location.pathname;

    console.log('All path parts:', currentPath.split('/'));
    
    // Extract the module name from the path
    const pathParts = currentPath.split('/');
    const moduleIndex = pathParts.findIndex(part => part === 'pages');

    const moduleName = moduleIndex !== -1 && moduleIndex + 1 < pathParts.length ? pathParts[moduleIndex + 1] : '';

    // Handle expenses with AJAX deletion
    if (itemType === 'expense_record' || (moduleName === 'expenses' && itemType === 'expense')) {

        deleteExpenseAjax(itemId);
        return;
    }
    
    // Handle income records with AJAX deletion
    if (itemType === 'income_record' || (moduleName === 'income' && itemType === 'income')) {

        deleteIncomeAjax(itemId);
        return;
    }
    
    // Handle inventory items with AJAX deletion
    if (itemType === 'inventory_item' || (moduleName === 'inventory' && itemType === 'inventory')) {

        deleteInventoryAjax(itemId);
        return;
    }
    
    // Handle users with AJAX deletion
    if (itemType === 'user' || (moduleName === 'users' && itemType === 'user')) {

        deleteUserAjax(itemId);
        return;
    }
    
    // Handle doctors with AJAX deletion
    if (itemType === 'doctor' || (moduleName === 'doctors' && itemType === 'doctor')) {

        deleteDoctorAjax(itemId);
        return;
    }
    
    // Handle specializations with AJAX deletion
    if (itemType === 'specialization' || (moduleName === 'specializations' && itemType === 'specialization')) {

        deleteSpecializationAjax(itemId);
        return;
    }
    
    // For other modules, redirect to delete page
    let deleteUrl;
    if (moduleName) {
        // Get base path dynamically from current location
        const currentPath = window.location.pathname;
        const pagesIndex = currentPath.indexOf('/pages/');
        const basePath = pagesIndex !== -1 ? currentPath.substring(0, pagesIndex) : '';
        
        // Use absolute path for other modules
        deleteUrl = basePath + '/pages/' + moduleName + '/delete.php?id=' + itemId;
    } else {
        // Fallback to relative path
        deleteUrl = 'delete.php?id=' + itemId;
    }

    // Redirect to delete page
    window.location.href = deleteUrl;
}

// Delete expense using AJAX
function deleteExpenseAjax(expenseId) {

    // Show loading state
    showLoadingMessage('Deleting expense...');
    
    // Get CSRF token from meta tag or form
    let csrfToken = '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        csrfToken = csrfMeta.getAttribute('content');
    } else {
        // Try to find CSRF token from any form on the page
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
            csrfToken = csrfInput.value;
        }
    }
    
    // If no CSRF token found, generate one via AJAX call
    if (!csrfToken) {

    }
    
    // Make AJAX request to delete endpoint
    fetch('delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: expenseId,
            csrf_token: csrfToken
        })
    })
    .then(response => {

        return response.json();
    })
    .then(data => {

        hideLoadingMessage();
        
        if (data.success) {
            // Simply reload the page to show the session-based success message
            // This ensures consistent styling with other success messages
            window.location.reload();
        } else {
            showErrorMessage(data.message || 'Failed to delete expense');
        }
    })
    .catch(error => {

        hideLoadingMessage();
        showErrorMessage('An error occurred while deleting the expense. Please try again.');
    });
}

// Delete income record using AJAX
function deleteIncomeAjax(incomeId) {

    // Show loading state
    showLoadingMessage('Deleting income record...');
    
    // Get CSRF token from meta tag or form
    let csrfToken = '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        csrfToken = csrfMeta.getAttribute('content');
    } else {
        // Try to find CSRF token from any form on the page
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
            csrfToken = csrfInput.value;
        }
    }
    
    // If no CSRF token found, generate one via AJAX call
    if (!csrfToken) {

    }
    
    // Make AJAX request to delete endpoint
    fetch('delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: incomeId,
            csrf_token: csrfToken
        })
    })
    .then(response => {

        return response.json();
    })
    .then(data => {

        hideLoadingMessage();
        
        if (data.success) {
            // Simply reload the page to show the session-based success message
            // This ensures consistent styling with other success messages
            window.location.reload();
        } else {
            showErrorMessage(data.message || 'Failed to delete income record');
        }
    })
    .catch(error => {

        hideLoadingMessage();
        showErrorMessage('An error occurred while deleting the income record. Please try again.');
    });
}

// Delete inventory item using AJAX
function deleteInventoryAjax(inventoryId) {

    // Show loading state
    showLoadingMessage('Deleting inventory item...');
    
    // Get CSRF token from window object or try other sources
    let csrfToken = window.csrfToken || '';
    
    if (!csrfToken) {
        // Try to find CSRF token from meta tag or form
        const csrfMeta = document.querySelector('meta[name="csrf-token"]');
        if (csrfMeta) {
            csrfToken = csrfMeta.getAttribute('content');
        } else {
            // Try to find CSRF token from any form on the page
            const csrfInput = document.querySelector('input[name="csrf_token"]');
            if (csrfInput) {
                csrfToken = csrfInput.value;
            }
        }
    }
    
    // If no CSRF token found, log warning
    if (!csrfToken) {

    }
    
    // Prepare form data
    const formData = new FormData();
    formData.append('id', inventoryId);
    formData.append('csrf_token', csrfToken);
    
    // Make AJAX request to delete endpoint
    fetch('delete_ajax.php', {
        method: 'POST',
        body: formData
    })
    .then(response => {

        return response.json();
    })
    .then(data => {

        hideLoadingMessage();
        
        if (data.success) {
            // Show success message in the same style as "Inventory item added successfully!"
            showHeaderAlert('success', data.message);
            
            // Remove the row from the table
            const row = document.querySelector(`button[onclick*="confirmDelete(${inventoryId}"]`).closest('tr');
            if (row) {
                row.remove();
            }
            
            // Check if table is empty
            const tbody = document.querySelector('tbody');
            if (tbody && tbody.children.length === 0) {
                // Reload page to show "no inventory found" message
                setTimeout(() => {
                    window.location.reload();
                }, 1000);
            }
        } else {
            showHeaderAlert('danger', data.message || 'Failed to delete inventory item');
        }
    })
    .catch(error => {

        hideLoadingMessage();
        showHeaderAlert('danger', 'An error occurred while deleting the inventory item. Please try again.');
    });
}

// Delete user using AJAX
function deleteUserAjax(userId) {

    // Show loading state
    showLoadingMessage('Deleting user...');
    
    // Get CSRF token from meta tag or form
    let csrfToken = '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        csrfToken = csrfMeta.getAttribute('content');
    } else {
        // Try to find CSRF token from any form on the page
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
            csrfToken = csrfInput.value;
        }
    }
    
    // If no CSRF token found, log warning
    if (!csrfToken) {

    }
    
    // Make AJAX request to delete endpoint
    fetch('delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: userId,
            csrf_token: csrfToken
        })
    })
    .then(response => {

        return response.json();
    })
    .then(data => {

        hideLoadingMessage();
        
        if (data.success) {
            // Simply reload the page to show the session-based success message
            // This ensures consistent styling with other success messages
            window.location.reload();
        } else {
            showErrorMessage(data.message || 'Failed to delete user');
        }
    })
    .catch(error => {

        hideLoadingMessage();
        showErrorMessage('An error occurred while deleting the user. Please try again.');
    });
}

// Delete doctor using AJAX
function deleteDoctorAjax(doctorId) {

    // Show loading state
    showLoadingMessage('Deleting doctor...');
    
    // Get CSRF token from meta tag or form
    let csrfToken = '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        csrfToken = csrfMeta.getAttribute('content');
    } else {
        // Try to find CSRF token from any form on the page
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
            csrfToken = csrfInput.value;
        }
    }
    
    // If no CSRF token found, log warning
    if (!csrfToken) {

    }
    
    // Make AJAX request to delete endpoint
    fetch('delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: doctorId,
            csrf_token: csrfToken
        })
    })
    .then(response => {

        return response.json();
    })
    .then(data => {

        hideLoadingMessage();
        
        if (data.success) {
            // Simply reload the page to show the session-based success message
            // This ensures consistent styling with other success messages
            window.location.reload();
        } else {
            showErrorMessage(data.message || 'Failed to delete doctor');
        }
    })
    .catch(error => {

        hideLoadingMessage();
        showErrorMessage('An error occurred while deleting the doctor. Please try again.');
    });
}

// Delete specialization using AJAX
function deleteSpecializationAjax(specializationId) {

    // Show loading state
    showLoadingMessage('Deleting specialization...');
    
    // Get CSRF token from meta tag or form
    let csrfToken = '';
    const csrfMeta = document.querySelector('meta[name="csrf-token"]');
    if (csrfMeta) {
        csrfToken = csrfMeta.getAttribute('content');
    } else {
        // Try to find CSRF token from any form on the page
        const csrfInput = document.querySelector('input[name="csrf_token"]');
        if (csrfInput) {
            csrfToken = csrfInput.value;
        }
    }
    
    // If no CSRF token found, log warning
    if (!csrfToken) {

    }
    
    // Make AJAX request to delete endpoint
    fetch('delete_ajax.php', {
        method: 'POST',
        headers: {
            'Content-Type': 'application/json',
            'X-Requested-With': 'XMLHttpRequest'
        },
        body: JSON.stringify({
            id: specializationId,
            csrf_token: csrfToken
        })
    })
    .then(response => {

        return response.json();
    })
    .then(data => {

        hideLoadingMessage();
        
        if (data.success) {
            // Simply reload the page to show the session-based success message
            // This ensures consistent styling with other success messages
            window.location.reload();
        } else {
            showErrorMessage(data.message || 'Failed to delete specialization');
        }
    })
    .catch(error => {

        hideLoadingMessage();
        showErrorMessage('An error occurred while deleting the specialization. Please try again.');
    });
}

// Show loading message
function showLoadingMessage(message) {
    // Remove existing loading message
    const existingLoading = document.getElementById('loadingMessage');
    if (existingLoading) {
        existingLoading.remove();
    }
    
    const loadingHTML = `
        <div id="loadingMessage" class="alert alert-info d-flex align-items-center" role="alert" style="position: fixed; top: 80px; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 300px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <div class="spinner-border spinner-border-sm me-2" role="status">
                <span class="visually-hidden">Loading...</span>
            </div>
            <strong>${message}</strong>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', loadingHTML);
}

// Hide loading message
function hideLoadingMessage() {
    const loadingMessage = document.getElementById('loadingMessage');
    if (loadingMessage) {
        loadingMessage.remove();
    }
}

// Show success message
function showSuccessMessage(message) {
    showMessage(message, 'success');
}

// Show error message
function showErrorMessage(message) {
    showMessage(message, 'danger');
}

// Show header alert in the same style as session-based messages (like "Inventory item added successfully!")
function showHeaderAlert(type, message) {
    // Remove existing header alerts
    const existingAlerts = document.querySelectorAll('.main-content .alert[data-js-alert="true"]');
    existingAlerts.forEach(alert => alert.remove());
    
    // Create alert HTML that matches the header.php session message style
    const alertHTML = `
        <div class="alert alert-${type} alert-dismissible fade show" role="alert" data-js-alert="true">
            ${message}
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    
    // Insert at the beginning of main-content (same location as session messages)
    const mainContent = document.querySelector('.main-content');
    if (mainContent) {
        // Find the first non-alert element to insert before
        const firstNonAlert = Array.from(mainContent.children).find(child => 
            !child.classList.contains('alert')
        );
        
        if (firstNonAlert) {
            firstNonAlert.insertAdjacentHTML('beforebegin', alertHTML);
        } else {
            mainContent.insertAdjacentHTML('afterbegin', alertHTML);
        }
    }
}

// Show message with specified type
function showMessage(message, type) {
    // Remove existing messages
    const existingMessages = document.querySelectorAll('.alert[data-auto-dismiss="true"]');
    existingMessages.forEach(msg => msg.remove());
    
    const messageHTML = `
        <div class="alert alert-${type} alert-dismissible" role="alert" data-auto-dismiss="true" style="position: fixed; top: 80px; left: 50%; transform: translateX(-50%); z-index: 9999; min-width: 400px; max-width: 600px; box-shadow: 0 4px 12px rgba(0,0,0,0.15);">
            <div class="d-flex align-items-center">
                <i class="fas fa-${type === 'success' ? 'check-circle' : type === 'danger' ? 'exclamation-triangle' : 'info-circle'} me-2"></i>
                <strong>${message}</strong>
            </div>
            <button type="button" class="btn-close" data-bs-dismiss="alert" aria-label="Close"></button>
        </div>
    `;
    
    document.body.insertAdjacentHTML('beforeend', messageHTML);
    
    // Auto-dismiss after 4 seconds
    setTimeout(() => {
        const messageElement = document.querySelector('.alert[data-auto-dismiss="true"]');
        if (messageElement) {
            const bsAlert = new bootstrap.Alert(messageElement);
            bsAlert.close();
        }
    }, 4000);
}

// Export to CSV function
function exportToCSV() {
    // Get current search parameters
    const urlParams = new URLSearchParams(window.location.search);
    const searchParams = urlParams.toString();
    
    // Create export URL
    const exportUrl = 'export.php?' + searchParams;
    
    // Download the file
    window.location.href = exportUrl;
}

// Toggle form fields based on selection (for income forms)
function toggleTypeFields() {
    const typeSelect = document.getElementById('type');
    const productSaleDoctorField = document.getElementById('productSaleDoctorField');
    const paymentAmountsSection = document.getElementById('paymentAmountsSection');
    const consultationAmountsRow = document.getElementById('consultationAmountsRow');
    const productPaidField = document.getElementById('productPaidField');
    const totalPaidField = document.getElementById('totalPaidField');
    const totalPendingField = document.getElementById('totalPendingField');
    const amountInput = document.getElementById('amount');
    
    // Check if all required elements exist
    if (!typeSelect || !amountInput) {
        return;
    }
    
    const type = typeSelect.value;

    if (type === 'consultation') {
        // Hide doctor field for product sales
        if (productSaleDoctorField) {
            productSaleDoctorField.style.display = 'none';
        }
        
        // Show payment amounts section for consultation
        if (paymentAmountsSection) {
            paymentAmountsSection.style.display = 'block';
        }
        
        // Show consultation amounts row
        if (consultationAmountsRow) {
            consultationAmountsRow.style.display = 'block';
        }
        if (totalPaidField) {
            totalPaidField.style.display = 'block';
        }
        if (totalPendingField) {
            totalPendingField.style.display = 'block';
        }
        
        // Check if there are any product items added to show product paid amount
        checkAndShowProductPaidAmount();
        // Show product items if they exist, even for consultation type
        const productItems = document.getElementById('productItems');
        if (productItems) {
            const hasProductItems = productItems.querySelectorAll('.product-item').length > 0;
            // Show product items and container if they exist
            productItems.style.display = hasProductItems ? 'block' : 'none';
        }
        // Update button text for consultation
        updateAddProductButton('consultation');
        amountInput.readOnly = true;
        // Clear amount when switching to consultation type
        amountInput.value = '';

        // Update consultation total
        updateConsultationFee();
        // Product items are optional for consultation
        removeRequiredFromProductFields();
        // Update help text for consultation
        updateProductFieldsHelp('consultation');
        // Recalculate total amount
        recalculateTotalAmount();
    } else if (type === 'product_sale') {
        // Show doctor field for product sales
        if (productSaleDoctorField) {
            productSaleDoctorField.style.display = 'block';
        }
        
        // Show payment amounts section for product sale
        if (paymentAmountsSection) {
            paymentAmountsSection.style.display = 'block';
        }
        
        // Hide consultation amounts row for product sale
        if (consultationAmountsRow) {
            consultationAmountsRow.style.display = 'none';
        }
        
        // Hide service amounts row for product sale
        const serviceAmountsRow = document.getElementById('serviceAmountsRow');
        if (serviceAmountsRow) {
            serviceAmountsRow.style.display = 'none';
        }
        
        // Hide product amounts row initially for product sale (will show when for sale products are selected)
        const productAmountsRow = document.getElementById('productAmountsRow');
        if (productAmountsRow) {
            productAmountsRow.style.display = 'none';
        }
        if (productPaidField) {
            productPaidField.style.display = 'block';
        }
        if (totalPaidField) {
            totalPaidField.style.display = 'block';
        }
        if (totalPendingField) {
            totalPendingField.style.display = 'block';
        }
        // Show product items for product sale
        const productItems = document.getElementById('productItems');
        if (productItems) {
            productItems.style.display = 'block';
        }
        // Update button text for product sale
        updateAddProductButton('product_sale');
        amountInput.readOnly = true;
        // Add required attributes back to product sale fields
        addRequiredToProductFields();
        // Update help text for product sale
        updateProductFieldsHelp('product_sale');
        // Recalculate total amount
        recalculateTotalAmount();
        // Check and show product amounts row based on product types
        if (typeof checkAndShowProductPaidAmount === 'function') {
            checkAndShowProductPaidAmount();
        }
    } else {
        // Hide doctor field for product sales
        if (productSaleDoctorField) {
            productSaleDoctorField.style.display = 'none';
        }
        
        // Hide all payment amount fields when no type is selected
        if (paymentAmountsSection) {
            paymentAmountsSection.style.display = 'none';

        }
        if (consultationAmountsRow) {
            consultationAmountsRow.style.display = 'none';
        }
        if (productPaidField) {
            productPaidField.style.display = 'none';
        }
        if (totalPaidField) {
            totalPaidField.style.display = 'none';
        }
        if (totalPendingField) {
            totalPendingField.style.display = 'none';
        }
        
        amountInput.readOnly = false;
        amountInput.value = '';
        // Remove required attributes from product sale fields
        removeRequiredFromProductFields();
        // Hide help text
        updateProductFieldsHelp('none');
    }
}

// Function to check if there are product items and show product amounts row accordingly
function checkAndShowProductPaidAmount() {
    const productAmountsRow = document.getElementById('productAmountsRow');
    const typeSelect = document.getElementById('type');
    
    // Check for both consultation and product_sale types
    if (typeSelect.value !== 'consultation' && typeSelect.value !== 'product_sale') {
        return;
    }
    
    // Check if there are any valid product items and their types
    let hasProductItems = false;
    let hasForUseOnly = true;
    
    document.querySelectorAll('.product-inventory').forEach(select => {
        if (select.value && select.value !== '') {
            hasProductItems = true;
            
            // Get the selected option
            let selectedOption;
            if (typeof $ !== 'undefined' && $(select).hasClass('select2-hidden-accessible')) {
                selectedOption = $(select).find('option:selected');
            } else {
                const selectedIndex = select.selectedIndex;
                if (selectedIndex > 0) {
                    selectedOption = select.options[selectedIndex];
                }
            }
            
            // Check the product type
            if (selectedOption && (selectedOption.length || selectedOption)) {
                const productType = selectedOption.attr ? selectedOption.attr('data-product-type') : selectedOption.getAttribute('data-product-type');
                if (productType !== 'for_use') {
                    hasForUseOnly = false;
                }
            }
        }
    });
    
    // Show or hide product amounts row based on whether there are product items AND if they are not all "for use"
    if (productAmountsRow) {
        // Only show if there are products AND not all are "for use"
        productAmountsRow.style.display = (hasProductItems && !hasForUseOnly) ? 'block' : 'none';
    }
}

// Function to update payment calculations and status
let isUpdatingPaymentCalculations = false; // Flag to prevent infinite recursion
// Make it accessible globally for form submission
window.isUpdatingPaymentCalculations = false;
function updatePaymentCalculations() {
    // Prevent recursive calls
    if (isUpdatingPaymentCalculations || window.isUpdatingPaymentCalculations) {
        return;
    }
    isUpdatingPaymentCalculations = true;
    window.isUpdatingPaymentCalculations = true;
    
    try {
    const serviceAmountField = document.getElementById('service_amount');
    const servicePaidField = document.getElementById('service_paid_amount');
    const servicePendingField = document.getElementById('service_pending_amount');
    const productAmountField = document.getElementById('product_amount');
    const productPaidField = document.getElementById('product_paid_amount');
    const productPendingField = document.getElementById('product_pending_amount');
    const totalAmountField = document.getElementById('amount');
    
    const serviceAmount = serviceAmountField ? parseFloat(serviceAmountField.value) || 0 : 0;
    const servicePaidAmount = servicePaidField ? parseFloat(servicePaidField.value) || 0 : 0;
    const productAmount = productAmountField ? parseFloat(productAmountField.value) || 0 : 0;
    const productPaidAmount = productPaidField ? parseFloat(productPaidField.value) || 0 : 0;
    const totalAmount = totalAmountField ? parseFloat(totalAmountField.value) || 0 : 0;
    
    // Calculate service pending amount
    const servicePendingAmount = serviceAmount - servicePaidAmount;
    
    // Calculate product pending amount
    const productPendingAmount = productAmount - productPaidAmount;
    
    // Calculate totals
    const totalPaidAmount = servicePaidAmount + productPaidAmount;
    const totalPendingAmount = totalAmount - totalPaidAmount;
    
    // Update service pending amount field
    if (servicePendingField) {
        servicePendingField.value = Math.max(0, servicePendingAmount).toFixed(2);
    }
    
    // Update product pending amount field
    if (productPendingField) {
        productPendingField.value = Math.max(0, productPendingAmount).toFixed(2);
    }
    
    // Update total fields
    const totalPaidField = document.getElementById('total_paid_amount');
    const totalPendingField = document.getElementById('total_pending_amount');
    
    if (totalPaidField) {
        totalPaidField.value = totalPaidAmount.toFixed(2);
    }
    if (totalPendingField) {
            const previousValue = totalPendingField.value;
        totalPendingField.value = totalPendingAmount.toFixed(2);
        
        // Clear "No Pending Amount" warning if pending amount becomes greater than 0
        if (totalPendingAmount > 0) {
            const paymentStatusWarningDiv = document.getElementById('payment_status_warning');
            const paymentStatusSelect = document.getElementById('payment_status_id');
            if (paymentStatusWarningDiv) {
                paymentStatusWarningDiv.style.display = 'none';
                paymentStatusWarningDiv.classList.remove('d-block');
            }
            if (paymentStatusSelect) {
                paymentStatusSelect.classList.remove('is-invalid');
            }
        }
        
            // Only dispatch event if value actually changed to prevent unnecessary triggers
            if (previousValue !== totalPendingField.value) {
                // Use setTimeout to break the call stack and prevent immediate recursion
                setTimeout(function() {
        totalPendingField.dispatchEvent(new Event('input', { bubbles: true }));
                }, 0);
            }
        }
    } finally {
        // Always reset the flag, even if an error occurs
        isUpdatingPaymentCalculations = false;
        window.isUpdatingPaymentCalculations = false;
    }
}

// Function to update payment status based on consultation and product payments
// function updatePaymentStatusFromAmounts() {
//     const consultationPaidField = document.getElementById('consultation_paid_amount');
//     const productPaidField = document.getElementById('product_paid_amount');
//     const consultationFeeField = document.getElementById('consultation_fee');
//     const paymentStatusSelect = document.getElementById('payment_status_id');
    
//     if (!paymentStatusSelect) return;
    
//     const consultationPaidAmount = consultationPaidField ? parseFloat(consultationPaidField.value) || 0 : 0;
//     const productPaidAmount = productPaidField ? parseFloat(productPaidField.value) || 0 : 0;
//     const consultationFee = consultationFeeField ? parseFloat(consultationFeeField.value) || 0 : 0;
//     const productTotal = calculateProductTotal();
    
//     // Find pending and completed status options
//     let pendingOption = null;
//     let completedOption = null;
    
//     for (let option of paymentStatusSelect.options) {
//         const text = option.text.toLowerCase();
//         if (text.includes('pending')) {
//             pendingOption = option;
//         } else if (text.includes('completed') || text.includes('paid')) {
//             completedOption = option;
//         }
//     }
    
//     // Check if consultation is fully paid
//     const consultationFullyPaid = consultationPaidAmount >= consultationFee && consultationFee > 0;
    
//     // Check if products are fully paid (if any)
//     const productsFullyPaid = productTotal === 0 || productPaidAmount >= productTotal;
    
//     // Auto-select status based on payment
//     if (consultationFullyPaid && productsFullyPaid) {
//         // Both consultation and products fully paid
//         if (completedOption) {
//             completedOption.selected = true;
//             console.log('Payment status set to completed - consultation and products fully paid');
//         }
//     } else if (consultationPaidAmount > 0 || productPaidAmount > 0) {
//         // Partially paid
//         if (pendingOption) {
//             pendingOption.selected = true;
//             console.log('Payment status set to pending - partial payment received');
//         }
//     } else if (consultationPaidAmount === 0 && productPaidAmount === 0) {
//         // No payments made - leave status as is (user can manually select)
//         console.log('No payments made - payment status unchanged');
//     }
// }

// Function to calculate product total
function calculateProductTotal() {
    let total = 0;
    document.querySelectorAll('.product-total').forEach(input => {
        total += parseFloat(input.value) || 0;
    });
    return total;
}

// Remove required attributes from product fields
function removeRequiredFromProductFields() {
    const productItems = document.querySelectorAll('.product-item');
    if (productItems.length > 0) {
        productItems.forEach(item => {
            if (item) {
                const fields = item.querySelectorAll('input[type="text"], input[type="number"], select');
                fields.forEach(field => {
                    if (field) {
                        field.removeAttribute('required');
                    }
                });
            }
        });
    }
    
    // Update help text for consultation
    const productItemsHelp = document.getElementById('productItemsHelp');
    const productItemsOptional = document.getElementById('productItemsOptional');
    
    if (productItemsHelp) productItemsHelp.style.display = 'none';
    if (productItemsOptional) productItemsOptional.style.display = 'block';
}

// Add required attributes to product fields
function addRequiredToProductFields() {
    const productItems = document.querySelectorAll('.product-item');
    if (productItems.length > 0) {
        productItems.forEach(item => {
            if (item) {
                const fields = item.querySelectorAll('input[type="text"], input[type="number"], select');
                fields.forEach(field => {
                    if (field && (field.name.includes('inventory_id') || field.name.includes('quantity') || field.name.includes('unit_price'))) {
                        field.setAttribute('required', 'required');
                    }
                });
            }
        });
    }
    
    // Update help text for product sale
    const productItemsHelp = document.getElementById('productItemsHelp');
    const productItemsOptional = document.getElementById('productItemsOptional');
    
    if (productItemsHelp) productItemsHelp.style.display = 'block';
    if (productItemsOptional) productItemsOptional.style.display = 'none';
}

// Update consultation fee when doctor is selected
function updateConsultationFee() {
    // Consultation fee has been removed - this function is kept for compatibility
    // Just update the consultation total
    const typeSelect = document.getElementById('type');
    
    if (typeSelect && typeSelect.value === 'consultation') {
        calculateTotalAmountForConsultation();
    }
}

// Update consultation total when fee is manually changed
function updateConsultationTotal() {
    const typeSelect = document.getElementById('type');
    if (typeSelect && typeSelect.value === 'consultation') {
        calculateTotalAmountForConsultation();
    }
}

// Update payment status based on payment mode selection
function updatePaymentStatus() {
    const paymentModeSelect = document.getElementById('payment_mode');
    const paymentStatusSelect = document.getElementById('payment_status');
    
    if (!paymentModeSelect || !paymentStatusSelect) {
        return;
    }
    
    const paymentMode = paymentModeSelect.value;
    
    // Auto-select payment status based on payment mode
    if (paymentMode === 'cash') {
        paymentStatusSelect.value = 'completed';
    } else if (paymentMode === 'online') {
        paymentStatusSelect.value = 'pending';
    } else {
        paymentStatusSelect.value = '';
    }
}

// Update service amount when service is selected
function updateServiceAmount() {
    const serviceSelect = document.getElementById('service_id');
    const serviceAmountInput = document.getElementById('service_amount');
    const serviceAmountsRow = document.getElementById('serviceAmountsRow');
    
    if (!serviceSelect || !serviceAmountInput || !serviceAmountsRow) {
        return;
    }
    
    const selectedOption = serviceSelect.options[serviceSelect.selectedIndex];
    
    if (selectedOption && selectedOption.value) {
        const servicePrice = selectedOption.getAttribute('data-price');
        if (servicePrice) {
            // Only show service amounts row if income type is consultation
            const typeSelect = document.getElementById('type');
            if (typeSelect && typeSelect.value === 'consultation') {
                serviceAmountsRow.style.display = 'block';
            }
            
            // Set service amount
            serviceAmountInput.value = parseFloat(servicePrice).toFixed(2);
            
            // Update calculations
            updateConsultationTotal();
            updatePaymentCalculations();
        }
    } else {
        // Hide service amounts row when no service selected (only for consultation type)
        const typeSelect = document.getElementById('type');
        if (typeSelect && typeSelect.value === 'consultation') {
            serviceAmountsRow.style.display = 'none';
        }
        serviceAmountInput.value = '0.00';
        
        // Update calculations
        updateConsultationTotal();
        updatePaymentCalculations();
    }
}

// Calculate total amount for consultation (service amount + product items)
function calculateTotalAmountForConsultation() {
    const amountInput = document.getElementById('amount');
    const serviceAmountInput = document.getElementById('service_amount');
    
    if (!amountInput) {

        return;
    }
    
    let total = 0;
    
    // Add service amount if present
    if (serviceAmountInput) {
        const serviceAmount = parseFloat(serviceAmountInput.value) || 0;
        total += serviceAmount;

    }
    
    // Add product items total
    const productTotals = document.querySelectorAll('.product-total');
    let productTotal = 0;

    productTotals.forEach((input, index) => {
        const value = parseFloat(input.value) || 0;
        productTotal += value;

    });
    total += productTotal;

    amountInput.value = total.toFixed(2);
    
    // Update Product Amount field with the product total
    const productAmountInput = document.getElementById('product_amount');
    if (productAmountInput) {
        productAmountInput.value = productTotal.toFixed(2);
        // Trigger payment calculations to update pending amount
        updatePaymentCalculations();
    }
    if (typeof refreshPaymentSummaries === 'function') {
        refreshPaymentSummaries();
    }
}

// Update product fields help text based on income type
function updateProductFieldsHelp(type) {
    const helpText = document.getElementById('productItemsHelp');
    const optionalText = document.getElementById('productItemsOptional');
    
    if (!helpText || !optionalText) {
        return;
    }
    
    if (type === 'consultation') {
        helpText.style.display = 'none';
        optionalText.style.display = 'block';
    } else if (type === 'product_sale') {
        helpText.style.display = 'block';
        optionalText.style.display = 'none';
    } else {
        helpText.style.display = 'none';
        optionalText.style.display = 'none';
    }
}

function renderAddProductButton(button, text) {
    if (!button) {
        return;
    }
    button.innerHTML = '';
    const icon = document.createElement('i');
    icon.className = 'fas fa-plus';
    button.appendChild(icon);
    const textSpan = document.createElement('span');
    textSpan.className = 'ms-1';
    textSpan.id = 'addProductBtnText';
    textSpan.textContent = text;
    button.appendChild(textSpan);
}

// Update add product button text and behavior based on income type
function updateAddProductButton(type) {
    const addProductBtn = document.getElementById('addProductBtn');
    if (!addProductBtn) {
        return;
    }
    
    // Check if translations are available
    const translations = window.translations || {};
    const addProductText = translations.addProduct || 'Add Product';
    
    if (type === 'consultation') {
        renderAddProductButton(addProductBtn, addProductText);
        addProductBtn.setAttribute('aria-label', addProductText);
        addProductBtn.onclick = toggleProductItems;
    } else if (type === 'product_sale') {
        renderAddProductButton(addProductBtn, addProductText);
        addProductBtn.setAttribute('aria-label', addProductText);
        addProductBtn.onclick = addProductItem;
    }

    if (typeof updateAddProductButtonLabel === 'function') {
        updateAddProductButtonLabel();
    }
}

function dcmtResolveInventoryOptionsHTML(selectProductText) {
    if (typeof window.inventoryOptionsHTML !== 'undefined' && window.inventoryOptionsHTML && window.inventoryOptionsHTML.trim() !== '') {
        return window.inventoryOptionsHTML;
    }
    if (typeof window.dcmtGetProductInventoryOptionsHTML === 'function') {
        const html = window.dcmtGetProductInventoryOptionsHTML();
        if (html && html.trim() !== '') {
            window.inventoryOptionsHTML = html;
            return html;
        }
    }
    const firstSelect = document.querySelector('#productItems .product-inventory');
    if (firstSelect) {
        const html = firstSelect.innerHTML;
        window.inventoryOptionsHTML = html;
        return html;
    }
    return `<option value="">${selectProductText}</option>`;
}

// Toggle product items visibility for consultation
function toggleProductItems() {
    const productItems = document.getElementById('productItems');
    const addProductBtn = document.getElementById('addProductBtn');
    
    const translations = window.translations || {};
    const addProductText = translations.addProduct || 'Add Product';
    
    if (!productItems || !addProductBtn) {
        return;
    }
    
    if (productItems.style.display === 'none' || productItems.style.display === '') {
        productItems.style.display = 'block';
    }
    
    renderAddProductButton(addProductBtn, addProductText);
    addProductBtn.setAttribute('aria-label', addProductText);
    addProductBtn.onclick = addProductItem;
    
    addProductItem();
}

// Add new product item row (updated for Select2)
function addProductItem() {
    const container = document.getElementById('productItems');
    const newItem = document.createElement('div');
    newItem.className = 'product-item row mb-2';
    
    // Get the current number of existing items to use as index
    const existingItems = container.querySelectorAll('.product-item');
    const currentIndex = existingItems.length;
    
    // Get translations if available
    const translations = window.translations || {};
    const selectProductText = translations.selectProduct || 'Select Product';
    const qtyText = translations.qty || 'Qty';
    const priceText = translations.price || 'Price';
    const totalText = translations.total || 'Total';
    
    // Use the stored inventory options or fallback to basic option
    const optionsHTML = dcmtResolveInventoryOptionsHTML(selectProductText);
    
    newItem.innerHTML = `
        <div class="col-md-4">
            <select class="form-select product-inventory" name="product_items[${currentIndex}][inventory_id]" onchange="updateProductPrice(this, ${currentIndex}); checkAndShowProductPaidAmount();">
                ${optionsHTML}
            </select>
        </div>
        <div class="col-md-2">
            <input type="number" class="form-control product-quantity" name="product_items[${currentIndex}][quantity]" 
                   placeholder="${qtyText}" min="1" value="" onchange="updateProductTotal(${currentIndex})" oninput="updateProductTotal(${currentIndex})">
        </div>
        <div class="col-md-2" style="display: none;">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol">${typeof dcmtCurrencySymbolClient !== 'undefined' ? dcmtCurrencySymbolClient : (typeof window.dcmtCurrencySymbolClient !== 'undefined' ? window.dcmtCurrencySymbolClient : '$')}</span>
                <input type="number" class="form-control dcmt-amount-input product-price" name="product_items[${currentIndex}][unit_price]" 
                       placeholder="${priceText}" step="0.01" min="0.01" onchange="updateProductTotal(${currentIndex})" oninput="updateProductTotal(${currentIndex})">
            </div>
        </div>
        <div class="col-md-2" style="display: none;">
            <div class="dcmt-amount-input-wrapper">
                <span class="dcmt-currency-symbol">${typeof dcmtCurrencySymbolClient !== 'undefined' ? dcmtCurrencySymbolClient : (typeof window.dcmtCurrencySymbolClient !== 'undefined' ? window.dcmtCurrencySymbolClient : '$')}</span>
                <input type="text" class="form-control dcmt-amount-input product-total" placeholder="${totalText}" readonly>
            </div>
        </div>
        <div class="col-md-2 dcmt-delete-cell">
            <input type="hidden" class="product-type" name="product_items[${currentIndex}][product_type]" value="">
            <button type="button" class="btn btn-outline-danger btn-sm remove-product-btn">
                <i class="fas fa-trash"></i>
            </button>
        </div>
    `;
    
    container.appendChild(newItem);
    
    // Initialize Select2 on the new select element if jQuery is available
    if (typeof $ !== 'undefined') {
        $(newItem).find('.product-inventory').select2({
            placeholder: selectProductText,
            allowClear: true,
            width: '100%'
        }).val('').trigger('change');  // Explicitly clear any value to ensure it starts empty
    }
    
    // Set required attributes based on current income type
    const typeSelect = document.getElementById('type');
    if (typeSelect.value === 'product_sale') {
        const newFields = newItem.querySelectorAll('.product-inventory, .product-quantity, .product-price');
        newFields.forEach(field => field.setAttribute('required', 'required'));
    }
    
    // Check if product paid amount field should be shown for consultation type
    if (typeof checkAndShowProductPaidAmount === 'function') {
        checkAndShowProductPaidAmount();
    }

    if (typeof updateAddProductButtonLabel === 'function') {
        updateAddProductButtonLabel();
    }
}

// Remove product item row (updated for Select2)
function removeProductItem(button) {
    // Get translations if available
    const translations = window.translations || {};
    const confirmDeleteText = translations.confirmDeleteProduct || 'Are you sure you want to delete this product item?';
    
    if (confirm(confirmDeleteText)) {
        const productItem = button.closest('.product-item');
        if (productItem) {
            // Destroy Select2 before removing the element if jQuery is available and Select2 is initialized
            if (typeof $ !== 'undefined') {
                const inventorySelect = productItem.querySelector('.product-inventory');
                if (inventorySelect) {
                    const $select = $(inventorySelect);
                    // Check if Select2 is initialized by checking for the class and data
                    if ($select.hasClass('select2-hidden-accessible')) {
                        try {
                            // Check if Select2 data exists before destroying
                            const select2Data = $select.data('select2');
                            if (select2Data) {
                                $select.select2('destroy');
                            }
                        } catch (e) {
                            // If destroy fails, just continue - the element will be removed anyway
                            console.warn('Error destroying Select2:', e);
                        }
                    }
                }
            }
            productItem.remove();
            
            // Always recalculate the total amount
            recalculateTotalAmount();
            
            // Check if product paid amount field should be shown for consultation type
            if (typeof checkAndShowProductPaidAmount === 'function') {
                checkAndShowProductPaidAmount();
            }

            if (typeof updateAddProductButtonLabel === 'function') {
                updateAddProductButtonLabel();
            }
        }
    }
}

// Update product price when inventory is selected (updated for Select2)
function updateProductPrice(select, index) {
    // Check if jQuery and Select2 are available
    if (typeof $ !== 'undefined' && $(select).hasClass('select2-hidden-accessible')) {
        const selectedOption = $(select).find('option:selected');
        if (selectedOption && selectedOption.val()) {
            const price = selectedOption.attr('data-price');
            const stock = selectedOption.attr('data-stock');
            const productType = selectedOption.attr('data-product-type');
            const productItem = $(select).closest('.product-item');
            
            if (productType === 'for_use') {
                // Hide price and total fields for "for use" items
                productItem.find('.product-price').closest('.col-md-2').hide();
                productItem.find('.product-total').closest('.col-md-2').hide();
                // Clear price and total values
                productItem.find('.product-price').val('');
                productItem.find('.product-total').val('');
                // Remove validation attributes to prevent form validation errors
                productItem.find('.product-price').removeAttr('required min max step');
                // Set product type in hidden field
                productItem.find('.product-type').val('for_use');
            } else {
                // Show price and total fields for "for sale" items
                productItem.find('.product-price').closest('.col-md-2').show();
                productItem.find('.product-total').closest('.col-md-2').show();
                // Restore validation attributes for "for sale" items
                productItem.find('.product-price').attr('step', '0.01').attr('min', '0.01');
                // Set product type in hidden field
                productItem.find('.product-type').val('for_sale');
                
                if (price) {
                    const priceInput = productItem.find('.product-price');
                    if (priceInput.length) {
                        priceInput.val(price);
                        // Use event-based update instead of index-based
                        const event = { target: priceInput[0] };
                        updateProductTotalByEvent(event);
                    }
                }
            }
            
            // Update quantity field max attribute and validate current value
            const quantityInput = productItem.find('.product-quantity');
            if (quantityInput.length && stock) {
                quantityInput.attr('max', stock);
                validateProductQuantity(quantityInput[0]);
            }
        } else {
            // Reset to default state when no product is selected - keep price and total hidden
            const productItem = $(select).closest('.product-item');
            productItem.find('.product-price').closest('.col-md-2').hide();
            productItem.find('.product-total').closest('.col-md-2').hide();
            productItem.find('.product-price').val('');
            productItem.find('.product-total').val('');
        }
    } else {
        // Fallback for regular select elements
        const selectedOption = select.options[select.selectedIndex];
        if (selectedOption && selectedOption.value) {
            const price = selectedOption.getAttribute('data-price');
            const stock = selectedOption.getAttribute('data-stock');
            const productType = selectedOption.getAttribute('data-product-type');
            const productItem = select.closest('.product-item');
            
            if (productType === 'for_use') {
                // Hide price and total fields for "for use" items
                const priceCol = productItem.querySelector('.product-price').closest('.col-md-2');
                const totalCol = productItem.querySelector('.product-total').closest('.col-md-2');
                if (priceCol) priceCol.style.display = 'none';
                if (totalCol) totalCol.style.display = 'none';
                // Clear price and total values
                const priceInput = productItem.querySelector('.product-price');
                const totalInput = productItem.querySelector('.product-total');
                if (priceInput) priceInput.value = '';
                if (totalInput) totalInput.value = '';
            } else {
                // Show price and total fields for "for sale" items
                const priceCol = productItem.querySelector('.product-price').closest('.col-md-2');
                const totalCol = productItem.querySelector('.product-total').closest('.col-md-2');
                if (priceCol) priceCol.style.display = '';
                if (totalCol) totalCol.style.display = '';
                
                if (price) {
                    const priceInput = productItem.querySelector('.product-price');
                    if (priceInput) {
                        priceInput.value = price;
                        // Use event-based update instead of index-based
                        const event = { target: priceInput };
                        updateProductTotalByEvent(event);
                    }
                }
            }
            
            // Update quantity field max attribute and validate current value
            const quantityInput = productItem.querySelector('.product-quantity');
            if (quantityInput && stock) {
                quantityInput.setAttribute('max', stock);
                validateProductQuantity(quantityInput);
            }
        } else {
            // Reset to default state when no product is selected - keep price and total hidden
            const productItem = select.closest('.product-item');
            const priceCol = productItem.querySelector('.product-price').closest('.col-md-2');
            const totalCol = productItem.querySelector('.product-total').closest('.col-md-2');
            if (priceCol) priceCol.style.display = 'none';
            if (totalCol) totalCol.style.display = 'none';
            const priceInput = productItem.querySelector('.product-price');
            const totalInput = productItem.querySelector('.product-total');
            if (priceInput) priceInput.value = '';
            if (totalInput) totalInput.value = '';
        }
    }
}

// Update product total when quantity or price changes
function updateProductTotal(index) {
    // Find the product item that contains the changed input
    const productItems = document.querySelectorAll('.product-item');
    let targetItem = null;
    
    // Try to find the item by index first
    if (index >= 0 && index < productItems.length) {
        targetItem = productItems[index];
    } else {
        // If index is invalid, find the item by looking for the input that triggered the event
        // This is a fallback method
        const allInputs = document.querySelectorAll('.product-quantity, .product-price');
        for (let input of allInputs) {
            if (input.value && input.value !== '') {
                targetItem = input.closest('.product-item');
                break;
            }
        }
    }
    
    if (targetItem) {
        const quantityInput = targetItem.querySelector('.product-quantity');
        const priceInput = targetItem.querySelector('.product-price');
        const totalInput = targetItem.querySelector('.product-total');
        const inventorySelect = targetItem.querySelector('.product-inventory');
        
        // Check if this is a "for use" item
        let isForUseItem = false;
        if (inventorySelect) {
            let selectedOption;
            if (typeof $ !== 'undefined' && $(inventorySelect).hasClass('select2-hidden-accessible')) {
                selectedOption = $(inventorySelect).find('option:selected');
            } else {
                const selectedIndex = inventorySelect.selectedIndex;
                if (selectedIndex > 0) {
                    selectedOption = inventorySelect.options[selectedIndex];
                }
            }
            
            if (selectedOption && selectedOption.length) {
                const productType = selectedOption.attr('data-product-type') || selectedOption.getAttribute('data-product-type');
                isForUseItem = (productType === 'for_use');
            }
        }
        
        if (isForUseItem) {
            // For "for use" items, set total to 0 and don't calculate price
            if (totalInput) {
                totalInput.value = '0.00';
            }
        } else {
            // For "for sale" items, calculate normally
            const quantity = parseFloat(quantityInput.value) || 0;
            const price = parseFloat(priceInput.value) || 0;
            const total = quantity * price;
            
            if (totalInput) {
                totalInput.value = total.toFixed(2);
            }

        }
        
        // Validate quantity against stock
        if (quantityInput) {
            validateProductQuantity(quantityInput);
        }
        
        // Always recalculate the total amount
        recalculateTotalAmount();
    } else {

    }
}

// Validate product quantity against available stock
function validateProductQuantity(quantityInput) {
    const productItem = quantityInput.closest('.product-item');
    const inventorySelect = productItem.querySelector('.product-inventory');
    const quantity = parseFloat(quantityInput.value) || 0;
    
    if (inventorySelect && quantity > 0) {
        let selectedOption;
        let inventoryId;
        
        // Check if using Select2
        if (typeof $ !== 'undefined' && $(inventorySelect).hasClass('select2-hidden-accessible')) {
            selectedOption = $(inventorySelect).find('option:selected');
            if (selectedOption.length) {
                inventoryId = selectedOption.val();
                const stock = parseInt(selectedOption.attr('data-stock')) || 0;
                
                // Calculate effective stock based on context (add vs edit)
                const effectiveStock = calculateEffectiveStockForContext(inventoryId, stock);
                
                if (quantity > effectiveStock) {
                    showStockValidationError(quantityInput, effectiveStock);
                    return false;
                } else {
                    clearStockValidationError(quantityInput);
                    return true;
                }
            }
        } else {
            // Regular select element
            const selectedIndex = inventorySelect.selectedIndex;
            if (selectedIndex > 0) {
                const selectedOption = inventorySelect.options[selectedIndex];
                inventoryId = selectedOption.value;
                const stock = parseInt(selectedOption.getAttribute('data-stock')) || 0;
                
                // Calculate effective stock based on context (add vs edit)
                const effectiveStock = calculateEffectiveStockForContext(inventoryId, stock);
                
                if (quantity > effectiveStock) {
                    showStockValidationError(quantityInput, effectiveStock);
                    return false;
                } else {
                    clearStockValidationError(quantityInput);
                    return true;
                }
            }
        }
    }
    
    clearStockValidationError(quantityInput);
    return true;
}

// Calculate effective stock based on context (add vs edit)
function calculateEffectiveStockForContext(inventoryId, currentStock) {
    // Check if we're in edit context by looking at the URL
    const currentPath = window.location.pathname;
    const isEditContext = currentPath.includes('/edit.php');
    
    if (isEditContext) {
        // For edit context, calculate effective stock (current stock + current quantity from this income)
        return calculateEffectiveStockForEdit(inventoryId, currentStock);
    } else {
        // For add context, just use current stock
        return currentStock;
    }
}

// Calculate effective stock for edit scenario
function calculateEffectiveStockForEdit(inventoryId, currentStock) {
    // Find the current quantity for this inventory item in the existing income
    const productItems = document.querySelectorAll('.product-item');
    let currentQuantity = 0;
    
    productItems.forEach(item => {
        const inventorySelect = item.querySelector('.product-inventory');
        const quantityInput = item.querySelector('.product-quantity');
        
        if (inventorySelect && quantityInput) {
            let selectedInventoryId;
            
            // Check if using Select2
            if (typeof $ !== 'undefined' && $(inventorySelect).hasClass('select2-hidden-accessible')) {
                const selectedOption = $(inventorySelect).find('option:selected');
                if (selectedOption.length) {
                    selectedInventoryId = selectedOption.val();
                }
            } else {
                // Regular select element
                const selectedIndex = inventorySelect.selectedIndex;
                if (selectedIndex > 0) {
                    selectedInventoryId = inventorySelect.options[selectedIndex].value;
                }
            }
            
            // If this is the same inventory item, add its current quantity
            if (selectedInventoryId === inventoryId) {
                const quantity = parseFloat(quantityInput.value) || 0;
                currentQuantity += quantity;
            }
        }
    });
    
    // Effective stock = current stock + current quantity from this income
    return currentStock + currentQuantity;
}

// Show stock validation error
function showStockValidationError(quantityInput, availableStock) {
    const productItem = quantityInput.closest('.product-item');
    let errorDiv = productItem.querySelector('.stock-validation-error');
    
    if (!errorDiv) {
        errorDiv = document.createElement('div');
        errorDiv.className = 'stock-validation-error text-danger small mt-1';
        quantityInput.parentNode.appendChild(errorDiv);
    }
    
    errorDiv.textContent = `Available stock: ${availableStock}`;
    quantityInput.classList.add('is-invalid');
}

// Clear stock validation error
function clearStockValidationError(quantityInput) {
    const productItem = quantityInput.closest('.product-item');
    const errorDiv = productItem.querySelector('.stock-validation-error');
    
    if (errorDiv) {
        errorDiv.remove();
    }
    
    quantityInput.classList.remove('is-invalid');
}

// Update product total using event delegation (more reliable)
function updateProductTotalByEvent(event) {
    const targetItem = event.target.closest('.product-item');
    if (targetItem) {
        const inventorySelect = targetItem.querySelector('.product-inventory');
        const totalInput = targetItem.querySelector('.product-total');
        
        // Check if this is a "for use" item
        let isForUseItem = false;
        if (inventorySelect) {
            let selectedOption;
            if (typeof $ !== 'undefined' && $(inventorySelect).hasClass('select2-hidden-accessible')) {
                selectedOption = $(inventorySelect).find('option:selected');
            } else {
                const selectedIndex = inventorySelect.selectedIndex;
                if (selectedIndex > 0) {
                    selectedOption = inventorySelect.options[selectedIndex];
                }
            }
            
            if (selectedOption && selectedOption.length) {
                const productType = selectedOption.attr('data-product-type') || selectedOption.getAttribute('data-product-type');
                isForUseItem = (productType === 'for_use');
            }
        }
        
        if (isForUseItem) {
            // For "for use" items, set total to 0
            if (totalInput) {
                totalInput.value = '0.00';
            }
        } else {
            // For "for sale" items, calculate normally
            const quantity = parseFloat(targetItem.querySelector('.product-quantity').value) || 0;
            const price = parseFloat(targetItem.querySelector('.product-price').value) || 0;
            const total = quantity * price;
            
            if (totalInput) {
                totalInput.value = total.toFixed(2);
            }

        }
        
        // Validate quantity if this is a quantity input
        if (event.target.classList.contains('product-quantity')) {
            validateProductQuantity(event.target);
        }
        
        // Always recalculate the total amount
        recalculateTotalAmount();
    }
}

// Recalculate total amount based on current income type
function recalculateTotalAmount() {
    const typeSelect = document.getElementById('type');

    if (typeSelect && typeSelect.value === 'consultation') {
        calculateTotalAmountForConsultation();
    } else if (typeSelect && typeSelect.value === 'product_sale') {
        calculateTotalAmount();
    }
}

// Calculate total amount from all product items
function calculateTotalAmount() {
    let total = 0;
    const productTotals = document.querySelectorAll('.product-total');

    productTotals.forEach((input, index) => {
        const value = parseFloat(input.value) || 0;
        total += value;

    });

    const amountInput = document.getElementById('amount');
    if (amountInput) {
        amountInput.value = total.toFixed(2);
    }
    
    // Update Product Amount field with the total
    const productAmountInput = document.getElementById('product_amount');
    if (productAmountInput) {
        productAmountInput.value = total.toFixed(2);
        // Trigger payment calculations to update pending amount
        updatePaymentCalculations();
    }
    if (typeof refreshPaymentSummaries === 'function') {
        refreshPaymentSummaries();
    }
}

// Initialize page functionality
document.addEventListener('DOMContentLoaded', function() {
    // Initialize tooltips if Bootstrap is available
    if (typeof bootstrap !== 'undefined' && bootstrap.Tooltip) {
        const tooltipTriggerList = [].slice.call(document.querySelectorAll('[data-bs-toggle="tooltip"]'));
        tooltipTriggerList.map(function (tooltipTriggerEl) {
            return new bootstrap.Tooltip(tooltipTriggerEl);
        });
    }
    
    // Initialize income type fields if on income page
    const incomeTypeSelect = document.getElementById('type');
    if (incomeTypeSelect) {
        toggleTypeFields();
        
        // After toggleTypeFields, ensure product items are visible if they exist
        // This ensures stored product items display even for consultation type
        setTimeout(function() {
            const productItems = document.getElementById('productItems');
            if (productItems) {
                const hasProductItems = productItems.querySelectorAll('.product-item').length > 0;
                if (hasProductItems) {
                    productItems.style.display = 'block';
                }
            }
        }, 50);
    }
    
    // Initialize product fields if on income page
    const productItems = document.querySelectorAll('.product-item');
    if (productItems.length > 0) {
        // Store the original inventory options for cloning
        const firstProductItem = productItems[0];
        if (firstProductItem) {
            const inventorySelect = firstProductItem.querySelector('.product-inventory');
            if (inventorySelect) {
                inventoryOptionsHTML = inventorySelect.innerHTML;
            }
        }
        
        const incomeType = document.getElementById('type')?.value;
        if (incomeType === 'product_sale') {
            addRequiredToProductFields();
            updateProductFieldsHelp('product_sale');
            updateAddProductButton('product_sale');
        } else if (incomeType === 'consultation') {
            removeRequiredFromProductFields();
            updateProductFieldsHelp('consultation');
            updateAddProductButton('consultation');
        } else {
            removeRequiredFromProductFields();
            updateProductFieldsHelp('none');
        }
    }
    
    // Set up event delegation for product items
    setupProductItemEventDelegation();
});

// Form validation helpers
function validateNumericInput(input) {
    if (input.classList.contains('dcmt-starting-denomination-input') || input.classList.contains('dcmt-ending-denomination-input')) {
        input.setCustomValidity('');
        return true;
    }
    const value = parseFloat(input.value);
    if (isNaN(value) || value < 0) {
        input.setCustomValidity('Please enter a valid positive number');
        return false;
    } else {
        input.setCustomValidity('');
        return true;
    }
}

function validateRequiredField(input) {
    if (!input.value.trim()) {
        input.setCustomValidity('This field is required');
        return false;
    } else {
        input.setCustomValidity('');
        return true;
    }
}

// Add validation event listeners
document.addEventListener('DOMContentLoaded', function() {
    const numericInputs = document.querySelectorAll('input[type="number"]:not(.dcmt-starting-denomination-input):not(.dcmt-ending-denomination-input)');
    numericInputs.forEach(input => {
        input.addEventListener('blur', () => validateNumericInput(input));
    });
    
    const requiredInputs = document.querySelectorAll('input[required], select[required], textarea[required]');
    requiredInputs.forEach(input => {
        input.addEventListener('blur', () => validateRequiredField(input));
    });
});

// Global variable for product item count
let productItemCount = 1;

// Global variable to store inventory options
let inventoryOptionsHTML = '';

// Set up event delegation for product items (updated for Select2)
function setupProductItemEventDelegation() {
    const productItemsContainer = document.getElementById('productItems');
    if (productItemsContainer) {
        // Handle inventory selection changes (using Select2 events if available)
        if (typeof $ !== 'undefined') {
            $(productItemsContainer).on('change', '.product-inventory', function(event) {
                updateProductPrice(this);
            });
        } else {
            // Fallback for regular select elements
            productItemsContainer.addEventListener('change', function(event) {
                if (event.target.classList.contains('product-inventory')) {
                    updateProductPrice(event.target);
                }
            });
        }
        
        // Handle quantity and price changes
        productItemsContainer.addEventListener('input', function(event) {
            if (event.target.classList.contains('product-quantity') || event.target.classList.contains('product-price')) {
                updateProductTotalByEvent(event);
            }
        });
        
        // Add form submission validation
        const incomeForm = document.querySelector('form');
        if (incomeForm) {
            incomeForm.addEventListener('submit', function(event) {
                const quantityInputs = productItemsContainer.querySelectorAll('.product-quantity');
                let hasValidationErrors = false;
                
                quantityInputs.forEach(quantityInput => {
                    if (!validateProductQuantity(quantityInput)) {
                        hasValidationErrors = true;
                    }
                });
                
                if (hasValidationErrors) {
                    event.preventDefault();
                    alert('Please fix stock validation errors before submitting the form.');
                    return false;
                }
            });
        }
        
        // Handle remove button clicks - only if not already handled by page-specific version
        // Check if a custom handler has been set up (for add/edit pages)
        if (!productItemsContainer.dataset.removeHandlerSetup) {
        productItemsContainer.addEventListener('click', function(event) {
            if (event.target.closest('.remove-product-btn')) {
                removeProductItem(event.target.closest('.remove-product-btn'));
            }
        });
            productItemsContainer.dataset.removeHandlerSetup = 'true';
        }
    }
}

// Form change detection system
let dcmtFormChanged = false;
let dcmtOriginalFormData = {};

// Initialize form change detection
function dcmtInitFormChangeDetection(formId) {
    const form = document.getElementById(formId);
    if (!form) return;

    // Store original form data
    dcmtStoreOriginalFormData(form);
    
    // Add change listeners to all form elements
    const formElements = form.querySelectorAll('input, select, textarea');
    formElements.forEach(element => {
        element.addEventListener('change', dcmtMarkFormAsChanged);
        element.addEventListener('input', dcmtMarkFormAsChanged);
    }); 
    
    // Add click listeners to navigation links
    dcmtAddNavigationListeners();
    
    // Mark form as unchanged initially
    dcmtFormChanged = false;
}

// Store original form data
function dcmtStoreOriginalFormData(form) {
    const formData = new FormData(form);
    dcmtOriginalFormData = {};
    
    for (let [key, value] of formData.entries()) {
        dcmtOriginalFormData[key] = value;
    }
    
    // Also store unchecked checkboxes and unselected options
    const inputs = form.querySelectorAll('input, select, textarea');
    inputs.forEach(input => {
        if (input.type === 'checkbox' && !input.checked) {
            dcmtOriginalFormData[input.name] = '';
        } else if (input.type === 'radio' && !input.checked) {
            if (!dcmtOriginalFormData[input.name]) {
                dcmtOriginalFormData[input.name] = '';
            }
        }
    });

}

// Mark form as changed
function dcmtMarkFormAsChanged() {
    dcmtFormChanged = true;

}

// Check if form has actually changed
function dcmtHasFormChanged(form) {
    const currentFormData = new FormData(form);
    
    // Compare current data with original
    for (let [key, value] of currentFormData.entries()) {
        if (dcmtOriginalFormData[key] !== value) {

            return true;
        }
    }
    
    // Check for removed values
    for (let key in dcmtOriginalFormData) {
        if (!currentFormData.has(key) && dcmtOriginalFormData[key] !== '') {

            return true;
        }
    }
    
    return false;
}

// Handle beforeunload event (for browser navigation like back/forward/close/refresh)
function dcmtHandleBeforeUnload(event) {
    if (dcmtFormChanged) {
        // Double-check if form actually has changes
        const form = document.querySelector('form[id$="Form"]'); // Find any form ending with "Form"
        if (form && dcmtHasFormChanged(form)) {
            // Modern browsers will show their own message, but we still need to prevent default
            event.preventDefault();
            event.returnValue = ''; // Modern browsers ignore custom messages
            return ''; // For older browsers
        } else {
            // False positive, clear the flag
            dcmtClearFormChanged();
        }
    }
}

// Add navigation listeners
function dcmtAddNavigationListeners() {
    // Get all navigation links (internal navigation)
    const navLinks = document.querySelectorAll('a:not([href^="#"]):not([href^="javascript:"]):not([target="_blank"]):not([href^="http"])');
    
    navLinks.forEach(link => {
        link.addEventListener('click', function(event) {
            if (dcmtFormChanged) {
                const linkText = this.textContent.trim() || 'this page';
                const t = window.translations || {};
                const msgTemplate = t.unsaved_changes_message || 'You have unsaved changes that will be lost.\n\nAre you sure you want to navigate to "{link}"?';
                const confirmed = confirm(msgTemplate.replace('{link}', linkText));
                if (!confirmed) {
                    event.preventDefault();
                    return false;
                } else {
                    // User confirmed leaving, clear the flag to prevent beforeunload warning
                    dcmtClearFormChanged();
                }
            }
        });
    });

}

// Clear form changed flag (call when form is successfully submitted)
function dcmtClearFormChanged() {
    dcmtFormChanged = false;

}

// Clean up form change detection (remove event listeners)
function dcmtCleanupFormChangeDetection() {
    dcmtFormChanged = false;
    dcmtOriginalFormData = {};
    window.removeEventListener('beforeunload', dcmtHandleBeforeUnload);

}

// Reset form function for income forms
function resetForm() {
    if (confirm('Are you sure you want to reset the form? All entered data will be lost.')) {
        // Clear form changed flag since user confirmed reset
        dcmtClearFormChanged();
        const form = document.getElementById('incomeForm');
        if (form) {
            form.reset();
            
            // Reset specific fields to default values
            const transactionDateField = document.getElementById('transaction_date');
            if (transactionDateField) {
                transactionDateField.value = new Date().toISOString().split('T')[0];
            }
            
            // Reset payment status based on payment mode
            const paymentModeField = document.getElementById('payment_mode');
            const paymentStatusField = document.getElementById('payment_status');
            if (paymentModeField && paymentStatusField) {
                updatePaymentStatus();
            }
            
            // Reset type-specific fields
            const typeField = document.getElementById('type');
            if (typeField) {
                toggleTypeFields();
            }
            
            // Reset product items if any
            const productItemsContainer = document.getElementById('productItems');
            if (productItemsContainer) {
                productItemsContainer.innerHTML = '';
                addProductItem();
            }
        }
    }
}
