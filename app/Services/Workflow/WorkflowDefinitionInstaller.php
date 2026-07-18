<?php
namespace App\Services\Workflow;

use App\Models\WorkflowDefinition;
use App\Models\WorkflowPrompt;

/**
 * Installiert die eingebauten Workflow-Definitionen idempotent in die
 * Wissensdatenbank (workflow_definitions + workflow_prompts). Eine neue
 * Dienstleistung ist ein weiterer Eintrag hier - KEIN neuer Kern-Code
 * (Blueprint Saeule 1). Aufgerufen aus dem Artisan-Befehl `workflow:install`
 * und aus den Tests.
 *
 * Die Definitionen sind reine Konfiguration (Schritt-Liste, Felder, Prompts),
 * keine Kunden-PII.
 */
class WorkflowDefinitionInstaller
{
    /**
     * Alle eingebauten Definitionen installieren.
     *
     * @return list<WorkflowDefinition>
     */
    public function installAll(): array
    {
        return array_map(fn (array $def) => $this->install($def), $this->builtIn());
    }

    /**
     * Eine Definition (+ Prompts) idempotent anlegen/aktualisieren.
     *
     * @param array<string,mixed> $def
     */
    public function install(array $def): WorkflowDefinition
    {
        $definition = WorkflowDefinition::updateOrCreate(
            ['service_key' => $def['service_key'], 'version' => $def['version'] ?? 1],
            [
                'branch' => $def['branch'] ?? 'allgemein',
                'active' => $def['active'] ?? true,
                'title' => $def['title'],
                'description' => $def['description'] ?? null,
                'steps' => $def['steps'] ?? [],
                'required_documents' => $def['required_documents'] ?? [],
                'extraction_fields' => $def['extraction_fields'] ?? [],
                'intent_examples' => $def['intent_examples'] ?? [],
                'confidence_threshold' => $def['confidence_threshold'] ?? 90,
            ],
        );

        foreach ($def['prompts'] ?? [] as $type => $template) {
            WorkflowPrompt::updateOrCreate(
                ['workflow_definition_id' => $definition->id, 'type' => $type],
                ['template' => $template],
            );
        }

        return $definition;
    }

    /**
     * Katalog der eingebauten Definitionen.
     *
     * @return list<array<string,mixed>>
     */
    public function builtIn(): array
    {
        return [
            $this->bankverbindungAendern(),
        ];
    }

    /**
     * Sparte-uebergreifende Dienstleistung "Bankverbindung aendern":
     * Nachweis anfordern -> IBAN/Kontoinhaber extrahieren -> Aenderungsantrag
     * (Vier-Augen-Prinzip) -> Antwort-Entwurf zur Freigabe.
     *
     * @return array<string,mixed>
     */
    private function bankverbindungAendern(): array
    {
        return [
            'branch' => 'allgemein',
            'service_key' => 'bankverbindung_aendern',
            'version' => 1,
            'active' => true,
            'title' => 'Bankverbindung aendern',
            'description' => 'Neue Bankverbindung eines Kunden aus einem Nachweis uebernehmen (mit Mitarbeiter-Freigabe).',
            'confidence_threshold' => 90,
            'required_documents' => ['sepa_mandat'],
            'extraction_fields' => ['iban', 'account_holder'],
            'intent_examples' => [
                'Ich habe eine neue Bankverbindung.',
                'Meine IBAN hat sich geaendert.',
                'Bitte aktualisieren Sie meine Kontodaten.',
                'Neue Bankdaten fuer den Einzug.',
            ],
            'steps' => [
                [
                    'key' => 'dokument_anfordern',
                    'type' => 'request_document',
                    'config' => ['message' => 'Bitte laden Sie einen Nachweis der neuen Bankverbindung hoch (z.B. SEPA-Mandat oder Kopf eines Kontoauszugs).'],
                ],
                [
                    'key' => 'daten_extrahieren',
                    'type' => 'extract_data',
                    'config' => ['fields' => ['iban', 'account_holder']],
                ],
                [
                    'key' => 'bankdaten_uebernehmen',
                    'type' => 'apply_change',
                    'config' => ['change_type' => 'bank', 'fields' => ['iban', 'account_holder']],
                ],
                [
                    'key' => 'antwort_entwerfen',
                    'type' => 'draft_reply',
                    'config' => [],
                ],
            ],
            'prompts' => [
                'system' => 'Du unterstuetzt einen deutschen Versicherungsmakler. Antworte praezise und auf Deutsch.',
                'extraction' => "Extrahiere aus dem folgenden Dokumenttext die neue Bankverbindung des Kunden.\n"
                    . "Gesuchte Felder: {fields}.\n"
                    . "Gib AUSSCHLIESSLICH ein JSON-Objekt zurueck mit genau diesen Schluesseln plus \"confidence\" (0-100).\n"
                    . "Lass ein Feld weg, wenn es nicht eindeutig im Text steht.\n\nDOKUMENTTEXT:\n{text}",
                'reply' => 'Formuliere eine kurze, freundliche Bestaetigung auf Deutsch: Wir haben die neue Bankverbindung erhalten und pruefen sie. Nenne KEINE vollstaendige IBAN. Kontext (JSON): {context}',
            ],
        ];
    }
}
