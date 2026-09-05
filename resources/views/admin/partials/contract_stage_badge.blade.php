{{--
    Hinweis-Badge fuer einen Vertrag, der aus einem AUFTRAG/ANTRAG entstanden
    ist und noch auf seine Vertragsbestaetigung/Police wartet (Betreiber-Vorgabe
    29.07.2026). Sobald das Bestaetigungs-Dokument hochgeladen wird, ergaenzt
    der Dokumenten-Eingang denselben Vertrag (Vertragsnummer, Kundennummer,
    Beginn, Abschlag) und der Hinweis verschwindet.
--}}
@if($contract->isApplication())
<span class="badge badge-pending nowrap"
      title="Aus einem Auftrag/Antrag angelegt. Sobald die Vertragsbestätigung (Police) hochgeladen wird, ergänzt das System automatisch Vertragsnummer und die endgültigen Angaben.">
    📝 Antrag – wartet auf Bestätigung
</span>
@endif
