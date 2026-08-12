<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// In-app support tickets (threaded, client ↔ admin). Tickets live on the
// CENTRAL connection (users/websites are central tables, so real FKs are
// fine — only tenant→central FKs are banned by the sharding policy).
// Additive only.
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('support_tickets', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUlid('website_id')->nullable(); // soft ref — triage context only
            $table->string('subject', 200);
            // open = awaiting our reply · answered = we replied, awaiting the
            // client · closed = done (client reply re-opens).
            $table->string('status', 12)->default('open');
            $table->timestamp('last_reply_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'last_reply_at']);
            $table->index(['user_id', 'created_at']);
        });

        Schema::create('support_ticket_messages', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->foreignUlid('ticket_id')->constrained('support_tickets')->cascadeOnDelete();
            $table->foreignUlid('user_id')->constrained()->cascadeOnDelete(); // author
            // Snapshot of the author's side at write time, so the thread
            // renders correctly even if an author's admin flag changes later.
            $table->boolean('is_admin')->default(false);
            $table->text('body');
            $table->timestamps();

            $table->index(['ticket_id', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('support_ticket_messages');
        Schema::dropIfExists('support_tickets');
    }
};
