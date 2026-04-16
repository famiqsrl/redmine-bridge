<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge\Contacts;

use Famiq\RedmineBridge\DTO\ContactDTO;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;

/**
 * Busqueda directa contra la base de datos de Redmine (MySQL) para resolver
 * el contacto de una empresa a partir de su SAP number.
 *
 * NOTA: las credenciales estan hardcodeadas a pedido del equipo. Mover a
 * variables de entorno cuando sea posible.
 */
final class DatabaseContactResolver
{
    private const HOST = '10.1.43.246';
    private const DATABASE = 'redmine';
    private const USERNAME = 'famiqdos';
    private const PASSWORD = 'Famiq2022*';
    private const PORT = 3306;

    private ?\PDO $pdo = null;
    private bool $connectionFailed = false;

    public function __construct(
        private LoggerInterface $logger = new NullLogger(),
    ) {
    }

    /**
     * Busca en la tabla `contacts` un contacto cuyo `sap_number` coincida con
     * el valor dado. Devuelve un ContactDTO con email utilizable o null si
     * no se encuentra nada apto.
     */
    public function buscarContactoEmpresaPorSapNumber(string $sapNumber): ?ContactDTO
    {
        $sapNumber = trim($sapNumber);
        if ($sapNumber === '') {
            return null;
        }

        $pdo = $this->connect();
        if ($pdo === null) {
            return null;
        }

        try {
            $sql = 'SELECT id, first_name, last_name, company, is_company, sap_number '
                . 'FROM contacts '
                . 'WHERE sap_number = :sap '
                . 'ORDER BY is_company DESC, id ASC '
                . 'LIMIT 25';

            $stmt = $pdo->prepare($sql);
            $stmt->execute(['sap' => $sapNumber]);

            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (!is_array($row)) {
                    continue;
                }

                $contactId = isset($row['id']) ? (int) $row['id'] : 0;
                if ($contactId <= 0) {
                    continue;
                }

                $email = $this->buscarEmailUtilDeContacto($pdo, $contactId);
                if ($email === null) {
                    continue;
                }

                $firstName = $this->normalizeString($row['first_name'] ?? null);
                $lastName = $this->normalizeString($row['last_name'] ?? null);
                $company = $this->normalizeString($row['company'] ?? null);

                if (($firstName === null || $firstName === '') && $company !== null) {
                    $firstName = $company;
                }

                $this->logger->info('redmine.bridge.db.contact_found', [
                    'sap_number' => $sapNumber,
                    'contact_id' => $contactId,
                    'email' => $email,
                ]);

                return new ContactDTO(
                    email: $email,
                    firstName: $firstName,
                    lastName: $lastName,
                    id: $contactId,
                );
            }
        } catch (\Throwable $e) {
            $this->logger->warning('redmine.bridge.db.lookup_failed', [
                'sap_number' => $sapNumber,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        $this->logger->info('redmine.bridge.db.contact_not_found', [
            'sap_number' => $sapNumber,
        ]);

        return null;
    }

    /**
     * Intenta obtener un email utilizable del contacto. Prueba primero la
     * tabla `contacts_emails` (versiones nuevas de RedmineUP CRM) y cae a
     * la columna `contacts.email` en caso de no existir.
     */
    private function buscarEmailUtilDeContacto(\PDO $pdo, int $contactId): ?string
    {
        // 1) Tabla contacts_emails (uno-a-muchos, con is_default).
        try {
            $stmt = $pdo->prepare(
                'SELECT address FROM contacts_emails '
                . 'WHERE contact_id = :cid '
                . 'ORDER BY is_default DESC, id ASC'
            );
            $stmt->execute(['cid' => $contactId]);
            while (($row = $stmt->fetch(\PDO::FETCH_ASSOC)) !== false) {
                if (!is_array($row)) {
                    continue;
                }
                $address = $this->normalizeString($row['address'] ?? null);
                if ($address !== null) {
                    return $address;
                }
            }
        } catch (\Throwable $e) {
            // La tabla puede no existir en instalaciones viejas; se ignora.
            $this->logger->debug('redmine.bridge.db.contacts_emails_lookup_failed', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
        }

        // 2) Columna contacts.email directa.
        try {
            $stmt = $pdo->prepare('SELECT email FROM contacts WHERE id = :id LIMIT 1');
            $stmt->execute(['id' => $contactId]);
            $row = $stmt->fetch(\PDO::FETCH_ASSOC);
            if (is_array($row)) {
                $address = $this->normalizeString($row['email'] ?? null);
                if ($address !== null) {
                    return $address;
                }
            }
        } catch (\Throwable $e) {
            $this->logger->debug('redmine.bridge.db.contacts_email_column_lookup_failed', [
                'contact_id' => $contactId,
                'error' => $e->getMessage(),
            ]);
        }

        return null;
    }

    private function normalizeString(mixed $value): ?string
    {
        if (!is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);
        return $trimmed === '' ? null : $trimmed;
    }

    private function connect(): ?\PDO
    {
        if ($this->pdo !== null) {
            return $this->pdo;
        }
        if ($this->connectionFailed) {
            return null;
        }

        $dsn = sprintf(
            'mysql:host=%s;port=%d;dbname=%s;charset=utf8mb4',
            self::HOST,
            self::PORT,
            self::DATABASE,
        );

        try {
            $this->pdo = new \PDO($dsn, self::USERNAME, self::PASSWORD, [
                \PDO::ATTR_ERRMODE => \PDO::ERRMODE_EXCEPTION,
                \PDO::ATTR_TIMEOUT => 5,
                \PDO::ATTR_DEFAULT_FETCH_MODE => \PDO::FETCH_ASSOC,
                \PDO::ATTR_EMULATE_PREPARES => false,
            ]);
        } catch (\Throwable $e) {
            $this->connectionFailed = true;
            $this->logger->error('redmine.bridge.db.connect_failed', [
                'host' => self::HOST,
                'database' => self::DATABASE,
                'error' => $e->getMessage(),
            ]);
            return null;
        }

        return $this->pdo;
    }
}
