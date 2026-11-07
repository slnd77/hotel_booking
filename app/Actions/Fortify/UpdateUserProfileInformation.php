<?php

namespace App\Actions\Fortify;

use App\Models\User;
use Illuminate\Support\Facades\Validator;
use Laravel\Fortify\Contracts\UpdatesUserProfileInformation;

/**
 * Profile settings let users change their own name and mobile number (where SMS and WhatsApp
 * codes go); the e-mail address is their login and is managed by an administrator.
 */
class UpdateUserProfileInformation implements UpdatesUserProfileInformation
{
    /**
     * Validate and update the given user's profile information.
     *
     * @param  array<string, mixed>  $input
     */
    public function update(User $user, array $input): void
    {
        Validator::make($input, [
            'name' => ['required', 'string', 'max:255'],
            ...User::mobileRules(),
        ])->validateWithBag('updateProfileInformation');

        $user->forceFill([
            'name' => trim($input['name']),
            ...User::mobileAttributes($input),
        ])->save();
    }
}
