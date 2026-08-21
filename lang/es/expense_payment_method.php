<?php
/**
 * Spanish Translations - Expense Payment Methods
 */

return [
    // Page titles
    "expense_payment_methods" => "Métodos de Pago de Gastos",
    "add_payment_method" => "Agregar Método de Pago",
    "edit_payment_method" => "Editar Método de Pago",
    "delete_payment_method" => "Eliminar Método de Pago",
    "view_all_payment_methods" => "Ver Todos los Métodos de Pago",
    
    // Form fields
    "name" => "Nombre del Método de Pago",
    "payment_method" => "Método de Pago",
    "description" => "Descripción",
    "status" => "Estado",
    "usage_count" => "Número de Usos",
    "created_at" => "Creado el",
    "updated_at" => "Actualizado el",
    
    // Form placeholders and help text
    "enter_name" => "Ingrese el nombre del método de pago",
    "enter_description" => "Ingrese la descripción del método de pago",
    "name_help" => "Ingrese un nombre único para el método de pago (máximo 100 caracteres)",
    "description_help" => "Descripción opcional para el método de pago (máximo 500 caracteres)",
    
    // Actions
    "add_payment_method_record" => "Agregar Método de Pago",
    "update_payment_method_record" => "Actualizar Método de Pago",
    "adding" => "Agregando",
    "updating" => "Actualizando",
    "reset" => "Restablecer",
    "confirm_reset" => "¿Está seguro de que desea restablecer el formulario?",
    
    // Messages
    "add_success" => "Método de pago agregado exitosamente",
    "update_success" => "Método de pago actualizado exitosamente",
    "delete_success" => "Método de pago eliminado exitosamente",
    "delete_error" => "Error al eliminar el método de pago",
    "delete_cancelled" => "Eliminación del método de pago cancelada",
    "load_error" => "Error al cargar los métodos de pago",
    "database_error" => "Ocurrió un error en la base de datos",
    
    // Validation messages
    "name_required" => "El nombre del método de pago es obligatorio",
    "name_too_long" => "El nombre del método de pago es muy largo (máximo 100 caracteres)",
    "name_exists" => "Ya existe un método de pago con este nombre",
    "invalid_token" => "Token de seguridad inválido",
    
    // List page
    "payment_methods_list" => "Lista de Métodos de Pago",
    "no_payment_methods_found" => "No se encontraron métodos de pago",
    "no_payment_methods_message" => "Aún no se han creado métodos de pago. Agregue su primer método de pago para comenzar.",
    "add_first_payment_method" => "Agregar Primer Método de Pago",
    "search_placeholder" => "Buscar métodos de pago...",
    
    // Delete confirmation
    "confirm_deletion" => "Confirmar Eliminación",
    "confirm_delete" => "¿Está seguro de que desea eliminar este método de pago?",
    "confirm_delete_warning" => "Entiendo que esta acción no se puede deshacer y eliminará permanentemente este método de pago.",
    "payment_method_details_to_delete" => "Detalles del Método de Pago",
    "back_to_payment_methods" => "Volver a Métodos de Pago",
    "warning" => "Advertencia",
    "cannot_delete_in_use" => "Este método de pago no se puede eliminar porque está siendo utilizado por uno o más registros de gastos.",
    
    // Management
    "manage_payment_methods" => "Gestionar Métodos de Pago de Gastos",
    
    // Additional keys for new design
    "search_results_for" => "Resultados de búsqueda para",
    "expense_records" => "registros de gastos",
    "delete_warning_message" => "Esta acción no se puede deshacer.",
    "income_records" => "registros de ingresos",
    
    // AJAX deletion
    "invalid_payment_method_id" => "ID de método de pago inválido",
    "payment_method_not_found" => "Método de pago no encontrado",
    
    // Default system payment methods (cannot be edited)
    "name_locked" => "El nombre de este método de pago no se puede cambiar porque es un valor predeterminado del sistema",
    "system_default" => "Predeterminado del Sistema",
    
    // Default payment method names (system defaults)
    "Cash" => "Efectivo",
    "Credit Card" => "Tarjeta de Crédito",
    "Debit Card" => "Tarjeta de Débito",
    "Bank Transfer" => "Transferencia Bancaria",
    "Check" => "Cheque",
    "Online Payment" => "Pago en Línea",

    // Bulk delete
    "please_select_one_record" => "Por favor seleccione al menos un método de pago para eliminar.",
    "confirm_delete_single" => "¿Está seguro de que desea eliminar este método de pago? Esta acción no se puede deshacer.",
    "confirm_delete_multiple" => "¿Está seguro de que desea eliminar estos {count} métodos de pago? Esta acción no se puede deshacer.",
    "bulk_delete_success" => "¡{count} métodos de pago eliminados correctamente!",
    "bulk_delete_partial" => "{deleted} métodos de pago eliminados. {skipped} no se pudieron eliminar porque son predeterminados del sistema o se usan en registros de gastos.",
    "bulk_delete_none" => "Ninguno de los métodos de pago seleccionados se pudo eliminar. Los predeterminados del sistema y los que se usan en gastos no se pueden eliminar.",
    "deleting_records" => "Eliminando métodos de pago...",
    "failed_to_delete_records" => "Error al eliminar los métodos de pago",
    "error_occurred_deleting_records" => "Ocurrió un error al eliminar los métodos de pago. Por favor, inténtelo de nuevo.",
    "invalid_json_input" => "Entrada JSON inválida",
    "invalid_ids" => "IDs de método de pago inválidos",
    "records_not_found" => "Uno o más métodos de pago no se encontraron"
];
