<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\Http\Controllers\RedmineBridge;

use Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge\BuscarClienteRequest;
use Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge\CrearAdjuntoRequest;
use Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge\CrearMensajeRequest;
use Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge\CrearTicketRequest;
use Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge\UpsertClienteRequest;
use Famiq\ActiveDirectoryUser\RedmineBridge\RedmineBridgeFacade;
use Famiq\RedmineBridge\Contracts\DTO\AdjuntoDTO;
use Famiq\RedmineBridge\Contracts\DTO\ClienteDTO;
use Famiq\RedmineBridge\Contracts\DTO\TicketDTO;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;

final class RedmineBridgeController extends Controller
{
    public function __construct(private readonly RedmineBridgeFacade $facade)
    {
    }

    public function buscarCliente(BuscarClienteRequest $request): JsonResponse
    {
        $result = $this->facade->buscarCliente(
            $request->string('query')->toString(),
            $request->string('external_id')->toString(),
            $request->header('X-Correlation-Id'),
        );

        return response()->json($result);
    }

    public function upsertCliente(UpsertClienteRequest $request): JsonResponse
    {
        $cliente = new ClienteDTO(
            $request->string('tipo')->toString(),
            $request->string('razon_social')->toString(),
            $request->string('nombre')->toString(),
            $request->string('apellido')->toString(),
            $request->string('cuit')->toString(),
            $request->input('emails', []),
            $request->input('telefonos', []),
            $request->string('direccion')->toString(),
            $request->string('external_id')->toString(),
            $request->string('source_system')->toString(),
        );

        $result = $this->facade->upsertCliente($cliente, $request->header('X-Correlation-Id'));

        return response()->json($result);
    }

    public function crearTicket(CrearTicketRequest $request): JsonResponse
    {
        $ticket = new TicketDTO(
            $request->string('subject')->toString(),
            $request->string('description')->toString(),
            $request->string('prioridad')->toString(),
            $request->string('categoria')->toString(),
            $request->string('canal')->toString(),
            $request->string('external_ticket_id')->toString(),
            $request->string('cliente_ref')->toString(),
            $request->input('custom_fields', []),
        );

        $result = $this->facade->crearTicket(
            $ticket,
            $request->string('idempotency_key')->toString(),
            $request->header('X-Correlation-Id'),
        );

        return response()->json($result);
    }

    public function listarTickets(Request $request): JsonResponse
    {
        $result = $this->facade->listarTickets(
            $request->query('status'),
            $request->integer('page'),
            $request->integer('per_page'),
            $request->query('cliente_ref'),
            $request->header('X-Correlation-Id'),
        );

        return response()->json($result);
    }

    public function crearMensaje(int $id, CrearMensajeRequest $request): JsonResponse
    {
        $result = $this->facade->crearMensaje(
            $id,
            $request->string('body')->toString(),
            $request->string('visibility')->toString(),
            $request->string('author_ref')->toString(),
            $request->header('X-Correlation-Id'),
        );

        return response()->json($result);
    }

    public function crearAdjunto(int $id, CrearAdjuntoRequest $request): JsonResponse
    {
        $adjunto = new AdjuntoDTO(
            $id,
            $request->string('filename')->toString(),
            $request->string('mime')->toString(),
            $request->string('content')->toString(),
            $request->string('sha256')->toString(),
            $request->string('external_attachment_id')->toString(),
        );

        $result = $this->facade->crearAdjunto(
            $adjunto,
            $request->string('idempotency_key')->toString(),
            $request->header('X-Correlation-Id'),
        );

        return response()->json($result);
    }
}
