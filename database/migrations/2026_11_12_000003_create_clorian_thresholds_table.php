<?php

declare(strict_types=1);

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('clorian_thresholds', function (Blueprint $table): void {
            $table->id();
            $table->string('policy');
            $table->string('question', 100);
            // '' means the whole question. Not nullable: unique indexes treat NULLs as distinct on every engine.
            $table->string('option', 191)->default('');
            // A null accept means always review.
            $table->decimal('accept', 5, 4)->nullable();
            $table->decimal('review', 5, 4)->nullable();
            $table->nullableMorphs('tuned_by');
            $table->timestamps();

            $table->unique(['policy', 'question', 'option']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('clorian_thresholds');
    }
};
