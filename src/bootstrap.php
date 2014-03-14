<?php

spl_autoload_register(function($class) {
    if (strpos($class, "Artax\\") === 0) {
        $name = substr($class, strlen("Artax"));
        require __DIR__ . "/../lib" . strtr($name, "\\", DIRECTORY_SEPARATOR) . ".php";
    } elseif (strpos($class, "Alert\\") === 0) {
        $name = substr($class, strlen("Alert"));
        require __DIR__ . "/../vendor/Alert/lib" . strtr($name, "\\", DIRECTORY_SEPARATOR) . ".php";
    }
});
