<?php
/**
 * WhatsApp message template helpers
 */

if (!function_exists('dcmt_whatsapp_template_placeholders_help')) {
    function dcmt_whatsapp_template_placeholders_help(): string
    {
        return '{patient_name}, {site_name}, {phone}';
    }
}

if (!function_exists('dcmt_whatsapp_template_defaults')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function dcmt_whatsapp_template_defaults(): array
    {
        return [
            [
                'dcmt_id' => 1,
                'dcmt_name' => trans('whatsapp_template', 'default_general_name'),
                'dcmt_message' => trans('whatsapp_template', 'default_general_message'),
            ],
            [
                'dcmt_id' => 2,
                'dcmt_name' => trans('whatsapp_template', 'default_appointment_name'),
                'dcmt_message' => trans('whatsapp_template', 'default_appointment_message'),
            ],
            [
                'dcmt_id' => 3,
                'dcmt_name' => trans('whatsapp_template', 'default_birthday_name'),
                'dcmt_message' => trans('whatsapp_template', 'default_birthday_message'),
            ],
        ];
    }
}

if (!function_exists('dcmt_fetch_active_whatsapp_templates')) {
    /**
     * @return array<int, array<string, mixed>>
     */
    function dcmt_fetch_active_whatsapp_templates(PDO $pdo): array
    {
        try {
            $table = $pdo->query("SHOW TABLES LIKE 'dcmt_whatsapp_templates'");
            if (!$table || $table->rowCount() === 0) {
                return dcmt_whatsapp_template_defaults();
            }
            $stmt = $pdo->query("
                SELECT dcmt_id, dcmt_name, dcmt_message
                FROM dcmt_whatsapp_templates
                WHERE dcmt_status = 'active'
                ORDER BY dcmt_name ASC
            ");
            $rows = $stmt ? $stmt->fetchAll(PDO::FETCH_ASSOC) : [];
            return !empty($rows) ? $rows : dcmt_whatsapp_template_defaults();
        } catch (PDOException $e) {
            error_log('dcmt_fetch_active_whatsapp_templates: ' . $e->getMessage());
            return dcmt_whatsapp_template_defaults();
        }
    }
}

if (!function_exists('dcmt_apply_whatsapp_template')) {
    function dcmt_apply_whatsapp_template(string $template, array $vars): string
    {
        $replacements = [
            '{patient_name}' => (string)($vars['patient_name'] ?? ''),
            '{site_name}' => (string)($vars['site_name'] ?? ''),
            '{phone}' => (string)($vars['phone'] ?? ''),
        ];
        return str_replace(array_keys($replacements), array_values($replacements), $template);
    }
}

if (!function_exists('dcmt_whatsapp_message_url')) {
    function dcmt_whatsapp_message_url(string $phone, string $message): string
    {
        $digits = preg_replace('/\D+/', '', $phone);
        $encoded = rawurlencode($message);
        if ($digits === '') {
            return 'https://web.whatsapp.com/send?text=' . $encoded;
        }
        return 'https://wa.me/' . $digits . '?text=' . $encoded;
    }
}
