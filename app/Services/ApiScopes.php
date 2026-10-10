<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Str;
use Laravel\Sanctum\PersonalAccessToken;

/**
 * Scopes for the JSON API (`/api/v1`). Each API route needs one scope; each scope stands for
 * the panel permissions a user needs to use it. A request passes when its token holds the
 * scope AND its user holds the permission right now, so an API key never does more than its
 * owner's role allows, today.
 *
 * Signed-in mobile devices get every scope ("*"); keys made under Profile → Developer get the
 * scopes chosen there.
 */
final class ApiScopes
{
    /** Token name prefixes: the mobile app's device logins and Developer API keys. */
    public const DEVICE_PREFIX = 'app:';

    public const KEY_PREFIX = 'key:';

    /** A scope for the account owner roles only (Super admin, Admin, Account Admin). */
    public const OWNER = ['@owner'];

    /**
     * scope => [label, description, permissions (any one; null: any admin panel user; OWNER: the
     * account owner roles)]
     *
     * @var array<string, array{0: string, 1: string, 2: list<string>|null}>
     */
    public const SCOPES = [
        'account:read' => ['Account', 'Read the user, their roles and permissions, and the properties.', null],
        'chart:read' => ['Smart chart', 'Read the smart booking chart: availability, stays, blocks, holidays, enquiry counts and notes.', null],
        'chart:notes' => ['Chart notes', 'Add notes to chart days and edit your own.', null],
        // The actions below are also checked against the role's permissions by each action.
        'chart:housekeeping' => ['Housekeeping', 'Mark rooms Clean, Dirty and the other housekeeping statuses.', ['housekeeping.change_status']],
        'chart:blocks' => ['Room blocks', 'Block, unblock and move room blocks on the chart.', null],
        'bookings:read' => ['Read bookings', 'List, search and read reservations and enquiries, with their rooms, guests, receipts and folios.', null],
        'channels:manage' => ['Booking channels', 'List, add, rename and switch on or off the OTAs bookings come from (account owner only).', self::OWNER],
        'bookings:write' => ['Change bookings', 'Create and edit bookings, holds and enquiries and add their guests; check in, undo check-in, cancel and move them.', null],
        'guests:read' => ['Read guests', 'List, search and read guest profiles.', ['database.guests.view']],
        'guests:write' => ['Change guests', 'Edit guest profiles.', null],
        'payments:read' => ['Read payments', 'List and read receipts and refunds.', ['database.payments.view_download']],
        'payments:write' => ['Record payments', 'Record receipts and refunds against bookings and their folios, and cancel them.', ['database.payments.add', 'reservation.add_payment']],
        'pos:read' => ['Read POS', 'List outlets with their items and prices, outlet bills and kitchen tickets, print them (HTML or ESC/POS), and read POS sales.', ['pos.post_transaction']],
        'panel' => ['Admin panel', 'Every page and action of the admin panel under /api/v1/panel, each allowed only when the role allows it on the web (the staff app).', null],
        'pos:write' => ['POS billing', 'Open outlet bills, send them to the kitchen, post them to a room folio with an optional payment, settle, void and print them on the outlet printers.', ['pos.post_transaction']],
    ];

    /**
     * Every scope name.
     *
     * @return list<string>
     */
    public static function names(): array
    {
        return array_keys(self::SCOPES);
    }

    /**
     * Whether the user's role allows what the scope does (null permissions: any panel user).
     */
    public static function userMay(User $user, string $scope): bool
    {
        if (! array_key_exists($scope, self::SCOPES) || ! PermissionCatalog::canEnterPanel($user)) {
            return false;
        }

        $permissions = self::SCOPES[$scope][2];

        if ($permissions === self::OWNER) {
            return $user->hasAnyRole(PermissionCatalog::ownerRoles());
        }

        if ($permissions === null || $user->hasAnyRole(PermissionCatalog::fullAccessRoles())) {
            return true;
        }

        return collect($permissions)->contains(fn (string $permission): bool => $user->checkPermissionTo($permission));
    }

    /**
     * The scopes the user may put on a key, for the Developer page.
     *
     * @return list<array{value: string, label: string, description: string}>
     */
    public static function availableTo(User $user): array
    {
        return array_values(collect(self::SCOPES)
            ->filter(fn (array $scope, string $name): bool => self::userMay($user, $name))
            ->map(fn (array $scope, string $name): array => ['value' => $name, 'label' => $scope[0], 'description' => $scope[1]])
            ->all());
    }

    public static function isDevice(PersonalAccessToken $token): bool
    {
        return Str::startsWith($token->name, self::DEVICE_PREFIX);
    }

    public static function isKey(PersonalAccessToken $token): bool
    {
        return Str::startsWith($token->name, self::KEY_PREFIX);
    }
}
