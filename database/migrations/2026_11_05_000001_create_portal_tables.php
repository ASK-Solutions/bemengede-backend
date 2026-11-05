<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('drivers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->string('full_name');
            $table->string('phone', 20);
            $table->string('license_no');
            $table->date('license_expires_on');
            $table->string('status', 20)->default('active'); // active | inactive
            $table->timestamps();
            $table->unique(['operator_id', 'license_no']);
        });

        Schema::table('trips', function (Blueprint $table) {
            $table->foreignId('driver_id')->nullable()->after('bus_id')->constrained()->nullOnDelete();
            $table->unsignedSmallInteger('delay_minutes')->default(0)->after('status');
        });

        Schema::table('routes', function (Blueprint $table) {
            $table->unsignedInteger('default_fare')->nullable()->after('duration_minutes'); // santim
        });

        Schema::create('staff_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->nullable()->constrained()->cascadeOnDelete(); // null = platform staff
            $table->string('email');
            $table->string('name');
            $table->string('phone', 20);
            $table->string('role', 30);
            $table->string('token', 64)->unique();
            $table->dateTime('expires_at');
            $table->timestamp('accepted_at')->nullable();
            $table->foreignId('invited_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('operator_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->string('type', 30); // trade_license | tin | operating_license | other
            $table->string('path');
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->text('note')->nullable();
            $table->timestamps();
        });

        Schema::create('settlements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('operator_id')->constrained()->cascadeOnDelete();
            $table->date('period_start');
            $table->date('period_end');
            $table->string('channel', 20); // chapa | telebirr | transfer | cash
            $table->unsignedBigInteger('gross');
            $table->unsignedBigInteger('gateway_fee');
            $table->unsignedBigInteger('commission');
            $table->unsignedBigInteger('service_fees');
            $table->bigInteger('net_to_operator');
            // scheduled | paid | settled_by_provider | invoiced
            $table->string('status', 30)->default('scheduled');
            $table->string('payout_reference')->nullable();
            $table->timestamp('paid_at')->nullable();
            $table->timestamps();
            $table->unique(['operator_id', 'period_start', 'period_end', 'channel']);
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('settlement_id')->nullable()->after('booking_id')->constrained()->nullOnDelete();
        });

        Schema::create('disputes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('booking_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('operator_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('subject', 120);
            $table->text('description');
            $table->string('status', 20)->default('open'); // open | resolved | rejected
            $table->text('resolution')->nullable();
            $table->foreignId('resolved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('resolved_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('disputes');
        Schema::table('payments', fn (Blueprint $t) => $t->dropConstrainedForeignId('settlement_id'));
        Schema::dropIfExists('settlements');
        Schema::dropIfExists('operator_documents');
        Schema::dropIfExists('staff_invitations');
        Schema::table('routes', fn (Blueprint $t) => $t->dropColumn('default_fare'));
        Schema::table('trips', function (Blueprint $t) {
            $t->dropConstrainedForeignId('driver_id');
            $t->dropColumn('delay_minutes');
        });
        Schema::dropIfExists('drivers');
    }
};
