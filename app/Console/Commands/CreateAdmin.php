<?php

namespace App\Console\Commands;

use App\Models\Admin;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Str;
use Illuminate\Validation\Rules\Password;

use function Laravel\Prompts\password as promptPassword;
use function Laravel\Prompts\text;

class CreateAdmin extends Command
{
    protected $signature = 'app:create-admin
        {--name= : The admin display name}
        {--email= : The admin email address}
        {--password= : The admin password (leave empty to generate one)}';

    protected $description = 'Create or update a Filament admin account';

    public function handle(): int
    {
        $name = $this->option('name') ?: text(
            label: 'Name',
            required: true,
        );

        $email = $this->option('email') ?: text(
            label: 'Email',
            required: true,
        );

        // A generated password is shown once so no credential is ever hardcoded.
        $generated = false;
        $plainPassword = $this->option('password');

        if (! $plainPassword && $this->input->isInteractive()) {
            $plainPassword = promptPassword(label: 'Password (leave empty to generate one)');
        }

        if (! $plainPassword) {
            $plainPassword = Str::password(20);
            $generated = true;
        }

        $validator = Validator::make(
            ['name' => $name, 'email' => $email, 'password' => $plainPassword],
            [
                'name' => ['required', 'string', 'max:255'],
                'email' => ['required', 'email'],
                'password' => ['required', Password::min(12)],
            ]
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $admin = Admin::updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => Hash::make($plainPassword),
                'email_verified_at' => now(),
            ]
        );

        $this->info("Admin saved: {$admin->email}");

        if ($generated) {
            $this->newLine();
            $this->warn('Generated password (shown once, store it now):');
            $this->line($plainPassword);
        }

        return self::SUCCESS;
    }
}
