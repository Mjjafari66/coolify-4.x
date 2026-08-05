<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->boolean('manual_ssl_only')->default(false)->after('is_force_https_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('application_settings', function (Blueprint $table) {
            $table->dropColumn('manual_ssl_only');
        });
    }
};
