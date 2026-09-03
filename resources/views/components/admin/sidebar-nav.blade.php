{{-- Die Navigation der Beraterwelt.

     Der Inhalt steht NICHT hier, sondern in App\Support\Navigation\
     AdminNavigation - diese Datei bestimmt nur das Aussehen. Wer einen
     Bereich hinzufuegt, aendert genau eine Zeile PHP und sieht dabei die
     ganze Struktur; frueher stand jeder Punkt mit Rolle, Zaehler, Icon und
     Aktiv-Muster verwoben im Layout. --}}
@php($nav = \App\Support\Navigation\AdminNavigation::for(auth()->user()))
<nav class="sidebar-nav" aria-label="Hauptnavigation">
    <x-admin.nav-item :item="$nav->home()" />
    @foreach($nav->groups() as $group)
        <x-admin.nav-group :group="$group" />
    @endforeach
</nav>
