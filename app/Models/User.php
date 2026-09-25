<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Enums\TwoFactorMethod;
use App\Enums\UserStatus;
use Database\Factories\UserFactory;
use Illuminate\Database\Eloquent\Attributes\Fillable;
use Illuminate\Database\Eloquent\Attributes\Hidden;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;
use Laravel\Fortify\Contracts\PasskeyUser;
use Laravel\Fortify\PasskeyAuthenticatable;
use Laravel\Fortify\TwoFactorAuthenticatable;
use Laravel\Sanctum\HasApiTokens;
use Laravel\Sanctum\PersonalAccessToken;
use Laravel\Sanctum\TransientToken;
use Spatie\Permission\Traits\HasRoles;

/**
 * @property int $id
 * @property string $name
 * @property string $email
 * @property string|null $mobile_country_code
 * @property string|null $mobile
 * @property Carbon|null $email_verified_at
 * @property string $password
 * @property UserStatus $status
 * @property string|null $remember_token
 * @property string|null $two_factor_secret
 * @property string|null $two_factor_recovery_codes
 * @property Carbon|null $two_factor_confirmed_at
 * @property list<string>|null $two_factor_channels
 * @property array<string, array<string, bool>>|null $list_views
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
#[Fillable(['name', 'email', 'mobile_country_code', 'mobile', 'password', 'status'])]
#[Hidden(['password', 'remember_token', 'two_factor_secret', 'two_factor_recovery_codes', 'list_views'])]
class User extends Authenticatable implements PasskeyUser
{
    /** @use HasApiTokens<PersonalAccessToken|TransientToken> */
    use HasApiTokens;

    /** @use HasFactory<UserFactory> */
    use HasFactory, HasRoles, Notifiable, PasskeyAuthenticatable, TwoFactorAuthenticatable;

    protected static function booted(): void
    {
        // A new mobile number hasn't received a code yet: its SMS / WhatsApp sign-in stops.
        static::updating(function (User $user): void {
            if ($user->isDirty(['mobile_country_code', 'mobile'])) {
                $user->two_factor_channels = array_values(array_diff($user->two_factor_channels ?? [], [TwoFactorMethod::Sms->value, TwoFactorMethod::WhatsApp->value]));
            }
        });
    }

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'status' => UserStatus::class,
            'two_factor_confirmed_at' => 'datetime',
            'two_factor_channels' => 'array',
            'list_views' => 'array',
        ];
    }

    /**
     * Inactive users can't sign in.
     */
    public function isActive(): bool
    {
        return $this->status !== UserStatus::Inactive;
    }

    /**
     * Validation for the mobile number fields (user form and profile).
     *
     * @return array<string, list<string>>
     */
    public static function mobileRules(): array
    {
        return [
            'mobile_country_code' => ['nullable', 'required_with:mobile', 'string', 'regex:/^\+?[0-9]{1,4}$/'],
            'mobile' => ['nullable', 'string', 'regex:/^[0-9 ()-]{4,20}$/'],
        ];
    }

    /**
     * The mobile number fields as stored: the code with its "+", the number without spaces.
     *
     * @param  array<string, mixed>  $input
     * @return array{mobile_country_code: string|null, mobile: string|null}
     */
    public static function mobileAttributes(array $input): array
    {
        $mobile = preg_replace('/\D+/', '', (string) ($input['mobile'] ?? ''));

        return [
            'mobile_country_code' => $mobile === '' ? null : '+'.ltrim((string) $input['mobile_country_code'], '+'),
            'mobile' => $mobile === '' ? null : $mobile,
        ];
    }

    /**
     * The mobile number with its country code (e.g. "+919812345678"), or null without one.
     */
    public function mobileNumber(): ?string
    {
        $digits = preg_replace('/\D+/', '', (string) $this->mobile);

        return $digits === '' ? null : '+'.ltrim((string) $this->mobile_country_code, '+').$digits;
    }

    /**
     * The two-factor methods the user turned on, in the order they are offered at login.
     *
     * @return list<TwoFactorMethod>
     */
    public function twoFactorMethods(): array
    {
        return array_values(array_filter(TwoFactorMethod::cases(), fn (TwoFactorMethod $method): bool => $this->hasTwoFactorMethod($method)));
    }

    public function hasTwoFactorMethod(TwoFactorMethod $method): bool
    {
        return match ($method) {
            TwoFactorMethod::Passkey => $this->hasPasskeysEnabled(),
            TwoFactorMethod::Totp => $this->two_factor_secret !== null && $this->two_factor_confirmed_at !== null,
            default => in_array($method->value, $this->two_factor_channels ?? [], true)
                && (! $method->needsMobile() || $this->mobileNumber() !== null),
        };
    }
}
