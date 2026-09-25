<?php

namespace App\Http\Controllers\Admin;

use App\Enums\UserStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Admin\StoreUserRequest;
use App\Http\Requests\Admin\UpdateUserRequest;
use App\Models\Country;
use App\Models\User;
use App\Services\PermissionCatalog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Inertia\Inertia;
use Inertia\Response;
use Spatie\Permission\Models\Role;

class UserController extends Controller
{
    public function index(Request $request): Response
    {
        $users = User::query()->with('roles')->latest()->paginate(10);
        $users->getCollection()->each(fn (User $user) => $user->setAttribute('can_manage', PermissionCatalog::canManageUser($request->user(), $user)));

        return Inertia::render('admin/users/Index', [
            'users' => $users,
        ]);
    }

    public function create(Request $request): Response
    {
        return Inertia::render('admin/users/Create', [
            'roleOptions' => $this->roleOptions($request->user()),
            'statusOptions' => UserStatus::options(),
            'countries' => $this->countries(),
        ]);
    }

    public function store(StoreUserRequest $request): RedirectResponse
    {
        $data = $request->validated();

        $user = User::create([
            'name' => $data['name'],
            'email' => $data['email'],
            ...User::mobileAttributes($data),
            'password' => Hash::make($data['password']),
            'status' => $data['status'],
        ]);

        // Without the right to give roles, a new user starts as a plain user.
        $user->assignRole($data['role'] ?? Role::findOrCreate(PermissionCatalog::USER, 'web'));

        return redirect()->route('admin.users.index')->with('success', 'User created.');
    }

    public function edit(Request $request, User $user): Response
    {
        $this->authorizeManaging($request->user(), $user);

        return Inertia::render('admin/users/Edit', [
            'user' => $user->load('roles'),
            'roleOptions' => $this->roleOptions($request->user()),
            'statusOptions' => UserStatus::options(),
            'countries' => $this->countries(),
        ]);
    }

    public function update(UpdateUserRequest $request, User $user): RedirectResponse
    {
        $this->authorizeManaging($request->user(), $user);

        $data = $request->validated();

        $user->update([
            'name' => $data['name'],
            'email' => $data['email'],
            ...User::mobileAttributes($data),
            'status' => $data['status'],
            ...(filled($data['password'] ?? null) ? ['password' => Hash::make($data['password'])] : []),
        ]);

        if (array_key_exists('role', $data)) {
            $user->syncRoles([$data['role']]);
        }

        return redirect()->route('admin.users.index')->with('success', 'User updated.');
    }

    /**
     * Mobile number country codes.
     *
     * @return Collection<int, Country>
     */
    private function countries(): Collection
    {
        return Country::query()->orderBy('name')->get(['id', 'name', 'iso2', 'phonecode']);
    }

    /**
     * @return list<array{value: string, label: string}>
     */
    private function roleOptions(User $grantor): array
    {
        return array_values(collect(PermissionCatalog::assignableRoles($grantor))
            ->map(fn (string $label, string $value): array => ['value' => $value, 'label' => $label])
            ->all());
    }

    /**
     * Users holding a role the current user can't grant (e.g. Super admin) are out of their reach.
     */
    private function authorizeManaging(User $grantor, User $user): void
    {
        abort_unless(PermissionCatalog::canManageUser($grantor, $user), 403, 'You can\'t change a user with this role.');
    }

    public function destroy(Request $request, User $user): RedirectResponse
    {
        if ($user->is($request->user())) {
            return back()->with('error', 'You cannot delete your own account.');
        }

        $this->authorizeManaging($request->user(), $user);

        $user->delete();

        return back()->with('success', 'User deleted.');
    }
}
