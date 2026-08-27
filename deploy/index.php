<?php

/**
 * Punto de entrada para hosting compartido (Hostinger).
 *
 * En un servidor propio, el dominio apunta directo a la carpeta public/ de
 * Laravel. En hosting compartido no se puede cambiar la raiz web: siempre
 * es public_html. Si se sube el proyecto entero ahi, cualquiera puede
 * abrir tudominio.com/.env y leer la contrasena de la base de datos.
 *
 * Por eso el proyecto se parte en dos:
 *
 *   ~/muevo/          <- todo el codigo, FUERA de la raiz web
 *   ~/public_html/    <- solo el contenido de public/, y este archivo
 *
 * Este archivo reemplaza a public_html/index.php y le dice a Laravel que
 * el codigo esta en la carpeta de al lado.
 */

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

/*
 * Ruta a la carpeta del proyecto, relativa a public_html.
 *
 * Si la carpeta no se llama "muevo" o no esta al mismo nivel que
 * public_html, este es el unico valor que hay que cambiar.
 */
$app_path = __DIR__.'/../muevo';

// Modo mantenimiento...
if (file_exists($maintenance = $app_path.'/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Autoloader de Composer...
require $app_path.'/vendor/autoload.php';

/** @var Application $app */
$app = require_once $app_path.'/bootstrap/app.php';

/*
 * Sin esto Laravel creeria que la carpeta publica es ~/muevo/public, que
 * es la que el navegador no puede ver. Cualquier cosa que escriba o lea
 * archivos publicos terminaria en el lugar equivocado.
 */
$app->usePublicPath(__DIR__);

$app->handleRequest(Request::capture());
