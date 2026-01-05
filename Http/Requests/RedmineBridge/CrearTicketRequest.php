<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge;

use Illuminate\Foundation\Http\FormRequest;

final class CrearTicketRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'subject' => ['required', 'string'],
            'description' => ['required', 'string'],
            'prioridad' => ['required', 'string'],
            'categoria' => ['nullable', 'string'],
            'canal' => ['nullable', 'string'],
            'external_ticket_id' => ['nullable', 'string'],
            'cliente_ref' => ['nullable', 'string'],
            'custom_fields' => ['array'],
            'idempotency_key' => ['required', 'string'],
        ];
    }
}
