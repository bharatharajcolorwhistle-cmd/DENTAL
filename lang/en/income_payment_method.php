<?php
/**
 * English Translations - Income Payment Methods
 */

return [
    // Page titles
    "income_payment_methods" => "Income Payment Methods",
    "add_payment_method" => "Add Payment Method",
    "edit_payment_method" => "Edit Payment Method",
    "delete_payment_method" => "Delete Payment Method",
    "view_all_payment_methods" => "View All Payment Methods",
    
    // Form fields
    "name" => "Payment Method Name",
    "payment_method" => "Payment Method",
    "description" => "Description",
    "status" => "Status",
    "usage_count" => "Usage Count",
    "created_at" => "Created At",
    "updated_at" => "Updated At",
    
    // Form placeholders and help text
    "enter_name" => "Enter payment method name",
    "enter_description" => "Enter payment method description",
    "name_help" => "Enter a unique name for the payment method (max 100 characters)",
    "description_help" => "Optional description for the payment method (max 500 characters)",
    
    // Actions
    "add_payment_method_record" => "Add Payment Method",
    "update_payment_method_record" => "Update Payment Method",
    "adding" => "Adding",
    "updating" => "Updating",
    "reset" => "Reset",
    "confirm_reset" => "Are you sure you want to reset the form?",
    
    // Messages
    "add_success" => "Payment method added successfully",
    "update_success" => "Payment method updated successfully",
    "delete_success" => "Payment method deleted successfully",
    "delete_error" => "Error deleting payment method",
    "delete_cancelled" => "Payment method deletion cancelled",
    "load_error" => "Error loading payment methods",
    "database_error" => "Database error occurred",
    
    // Validation messages
    "name_required" => "Payment method name is required",
    "name_too_long" => "Payment method name is too long (max 100 characters)",
    "name_exists" => "A payment method with this name already exists",
    "invalid_token" => "Invalid security token",
    
    // List page
    "payment_methods_list" => "Payment Methods List",
    "no_payment_methods_found" => "No payment methods found",
    "no_payment_methods_message" => "No payment methods have been created yet. Add your first payment method to get started.",
    "add_first_payment_method" => "Add First Payment Method",
    "search_placeholder" => "Search payment methods...",
    
    // Delete confirmation
    "confirm_deletion" => "Confirm Deletion",
    "confirm_delete" => "Are you sure you want to delete this payment method?",
    "confirm_delete_warning" => "I understand that this action cannot be undone and will permanently delete this payment method.",
    "payment_method_details_to_delete" => "Payment Method Details",
    "back_to_payment_methods" => "Back to Payment Methods",
    "warning" => "Warning",
    "cannot_delete_in_use" => "This payment method cannot be deleted because it is currently being used by one or more income records.",
    
    // Management
    "manage_payment_methods" => "Manage Income Payment Methods",
    
    // Additional keys for new design
    "search_results_for" => "Search results for",
    "income_records" => "income records",
    "delete_warning_message" => "This action cannot be undone.",
    "expense_records" => "expense records",
    
    // AJAX deletion
    "invalid_payment_method_id" => "Invalid payment method ID",
    "payment_method_not_found" => "Payment method not found",
    
    // Default system payment methods (cannot be edited)
    "name_locked" => "This payment method name cannot be changed because it is a system default",
    "system_default" => "System Default",
    
    // Default payment method names (system defaults)
    "Cash" => "Cash",
    "Credit Card" => "Credit Card",
    "Debit Card" => "Debit Card",
    "Bank Transfer" => "Bank Transfer",
    "Online Payment" => "Online Payment",
    "Check" => "Check",

    // Bulk delete
    "please_select_one_record" => "Please select at least one payment method to delete.",
    "confirm_delete_single" => "Are you sure you want to delete this payment method? This action cannot be undone.",
    "confirm_delete_multiple" => "Are you sure you want to delete these {count} payment methods? This action cannot be undone.",
    "bulk_delete_success" => "{count} payment methods deleted successfully!",
    "bulk_delete_partial" => "{deleted} payment methods deleted. {skipped} could not be deleted because they are system defaults or are used by income records.",
    "bulk_delete_none" => "None of the selected payment methods could be deleted. System defaults and methods used by income records cannot be deleted.",
    "deleting_records" => "Deleting payment methods...",
    "failed_to_delete_records" => "Failed to delete payment methods",
    "error_occurred_deleting_records" => "An error occurred while deleting the payment methods. Please try again.",
    "invalid_json_input" => "Invalid JSON input",
    "invalid_ids" => "Invalid payment method IDs",
    "records_not_found" => "One or more payment methods not found"
];
