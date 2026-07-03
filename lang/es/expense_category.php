<?php
/**
 * Spanish Translations - Expense Category Module
 */

return [
    // Form Labels
    "add_category" => "Agregar categoría de gasto",
    "back_to_categories" => "Volver a las categorías de gastos",
    "category_name" => "Nombre de la categoría",
    "enter_name" => "Ingrese el nombre de la categoría",
    "name_help" => "Nombre único para la categoría de gastos",
    "enter_description" => "Ingrese la descripción de la categoría",
    "description_help" => "Descripción opcional para la categoría",
    "color" => "Color",
    "color_help" => "Color para la identificación de categorías",
    "quick_colors" => "Colores rápidos",
    "status_help" => "Estado de disponibilidad de la categoría",
    "select_status" => "Seleccionar estado",
    "reset_form" => "Restablecer formulario",
    "reset" => "Reiniciar",
    "save_category" => "Guardar categoría",
    "add_category_record" => "Agregar Registro de Categoría",
    "update_category_record" => "Actualizar Registro de Categoría",
    "view_all_categories" => "Ver Todas las Categorías",
    
    // Messages
    "add_success" => "Categoría de gastos agregada correctamente",
    "invalid_color" => "Formato de color no válido. Utilice un código de color hexadecimal válido.",
    "name_exists" => "Ya existe una categoría con este nombre.",
    "duplicate_check_error" => "Error en la base de datos al verificar duplicados.",
    "database_error" => "Error en la base de datos",
    "confirm_reset" => "¿Está seguro de que desea restablecer el formulario? Se perderán todos los datos ingresados.",
    
    // Index page
    "search_placeholder" => "Buscar por nombre o descripción",
    "search_results_for" => "Resultados de la búsqueda para",
    "no_categories_found" => "No se han encontrado categorías de gastos.",
    "start_adding_category" => "Comience por agregar su primera categoría de gastos.",
    "usage" => "Uso",
    "expenses" => "gastos",
    "load_error" => "Error al cargar las categorías de gastos. Inténtalo de nuevo.",
    "confirm_delete" => "¿Estás seguro de que deseas eliminar esta categoría de gastos?",
    
    // Edit page
    "edit_category" => "Editar categoría de gastos",
    "update_category" => "Actualizar categoría",
    "category_updated_successfully" => "¡Categoría de gastos actualizada correctamente!",
    "category_updated" => "Categoría de gastos actualizada",
    "invalid_category_id" => "ID de categoría no válido",
    "category_not_found" => "Categoría no encontrada",
    "error_loading_category" => "Error al cargar la categoría",
    "name_required" => "Se requiere el nombre de la categoría.",
    "name_too_long" => "El nombre de la categoría debe tener 100 caracteres o menos.",
    "invalid_status" => "Estado no válido seleccionado",
    "name_already_exists" => "Ya existe una categoría con este nombre.",
    "error_checking_name" => "Error al verificar el nombre de la categoría",
    "error_updating_category" => "Error al actualizar la categoría",
    "invalid_token" => "Token de seguridad no válido",
    
    // Delete page
    "delete_category" => "Eliminar categoría de gastos",
    "delete_warning" => "¡Atención!",
    "delete_confirmation_message" => "¿Estás seguro de que deseas eliminar esta categoría de gastos? Esta acción no se puede deshacer.",
    "category_information" => "Category Information",
    "category_in_use" => "Categoría en uso",
    "category_used_by_expenses" => "Esta categoría está siendo utilizada por {count} gastos.",
    "expenses_will_be_uncategorized" => "Estos gastos quedarán sin categorizar tras su eliminación.",
    "category_deleted_successfully" => "¡Categoría de gastos eliminada correctamente!",
    "category_deleted" => "Categoría de gastos eliminada",
    "error_checking_usage" => "Error al verificar el uso de la categoría",
    "error_deleting_category" => "Error al eliminar categoría",
    "cancel" => "Cancelar",
    "confirm_delete" => "¿Estás seguro de que deseas eliminar esta categoría de gastos?",
    
    // Sub-category specific
    "parent_category" => "Categoría Principal",
    "select_parent_category" => "Seleccionar Categoría Principal (Opcional)",
    "parent_category_help" => "Elija una categoría principal para crear una sub-categoría",
    "no_parent" => "Sin Principal (Categoría Principal)",
    "sub_categories" => "Sub-categorías",
    "main_categories" => "Categorías Principales",
    "category_hierarchy" => "Jerarquía de Categorías",
    "level" => "Nivel",
    "parent" => "Principal",
    "children" => "Hijos",
    "cannot_delete_with_children" => "No se puede eliminar la categoría porque tiene sub-categorías. Elimine primero las sub-categorías.",
    "cannot_delete_used_category" => "No se puede eliminar la categoría porque está siendo utilizada por gastos. Elimine o reasigne los gastos primero.",
    "category_locked" => "Esta categoría está bloqueada porque está siendo utilizada por gastos",
    "locked" => "Bloqueada",
    "sub_category_added" => "Sub-categoría agregada correctamente",
    "sub_category_updated" => "Sub-categoría actualizada correctamente",
    "sub_category_deleted" => "Sub-categoría eliminada correctamente",
    "invalid_parent_category" => "Seleccione una categoría principal válida como padre.",
    "parent_locked_has_children" => "Esta categoría tiene sub-categorías y debe permanecer como categoría principal.",
    
    // Search and Filter
    "search_and_filter" => "Buscar y Filtrar",
    
    // Table Headers
    "expense_categories" => "Categorías de Gastos",
    
    // Status options
    "status" => "Estado",
    "active" => "Activo",
    "inactive" => "Inactivo",
    
    // Default system expense categories (cannot be edited)
    "name_locked" => "El nombre de esta categoría de gastos no se puede cambiar porque es un valor predeterminado del sistema",
    "system_default" => "Predeterminado del Sistema",
    
    // Default expense category names (system defaults)
    "Office Supplies" => "Suministros de Oficina",
    "Equipment" => "Equipamiento",
    "Utilities" => "Servicios Públicos",
    "Rent" => "Alquiler",
    "Marketing" => "Marketing",
    "Insurance" => "Seguros",
    "Training" => "Capacitación",
    "Other" => "Otros"
];
