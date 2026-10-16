<?php
/**
 * Bootstrap domPDF hasil INSTALL MANUAL (tanpa Composer).
 *
 * Cara pasang:
 *  1. Unduh arsip sumber domPDF dari https://github.com/dompdf/dompdf/releases
 *     lalu ekstrak ke folder: lib/dompdf/
 *  2. Pastikan struktur berikut ada:
 *       lib/dompdf/src/Dompdf.php
 *       lib/dompdf/lib/fonts/            (DejaVu Sans dll.)
 *       lib/html5-parser/src/HTML5/*     (dependensi Masterminds HTML5 parser)
 *       lib/php-svg-lib/src/Svg/*        (dependensi)
 *       lib/php-font-lib/src/FontLib/*   (dependensi)
 *  3. Tidak perlu composer install — file ini memetakan namespace secara sederhana.
 */

if (!class_exists(Dompdf\Dompdf::class)) {

    // Peta root namespace => folder sumber
    $roots = [
        'Dompdf\\'      => __DIR__ . '/../lib/dompdf/src/',
        'Svg\\'         => __DIR__ . '/../lib/php-svg-lib/src/Svg/',
        'FontLib\\'     => __DIR__ . '/../lib/php-font-lib/src/FontLib/',
        'Masterminds\\' => __DIR__ . '/../lib/html5-parser/src/Masterminds/',
    ];

    spl_autoload_register(function ($class) use ($roots) {
        foreach ($roots as $prefix => $baseDir) {
            if (strncmp($class, $prefix, strlen($prefix)) === 0) {
                $relative = substr($class, strlen($prefix));
                $file = $baseDir . str_replace('\\', '/', $relative) . '.php';
                if (is_file($file)) { require $file; return; }
            }
        }
    });

    // html5-php memakai namespace global "Masterminds" pada beberapa versi lama,
    // namun versi 2.x memakai namespace "Masterminds\HTML5". Peta tambahan:
    spl_autoload_register(function ($class) {
        if (strncmp($class, 'Masterminds\\', 12) === 0) {
            $file = __DIR__ . '/../lib/html5-parser/src/' . str_replace('\\', '/', $class) . '.php';
            if (is_file($file)) require $file;
        }
    });



    // Masterminds\HTML5 (html5-php) => file src/HTML5.php, sub-namespace src/HTML5/*
    spl_autoload_register(function ($class) {
        if ($class === 'Masterminds\\HTML5') {
            require __DIR__ . '/../lib/html5-parser/src/HTML5.php';
            return;
        }
        if (strncmp($class, 'Masterminds\\HTML5\\', 18) === 0) {
            $relative = substr($class, strlen('Masterminds\\'));
            $file = __DIR__ . '/../lib/html5-parser/src/' . str_replace('\\', '/', $relative) . '.php';
            if (is_file($file)) require $file;
        }
    });

    // Cpdf berada di lib/dompdf/lib/Cpdf.php dengan namespace Dompdf (versi 3.x)
    if (!class_exists('Dompdf\\Cpdf', false)) {
        $cpdf = __DIR__ . '/../lib/dompdf/lib/Cpdf.php';
        if (is_file($cpdf)) require_once $cpdf;
    }

    // Muat autoloader resmi dompdf bila tersedia (menangani konstanta & helper)
    $autoloaderFile = __DIR__ . '/../lib/dompdf/src/Autoloader.php';
    if (is_file($autoloaderFile)) {
        require_once $autoloaderFile;
        if (class_exists(\Dompdf\Autoloader::class)) \Dompdf\Autoloader::register();
    }
}
