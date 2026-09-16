#!/usr/bin/env bash
#
# SEC-2: Netzwerk- und Server-Pruefung (Audit 15.09.2026).
#
# NUR LESEND. Das Skript aendert NICHTS - keine Firewall-Regel, keinen
# Dienst, keine Datei. Es beantwortet Fragen, die sich im Repository
# nicht beantworten lassen, weil sie vom Server abhaengen:
#
#   1. Steht wirklich ein CDN/Proxy davor - und welcher?
#   2. Ist der Origin AM PROXY VORBEI per IP erreichbar?
#      (Dann laufen WAF, Bot- und DDoS-Schutz ins Leere.)
#   3. Welche Ports sind offen? Haengt die Datenbank am Netz?
#   4. Laeuft eine Host-Firewall - und wenn ja, mit welchen Regeln?
#   5. Wie ist der SSH-Zugang konfiguriert?
#   6. Sieht die Anwendung die echte Client-IP?
#
# WARUM NICHT AUTOMATISCH SCHARFSCHALTEN: eine Firewall, die blind
# aktiviert wird, sperrt im schlechtesten Fall den SSH-Zugang aus - und
# zwar genau den, ueber den man sie wieder abschalten muesste. Deshalb
# MISST dieses Skript nur und SCHLAEGT einen Regelsatz VOR. Scharf macht
# ihn ein Mensch, nach dem Ablauf in docs/SICHERHEIT_NETZWERK_ORIGIN.md.
#
# Aufruf auf dem VPS:
#   bash scripts/netz-pruefen.sh              # Kurzfassung
#   bash scripts/netz-pruefen.sh --vorschlag  # zusaetzlich Firewall-Vorschlag
#
set -uo pipefail

cd "$(dirname "$0")/.."

HOSTS="${NETZ_HOSTS:-www.dienstly24.de portal.dienstly24.de admin.dienstly24.de}"
BEFUNDE=0
WARNUNGEN=0

titel() { printf '\n\033[1m%s\033[0m\n' "$1"; }
ok()    { printf '  \033[32m✓\033[0m %s\n' "$1"; }
warn()  { printf '  \033[33m!\033[0m %s\n' "$1"; WARNUNGEN=$((WARNUNGEN+1)); }
fund()  { printf '  \033[31m✗\033[0m %s\n' "$1"; BEFUNDE=$((BEFUNDE+1)); }
info()  { printf '    %s\n' "$1"; }

# ---------------------------------------------------------------- 1) Edge
titel "1) Was steht vor der Anwendung?"
for h in $HOSTS; do
  kopf="$(curl -sS -I --max-time 15 "https://$h/" 2>/dev/null || true)"
  if [ -z "$kopf" ]; then
    warn "$h: keine Antwort (DNS/Netz/TLS?)"
    continue
  fi
  server="$(printf '%s' "$kopf" | grep -i '^server:' | head -1 | cut -d' ' -f2- | tr -d '\r')"
  if printf '%s' "$kopf" | grep -qi '^cf-ray:'; then
    ok "$h: Cloudflare (cf-ray vorhanden, server: ${server:-?})"
  elif [ -n "$server" ]; then
    warn "$h: Edge ist '${server}' - NICHT Cloudflare."
    info "Die Cloudflare-Ranges in config/trustedproxy.php passen dann nicht zum echten Proxy."
    info "Sie sind dadurch nicht unsicher (zu kleine Liste = zu wenig Vertrauen), aber wirkungslos."
  else
    warn "$h: kein Server-Header - Edge nicht bestimmbar."
  fi
done

# ------------------------------------------------------------- 2) Origin
titel "2) Ist der Origin am Proxy vorbei erreichbar?"
eigene_ip="$(curl -sS --max-time 10 https://api.ipify.org 2>/dev/null || true)"
if [ -z "$eigene_ip" ]; then
  warn "Eigene oeffentliche IP nicht ermittelbar - Pruefung uebersprungen."
else
  info "Oeffentliche IP dieses Servers: $eigene_ip"
  for h in $HOSTS; do
    # Den Host-Header setzen und die IP direkt ansprechen: antwortet die
    # Anwendung, laeuft jeder Schutz am Edge ins Leere.
    code="$(curl -sS -o /dev/null -w '%{http_code}' --max-time 15 \
      --resolve "$h:443:$eigene_ip" "https://$h/" 2>/dev/null || echo 000)"
    if [ "$code" = "000" ]; then
      ok "$h ueber die Origin-IP: keine Antwort (gut - der Origin ist nicht direkt ansprechbar)"
    else
      fund "$h antwortet DIREKT auf der Origin-IP (HTTP $code)."
      info "Jeder, der die IP kennt, umgeht damit WAF, Bot- und DDoS-Schutz."
      info "Abhilfe: am Edge/Hoster nur die Proxy-Adressen auf 80/443 zulassen."
    fi
  done
fi

# -------------------------------------------------------------- 3) Ports
titel "3) Offene Ports auf diesem Server"
if command -v ss >/dev/null 2>&1; then
  lauschend="$(ss -tlnp 2>/dev/null | tail -n +2)"
  printf '%s\n' "$lauschend" | awk '{print "    "$4"  "$6}' | head -20

  # Die gefaehrlichen Faelle: Datenbank/Cache/Queue auf einer OEFFENTLICHEN
  # Adresse. Auf 127.0.0.1 sind sie richtig aufgehoben.
  for port_name in "3306:MySQL" "5432:PostgreSQL" "6379:Redis" "11211:Memcached" "27017:MongoDB"; do
    port="${port_name%%:*}"; name="${port_name##*:}"
    if printf '%s\n' "$lauschend" | awk '{print $4}' | grep -qE "^(0\.0\.0\.0|\*|\[::\]):$port$"; then
      fund "$name lauscht auf ALLEN Adressen (Port $port) - gehoert auf 127.0.0.1."
    elif printf '%s\n' "$lauschend" | awk '{print $4}' | grep -qE ":$port$"; then
      ok "$name lauscht lokal (Port $port)"
    fi
  done
else
  warn "'ss' nicht verfuegbar - Portpruefung uebersprungen (apt install iproute2)."
fi

# ----------------------------------------------------------- 4) Firewall
titel "4) Host-Firewall"
if command -v ufw >/dev/null 2>&1; then
  status="$(ufw status 2>/dev/null | head -1)"
  if printf '%s' "$status" | grep -qi 'active'; then
    ok "ufw ist aktiv"
    ufw status numbered 2>/dev/null | tail -n +4 | head -15 | sed 's/^/    /'
  else
    fund "ufw ist INAKTIV - es gibt keine Host-Firewall."
    info "Jeder offene Port ist damit genau so erreichbar, wie der Dienst ihn anbietet."
    info "Vorschlag fuer einen Regelsatz: bash scripts/netz-pruefen.sh --vorschlag"
  fi
elif command -v nft >/dev/null 2>&1 && nft list ruleset 2>/dev/null | grep -q 'chain input'; then
  ok "nftables-Regelsatz vorhanden"
else
  fund "Keine Host-Firewall gefunden (weder ufw noch nftables)."
fi

# --------------------------------------------------------------- 5) SSH
titel "5) SSH-Zugang"
sshd="/etc/ssh/sshd_config"
if [ -r "$sshd" ]; then
  wert() { grep -Ei "^[[:space:]]*$1[[:space:]]" "$sshd" 2>/dev/null | tail -1 | awk '{print tolower($2)}'; }
  port="$(wert Port)"; port="${port:-22}"
  info "Port: $port"

  case "$(wert PermitRootLogin)" in
    yes) fund "PermitRootLogin yes - Anmeldung als root ist erlaubt." ;;
    ''|prohibit-password|without-password) ok "root-Anmeldung nur mit Schluessel (oder Vorgabe)" ;;
    no) ok "root-Anmeldung abgeschaltet" ;;
  esac

  case "$(wert PasswordAuthentication)" in
    no) ok "Passwort-Anmeldung abgeschaltet (nur Schluessel)" ;;
    yes) fund "PasswordAuthentication yes - SSH ist mit Passwort angreifbar." ;;
    *) warn "PasswordAuthentication nicht gesetzt - es gilt die Vorgabe (meist yes)." ;;
  esac
else
  warn "$sshd nicht lesbar - SSH-Pruefung uebersprungen (als root ausfuehren)."
fi

# ---------------------------------------------------------- 6) Client-IP
titel "6) Sieht die Anwendung die echte Client-IP?"
if [ -f artisan ]; then
  php artisan netz:client-ip-pruefen 2>/dev/null | sed 's/^/    /' || \
    warn "Befehl 'netz:client-ip-pruefen' nicht verfuegbar."
else
  warn "Nicht im Anwendungsverzeichnis - uebersprungen."
fi

# ------------------------------------------------------ Firewall-Vorschlag
if [ "${1:-}" = "--vorschlag" ]; then
  titel "Vorschlag fuer einen Firewall-Regelsatz (wird NICHT ausgefuehrt)"
  ssh_port="22"
  [ -r "$sshd" ] && ssh_port="$(grep -Ei '^[[:space:]]*Port[[:space:]]' "$sshd" 2>/dev/null | tail -1 | awk '{print $2}')"
  ssh_port="${ssh_port:-22}"
  cat <<VORSCHLAG
    # ------------------------------------------------------------------
    # REIHENFOLGE IST WICHTIG. Zuerst SSH erlauben, DANN die Vorgabe auf
    # "alles ablehnen" stellen, erst ZULETZT einschalten. Wer zuerst
    # einschaltet, sperrt sich aus - und zwar ueber genau die Verbindung,
    # die er zum Reparieren braeuchte.
    #
    # Vorher pruefen, ob $ssh_port wirklich der eigene SSH-Port ist:
    #   ss -tlnp | grep sshd
    # Und die laufende Sitzung NICHT schliessen, bis eine ZWEITE
    # Verbindung nachweislich funktioniert.
    # ------------------------------------------------------------------

    ufw allow $ssh_port/tcp comment 'SSH'      # ZUERST
    ufw allow 80/tcp        comment 'HTTP'
    ufw allow 443/tcp       comment 'HTTPS'
    ufw default deny incoming
    ufw default allow outgoing

    # Probe OHNE Scharfschalten - zeigt, was die Regeln bewirken wuerden:
    ufw --dry-run enable

    # Erst wenn die Ausgabe stimmt UND eine zweite SSH-Sitzung offen ist:
    ufw enable
    ufw status numbered

    # Zurueck, falls etwas klemmt:
    ufw disable
VORSCHLAG
fi

titel "Ergebnis"
printf '  %d Befund(e), %d Warnung(en)\n' "$BEFUNDE" "$WARNUNGEN"
printf '  Einordnung und Ergebnistabelle: docs/SICHERHEIT_NETZWERK_ORIGIN.md\n'
[ "$BEFUNDE" -gt 0 ] && exit 1
exit 0
