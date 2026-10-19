<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('chat_conversation_id')->constrained()->cascadeOnDelete();
            $table->string('role'); // user, assistant
            $table->string('answer_type')->nullable(); // computed, advisory, unanswerable (assistant messages only)
            $table->text('content');
            $table->json('computed_spec')->nullable(); // MetricSpec used, so it can be pinned later
            $table->json('citation_data')->nullable(); // raw numbers/rows behind a computed answer
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_messages');
    }
};
