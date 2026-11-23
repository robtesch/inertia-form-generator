<?php

namespace RobTesch\InertiaFormGenerator\Tests\Fixtures\Requests;

class StorePostRequest extends BaseRequest
{
    public function rules(): array
    {
        return [
            'title' => 'required|string',
        ];
    }
}
