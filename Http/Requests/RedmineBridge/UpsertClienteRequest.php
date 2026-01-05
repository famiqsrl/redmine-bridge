<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge;

use Illuminate\Foundation\Http\FormRequest;

final class UpsertClienteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'tipo' => ['required', 'in:empresa,persona'],
            'razon_social' => ['nullable', 'string'],
            'nombre' => ['nullable', 'string'],
            'apellido' => ['nullable', 'string'],
            'cuit' => ['nullable', 'string'],
            'emails' => ['array'],
            'emails.*' => ['string', 'email'],
            'telefonos' => ['array'],
            'telefonos.*' => ['string'],
            'direccion' => ['nullable', 'string'],
            'external_id' => ['nullable', 'string'],
            'source_system' => ['required', 'string'],
        ];
    }
}
