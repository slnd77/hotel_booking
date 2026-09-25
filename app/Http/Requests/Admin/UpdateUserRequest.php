<?php

namespace App\Http\Requests\Admin;

use App\Enums\UserStatus;
use App\Models\User;
use App\Services\PermissionCatalog;
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
     * @return array<string, array<int, mixed>>
     */
    public function rules(): array
    {
        $editedUser = $this->route('user');

        return [
            ...User::mobileRules(),
            'name' => ['required', 'string', 'max:255'],
            'email' => ['required', 'string', 'email', 'max:255', Rule::unique('users', 'email')->ignore($this->route('user'))],
            'password' => ['nullable', 'string', Password::defaults()],
            'status' => [
                'required', Rule::enum(UserStatus::class),
                // Users can't lock themselves out.
                Rule::when($editedUser instanceof User && $editedUser->is($this->user()), [Rule::in([UserStatus::Active->value])]),
            ],
            'role' => PermissionCatalog::canAssignRoles($this->user())
                ? ['required', 'string', Rule::in(array_keys(PermissionCatalog::assignableRoles($this->user())))]
                : ['prohibited'],
        ];
    }

    /**
     * @return array<string, string>
     */
    public function messages(): array
    {
        return ['status.in' => 'You can\'t make your own user inactive.'];
    }
}
