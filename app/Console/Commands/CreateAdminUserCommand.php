<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Spatie\Permission\Models\Role;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\password;
use function Laravel\Prompts\text;

#[Signature('admin:create {email? : The admin\'s email address} {--name= : The admin\'s name} {--password= : The admin\'s password}')]
#[Description('Create a user with the admin role, or promote an existing user to admin')]
class CreateAdminUserCommand extends Command
{
    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $email = $this->argument('email') ?: text(
            label: 'Admin email address',
            required: true,
        );

        $validator = Validator::make(['email' => $email], ['email' => ['required', 'email']]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('email'));

            return self::FAILURE;
        }

        $user = User::whereEmail($email)->first();

        if ($user && ! confirm("A user with email [{$email}] already exists. Promote them to admin?")) {
            $this->components->info('Cancelled.');

            return self::SUCCESS;
        }

        $name = $this->option('name') ?: text(
            label: 'Admin name',
            default: $user->name ?? '',
            required: true,
        );

        $password = $this->option('password') ?: password(
            label: 'Admin password',
            required: true,
        );

        $validator = Validator::make(['password' => $password], ['password' => ['required', 'string', 'min:8']]);

        if ($validator->fails()) {
            $this->components->error($validator->errors()->first('password'));

            return self::FAILURE;
        }

        $user = User::updateOrCreate(
            ['email' => $email],
            ['name' => $name, 'password' => Hash::make($password)],
        );

        Role::firstOrCreate(['name' => 'admin']);
        $user->syncRoles(['admin']);

        $this->components->info("Admin user [{$user->email}] is ready.");

        return self::SUCCESS;
    }
}
