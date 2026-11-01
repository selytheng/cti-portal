<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
            $table->boolean('mfa_enabled')->default(false)->after('must_change_password');
            $table->text('mfa_secret')->nullable()->after('mfa_enabled');
            $table->timestamp('mfa_verified_at')->nullable()->after('mfa_secret');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['must_change_password', 'mfa_enabled', 'mfa_secret', 'mfa_verified_at']);
        });
    }
};
