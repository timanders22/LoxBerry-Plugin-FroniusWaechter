#!/bin/bash
# Fronius-Waechter - postupgrade
# command <ZUFALLSKENNUNG> <NAME> <FOLDER> <VERSION> <BASEFOLDER> <TEMPFOLDER>
#
# postinstall.sh laeuft beim Upgrade ohnehin und spielt die Zweitschrift
# zurueck. Hier werden nur Nebendateien eines abgebrochenen Schreibens
# abgeraeumt. Der Stand (data/plugins/<ordner>/stand.json) uebersteht ein
# Update NICHT: der erste Lauf danach ist ein erster Lauf und meldet nichts.
ARGV3=$3
ARGV5=$5
PFOLDER="${ARGV3:-froniuswaechter}"
BASE="${ARGV5:-$LBHOMEDIR}"
if [ -z "$BASE" ] || [ ! -d "$BASE" ]; then
    SELF=$(cd "$(dirname "$0")" && pwd)
    BASE=$(cd "$SELF/../.." 2>/dev/null && pwd)
fi
case "$PFOLDER" in
    ''|*/*|*..*) echo "<WARNING> Unzulaessiger Ordnername '$PFOLDER' - nichts abgeraeumt."; exit 0 ;;
esac
rm -f "$BASE/config/plugins/$PFOLDER/froniuswaechter.json.tmp."* \
      "$BASE/config/plugins/$PFOLDER.backup.json.tmp."* \
      "$BASE/config/plugins/$PFOLDER.backup.json.neu."* 2>/dev/null
echo "<OK> postupgrade abgeschlossen. Der letzte Stand der Abrufe beginnt neu; die Einstellungen sind erhalten."
exit 0
