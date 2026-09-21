<?php

namespace App\Console\Commands;

use App\Actions\Platform\ResetPlatformAdminPasswordAction;
use Illuminate\Console\Command;
use Illuminate\Validation\ValidationException;

class ResetPlatformAdminPasswordCommand extends Command
{
    protected $signature = 'bluepos:platform-admin-reset-password
        {--email= : Platform administrator email}
        {--password= : Temporary password (omit to generate once)}';

    protected $description = 'Reset a platform Super Admin password from the server. There is no self-service forgot-password screen.';

    public function handle(ResetPlatformAdminPasswordAction $reset): int
    {
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $password = (string) $this->option('password');
        $generated = $password === '';

        try {
            $result = $reset->execute($email, $generated ? null : $password);
        } catch (ValidationException $e) {
            $this->error(collect($e->errors())->flatten()->first() ?: 'Unable to reset the password.');

            return self::FAILURE;
        }

        $this->info('Platform Super Admin password reset.');
        $this->line('Email: '.$result['user']->email);
        $this->line('ULID: '.$result['user']->ulid);
        if ($generated) {
            $this->line('Temporary password (shown once): '.$result['temporary_password']);
        }
        $this->warn('Existing Super Admin sessions were revoked. Sign in at /platform/login and complete MFA.');

        return self::SUCCESS;
    }
}
