<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge;

use Illuminate\Foundation\Http\FormRequest;

final class CrearAdjuntoRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'filename' => ['required', 'string'],
            'mime' => ['required', 'string'],
            'content' => ['required', 'string'],
            'sha256' => ['nullable', 'string'],
            'external_attachment_id' => ['nullable', 'string'],
            'idempotency_key' => ['required', 'string'],
        ];
    }
}
