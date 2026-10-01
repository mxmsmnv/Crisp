#!/usr/bin/env php
<?php

$module = file_get_contents(__DIR__ . '/../Crisp.module.php');
$styles = file_get_contents(__DIR__ . '/../assets/admin.css');

$checks = [
    'release version is current' => str_contains($module, "'version'  => 101"),
    'config wrapper has stable scope' => str_contains($module, "addClass('crisp-module-config')"),
    'admin stylesheet is cache-busted' => str_contains($module, "Crisp/assets/admin.css?v=") && str_contains($module, 'filemtime($adminCss)'),
    'long labels wrap within the viewport' => str_contains($styles, '.crisp-module-config label') && str_contains($styles, 'overflow-wrap: anywhere;'),
    'config descendants may shrink' => str_contains($styles, '.crisp-module-config *') && str_contains($styles, 'min-width: 0;'),
];

foreach ($checks as $label => $passed) {
    if (!$passed) {
        fwrite(STDERR, "FAIL: {$label}\n");
        exit(1);
    }
}

echo "Crisp admin responsive contract: " . count($checks) . " checks passed.\n";
