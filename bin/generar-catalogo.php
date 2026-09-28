<?php

/**
 * Genera `src/Errors/Catalogo.php`: la categoría de cada código de error de la
 * API, a partir de los vectores compartidos (`tests/vectors/errores.json`), que
 * el CI compara con los de la última versión publicada de
 * @lacasoft/coatipay-protocol.
 *
 * No se edita a mano: los tests de vectores fallan si un código del catálogo
 * no está, o si su clase no es la que dicen los vectores.
 *
 * Uso: php bin/generar-catalogo.php
 */

declare(strict_types=1);

$raiz = dirname(__DIR__);
$codigos = json_decode(
    (string) file_get_contents("{$raiz}/tests/vectors/errores.json"),
    true,
    512,
    JSON_THROW_ON_ERROR,
)['codigos'];
ksort($codigos);

$lineas = [];
foreach ($codigos as $codigo => $d) {
    $lineas[] = "        '{$codigo}' => '{$d['category']}',";
}
$lista = implode("\n", $lineas);

file_put_contents("{$raiz}/src/Errors/Catalogo.php", <<<PHP
<?php

declare(strict_types=1);

namespace CoatiPay\\Errors;

/**
 * Categoría de cada código de error de la API de CoatiPay.
 *
 * GENERADO por bin/generar-catalogo.php desde los vectores compartidos
 * (tests/vectors/errores.json). No editar a mano.
 */
final class Catalogo
{
    public const CATEGORIAS = [
{$lista}
    ];
}

PHP);
echo '✓ src/Errors/Catalogo.php: ' . count($codigos) . " códigos\n";
