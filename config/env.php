<?php
/**
 * Environment variable loader (.env file + helpers).
 * Dental Clinic Management System
 */

if (!function_exists('dcmt_load_env_file')) {
    /**
     * Load KEY=VALUE pairs from a .env file into the process environment.
     * Existing environment variables are not overwritten.
     */
    function dcmt_load_env_file(string $path): void
    {
        if (!is_readable($path)) {
            return;
        }

        $lines = file($path, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        if ($lines === false) {
            return;
        }

        foreach ($lines as $line) {
            $line = trim($line);
            if ($line === '' || $line[0] === '#') {
                continue;
            }
            if (strpos($line, '=') === false) {
                continue;
            }

            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value);

            if ($name === '') {
                continue;
            }

            if (
                (strlen($value) >= 2 && $value[0] === '"' && substr($value, -1) === '"')
                || (strlen($value) >= 2 && $value[0] === "'" && substr($value, -1) === "'")
            ) {
                $value = substr($value, 1, -1);
            }

            if (getenv($name) === false) {
                putenv($name . '=' . $value);
                $_ENV[$name] = $value;
            }
        }
    }
}

if (!function_exists('dcmt_env')) {
    /**
     * Read an environment variable with an optional default.
     *
     * @param mixed $default
     * @return mixed
     */
    function dcmt_env(string $key, $default = '')
    {
        $value = getenv($key);
        if ($value === false) {
            return $default;
        }
        return $value;
    }
}

if (!function_exists('dcmt_request_is_https')) {
    /**
     * Detect HTTPS including common reverse-proxy headers.
     */
    function dcmt_request_is_https(): bool
    {
        if (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') {
            return true;
        }
        if (!empty($_SERVER['SERVER_PORT']) && (string) $_SERVER['SERVER_PORT'] === '443') {
            return true;
        }
        $forwardedProto = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')));
        if ($forwardedProto === 'https') {
            return true;
        }
        $forwardedSsl = strtolower(trim((string) ($_SERVER['HTTP_X_FORWARDED_SSL'] ?? '')));
        if ($forwardedSsl === 'on') {
            return true;
        }
        return false;
    }
}
