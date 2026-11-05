<?php

namespace App\Mail;

use App\Models\MarketingSubscription;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Mail\Mailables\Headers;

/**
 * Every marketing email carries a visible unsubscribe link AND the
 * List-Unsubscribe / one-click headers (RFC 8058) so mail apps show a button.
 * Only send to active subscriptions: MarketingSubscription::whereNull('unsubscribed_at').
 */
class MarketingMessage extends Mailable
{
    use Queueable;

    public function __construct(public MarketingSubscription $sub, public string $title, public string $body) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: $this->title);
    }

    public function headers(): Headers
    {
        return new Headers(text: [
            'List-Unsubscribe' => '<'.url('/api/v1/unsubscribe/'.$this->sub->token).'>, <mailto:'.config('bemengede.business.privacy_email').'?subject=unsubscribe>',
            'List-Unsubscribe-Post' => 'List-Unsubscribe=One-Click',
        ]);
    }

    public function content(): Content
    {
        return new Content(view: 'mail.marketing', with: [
            'body' => $this->body,
            'unsubscribeUrl' => $this->sub->unsubscribeUrl(),
            'business' => config('bemengede.business'),
        ]);
    }
}
