# Fronius-Wächter

Version 0.9.2 · LoxBerry ab 3.0 · PHP 7.4 bis 8.5 · noch auf keinem LoxBerry installiert

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

Der Dienst, alle Meldungen, alle Schwellen und die Speichermodule als Sendegruppe. Solange der Dienst aus ist, wird kein Datamanager abgefragt.

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

## Bedeutung der Codes und ihre Quellen

* **Statuscode** (StatusCode): Fronius Solar API V1, 42,0410,2012, Abschnitt 4.6.5 „Meaning of numerical status codes“ – 0 bis 6 Startup, 7 Running, 8 Standby, 9 Bootloading, 10 Error, 11 Idle, 12 Ready, 13 Sleeping, 255 Unknown.
* **Fehlercode** (ErrorCode): −1 heißt „kein gültiger Code“ (ebenda, 4.6). 306 „Power low“ und 307 „DC low“: Fronius Symo / Fronius Eco Bedienungsanleitung, „Class 3 status codes“, und Fronius Energy Package (Symo Hybrid), jeweils mit dem Hinweis, dass beide morgens und abends regelmäßig erscheinen und keine Störung sind. Für alle anderen Codes zeigt das Plugin nur die Zahl – es rät keine Bedeutung.
* **Zellzustand** (Status_BatteryCell): Solar API V1, Abschnitt 4.9.7, Tabelle 2 – ein Byte 0xYX, Y aktueller, X vorheriger Zustand (1 Pre Charge, 2 Initial, 3 Normal Charge, 4 Charge Terminate, 5 Normal Discharge, 6 Over Voltage, 7 Over Discharge, 9 Over Temp Charge, 10 Over Current Charge, 11 Over Temp Discharge, 12 Over Current Discharge, 13 Cell Unbalance, 14 Charge Suspend).

**Störung** heißt: Statuscode 10 oder ein Fehlercode außer −1, 0, 306 und 307.

## Was nicht gemessen ist

Gemessen ist der Nachtbetrieb einer Anlage mit Symo Hybrid 5.0-3-S und Symo 8.2-3-M (12.11.2026). Nicht gemessen sind: die Modulwerte beim Laden aus PV, bei vollem Speicher und beim Entladen; ob die Lesart des Zellzustands (Zahlenwert 53 als Byte 0x35) beim Entladen stimmt; `P_Akku` bei vollem, ruhendem Speicher; der Fehlercode über einen ganzen Tag; Antwortzeiten bei voller Last tagsüber. Eine Schwelle im Plugin ist eine Einstellung, keine Herstellerangabe.

## Lizenz

MIT, siehe `LICENSE`.
