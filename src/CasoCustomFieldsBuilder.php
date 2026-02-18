<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

/**
 * Encapsula la lógica de negocio para construir los custom fields
 * obligatorios de Redmine según el tipo de caso (consulta/reclamo/solicitud).
 */
final class CasoCustomFieldsBuilder
{
    // ── Custom Field IDs (issue) ─────────────────────────────────
    public const CF_NUMERO_PEDIDO_ID = 11;
    public const CF_POSICIONES_ID = 12;
    public const CF_CREADO_POR_FAMIQ_ID = 13;
    public const CF_MOTIVO_CONSULTA_ID = 15;
    public const CF_MOTIVO_AVISO_PROACTIVO_ID = 16;
    public const CF_MOTIVO_RECLAMO_ID = 17;
    public const CF_MOTIVO_SOLICITUD_ID = 18;
    public const CF_FORMA_CONTACTO_ID = 19;
    public const CF_MOTIVO_RESOLUCION_ID = 20;

    // ── Valores por defecto ──────────────────────────────────────
    public const CF_FORMA_CONTACTO_VALUE = 'PIN';
    public const CF_MOTIVO_RESOLUCION_VALUE = 'Enviamos la información solicitada';
    public const CF_MOTIVO_SOLICITUD_VALUE = 'S70. Otras solicitudes';

    public const CF_CREADO_POR_FAMIQ_YES = 1;
    public const CF_CREADO_POR_FAMIQ_NO = 0;

    // ── Custom Field IDs (contacto) ──────────────────────────────
    public const CONTACT_CF_CLASE_CLIENTE_ID = 10;
    public const CONTACT_CF_CLASE_CLIENTE_DEFAULT = 'A';

    // ── IDs de custom fields de referencia de empresa en issues ──
    public const ISSUE_COMPANY_REF_CUSTOM_FIELD_IDS = [12, 13, 14];

    // ── Mapeo caso ID → tipo de ayuda (C/R/S) ───────────────────
    private const CASO_TIPO_AYUDA_MAP = [
        1 => 'C',
        2 => 'C',
        3 => 'C',
        4 => 'C',
        19 => 'C',
        20 => 'C',
        22 => 'C',
        23 => 'C',
        11 => 'R',
        12 => 'R',
        13 => 'R',
        14 => 'R',
        15 => 'R',
        16 => 'R',
        17 => 'R',
        18 => 'R',
        5 => 'S',
        6 => 'S',
        7 => 'S',
        8 => 'S',
        9 => 'S',
        10 => 'S',
        21 => 'S',
        24 => 'S',
        25 => 'S',
    ];

    // ── Mapeo caso ID → valor exacto que espera Redmine para el campo motivo ──
    private const CASO_MOTIVO_REDMINE_MAP = [
        '1'  => 'C10. Consultar el estado del pedido',
        '2'  => 'C20. Consultar datos del pedido (importe, entrega, etc.)',
        '3'  => 'S50. Solicitar documentación (facturas, remitos, NC, ND, certificados)',
        '4'  => 'C40. Otras consultas',
        '5'  => 'S11. Solicitar modificar la forma de entrega',
        '6'  => 'S12. Solicitar modificar el lugar de entrega',
        '7'  => 'S21. Solicitar modificar un producto',
        '8'  => 'S22. Solicitar modificar la cantidad de un producto',
        '9'  => 'S30. Solicitar devolución de posición o pedido',
        '10' => 'S40. Solicitar anulación de posición o pedido',
        '11' => 'R10. El cliente no recibió el pedido',
        '12' => 'R70. Ventas no generó el pedido',
        '13' => 'R30. Error en el material enviado',
        '14' => 'R40. Material deteriorado o con problemas de calidad',
        '15' => 'R60. El cliente tiene diferencias en la factura',
        '16' => 'R80. El cliente realizó el pago y no lo ve imputado',
        '17' => 'R50. Inconvenientes con el retiro del pedido',
        '18' => 'R90. Otros reclamos',
        '19' => 'C30. Consultar el saldo de la cuenta',
        '20' => 'C40. Otras consultas',
        '21' => 'S60. Solicitar modificación de datos del cliente',
        '22' => 'S70. Otras solicitudes',
        '23' => 'C40. Otras consultas',
        '24' => 'S54. Solicitar descuento de percepciones sobre una factura',
        '25' => 'S54. Solicitar descuento de percepciones sobre una factura',
    ];

    // ── Enumeraciones: texto → ID numérico de Redmine ────────────
    private const ENUM_FORMA_CONTACTO = [
        'PIN' => '93',
        'Llamada telefónica' => '94',
        'Email' => '95',
        'Acerin' => '96',
        'Internet' => '97',
        'Visita' => '98',
        'Mostrador' => '99',
        'Vendedor' => '100',
        'WhatsApp' => '101',
    ];

    private const ENUM_MOTIVO_CONSULTA_BY_CODE = [
        'C10' => '8',
        'C11' => '9',
        'C12' => '10',
        'C20' => '11',
        'C30' => '12',
        'C40' => '13',
        'C41' => '14',
        'C42' => '15',
        'C43' => '16',
        'C45' => '17',
    ];

    private const ENUM_MOTIVO_RECLAMO_BY_CODE = [
        'R10' => '22',
        'R11' => '23',
        'R12' => '24',
        'R13' => '25',
        'R14' => '26',
        'R15' => '27',
        'R16' => '28',
        'R17' => '29',
        'R18' => '30',
        'R19' => '31',
        'R21' => '32',
        'R22' => '33',
        'R23' => '34',
        'R24' => '35',
        'R25' => '36',
        'R26' => '37',
        'R27' => '38',
        'R28' => '39',
        'R29' => '40',
        'R30' => '41',
        'R31' => '42',
        'R32' => '43',
        'R33' => '44',
        'R34' => '45',
        'R35' => '46',
        'R36' => '47',
        'R37' => '48',
        'R40' => '49',
        'R41' => '50',
        'R42' => '51',
        'R43' => '52',
        'R50' => '53',
        'R60' => '54',
        'R61' => '55',
        'R62' => '56',
        'R63' => '57',
        'R64' => '58',
        'R65' => '59',
        'R67' => '60',
        'R68' => '61',
        'R69' => '62',
        'R70' => '63',
        'R71' => '64',
        'R72' => '65',
        'R80' => '66',
        'R81' => '67',
        'R82' => '68',
        'R90' => '69',
    ];

    private const ENUM_MOTIVO_SOLICITUD_BY_CODE = [
        'S10' => '70',
        'S11' => '71',
        'S12' => '72',
        'S20' => '73',
        'S21' => '74',
        'S22' => '75',
        'S30' => '76',
        'S40' => '77',
        'S50' => '79',
        'S51' => '80',
        'S52' => '81',
        'S53' => '82',
        'S54' => '83',
        'S60' => '84',
        'S70' => '85',
        'S72' => '86',
        'X' => '87',
        'X10' => '88',
        'X11' => '89',
        'X12' => '90',
        'X13' => '91',
        'X20' => '92',
    ];

    private const ENUM_MOTIVO_RESOLUCION = [
        'Anulamos el pedido' => '102',
        'Coordinamos la inspección' => '103',
        'Enviamos la documentación solicitada' => '104',
        'Enviamos la información solicitada' => '105',
        'Gestionamos la devolución' => '106',
        'Gestionamos la nota de crédito' => '107',
        'Informamos el estado del pedido al cliente' => '108',
        'Infor el est del pedido, gestionando con el área corresp' => '109',
        'Ingresamos los datos solicitados' => '110',
        'Negociamos la resolución del problema con el cliente' => '111',
        'Reprogramamos la devolución' => '112',
        'Reprogramamos la entrega' => '113',
    ];

    // ── IDs permitidos para sanitización ─────────────────────────
    private const ALLOWED_SMALL_IDS = [
        self::CF_FORMA_CONTACTO_ID,
        self::CF_MOTIVO_RESOLUCION_ID,
        self::CF_MOTIVO_CONSULTA_ID,
        self::CF_MOTIVO_RECLAMO_ID,
        self::CF_MOTIVO_SOLICITUD_ID,
        self::CF_NUMERO_PEDIDO_ID,
        self::CF_POSICIONES_ID,
        self::CF_CREADO_POR_FAMIQ_ID,
    ];

    /**
     * Construye los custom fields obligatorios para un caso, listos para enviar a Redmine.
     *
     * @return array<int, array{id: int, value: mixed}>
     */
    public function buildForCaso(
        ?int $casoId,
        ?string $tipoAyuda,
        bool $esFamiq,
        ?string $numeroPedido = null,
        ?string $posiciones = null,
    ): array {
        // Inferir tipo de ayuda desde el caso si es posible
        if ($casoId !== null) {
            $tipoInferido = $this->inferirTipoAyuda($casoId);
            if ($tipoInferido !== null) {
                $tipoAyuda = $tipoInferido;
            }
        }

        // Resolver motivo
        $motivoRedmine = $casoId !== null ? $this->obtenerMotivoRedminePorCaso($casoId) : null;
        $motivoCfId = $this->obtenerCustomFieldIdMotivo((string) $tipoAyuda);

        // Construir array base
        $customFields = [
            ['id' => self::CF_FORMA_CONTACTO_ID, 'value' => self::CF_FORMA_CONTACTO_VALUE],
            ['id' => $motivoCfId, 'value' => $motivoRedmine],
            ['id' => self::CF_MOTIVO_RESOLUCION_ID, 'value' => self::CF_MOTIVO_RESOLUCION_VALUE],
            ['id' => self::CF_CREADO_POR_FAMIQ_ID, 'value' => $esFamiq ? self::CF_CREADO_POR_FAMIQ_YES : self::CF_CREADO_POR_FAMIQ_NO],
        ];

        if ($numeroPedido !== null) {
            $customFields[] = ['id' => self::CF_NUMERO_PEDIDO_ID, 'value' => $numeroPedido];
        }
        if ($posiciones !== null) {
            $customFields[] = ['id' => self::CF_POSICIONES_ID, 'value' => $posiciones];
        }

        // Convertir enumeraciones (texto → ID numérico)
        $customFields = array_map(function ($cf) {
            $id = (int) ($cf['id'] ?? 0);
            if ($id <= 0 || !array_key_exists('value', $cf)) {
                return $cf;
            }
            $cf['value'] = $this->mapEnumerationValue($id, $cf['value']);
            return $cf;
        }, $customFields);

        // Filtrar campos vacíos/nulos/con id inválido
        $customFields = array_values(array_filter(
            $customFields,
            static fn($cf) => isset($cf['id']) && (int) $cf['id'] > 0
                && array_key_exists('value', $cf)
                && $cf['value'] !== null
                && !(is_string($cf['value']) && trim($cf['value']) === '')
        ));

        // Sanitizar
        return $this->sanitizeCustomFields($customFields, $tipoAyuda);
    }

    /**
     * Infiere el tipo de ayuda (C/R/S) a partir del ID del caso.
     */
    public function inferirTipoAyuda(int $casoId): ?string
    {
        return self::CASO_TIPO_AYUDA_MAP[$casoId] ?? null;
    }

    /**
     * Mapea tipo de ayuda (C/R/S) al tracker ID de Redmine.
     */
    public function obtenerTrackerId(string $tipoAyuda): int
    {
        return match ($tipoAyuda) {
            'C' => 1,
            'R' => 3,
            'S' => 6,
            default => 2,
        };
    }

    /**
     * Obtiene el texto del motivo Redmine por ID de caso.
     */
    public function obtenerMotivoRedminePorCaso(int $casoId): ?string
    {
        return self::CASO_MOTIVO_REDMINE_MAP[(string) $casoId] ?? null;
    }

    public function getContactCfClaseClienteId(): int
    {
        return self::CONTACT_CF_CLASE_CLIENTE_ID;
    }

    public function getContactCfClaseClienteDefault(): string
    {
        return self::CONTACT_CF_CLASE_CLIENTE_DEFAULT;
    }

    // ── Privados ─────────────────────────────────────────────────

    /**
     * Devuelve el ID del custom field de motivo según el tipo de ayuda.
     */
    private function obtenerCustomFieldIdMotivo(string $tipoAyuda): ?int
    {
        return match ($tipoAyuda) {
            'C' => self::CF_MOTIVO_CONSULTA_ID,
            'R' => self::CF_MOTIVO_RECLAMO_ID,
            'S' => self::CF_MOTIVO_SOLICITUD_ID,
            default => null,
        };
    }

    /**
     * Extrae el código de motivo (ej. "C10", "R30") de un texto.
     */
    private function extractMotivoCode(?string $text): ?string
    {
        if (!is_string($text)) {
            return null;
        }

        $t = trim($text);
        if ($t === '') {
            return null;
        }

        if (preg_match('/^([A-Z])\s*([0-9]{1,2})?(\.)?/u', $t, $m)) {
            $letter = $m[1] ?? null;
            $num = $m[2] ?? '';
            if (!$letter) {
                return null;
            }
            return $letter . $num;
        }

        return null;
    }

    /**
     * Convierte el valor de un custom field enumeration de texto a su ID numérico.
     */
    private function mapEnumerationValue(int $cfId, mixed $value): mixed
    {
        if ($value === null) {
            return null;
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_string($value) && preg_match('/^\d+$/', trim($value))) {
            return trim($value);
        }

        $v = is_string($value) ? trim($value) : (string) $value;

        return match ($cfId) {
            self::CF_FORMA_CONTACTO_ID => self::ENUM_FORMA_CONTACTO[$v] ?? null,

            self::CF_MOTIVO_RESOLUCION_ID => self::ENUM_MOTIVO_RESOLUCION[$v] ?? null,

            self::CF_MOTIVO_CONSULTA_ID => (function () use ($v) {
                $code = $this->extractMotivoCode($v);
                return $code ? (self::ENUM_MOTIVO_CONSULTA_BY_CODE[$code] ?? null) : null;
            })(),

            self::CF_MOTIVO_RECLAMO_ID => (function () use ($v) {
                $code = $this->extractMotivoCode($v);
                return $code ? (self::ENUM_MOTIVO_RECLAMO_BY_CODE[$code] ?? null) : null;
            })(),

            self::CF_MOTIVO_SOLICITUD_ID => (function () use ($v) {
                $code = $this->extractMotivoCode($v);
                return $code ? (self::ENUM_MOTIVO_SOLICITUD_BY_CODE[$code] ?? null) : null;
            })(),

            default => $value,
        };
    }

    /**
     * Sanitiza los custom fields: filtra IDs no permitidos y valores inválidos.
     *
     * @param array<int, array{id: int, value: mixed}> $customFields
     * @return array<int, array{id: int, value: mixed}>
     */
    private function sanitizeCustomFields(array $customFields, ?string $tipoDeAyuda = null): array
    {
        $out = [];
        foreach ($customFields as $cf) {
            $id = (int) ($cf['id'] ?? 0);
            $value = $cf['value'] ?? null;

            if (is_array($value)) {
                $value = implode(', ', array_map('strval', $value));
            }

            if ($id <= 0) {
                continue;
            }
            if ($value === null) {
                continue;
            }
            if (is_string($value) && trim($value) === '') {
                continue;
            }

            if ($id <= 20 && !in_array($id, self::ALLOWED_SMALL_IDS, true)) {
                continue;
            }

            $out[] = ['id' => $id, 'value' => $value];
        }

        return array_values($out);
    }
}
