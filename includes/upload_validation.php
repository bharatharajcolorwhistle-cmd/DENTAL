<?php
/**
 * Server-side upload validation using MIME sniffing (finfo).
 */

if (!function_exists('dcmt_upload_detect_mime')) {
    function dcmt_upload_detect_mime(string $tmpPath): string
    {
        if (!is_readable($tmpPath)) {
            return '';
        }
        if (class_exists('finfo')) {
            $finfo = new finfo(FILEINFO_MIME_TYPE);
            $mime = $finfo->file($tmpPath);
            return is_string($mime) ? strtolower($mime) : '';
        }
        $mime = @mime_content_type($tmpPath);
        return is_string($mime) ? strtolower($mime) : '';
    }
}

if (!function_exists('dcmt_validate_upload_mime')) {
    /**
     * @param string[] $allowedMimes
     */
    function dcmt_validate_upload_mime(string $tmpPath, array $allowedMimes): bool
    {
        $mime = dcmt_upload_detect_mime($tmpPath);
        if ($mime === '') {
            return false;
        }
        $allowedMimes = array_map('strtolower', $allowedMimes);
        return in_array($mime, $allowedMimes, true);
    }
}

if (!function_exists('dcmt_validate_image_upload')) {
    function dcmt_validate_image_upload(array $file): bool
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        return dcmt_validate_upload_mime($tmp, [
            'image/jpeg',
            'image/png',
            'image/gif',
            'image/webp',
        ]);
    }
}

if (!function_exists('dcmt_validate_csv_upload')) {
    function dcmt_validate_csv_upload(array $file): bool
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'csv') {
            return false;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        return dcmt_validate_upload_mime($tmp, [
            'text/csv',
            'text/plain',
            'application/csv',
            'application/vnd.ms-excel',
            'inode/x-empty',
        ]);
    }
}

if (!function_exists('dcmt_validate_xlsx_upload')) {
    function dcmt_validate_xlsx_upload(array $file): bool
    {
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            return false;
        }
        $ext = strtolower(pathinfo((string) ($file['name'] ?? ''), PATHINFO_EXTENSION));
        if ($ext !== 'xlsx') {
            return false;
        }
        $tmp = (string) ($file['tmp_name'] ?? '');
        return dcmt_validate_upload_mime($tmp, [
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'application/zip',
            'application/octet-stream',
        ]);
    }
}
