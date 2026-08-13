<?php

$root = dirname(__DIR__);
$entry = file_get_contents($root . '/IdcsmartClientLevel.php');
if (!preg_match("/'version'\\s*=>\\s*'([^']+)'/", (string) $entry, $match)) {
    fwrite(STDERR, "Cannot determine plugin version\n");
    exit(1);
}
$version = $match[1];
$output = isset($argv[1]) && $argv[1] !== ''
    ? $argv[1]
    : $root . '/dist/idcsmart_client_level_v' . $version . '_zjmf_v10_import.zip';
$outputDir = dirname($output);
if (!is_dir($outputDir) && !mkdir($outputDir, 0775, true) && !is_dir($outputDir)) {
    fwrite(STDERR, "Cannot create output directory\n");
    exit(1);
}

$runtimeDirectories = ['controller', 'lang', 'lib', 'model', 'public', 'template'];
$runtimeFiles = [
    'IdcsmartClientLevel.php',
    'auth.php',
    'auth_clientarea.php',
    'route.php',
    'sidebar.php',
    'sidebar_clientarea.php',
];
foreach ($runtimeDirectories as $directory) {
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root . '/' . $directory, FilesystemIterator::SKIP_DOTS)
    );
    foreach ($iterator as $file) {
        if ($file->isFile()) {
            $runtimeFiles[] = substr($file->getPathname(), strlen($root) + 1);
        }
    }
}
$runtimeFiles = array_values(array_unique($runtimeFiles));
sort($runtimeFiles, SORT_STRING);

$temporaryOutput = $output . '.tmp-' . getmypid();
$zip = new ZipArchive();
if ($zip->open($temporaryOutput, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP\n");
    exit(1);
}
foreach ($runtimeFiles as $relative) {
    if (!$zip->addFile($root . '/' . $relative, 'idcsmart_client_level/' . $relative)) {
        $zip->close();
        fwrite(STDERR, "Cannot add {$relative}\n");
        exit(1);
    }
}
$zip->close();
if (is_file($output) && !unlink($output)) {
    @unlink($temporaryOutput);
    fwrite(STDERR, "Cannot replace existing ZIP\n");
    exit(1);
}
if (!rename($temporaryOutput, $output)) {
    @unlink($temporaryOutput);
    fwrite(STDERR, "Cannot activate ZIP\n");
    exit(1);
}

echo json_encode([
    'status' => 'OK',
    'version' => $version,
    'files' => count($runtimeFiles),
    'output' => realpath($output),
    'sha256' => hash_file('sha256', $output),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
