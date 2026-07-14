<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marks rows written while an admin is impersonating a client, so the
 * "last activity" shown on the admin clients list (MAX(created_at) over
 * client_activities) reflects the client's own usage, not an admin poking
 * around inside their account during support/debugging.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('client_activities', function (Blueprint $table): void {
            $table->boolean('is_impersonated')->default(false)->after('actor_user_id');
            $table->index(['user_id', 'is_impersonated', 'created_at'], 'client_activities_user_impersonated_idx');
        });
    }

    public function down(): void
    {
        Schema::table('client_activities', function (Blueprint $table): void {
            $table->dropIndex('client_activities_user_impersonated_idx');
            $table->dropColumn('is_impersonated');
        });
    }
};
