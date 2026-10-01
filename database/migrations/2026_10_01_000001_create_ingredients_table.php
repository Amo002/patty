<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('ingredients', function (Blueprint $table) {
            $table->id();
            $table->char('ulid', 26)->unique();
            // G6: NOCASE makes the unique index case-insensitive, so "Beef" and
            // "beef" cannot coexist even past a race. MySQL needs no equivalent
            // because its default collations are already case-insensitive.
            $table->string('name')->collation('NOCASE')->unique();
            // D-015: one unit per ingredient, stored as the Unit enum value.
            $table->string('unit');
            // D-035: null means "use the unit default from config/patty.php".
            $table->unsignedSmallInteger('over_tolerance_bps')->nullable();
            $table->unsignedSmallInteger('under_tolerance_bps')->nullable();
            $table->unsignedInteger('over_tolerance_cap')->nullable();
            // D-036: seed photos only, never uploaded.
            $table->string('image_path')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('ingredients');
    }
};
