<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('proposals', function (Blueprint $table): void {
            $table->id();
            $table->string('process');
            $table->string('system');
            $table->string('model');
            $table->unsignedBigInteger('record_id');
            $table->string('record_label');
            $table->json('values');
            $table->json('before')->nullable();
            $table->json('facts')->nullable();
            $table->string('message_id')->index();
            $table->text('quote');
            $table->string('quote_source')->nullable();
            $table->string('quote_location')->nullable();
            $table->string('quote_status')->nullable();
            $table->string('title');
            $table->text('summary');
            $table->string('tier');
            $table->string('status')->index();
            $table->string('rule')->nullable();
            $table->string('policy_section')->nullable();
            $table->json('reasons');
            $table->string('decided_by')->nullable();
            $table->string('decided_via')->nullable();
            $table->timestamp('decided_at')->nullable();
            $table->text('decision_reason')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->text('error')->nullable();
            $table->char('dedup_key', 64);
            $table->foreignId('replaces')->nullable()->constrained('proposals');
            $table->timestamps();

            $table->index(['model', 'record_id']);
        });

        // A failed or stale proposal does not block a new one with the same key.
        DB::statement(
            "CREATE UNIQUE INDEX proposals_dedup_key_unique ON proposals (dedup_key) WHERE status NOT IN ('failed', 'stale')"
        );
    }

    public function down(): void
    {
        Schema::dropIfExists('proposals');
    }
};
