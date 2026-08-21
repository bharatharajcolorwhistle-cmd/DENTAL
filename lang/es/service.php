<?php
/**
 * Spanish Translations - Services
 */

return [
    // Service Management
    "services" => "Servicios",
    "service" => "Servicio",
    "add_service" => "Agregar Servicio",
    "edit_service" => "Editar Servicio",
    "view_all_services" => "Ver Todos los Servicios",
    "all_status" => "Todos los Estados",
    "service_name" => "Nombre del Servicio",
    "base_price" => "Precio Base",
    "service_details" => "Detalles del Servicio",
    
    // Form Fields
    "enter_name" => "Ingrese el nombre del servicio",
    "enter_description" => "Ingrese la descripción del servicio",
    "select_status" => "Seleccionar Estado",
    "select_service" => "Seleccionar Servicio",
    
    // Help Text
    "name_help" => "Ingrese el nombre del servicio (ej: Endodoncia, Limpieza Dental)",
    "price_help" => "Precio predeterminado para este servicio (se puede personalizar por doctor)",
    "description_help" => "Descripción opcional del servicio",
    
    // Statistics
    "doctors_assigned" => "Medicos Asignados",
    "times_used" => "Veces Usado",
    "doctors" => "Medicos",
    "times" => "Veces",
    
    // Messages
    "add_success" => "Servicio agregado exitosamente",
    "add_failed" => "Error al agregar servicio",
    "update_success" => "Servicio actualizado exitosamente",
    "update_failed" => "Error al actualizar servicio",
    "delete_success" => "Servicio eliminado exitosamente",
    "delete_failed" => "Error al eliminar servicio",
    "delete_failed_in_use" => "No se puede eliminar el servicio porque está en uso",
    "cannot_delete_used_service" => "No se puede eliminar el servicio que está siendo usado",
    "confirm_delete" => "¿Está seguro de que desea eliminar este servicio",
    "delete_service" => "Eliminar Servicio",
    "delete_warning" => "Advertencia: ¡Esta acción no se puede deshacer!",
    "delete_confirmation_message" => "¿Está seguro de que desea eliminar este servicio? Esta acción no se puede deshacer.",
    "service_information" => "Información del Servicio",
    "service_deleted_successfully" => "Servicio eliminado exitosamente",
    "showing" => "Mostrando",
    "records" => "registros",
    "services_pagination" => "Paginación de servicios",
    "name_exists" => "Ya existe un servicio con este nombre",
    "price_negative" => "El precio base no puede ser negativo",
    "invalid_service_id" => "ID de servicio inválido",
    "service_not_found" => "Servicio no encontrado",
    "database_error" => "Ocurrió un error en la base de datos",
    "confirm_reset" => "¿Está seguro de que desea restablecer el formulario? Se perderán todos los datos ingresados.",
    
    // List
    "no_services_found" => "No se encontraron servicios",
    "start_adding_service" => "Comience agregando su primer servicio",
    "search_placeholder" => "Buscar por nombre o descripción",
    "add_service_record" => "Agregar Servicio",
    "update_service_record" => "Actualizar Servicio",
    
    // Doctor Services
    "manage_services" => "Gestionar Servicios",
    "doctor_services" => "Servicios del Doctor",
    "assign_services" => "Asignar Servicios al Doctor",
    "service_price" => "Precio del Servicio",
    "custom_price" => "Precio Personalizado",
    "services_assigned" => "Servicios Asignados",
    "no_services_assigned" => "No hay servicios asignados a este doctor",
    "assign_service_help" => "Seleccione los servicios que este doctor proporciona y establezca precios personalizados",
    "save_services" => "Guardar Servicios",
    "services_updated_success" => "Servicios del doctor actualizados exitosamente",
    "services_updated_failed" => "Error al actualizar servicios del doctor",
    "service" => "Servicio",
    "optional_service_help" => "Seleccione un servicio para auto-completar el monto del servicio (opcional)",
    "service_amount" => "Monto del Servicio",
    "service_amount_help" => "Monto para el servicio seleccionado (editable)",
    
    // View page
    "back_to_services" => "Volver a Todos los Servicios",
    "service_statistics" => "Estadísticas del Servicio",
    "total_revenue" => "Ingresos Totales",
    "average_price" => "Precio Promedio",
    "no_usage_data" => "Sin datos de uso",
    "assigned_doctors" => "Médicos Asignados",
    "no_doctors_assigned" => "No hay Médicos Asignados",
    "no_doctors_assigned_message" => "Este servicio no está asignado a ningún médico aún.",
    "recent_usage" => "Uso Reciente",
    "not_specified" => "No especificado",

    // Bulk delete
    "please_select_one_record" => "Por favor seleccione al menos un servicio para eliminar.",
    "confirm_delete_single" => "¿Está seguro de que desea eliminar este servicio? Esta acción no se puede deshacer.",
    "confirm_delete_multiple" => "¿Está seguro de que desea eliminar estos {count} servicios? Esta acción no se puede deshacer.",
    "bulk_delete_success" => "¡{count} servicios eliminados correctamente!",
    "bulk_delete_partial" => "{deleted} servicios eliminados. {skipped} no se pudieron eliminar porque están asignados a médicos o se usan en registros de ingresos.",
    "deleting_records" => "Eliminando servicios...",
    "failed_to_delete_records" => "Error al eliminar los servicios",
    "error_occurred_deleting_records" => "Ocurrió un error al eliminar los servicios. Por favor, inténtelo de nuevo.",
    "invalid_json_input" => "Entrada JSON inválida",
    "invalid_ids" => "IDs de servicio inválidos",
    "records_not_found" => "Uno o más servicios no se encontraron"
];
?>
