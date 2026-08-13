<?php

$root = dirname(__DIR__);
$entry = (string) file_get_contents($root . '/IdcsmartClientLevel.php');
if (!preg_match("/'version'\s*=>\s*'([^']+)'/", $entry, $match)) {
    fwrite(STDERR, "Cannot determine plugin version\n");
    exit(1);
}
$version = $match[1];
$output = isset($argv[1]) && $argv[1] !== ''
    ? $argv[1]
    : $root . '/dist/idcsmart_client_level_v' . $version . '_github_source.zip';
$outputDirectory = dirname($output);
if (!is_dir($outputDirectory) && !mkdir($outputDirectory, 0775, true) && !is_dir($outputDirectory)) {
    fwrite(STDERR, "Cannot create output directory\n");
    exit(1);
}

$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    if ($file->isLink()) {
        fwrite(STDERR, "Source tree contains symlink: {$relative}\n");
        exit(1);
    }
    if (!$file->isFile()
        || strpos($relative, '.git/') === 0
        || strpos($relative, 'dist/') === 0
        || preg_match('/(^|\/)\.DS_Store$/', $relative)
    ) {
        continue;
    }
    $files[] = $relative;
}
sort($files, SORT_STRING);

$temporaryOutput = $output . '.tmp-' . getmypid();
$zip = new ZipArchive();
if ($zip->open($temporaryOutput, ZipArchive::CREATE | ZipArchive::OVERWRITE) !== true) {
    fwrite(STDERR, "Cannot create ZIP\n");
    exit(1);
}
foreach ($files as $relative) {
    if (!$zip->addFile($root . '/' . $relative, 'idcsmart_client_level/' . $relative)) {
        $zip->close();
        @unlink($temporaryOutput);
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
    'files' => count($files),
    'output' => realpath($output),
    'sha256' => hash_file('sha256', $output),
], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT) . PHP_EOL;
