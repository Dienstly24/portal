<?php
namespace App\Console\Commands;

use App\Models\Task;
use App\Services\Notifications\NotificationService;
use App\Support\Facades\Notify;
use Illuminate\Console\Command;

/**
 * Wiedervorlage-Erinnerung: EIN gebuendelter Glocken-Hinweis je Mitarbeiter
 * ueber heute faellige und ueberfaellige Aufgaben ("Kunde in 10/20 Tagen
 * kontaktieren" geht so nicht mehr unter). Bewusst aggregiert statt je
 * Aufgabe - bei vielen offenen Aufgaben wuerde die Glocke sonst fluten.
 * dedup_key je Mitarbeiter: ein ungelesener Hinweis wird aufgefrischt.
 */
class RemindDueTasks extends Command
{
    protected $signature = 'tasks:remind';
    protected $description = 'Erinnert Mitarbeiter per Glocke an heute faellige und ueberfaellige Aufgaben';

    public function handle(): int
    {
        $byAssignee = Task::open()
            ->whereNotNull('assigned_to')
            ->whereNotNull('due_date')
            ->whereDate('due_date', '<=', today())
            ->orderBy('due_date')
            ->get()
            ->groupBy('assigned_to');

        $notified = 0;
        foreach ($byAssignee as $userId => $tasks) {
            $todayDue = $tasks->filter(fn(Task $t) => $t->due_date->isToday())->count();
            $overdue = $tasks->filter(fn(Task $t) => $t->due_date->lt(today()))->count();

            $parts = [];
            if ($todayDue > 0) $parts[] = $todayDue . ' heute fällig';
            if ($overdue > 0) $parts[] = $overdue . ' überfällig';
            if ($parts === []) continue;

            Notify::push((int) $userId, [
                'type' => NotificationService::TYPE_SYSTEM,
                'title' => 'Aufgaben: ' . implode(' · ', $parts),
                'body' => $tasks->take(3)->pluck('title')->implode(' · '),
                'link' => route('admin.tasks', ['tab' => 'mine', 'due' => $overdue > 0 ? 'overdue' : 'today']),
                'dedup_key' => 'tasks-due-' . $userId,
            ]);
            $notified++;
        }

        $this->info("$notified Mitarbeiter benachrichtigt.");
        return self::SUCCESS;
    }
}
