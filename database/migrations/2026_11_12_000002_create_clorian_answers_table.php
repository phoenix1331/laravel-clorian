<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clorian_answers', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('weighing_id')->index()->constrained('clorian_weighings')->cascadeOnDelete();
            // Lengths keep the composite index under MySQL's 3072-byte key limit.
            $table->string('question', 100);
            // Top option, or "true" / "false" for a boolean.
            $table->string('value', 191)->nullable();
            $table->decimal('confidence', 5, 4);
            // Every option's probability, as the driver returned it.
            $table->json('scores');
            // Thresholds in force when this answer resolved; a null accept means always review.
            $table->decimal('accept_threshold', 5, 4)->nullable();
            $table->decimal('review_threshold', 5, 4)->nullable();
            $table->string('status', 20);
            $table->string('review_action', 20)->nullable();
            $table->string('reviewed_value', 191)->nullable();
            $table->nullableMorphs('reviewer');
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['status', 'created_at']);
            $table->index(['question', 'value', 'review_action']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clorian_answers');
    }
};
