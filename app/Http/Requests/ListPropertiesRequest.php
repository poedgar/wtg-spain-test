<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ListPropertiesRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'city' => ['sometimes', 'string', 'max:255'],
            'check_in' => ['required', 'date_format:Y-m-d'],
            'check_out'=> ['required', 'date_format:Y-m-d', 'after:check_in'],
            'guests'   => ['required', 'integer', 'min:1'],
            'page'     => ['sometimes', 'integer', 'min:1'],
            'per_page' => ['sometimes', 'integer', 'min:1', 'max:100'],
        ];
    }
}