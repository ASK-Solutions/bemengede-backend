<?php

namespace App\Http\Controllers;

use App\Models\City;
use App\Models\Operator;
use App\Models\Review;
use App\Models\Route;
use Illuminate\Http\JsonResponse;

class PublicController extends Controller
{
    public function business(): JsonResponse
    {
        return response()->json(config('bemengede.business'));
    }

    public function legal(string $doc): JsonResponse
    {
        abort_unless(in_array($doc, ['privacy', 'terms', 'refund', 'cookies'], true), 404);
        $locale = in_array(request('locale'), ['en', 'am'], true) ? request('locale') : 'en';
        $path = resource_path("legal/{$locale}/{$doc}.md");
        if (! is_file($path)) {
            $path = resource_path("legal/en/{$doc}.md");
        }
        $b = config('bemengede.business');
        $md = strtr(file_get_contents($path), [
            '{{legal_name}}' => $b['legal_name'], '{{trade_name}}' => $b['trade_name'], '{{tin}}' => $b['tin'],
            '{{trade_license_no}}' => $b['trade_license_no'], '{{address}}' => $b['address'], '{{email}}' => $b['email'],
            '{{privacy_email}}' => $b['privacy_email'], '{{phone}}' => $b['phone'],
            '{{service_fee}}' => number_format(config('bemengede.pricing.service_fee_per_seat') / 100, 2),
            '{{cooling_off_hours}}' => config('bemengede.privacy.deletion_cooling_off_hours'),
            '{{retention_years}}' => config('bemengede.privacy.financial_retention_years'),
            '{{effective}}' => config("bemengede.legal.{$doc}.effective"),
        ]);

        return response()->json([
            'doc' => $doc,
            'version' => config("bemengede.legal.{$doc}.version"),
            'effective' => config("bemengede.legal.{$doc}.effective"),
            'locale' => $locale,
            'markdown' => $md,
        ]);
    }

    public function cities(): JsonResponse
    {
        return response()->json(City::orderBy('name_en')->get(['id', 'name_en', 'name_am']));
    }

    /** Only operators with a signed agreement are named publicly. */
    public function partners(): JsonResponse
    {
        $ops = Operator::publicPartner()->orderBy('name')->get()->map(function (Operator $o) {
            $count = Review::where('operator_id', $o->id)->where('published', true)->count();

            return [
                'name' => $o->name,
                'slug' => $o->slug,
                'brand_color' => $o->brand_color,
                'logo_url' => $o->logo_path ? asset('storage/'.$o->logo_path) : null,
                'logo_alt' => $o->logo_alt ?: $o->name.' logo',
                // A rating is shown only when enough verified travellers have rated.
                'rating' => $count >= 5 ? round(Review::where('operator_id', $o->id)->where('published', true)->avg('rating'), 1) : null,
                'review_count' => $count,
            ];
        });

        return response()->json($ops);
    }

    /** Real numbers for the landing page, so marketing copy never overstates. */
    public function stats(): JsonResponse
    {
        return response()->json([
            'partners' => Operator::publicPartner()->count(),
            'routes' => Route::whereHas('trips')->count(),
            'cities' => City::count(),
        ]);
    }
}
