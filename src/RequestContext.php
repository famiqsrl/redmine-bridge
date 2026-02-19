<?php

declare(strict_types=1);

namespace Famiq\RedmineBridge;

final class RequestContext
{
    public string $correlationId;

    /**
     * Login/identificador del usuario para X-Redmine-Switch-User.
     * (En Redmine suele ser el "login", no el email completo)
     */
    public ?string $idUsuario;

    public ?string $emailUsuario;
    public ?string $nombreUsuario;
    public ?string $apellidoUsuario;

    /**
     * @deprecated Usar $idUsuario. Se mantiene por compat con callers que hacen:
     *           new RequestContext(correlationId: ..., switchUser: ...)
     */
    public ?string $switchUser;

    public function __construct(
        string $correlationId,
        ?string $idUsuario = null,
        ?string $emailUsuario = null,
        ?string $nombreUsuario = null,
        ?string $apellidoUsuario = null,
        ?string $switchUser = null,
    ) {
        $this->correlationId = $correlationId;

        // Compat:
        // - llamadas viejas: new RequestContext($trace, $login) => $idUsuario OK
        // - llamadas nuevas: new RequestContext(correlationId: ..., switchUser: ...) => lo mapeamos a idUsuario
        $this->idUsuario = $idUsuario ?? $switchUser;

        $this->switchUser = $switchUser ?? $idUsuario;

        $this->emailUsuario = $emailUsuario;
        $this->nombreUsuario = $nombreUsuario;
        $this->apellidoUsuario = $apellidoUsuario;
    }

    public static function generate(): self
    {
        try {
            $cid = bin2hex(random_bytes(16));
        } catch (\Throwable) {
            $cid = uniqid('cid_', true);
        }

        return new self($cid);
    }
}
