<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('running_numbers', function (Blueprint $table) {
            $table->id();
            $table->string('type')->unique();

            $table->string('reset_interval')->default('never');
            $table->unsignedTinyInteger('reset_month')->nullable();
            $table->unsignedTinyInteger('reset_day')->nullable();
            $table->unsignedTinyInteger('reset_weekday')->nullable();
            $table->unsignedTinyInteger('reset_hour')->nullable();
            $table->string('timezone')->nullable();
            $table->unsignedInteger('reset_cycle')->default(1);

            $table->string('undo_mode')->default('reclaim');

            $table->string('format')->nullable();
            $table->unsignedInteger('number')->default(0);
            $table->unsignedInteger('padding')->default(5);
            $table->timestamp('last_reset_at')->useCurrent();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('running_numbers');
    }
};
