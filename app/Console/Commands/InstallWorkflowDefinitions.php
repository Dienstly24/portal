<?php
namespace App\Console\Commands;

use App\Services\Workflow\WorkflowDefinitionInstaller;
use Illuminate\Console\Command;

/**
 * Installiert/aktualisiert die eingebauten Workflow-Definitionen idempotent.
 * Nach einem Deploy einmal ausfuehren:  php artisan workflow:install
 */
class InstallWorkflowDefinitions extends Command
{
    protected $signature = 'workflow:install';
    protected $description = 'Installiert die eingebauten Workflow-Definitionen (idempotent) in die Wissensdatenbank';

    public function handle(WorkflowDefinitionInstaller $installer): int
    {
        $installed = $installer->installAll();

        foreach ($installed as $definition) {
            $this->info(sprintf(
                '- %s (v%d, %d Schritte) installiert.',
                $definition->service_key,
                $definition->version,
                count($definition->steps ?? []),
            ));
        }

        $this->info(count($installed) . ' Definition(en) installiert/aktualisiert.');
        return self::SUCCESS;
    }
}
