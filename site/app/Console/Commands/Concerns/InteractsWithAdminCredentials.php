<?php

namespace App\Console\Commands\Concerns;

use Illuminate\Support\Str;

trait InteractsWithAdminCredentials
{
    protected function resolveAdminEmail(): ?string
    {
        $email = trim((string) ($this->argument('email') ?: $this->ask('Administrator email')));
        $email = Str::lower($email);

        if (! filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 255) {
            $this->error('Enter a valid administrator email address.');

            return null;
        }

        return $email;
    }

    protected function resolveAdminPassword(): ?string
    {
        if ($this->option('generate-password')) {
            return Str::password(24);
        }

        if (! $this->input->isInteractive()) {
            $this->error('Use an interactive terminal or pass --generate-password.');

            return null;
        }

        $password = (string) $this->secret('Password (minimum 12 characters)');
        $confirmation = (string) $this->secret('Confirm password');

        if ($password !== $confirmation) {
            $this->error('The passwords do not match.');

            return null;
        }

        if (strlen($password) < 12 || strlen($password) > 255) {
            $this->error('The password must contain between 12 and 255 characters.');

            return null;
        }

        return $password;
    }

    protected function showGeneratedPassword(string $password): void
    {
        if (! $this->option('generate-password')) {
            return;
        }

        $this->newLine();
        $this->warn('Generated password: '.$password);
        $this->warn('Store it now. It will not be shown again.');
    }
}
