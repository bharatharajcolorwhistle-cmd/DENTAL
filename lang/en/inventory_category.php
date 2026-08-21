<?php
/**
 * English Translations - Inventory Category Module
 */

return [
    // Form Labels
    "add_category" => "Add Inventory Category",
    "back_to_categories" => "Back to Inventory Categories",
    "category_name" => "Category Name",
    "enter_name" => "Enter category name",
    "name_help" => "Unique name for the inventory category",
    "enter_description" => "Enter category description",
    "description" => "Description",
    "description_help" => "Optional description for the category",
    "color" => "Color",
    "color_help" => "Color for category identification",
    "quick_colors" => "Quick Colors",
    "status_help" => "Category availability status",
    "select_status" => "Select Status",
    "product_type" => "Product Type",
    "product_type_help" => "Select whether products in this category are for sale or for internal use",
    "select_product_type" => "Select Product Type",
    "for_sale" => "For Sale",
    "for_use" => "For Use",
    "reset_form" => "Reset Form",
    "reset" => "Reset",
    "save_category" => "Save Category",
    "add_category_record" => "Add Category Record",
    "update_category_record" => "Update Category Record",
    "view_all_categories" => "View All Categories",
    
    // Messages
    "add_success" => "Inventory category added successfully",
    "invalid_color" => "Invalid color format. Please use a valid hex color code.",
    "name_exists" => "A category with this name already exists",
    "duplicate_check_error" => "Database error while checking for duplicates.",
    "database_error" => "Database error",
    "confirm_reset" => "Are you sure you want to reset the form? All entered data will be lost.",
    
    // Index page
    "search_placeholder" => "Search by name or description",
    "search_results_for" => "Search results for",
    "no_categories_found" => "No inventory categories found",
    "start_adding_category" => "Start by adding your first inventory category.",
    "usage" => "Usage",
    "items" => "items",
    "load_error" => "Error loading inventory categories. Please try again.",
    "confirm_delete" => "Are you sure you want to delete this inventory category?",
    
    // Edit page
    "edit_category" => "Edit Inventory Category",
    "update_category" => "Update Category",
    "category_updated_successfully" => "Inventory category updated successfully!",
    "category_updated" => "Inventory category updated",
    "invalid_category_id" => "Invalid category ID",
    "category_not_found" => "Category not found",
    "error_loading_category" => "Error loading category",
    "name_required" => "Category name is required",
    "name_too_long" => "Category name must be 100 characters or less",
    "invalid_status" => "Invalid status selected",
    "invalid_product_type" => "Invalid product type selected",
    "name_already_exists" => "A category with this name already exists",
    "error_checking_name" => "Error checking category name",
    "error_updating_category" => "Error updating category",
    "invalid_token" => "Invalid security token",
    
    // Delete page
    "delete_category" => "Delete Inventory Category",
    "delete_warning" => "Warning!",
    "delete_confirmation_message" => "Are you sure you want to delete this inventory category? This action cannot be undone.",
    "category_information" => "Category Information",
    "category_in_use" => "Category in Use",
    "category_used_by_inventory" => "This category is being used by {count} inventory item(s).",
    "inventory_will_be_uncategorized" => "These inventory items will be uncategorized after deletion.",
    "category_deleted_successfully" => "Inventory category deleted successfully!",
    "category_deleted" => "Inventory category deleted",
    "error_checking_usage" => "Error checking category usage",
    "error_deleting_category" => "Error deleting category",
    "cancel" => "Cancel",
    "confirm_delete" => "Are you sure you want to delete this inventory category?",
    
    // Search and Filter
    "search_and_filter" => "Search and Filter",
    
    // Table Headers
    "inventory_categories" => "Inventory Categories",

    // Status
    "status" => "Status",
    "active" => "Active",
    "inactive" => "Inactive",
    
    // Default system inventory categories (cannot be edited)
    "name_locked" => "This inventory category name cannot be changed because it is a system default",
    "system_default" => "System Default",
    
    // Default inventory category names (system defaults)
    "Dental Materials" => "Dental Materials",
    "Instruments" => "Instruments",
    "Medications" => "Medications",
    "Disposables" => "Disposables",
    "Equipment" => "Equipment",
    "Cleaning Supplies" => "Cleaning Supplies",
    "Office Supplies" => "Office Supplies",
    "Other" => "Other",

    "cannot_delete_used_category" => "Cannot delete category because it is being used by inventory items.",

    // Bulk delete
    "please_select_one_record" => "Please select at least one inventory category to delete.",
    "confirm_delete_single" => "Are you sure you want to delete this inventory category? This action cannot be undone.",
    "confirm_delete_multiple" => "Are you sure you want to delete these {count} inventory categories? This action cannot be undone.",
    "bulk_delete_success" => "{count} inventory categories deleted successfully!",
    "bulk_delete_partial" => "{deleted} inventory categories deleted. {skipped} could not be deleted because they are system defaults or are used by inventory items.",
    "bulk_delete_none" => "None of the selected categories could be deleted. System defaults and categories used by inventory items cannot be deleted.",
    "deleting_records" => "Deleting inventory categories...",
    "failed_to_delete_records" => "Failed to delete inventory categories",
    "error_occurred_deleting_records" => "An error occurred while deleting the inventory categories. Please try again.",
    "invalid_json_input" => "Invalid JSON input",
    "invalid_ids" => "Invalid inventory category IDs",
    "records_not_found" => "One or more inventory categories not found"
];
