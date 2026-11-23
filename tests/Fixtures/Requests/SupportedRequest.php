<?php

namespace RobTesch\InertiaFormGenerator\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

final class SupportedRequestHelper
{
    public const string FIELD = 'label';
}

class SupportedRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            SupportedRequestHelper::FIELD => 'required|string',
        ];
    }
}
