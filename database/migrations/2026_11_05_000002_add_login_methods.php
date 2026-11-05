<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            // Accounts can now start from Google or username + password, so a phone is optional.
            // (A phone is still required on every booking, for the ticket SMS.)
            $table->string('phone', 20)->nullable()->change();
            $table->string('username', 30)->nullable()->unique()->after('name');
            $table->string('google_id', 64)->nullable()->unique()->after('email_verified_at');
        });

        Schema::table('otp_codes', function (Blueprint $table) {
            // Codes now go to a phone or an email (password reset), so widen the key.
            $table->string('phone', 191)->change();
            $table->string('purpose', 20)->default('login')->after('phone');
        });
    }

    public function down(): void
    {
        Schema::table('otp_codes', function (Blueprint $table) {
            $table->dropColumn('purpose');
        });
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['username']);
            $table->dropUnique(['google_id']);
            $table->dropColumn(['username', 'google_id']);
        });
    }
};
