<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Weblines: where a build fetches the app's code when its Git host (GitHub)
 * can't be reached, e.g. during an international outage. The portal keeps
 * that copy inside Iran and sets this URL through the API.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->text('git_mirror_url')->nullable()->after('git_commit_sha');
        });
    }

    public function down(): void
    {
        Schema::table('applications', function (Blueprint $table) {
            $table->dropColumn('git_mirror_url');
        });
    }
};
