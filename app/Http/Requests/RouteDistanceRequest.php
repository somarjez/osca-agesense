<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class RouteDistanceRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'origin_lat' => ['required', 'numeric', 'between:-90,90'],
            'origin_lng' => ['required', 'numeric', 'between:-180,180'],
            'destination_lat' => ['required', 'numeric', 'between:-90,90'],
            'destination_lng' => ['required', 'numeric', 'between:-180,180'],
            'senior_id' => ['nullable', 'integer', 'exists:senior_citizens,id'],
            'facility_id' => ['nullable', 'integer', 'exists:facilities,id'],
        ];
    }
}
