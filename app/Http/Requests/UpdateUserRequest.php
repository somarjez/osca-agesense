<?php

namespace App\Http\Requests;

use App\Models\User;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class UpdateUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * Editing happens in a per-row modal on the users index page, so a fixed
     * error bag would leak one row's validation errors onto every other
     * row's form. FormRequest::$errorBag is just a plain property read by
     * failedValidation() at throw time, so it can be set dynamically here
     * from the bound {user} route parameter — same bag name the inline
     * validateWithBag("editUser{$user->id}", ...) call used before this
     * extraction.
     */
    protected function prepareForValidation(): void
    {
        /** @var User $user */
        $user = $this->route('user');
        $this->errorBag = "editUser{$user->id}";
    }

    public function rules(): array
    {
        /** @var User $user */
        $user = $this->route('user');

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'email:filter', 'max:255', Rule::unique('users', 'email')->ignore($user->id)],
            'password' => ['nullable', Password::min(8)->mixedCase()->numbers()->symbols(), 'confirmed'],
            'role' => ['required', Rule::in(['admin', 'encoder', 'viewer'])],
        ];
    }
}
