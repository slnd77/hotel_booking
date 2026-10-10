<?php

namespace App\Services;

use App\Enums\TwoFactorMethod;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use RuntimeException;

/**
 * Sends SMS and WhatsApp messages. The driver comes from `services.text_messages.driver`:
 * "twilio" (Twilio's Messages API, which serves both) or "log" (written to the log, for
 * development and until a provider is set up).
 */
class TextMessages
{
    public function send(TwoFactorMethod $channel, string $to, string $message): void
    {
        match (config('services.text_messages.driver', 'log')) {
            'twilio' => $this->sendWithTwilio($channel, $to, $message),
            default => Log::info("[{$channel->value}] to {$to}: {$message}"),
        };
    }

    private function sendWithTwilio(TwoFactorMethod $channel, string $to, string $message): void
    {
        $sid = (string) config('services.twilio.sid');
        $whatsApp = $channel === TwoFactorMethod::WhatsApp;
        $from = (string) config($whatsApp ? 'services.twilio.whatsapp_from' : 'services.twilio.sms_from');

        if ($sid === '' || $from === '') {
            throw new RuntimeException("Twilio isn't set up for {$channel->label()}.");
        }

        Http::asForm()
            ->withBasicAuth($sid, (string) config('services.twilio.token'))
            ->post("https://api.twilio.com/2010-04-01/Accounts/{$sid}/Messages.json", [
                'To' => $whatsApp ? "whatsapp:{$to}" : $to,
                'From' => $whatsApp ? "whatsapp:{$from}" : $from,
                'Body' => $message,
            ])
            ->throw();
    }
}
