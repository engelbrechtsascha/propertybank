<?php
declare(strict_types=1);

/**
 * FINATUM PropertyBank — the release step (as the Pricer's and MarketData's).
 *
 *   php tools/release.php --zip      builds, beside the project folder:
 *                                    propertybank-X.zip         a new installation
 *                                    propertybank-X-update.zip  over an existing one
 *
 * Runs the parser checks first and stops if one fails.
 */

$root = dirname(__DIR__);
require_once $root . '/api/lib/version.php';
$version = PB_VERSION;

echo "FINATUM PropertyBank — release " . $version . "\n" . str_repeat('-', 58) . "\n";

passthru(escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($root . '/tools/test-parse.php'), $code);
if ($code !== 0) {
    fwrite(STDERR, "The parser checks failed — nothing was packaged.\n");
    exit(1);
}
if (!in_array('--zip', array_slice($argv, 1), true)) {
    echo "Checks passed. Add --zip to build the upload packages.\n";
    exit(0);
}

$exclude = static fn (string $rel): bool => $rel === 'api/config.php'
    || (str_starts_with($rel, 'data/') && $rel !== 'data/.htaccess')
    || str_starts_with($rel, 'tools/')
    || str_starts_with($rel, '.git')
    || str_ends_with($rel, '.DS_Store');

$files = [];
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
foreach ($it as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $rel = str_replace('\\', '/', substr($file->getPathname(), strlen($root) + 1));
    if (!$exclude($rel)) {
        $files[] = $rel;
    }
}
sort($files);

foreach (['' => false, '-update' => true] as $suffix => $isUpdate) {
    $name = 'propertybank-' . $version . $suffix . '.zip';
    $out = dirname($root) . '/' . $name;
    @unlink($out);
    $zip = new ZipArchive();
    if ($zip->open($out, ZipArchive::CREATE) !== true) {
        fwrite(STDERR, "Could not create " . $out . "\n");
        exit(1);
    }
    $n = 0;
    foreach ($files as $rel) {
        if ($isUpdate && $rel === 'install.php') {
            continue;   // an update must not bring the installer back
        }
        $zip->addFile($root . '/' . $rel, 'propertybank/' . $rel);
        $n++;
    }
    $zip->close();
    echo '  ' . $name . ' — ' . $n . ' files, ' . round(filesize($out) / 1024) . " KB\n";
}
echo '  in ' . dirname($root) . "\n";
