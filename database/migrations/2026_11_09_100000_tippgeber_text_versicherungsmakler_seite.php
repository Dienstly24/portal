<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/*
 * Rolle Tippgeber (09.11.2026): Dienstly24 ist KEIN (gebundener) Vermittler nach
 * Paragraph 34d GewO. Der Seeder traegt bestehende Leistungsseiten nicht nach -
 * deshalb ersetzt diese Migration auf der LIVE-Seite /leistungen/versicherungsmakler
 * genau die betroffenen Saetze. Woertlicher Austausch: hat jemand den Text im Admin
 * schon geaendert, findet sich der alte Satz nicht und es passiert nichts.
 */
return new class extends Migration {
    private const ERSETZUNGEN = [
        [
            'Dienstly24 tritt dabei als vertraglich gebundener Vermittler unter dessen Haftung auf.',
            'Dienstly24 ist dabei Ihr Ansprechpartner für den Kontakt: Beratung und Vermittlung übernimmt unser Kooperationspartner in eigener Verantwortung.',
        ],
        [
            'bitte lesen Sie sie, bevor Sie uns beauftragen.',
            'bitte lesen Sie sie, bevor Sie einen Auftrag erteilen.',
        ],
        [
            'Mit der Maklervollmacht erlauben Sie uns, gegenüber',
            'Mit der Maklervollmacht erlauben Sie unserem Partnermakler, gegenüber',
        ],
        [
            'وDienstly24 بتشتغل كوسيط مرتبط تعاقدياً وتحت مسؤوليته.',
            'وDienstly24 بتوصلك فيه وبتتابع معك، أما الاستشارة والوساطة فبيقدّمها شريكنا تحت مسؤوليته الكاملة.',
        ],
        [
            'رجاءً اقرأها قبل ما توكّلنا.',
            'رجاءً اقرأها قبل ما تعطي أي توكيل.',
        ],
        [
            'بتوكيل الوساطة بتسمحلنا نمثّلك',
            'بتوكيل الوساطة بتسمح لوسيطنا الشريك يمثّلك',
        ],
    ];

    public function up(): void
    {
        $this->tausche(false);
    }

    public function down(): void
    {
        $this->tausche(true);
    }

    private function tausche(bool $zurueck): void
    {
        $seite = DB::table('service_pages')->where('slug', 'versicherungsmakler')->first();
        if ($seite === null) {
            return;
        }

        $updates = [];
        foreach (['body_de', 'body_ar'] as $spalte) {
            $text = (string) ($seite->{$spalte} ?? '');
            $neu = $text;
            foreach (self::ERSETZUNGEN as [$alt, $ersatz]) {
                if ($zurueck) {
                    $neu = str_replace($ersatz, $alt, $neu);
                } else {
                    $neu = str_replace($alt, $ersatz, $neu);
                }
            }
            if ($neu !== $text) {
                $updates[$spalte] = $neu;
            }
        }

        if ($updates !== []) {
            DB::table('service_pages')->where('id', $seite->id)->update($updates);
        }
    }
};
