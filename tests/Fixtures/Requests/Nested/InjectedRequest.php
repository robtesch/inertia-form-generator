<?php

namespace RobTesch\InertiaFormGenerator\Tests\Fixtures\Requests\Nested;

use Illuminate\Contracts\Config\Repository;
use Illuminate\Foundation\Http\FormRequest;

class InjectedRequest extends FormRequest
{
    public function rules(Repository $config): array
    {
        return [
            'name' => $config->get('app.name') ? 'required|string' : 'nullable',
        ];
    }
}
