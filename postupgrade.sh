#!/bin/bash
# Fronius-Waechter - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin und spielt die Zweitschrift
# zurueck. Hier werden nur Nebendateien eines abgebrochenen Schreibens
# abgeraeumt. Der Stand (data/plugins/<ordner>/stand.json) uebersteht ein
# Update NICHT: der erste Lauf danach ist ein erster Lauf und meldet nichts -
# ausser mit dem Haken stand_retten (seit 0.9.7, ab Werk aus, siehe unten).
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-froniuswaechter}"
BASE="${ARGV5:-$LBHOMEDIR}"
# 0.9.7 (fremder Pruefer F1): dieselbe Wurzelpruefung wie in preinstall.sh und preupgrade.sh. Bis 0.9.6 galt ohne
# Verzeichnis $SELF/../.., und das Abraeumen unten loeschte in einem fremden Baum (nachgestellt, Fall N1b).
if [ -z "$BASE" ] || [ ! -d "$BASE/config/plugins" ] || [ ! -d "$BASE/data/plugins" ] \
   || [ ! -f "$BASE/config/system/general.json" ]; then
    echo "<WARNING> Kein LoxBerry-Wurzelverzeichnis erkannt ('$BASE') - nichts abgeraeumt."
    exit 0
fi
case "$PFOLDER" in
    ''|.|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts abgeraeumt."; exit 0 ;;
esac
rm -f "$BASE/config/plugins/$PFOLDER/froniuswaechter.json.tmp."* \
      "$BASE/config/plugins/$PFOLDER.backup.json.tmp."* \
      "$BASE/config/plugins/$PFOLDER.backup.json.neu."* 2>/dev/null
# 0.9.7 (fremder Pruefer F11): hat preupgrade.sh den Stand beiseitegelegt (nur mit dem Haken "Stand der Abrufe ueber
# ein Update behalten", stand_retten, ab Werk aus), kommt er in den neuen Datenordner zurueck - mit zs_hinlegen,
# wortgleich aus preupgrade.sh. Ohne beiseitegelegten Stand bleibt alles wie bis 0.9.6.
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
SN="$BASE/data/plugins/$PFOLDER.stand.json"
SJ="$BASE/data/plugins/$PFOLDER/stand.json"
if [ -f "$SN" ]; then
    if [ -d "$BASE/data/plugins/$PFOLDER" ] && [ ! -e "$SJ" ] && zs_hinlegen "$SN" "$SJ"; then
        rm -f "$SN" 2>/dev/null
        echo "<OK> postupgrade abgeschlossen. Der letzte Stand der Abrufe ist uebernommen (Haken stand_retten); die Einstellungen sind erhalten."
        exit 0
    fi
    rm -f "$SN" 2>/dev/null
    echo "<WARNING> Der beiseitegelegte Stand der Abrufe liess sich nicht zurueckholen - er beginnt neu."
fi
echo "<OK> postupgrade abgeschlossen. Der letzte Stand der Abrufe beginnt neu; die Einstellungen sind erhalten."
exit 0
