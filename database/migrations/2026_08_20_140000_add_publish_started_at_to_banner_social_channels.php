<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Marker "eine Veroeffentlichung laeuft gerade".
 *
 * Der Sofort-Post ging bisher SYNCHRON im Web-Request raus. Bei Instagram
 * sind das im schlechtesten Fall drei Minuten (Container anlegen, bis zu
 * viermal auf die Verarbeitung warten, veroeffentlichen, Permalink holen) -
 * laenger als jede uebliche PHP-Laufzeitgrenze. Reisst der Request dabei ab,
 * kann der Beitrag auf Instagram bereits stehen, waehrend die App nichts
 * davon weiss: der naechste Klick postet ihn ein zweites Mal.
 *
 * Der Versand laeuft deshalb jetzt als Job. Dieses Feld ist die Klammer
 * darum: gesetzt = ein Versuch ist unterwegs, niemand sonst darf starten.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('banner_social_channels', function (Blueprint $table) {
            $table->timestamp('publish_started_at')->nullable()->after('auto_attempted_at');
        });
    }

    public function down(): void
    {
        Schema::table('banner_social_channels', function (Blueprint $table) {
            $table->dropColumn('publish_started_at');
        });
    }
};
