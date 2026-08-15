<?php

namespace App\Console\Commands;

use App\Mail\TransportCheck;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Validator;
use Throwable;

/**
 * Proves that mail actually leaves this environment.
 *
 * The sibling of `media:check`, and it exists for the same reason: the failure
 * it catches is silent. An environment left on the `log` mailer accepts every
 * send, logs it, reports success, and delivers nothing. Nobody finds out until
 * a customer cannot reset their password.
 *
 * By default this queues, because that is how every real message in this
 * application is sent and a proof should travel the channel real mail travels.
 * `--now` sends on the spot instead, which is what tells a broken transport
 * apart from a queue worker that is not running. Those two look identical from
 * the outside and have nothing to do with each other.
 */
class CheckMail extends Command
{
    protected $signature = 'mail:check {email : Where to send it} {--now : Send immediately instead of queueing}';

    protected $description = 'Send a real message through the configured transport to prove it works';

    public function handle(): int
    {
        $email = (string) $this->argument('email');

        if (Validator::make(['email' => $email], ['email' => 'required|email'])->fails()) {
            $this->error($email.' is not an email address.');

            return self::FAILURE;
        }

        $mailer = config('mail.default');

        // Worth saying out loud rather than letting a green result be read as
        // proof. On the log mailer this command succeeds and sends nothing.
        if ($mailer === 'log' || $mailer === 'array') {
            $this->warn('The mailer is "'.$mailer.'", so nothing will leave this machine.');
        }

        $message = new TransportCheck(app()->environment());

        try {
            $this->option('now')
                ? Mail::to($email)->sendNow($message)
                : Mail::to($email)->send($message);
        } catch (Throwable $e) {
            $this->error('The transport refused it: '.$e->getMessage());

            return self::FAILURE;
        }

        if ($this->option('now')) {
            $this->info('Sent to '.$email.' through the "'.$mailer.'" mailer.');
            $this->line('If it does not arrive, the transport is the problem.');

            return self::SUCCESS;
        }

        $this->info('Queued for '.$email.' through the "'.$mailer.'" mailer.');
        $this->line('It leaves when a queue worker picks it up. If it never arrives,');
        $this->line('run this again with --now to find out which half is broken.');

        return self::SUCCESS;
    }
}
