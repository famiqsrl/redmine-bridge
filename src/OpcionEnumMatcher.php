<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

/**
 * Resuelve el id de submit de una opción de un custom field de enumeración de Redmine,
 * a partir de los possible_values que devuelve la API (/custom_fields.json).
 *
 * Estrategia de matcheo (en orden):
 *  1. Match exacto (case-insensitive) del texto buscado contra el texto de cada opción.
 *  2. Si el texto buscado tiene un código de motivo (letra + dígitos, ej. C10/R30/S54),
 *     matchea por ese prefijo de código contra el texto de cada opción.
 *
 * Cada possible_value puede venir como string suelto o como array con claves entre
 * {id, value, label, name}. El id a enviar a Redmine = 'id' si existe, si no 'value';
 * el texto a matchear = 'label' / 'name' / 'value'.
 */
final class OpcionEnumMatcher
{
    /**
     * @param array<int, mixed> $possibleValues
     */
    public static function resolver(array $possibleValues, string $buscado): ?string
    {
        $buscado = trim($buscado);
        if ($buscado === '') {
            return null;
        }

        $opciones = self::normalizar($possibleValues);

        // 1. Match exacto por texto (case-insensitive).
        $buscadoLower = self::lower($buscado);
        foreach ($opciones as $op) {
            if (self::lower($op['texto']) === $buscadoLower) {
                return $op['id'];
            }
        }

        // 2. Match por código de motivo (C10, R30, S54, ...).
        $codigo = self::extraerCodigo($buscado);
        if ($codigo !== null) {
            foreach ($opciones as $op) {
                if (self::extraerCodigo($op['texto']) === $codigo) {
                    return $op['id'];
                }
            }
        }

        return null;
    }

    /**
     * @param array<int, mixed> $possibleValues
     * @return array<int, array{id: string, texto: string}>
     */
    private static function normalizar(array $possibleValues): array
    {
        $out = [];

        foreach ($possibleValues as $pv) {
            if (is_string($pv) || is_numeric($pv)) {
                $val = (string) $pv;
                $out[] = ['id' => $val, 'texto' => $val];
                continue;
            }

            if (!is_array($pv)) {
                continue;
            }

            $id = $pv['id'] ?? $pv['value'] ?? null;
            $texto = $pv['label'] ?? $pv['name'] ?? $pv['value'] ?? $id;

            if ($id === null || $texto === null) {
                continue;
            }

            $out[] = ['id' => (string) $id, 'texto' => (string) $texto];
        }

        return $out;
    }

    private static function extraerCodigo(string $text): ?string
    {
        $t = trim($text);
        if ($t === '') {
            return null;
        }

        // Código de motivo: una letra seguida de al menos un dígito (C10, R30, S54).
        if (preg_match('/^([A-Z])\s*([0-9]{1,2})/u', strtoupper($t), $m)) {
            return $m[1] . $m[2];
        }

        return null;
    }

    private static function lower(string $s): string
    {
        $s = trim($s);

        return function_exists('mb_strtolower') ? mb_strtolower($s) : strtolower($s);
    }
}
