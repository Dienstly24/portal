{{-- Die Navigation der Beraterwelt.

     Der Inhalt steht NICHT hier, sondern in App\Support\Navigation\
     AdminNavigation - diese Datei bestimmt nur das Aussehen. Wer einen
     Bereich hinzufuegt, aendert genau eine Zeile PHP und sieht dabei die
     ganze Struktur; frueher stand jeder Punkt mit Rolle, Zaehler, Icon und
     Aktiv-Muster verwoben im Layout.

     Seit dem Umbau 06.09.2026 traegt die Seitenleiste nur noch den
     taeglichen Arbeitsweg. Vertrieb, Marketing und Administration liegen
     als Untermenues in der Einstellungen-Ansicht; der letzte Punkt fuehrt
     dorthin. --}}
@php($nav = \App\Support\Navigation\AdminNavigation::for(auth()->user()))
<nav class="sidebar-nav" aria-label="Hauptnavigation">
    <x-admin.nav-item :item="$nav->home()" />
    @foreach($nav->groups() as $group)
        <x-admin.nav-group :group="$group" />
    @endforeach
    {{-- Der Weg in die Verwaltung steht ausserhalb jeder Gruppe und immer
         ganz unten - er ist kein Arbeitsschritt, sondern ein Ort. --}}
    <div class="nav-sep" aria-hidden="true"></div>
    <x-admin.nav-item :item="$nav->settings()" />
</nav>
