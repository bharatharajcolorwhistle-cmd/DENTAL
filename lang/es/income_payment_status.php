<?php
/**
 * Spanish Translations - Income Payment Status
 */

return [
    // Page titles
    "income_payment_statuses" => "Estados de Pago de Ingresos",
    "add_payment_status" => "Agregar Estado de Pago",
    "edit_payment_status" => "Editar Estado de Pago",
    "delete_payment_status" => "Eliminar Estado de Pago",
    "view_all_payment_statuses" => "Ver Todos los Estados de Pago",
    
    // Form fields
    "name" => "Nombre del Estado de Pago",
    "payment_status" => "Estado de Pago",
    "description" => "Descripción",
    "color" => "Color",
    "status" => "Estado",
    "usage_count" => "Número de Usos",
    "created_at" => "Creado el",
    "updated_at" => "Actualizado el",
    
    // Form placeholders and help text
    "enter_name" => "Ingrese el nombre del estado de pago",
    "enter_description" => "Ingrese la descripción del estado de pago",
    "choose_color" => "Elija un color para este estado de pago",
    "name_help" => "Ingrese un nombre único para el estado de pago (máximo 100 caracteres)",
    "description_help" => "Descripción opcional para el estado de pago (máximo 500 caracteres)",
    "color_help" => "Elija un color para representar este estado de pago",
    
    // Actions
    "add_payment_status_record" => "Agregar Estado de Pago",
    "update_payment_status_record" => "Actualizar Estado de Pago",
    "adding" => "Agregando",
    "updating" => "Actualizando",
    "reset" => "Restablecer",
    "confirm_reset" => "¿Está seguro de que desea restablecer el formulario?",
    
    // Messages
    "add_success" => "Estado de pago agregado exitosamente",
    "update_success" => "Estado de pago actualizado exitosamente",
    "delete_success" => "Estado de pago eliminado exitosamente",
    "delete_error" => "Error al eliminar el estado de pago",
    "delete_cancelled" => "Eliminación del estado de pago cancelada",
    "load_error" => "Error al cargar los estados de pago",
    "database_error" => "Ocurrió un error en la base de datos",
    
    // Validation messages
    "name_required" => "El nombre del estado de pago es obligatorio",
    "name_too_long" => "El nombre del estado de pago es muy largo (máximo 100 caracteres)",
    "name_exists" => "Ya existe un estado de pago con este nombre",
    "invalid_color" => "Formato de color inválido. Por favor use un color hex válido.",
    "invalid_token" => "Token de seguridad inválido",
    
    // List page
    "payment_statuses_list" => "Lista de Estados de Pago",
    "no_payment_statuses_found" => "No se encontraron estados de pago",
    "no_payment_statuses_message" => "Aún no se han creado estados de pago. Agregue su primer estado de pago para comenzar.",
    "add_first_payment_status" => "Agregar Primer Estado de Pago",
    "search_placeholder" => "Buscar estados de pago...",
    
    // Delete confirmation
    "confirm_deletion" => "Confirmar Eliminación",
    "confirm_delete" => "¿Está seguro de que desea eliminar este estado de pago?",
    "confirm_delete_warning" => "Entiendo que esta acción no se puede deshacer y eliminará permanentemente este estado de pago.",
    "payment_status_details_to_delete" => "Detalles del Estado de Pago",
    "back_to_payment_statuses" => "Volver a Estados de Pago",
    "warning" => "Advertencia",
    "cannot_delete_in_use" => "Este estado de pago no se puede eliminar porque está siendo utilizado por uno o más registros de ingresos.",
    
    // Management
    "manage_payment_statuses" => "Gestionar Estados de Pago de Ingresos",
    
    // Additional keys for new design
    "search_results_for" => "Resultados de búsqueda para",
    "income_records" => "registros de ingresos",
    "delete_warning_message" => "Esta acción no se puede deshacer.",
    
    // AJAX deletion
    "invalid_payment_status_id" => "ID de estado de pago inválido",
    "payment_status_not_found" => "Estado de pago no encontrado",
    
    // Default system payment statuses (cannot be edited)
    "name_locked" => "El nombre de este estado de pago no se puede cambiar porque es un valor predeterminado del sistema",
    "system_default" => "Predeterminado del Sistema",
    
    // Default payment status names (system defaults)
    "Completed" => "Completado",
    "Pending" => "Pendiente",
    "Failed" => "Fallido",
    "Cancelled" => "Cancelado",
    "Refunded" => "Reembolsado",

    // Bulk delete
    "please_select_one_record" => "Por favor seleccione al menos un estado de pago para eliminar.",
    "confirm_delete_single" => "¿Está seguro de que desea eliminar este estado de pago? Esta acción no se puede deshacer.",
    "confirm_delete_multiple" => "¿Está seguro de que desea eliminar estos {count} estados de pago? Esta acción no se puede deshacer.",
    "bulk_delete_success" => "¡{count} estados de pago eliminados correctamente!",
    "bulk_delete_partial" => "{deleted} estados de pago eliminados. {skipped} no se pudieron eliminar porque son predeterminados del sistema o se usan en registros de ingresos.",
    "bulk_delete_none" => "Ninguno de los estados de pago seleccionados se pudo eliminar. Los predeterminados del sistema y los que se usan en ingresos no se pueden eliminar.",
    "deleting_records" => "Eliminando estados de pago...",
    "failed_to_delete_records" => "Error al eliminar los estados de pago",
    "error_occurred_deleting_records" => "Ocurrió un error al eliminar los estados de pago. Por favor, inténtelo de nuevo.",
    "invalid_json_input" => "Entrada JSON inválida",
    "invalid_ids" => "IDs de estado de pago inválidos",
    "records_not_found" => "Uno o más estados de pago no se encontraron"
];
