<?php

/*
 * Erzeugt docs/project-knowledge/ROUTES_INVENTORY.md aus der echten
 * Routentabelle. Rein lesend gegenueber der Anwendung.
 *
 * Aufruf (im Projektverzeichnis):
 *   php artisan route:list --json > /tmp/routes.json
 *   php scripts/wissensbasis-routen.php /tmp/routes.json
 *
 * Warum ein Skript statt Handpflege: ein von Hand gepflegtes Inventar von
 * 500 Routen ist nach dem dritten PR falsch, und ein falsches Inventar ist
 * schlimmer als keines (Definition of Done in CLAUDE.md).
 */

$quelle = $argv[1] ?? null;
if ($quelle === null || ! is_readable($quelle)) {
    fwrite(STDERR, "Aufruf: php scripts/wissensbasis-routen.php <routes.json>\n");
    exit(1);
}

$routen = json_decode((string) file_get_contents($quelle), true);
if (! is_array($routen)) {
    fwrite(STDERR, "Keine gueltige JSON-Routenliste.\n");
    exit(1);
}

usort($routen, fn ($a, $b) => strcmp($a['uri'], $b['uri']));

$zeilen = [
    '# Routen-Inventar (generiert)',
    '',
    'Generiert am '.date('d.m.Y').' aus `php artisan route:list --json` ('.count($routen).' Routen).',
    '**Nicht von Hand pflegen** - neu erzeugen mit `scripts/wissensbasis-routen.php` (Aufruf siehe Dateikopf).',
    'Schutz: `staff` = role:admin,manager,support,employee; bei mehreren role-Eintraegen gilt der ENGSTE (alle muessen passen).',
    '',
    '| Methode | URI | Name | Schutz |',
    '|---|---|---|---|',
];

foreach ($routen as $route) {
    $schutz = [];
    foreach ($route['middleware'] as $m) {
        if ($m === 'web') {
            continue;
        }
        if (str_starts_with($m, 'role:')) {
            $m = str_replace('admin,manager,support,employee', 'staff', $m);
        }
        if (str_contains($m, 'HealthToken')) {
            $m = 'healthtoken';
        }
        $schutz[] = $m;
    }
    $methode = str_replace('|HEAD', '', $route['method']);
    $zeilen[] = '| '.$methode.' | `'.$route['uri'].'` | '.($route['name'] ?? '').' | '.implode(' ', array_unique($schutz)).' |';
}

$ziel = __DIR__.'/../docs/project-knowledge/ROUTES_INVENTORY.md';
file_put_contents($ziel, implode("\n", $zeilen)."\n");
echo 'Geschrieben: '.count($routen)." Routen -> docs/project-knowledge/ROUTES_INVENTORY.md\n";
