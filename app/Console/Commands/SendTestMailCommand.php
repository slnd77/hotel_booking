<?php

namespace App\Console\Commands;

use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

#[Signature('ops:mail-test {to : The address to send the test to}')]
#[Description('Send a test e-mail straight away (not queued) to check the production SMTP settings')]
class SendTestMailCommand extends Command
{
    public function handle(): int
    {
        $to = (string) $this->argument('to');

        if (config('mail.default') === 'log') {
            $this->warn('MAIL_MAILER is "log": the e-mail goes to the log file, not to anyone. Set MAIL_MAILER=smtp and the MAIL_* settings.');
        }

        try {
            Mail::raw('This is a test e-mail from '.config('app.name').' ('.config('app.url').'). If you can read it, outgoing e-mail works.', fn ($message) => $message
                ->to($to)
                ->subject(config('app.name').': test e-mail'));
        } catch (Throwable $exception) {
            $this->error('Sending failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info("Sent to {$to} with the ".config('mail.default').' mailer, from '.config('mail.from.address').'.');

        return self::SUCCESS;
    }
}
