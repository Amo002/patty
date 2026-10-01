<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('menu_items', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            // G6: case-insensitive unique name. NOCASE is SQLite-only: on MySQL drop the
            // collation() call (the default utf8mb4_0900_ai_ci is case-insensitive); on
            // Postgres use citext or a unique index on lower(name).
            $table->string('name')->collation('NOCASE')->unique();
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('menu_items');
    }
};
