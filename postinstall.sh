#!/bin/bash
# Fronius-Waechter - postinstall
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASE> <TEMPFOLDER>
#
# postinstall laeuft IMMER, auch beim Upgrade; alles hier ist mehrfach
# ausfuehrbar. $3 ist der Ordnername, $5 die LoxBerry-Wurzel. Bauform
# Ecowitt-Weiche 0.9.33.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-froniuswaechter}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<FAIL> Unzulaessiger Ordnername '$PFOLDER'."; exit 1 ;;
esac

MARKE="$BASE/data/plugins/$PFOLDER.upgrade_laeuft"
UPGRADE=0
[ -f "$MARKE" ] && UPGRADE=1
trap 'rm -f "$MARKE" 2>/dev/null' EXIT

PCONFIG="$BASE/config/plugins/$PFOLDER"
PLOG="$BASE/log/plugins/$PFOLDER"
PDATA="$BASE/data/plugins/$PFOLDER"
PHTML="$BASE/webfrontend/html/plugins/$PFOLDER"
PBIN="$BASE/bin/plugins/$PFOLDER"

mkdir -p "$PCONFIG" "$PLOG" "$PDATA" || {
    echo "<FAIL> Ordner konnten nicht angelegt werden."
    exit 1
}
chmod 755 "$PDATA" "$PLOG" 2>/dev/null
# Die Konfiguration traegt das Aktionstoken - sie geht niemanden ausser dem Plugin etwas an.
chmod 700 "$PCONFIG" 2>/dev/null
CF="$PCONFIG/froniuswaechter.json"
BK="$BASE/config/plugins/$PFOLDER.backup.json"
KAP="$BASE/config/plugins/$PFOLDER.kaputt.json"
[ -f "$CF" ] || echo '{}' > "$CF"
chmod 600 "$CF" 2>/dev/null

mit_inhalt() {   # 0 = ja, 1 = leer, 3 = ohne verwertbaren Stand, 2 = nicht pruefbar
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
# Zurueckgespielt wird NUR bei einer Aktualisierung (Marke) und nur, wenn die
# Konfiguration keinen Inhalt traegt.
if [ "$UPGRADE" = 1 ] && [ -f "$BK" ]; then
    mit_inhalt "$CF"; CF_RC=$?
    if [ "$CF_RC" = 1 ] || [ "$CF_RC" = 3 ]; then
        mit_inhalt "$BK"; BK_RC=$?
        if [ "$BK_RC" = 0 ]; then
            if cp -p "$BK" "$CF" 2>/dev/null && chmod 600 "$CF" 2>/dev/null; then
                echo "<OK> Konfiguration aus der Zweitschrift wiederhergestellt."
            else
                echo "<WARNING> Die Zweitschrift liess sich nicht zurueckspielen: $BK"
            fi
        else
            echo "<WARNING> Die Zweitschrift traegt keinen verwertbaren Stand - nichts zurueckgespielt: $BK"
        fi
    elif [ "$CF_RC" = 2 ]; then
        echo "<WARNING> Der Inhalt von $CF liess sich nicht pruefen (fehlt php?). Nichts zurueckgespielt."
    fi
fi
if [ "$UPGRADE" = 1 ] && [ -f "$KAP" ]; then
    if mv -f "$KAP" "$CF.kaputt" 2>/dev/null && chmod 600 "$CF.kaputt" 2>/dev/null; then
        echo "<WARNING> Die unlesbare Konfiguration von vor dem Update liegt als $CF.kaputt."
    else
        echo "<WARNING> Die unlesbare Konfiguration von vor dem Update liegt weiter unter $KAP."
    fi
fi

if ! command -v php >/dev/null 2>&1; then
    echo "<FAIL> PHP wurde nicht gefunden. Ohne PHP laeuft weder der Dienst noch der Endpunkt."
    exit 1
fi

# Hausregel: den Dienst nach der Installation einmal von Hand aufrufen und den
# Rueckgabewert ansehen - hier der Selbsttest, der keinen Datamanager fragt.
if [ -f "$PBIN/frw_dienst.php" ]; then
    AUS=$(LBHOMEDIR="$BASE" php "$PBIN/frw_dienst.php" --selbsttest 2>&1)
    RC=$?
    LETZTE=$(echo "$AUS" | tail -1)
    if [ $RC -eq 0 ]; then
        echo "<OK> Selbsttest: $LETZTE"
    else
        echo "<FAIL> Selbsttest mit Rueckgabewert $RC:"
        echo "$AUS" | grep -E '^\[FEHL\]|Rechenkern|nicht gefunden' | tail -10 | sed 's/^/<FAIL> /'
    fi
else
    echo "<WARNING> $PBIN/frw_dienst.php wurde nicht gefunden - der Selbsttest entfaellt."
fi
if [ -f "$PHTML/frw_lib.php" ]; then
    AUS=$(FRWLIB="$PHTML/frw_lib.php" LBHOMEDIR="$BASE" php -r 'require getenv("FRWLIB"); echo frw_paths()["cfgdatei"];' 2>&1)
    if [ "$AUS" = "$CF" ]; then
        echo "<OK> Der Unterbau laedt und findet $AUS"
    else
        echo "<FAIL> Der Unterbau sucht seine Konfiguration an der falschen Stelle: $AUS (erwartet $CF)"
    fi
fi

if id loxberry >/dev/null 2>&1; then
    chown -R loxberry:loxberry "$PCONFIG" "$PLOG" "$PDATA" 2>/dev/null
    [ -f "$BK" ] && chown loxberry:loxberry "$BK" 2>/dev/null
fi
echo "<OK> Installation abgeschlossen. Der Dienst ist ab Werk aus; es wird nichts abgefragt,"
echo "<INFO> bis im Reiter Einstellungen mindestens ein Datamanager eingetragen und der Dienst eingeschaltet ist."
exit 0
