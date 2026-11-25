# Fronius-Wächter

Version 0.9.10 · LoxBerry ab 3.0 · PHP 7.4 bis 8.5 · noch auf keinem LoxBerry installiert

## Neu in 0.9.10

- **Themen-Präfix auf einem gemeinsamen Bereich (Entscheidung des Hausherrn 23.11.2026 „Nur gemeinsame Bereiche“):** Ein MQTT-Präfix, das einer der gemeinsamen Bereiche unter `haus/` ist oder darin beginnt (`haus/<bereich>` bzw. `haus/<bereich>/…` für abfahrt, abfuhr, alarm, ansage, anwesenheit, energie, fahrzeug, kalender, klima, pv, raumklima, sonne, speicher, strompreis, szene, tag, tuer, usv, wetter), weisen Speichern im Reiter *MQTT* und das Zurückspielen einer Sicherung jetzt mit Namen ab: Dort liefern die Plugins einander Zustände ohne eigenes Präfix. `haus` allein und andere Präfixe unter `haus/` (etwa `haus/fenster`) bleiben erlaubt; Groß und Klein zählen wie bei MQTT. Ist ein solches Präfix schon gespeichert, ändert sich am Betrieb nichts – das Plugin sendet weiter darunter, nennt es einmal im Protokoll, und der Reiter *Test* zeigt die Zeile „Liegt das Themen-Präfix außerhalb der gemeinsamen Bereiche unter haus/?“ rot. Liste und Texte kommen aus dem gemeinsamen Modul `haus_bereiche.php` (in diesem Plugin lässt das Muster keinen Schrägstrich zu, ein solches Präfix ist also schon bisher weder einzugeben noch zu betreiben).
- **Gespeicherte Einstellungen, die beim Laden nicht gelten (Entscheidung des Hausherrn 23./24.11.2026, Regeln/05 „Lade-Beanstandungen“):** Ungültige Werte der Konfigurationsdatei wurden schon bisher abgewiesen und im Reiter *Test* gemeldet. Ein gelber Kasten über den Reitern nennt jetzt jedes Feld mit gespeichertem und verwendetem Wert, die Selbstprüfung im Reiter *Test* zeigt die Zeile „Gelten die gespeicherten Einstellungen beim Laden so, wie sie gespeichert sind?“ rot, und das Protokoll nennt die Namen einmal je Änderung. Bis 0.9.9 schrieb das Anlegen eines fehlenden Aktionstokens die Vorgaben statt der gespeicherten Werte in die Datei; jetzt schreibt es die gespeicherten Werte und das neue Token. Ein vorhandenes Aktionstoken bleibt, wie es ist (gemeldet, wenn seine Form nicht stimmt). Speichern behält bei einem genannten Feld, das Sie nicht ändern, den gespeicherten Wert, und die angezeigten Werte übernimmt nur der Knopf „Angezeigte Werte übernehmen“ mit gesetztem Haken. Token und Zugangsdaten erscheinen in keiner Meldung mit ihrem Wert, das Protokoll nennt keinen Wert. Gemeinsames Sicherungsmodul 1.3.0.
- **Statusmeldungen an SignalBot (Auftrag des Hausherrn 23.11.2026):** Im Reiter *Einstellungen* steht unter den Sprachansagen der neue Block „Meldungen an SignalBot“, ab Werk aus. Eingeschaltet schickt das Plugin dieselben Anlässe, die es ansagen kann – je Anlass eigener Haken, auch ohne eingerichtete Ansage – als Nachricht über MQTT an das Plugin SignalBot (Thema `haus/meldung/<ordner>`, nie retained; SignalBot ab 0.9.44 mit „Meldungen unter haus/meldung annehmen“ und diesem Ordner unter „Meldungen anderer Plugins annehmen von“). Dieselbe Meldung frühestens wieder nach der eingestellten Sperre (Vorgabe 60 Minuten), „wieder da“ nur nach einer gemeldeten Störung, höchstens 10 Nachrichten je Stunde, darüber eine Sammelzeile; die Nachtruhe stellt SignalBot. Ein Text, der wie ein Zugangsdatum aussieht, geht nicht hinaus. Der Knopf „Testmeldung senden“ prüft den Weg mit Quittung, die Zeile darunter nennt die letzte. Gemeinsames Modul `meldung.php`, Texte im Abschnitt `[SIGNALMELDUNG]`.

## Neu in 0.9.9

- Gemeinsames Sprachmodul 1.5.0 (Entscheidung des Hausherrn 22.11.2026): Die Nachtruhe der Ansagen kann an Nicht-Schultagen (Samstag, Sonntag, Feiertage, Ferien) später enden. Neues Feld „Nachtruhe an Nicht-Schultagen bis“ bei „Wann gesprochen wird“, ab Werk leer: Dann gilt die Zeit, die im Plugin Ferien und Feiertage eingestellt ist (MQTT-Thema `haus/ansage/nachtruhe_frei_bis`); ohne sie endet die Nachtruhe wie an Schultagen. Ob heute ein Schultag ist, sagt `haus/kalender/heute/frei` (nur bei `haus/kalender/datum` = heute); ohne diese Aussage zählen nur Samstag und Sonntag. Dringende Ansagen übergehen die Nachtruhe wie bisher. Ohne Vorgabe und ohne eigene Zeit ändert sich nichts.
- Reiter Test: neue Zeile zum Ende der Nachtruhe an Nicht-Schultagen (Quelle der Zeit, Art des heutigen Tages).

## Neu in 0.9.8

- **Berichtigt – Sprachdatei im installierten Zustand ohne LoxBerry-Wurzel:** Findet die Oberfläche weder `LBHOMEDIR` noch die `general.json`, sucht sie die Sprachdatei über den eigenen Pfad. Dieser Pfad war bis 0.9.7 eine Ebene zu kurz und zeigte auf `webfrontend/templates/…` statt auf `templates/plugins/…`; die Suche fiel dann auf den Pfad des entpackten Archivs zurück, und der Selbsttest meldete einen Fehlschlag bei den Ansagen. Mit gesetztem `LBHOMEDIR` – dem Regelfall – war nichts zu merken. Gemessen auf dem Arbeitsrechner (PHP 7.4 und 8.4, nachgebildete Installation); am Gerät nicht gemessen.

## Neu in 0.9.7

- Die Kopfzeile zeigt jetzt die installierte Fassung in derselben Form wie alle Plugins (bisher die eingetragene Konstante FRW_FASSUNG, angezeigt wurde 0.9.3).
- **Geändert – Fassung auch in Kennung, Stand und Selbsttest:** Die Kennung beim Abruf der Solar API (`User-Agent`), der Stand des letzten Laufs und die Schlusszeile des Selbsttests nennen die Fassung jetzt aus demselben gemeinsamen Stück wie die Kopfzeile (`fassung.php`). Bis 0.9.6 stand dort fest 0.9.3. Findet sich keine Fassung, steht keine Nummer da statt einer geratenen.
- **Neu – Stand der Abrufe über ein Update behalten (ab Werk aus):** Mit dem Haken im Reiter *Einstellungen* legt das Update den Stand neben den Datenordner und holt ihn danach zurück. Ohne ihn beginnt der Stand wie bisher neu: der erste Lauf nach dem Update meldet nichts, und eine Störung, die während des Updates beginnt, wird nicht gemeldet, solange sie anhält.
- **Neu – kompakte Abfrage als JSON (ab Werk aus):** `index.php?aktion=json` liefert je Datamanager Zustand und Alter, je Wechselrichter Status, Fehlercode und Störung, je Speicher Ladestand, Zahl der Module, Streuung des Ladestands und größte Zellspannungsdifferenz – etwa für das Dashboard. Offen wie die Abfrage je Datamanager und nur lesend; ohne den Haken antwortet der Endpunkt wie bisher.
- **Geändert – die Deinstallation leert die zurückbehaltenen MQTT-Themen:** Status, Fehlercode, Störung, Modulzustand, Zahl der Module und die Warnungen der Schwellen gehen mit leerer Nutzlast über den UDP-Eingang des Gateways hinaus, dreimal. Bis 0.9.6 blieben sie im Broker stehen. Nachgelesen wird nicht; was stehen bleibt, lässt sich mit `mosquitto_pub -r -n -t <thema>` löschen.
- **Berichtigt – Deinstallation eines anderen Plugins:** LoxBerry ruft beim Deinstallieren jedes Deinstallationsskript auf, dessen Name mit dem Namen des deinstallierten Plugins beginnt. Bis 0.9.6 hätte die Deinstallation eines Plugins namens „fronius“ über dieses Skript dessen Datenordner und Sicherung gelöscht; bei einem fremden Namen tut das Skript jetzt nichts.
- **Berichtigt – Installationsskripte:** `postinstall.sh` und `postupgrade.sh` erkennen die LoxBerry-Wurzel jetzt wie `preinstall.sh` und `preupgrade.sh`; von Hand aus einem ausgepackten Archiv gestartet, legten sie bisher Ordner in einem fremden Verzeichnis an. Die Zweitschrift wird beim Update geprüft zurückgespielt (Kopie, Vergleich, dann an ihren Platz); bisher hing die Erfolgsmeldung nur am Rückgabewert des Kopierens. Ein Ordnername „.“ wird in allen Skripten abgewiesen.
- **Berichtigt – `cron.err` wird gekappt:** über 256 kB bleiben die letzten 300 Zeilen; bisher wuchs die Datei auf der RAM-Scheibe ohne Grenze.
- **Berichtigt – Oberfläche:** PHP-Meldungen gehen nicht mehr in die Seite; eine Warnung hätte die Umleitung nach dem Speichern und die Downloads (Sicherung, Importvorlage) verdorben. Nach einer Beanstandung stehen die eingetippten Werte wieder im Formular, das beanstandete Feld ist rot umrandet; gespeichert wird wie bisher nichts.
- **Neu – Sprachansagen (ab Werk aus):** Eine Störung eines Wechselrichters (Statuscode 10 oder ein Fehlercode, der laut Fronius-Unterlage keine regelmäßige Meldung ist) und das Überschreiten der Schwelle für die Zellspannungsdifferenz können über die Lautsprecher des Hauses angesagt werden – mit dem gemeinsamen Sprachmodul (`sprachausgabe.php`) wie in den übrigen Plugins: dieselben Ausgabearten und Ziele, Nachtruhe, Sperre, der Schalter „Ansagen aus“ (`haus/ansage/ruhe`) und eine Testansage im Reiter *Test*. Jeder Eintritt wird einmal angesagt, das Ende nicht; nach einem unvollständigen Abruf wird nichts angesagt. Beide Anlässe sind ab Werk aus. Die Sicherung enthält den Block der Sprachausgabe ohne Sprechtoken.
- Der Selbsttest prüft 50 Fälle (0.9.6: 42). Gemessen auf dem Arbeitsrechner unter PHP 7.4 und 8.4, die Installationsskripte in einer Nachstellung unter WSL; am Gerät nicht gemessen.

## Neu in 0.9.6

- **Geändert – Takt ruht während des Updates schon in der Schale:** Der Minutentakt endet bei laufender Aktualisierung jetzt, bevor PHP startet. Bis 0.9.5 hielt erst die Bibliothek an; der Takt legte vorher nur `cron.err` an, Daten gingen nicht verloren. Dazu schreibt `preupgrade.sh` die Marke jetzt als Unixzeit statt als Datum, wie alle anderen Plugins; mit dem Datum hätte die neue Abfrage die Marke nie erkannt. Ist die Marke eine Stunde oder älter, liegt sie in der Zukunft oder enthält sie keine Unixzeit, gilt sie nicht, damit eine abgebrochene Aktualisierung den Takt nicht dauerhaft stilllegt. Alle Plugins dieser Reihe fragen die Marke mit demselben Block ab. Es wirkt schon beim Update auf diese Fassung, denn der Installer legt die Takte der neuen Fassung vor der Lücke an, und das `preupgrade.sh` der neuen Fassung legt die Marke an. Gemessen in einer Nachstellung des Update-Ablaufs auf dem Arbeitsrechner (WSL); am Gerät nicht gemessen.
- **Geändert – Upgrade-Marke in der Bibliothek:** `frw_upgrade_laeuft()` folgt der Hausregel: Inhalt Unixzeit, nicht aus der Zukunft, jünger als 3600 s. Bisher zählte die Änderungszeit mit Betrag und 900 s, eine Marke aus der Zukunft galt. Auf dem Arbeitsrechner nur auf Syntax geprüft (PHP 7.4 und 8.4, `bash -n`); die Wirkung ist weder dort noch am Gerät gemessen.

## Neu in 0.9.5

- **Einstellungen sichern und zurückspielen mit dem gemeinsamen Sicherungsmodul 1.2.11 statt 1.2.9:** Neu in 1.2.10 ist, dass ein leerer Wert auch in einem Teil der Sicherung, der nur aus einem einzelnen Wert besteht (etwa einem Zugangsschlüssel in einer eigenen Datei), einen gesetzten Wert nicht mehr überschreibt; er bleibt stehen und wird in der Meldung genannt. Dieses Plugin führt keinen solchen Teil; an seinem Verhalten ändert sich dadurch nichts. Gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4 (2 Vorfassungen zurückgespielt). Am Gerät nicht gemessen.
- **Sicherungsmodul 1.2.11:** Wandelt ein Plugin beim Zurückspielen eine Sicherung aus einer Textdatei um, sagt die Meldung das jetzt mit einem gemeinsamen Satz, und Mängel darin werden in allen Plugins mit denselben Bezeichnungen genannt. Dieses Plugin wandelt keine Textdateien um; an seinem Verhalten ändert sich dadurch nichts. Gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4. Am Gerät nicht gemessen.

## Neu in 0.9.4

- **Verhalten geändert – Einstellungen sichern und zurückspielen mit dem gemeinsamen Sicherungsmodul 1.2.9 statt 1.2.2:**
  - Eine Sicherungsdatei, die ein Editor mit einer unsichtbaren Markierung am Anfang (UTF-8-BOM) gespeichert hat, wird jetzt gelesen wie ohne sie. Bis 0.9.3 wurde sie als nicht lesbare Sicherung abgewiesen.
  - Einträge der geltenden Einstellungen, die diese Fassung nicht kennt (etwa aus einer Bearbeitung von Hand), fallen beim Zurückspielen weiter weg, werden jetzt aber mit ihrem Namen genannt, nie mit ihrem Wert. Bis 0.9.3 fielen sie still weg.
  - Jede unbekannte Einstellung wird in der Meldung genau einmal genannt.
  - Die übrigen Neuerungen der Modulfassungen bis 1.2.9 betreffen Fähigkeiten, die dieses Plugin nicht nutzt; an ihm ändert sich dadurch nichts.
- **Knopf „Dokumentation“:** `plugin.cfg` nennt jetzt `WEBSITE=` mit diesem Repository; LoxBerry übernimmt die Adresse bei der Installation und zeigt sie in der Plugin-Verwaltung als Knopf.
- Sicherungen der früheren Fassungen werden weiter angenommen wie bisher; gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4, der über einen Webserver die echten Knöpfe drückt und 2 Vorfassungen zurückspielt. Am Gerät nicht gemessen.

## Neu in 0.9.3

- **Verhalten geändert – Einstellungen sichern und zurückspielen nach der gemeinsamen Vorlage:** Die Knöpfe im Reiter *Einstellungen* arbeiten jetzt mit demselben Sicherungsmodul (1.2.2) wie die übrigen Plugins dieser Reihe. Für den Anwender ändert sich:
  - Der Knopf „Einstellungen zurückspielen“ wirkt nur mit dem Haken „Ja, die geltenden Einstellungen durch die Sicherung ersetzen“, ab Werk aus. Ohne ihn wird die Datei nicht gelesen und nichts geändert, und die Meldung sagt es.
  - Die Sicherungsdatei trägt im Kopf das Kennzeichen `LoxBerry-Plugin-FroniusWaechter` (`_plugin`) und die Fassung (`_fassung`). Bis 0.9.2 stand dort „froniuswaechter“ mit der Fassung dahinter; solche Sicherungen werden weiter angenommen. Eine Datei eines anderen Plugins und eine Datei ohne Kennzeichen werden als Ganzes abgewiesen; bis 0.9.2 wurden sie angenommen, wenn ihre Schlüssel passten.
  - Eine Einstellung, die diese Fassung nicht kennt, wird nicht übernommen und mit ihrem Namen gemeldet, nie mit ihrem Wert; die übrigen werden zurückgespielt. Bis 0.9.2 wies ein unbekannter Name die ganze Datei ab.
  - Ein leerer Wert in der Sicherung überschreibt keinen bestehenden; der bisherige Wert bleibt, und die Meldung nennt den Namen. Bis 0.9.2 wies etwa ein leeres MQTT-Präfix die ganze Datei ab.
  - Wie bisher: Eine Datei mit einem unzulässigen Wert ändert gar nichts, alle Beanstandungen werden genannt, die Zweitschrift neben dem Konfigurationsordner zieht mit, und die Meldung sagt, was mit dem Dienst geschieht. Die Datei darf jetzt bis 256 KB groß sein (bis 0.9.2: 64 KB), wie in allen Plugins dieser Reihe.
- Die Fassung im Kopf der Sicherungsdatei kommt aus der Plugin-Datenbank des LoxBerry bzw. der `plugin.cfg`. Die feste Fassungsnummer im Code (Titelzeile der Oberfläche, Selbsttest, Stand des letzten Laufs, Kennung beim Abruf der Solar API) stand in diesem Ordner noch auf 0.9.2 und ist auf 0.9.3 berichtigt.
- Der Selbsttest prüft 42 Fälle (0.9.2: 39); neu sind die Fälle zum Kennzeichen, zu einer Sicherung aus 0.9.2 und zu einem unbekannten Schlüssel.
- Gemessen mit dem Prüfstand auf dem Arbeitsrechner unter PHP 7.4 und 8.4, der über einen Webserver die echten Knöpfe drückt; am Gerät nicht gemessen.

## Neu in 0.9.2

- Der Selbsttest rechnet mit erfundenen Werten statt mit gemessenen Antworten; am Verhalten ändert sich nichts.
- Die nachgebildeten Antworten behalten Aufbau, Schlüssel und Codes der beiden Datamanager. Energien, Leistungen, Spannungen, Ströme, Temperaturen, Ladestände, Kapazitäten, Zyklen und Zeitstempel sind runde, erfundene Zahlen; der Selbsttest prüft dieselben 39 Fälle wie in 0.9.1.
- Im Reiter „Einbindung in Loxone“ steht unter der Baustein-Liste das Bild der Seite „Fronius-Wächter“ aus dem LoxBerry-Plugins Musterprojekt.
- Die Formel „Fronius-Wächter Alter“ ist so beschrieben wie auf dieser Seite: Alter = I2 − I1, mit I1 = Zeitstempel des Plugins (`froniuswaechter_status_ts`) und I2 = Systembaustein „Unix Zeitstempel“ (so heißt er in Loxone Config 17.3, in 17.2 „Unix Timestamp“).
- Am Gerät nicht gemessen; geprüft unter PHP 7.4 und 8.4 auf dem Arbeitsrechner.

Ein kleines, rein lesendes LoxBerry-Plugin für Fronius-Wechselrichter mit Datamanager oder Hybridmanager. Es liest die Fronius Solar API v1 und liefert drei Dinge, die die Loxone-eigene Fronius-Anbindung nicht liefert:

1. **Status- und Fehlercode** jedes Wechselrichters, daraus eine Störungsmeldung.
2. **Die vom Gerät gezählten Energiestände** (Tag, Jahr, gesamt, in Wh) als Gegenprobe zu den Zählern in Loxone.
3. **Die Speichermodule** eines Fronius Solar Battery: Ladestand, Zyklen, kleinste und größte Zellspannung und Temperatur je Modul, dazu abgeleitet die Streuung des Ladestands, die größte Zellspannungsdifferenz und der Abstand der Zyklen.

Das Plugin schreibt nie in einen Wechselrichter, benutzt kein Modbus und steuert nichts.

## Ab Werk aus

Der Dienst, alle Meldungen, alle Sprachansagen, alle Schwellen, die Speichermodule als Sendegruppe, die Abfrage als JSON und das Behalten des Stands über ein Update. Solange der Dienst aus ist, wird kein Datamanager abgefragt.

## Einrichtung

1. Reiter **Einstellungen**: die Adresse jedes Datamanagers eintragen (ohne `http://`, wahlweise mit `:Port`, zum Beispiel `192.0.2.31` und `192.0.2.32`). Jede Solar API sieht nur ihre eigenen Wechselrichter; bei zwei Datamanagern werden beide eingetragen. Welche Wechselrichter und Speicher dahinter stehen, liest das Plugin aus `GetActiveDeviceInfo` – es nimmt keine Geräteadresse an.
2. **Dienst einschalten** und speichern. Der erste Abruf folgt im nächsten Minutenlauf.
3. Reiter **Test**: der letzte Stand je Datamanager, die Antwortzeiten und die Selbstprüfung.
4. Reiter **Einbindung in Loxone**: MQTT-Namen oder Importvorlage für einen virtuellen HTTP-Eingang.

## Rücksicht auf andere Leser derselben Datamanager

Die Datamanager werden oft schon von anderen gefragt (im Haus: die Einspeisebremse im 5-s-Takt). Deshalb:

* Takt ab Werk **300 s** (zulässig 60 bis 3600 s). Status, Fehlercode, Energiestände und Modulwerte ändern sich nicht im Sekundenbereich.
* Je Datamanager geht **nie mehr als eine Anfrage zugleich** hinaus. Dienst und Messknopf im Reiter Test teilen eine Sperre.
* Zwischen zwei Anfragen an denselben Datamanager liegen **4 s**. Die Solar API V1 erlaubt höchstens zwei Echtzeitanfragen zugleich „with keeping a timeout of 4 seconds between two consecutive calls“ (Fronius, 42,0410,2012, Abschnitt 2.6).
* Zeitüberschreitung ab Werk **3 s** je Anfrage. Gemessen am 12.11.2026 nachts: längste Antwort 687 ms (Speicherabfrage am Hybridmanager), alle übrigen unter 300 ms.
* Je Lauf 3 Anfragen an einen Datamanager ohne Speicher, 4 mit Speicher und eingeschalteter Gruppe; einmal je Stunde eine weitere für die Geräteliste.
* Der HTTP-Endpunkt fragt nie einen Datamanager; er liest nur den Stand des Dienstes.

## MQTT

Regelweg ist das MQTT-Gateway des LoxBerry (UDP-Eingang, Befehle `publish` und `retain`). Präfix ab Werk `froniuswaechter`.

| Thema | Bedeutung | Einheit | Retain |
|---|---|---|---|
| `status/ok`, `status/ts`, `status/zaehler` | Lebenszeichen des Dienstes | –, s, – | nein |
| `dmN/erreichbar`, `dmN/ausfall_folge`, `dmN/antwort_ms` | Aussagen des Dienstes über den Datamanager | –, –, ms | nein |
| `dmN/wrI/status`, `dmN/wrI/fehler`, `dmN/wrI/stoerung` | Status- und Fehlercode, Störung (abgeleitet) | – | ja |
| `dmN/wrI/e_tag`, `e_jahr`, `e_gesamt` | Energiestände des Geräts | Wh | nein |
| `dmN/spS/soc`, `anzahl`, `soc_min`, `soc_max`, `soc_streuung`, `u_min`, `u_max`, `u_diff_mv`, `zyklen_min`, `zyklen_max`, `zyklen_abstand`, `temp_max` | Speicher, ab `soc_min` abgeleitet | %, –, %, %, %, V, V, mV, –, –, –, °C | `anzahl` ja, sonst nein |
| `dmN/spS/modulK/soc`, `zyklen`, `u_min`, `u_max`, `temp`, `zustand`, `zustand_roh` | je Modul | %, –, V, V, °C, –, – | `zustand*` ja, sonst nein |
| `dmN/spS/warn_streuung`, `warn_zellen` | Schwelle erreicht (nur mit Schwelle über 0) | – | ja |

`N` ist die Nummer des Datamanagers im Plugin, `I` die Nummer des Wechselrichters und `S` die des Speichers, wie der Datamanager sie meldet. Werte, die das Gerät als `null` liefert, gehen nicht hinaus und werden nie als 0 gedeutet. Bei einem Ausfall geht nur das Signal (`erreichbar`, `ausfall_folge`) hinaus. Gesendet wird bei Änderung und alle 30 Minuten vollständig.

## HTTP-Endpunkt

`http://<loxberry>/plugins/froniuswaechter/index.php?aktion=status&dm=N` liefert eine Zeile `FRWN;OK=..;ALTER=..;ZAEHLER=..;WR1_STATUS=..;…`, Suchtexte mit Semikolon davor (`\i;WR1_STATUS=\i\v`). Vor dem ersten Abruf antwortet der Endpunkt mit HTTP 503. Abfragen sind offen. Mit Aktionstoken: `?aktion=jetzt&token=…` (beim nächsten Minutenlauf abfragen) und `?selftest=1&token=…`.

Mit dem Haken „Kompakte Abfrage als JSON erlauben“ (ab Werk aus) liefert `?aktion=json` den Stand aller Datamanager als ein JSON-Objekt (Vertrag 1): `plugin`, `vertrag`, `ts`, `dienst`, `ok` und je Datamanager unter `dm` die Felder `ok`, `alter`, `erreichbar`, `ausfall_folge`, `wr` (je Wechselrichter `status`, `fehler`, `stoerung`) und `sp` (je Speicher `soc`, `anzahl`, `soc_streuung`, `u_diff_mv`, mit Schwelle über 0 auch `warn_streuung`, `warn_zellen`). Ein Wert, den das Gerät nicht geliefert hat, fehlt. Vor dem ersten Abruf antwortet die Abfrage mit HTTP 503 und `grund`.

## Bedeutung der Codes und ihre Quellen

* **Statuscode** (StatusCode): Fronius Solar API V1, 42,0410,2012, Abschnitt 4.6.5 „Meaning of numerical status codes“ – 0 bis 6 Startup, 7 Running, 8 Standby, 9 Bootloading, 10 Error, 11 Idle, 12 Ready, 13 Sleeping, 255 Unknown.
* **Fehlercode** (ErrorCode): −1 heißt „kein gültiger Code“ (ebenda, 4.6). 306 „Power low“ und 307 „DC low“: Fronius Symo / Fronius Eco Bedienungsanleitung, „Class 3 status codes“, und Fronius Energy Package (Symo Hybrid), jeweils mit dem Hinweis, dass beide morgens und abends regelmäßig erscheinen und keine Störung sind. Für alle anderen Codes zeigt das Plugin nur die Zahl – es rät keine Bedeutung.
* **Zellzustand** (Status_BatteryCell): Solar API V1, Abschnitt 4.9.7, Tabelle 2 – ein Byte 0xYX, Y aktueller, X vorheriger Zustand (1 Pre Charge, 2 Initial, 3 Normal Charge, 4 Charge Terminate, 5 Normal Discharge, 6 Over Voltage, 7 Over Discharge, 9 Over Temp Charge, 10 Over Current Charge, 11 Over Temp Discharge, 12 Over Current Discharge, 13 Cell Unbalance, 14 Charge Suspend).

**Störung** heißt: Statuscode 10 oder ein Fehlercode außer −1, 0, 306 und 307.

## Was nicht gemessen ist

Gemessen ist der Nachtbetrieb einer Anlage mit Symo Hybrid 5.0-3-S und Symo 8.2-3-M (12.11.2026). Nicht gemessen sind: die Modulwerte beim Laden aus PV, bei vollem Speicher und beim Entladen; ob die Lesart des Zellzustands (Zahlenwert 53 als Byte 0x35) beim Entladen stimmt; `P_Akku` bei vollem, ruhendem Speicher; der Fehlercode über einen ganzen Tag; Antwortzeiten bei voller Last tagsüber. Eine Schwelle im Plugin ist eine Einstellung, keine Herstellerangabe.

## Lizenz

MIT, siehe `LICENSE`.
