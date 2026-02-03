<?php
/**
 * English Translations - Doctor Module
 */

return [
    // Form Labels
    "add_doctor" => "Add Doctor",
    "back_to_doctors" => "Back to Doctors",
    "doctor_name" => "Doctor Name",
    "enter_name" => "Enter doctor's full name",
    "name_help" => "Full name of the doctor",
    "specialization" => "Specialization",
    "select_specialization" => "Select Specialization",
    "specialization_help" => "Area of dental specialization (optional)",
    "qualification" => "Qualification",
    "qualification_placeholder" => "e.g., BDS, MDS, PhD",
    "qualification_help" => "Educational qualifications (optional)",
    "select_status" => "Select Status",
    "enter_phone" => "Enter phone number",
    "phone_help" => "Contact phone number (optional)",
    "enter_email" => "Enter email address",
    "email_help" => "Email address (optional)",
    "consultation_fee" => "Consultation Fee",
    "fee_help" => "Standard consultation fee",
    "enter_address" => "Enter address",
    "address_help" => "Office or clinic address (optional)",
    "enter_notes" => "Additional notes about the doctor",
    "notes_help" => "Any additional information (optional)",
    "reset_form" => "Reset Form",
    "reset" => "Reset",
    "save_doctor" => "Save Doctor",
    "add_doctor_record" => "Add Doctor Record",
    "update_doctor_record" => "Update Doctor Record",
    "view_all_doctors" => "View All Doctors",
    
    // Messages
    "add_success" => "Doctor added successfully!",
    "fee_negative" => "Consultation fee cannot be negative.",
    "invalid_email" => "Invalid email address. Please enter a valid email.",
    "database_error" => "Database error",
    "confirm_reset" => "Are you sure you want to reset the form? All entered data will be lost.",
    
    // Index page
    "doctor_management" => "Doctor Management",
    "total_doctors" => "Total Doctors",
    "active_doctors" => "Active Doctors",
    "total_consultation_fees" => "Total Consultation Fees",
    "search_placeholder" => "Name, specialization, phone, or email",
    "all_status" => "All Status",
    "on_leave" => "On Leave",
    
    // Edit page
    "edit_doctor" => "Edit Doctor",
    "update_success" => "Doctor updated successfully!",
    "invalid_doctor_id" => "Invalid doctor ID",
    "doctor_not_found" => "Doctor not found",
    
    // Delete page
    "delete_doctor" => "Delete Doctor",
    "back_to_doctor" => "Back to Doctor",
    "delete_confirmation_required" => "Delete Confirmation Required",
    "delete_warning_message" => "You are about to delete a doctor. This action cannot be undone and will permanently remove all data associated with this doctor.",
    "delete_review_message" => "Please review the doctor details below and confirm that you want to proceed with the deletion.",
    "delete_success" => "Doctor deleted successfully!",
    "delete_failed" => "Failed to delete doctor. They may have already been deleted.",
    "delete_cancelled" => "Doctor deletion cancelled.",
    "no_delete_permission" => "You don't have permission to delete this doctor.",
    "cannot_delete_in_use" => "Cannot delete this doctor. They are associated with income records.",
    "cannot_delete_with_income" => "Cannot delete doctor with income records",
    "income_records" => "income records",
    "usage_check_error" => "Error checking doctor usage.",
    
    // View page
    "view_doctor" => "View Doctor",
    "doctor_details" => "Doctor Details",
    "not_specified" => "Not specified",
    
    // Export page
    "export_csv" => "Export CSV",
    
    // Additional keys for complete translation
    "no_doctors_found" => "No doctors found",
    "no_doctors_message" => "Try adjusting your search criteria or add a new doctor.",
    "confirm_delete" => "Are you sure you want to delete this doctor? This action cannot be undone.",
    "consultation_information" => "Consultation Information",
    "total_consultations" => "Total Consultations",
    "total_earnings" => "Total Earnings",
    "average_earnings" => "Average Earnings per Consultation",
    "average_earnings_per_consultation" => "Average Earnings per Consultation",
    "no_consultations" => "No consultations",
    "additional_information" => "Additional Information",
    "doctor_id" => "Doctor ID",
    "quick_actions" => "Quick Actions",
    "view_all_doctors" => "View All Doctors",
    "status_information" => "Status Information",
    "active_doctor" => "Active Doctor",
    "active_doctor_message" => "This doctor is currently active and available for consultations",
    "inactive_doctor" => "Inactive Doctor",
    "inactive_doctor_message" => "This doctor is currently inactive and not available for consultations",
    "on_leave_doctor" => "On Leave",
    "on_leave_doctor_message" => "This doctor is currently on leave and not available for consultations",
    "reset_to_original" => "Reset to Original",
    "update_doctor" => "Update Doctor",
    "confirm_reset_original" => "Are you sure you want to reset the form to the original values? All changes will be lost.",
    "doctor_details_to_delete" => "Doctor Details to be Deleted",
    "confirm_deletion" => "Confirm Deletion",
    "confirm_delete_question" => "Are you sure you want to delete this doctor?",
    "yes_delete_permanently" => "Yes, delete this doctor permanently",
    "no_cancel_deletion" => "No, cancel deletion",
    "safety_check" => "Safety Check",
    "deletion_blocked" => "Deletion Blocked",
    "safe_to_delete" => "Safe to Delete",
    "safe_to_delete_message" => "This doctor is not associated with any income records and can be safely removed",
    "cannot_delete" => "Cannot Delete",
    "cannot_delete_message" => "This doctor is associated with {count} income record(s) and cannot be deleted. You must first remove or reassign these income records before deleting the doctor.",
    "view_associated_income" => "View Associated Income Records",
    "select_confirmation_option" => "Please select a confirmation option.",
    "absolutely_sure_delete" => "Are you absolutely sure you want to delete this doctor? This action cannot be undone.",
    
    // Additional UI elements
    "summary_cards" => "Summary Cards",
    "search_and_filter_form" => "Search and Filter Form",
    "export_button" => "Export Button",
    "doctors_table" => "Doctors Table",
    "doctors_pagination" => "Doctors pagination",
    "get_current_search_parameters" => "Get current search parameters",
    "create_export_url" => "Create export URL",
    "download_file" => "Download the file",
    
    // Search and Filter
    "search_and_filter" => "Search and Filter",
    
    // Table Headers
    "doctors_records" => "Doctors Records",
    
    // Default Doctor functionality
    "default_doctor" => "Default Doctor (Crown)",
    "set_as_default" => "Set as Default Doctor",
    "confirm_set_default" => "Are you sure you want to set",
    "set_default_error" => "Failed to set default doctor. Please try again.",
];
