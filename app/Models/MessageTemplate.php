<?php
namespace App\Models;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

/**
 * Wiederverwendbare Nachrichten-/E-Mail-Vorlage mit Platzhaltern.
 * Kategorien: 'kunde' (Portal-Nachricht/E-Mail an Kunden) und
 * 'gesellschaft' (E-Mail an Versicherer/Anbieter).
 */
class MessageTemplate extends Model
{
    protected $keyType = 'string';
    public $incrementing = false;

    public const CATEGORIES = ['kunde' => 'Kunde', 'gesellschaft' => 'Gesellschaft'];

    /** Unterstuetzte Platzhalter (Anzeige in der Verwaltung + Composer). */
    public const PLACEHOLDERS = [
        'anrede'        => 'Korrekte Briefanrede (z. B. "Sehr geehrter Herr Meyer")',
        'name'          => 'Voller Name des Kunden',
        'vorname'       => 'Vorname des Kunden',
        'nachname'      => 'Nachname des Kunden',
        'kundennummer'  => 'Kundennummer',
        'geburtsdatum'  => 'Geburtsdatum (TT.MM.JJJJ)',
        'berater'       => 'Name des angemeldeten Mitarbeiters',
        'datum'         => 'Heutiges Datum (TT.MM.JJJJ)',
    ];

    protected $fillable = ['name', 'category', 'subject', 'body', 'lang', 'sort', 'created_by'];

    protected static function boot() {
        parent::boot();
        static::creating(fn($m) => $m->id = $m->id ?: (string) Str::uuid());
    }

    public function creator() { return $this->belongsTo(User::class, 'created_by'); }

    /**
     * Ersetzt {{platzhalter}} durch echte Werte. Ohne Kunde bleiben
     * kundenbezogene Platzhalter sichtbar stehen, damit der Mitarbeiter
     * sie im Composer bewusst ergaenzt.
     */
    public static function renderText(string $text, ?Customer $customer, ?User $sender): string
    {
        $values = [
            'berater' => $sender?->name ?? '',
            'datum' => now()->format('d.m.Y'),
        ];
        if ($customer) {
            $name = trim((string) ($customer->user?->name ?? ''));
            $parts = $name !== '' ? preg_split('/\s+/', $name) : [];
            $values += [
                'anrede' => $customer->salutationLine(),
                'name' => $name,
                'vorname' => $parts[0] ?? '',
                'nachname' => count($parts) > 1 ? end($parts) : '',
                'kundennummer' => (string) $customer->customer_number,
                'geburtsdatum' => $customer->birth_date
                    ? \Carbon\Carbon::parse($customer->birth_date)->format('d.m.Y') : '',
            ];
        }
        return preg_replace_callback('/\{\{\s*([a-z]+)\s*\}\}/i', function ($m) use ($values) {
            $key = strtolower($m[1]);
            return array_key_exists($key, $values) ? $values[$key] : $m[0];
        }, $text);
    }

    public function renderBody(?Customer $customer, ?User $sender): string {
        return self::renderText($this->body, $customer, $sender);
    }

    public function renderSubject(?Customer $customer, ?User $sender): string {
        return self::renderText((string) $this->subject, $customer, $sender);
    }

    /**
     * Startpaket an Vorlagen (per Button in der Verwaltung anlegbar).
     * Bereits vorhandene Namen werden nicht doppelt angelegt.
     */
    public static function seedDefaults(?int $createdBy = null): int
    {
        $defaults = [
            ['category' => 'kunde', 'name' => 'Rueckruf-Bitte', 'sort' => 10,
                'subject' => 'Bitte um Rückruf – Dienstly24',
                'body' => "{{anrede}},\n\nwir haben versucht, Sie zu erreichen. Bitte rufen Sie uns zurück oder antworten Sie kurz mit einem Terminvorschlag – wir melden uns dann umgehend.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
            ['category' => 'kunde', 'name' => 'Fehlende Unterlagen', 'sort' => 20,
                'subject' => 'Fehlende Unterlagen zu Ihrem Vertrag',
                'body' => "{{anrede}},\n\nzur weiteren Bearbeitung fehlen uns noch folgende Unterlagen:\n\n- \n- \n\nSie können die Dokumente direkt im Kundenportal hochladen (Bereich „Dokumente“) oder auf diese Nachricht antworten.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
            ['category' => 'kunde', 'name' => 'Termin-Bestaetigung', 'sort' => 30,
                'subject' => 'Ihr Termin bei Dienstly24',
                'body' => "{{anrede}},\n\nhiermit bestätigen wir Ihren Termin am ____ um ____ Uhr.\n\nFalls der Termin nicht passt, geben Sie uns bitte kurz Bescheid.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
            ['category' => 'kunde', 'name' => 'Angebot nachfassen', 'sort' => 40,
                'subject' => 'Ihr Angebot von Dienstly24 – haben Sie noch Fragen?',
                'body' => "{{anrede}},\n\nvor einigen Tagen haben wir Ihnen ein Angebot zugesendet. Gerne besprechen wir offene Fragen oder passen das Angebot an Ihre Wünsche an.\n\nAntworten Sie einfach auf diese Nachricht oder rufen Sie uns an.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
            ['category' => 'kunde', 'name' => 'Dokument liegt bereit', 'sort' => 50,
                'subject' => 'Neues Dokument in Ihrem Kundenportal',
                'body' => "{{anrede}},\n\nin Ihrem Kundenportal liegt ein neues Dokument für Sie bereit (Bereich „Dokumente“).\n\nBei Fragen sind wir gerne für Sie da.\n\nMit freundlichen Grüßen\n{{berater}}\nIhr Dienstly24 Team"],
            ['category' => 'gesellschaft', 'name' => 'Statusanfrage Antrag', 'sort' => 10,
                'subject' => 'Statusanfrage – Kunde {{name}}, geb. {{geburtsdatum}}',
                'body' => "Sehr geehrte Damen und Herren,\n\nwir bitten um eine kurze Statusauskunft zum Antrag unseres Kunden:\n\nName: {{name}}\nGeburtsdatum: {{geburtsdatum}}\nVertrags-/Antragsnummer: [bitte ergänzen]\n\nVielen Dank vorab.\n\nMit freundlichen Grüßen\n{{berater}}\nDienstly24"],
            ['category' => 'gesellschaft', 'name' => 'Kuendigung Vertrag', 'sort' => 20,
                'subject' => 'Kündigung – {{name}}, Vertragsnummer [bitte ergänzen]',
                'body' => "Sehr geehrte Damen und Herren,\n\nnamens und in Vollmacht unseres Kunden kündigen wir den folgenden Vertrag fristgerecht zum nächstmöglichen Termin:\n\nVersicherungsnehmer: {{name}}\nGeburtsdatum: {{geburtsdatum}}\nVertragsnummer: [bitte ergänzen]\n\nBitte bestätigen Sie uns die Kündigung und das Vertragsende schriftlich.\n\nMit freundlichen Grüßen\n{{berater}}\nDienstly24"],
            ['category' => 'gesellschaft', 'name' => 'Maklervollmacht / Bestandsuebertragung', 'sort' => 30,
                'subject' => 'Bestandsübertragung – {{name}}, geb. {{geburtsdatum}}',
                'body' => "Sehr geehrte Damen und Herren,\n\nanbei erhalten Sie die Maklervollmacht unseres gemeinsamen Kunden:\n\nName: {{name}}\nGeburtsdatum: {{geburtsdatum}}\n\nWir bitten um Übertragung des Bestandes in unsere Betreuung und um eine kurze Bestätigung.\n\nMit freundlichen Grüßen\n{{berater}}\nDienstly24"],
        ];

        $created = 0;
        foreach ($defaults as $tpl) {
            if (!self::where('name', $tpl['name'])->where('category', $tpl['category'])->exists()) {
                self::create($tpl + ['lang' => 'de', 'created_by' => $createdBy]);
                $created++;
            }
        }
        return $created;
    }
}
