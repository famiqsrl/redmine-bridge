<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge;

use Illuminate\Foundation\Http\FormRequest;

final class CrearMensajeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'body' => ['required', 'string'],
            'visibility' => ['required', 'in:internal,public'],
            'author_ref' => ['nullable', 'string'],
        ];
    }
}
