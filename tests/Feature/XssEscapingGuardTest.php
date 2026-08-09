<?php

namespace Tests\Feature;

use PHPUnit\Framework\TestCase;

/**
 * Audit SEC-2: Fruehere Stored-XSS-Stellen bauten Kundendaten (Name) bzw. den
 * Ticket-Betreff roh per innerHTML/Template-Literal in die Suche-Dropdowns.
 * Der Ticket-Betreff ist anonym befuellbar (Website-Anfrage) und traf die
 * globale Kopfzeilen-Suche jeder Admin-Seite. Diese Waechter-Tests schlagen an,
 * falls die escapeHtml-Absicherung an diesen Stellen wieder entfernt wird.
 */
class XssEscapingGuardTest extends TestCase
{
    private function view(string $rel): string
    {
        return (string) file_get_contents(dirname(__DIR__, 2) . '/resources/views/' . $rel);
    }

    public function test_global_search_dropdown_escapes_title_and_sub(): void
    {
        $c = $this->view('layouts/admin.blade.php');
        $this->assertStringContainsString('escapeHtml(item.title)', $c);
        $this->assertStringContainsString('escapeHtml(item.sub', $c);
        // Der rohe, unescapte Sink darf nicht zurueckkehren.
        $this->assertStringNotContainsString('${item.title}', $c);
    }

    public function test_customer_search_widgets_escape_name(): void
    {
        foreach ([
            'admin/employee_edit.blade.php',
            'admin/email_inbox.blade.php',
            'admin/email_message.blade.php',
            'admin/contract_new.blade.php',
        ] as $f) {
            $this->assertStringContainsString('escapeHtml(', $this->view($f), "escapeHtml fehlt in $f");
        }
        // Konkreter Sink: contract_new baute ${c.name} roh ins innerHTML.
        $this->assertStringContainsString('escapeHtml(c.name)', $this->view('admin/contract_new.blade.php'));
        $this->assertStringNotContainsString('">${c.name}<', $this->view('admin/contract_new.blade.php'));
    }

    public function test_banner_preview_escapes_title(): void
    {
        $this->assertStringContainsString('escapeHtml(title)', $this->view('admin/banners.blade.php'));
    }
}
