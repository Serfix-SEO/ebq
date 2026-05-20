<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-tenant outbound mail transport for branded reports. Resolution
     * mirrors ReportBrandingResolver: per-website override wins, else the
     * user's default, else the global Laravel mailer.
     *
     * Provider rows:
     *   - 'gmail'   → uses an existing `google_accounts` row identified by
     *                 `oauth_account_id`; sends via the Gmail send API.
     *   - 'outlook' → uses a `microsoft_accounts` row identified by
     *                 `oauth_account_id`; sends via Microsoft Graph
     *                 `/me/sendMail`.
     *   - 'smtp'    → uses the inline smtp_* columns on this row.
     *
     * Gated behind the `report_whitelabel` plan feature exactly like
     * `report_brandings`; rows are preserved when the feature is off.
     */
    public function up(): void
    {
        Schema::create('mail_transports', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->enum('provider', ['gmail', 'outlook', 'smtp']);
            $table->string('display_name', 120)->nullable();
            // The `From:` address shown to the recipient. For OAuth
            // providers this MUST match the connected account's email
            // (Gmail / Graph enforce this server-side).
            $table->string('from_address', 191);

            // OAuth row pointer. Polymorphic-ish — the `provider` column
            // selects which table this id lives in. Not a real FK because
            // it points to one of two tables.
            $table->unsignedBigInteger('oauth_account_id')->nullable();

            // SMTP inline credentials (provider = 'smtp' only).
            $table->string('smtp_host', 191)->nullable();
            $table->unsignedSmallInteger('smtp_port')->nullable();
            $table->string('smtp_username', 191)->nullable();
            // Encrypted at the model layer via the `encrypted` cast — the
            // raw column is `text` because Laravel's encrypter output is
            // base64 and grows with key rotation metadata.
            $table->text('smtp_password')->nullable();
            $table->enum('smtp_encryption', ['tls', 'ssl', 'none'])->default('tls');

            // Last-known health. `last_error` is null when the most
            // recent send (or test) succeeded; populated otherwise. The
            // settings UI surfaces both so the operator can see whether
            // the transport is still working.
            $table->timestamp('last_verified_at')->nullable();
            $table->text('last_error')->nullable();

            $table->timestamps();

            // One transport per scope: per-website OR per-user default
            // (website_id null). Same as branding.
            $table->unique(['user_id', 'website_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('mail_transports');
    }
};
