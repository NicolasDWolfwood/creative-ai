<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreateAdminUser extends Command
{
    protected $signature = 'creative-ai:create-admin
        {--email= : Admin email address}
        {--name= : Admin display name}
        {--password= : Admin password. A random password is generated when omitted.}';

    protected $description = 'Create or update the Creative-Ai Filament admin user.';

    public function handle(): int
    {
        $email = $this->option('email') ?: config('creative_ai.admin_email');
        $name = $this->option('name') ?: env('ADMIN_NAME', 'Creative-Ai Admin');
        $password = $this->option('password') ?: env('ADMIN_PASSWORD') ?: Str::password(24);

        if (blank($email)) {
            $this->error('Provide --email or set ADMIN_EMAIL.');

            return self::FAILURE;
        }

        $user = User::query()->updateOrCreate(
            ['email' => $email],
            [
                'name' => $name,
                'password' => $password,
                'email_verified_at' => now(),
            ],
        );

        $this->info($user->wasRecentlyCreated ? 'Admin user created.' : 'Admin user updated.');
        $this->line("Email: {$email}");

        if (! $this->option('password') && blank(env('ADMIN_PASSWORD'))) {
            $this->warn("Generated password: {$password}");
            $this->warn('Store this password now; it is not recoverable from the app.');
        }

        return self::SUCCESS;
    }
}
