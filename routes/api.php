<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\Operator\OperatorController;
use App\Http\Controllers\PartnerApplicationController;
use App\Http\Controllers\PaymentController;
use App\Http\Controllers\PrivacyPublicController;
use App\Http\Controllers\PublicController;
use App\Http\Controllers\ReviewController;
use App\Http\Controllers\TripController;
use App\Http\Controllers\WebhookController;
use Illuminate\Support\Facades\Route;

Route::prefix('v1')->middleware('throttle:api')->group(function () {
    // Public
    Route::get('business', [PublicController::class, 'business']);
    Route::get('legal/{doc}', [PublicController::class, 'legal']);
    Route::get('cities', [PublicController::class, 'cities']);
    Route::get('partners', [PublicController::class, 'partners']);
    Route::get('partners/{slug}/reviews', [ReviewController::class, 'index']);
    Route::get('stats', [PublicController::class, 'stats']);
    Route::get('trips/search', [TripController::class, 'search']);
    Route::get('trips/{trip}', [TripController::class, 'show']);
    Route::post('partner-applications', [PartnerApplicationController::class, 'store'])->middleware('throttle:lookup');

    // Consent & unsubscribe (no login required)
    Route::post('consents/cookies', [PrivacyPublicController::class, 'cookieConsent']);
    Route::get('unsubscribe/{token}', [PrivacyPublicController::class, 'unsubscribeStatus']);
    Route::post('unsubscribe/{token}', [PrivacyPublicController::class, 'unsubscribe']);

    // Auth
    Route::post('auth/otp', [AuthController::class, 'requestOtp'])->middleware('throttle:otp');
    Route::post('auth/verify', [AuthController::class, 'verifyOtp'])->middleware('throttle:otp');
    Route::post('auth/staff', [AuthController::class, 'staffLogin'])->middleware('throttle:otp');

    // Booking (guests allowed; lookups need reference + phone)
    Route::post('bookings', [BookingController::class, 'store']);
    Route::middleware('throttle:lookup')->group(function () {
        Route::get('bookings/{reference}', [BookingController::class, 'show']);
        Route::get('bookings/{reference}/cancellation', [BookingController::class, 'cancellationQuote']);
        Route::post('bookings/{reference}/pay', [PaymentController::class, 'start']);
        Route::get('payments/{txRef}', [PaymentController::class, 'status']);
        Route::post('payments/{txRef}/transfer', [PaymentController::class, 'verifyTransfer']);
    });
    Route::post('payments/{txRef}/mock-complete', [PaymentController::class, 'mockComplete']);

    // Webhooks (signature-checked; never trusted for amounts)
    Route::post('webhooks/verify-et', [WebhookController::class, 'verifyEt']);
    Route::post('webhooks/{provider}', [WebhookController::class, 'handle'])->whereIn('provider', ['chapa', 'telebirr']);

    Route::middleware('auth:sanctum')->group(function () {
        Route::get('me', [AuthController::class, 'show']);
        Route::post('auth/logout', [AuthController::class, 'logout']);
        Route::get('me/bookings', [BookingController::class, 'mine']);
        Route::post('bookings/{reference}/cancel', [BookingController::class, 'cancel']);
        Route::post('bookings/{reference}/review', [ReviewController::class, 'store']);

        Route::patch('me', [AccountController::class, 'update']);
        Route::get('me/consents', [AccountController::class, 'consents']);
        Route::put('me/consents', [AccountController::class, 'updateConsents']);
        Route::get('me/export', [AccountController::class, 'export']);
        Route::get('me/deletion', [AccountController::class, 'deletionStatus']);
        Route::delete('me', [AccountController::class, 'destroy']);
        Route::post('me/deletion/cancel', [AccountController::class, 'cancelDeletion']);

        Route::prefix('operator')->middleware('role:operator_owner,operator_agent')->group(function () {
            Route::get('trips', [OperatorController::class, 'trips']);
            Route::post('trips', [OperatorController::class, 'storeTrip'])->middleware('role:operator_owner');
            Route::get('verifications', [OperatorController::class, 'verifications']);
            Route::post('verifications/{verification}', [OperatorController::class, 'decide']);
            Route::post('board', [OperatorController::class, 'board']);
        });

        Route::prefix('admin')->middleware('role:platform_admin')->group(function () {
            Route::get('operators', [AdminController::class, 'operators']);
            Route::patch('operators/{operator}', [AdminController::class, 'setStatus']);
        });
    });
});
