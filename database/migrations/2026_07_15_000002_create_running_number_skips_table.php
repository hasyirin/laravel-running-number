<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('running_number_skips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('running_number_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('formatted_number');
            $table->string('reason')->nullable();
            $table->unsignedInteger('reset_cycle');
            $table->dateTime('used_at')->nullable();
            $table->datetimes();

            $table->unique(['running_number_id', 'reset_cycle', 'number'], 'running_number_skips_number_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('running_number_skips');
    }
};
