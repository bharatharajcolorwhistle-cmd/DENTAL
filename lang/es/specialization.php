<?php
/**
 * Doctor Specializations Translation File - Spanish
 * Dental Clinic Management System
 */

return [
    // General terms
    'specializations' => 'Especializaciones',
    'specialization' => 'Especialización',
    'name' => 'Nombre',
    'description' => 'Descripción',
    'doctors_count' => 'Cantidad de Doctores',
    'doctors' => 'doctores',
    
    // Actions
    'add_specialization' => 'Agregar Especialización',
    'edit_specialization' => 'Editar Especialización',
    'update_specialization' => 'Actualizar Especialización',
    'view_all_specializations' => 'Ver Todas las Especializaciones',
    'delete_specialization' => 'Eliminar Especialización',
    
    // Form labels and help text
    'name_help' => 'Ingrese un nombre único para la especialización',
    'description_help' => 'Descripción opcional para la especialización',
    'status_help' => 'Seleccione el estado para esta especialización',
    'no_description' => 'Sin descripción proporcionada',
    'enter_name' => 'Ingrese nombre de especialización',
    'enter_description' => 'Ingrese descripción de especialización',
    'select_status' => 'Seleccionar estado',
    
    // Button text
    'reset' => 'Restablecer',
    'add_specialization_record' => 'Agregar Especialización',
    
    // Status messages
    'add_success' => 'Especialización agregada exitosamente',
    'update_success' => 'Especialización actualizada exitosamente',
    'delete_success' => 'Especialización eliminada exitosamente',
    'add_error' => 'Error al agregar especialización',
    'update_error' => 'Error al actualizar especialización',
    'delete_failed' => 'Error al eliminar especialización',
    'database_error' => 'Error de base de datos',
    
    // Validation messages
    'name_exists' => 'Ya existe una especialización con este nombre',
    'duplicate_check_error' => 'Error al verificar nombres duplicados',
    'name_required' => 'El nombre de la especialización es requerido',
    'status_required' => 'El estado es requerido',
    'invalid_id' => 'ID de especialización inválido',
    'not_found' => 'Especialización no encontrada',
    'load_error' => 'Error al cargar especializaciones',
    
    // Search and filtering
    'search_placeholder' => 'Buscar especializaciones por nombre o descripción',
    'no_specializations_found' => 'No se encontraron especializaciones',
    'try_adjusting_search' => 'Intente ajustar sus criterios de búsqueda',
    
    // Deletion protection
    'cannot_delete_with_doctors' => 'No se puede eliminar especialización asignada a doctores',
    'locked_specialization_message' => 'Esta especialización está asignada a doctores y no se puede eliminar',
    
    // Information and notes
    'information' => 'Información',
    'note' => 'Nota',
    'add_note' => 'Las especializaciones ayudan a categorizar doctores por su experiencia médica.',
    'edit_note' => 'Actualice los detalles de la especialización a continuación.',
    'required_fields' => 'Campos Requeridos',
    'optional_fields' => 'Campos Opcionales',
    'specialization_details' => 'Detalles de la Especialización',
    
    // Confirmation messages
    'confirm_delete' => '¿Está seguro de que desea eliminar esta especialización?',
    'confirm_reset' => '¿Está seguro de que desea restablecer el formulario? Esto perderá todos los cambios no guardados.',
    
    // AJAX messages
    'unknown_error' => 'Ocurrió un error desconocido',
    'error_occurred_deleting' => 'Ocurrió un error al eliminar la especialización',

    // Bulk delete
    'please_select_one_record' => 'Por favor seleccione al menos una especialización para eliminar.',
    'confirm_delete_single' => '¿Está seguro de que desea eliminar esta especialización? Esta acción no se puede deshacer.',
    'confirm_delete_multiple' => '¿Está seguro de que desea eliminar estas {count} especializaciones? Esta acción no se puede deshacer.',
    'bulk_delete_success' => '¡{count} especializaciones eliminadas correctamente!',
    'bulk_delete_partial' => '{deleted} especializaciones eliminadas. {skipped} no se pudieron eliminar porque están asignadas a doctores.',
    'deleting_records' => 'Eliminando especializaciones...',
    'failed_to_delete_records' => 'Error al eliminar las especializaciones',
    'error_occurred_deleting_records' => 'Ocurrió un error al eliminar las especializaciones. Por favor, inténtelo de nuevo.',
    'invalid_json_input' => 'Entrada JSON inválida',
    'invalid_ids' => 'IDs de especialización inválidos',
    'records_not_found' => 'Una o más especializaciones no se encontraron',
];
?>
