<?php

namespace App\Notifications;

use App\Models\Booking;
use App\Notifications\Channels\SmsChannel;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;

/** Transactional: the ticket itself. Not marketing, so no opt-in needed. */
class BookingConfirmed extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(public Booking $booking) {}

    public function via(object $notifiable): array
    {
        $channels = [SmsChannel::class];
        if ($notifiable->routeNotificationFor('mail', $this)) {
            $channels[] = 'mail';
        }

        return $channels;
    }

    private function link(): string
    {
        return rtrim(config('bemengede.frontend_url'), '/').'/booking/'.$this->booking->reference;
    }

    public function toSms(object $notifiable): string
    {
        $t = $this->booking->trip;

        return "Bemengede: ticket {$this->booking->reference} confirmed. {$this->booking->operator->name}, "
            .$t->departure_at->setTimezone('Africa/Addis_Ababa')->format('D j M H:i').'. Seats: '
            .$this->booking->passengers->pluck('seat_number')->implode(',').'. '.$this->link();
    }

    public function toMail(object $notifiable): MailMessage
    {
        $b = config('bemengede.business');
        $t = $this->booking->trip;

        return (new MailMessage)
            ->subject('Your ticket '.$this->booking->reference)
            ->greeting('Your trip is booked')
            ->line($this->booking->operator->name.' · '.$t->departure_at->setTimezone('Africa/Addis_Ababa')->format('l j F Y, H:i'))
            ->line('Seats: '.$this->booking->passengers->pluck('seat_number')->implode(', '))
            ->line('Total paid: ETB '.number_format($this->booking->total / 100, 2))
            ->action('View ticket', $this->link())
            ->salutation($b['legal_name'].' · TIN '.$b['tin'].' · '.$b['address']);
    }
}
