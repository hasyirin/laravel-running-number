<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('running_number_undos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('running_number_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('number');
            $table->string('formatted_number');
            $table->string('mode');
            $table->string('reason')->nullable();
            $table->unsignedInteger('reset_cycle');
            $table->dateTime('reclaimed_at')->nullable();
            $table->datetimes();

            $table->unique(['running_number_id', 'number', 'reset_cycle'], 'running_number_undos_number_unique');
            $table->index(['running_number_id', 'reset_cycle', 'reclaimed_at', 'number'], 'running_number_undos_pending_index');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('running_number_undos');
    }
};
