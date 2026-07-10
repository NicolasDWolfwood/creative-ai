<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithAdminCredentials;
use App\Models\User;
use Illuminate\Console\Command;

class CreateAdminUser extends Command
{
    use InteractsWithAdminCredentials;

    protected $signature = 'creative-ai:admin:create
        {email? : Administrator email address}
        {--name= : Display name for a new administrator}
        {--generate-password : Generate and print a strong password for a new account}';

    protected $description = 'Create an administrator or promote an existing user without changing their password.';

    public function handle(): int
    {
        $email = $this->resolveAdminEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $user = User::query()->whereRaw('LOWER(email) = ?', [$email])->first();

        if ($user) {
            if ($this->option('generate-password')) {
                $this->error('The user already exists. Use creative-ai:admin:reset-password to change its password.');

                return self::FAILURE;
            }

            $name = trim((string) $this->option('name'));
            $attributes = [
                'email' => $email,
                'email_verified_at' => $user->email_verified_at ?: now(),
                'is_admin' => true,
            ];

            if ($name !== '') {
                if (strlen($name) > 255) {
                    $this->error('The display name may not exceed 255 characters.');

                    return self::FAILURE;
                }

                $attributes['name'] = $name;
            }

            $user->forceFill($attributes)->save();
            $this->info('Existing user promoted to administrator.');

            return self::SUCCESS;
        }

        $name = trim((string) ($this->option('name') ?: $this->ask('Display name', 'Creative-Ai Admin')));

        if ($name === '' || strlen($name) > 255) {
            $this->error('The display name must contain between 1 and 255 characters.');

            return self::FAILURE;
        }

        $password = $this->resolveAdminPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user = new User([
            'name' => $name,
            'email' => $email,
            'password' => $password,
        ]);
        $user->forceFill([
            'email_verified_at' => now(),
            'is_admin' => true,
        ])->save();

        $this->info('Administrator created.');
        $this->line('Email: '.$email);
        $this->showGeneratedPassword($password);

        return self::SUCCESS;
    }
}
