<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Per-user default + per-website override branding for outbound reports.
     * Exactly one of `user_id` / `website_id` is non-null per row.
     *
     * Resolution order (see ReportBrandingResolver):
     *   1. row where website_id = current website
     *   2. row where user_id = current user AND website_id IS NULL
     *   3. EBQ default (returned in-memory, no DB row)
     *
     * Gated behind the `report_whitelabel` plan feature. When the plan
     * disables the feature, this table is ignored and EBQ default
     * branding is used; rows are preserved so re-enabling lights them
     * up again with no migration.
     */
    public function up(): void
    {
        Schema::create('report_brandings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->cascadeOnDelete();
            $table->foreignId('website_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('company_name', 120);
            // Path on the `public` disk, NOT a full URL. Resolved via
            // Storage::disk('public')->url($logo_path) at render time so
            // moving the disk root doesn't invalidate stored rows.
            $table->string('logo_path', 255)->nullable();
            $table->string('accent_color', 7)->default('#4f46e5'); // hex including #
            $table->text('footer_text')->nullable();
            $table->string('contact_email', 191)->nullable();
            $table->string('contact_phone', 64)->nullable();
            $table->text('contact_address')->nullable();
            // Sets Reply-To header on outbound report emails when present.
            $table->string('reply_to_email', 191)->nullable();
            $table->timestamps();

            // One default per user, one override per website. Partial
            // uniques would be cleaner but MySQL doesn't support them
            // portably; full uniques on the non-null columns are fine
            // since exactly one is set per row.
            $table->unique('user_id');
            $table->unique('website_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_brandings');
    }
};
