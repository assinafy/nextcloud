<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$catalog = json_decode(
    file_get_contents($root . '/l10n/pt_BR.json'),
    true,
    512,
    JSON_THROW_ON_ERROR,
)['translations'] ?? [];
$messages = [];

foreach (['js/app.js', 'js/admin.js'] as $file) {
    $source = file_get_contents($root . '/' . $file);
    preg_match_all("/(?<![A-Za-z0-9_$])tr\\('([^']+)'/", $source, $matches);
    $messages = array_merge($messages, $matches[1]);
    preg_match_all("/(?<![A-Za-z0-9_$])t\\('assinafy',\\s*'([^']+)'/", $source, $matches);
    $messages = array_merge($messages, $matches[1]);
}
foreach (['templates/main.php', 'templates/admin.php'] as $file) {
    $source = file_get_contents($root . '/' . $file);
    preg_match_all("/->t\\('([^']+)'/", $source, $matches);
    $messages = array_merge($messages, $matches[1]);
}

$missing = array_values(array_diff(array_unique($messages), array_keys($catalog), ['Assinafy']));
sort($missing);
if ($missing !== []) {
    fwrite(STDERR, "Missing pt_BR translations:\n- " . implode("\n- ", $missing) . "\n");
    exit(1);
}

fwrite(STDOUT, count(array_unique($messages)) . " source messages covered.\n");
