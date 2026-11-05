<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('operators', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('slug')->unique();
            $table->string('category', 20)->default('intercity');
            // invited | applied | under_review | active | suspended
            $table->string('status', 20)->default('invited')->index();
            // A partner is only named on the public site after the agreement is signed.
            $table->timestamp('partnership_signed_at')->nullable();
            $table->string('legal_name')->nullable();
            $table->string('trade_license_no')->nullable();
            $table->string('tin')->nullable();
            $table->string('operating_license_no')->nullable();
            $table->string('contact_email')->nullable();
            $table->string('contact_phone')->nullable();
            $table->string('address')->nullable();
            $table->string('brand_color', 7)->nullable();
            $table->string('logo_path')->nullable();
            $table->string('logo_alt')->nullable();
            $table->unsignedSmallInteger('free_cancel_hours')->default(48);
            $table->unsignedTinyInteger('late_cancel_fee_percent')->default(20);
            $table->decimal('commission_percent', 5, 2)->nullable();
            $table->string('chapa_subaccount_id')->nullable();
            $table->timestamps();
        });

        Schema::create('operator_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->string('bank', 20); // CBE, TELEBIRR, AWASH, ...
            $table->string('account_name');
            $table->string('account_number');
            $table->timestamps();
            $table->unique(['bank', 'account_number']);
        });

        Schema::create('cities', function (Blueprint $table) {
            $table->id();
            $table->string('name_en');
            $table->string('name_am')->nullable();
            $table->string('region')->nullable();
        });

        Schema::create('terminals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('city_id')->constrained();
            $table->string('name');
        });

        Schema::create('buses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->string('plate_number');
            $table->string('side_number')->nullable();
            $table->string('class', 20)->default('standard'); // standard | vip
            $table->unsignedSmallInteger('seat_count');
            $table->json('layout'); // {"rows":13,"columns":4,"aisle_after":2,"blocked":[]}
            $table->json('amenities')->nullable();
            $table->string('status', 20)->default('active');
            $table->timestamps();
            $table->unique(['operator_id', 'plate_number']);
        });

        Schema::create('routes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('origin_terminal_id')->constrained('terminals');
            $table->foreignId('destination_terminal_id')->constrained('terminals');
            $table->unsignedInteger('distance_km');
            $table->unsignedInteger('duration_minutes');
            $table->json('stops')->nullable();
            $table->timestamps();
        });

        Schema::create('trips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->foreignId('route_id')->constrained();
            $table->foreignId('bus_id')->constrained();
            $table->dateTime('departure_at')->index();
            $table->dateTime('arrival_at');
            $table->unsignedInteger('base_fare'); // santim
            $table->string('status', 20)->default('scheduled');
            $table->timestamps();
        });

        Schema::create('bookings', function (Blueprint $table) {
            $table->id();
            $table->string('reference', 12)->unique();
            $table->foreignId('operator_id')->constrained();
            $table->foreignId('trip_id')->constrained();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('contact_phone', 20)->nullable()->index();
            $table->string('contact_email')->nullable();
            // pending_payment | awaiting_verification | confirmed | checked_in | cancelled | expired | refunded
            $table->string('status', 30)->default('pending_payment')->index();
            $table->unsignedInteger('subtotal');
            $table->unsignedInteger('discount')->default(0);
            $table->unsignedInteger('service_fee');
            $table->unsignedInteger('total');
            $table->string('currency', 3)->default('ETB');
            $table->string('channel', 20)->default('web');
            $table->string('terms_version');
            $table->string('privacy_version');
            $table->dateTime('expires_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedInteger('refund_amount')->nullable();
            $table->timestamp('anonymized_at')->nullable();
            $table->timestamps();
        });

        Schema::create('seat_holds', function (Blueprint $table) {
            $table->id();
            $table->foreignId('trip_id')->constrained()->cascadeOnDelete();
            $table->unsignedSmallInteger('seat_number');
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->dateTime('expires_at')->nullable(); // null = sold
            $table->timestamps();
            // The database itself guarantees a seat can never be held or sold twice.
            $table->unique(['trip_id', 'seat_number']);
        });

        Schema::create('passengers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('full_name'); // needed on the boarding manifest
            $table->unsignedSmallInteger('seat_number');
            $table->string('fare_category', 10)->default('adult'); // adult | child | student
            $table->unsignedInteger('fare');
            $table->string('qr_token', 40)->unique();
            $table->timestamp('checked_in_at')->nullable();
            $table->timestamp('boarded_at')->nullable();
            $table->timestamps();
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->constrained()->cascadeOnDelete();
            $table->string('provider', 20); // chapa | telebirr | transfer | cash
            $table->string('tx_ref', 40)->unique();
            $table->string('provider_reference')->nullable()->unique();
            $table->unsignedInteger('amount');
            // initiated | pending | succeeded | failed | refunded
            $table->string('status', 20)->default('initiated')->index();
            $table->string('checkout_url', 1000)->nullable();
            $table->json('provider_response')->nullable(); // sanitised: no card or wallet numbers
            $table->timestamp('verified_at')->nullable();
            $table->timestamps();
        });

        Schema::create('transfer_verifications', function (Blueprint $table) {
            $table->id();
            $table->foreignId('payment_id')->constrained()->cascadeOnDelete();
            $table->string('bank', 20);
            // One bank reference can only ever pay for one booking.
            $table->string('reference')->unique();
            $table->string('verify_et_id')->nullable();
            $table->unsignedInteger('verified_amount')->nullable();
            $table->string('receiver_account')->nullable();
            $table->dateTime('paid_at')->nullable();
            $table->json('checks')->nullable();
            // pending | auto_approved | needs_review | approved | rejected
            $table->string('decision', 20)->default('pending');
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('decided_at')->nullable();
            $table->timestamps();
        });

        Schema::create('consents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('booking_id')->nullable()->constrained()->cascadeOnDelete();
            $table->uuid('anonymous_id')->nullable()->index(); // cookie consent id, not linked to a person
            // terms | privacy | marketing_sms | marketing_email | cookies_analytics
            $table->string('type', 30);
            $table->string('version', 20);
            $table->boolean('granted');
            $table->string('source', 10); // web | mobile | counter
            $table->timestamp('created_at')->useCurrent();
            $table->index(['user_id', 'type']);
        });

        Schema::create('marketing_subscriptions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('channel', 10); // sms | email
            $table->string('address');
            $table->string('token', 64)->unique();
            $table->timestamp('subscribed_at');
            $table->timestamp('unsubscribed_at')->nullable();
            $table->timestamps();
            $table->unique(['channel', 'address']);
        });

        Schema::create('privacy_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('type', 10); // export | delete
            // scheduled | cancelled | completed
            $table->string('status', 20)->default('scheduled');
            $table->dateTime('run_after');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();
        });

        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            // Only a real, completed booking can leave a review — one per booking.
            $table->foreignId('booking_id')->unique()->constrained()->cascadeOnDelete();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->text('comment')->nullable();
            $table->boolean('published')->default(true);
            $table->text('operator_reply')->nullable();
            $table->timestamps();
        });

        Schema::create('otp_codes', function (Blueprint $table) {
            $table->id();
            $table->string('phone', 20)->index();
            $table->string('code_hash');
            $table->unsignedTinyInteger('attempts')->default(0);
            $table->dateTime('expires_at');
            $table->timestamps();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->index();
            $table->string('action', 60);
            $table->nullableMorphs('subject');
            $table->json('meta')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        foreach (['audit_logs', 'otp_codes', 'reviews', 'privacy_requests', 'marketing_subscriptions', 'consents',
            'transfer_verifications', 'payments', 'passengers', 'seat_holds', 'bookings', 'trips', 'routes',
            'buses', 'terminals', 'cities', 'operator_accounts', 'operators'] as $t) {
            Schema::dropIfExists($t);
        }
    }
};
