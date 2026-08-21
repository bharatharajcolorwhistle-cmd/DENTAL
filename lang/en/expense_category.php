<?php
/**
 * English Translations - Expense Category Module
 */

return [
    // Form Labels
    "add_category" => "Add Expense Category",
    "back_to_categories" => "Back to Expense Categories",
    "category_name" => "Category Name",
    "enter_name" => "Enter category name",
    "name_help" => "Unique name for the expense category",
    "enter_description" => "Enter category description",
    "description_help" => "Optional description for the category",
    "color" => "Color",
    "color_help" => "Color for category identification",
    "quick_colors" => "Quick Colors",
    "status_help" => "Category availability status",
    "select_status" => "Select Status",
    "reset_form" => "Reset Form",
    "reset" => "Reset",
    "save_category" => "Save Category",
    "add_category_record" => "Add Category Record",
    "update_category_record" => "Update Category Record",
    "view_all_categories" => "View All Categories",
    
    // Messages
    "add_success" => "Expense category added successfully",
    "invalid_color" => "Invalid color format. Please use a valid hex color code.",
    "name_exists" => "A category with this name already exists",
    "duplicate_check_error" => "Database error while checking for duplicates.",
    "database_error" => "Database error",
    "confirm_reset" => "Are you sure you want to reset the form? All entered data will be lost.",
    
    // Index page
    "search_placeholder" => "Search by name or description",
    "search_results_for" => "Search results for",
    "no_categories_found" => "No expense categories found",
    "start_adding_category" => "Start by adding your first expense category.",
    "usage" => "Usage",
    "expenses" => "expenses",
    "load_error" => "Error loading expense categories. Please try again.",
    "confirm_delete" => "Are you sure you want to delete this expense category?",
    
    // Edit page
    "edit_category" => "Edit Expense Category",
    "update_category" => "Update Category",
    "category_updated_successfully" => "Expense category updated successfully!",
    "category_updated" => "Expense category updated",
    "invalid_category_id" => "Invalid category ID",
    "category_not_found" => "Category not found",
    "error_loading_category" => "Error loading category",
    "name_required" => "Category name is required",
    "name_too_long" => "Category name must be 100 characters or less",
    "invalid_status" => "Invalid status selected",
    "name_already_exists" => "A category with this name already exists",
    "error_checking_name" => "Error checking category name",
    "error_updating_category" => "Error updating category",
    "invalid_token" => "Invalid security token",
    
    // Delete page
    "delete_category" => "Delete Expense Category",
    "delete_warning" => "Warning!",
    "delete_confirmation_message" => "Are you sure you want to delete this expense category? This action cannot be undone.",
    "category_information" => "Category Information",
    "category_in_use" => "Category in Use",
    "category_used_by_expenses" => "This category is being used by {count} expense(s).",
    "expenses_will_be_uncategorized" => "These expenses will be uncategorized after deletion.",
    "category_deleted_successfully" => "Expense category deleted successfully!",
    "category_deleted" => "Expense category deleted",
    "error_checking_usage" => "Error checking category usage",
    "error_deleting_category" => "Error deleting category",
    "cancel" => "Cancel",
    "confirm_delete" => "Are you sure you want to delete this expense category?",
    
    // Sub-category specific
    "parent_category" => "Parent Category",
    "select_parent_category" => "Select Parent Category (Optional)",
    "parent_category_help" => "Choose a parent category to create a sub-category",
    "no_parent" => "No Parent (Main Category)",
    "sub_categories" => "Sub-categories",
    "main_categories" => "Main Categories",
    "category_hierarchy" => "Category Hierarchy",
    "level" => "Level",
    "parent" => "Parent",
    "children" => "Children",
    "cannot_delete_with_children" => "Cannot delete category because it has sub-categories. Please delete sub-categories first.",
    "cannot_delete_used_category" => "Cannot delete category because it is being used by expenses. Please remove or reassign expenses first.",
    "category_locked" => "This category is locked because it is being used by expenses",
    "locked" => "Locked",
    "sub_category_added" => "Sub-category added successfully",
    "sub_category_updated" => "Sub-category updated successfully",
    "sub_category_deleted" => "Sub-category deleted successfully",
    "invalid_parent_category" => "Please select a valid main category as the parent.",
    "parent_locked_has_children" => "This category has sub-categories and must remain a main category.",
    
    // Search and Filter
    "search_and_filter" => "Search and Filter",
    
    // Table Headers
    "expense_categories" => "Expense Categories",
    
    // Status options
    "status" => "Status",
    "active" => "Active",
    "inactive" => "Inactive",
    
    // Default system expense categories (cannot be edited)
    "name_locked" => "This expense category name cannot be changed because it is a system default",
    "system_default" => "System Default",
    
    // Default expense category names (system defaults)
    "Office Supplies" => "Office Supplies",
    "Equipment" => "Equipment",
    "Utilities" => "Utilities",
    "Rent" => "Rent",
    "Marketing" => "Marketing",
    "Insurance" => "Insurance",
    "Training" => "Training",
    "Other" => "Other",

    // Bulk delete
    "please_select_one_record" => "Please select at least one expense category to delete.",
    "confirm_delete_single" => "Are you sure you want to delete this expense category? This action cannot be undone.",
    "confirm_delete_multiple" => "Are you sure you want to delete these {count} expense categories? This action cannot be undone.",
    "bulk_delete_success" => "{count} expense categories deleted successfully!",
    "bulk_delete_partial" => "{deleted} expense categories deleted. {skipped} could not be deleted because they are system defaults, have sub-categories, or are used by expenses.",
    "bulk_delete_none" => "None of the selected categories could be deleted. System defaults, categories with expenses, and categories with sub-categories cannot be deleted.",
    "deleting_records" => "Deleting expense categories...",
    "failed_to_delete_records" => "Failed to delete expense categories",
    "error_occurred_deleting_records" => "An error occurred while deleting the expense categories. Please try again.",
    "invalid_json_input" => "Invalid JSON input",
    "invalid_ids" => "Invalid expense category IDs",
    "records_not_found" => "One or more expense categories not found"
];
