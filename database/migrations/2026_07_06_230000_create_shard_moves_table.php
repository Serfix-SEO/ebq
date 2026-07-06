<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Progress tracking for tenant/crawl-site moves between DB shard nodes
 * (added 2026-07-06). Before this, a MoveShardJob was a black box: the
 * admin UI "polled the anchors to see completion" — i.e. you learned a
 * move finished only when it flipped, and learned it died only from
 * failed_jobs. ShardMover now writes a row here and updates it per phase
 * and per copy-chunk; the fleet page polls it live.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('shard_moves', function (Blueprint $table): void {
            $table->ulid('id')->primary();
            $table->string('kind', 12);                    // tenant | crawl
            $table->string('subject_id', 26);              // user id | crawl_site id
            $table->string('subject_label')->nullable();   // email | domain (display)
            $table->string('source_node_id', 26)->nullable();
            $table->string('target_node_id', 26);
            $table->string('status', 16)->default('counting'); // counting|copying|verifying|cutover|purging|completed|failed
            $table->string('current_table', 64)->nullable();
            $table->unsignedInteger('tables_total')->default(0);
            $table->unsignedInteger('tables_done')->default(0);
            $table->unsignedBigInteger('rows_total')->default(0);
            $table->unsignedBigInteger('rows_copied')->default(0);
            $table->json('table_counts')->nullable();      // per-table copied counts (final)
            $table->text('error')->nullable();
            $table->timestamp('started_at')->nullable();
            $table->timestamp('finished_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['kind', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('shard_moves');
    }
};
