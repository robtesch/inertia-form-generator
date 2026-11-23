<?php

namespace RobTesch\InertiaFormGenerator\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

abstract class BaseRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }
}
