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
        Schema::create('audit_log', function (Blueprint $table): void {
            $table->id();
            $table->timestamp('occurred_at');
            $table->string('request_id')->index();
            $table->string('actor_type');
            $table->string('actor');
            $table->string('channel');
            $table->string('event')->index();
            $table->string('client')->nullable();
            $table->string('tool')->nullable();
            $table->foreignId('proposal_id')->nullable()->index()->constrained('proposals');
            $table->string('outcome');
            $table->json('detail')->nullable();
        });

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_no_update BEFORE UPDATE ON audit_log
            BEGIN
                SELECT RAISE(ABORT, 'audit_log is append-only');
            END
            SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER audit_log_no_delete BEFORE DELETE ON audit_log
            BEGIN
                SELECT RAISE(ABORT, 'audit_log is append-only');
            END
            SQL);
    }

    public function down(): void
    {
        Schema::dropIfExists('audit_log');
    }
};
