<?php

namespace Tests\Feature\Security;

use Tests\TestCase;

/**
 * Sicherheits-Regressionstests der Abhaengigkeits-Pflege (Audit SEC-3).
 *
 * Der eigentliche Abgleich gegen die Schwachstellen-Datenbank laeuft in
 * der CI (`composer audit`) - er braucht Netz und gehoert nicht in die
 * Testsuite. Was hier geprueft wird, ist die MECHANIK: dass das Gate
 * ueberhaupt existiert und der Deploy daran haengt. Genau die faellt
 * sonst unbemerkt weg, wenn jemand den Workflow umbaut.
 */
class DependencyAuditTest extends TestCase
{
    public function test_ci_runs_composer_audit(): void
    {
        $this->assertStringContainsString('composer audit', $this->workflow(),
            'Die CI prueft die Abhaengigkeiten nicht mehr auf bekannte Schwachstellen.');
    }

    public function test_ci_runs_npm_audit(): void
    {
        $this->assertStringContainsString('npm audit', $this->workflow());
    }

    /** Der Deploy darf nur nach BEIDEN Gates laufen. */
    public function test_deploy_depends_on_tests_and_audit(): void
    {
        $workflow = $this->workflow();

        $this->assertMatchesRegularExpression('/^  audit:$/m', $workflow,
            'Der Audit-Job fehlt.');

        // needs des deploy-Jobs: "needs: [test, audit]"
        preg_match('/^  deploy:.*?^    needs:\s*(.+)$/ms', $workflow, $treffer);
        $this->assertNotEmpty($treffer, 'Der deploy-Job hat kein needs.');

        $needs = $treffer[1];
        $this->assertStringContainsString('test', $needs);
        $this->assertStringContainsString('audit', $needs,
            'Der Deploy haengt nicht mehr am Sicherheits-Gate - eine verwundbare Abhaengigkeit ginge live.');
    }

    /**
     * Kein --ignore-severity und kein sonstiger Weg, das Ergebnis
     * wegzuwischen. Ein Gate, das jeden Fehler schluckt, ist keins.
     */
    public function test_audit_result_is_not_suppressed(): void
    {
        // Nur der Abschnitt des audit-Jobs (bis zum naechsten Job auf
        // gleicher Einrueckung).
        preg_match('/^  audit:$(.*?)(?=^  [a-z-]+:$)/ms', $this->workflow(), $treffer);
        $this->assertNotEmpty($treffer, 'Der audit-Job liess sich nicht lesen.');

        // Kommentarzeilen raus: der Workflow ERKLAERT, warum kein
        // --ignore-severity gesetzt ist - die Erklaerung darf den Test
        // nicht ausloesen.
        $abschnitt = preg_replace('/^\s*#.*$/m', '', $treffer[1]);

        $this->assertStringNotContainsString('continue-on-error', $abschnitt,
            'Der Audit-Job darf Fehler nicht schlucken - dann ist er kein Gate.');
        $this->assertStringNotContainsString('--ignore-severity', $abschnitt);
        $this->assertStringNotContainsString('|| true', $abschnitt);
    }

    /** Dependabot ueberwacht composer UND npm. */
    public function test_dependabot_watches_composer_and_npm(): void
    {
        $pfad = base_path('.github/dependabot.yml');
        $this->assertFileExists($pfad, 'Dependabot ist nicht eingerichtet.');

        $config = (string) file_get_contents($pfad);

        $this->assertStringContainsString('package-ecosystem: composer', $config);
        $this->assertStringContainsString('package-ecosystem: npm', $config);
    }

    private function workflow(): string
    {
        $pfad = base_path('.github/workflows/deploy.yml');
        $this->assertFileExists($pfad);

        return (string) file_get_contents($pfad);
    }
}
