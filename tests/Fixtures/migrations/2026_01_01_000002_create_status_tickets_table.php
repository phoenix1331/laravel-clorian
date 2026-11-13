<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/*
 * Tickets with the status columns already added, so tests never alter a
 * table mid-transaction (MySQL commits implicitly on ALTER TABLE).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('status_tickets', function (Blueprint $table): void {
            $table->id();
            $table->string('subject');
            $table->text('body');
            $table->string('queue')->nullable();
            $table->string('priority')->nullable();
            $table->boolean('needs_human')->nullable();
            $table->string('clorian_status', 20)->nullable()->index();
            $table->string('triage_state', 20)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('status_tickets');
    }
};
