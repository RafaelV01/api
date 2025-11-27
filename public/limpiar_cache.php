<?php

echo "<pre style='background-color: #222; color: #0f0; padding: 20px; font-family: monospace; font-size: 14px; border-radius: 5px;'>";

// La ruta absoluta al archivo artisan
$artisanPath = '/home1/colminds/public_html/api/artisan';

// Verifica si el archivo artisan existe
if (!file_exists($artisanPath)) {
    die("ERROR: No se pudo encontrar el archivo 'artisan'.\nVerifica que la ruta es correcta: " . $artisanPath);
}

// Lista de comandos para LIMPIAR todas las cachés
$commands = [
    'route:clear',
    'view:clear',
    'cache:clear',
    'config:clear' // <-- CAMBIO IMPORTANTE: Usamos 'clear' para borrar
];

echo "--- INICIANDO LIMPIEZA TOTAL DE CACHÉ ---\n\n";

foreach ($commands as $command) {
    echo "Ejecutando: php artisan " . $command . "\n";
    // El comando 'php' debe estar en el PATH del servidor, lo cual es estándar.
    $full_command = 'php ' . $artisanPath . ' ' . $command . ' 2>&1';
    $output = shell_exec($full_command);
    echo htmlentities($output) . "\n----------------------------------\n";
}

echo "--- PROCESO DE LIMPIEZA COMPLETADO ---\n\n";
echo "Ahora intenta probar tu login de nuevo.";

echo "</pre>";

?>