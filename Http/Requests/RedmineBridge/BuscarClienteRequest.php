<?php

declare(strict_types=1);

namespace Famiq\ActiveDirectoryUser\Http\Requests\RedmineBridge;

use Illuminate\Foundation\Http\FormRequest;

final class BuscarClienteRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'query' => ['required', 'string'],
            'external_id' => ['nullable', 'string'],
        ];
    }
}
