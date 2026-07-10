<?php
namespace App\Services\Workflow;

use App\Models\User;

/**
 * Systemweiter Zuweisungs-Fallback für automatisiert erzeugte Aufgaben
 * ohne konkreten Betreuer. Bewusst NICHT hart auf ID 1 verdrahtet (das
 * bricht auf einer frischen/leeren Instanz mit Foreign-Key-Fehler) -
 * stattdessen der erste Admin, sonst irgendein vorhandener Nutzer.
 * Gemeinsam genutzt von EmailWorkflowService und FondsFinanzImportService.
 */
class SystemUserResolver
{
    public function resolveId(): int
    {
        return User::where('role', 'admin')->value('id')
            ?? User::query()->value('id')
            ?? throw new \RuntimeException('Keine Benutzer im System vorhanden - automatisierte Aufgabe kann niemandem zugewiesen werden.');
    }
}
