<?php
namespace App\Mail;

use App\Models\Contract;
use Carbon\Carbon;
use Illuminate\Bus\Queueable;
use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Queue\SerializesModels;

/**
 * Spartenspezifische Wechsel-Erinnerung (Paket C). Ersetzt die frühere
 * pauschale ContractExpiryMail (30/14/7 Tage). stage: first | followup.
 */
class ContractSwitchMail extends Mailable
{
    use Queueable, SerializesModels;

    public string $lang;

    public function __construct(
        public Contract $contract,
        public string $stage,
        public ?string $unsubscribeUrl = null,
    ) {
        $this->lang = $contract->customer?->preferred_lang ?? 'de';
    }

    public function envelope(): Envelope
    {
        $de = [
            'kfz' => 'Ihre Kfz-Versicherung: Jetzt Wechsel prüfen und sparen',
            'strom' => 'Ihr Stromvertrag: Jetzt Tarife vergleichen und sparen',
            'gas' => 'Ihr Gasvertrag: Jetzt Tarife vergleichen und sparen',
            'strom_gas' => 'Ihr Energievertrag: Jetzt Tarife vergleichen und sparen',
            'internet' => 'Ihr Internetvertrag: Jetzt Wechsel prüfen und sparen',
            'krankenversicherung' => 'Ihre Krankenkasse: Wechsel jetzt möglich',
        ];
        $ar = [
            'kfz' => 'تأمين سيارتك: افحص إمكانية التبديل ووفّر الآن',
            'strom' => 'عقد الكهرباء: قارن الأسعار ووفّر الآن',
            'gas' => 'عقد الغاز: قارن الأسعار ووفّر الآن',
            'strom_gas' => 'عقد الطاقة: قارن الأسعار ووفّر الآن',
            'internet' => 'عقد الإنترنت: افحص إمكانية التبديل ووفّر',
            'krankenversicherung' => 'تأمينك الصحي: التبديل ممكن الآن',
        ];
        $map = $this->lang === 'ar' ? $ar : $de;
        $subject = $map[$this->contract->type] ?? ($this->lang === 'ar' ? 'عرض توفير على عقدك' : 'Sparpotenzial bei Ihrem Vertrag');
        if ($this->stage === 'followup') {
            $subject = ($this->lang === 'ar' ? 'تذكير: ' : 'Erinnerung: ') . $subject;
        }
        return new Envelope(subject: $subject);
    }

    public function content(): Content
    {
        return new Content(view: 'emails.contract_switch', with: [
            'gkvActiveFrom' => $this->gkvActiveFrom(),
        ]);
    }

    /**
     * GKV (§175 SGB V): Antragsmonat zählt nicht, danach 2 volle
     * Kalendermonate -> neue Kasse aktiv ab dem 1. des übernächsten
     * Folgemonats (Antrag im Juli -> aktiv 1. Oktober).
     */
    public function gkvActiveFrom(): ?Carbon
    {
        if ($this->contract->type !== 'krankenversicherung') return null;
        return now()->startOfMonth()->addMonths(3);
    }
}
