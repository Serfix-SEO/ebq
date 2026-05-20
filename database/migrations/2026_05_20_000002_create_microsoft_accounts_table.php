<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Microsoft OAuth tokens for the "send report from Outlook" flow.
     * Mirrors the shape of `google_accounts` so MicrosoftOAuthService can
     * follow the same persistAccount → refresh pattern.
     */
    public function up(): void
    {
        Schema::create('microsoft_accounts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->text('access_token');
            $table->text('refresh_token')->nullable();
            $table->timestamp('expires_at')->nullable();
            // Microsoft Graph user id (sub claim in id_token). Keyed
            // alongside user_id so the same EBQ user can connect
            // separate work + personal Microsoft accounts later.
            $table->string('microsoft_id');
            // The email Outlook will send from. Cached at connect time
            // so we can show it in the settings UI without an extra
            // Graph call per render.
            $table->string('email', 191);
            $table->timestamps();

            $table->unique(['user_id', 'microsoft_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('microsoft_accounts');
    }
};
