<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\PermissionCatalog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Password;

class StoreUserRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    /**
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        return [
            ...User::mobileRules(),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')],
            'password' => ['required', 'string', Password::defaults()],
            'status' => ['required', Rule::enum(UserStatus::class)],
            'role' => PermissionCatalog::canAssignRoles($this->user())
                ? ['required', 'string', Rule::in(array_keys(PermissionCatalog::assignableRoles($this->user())))]
                : ['prohibited'],
        ];
    }
}
