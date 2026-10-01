<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('purchase_order_lines', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            $table->foreignId('purchase_order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('ingredient_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity_ordered');
            // D-035: snapshot of the effective tolerance at line creation, so
            // later changes to an ingredient never alter an order in flight.
            $table->unsignedSmallInteger('over_tolerance_bps');
            $table->unsignedSmallInteger('under_tolerance_bps');
            $table->unsignedInteger('over_tolerance_cap')->nullable();
            $table->timestamps();

            $table->unique(['purchase_order_id', 'ingredient_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('purchase_order_lines');
    }
};
