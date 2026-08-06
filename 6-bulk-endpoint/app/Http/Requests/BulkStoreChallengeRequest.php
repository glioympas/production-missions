<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class BulkStoreChallengeRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'items' => ['required', 'array', 'min:1', 'max:100'],

            'items.*.title' => ['required', 'string', 'max:255'],
            'items.*.points' => ['required', 'integer', 'min:0'],
        ];
    }
}
