<?php

$root = dirname(__DIR__);
$failures = [];
$files = [];
$iterator = new RecursiveIteratorIterator(
    new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS)
);
foreach ($iterator as $file) {
    if (!$file->isFile()) {
        continue;
    }
    $relative = str_replace(DIRECTORY_SEPARATOR, '/', substr($file->getPathname(), strlen($root) + 1));
    if (strpos($relative, '.git/') === 0 || strpos($relative, 'dist/') === 0) {
        continue;
    }
    $files[$relative] = $file->getPathname();
}

$required = [
    'LICENSE', 'NOTICE.md', 'README.md', 'RELEASE_NOTES.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md',
    '.gitignore', '.gitattributes', '.github/workflows/ci.yml', 'docs/ARCHITECTURE.md',
    'docs/DATABASE.md', 'docs/DEPLOYMENT.md', 'docs/HISTORY.md', 'docs/OPEN_SOURCE_AUDIT.md',
    'IdcsmartClientLevel.php',
];
foreach ($required as $relative) {
    if (!isset($files[$relative])) {
        $failures[] = 'missing required file: ' . $relative;
    }
}

$forbiddenNames = [
    '/(^|\/)(private\.key|\.env(?:\..*)?|[^\/]+\.(?:pem|p12|pfx|sql|sqlite|sqlite3))$/i',
    '/(^|\/)\.DS_Store$/',
];
$secretPatterns = [
    '/-----BEGIN (?:RSA |OPENSSH |EC )?PRIVATE KEY-----/',
    '/(?:password|passwd|secret|api[_-]?key)\s*[:=]\s*["\'][^"\']{8,}["\']/i',
    '/(?:mysql|postgres(?:ql)?):\/\/[^\s:@]+:[^\s@]+@/i',
];
$identityPatterns = [
    '/175\.178\.97\.112/',
    '#/(?:Users|home)/[^/\s]+/#',
];
$dangerousPatterns = [
    '/\beval\s*\(/i',
    '/\b(?:shell_exec|passthru|proc_open|popen)\s*\(/i',
    '/\b(?:include|require)(?:_once)?\s*\(?\s*["\']https?:\/\//i',
];
$textExtensions = ['php', 'js', 'css', 'html', 'md', 'yml', 'yaml', 'json', 'txt', 'gitignore'];
foreach ($files as $relative => $path) {
    foreach ($forbiddenNames as $pattern) {
        if (preg_match($pattern, $relative)) {
            $failures[] = 'forbidden file: ' . $relative;
        }
    }
    $extension = strtolower(pathinfo($relative, PATHINFO_EXTENSION));
    if ($relative === '.gitignore') {
        $extension = 'gitignore';
    }
    if ($relative === 'tools/open_source_audit.php') {
        continue;
    }
    if (!in_array($extension, $textExtensions, true)) {
        continue;
    }
    $contents = (string) file_get_contents($path);
    foreach (array_merge($secretPatterns, $dangerousPatterns, $identityPatterns) as $pattern) {
        if (preg_match($pattern, $contents)) {
            $failures[] = 'forbidden pattern in ' . $relative . ': ' . $pattern;
        }
    }
    if (preg_match_all('#https?://[^\s)\]}>"\']+#i', $contents, $urlMatches)) {
        foreach ($urlMatches[0] as $url) {
            preg_match('#^https?://([A-Za-z0-9.-]+)#i', $url, $hostMatch);
            $host = strtolower((string) ($hostMatch[1] ?? ''));
            if (!in_array($host, ['example.com', 'github.com', 'img.shields.io'], true)
                && substr($host, -12) !== '.example.com') {
                $failures[] = 'non-example URL in ' . $relative . ': ' . $url;
            }
        }
    }
    if (preg_match_all('/[A-Z0-9._%+-]+@([A-Z0-9.-]+\.[A-Z]{2,})/i', $contents, $mailMatches)) {
        foreach ($mailMatches[1] as $domain) {
            if (strcasecmp($domain, 'example.com') !== 0) {
                $failures[] = 'non-example email domain in ' . $relative . ': ' . $domain;
            }
        }
    }
    if (strpos($contents, "\0") !== false) {
        $failures[] = 'binary or NUL content in source file: ' . $relative;
    }
}

$clientTemplates = [
    'template/clientarea/index.html',
    'template/clientarea/pc/default/index.html',
    'template/clientarea/mobile/default/index.html',
];
$templateHashes = [];
foreach ($clientTemplates as $relative) {
    if (isset($files[$relative])) {
        $templateHashes[$relative] = hash_file('sha256', $files[$relative]);
    }
}
if (count(array_unique($templateHashes)) > 1) {
    $failures[] = 'client templates are not byte-identical';
}

$runtimeForbidden = ['README.md', 'CHANGELOG.md', 'CONTRIBUTING.md', 'SECURITY.md', 'LICENSE', 'NOTICE.md'];
$entry = isset($files['IdcsmartClientLevel.php']) ? (string) file_get_contents($files['IdcsmartClientLevel.php']) : '';
if (!preg_match("/'version'\\s*=>\\s*'1\\.6\\.3'/", $entry)) {
    $failures[] = 'plugin version is not 1.6.3';
}
foreach ($runtimeForbidden as $relative) {
    if (strpos($entry, $relative) !== false) {
        $failures[] = 'runtime entry unexpectedly references development file: ' . $relative;
    }
}

if ($failures) {
    foreach ($failures as $failure) {
        fwrite(STDERR, 'FAIL: ' . $failure . PHP_EOL);
    }
    exit(1);
}

echo json_encode([
    'status' => 'OK',
    'source_files_checked' => count($files),
    'required_files' => count($required),
    'secret_scan' => 'passed',
    'dangerous_primitive_scan' => 'passed',
    'identity_and_domain_scan' => 'passed',
    'client_templates_identical' => true,
    'version' => '1.6.3',
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . PHP_EOL;
