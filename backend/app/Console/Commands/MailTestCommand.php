<?php

namespace App\Console\Commands;

use App\Mail\MailTransportTestMail;
use App\Security\EmailNormalizer;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

class MailTestCommand extends Command
{
    protected $signature = 'bluepos:mail-test {email : Recipient address for a harmless transport test}';

    protected $description = 'Send a harmless BluePOS mail transport test. Does not generate MFA or print secrets.';

    public function handle(): int
    {
        $email = EmailNormalizer::normalize((string) $this->argument('email'));
        if ($email === null) {
            $this->error('Delivery attempted: no');
            $this->error('Result: failure');
            $this->line('A valid email address is required.');

            return self::FAILURE;
        }

        $this->line('Delivery attempted: yes');
        $this->line('Mailer: '.(string) config('mail.default'));
        $this->line('From: '.(string) config('mail.from.address'));

        try {
            Mail::to($email)->send(new MailTransportTestMail);
        } catch (Throwable $e) {
            $this->error('Result: failure');
            $this->line('Unable to deliver the test message. Check SMTP host/port and credentials in the environment. Secrets are not printed.');

            return self::FAILURE;
        }

        $this->info('Result: success');
        $this->line('A transport test was accepted by the configured mailer.');

        return self::SUCCESS;
    }
}
