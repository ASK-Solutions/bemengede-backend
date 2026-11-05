<?php

use App\Http\Controllers\AccountController;
use App\Http\Controllers\Admin\AdminController;
use App\Http\Controllers\AuthController;
use App\Http\Controllers\BookingController;
use App\Http\Controllers\Operator\BookingController as OpBooking;
use App\Http\Controllers\Operator\DashboardController;
use App\Http\Controllers\Operator\FinanceController;
use App\Http\Controllers\Operator\FleetController;
use App\Http\Controllers\Operator\OperatorController;
use App\Http\Controllers\Operator\SettingsController;
use App\Http\Controllers\Operator\TripController as OpTrip;
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
    Route::get('auth/invitations/{token}', [AuthController::class, 'invitation'])->middleware('throttle:lookup');
    Route::post('auth/invitations/{token}', [AuthController::class, 'acceptInvitation'])->middleware('throttle:otp');

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
        Route::post('bookings/{reference}/disputes', [BookingController::class, 'dispute']);

        Route::patch('me', [AccountController::class, 'update']);
        Route::get('me/consents', [AccountController::class, 'consents']);
        Route::put('me/consents', [AccountController::class, 'updateConsents']);
        Route::get('me/export', [AccountController::class, 'export']);
        Route::get('me/deletion', [AccountController::class, 'deletionStatus']);
        Route::delete('me', [AccountController::class, 'destroy']);
        Route::post('me/deletion/cancel', [AccountController::class, 'cancelDeletion']);

        // Operator portal: every controller scopes queries to the user's own company.
        Route::prefix('operator')->middleware('role:operator_owner,operator_agent')->group(function () {
            Route::get('dashboard', DashboardController::class);

            Route::get('trips', [OpTrip::class, 'index']);
            Route::post('trips', [OpTrip::class, 'store']);
            Route::patch('trips/{trip}', [OpTrip::class, 'update']);
            Route::post('trips/{trip}/delay', [OpTrip::class, 'delay']);
            Route::post('trips/{trip}/cancel', [OpTrip::class, 'cancel']);
            Route::get('trips/{trip}/manifest', [OpTrip::class, 'manifest']);

            Route::get('bookings', [OpBooking::class, 'index']);
            Route::get('bookings/{reference}', [OpBooking::class, 'show']);
            Route::post('bookings/{reference}/cancel', [OpBooking::class, 'cancel']);
            Route::patch('passengers/{passenger}', [OpBooking::class, 'updatePassenger']);
            Route::post('counter-sales', [OpBooking::class, 'counterSale']);

            Route::get('verifications', [OperatorController::class, 'verifications']);
            Route::post('verifications/{verification}', [OperatorController::class, 'decide']);
            Route::post('board', [OperatorController::class, 'board']);

            Route::get('buses', [FleetController::class, 'buses']);
            Route::post('buses', [FleetController::class, 'storeBus']);
            Route::patch('buses/{bus}', [FleetController::class, 'updateBus']);
            Route::delete('buses/{bus}', [FleetController::class, 'destroyBus']);
            Route::get('drivers', [FleetController::class, 'drivers']);
            Route::post('drivers', [FleetController::class, 'storeDriver']);
            Route::patch('drivers/{driver}', [FleetController::class, 'updateDriver']);
            Route::get('terminals', [FleetController::class, 'terminals']);
            Route::get('routes', [FleetController::class, 'routes']);
            Route::post('routes', [FleetController::class, 'storeRoute']);

            Route::get('settings', [SettingsController::class, 'show']);
            Route::patch('settings', [SettingsController::class, 'update']);
            Route::post('accounts', [SettingsController::class, 'storeAccount']);
            Route::delete('accounts/{account}', [SettingsController::class, 'destroyAccount']);
            Route::post('documents', [SettingsController::class, 'uploadDocument']);
            Route::get('staff', [SettingsController::class, 'staff']);
            Route::post('staff', [SettingsController::class, 'invite']);
            Route::delete('staff/{user}', [SettingsController::class, 'removeStaff']);

            Route::get('payouts', [FinanceController::class, 'payouts']);
            Route::get('reports', [FinanceController::class, 'reports']);
        });

        Route::prefix('admin')->middleware('role:platform_admin')->group(function () {
            Route::get('overview', [AdminController::class, 'overview']);
            Route::get('operators', [AdminController::class, 'operators']);
            Route::get('operators/{operator}', [AdminController::class, 'operator']);
            Route::patch('operators/{operator}', [AdminController::class, 'updateOperator']);
            Route::post('operators/{operator}/status', [AdminController::class, 'setStatus']);
            Route::post('operators/{operator}/owner', [AdminController::class, 'inviteOwner']);
            Route::get('documents/{document}', [AdminController::class, 'document']);
            Route::post('documents/{document}', [AdminController::class, 'decideDocument']);
            Route::get('providers', [AdminController::class, 'providers']);
            Route::post('payments/{payment}/refunded', [AdminController::class, 'markRefunded']);
            Route::get('settlements', [AdminController::class, 'settlements']);
            Route::post('settlements', [AdminController::class, 'generateSettlements']);
            Route::post('settlements/{settlement}/paid', [AdminController::class, 'markSettlementPaid']);
            Route::get('disputes', [AdminController::class, 'disputes']);
            Route::post('disputes/{dispute}', [AdminController::class, 'resolveDispute']);
            Route::get('cities', [AdminController::class, 'cities']);
            Route::post('cities', [AdminController::class, 'storeCity']);
            Route::post('terminals', [AdminController::class, 'storeTerminal']);
            Route::get('audit', [AdminController::class, 'audit']);
        });
    });
});
