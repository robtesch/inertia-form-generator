<?php

namespace RobTesch\InertiaFormGenerator\Tests\Fixtures\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RouteDependentRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'title' => 'unique:posts,title,'.$this->route('post')->id,
        ];
    }
}
