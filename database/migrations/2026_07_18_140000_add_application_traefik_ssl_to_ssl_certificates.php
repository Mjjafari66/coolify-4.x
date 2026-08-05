<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->string('domain')->nullable()->after('is_proxy_certificate');
            $table->boolean('is_application_traefik_certificate')->default(false)->after('domain');
        });
    }

    public function down(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->dropColumn(['domain', 'is_application_traefik_certificate']);
        });
    }
};
