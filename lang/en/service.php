<?php
/**
 * English Translations - Services
 */

return [
    // Service Management
    "services" => "Services",
    "service" => "Service",
    "add_service" => "Add Service",
    "edit_service" => "Edit Service",
    "view_all_services" => "View All Services",
    "all_status" => "All Status",
    "service_name" => "Service Name",
    "base_price" => "Base Price",
    "service_details" => "Service Details",
    
    // Form Fields
    "enter_name" => "Enter service name",
    "enter_description" => "Enter service description",
    "select_status" => "Select Status",
    "select_service" => "Select Service",
    
    // Help Text
    "name_help" => "Enter the name of the service (e.g., Root Canal, Teeth Cleaning)",
    "price_help" => "Default price for this service (can be customized per doctor)",
    "description_help" => "Optional description of the service",
    
    // Statistics
    "doctors_assigned" => "Doctors Assigned",
    "times_used" => "Times Used",
    "doctors" => "Doctors",
    "times" => "Times",
    
    // Messages
    "add_success" => "Service added successfully",
    "add_failed" => "Failed to add service",
    "update_success" => "Service updated successfully",
    "update_failed" => "Failed to update service",
    "delete_success" => "Service deleted successfully",
    "delete_failed" => "Failed to delete service",
    "delete_failed_in_use" => "Cannot delete service as it is in use",
    "cannot_delete_used_service" => "Cannot delete service that is being used",
    "confirm_delete" => "Are you sure you want to delete this service",
    "delete_service" => "Delete Service",
    "delete_warning" => "Warning: This action cannot be undone!",
    "delete_confirmation_message" => "Are you sure you want to delete this service? This action cannot be undone.",
    "service_information" => "Service Information",
    "service_deleted_successfully" => "Service deleted successfully",
    "showing" => "Showing",
    "records" => "records",
    "services_pagination" => "Services pagination",
    "name_exists" => "A service with this name already exists",
    "price_negative" => "Base price cannot be negative",
    "invalid_service_id" => "Invalid service ID",
    "service_not_found" => "Service not found",
    "database_error" => "Database error occurred",
    "confirm_reset" => "Are you sure you want to reset the form? All entered data will be lost.",
    
    // List
    "no_services_found" => "No services found",
    "start_adding_service" => "Start by adding your first service",
    "search_placeholder" => "Search by name or description",
    "add_service_record" => "Add Service",
    "update_service_record" => "Update Service",
    
    // Doctor Services
    "manage_services" => "Manage Services",
    "doctor_services" => "Doctor Services",
    "assign_services" => "Assign Services to Doctor",
    "service_price" => "Service Price",
    "custom_price" => "Custom Price",
    "services_assigned" => "Services Assigned",
    "no_services_assigned" => "No services assigned to this doctor",
    "assign_service_help" => "Select services this doctor provides and set custom prices",
    "save_services" => "Save Services",
    "services_updated_success" => "Doctor services updated successfully",
    "services_updated_failed" => "Failed to update doctor services",
    "service" => "Service",
    "optional_service_help" => "Select a service to auto-fill the service amount (optional)",
    "service_amount" => "Service Amount",
    "service_amount_help" => "Amount for the selected service (editable)",
    
    // View page
    "back_to_services" => "Back to All Services",
    "service_statistics" => "Service Statistics",
    "total_revenue" => "Total Revenue",
    "average_price" => "Average Price",
    "no_usage_data" => "No usage data",
    "assigned_doctors" => "Assigned Doctors",
    "no_doctors_assigned" => "No Doctors Assigned",
    "no_doctors_assigned_message" => "This service is not assigned to any doctors yet.",
    "recent_usage" => "Recent Usage",
    "not_specified" => "Not specified",

    // Bulk delete
    "please_select_one_record" => "Please select at least one service to delete.",
    "confirm_delete_single" => "Are you sure you want to delete this service? This action cannot be undone.",
    "confirm_delete_multiple" => "Are you sure you want to delete these {count} services? This action cannot be undone.",
    "bulk_delete_success" => "{count} services deleted successfully!",
    "bulk_delete_partial" => "{deleted} services deleted. {skipped} could not be deleted because they are assigned to doctors or used in income records.",
    "deleting_records" => "Deleting services...",
    "failed_to_delete_records" => "Failed to delete services",
    "error_occurred_deleting_records" => "An error occurred while deleting the services. Please try again.",
    "invalid_json_input" => "Invalid JSON input",
    "invalid_ids" => "Invalid service IDs",
    "records_not_found" => "One or more services not found"
];
?>
