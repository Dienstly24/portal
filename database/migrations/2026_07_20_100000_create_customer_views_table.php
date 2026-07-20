<?php
use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

// Zuletzt geoeffnete Kunden pro Mitarbeiter: jeder Aufruf einer Kundenakte
// (AdminController::customerShow) aktualisiert hier viewed_at. Das Dashboard
// zeigt daraus die eigene, chronologisch aktuelle Liste - fuellt endlich das
// Versprechen "zuletzt GEOEFFNET" (vorher faelschlich "zuletzt ANGELEGT").
return new class extends Migration {
    public function up(): void {
        Schema::create('customer_views', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained()->cascadeOnDelete();
            $table->timestamp('viewed_at')->useCurrent();
            $table->timestamps();
            // Pro Mitarbeiter genau ein Eintrag je Kunde (updateOrCreate-Upsert).
            $table->unique(['user_id', 'customer_id']);
            // Deckt die Dashboard-Abfrage: eigene Views, nach Zeit absteigend.
            $table->index(['user_id', 'viewed_at']);
        });
    }

    public function down(): void {
        Schema::dropIfExists('customer_views');
    }
};
