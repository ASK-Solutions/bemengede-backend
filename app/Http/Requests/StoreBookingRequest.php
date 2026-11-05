<?php

namespace App\Http\Requests;

use App\Support\Phone;
use Illuminate\Foundation\Http\FormRequest;

class StoreBookingRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    protected function prepareForValidation(): void
    {
        $this->merge(['contact_phone' => Phone::normalize($this->contact_phone) ?? $this->contact_phone]);
    }

    /**
     * Collect only what's needed to issue and check a ticket:
     * passenger name (manifest), seat, fare type; one contact phone for the ticket SMS.
     * Email is optional. No gender, birth date or ID number.
     */
    public function rules(): array
    {
        $max = config('bemengede.booking.max_seats_per_booking');

        return [
            'trip_id' => ['required', 'integer', 'exists:trips,id'],
            'contact_phone' => ['required', 'string', Phone::rule()],
            'contact_email' => ['nullable', 'email:rfc', 'max:120'],
            'passengers' => ['required', 'array', 'min:1', "max:{$max}"],
            'passengers.*.full_name' => ['required', 'string', 'min:2', 'max:80'],
            'passengers.*.seat_number' => ['required', 'integer', 'min:1', 'distinct'],
            'passengers.*.fare_category' => ['required', 'in:adult,child,student'],
            // Required, never pre-ticked: agreement to terms and privacy notice.
            'accept_terms' => ['accepted'],
            // The person booking must be an adult; children can be passengers.
            'booker_is_adult' => ['accepted'],
            // Optional, separate, default false.
            'marketing_sms' => ['sometimes', 'boolean'],
            'marketing_email' => ['sometimes', 'boolean'],
            'channel' => ['sometimes', 'in:web,mobile'],
        ];
    }

    public function messages(): array
    {
        return [
            'accept_terms.accepted' => 'Please accept the Terms of Service and Privacy Policy to book.',
            'booker_is_adult.accepted' => 'The person booking must be 18 or older. Children can travel as passengers on an adult’s booking.',
            'passengers.*.seat_number.distinct' => 'Each passenger needs a different seat.',
        ];
    }
}
