<?php

spl_autoload_register(function ($class) {
    $class = ltrim($class, '\\');
    $baseDir = __DIR__ . DIRECTORY_SEPARATOR;

    if (strpos($class, 'SGMR\\') === 0) {
        $relative = substr($class, strlen('SGMR\\'));
        $relative = str_replace('\\', DIRECTORY_SEPARATOR, $relative);
        $path = $baseDir . $relative . '.php';
        if (file_exists($path)) {
            require_once $path;
        }
        return;
    }

    if (strpos($class, 'Sanigroup\\Montagerechner\\') === 0) {
        $relative = substr($class, strlen('Sanigroup\\Montagerechner\\'));
        $newClass = 'SGMR\\' . $relative;
        if (!class_exists($newClass, false) && !interface_exists($newClass, false) && !trait_exists($newClass, false)) {
            $path = $baseDir . str_replace('\\', DIRECTORY_SEPARATOR, $relative) . '.php';
            if (file_exists($path)) {
                require_once $path;
            }
        }
        if (class_exists($newClass, false) || interface_exists($newClass, false) || trait_exists($newClass, false)) {
            class_alias($newClass, $class);
        }
    }
});

SGMR\Plugin::instance();
