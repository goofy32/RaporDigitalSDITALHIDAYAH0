<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('notification_user_states', function (Blueprint $table) {
            $table->id();
            $table->foreignId('notification_id')->constrained()->onDelete('cascade');
            $table->string('user_type', 32);
            $table->unsignedBigInteger('user_id');
            $table->timestamp('read_at')->nullable();
            $table->timestamp('deleted_at')->nullable();
            $table->timestamps();

            $table->unique(['notification_id', 'user_type', 'user_id'], 'notification_user_state_unique');
            $table->index(['user_type', 'user_id', 'deleted_at'], 'notification_user_state_lookup');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('notification_user_states');
    }
};
