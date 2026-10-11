<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sale_return_lines', function (Blueprint $table) {
            $table->id();
            $table->foreignId('sale_return_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_line_id')->constrained()->restrictOnDelete();
            $table->unsignedInteger('quantity');
            $table->timestamps();
            // A sale line appears once per refund: duplicates are merged before they are stored.
            $table->unique(['sale_return_id', 'sale_line_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('sale_return_lines');
    }
};
