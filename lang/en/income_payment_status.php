<?php
/**
 * English Translations - Income Payment Status
 */

return [
    // Page titles
    "income_payment_statuses" => "Income Payment Status",
    "add_payment_status" => "Add Payment Status",
    "edit_payment_status" => "Edit Payment Status",
    "delete_payment_status" => "Delete Payment Status",
    "view_all_payment_statuses" => "View All Payment Statuses",
    
    // Form fields
    "name" => "Payment Status Name",
    "payment_status" => "Payment Status",
    "description" => "Description",
    "color" => "Color",
    "status" => "Status",
    "usage_count" => "Usage Count",
    "created_at" => "Created At",
    "updated_at" => "Updated At",
    
    // Form placeholders and help text
    "enter_name" => "Enter payment status name",
    "enter_description" => "Enter payment status description",
    "choose_color" => "Choose color for this payment status",
    "name_help" => "Enter a unique name for the payment status (max 100 characters)",
    "description_help" => "Optional description for the payment status (max 500 characters)",
    "color_help" => "Choose a color to represent this payment status",
    
    // Actions
    "add_payment_status_record" => "Add Payment Status",
    "update_payment_status_record" => "Update Payment Status",
    "adding" => "Adding",
    "updating" => "Updating",
    "reset" => "Reset",
    "confirm_reset" => "Are you sure you want to reset the form?",
    
    // Messages
    "add_success" => "Payment status added successfully",
    "update_success" => "Payment status updated successfully",
    "delete_success" => "Payment status deleted successfully",
    "delete_error" => "Error deleting payment status",
    "delete_cancelled" => "Payment status deletion cancelled",
    "load_error" => "Error loading payment statuses",
    "database_error" => "Database error occurred",
    
    // Validation messages
    "name_required" => "Payment status name is required",
    "name_too_long" => "Payment status name is too long (max 100 characters)",
    "name_exists" => "A payment status with this name already exists",
    "invalid_color" => "Invalid color format. Please use a valid hex color.",
    "invalid_token" => "Invalid security token",
    
    // List page
    "payment_statuses_list" => "Payment Status List",
    "no_payment_statuses_found" => "No payment statuses found",
    "no_payment_statuses_message" => "No payment statuses have been created yet. Add your first payment status to get started.",
    "add_first_payment_status" => "Add First Payment Status",
    "search_placeholder" => "Search payment statuses...",
    
    // Delete confirmation
    "confirm_deletion" => "Confirm Deletion",
    "confirm_delete" => "Are you sure you want to delete this payment status?",
    "confirm_delete_warning" => "I understand that this action cannot be undone and will permanently delete this payment status.",
    "payment_status_details_to_delete" => "Payment Status Details",
    "back_to_payment_statuses" => "Back to Payment Statuses",
    "warning" => "Warning",
    "cannot_delete_in_use" => "This payment status cannot be deleted because it is currently being used by one or more income records.",
    
    // Management
    "manage_payment_statuses" => "Manage Income Payment Statuses",
    
    // Additional keys for new design
    "search_results_for" => "Search results for",
    "income_records" => "income records",
    "delete_warning_message" => "This action cannot be undone.",
    
    // AJAX deletion
    "invalid_payment_status_id" => "Invalid payment status ID",
    "payment_status_not_found" => "Payment status not found",
    
    // Default system payment statuses (cannot be edited)
    "name_locked" => "This payment status name cannot be changed because it is a system default",
    "system_default" => "System Default",
    
    // Default payment status names (system defaults)
    "Completed" => "Completed",
    "Pending" => "Pending",
    "Failed" => "Failed",
    "Cancelled" => "Cancelled",
    "Refunded" => "Refunded",

    // Bulk delete
    "please_select_one_record" => "Please select at least one payment status to delete.",
    "confirm_delete_single" => "Are you sure you want to delete this payment status? This action cannot be undone.",
    "confirm_delete_multiple" => "Are you sure you want to delete these {count} payment statuses? This action cannot be undone.",
    "bulk_delete_success" => "{count} payment statuses deleted successfully!",
    "bulk_delete_partial" => "{deleted} payment statuses deleted. {skipped} could not be deleted because they are system defaults or are used by income records.",
    "bulk_delete_none" => "None of the selected payment statuses could be deleted. System defaults and statuses used by income records cannot be deleted.",
    "deleting_records" => "Deleting payment statuses...",
    "failed_to_delete_records" => "Failed to delete payment statuses",
    "error_occurred_deleting_records" => "An error occurred while deleting the payment statuses. Please try again.",
    "invalid_json_input" => "Invalid JSON input",
    "invalid_ids" => "Invalid payment status IDs",
    "records_not_found" => "One or more payment statuses not found"
];
