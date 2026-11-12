#!/bin/bash
# Fronius-Waechter - preupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER>
#
# Reihenfolge des Installers beim Upgrade (sbin/plugininstall.pl, Regeln/06):
#   preupgrade -> purge_installation (loescht config/plugins/<ordner>/ UND
#   data/plugins/<ordner>/) -> preinstall -> Dateien -> postinstall -> postupgrade
# Was das Upgrade ueberleben soll, wird HIER gerettet, NEBEN die Ordner.
# $3 ist der Ordnername, $5 die LoxBerry-Wurzel ($1 ist eine Zufallskennung).
# Bauform Ecowitt-Weiche 0.9.33.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-froniuswaechter}"
BASE="${ARGV5:-$LBHOMEDIR}"
case "$PFOLDER" in
    ''|*/*|*..*) echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER' - nichts gesichert, das Update wird abgebrochen."; exit 2 ;;
esac
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<FAIL> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts gesichert, das Update wird abgebrochen."
    exit 2
fi
# Als Erstes die Upgrade-Marke: preinstall.sh und postinstall.sh erkennen eine
# Aktualisierung allein an ihr; der Dienst ruht, solange sie juenger als 15 min ist.
MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
if date '+%Y-%m-%d %H:%M:%S' > "$MARKE" 2>/dev/null; then
    echo "<OK> Upgrade-Marke gesetzt: $MARKE"
else
    rm -f "$MARKE" 2>/dev/null
    echo "<FAIL> Die Marke $MARKE liess sich nicht anlegen - die Aktualisierung wird abgebrochen,"
    echo "<FAIL> die bisherige Fassung bleibt unveraendert installiert."
    exit 2
fi

CF="$BASE/config/plugins/$PFOLDER/froniuswaechter.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
KAP="$BASE/config/plugins/$PFOLDER.kaputt.json"

# Kopie als <Ziel>.neu.<pid>, 0600 BEVOR Inhalt hineinkommt, mit cmp geprueft,
# erst dann an ihren Platz: eine heile Sicherung bleibt heil.
zs_hinlegen() {   # $1 Quelle, $2 Ziel
    [ -d "$2" ] && return 1
    zs_neu="$2.neu.$$"
    rm -f "$zs_neu" 2>/dev/null
    if { : > "$zs_neu"; } 2>/dev/null && chmod 600 "$zs_neu" 2>/dev/null \
       && cp "$1" "$zs_neu" 2>/dev/null && cmp -s "$1" "$zs_neu" \
       && mv -f "$zs_neu" "$2" 2>/dev/null; then
        return 0
    fi
    rm -f "$zs_neu" 2>/dev/null
    return 1
}

# Inhalt heisst dasselbe wie in frw_hat_inhalt(): ein JSON-Objekt mit dem
# Schluessel aktionstoken. 0 = ja, 1 = leer, 3 = kein verwertbarer Stand,
# 2 = nicht pruefbar (kein php).
mit_inhalt() {
    [ -s "$1" ] || return 1
    php -r '$r = (string) @file_get_contents($argv[1]); $d = json_decode($r, true);
        if (is_array($d) && array_key_exists("aktionstoken", $d) && is_string($d["aktionstoken"])) { exit(0); }
        exit((trim($r) === "" || (is_array($d) && count($d) === 0)) ? 1 : 3);' -- "$1" 2>/dev/null
    RC=$?
    case "$RC" in
        0|1|3) return "$RC" ;;
    esac
    return 2
}

mit_inhalt "$CF"
case "$?" in
0)
    if zs_hinlegen "$CF" "$BK"; then
        echo "<OK> Konfiguration gesichert: $BK"
    else
        echo "<FAIL> Die Konfiguration liess sich nicht sichern - die Aktualisierung wird abgebrochen,"
        echo "<FAIL> damit der Purge das Original nicht loescht. Die bisherige Fassung bleibt installiert."
        rm -f "$MARKE" 2>/dev/null
        exit 2
    fi
    ;;
1)
    if [ -f "$BK" ]; then
        echo "<WARNING> froniuswaechter.json traegt keinen Stand; die vorhandene Zweitschrift bleibt"
        echo "<WARNING> unveraendert, aus ihr holt postinstall.sh die Einstellungen: $BK"
    else
        echo "<INFO> Keine Einstellungen vorhanden - es gibt nichts zu sichern."
    fi
    ;;
3)
    if [ -f "$BK" ]; then
        echo "<WARNING> froniuswaechter.json ist unlesbar; die vorhandene Zweitschrift bleibt unveraendert: $BK"
    elif zs_hinlegen "$CF" "$KAP"; then
        echo "<WARNING> froniuswaechter.json ist unlesbar und es gibt keine Zweitschrift. Der Stand liegt als $KAP"
        echo "<WARNING> und nach dem Update als froniuswaechter.json.kaputt im Konfigurationsordner."
    else
        echo "<FAIL> froniuswaechter.json ist unlesbar und liess sich nicht beiseitelegen - Abbruch."
        rm -f "$MARKE" 2>/dev/null
        exit 2
    fi
    ;;
*)
    echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?). Die Zweitschrift bleibt unveraendert: $BK"
    ;;
esac
echo "<OK> preupgrade abgeschlossen."
exit 0
