<?php
/**
 * One-time helper: standardize AJAX endpoints to includes/ajax_bootstrap.php
 * Run: php scripts/migrate_ajax_bootstrap.php
 */

declare(strict_types=1);

$root = dirname(__DIR__);
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root . DIRECTORY_SEPARATOR . 'pages')
);

$bootstrap = "require_once __DIR__ . '/../../includes/ajax_bootstrap.php';";
$bootstrapAlt = "require_once __DIR__ . '/../includes/ajax_bootstrap.php';";

foreach ($iterator as $file) {
    if (!$file->isFile() || !str_ends_with($file->getFilename(), '_ajax.php')) {
        continue;
    }

    $path = $file->getPathname();
    $content = file_get_contents($path);
    if ($content === false || str_contains($content, 'ajax_bootstrap.php')) {
        continue;
    }

    if (!preg_match('/<\?php\s*\n\/\*\*[\s\S]*?\*\/\s*\n/', $content, $m, PREG_OFFSET_CAPTURE)) {
        continue;
    }

    $insertPos = $m[0][1] + strlen($m[0][0]);
    $newContent = substr($content, 0, $insertPos) . "\n" . $bootstrap . "\n" . substr($content, $insertPos);

    // Remove duplicate auth/bootstrap blocks
    $patterns = [
        "/require_once __DIR__ \. '\/\.\.\/\.\.\/auth\/check_auth\.php';\s*\n/",
        "/require_once __DIR__ \. '\/\.\.\/\.\.\/config\/config\.php';\s*\n/",
        "/require_once __DIR__ \. '\/\.\.\/\.\.\/config\/database\.php';\s*\n/",
        "/require_once __DIR__ \. '\/\.\.\/\.\.\/includes\/dcmt_owner_doctor\.php';\s*\n/",
        "/header\('Content-Type: application\/json(?:; charset=utf-8)?'\);\s*\n/",
        "/if \(!dcmt_validate_session\(\)\) \{[\s\S]*?exit\(\);\s*\}\s*\n/",
    ];

    foreach ($patterns as $pattern) {
        $newContent = preg_replace($pattern, '', $newContent, 1) ?? $newContent;
    }

    // Collapse excessive blank lines after header comment block
    $newContent = preg_replace("/\n{3,}/", "\n\n", $newContent) ?? $newContent;

    if ($newContent !== $content) {
        file_put_contents($path, $newContent);
        echo 'Updated: ' . str_replace($root . DIRECTORY_SEPARATOR, '', $path) . PHP_EOL;
    }
}

echo "Done.\n";
