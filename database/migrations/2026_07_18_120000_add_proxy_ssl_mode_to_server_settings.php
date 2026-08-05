<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->string('proxy_ssl_mode')->default('letsencrypt')->after('wildcard_domain');
        });
    }

    public function down(): void
    {
        Schema::table('server_settings', function (Blueprint $table) {
            $table->dropColumn('proxy_ssl_mode');
        });
    }
};
