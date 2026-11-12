<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clorian_weighings', function (Blueprint $table): void {
            $table->id();
            // Null for subjectless Clorian::now() calls. No foreign key: deleting a subject must never fail because of Clorian.
            $table->nullableMorphs('subject');
            $table->string('policy');
            // The version that answered (jev-1.14.0), not the alias that was asked for.
            $table->string('model_version', 100)->nullable();
            $table->string('driver', 50);
            $table->string('status', 20);
            // sha256 of the content sent, so an unchanged save is not weighed again.
            $table->string('content_hash', 64)->nullable();
            // Encrypted, redacted input, per clorian.store_input.
            $table->text('input')->nullable();
            $table->text('error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamps();

            $table->index(['policy', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clorian_weighings');
    }
};
