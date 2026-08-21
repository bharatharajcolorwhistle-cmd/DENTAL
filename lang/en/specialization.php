<?php
/**
 * Doctor Specializations Translation File - English
 * Dental Clinic Management System
 */

return [
    // General terms
    'specializations' => 'Specializations',
    'specialization' => 'Specialization',
    'name' => 'Name',
    'description' => 'Description',
    'doctors_count' => 'Doctors Count',
    'doctors' => 'doctors',
    
    // Actions
    'add_specialization' => 'Add Specialization',
    'edit_specialization' => 'Edit Specialization',
    'update_specialization' => 'Update Specialization',
    'view_all_specializations' => 'View All Specializations',
    'delete_specialization' => 'Delete Specialization',
    
    // Form labels and help text
    'name_help' => 'Enter a unique name for the specialization',
    'description_help' => 'Optional description for the specialization',
    'status_help' => 'Select the status for this specialization',
    'no_description' => 'No description provided',
    'enter_name' => 'Enter specialization name',
    'enter_description' => 'Enter specialization description',
    'select_status' => 'Select status',
    
    // Button text
    'reset' => 'Reset',
    'add_specialization_record' => 'Add Specialization',
    
    // Status messages
    'add_success' => 'Specialization added successfully',
    'update_success' => 'Specialization updated successfully',
    'delete_success' => 'Specialization deleted successfully',
    'add_error' => 'Failed to add specialization',
    'update_error' => 'Failed to update specialization',
    'delete_failed' => 'Failed to delete specialization',
    'database_error' => 'Database error occurred',
    
    // Validation messages
    'name_exists' => 'A specialization with this name already exists',
    'duplicate_check_error' => 'Error checking for duplicate names',
    'name_required' => 'Specialization name is required',
    'status_required' => 'Status is required',
    'invalid_id' => 'Invalid specialization ID',
    'not_found' => 'Specialization not found',
    'load_error' => 'Failed to load specializations',
    
    // Search and filtering
    'search_placeholder' => 'Search specializations by name or description',
    'no_specializations_found' => 'No specializations found',
    'try_adjusting_search' => 'Try adjusting your search criteria',
    
    // Deletion protection
    'cannot_delete_with_doctors' => 'Cannot delete specialization that is assigned to doctors',
    'locked_specialization_message' => 'This specialization is currently assigned to doctors and cannot be deleted',
    
    // Information and notes
    'information' => 'Information',
    'note' => 'Note',
    'add_note' => 'Specializations help categorize doctors by their medical expertise.',
    'edit_note' => 'Update the specialization details below.',
    'required_fields' => 'Required Fields',
    'optional_fields' => 'Optional Fields',
    'specialization_details' => 'Specialization Details',
    
    // Confirmation messages
    'confirm_delete' => 'Are you sure you want to delete this specialization?',
    'confirm_reset' => 'Are you sure you want to reset the form? This will lose all unsaved changes.',
    
    // AJAX messages
    'unknown_error' => 'An unknown error occurred',
    'error_occurred_deleting' => 'An error occurred while deleting the specialization',

    // Bulk delete
    'please_select_one_record' => 'Please select at least one specialization to delete.',
    'confirm_delete_single' => 'Are you sure you want to delete this specialization? This action cannot be undone.',
    'confirm_delete_multiple' => 'Are you sure you want to delete these {count} specializations? This action cannot be undone.',
    'bulk_delete_success' => '{count} specializations deleted successfully!',
    'bulk_delete_partial' => '{deleted} specializations deleted. {skipped} could not be deleted because they are assigned to doctors.',
    'deleting_records' => 'Deleting specializations...',
    'failed_to_delete_records' => 'Failed to delete specializations',
    'error_occurred_deleting_records' => 'An error occurred while deleting the specializations. Please try again.',
    'invalid_json_input' => 'Invalid JSON input',
    'invalid_ids' => 'Invalid specialization IDs',
    'records_not_found' => 'One or more specializations not found',
];
?>
