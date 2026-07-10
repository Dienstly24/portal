<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/** Punkt 3: Banner-/Werbesystem im Kundenportal. */
return new class extends Migration {
    public function up(): void {
        Schema::create('banners', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('media_path');
            $table->string('media_type', 10)->default('image'); // image|video
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->timestamps();
            $table->index(['is_active', 'sort_order'], 'banners_active_sort_idx');
        });
    }
    public function down(): void { Schema::dropIfExists('banners'); }
};
