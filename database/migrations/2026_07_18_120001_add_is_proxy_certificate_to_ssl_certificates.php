<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->boolean('is_proxy_certificate')->default(false)->after('is_ca_certificate');
        });
    }

    public function down(): void
    {
        Schema::table('ssl_certificates', function (Blueprint $table) {
            $table->dropColumn('is_proxy_certificate');
        });
    }
};
