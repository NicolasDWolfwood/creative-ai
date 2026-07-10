<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithAdminCredentials;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class ResetAdminPassword extends Command
{
    use InteractsWithAdminCredentials;

    protected $signature = 'creative-ai:admin:reset-password
        {email? : Administrator email address}
        {--generate-password : Generate and print a strong replacement password}';

    protected $description = 'Reset an administrator password and invalidate remembered sessions.';

    public function handle(): int
    {
        $email = $this->resolveAdminEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $user = User::query()
            ->whereRaw('LOWER(email) = ?', [$email])
            ->where('is_admin', true)
            ->first();

        if (! $user) {
            $this->error('No administrator exists with that email address.');

            return self::FAILURE;
        }

        $password = $this->resolveAdminPassword();

        if ($password === null) {
            return self::FAILURE;
        }

        $user->forceFill([
            'password' => $password,
            'remember_token' => Str::random(60),
        ])->save();

        $this->info('Administrator password reset.');
        $this->showGeneratedPassword($password);

        return self::SUCCESS;
    }
}
