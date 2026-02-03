<?php
/**
 * Spanish Translations - Inventory Category Module
 */

 return [
    // Form Labels
    "add_category" => "Agregar categoría de inventario",
    "back_to_categories" => "Volver a las categorías de inventario",
    "category_name" => "Nombre de la categoría",
    "enter_name" => "Ingrese el nombre de la categoría",
    "name_help" => "Nombre único para la categoría de inventario",
    "enter_description" => "Ingrese la descripción de la categoría",
    "description" => "Descripción",
    "description_help" => "Descripción opcional para la categoría",
    "color" => "Color",
    "color_help" => "Color para la identificación de categorías",
    "quick_colors" => "Colores rápidos",
    "status_help" => "Estado de disponibilidad de la categoría",
    "select_status" => "Seleccionar estado",
    "product_type" => "Tipo de Producto",
    "product_type_help" => "Seleccione si los productos en esta categoría son para venta o para uso interno",
    "select_product_type" => "Seleccionar Tipo de Producto",
    "for_sale" => "Para Venta",
    "for_use" => "Para Uso",
    "reset_form" => "Restablecer formulario",
    "reset" => "Reiniciar",
    "save_category" => "Guardar categoría",
    "add_category_record" => "Agregar Registro de Categoría",
    "update_category_record" => "Actualizar Registro de Categoría",
    "view_all_categories" => "Ver Todas las Categorías",
    
    // Messages
    "add_success" => "Categoría de inventario agregada correctamente",
    "invalid_color" => "Formato de color no válido. Utilice un código de color hexadecimal válido.",
    "name_exists" => "Ya existe una categoría con este nombre.",
    "duplicate_check_error" => "Error en la base de datos al verificar duplicados.",
    "database_error" => "Error en la base de datos",
    "confirm_reset" => "¿Está seguro de que desea restablecer el formulario? Se perderán todos los datos ingresados.",
    
    // Index page
    "search_placeholder" => "Buscar por nombre o descripción",
    "search_results_for" => "Resultados de la búsqueda para",
    "no_categories_found" => "No se han encontrado categorías de inventario.",
    "start_adding_category" => "Comience agregando su primera categoría de inventario.",
    "usage" => "Uso",
    "items" => "artículos",
    "load_error" => "Error al cargar las categorías de inventario. Inténtalo de nuevo.",
    "confirm_delete" => "¿Estás seguro de que deseas eliminar esta categoría de inventario?",
    
    // Edit page
    "edit_category" => "Editar categoría de inventario",
    "update_category" => "Actualizar categoría",
    "category_updated_successfully" => "¡Categoría de inventario actualizada correctamente!",
    "category_updated" => "Categoría de inventario actualizada",
    "invalid_category_id" => "ID de categoría no válido",
    "category_not_found" => "Categoría no encontrada",
    "error_loading_category" => "Error al cargar la categoría",
    "name_required" => "Se requiere el nombre de la categoría.",
    "name_too_long" => "El nombre de la categoría debe tener 100 caracteres o menos.",
    "invalid_status" => "Estado no válido seleccionado",
    "invalid_product_type" => "Tipo de producto no válido seleccionado",
    "name_already_exists" => "Ya existe una categoría con este nombre.",
    "error_checking_name" => "Error al verificar el nombre de la categoría",
    "error_updating_category" => "Error al actualizar la categoría",
    "invalid_token" => "Token de seguridad no válido",
    
    // Delete page
    "delete_category" => "Eliminar categoría de inventario",
    "delete_warning" => "¡Atención!",
    "delete_confirmation_message" => "¿Está seguro de que desea eliminar esta categoría de inventario? Esta acción no se puede deshacer.",
    "category_information" => "Información sobre la categoría",
    "category_in_use" => "Categoría en uso",
    "category_used_by_inventory" => "Esta categoría está siendo utilizada por {count} artículos de inventario.",
    "inventory_will_be_uncategorized" => "Estos artículos del inventario quedarán sin categorizar tras su eliminación.",
    "category_deleted_successfully" => "¡Categoría de inventario eliminada correctamente!",
    "category_deleted" => "Categoría de inventario eliminada",
    "error_checking_usage" => "Error al verificar el uso de la categoría",
    "error_deleting_category" => "Error al eliminar categoría",
    "cancel" => "Cancelar",
    "confirm_delete" => "¿Estás seguro de que deseas eliminar esta categoría de inventario?",
    
    // Search and Filter
    "search_and_filter" => "Buscar y Filtrar",
    
    // Table Headers
    "inventory_categories" => "Categorías de Inventario",

    // Status
    "status" => "Estado",
    "active" => "Activo",
    "inactive" => "Inactivo",
    
    // Default system inventory categories (cannot be edited)
    "name_locked" => "El nombre de esta categoría de inventario no se puede cambiar porque es un valor predeterminado del sistema",
    "system_default" => "Predeterminado del Sistema",
    
    // Default inventory category names (system defaults)
    "Dental Materials" => "Materiales Dentales",
    "Instruments" => "Instrumentos",
    "Medications" => "Medicamentos",
    "Disposables" => "Desechables",
    "Equipment" => "Equipamiento",
    "Cleaning Supplies" => "Suministros de Limpieza",
    "Office Supplies" => "Suministros de Oficina",
    "Other" => "Otros"
];
