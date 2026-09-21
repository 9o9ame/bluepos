<?php

namespace App\Console\Commands;

use App\Actions\Platform\CreatePlatformAdminAction;
use Illuminate\Console\Command;
use Illuminate\Support\Str;

class CreatePlatformAdminCommand extends Command
{
    protected $signature = 'bluepos:platform-admin-create
        {--name= : Full name}
        {--email= : Platform email}
        {--password= : Temporary password (omit to generate once)}';

    protected $description = 'Create a BluePOS platform Super Admin (no public registration).';

    public function handle(CreatePlatformAdminAction $create): int
    {
        $name = (string) ($this->option('name') ?: $this->ask('Name'));
        $email = strtolower(trim((string) ($this->option('email') ?: $this->ask('Email'))));
        $generated = false;
        $password = (string) $this->option('password');
        if ($password === '') {
            $password = Str::password(16);
            $generated = true;
        }

        $user = $create->execute([
            'name' => $name,
            'email' => $email,
            'password' => $password,
            'must_change_password' => true,
        ]);

        $this->info('Platform Super Admin created.');
        $this->line('Email: '.$user->email);
        $this->line('ULID: '.$user->ulid);
        if ($generated) {
            $this->line('Temporary password (shown once): '.$password);
        }

        return self::SUCCESS;
    }
}
