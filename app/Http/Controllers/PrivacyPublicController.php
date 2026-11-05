<?php

namespace App\Http\Controllers;

use App\Models\Consent;
use App\Models\MarketingSubscription;
use App\Services\ConsentService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class PrivacyPublicController extends Controller
{
    public function __construct(private ConsentService $consents) {}

    public function unsubscribeStatus(string $token): JsonResponse
    {
        $sub = MarketingSubscription::where('token', $token)->firstOrFail();

        return response()->json(['channel' => $sub->channel, 'active' => $sub->isActive()]);
    }

    /** One click, no login, no "are you sure" maze. Also handles RFC 8058 one-click POSTs. */
    public function unsubscribe(string $token): JsonResponse
    {
        $sub = MarketingSubscription::where('token', $token)->firstOrFail();
        $this->consents->unsubscribe($sub);

        return response()->json(['channel' => $sub->channel, 'active' => false]);
    }

    /** Records a cookie choice against a random id (not linked to a person). */
    public function cookieConsent(Request $request): JsonResponse
    {
        $data = $request->validate([
            'anonymous_id' => ['required', 'uuid'],
            'analytics' => ['required', 'boolean'],
        ]);
        Consent::create([
            'anonymous_id' => $data['anonymous_id'],
            'type' => Consent::COOKIES_ANALYTICS,
            'version' => config('bemengede.legal.cookies.version'),
            'granted' => $data['analytics'],
            'source' => 'web',
        ]);

        return response()->json(['ok' => true, 'version' => config('bemengede.legal.cookies.version')]);
    }
}
