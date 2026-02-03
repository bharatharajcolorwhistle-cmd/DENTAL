<?php
/**
 * Spanish Translations - Doctor Module
 */

return [
    // Form Labels
    "add_doctor" => "Agregar médico",
    "back_to_doctors" => "Volver a Médicos",
    "doctor_name" => "Nombre del médico",
    "enter_name" => "Ingrese el nombre completo del médico.",
    "name_help" => "Nombre completo del médico",
    "specialization" => "Especialización",
    "select_specialization" => "Seleccionar especialización",
    "specialization_help" => "Área de especialización dental (opcional)",
    "qualification" => "Calificación",
    "qualification_placeholder" => "Por ejemplo, BDS, MDS, PhD.",
    "qualification_help" => "Titulación académica (opcional)",
    "select_status" => "Seleccionar estado",
    "enter_phone" => "Ingrese el número de teléfono",
    "phone_help" => "Número de teléfono de contacto (opcional)",
    "enter_email" => "Ingrese su dirección de correo electrónico",
    "email_help" => "Dirección de correo electrónico (opcional)",
    "consultation_fee" => "Tarifa de consulta",
    "fee_help" => "Tarifa estándar de consulta",
    "enter_address" => "Ingrese la dirección",
    "address_help" => "Dirección de la oficina o clínica (opcional)",
    "enter_notes" => "Notas adicionales sobre el médico",
    "notes_help" => "Cualquier información adicional (opcional)",
    "reset_form" => "Restablecer formulario",
    "reset" => "Reiniciar",
    "save_doctor" => "Salvar al doctor",
    "add_doctor_record" => "Agregar Registro de Médico",
    "update_doctor_record" => "Actualizar Registro de Médico",
    "view_all_doctors" => "Ver Todos los Médicos",
    
    // Messages
    "add_success" => "¡Médico agregado con éxito!",
    "fee_negative" => "La tarifa de consulta no puede ser negativa.",
    "invalid_email" => "Dirección de correo electrónico no válida. Introduce una dirección válida.",
    "database_error" => "Error en la base de datos",
    "confirm_reset" => "¿Está seguro de que desea restablecer el formulario? Se perderán todos los datos ingresados.",
    
    // Index page
    "doctor_management" => "Gestión médica",
    "total_doctors" => "Total de médicos",
    "active_doctors" => "Médicos en activo",
    "total_consultation_fees" => "Total de honorarios por consultas",
    "search_placeholder" => "Nombre, especialización, teléfono o correo electrónico.",
    "all_status" => "Todo el estado",
    "on_leave" => "De baja",
    
    // Edit page
    "edit_doctor" => "Editar Doctor",
    "update_success" => "¡Doctor actualizado correctamente!",
    "invalid_doctor_id" => "Identificación médica no válida",
    "doctor_not_found" => "No se encontró ningún médico.",
    
    // Delete page
    "delete_doctor" => "Eliminar Doctor",
    "back_to_doctor" => "Volver al médico",
    "delete_confirmation_required" => "Se requiere confirmación para eliminar",
    "delete_warning_message" => "Está a punto de eliminar un médico. Esta acción no se puede deshacer y eliminará de forma permanente todos los datos asociados a este médico.",
    "delete_review_message" => "Revise los datos del médico que figuran a continuación y confirme que desea continuar con la eliminación.",
    "delete_success" => "¡Doctor eliminado con éxito!",
    "delete_failed" => "No se ha podido eliminar al médico. Es posible que ya haya sido eliminado.",
    "delete_cancelled" => "Eliminación del médico cancelada.",
    "no_delete_permission" => "No tienes permiso para eliminar este médico.",
    "cannot_delete_in_use" => "No se puede eliminar este médico. Está asociado con registros de ingresos.",
    "cannot_delete_with_income" => "No se puede eliminar médico con registros de ingresos",
    "income_records" => "registros de ingresos",
    "usage_check_error" => "Error al verificar el uso del médico.",
    
    // View page
    "view_doctor" => "Ver médico",
    "doctor_details" => "Detalles del médico",
    "not_specified" => "No especificado",
    
    // Export page
    "export_csv" => "Exportar CSV",
    
    // Additional keys for complete translation
    "no_doctors_found" => "No se han encontrado médicos.",
    "no_doctors_message" => "Intente ajustar sus criterios de búsqueda o agregue un nuevo médico.",
    "confirm_delete" => "¿Está seguro de que desea eliminar este médico? Esta acción no se puede deshacer.",
    "consultation_information" => "Información de consulta",
    "total_consultations" => "Total de consultas",
    "total_earnings" => "Ganancias totales",
    "average_earnings" => "Ganancias promedio por consulta",
    "average_earnings_per_consultation" => "Ganancias Promedio por Consulta",
    "no_consultations" => "Sin consultas",
    "additional_information" => "Información adicional",
    "doctor_id" => "ID del médico",
    "quick_actions" => "Acciones rápidas",
    "view_all_doctors" => "Ver todos los médicos",
    "status_information" => "Información de estado",
    "active_doctor" => "Médico activo",
    "active_doctor_message" => "Este médico está actualmente activo y disponible para consultas",
    "inactive_doctor" => "Médico inactivo",
    "inactive_doctor_message" => "Este médico está actualmente inactivo y no está disponible para consultas",
    "on_leave_doctor" => "De baja",
    "on_leave_doctor_message" => "Este médico está actualmente de baja y no está disponible para consultas",
    "reset_to_original" => "Restablecer al original",
    "update_doctor" => "Actualizar médico",
    "confirm_reset_original" => "¿Está seguro de que desea restablecer el formulario a los valores originales? Se perderán todos los cambios.",
    "doctor_details_to_delete" => "Detalles del médico a eliminar",
    "confirm_deletion" => "Confirmar eliminación",
    "confirm_delete_question" => "¿Está seguro de que desea eliminar este médico?",
    "yes_delete_permanently" => "Sí, eliminar este médico permanentemente",
    "no_cancel_deletion" => "No, cancelar eliminación",
    "safety_check" => "Verificación de seguridad",
    "deletion_blocked" => "Eliminación bloqueada",
    "safe_to_delete" => "Seguro para eliminar",
    "safe_to_delete_message" => "Este médico no está asociado con ningún registro de ingresos y puede eliminarse de forma segura",
    "cannot_delete" => "No se puede eliminar",
    "cannot_delete_message" => "Este médico está asociado con {count} registro(s) de ingresos y no se puede eliminar. Primero debe eliminar o reasignar estos registros de ingresos antes de eliminar el médico.",
    "view_associated_income" => "Ver registros de ingresos asociados",
    "select_confirmation_option" => "Por favor seleccione una opción de confirmación.",
    "absolutely_sure_delete" => "¿Está absolutamente seguro de que desea eliminar este médico? Esta acción no se puede deshacer.",
    
    // Additional UI elements
    "summary_cards" => "Tarjetas de resumen",
    "search_and_filter_form" => "Formulario de búsqueda y filtro",
    "export_button" => "Botón de exportar",
    "doctors_table" => "Tabla de médicos",
    "doctors_pagination" => "Paginación de médicos",
    "get_current_search_parameters" => "Obtener parámetros de búsqueda actuales",
    "create_export_url" => "Crear URL de exportación",
    "download_file" => "Descargar el archivo",
    
    // Search and Filter
    "search_and_filter" => "Buscar y Filtrar",
    
    // Table Headers
    "doctors_records" => "Registros de Médicos",
    
    // Default Doctor functionality
    "default_doctor" => "Médico por Defecto (Corona)",
    "set_as_default" => "Establecer como Médico por Defecto",
    "confirm_set_default" => "¿Está seguro de que desea establecer",
    "set_default_error" => "No se pudo establecer el médico por defecto. Inténtelo de nuevo.",
];
