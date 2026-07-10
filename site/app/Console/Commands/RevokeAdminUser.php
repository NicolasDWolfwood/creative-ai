<?php

namespace App\Console\Commands;

use App\Console\Commands\Concerns\InteractsWithAdminCredentials;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class RevokeAdminUser extends Command
{
    use InteractsWithAdminCredentials;

    protected $signature = 'creative-ai:admin:revoke
        {email? : Administrator email address}
        {--allow-no-admin : Explicitly allow revoking the final administrator}';

    protected $description = 'Remove administration access without deleting the user.';

    public function handle(): int
    {
        $email = $this->resolveAdminEmail();

        if ($email === null) {
            return self::FAILURE;
        }

        $result = DB::transaction(function () use ($email): string {
            $administrators = User::query()
                ->where('is_admin', true)
                ->orderBy('id')
                ->lockForUpdate()
                ->get();

            $user = $administrators->first(
                fn (User $administrator): bool => Str::lower($administrator->email) === $email,
            );

            if (! $user) {
                return 'missing';
            }

            if (! $this->option('allow-no-admin') && $administrators->count() === 1) {
                return 'last-administrator';
            }

            $user->forceFill([
                'is_admin' => false,
                'remember_token' => Str::random(60),
            ])->save();

            return 'revoked';
        });

        if ($result === 'missing') {
            $this->error('No administrator exists with that email address.');

            return self::FAILURE;
        }

        if ($result === 'last-administrator') {
            $this->error('Refusing to revoke the final administrator. Pass --allow-no-admin to confirm recovery mode.');

            return self::FAILURE;
        }

        $this->info('Administrator access revoked.');

        return self::SUCCESS;
    }
}
