<?php
/**
 * Fronius-Waechter - Bedienoberflaeche
 *
 * NUR Oberflaeche. Der Abruf steht in bin/frw_dienst.php (Cron), der
 * Endpunkt fuer den Miniserver in webfrontend/html/index.php, der Unterbau
 * in webfrontend/html/frw_lib.php - drei Aufgaben, drei Dateien.
 *
 * Reihenfolge (Bauvorschrift, Regeln/04): Bibliothek, Kern, Reiterwahl,
 * Konfiguration, Wachposten, ALLE Handler samt Downloads, Umleitung, dann
 * erst lbheader() und HTML.
 *
 * (c) Fronius-Waechter Plugin Authors - MIT-Lizenz
 */
error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

/* Die Bibliothek liegt im ANDEREN Baum:
     installiert  $LB/webfrontend/htmlauth/plugins/<ordner>/index.php
                  $LB/webfrontend/html/plugins/<ordner>/frw_lib.php
     im Archiv    <plugin>/webfrontend/htmlauth/index.php
                  <plugin>/webfrontend/html/frw_lib.php */
$frw_kandidaten = array();
$frw_lbh = getenv('LBHOMEDIR');
if ($frw_lbh !== false && $frw_lbh !== '') {
    $frw_kandidaten[] = rtrim($frw_lbh, '/\\') . '/webfrontend/html/plugins/' . basename(__DIR__) . '/frw_lib.php';
}
$frw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/html/plugins/' . basename(__DIR__) . '/frw_lib.php';
$frw_kandidaten[] = dirname(__DIR__) . '/html/frw_lib.php';
$frw_geladen = '';
foreach ($frw_kandidaten as $frw_k) {
    if (is_file($frw_k)) {
        require_once $frw_k;
        $frw_geladen = $frw_k;
        break;
    }
}
if ($frw_geladen === '' || !function_exists('frw_paths')) {
    header('Content-Type: text/html; charset=utf-8');
    echo '<h2>Fronius-Waechter: frw_lib.php wurde nicht gefunden</h2><ul>';
    foreach ($frw_kandidaten as $frw_k) {
        echo '<li><code>' . htmlspecialchars($frw_k, ENT_QUOTES, 'UTF-8') . '</code></li>';
    }
    echo '</ul><p>Die Installation ist unvollstaendig - das Plugin bitte neu installieren.</p>';
    exit;
}

/* Der LoxBerry-Rahmen: erst loxberry_system.php, dann loxberry_web.php. */
$frw_lb = frw_paths()['lbhome'];
if ($frw_lb !== '' && is_file($frw_lb . '/libs/phplib/loxberry_system.php')) {
    require_once $frw_lb . '/libs/phplib/loxberry_system.php';
    if (is_file($frw_lb . '/libs/phplib/loxberry_web.php')) {
        require_once $frw_lb . '/libs/phplib/loxberry_web.php';
    }
}
$frw_p = frw_paths();

/* ---------- Reiter: Positivliste, Leiste und Bereiche (ausgeschrieben) ---- */
$frw_reiter = array('tab-settings', 'tab-mqtt', 'tab-loxone', 'tab-test', 'tab-log');
$frw_tab = 'tab-settings';
if (isset($_POST['activetab']) && is_string($_POST['activetab']) && in_array($_POST['activetab'], $frw_reiter, true)) {
    $frw_tab = $_POST['activetab'];
} elseif (isset($_GET['form']) && is_string($_GET['form']) && in_array('tab-' . $_GET['form'], $frw_reiter, true)) {
    $frw_tab = 'tab-' . $_GET['form'];
}

$frw_cfg = frw_config();
$frw_meldung = '';
$frw_hinweise = array();
$frw_fehler = array();
$frw_messung = null;
$frw_post = isset($_SERVER['REQUEST_METHOD']) && $_SERVER['REQUEST_METHOD'] === 'POST';

if (!$frw_post) {
    $frw_einmal = frw_einmal_lesen();
    if (is_array($frw_einmal)) {
        if (isset($frw_einmal['meldung']) && is_string($frw_einmal['meldung'])) {
            $frw_meldung = $frw_einmal['meldung'];
        }
        foreach (array('hinweise', 'fehler') as $frw_feldname) {
            if (isset($frw_einmal[$frw_feldname]) && is_array($frw_einmal[$frw_feldname])) {
                ${'frw_' . $frw_feldname} = array_values($frw_einmal[$frw_feldname]);
            }
        }
        if (isset($frw_einmal['messung']) && is_array($frw_einmal['messung'])) {
            $frw_messung = $frw_einmal['messung'];
        }
    }
}

/* ---------- Der Wachposten: EIN Posten vor allen Handlern ---------- */
$frw_wache = frw_wachposten();
if ($frw_wache !== '') {
    $frw_merk_reiter = (isset($_POST['activetab']) && is_string($_POST['activetab'])) ? $_POST['activetab'] : null;
    $_POST = array();
    if ($frw_merk_reiter !== null) {
        $_POST['activetab'] = $frw_merk_reiter;
    }
    $frw_fehler[] = $frw_wache;
}

function frw_feld($name)
{
    return (isset($_POST[$name]) && is_string($_POST[$name])) ? trim($_POST[$name]) : '';
}

/** Was mit dem Dienst geschieht - gesagt, nicht geraten (Regeln/05, Punkt 7). */
function frw_dienst_satz(array $c)
{
    if (empty($c['dienst_ein'])) {
        return frw_t('EINST.DIENST_SATZ_AUS');
    }
    if (!frw_dm_liste($c)) {
        return frw_t('EINST.DIENST_SATZ_OHNE');
    }
    return sprintf(frw_t('EINST.DIENST_SATZ_EIN'), (int) $c['takt_s']);
}

/* ---------------- Einstellungen speichern ----------------
 * Eigenes Formular (formular=einstellungen), fasst nur seine Schluessel an.
 * Alle Beanstandungen werden gesammelt; bei einer einzigen wird NICHTS
 * gespeichert. */
if ($frw_post && isset($_POST['speichern'])) {
    $frw_neu = $frw_cfg;
    $frw_mangel = array();
    foreach (array('dienst_ein', 'gruppe_wr', 'gruppe_energie', 'gruppe_speicher', 'meldung_stoerung',
                   'meldung_ausfall', 'meldung_speicher', 'eb_anzeigen') as $frw_s) {
        $frw_neu[$frw_s] = empty($_POST[$frw_s]) ? 0 : 1;
    }
    foreach (array('takt_s', 'timeout_s', 'schwelle_soc_streuung', 'schwelle_zellen_mv',
                   'dm1_adresse', 'dm2_adresse', 'dm3_adresse', 'dm4_adresse') as $frw_s) {
        list($frw_ok, $frw_wert, $frw_grund) = frw_wert_pruefen($frw_s, frw_feld($frw_s));
        if ($frw_ok) {
            $frw_neu[$frw_s] = $frw_wert;
        } else {
            $frw_mangel[] = $frw_grund;
        }
    }
    if (!$frw_mangel && $frw_neu['dienst_ein'] && !frw_dm_liste($frw_neu)) {
        $frw_mangel[] = frw_t('EINST.DIENST_OHNE_ADRESSE');
    }
    if (!$frw_mangel && $frw_neu['meldung_speicher'] && (int) $frw_neu['schwelle_soc_streuung'] === 0
        && (int) $frw_neu['schwelle_zellen_mv'] === 0) {
        $frw_hinweise[] = frw_t('EINST.MELDUNG_SPEICHER_OHNE_SCHWELLE');
    }
    if ($frw_mangel) {
        $frw_fehler[] = array('kopf' => frw_t('EINST.NICHT_GESPEICHERT'), 'punkte' => $frw_mangel);
    } elseif (frw_config_speichern($frw_neu)) {
        $frw_meldung = frw_t('EINST.GESPEICHERT') . ' ' . frw_dienst_satz($frw_neu);
        if ($frw_neu['dienst_ein'] && empty($frw_cfg['dienst_ein'])) {
            frw_jetzt_merken();
        }
    } else {
        $frw_fehler[] = frw_t('EINST.SCHREIBFEHLER');
    }
    $frw_tab = 'tab-settings';
}

/* Aktionstoken neu: orange, mit Warnhinweis daneben (Regeln/04). */
if ($frw_post && isset($_POST['token_neu'])) {
    $frw_neu = $frw_cfg;
    $frw_neu['aktionstoken'] = frw_token_erzeugen();
    if (frw_config_speichern($frw_neu)) {
        $frw_meldung = frw_t('EINST.TOKEN_ERZEUGT');
        frw_log('Aktionstoken in der Oberflaeche neu erzeugt.');
    } else {
        $frw_fehler[] = frw_t('EINST.SCHREIBFEHLER');
    }
    $frw_tab = 'tab-loxone';
}

/* ---------------- MQTT speichern: eigenes Formular, eigener Handler ------- */
if ($frw_post && isset($_POST['mqtt_speichern'])) {
    $frw_neu = $frw_cfg;
    $frw_neu['mqtt_ein'] = empty($_POST['mqtt_ein']) ? 0 : 1;
    list($frw_ok, $frw_wert, $frw_grund) = frw_wert_pruefen('mqtt_praefix', frw_feld('mqtt_praefix'));
    if (!$frw_ok) {
        $frw_fehler[] = array('kopf' => frw_t('EINST.NICHT_GESPEICHERT'), 'punkte' => array($frw_grund));
    } else {
        $frw_neu['mqtt_praefix'] = $frw_wert;
        if (frw_config_speichern($frw_neu)) {
            $frw_meldung = frw_t('MQTT.GESPEICHERT');
        } else {
            $frw_fehler[] = frw_t('EINST.SCHREIBFEHLER');
        }
    }
    $frw_tab = 'tab-mqtt';
}

/* ---------------- Einstellungen sichern (Download, keine Umleitung) ------- */
if ($frw_post && isset($_POST['frw_sichern'])) {
    $frw_js = json_encode(frw_sicherung_bauen(frw_config()),
        JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    if ($frw_js !== false) {
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/json; charset=utf-8');
        header('Content-Disposition: attachment; filename="froniuswaechter_einstellungen_' . date('Ymd_His') . '.json"');
        echo $frw_js;
        exit;
    }
    $frw_fehler[] = frw_t('EINST.SCHREIBFEHLER');
}

/* ---------------- Einstellungen zurueckspielen ----------------
 * is_uploaded_file() zuerst, dann die Groesse; eine halb gueltige Datei
 * aendert nichts; danach wird gesagt, was mit dem Dienst geschieht. */
if ($frw_post && isset($_POST['frw_zurueck'])) {
    if (!isset($_FILES['frw_sicherung']) || !is_array($_FILES['frw_sicherung'])
        || !isset($_FILES['frw_sicherung']['tmp_name']) || !is_string($_FILES['frw_sicherung']['tmp_name'])
        || !@is_uploaded_file($_FILES['frw_sicherung']['tmp_name'])) {
        $frw_fehler[] = frw_t('SICH.KEINE_DATEI');
    } elseif ((int) $_FILES['frw_sicherung']['size'] > 65536) {
        $frw_fehler[] = frw_t('SICH.ZU_GROSS');
    } else {
        list($frw_neu, $frw_mangel, $frw_n) = frw_sicherung_lesen(
            (string) @file_get_contents($_FILES['frw_sicherung']['tmp_name']));
        if ($frw_neu === null) {
            $frw_fehler[] = array('kopf' => frw_t('SICH.ABGELEHNT'), 'punkte' => $frw_mangel);
        } else {
            $frw_zweit = false;
            if (!frw_config_speichern($frw_neu, $frw_zweit)) {
                $frw_fehler[] = frw_t('EINST.SCHREIBFEHLER');
            } else {
                $frw_datei = frw_json_lesen($frw_p['cfgdatei']);
                $frw_gleich = is_array($frw_datei);
                foreach (array_keys(frw_vorgaben()) as $frw_k) {
                    if ($frw_gleich && (!array_key_exists($frw_k, $frw_datei) || $frw_datei[$frw_k] !== $frw_neu[$frw_k])) {
                        $frw_gleich = false;
                    }
                }
                if ($frw_gleich) {
                    $frw_meldung = sprintf(frw_t('SICH.UEBERNOMMEN'), $frw_n) . ' ' . frw_dienst_satz($frw_neu);
                    if (!$frw_zweit) {
                        $frw_hinweise[] = frw_t('SICH.ZWEIT_FEHLT');
                    }
                } else {
                    $frw_fehler[] = frw_t('SICH.NICHT_WIRKSAM');
                }
            }
        }
    }
    $frw_tab = 'tab-settings';
}

/* ---------------- Importvorlage (Download, keine Umleitung) ---------------- */
if ($frw_post && isset($_POST['vorlage'])) {
    $frw_vn = frw_feld('vorlage_dm');
    if (preg_match('/^[1-4]$/', $frw_vn) === 1) {
        list($frw_vname, $frw_vxml) = frw_vorlage((int) $frw_vn, frw_rechner(), $frw_cfg, frw_stand_lesen());
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }
        header('Content-Type: application/x-download');
        header('Content-Disposition: attachment; filename="' . $frw_vname . '"');
        echo $frw_vxml;
        exit;
    }
    $frw_fehler[] = frw_t('LOX.VORLAGE_DM_UNGUELTIG');
    $frw_tab = 'tab-loxone';
}

/* ---------------- Antwortzeit messen (Reiter Test, nur lesend) ------------ */
if ($frw_post && isset($_POST['messen'])) {
    if (!frw_dm_liste($frw_cfg)) {
        $frw_fehler[] = frw_t('TEST.MESS_OHNE_ADRESSE');
    } else {
        $frw_messung = frw_antwortzeit_messen($frw_cfg);
        if (!empty($frw_messung['besetzt'])) {
            $frw_fehler[] = frw_t('TEST.MESS_BESETZT');
            $frw_messung = null;
        }
    }
    $frw_tab = 'tab-test';
}

/* ---------------- Umleitung nach jedem POST ---------------- */
if ($frw_post) {
    $frw_m = array('meldung' => $frw_meldung, 'hinweise' => $frw_hinweise, 'fehler' => $frw_fehler,
                   'messung' => $frw_messung);
    if (frw_einmal_schreiben($frw_m)) {
        header('Location: index.php?form=' . rawurlencode(substr($frw_tab, 4)), true, 303);
        exit;
    }
    $frw_hinweise[] = frw_t('EINST.EINMAL_FEHLT');
}

$frw_cfg = frw_config();
$frw_stand = frw_stand_lesen();
$frw_host = frw_rechner();
$frw_gw = frw_mqtt_gateway();
$frw_pf = frw_mqtt_praefix($frw_cfg);
$frw_dms = frw_dm_liste($frw_cfg);
$frw_rahmen = class_exists('LBWeb', false);
if ($frw_rahmen) {
    LBWeb::lbheader(frw_t('ALLGEMEIN.TITEL') . ' ' . FRW_FASSUNG, 'https://wiki.loxberry.de/', 'help.html');
}
?>
<style>
/* Hausstandard: eigener Behaelter, kein Schattenwurf, Reiter im Fluss */
.sm-wrap { max-width: 980px; margin: 0 auto; font-family: -apple-system, 'Segoe UI', Roboto, sans-serif; color: #333; }
.sm-wrap, .sm-wrap *, .sm-tabs, .sm-tabs * { text-shadow: none !important; }
.sm-wrap h2 { color: #6dac20; margin: 24px 0 10px; font-size: 1.15em; border-bottom: 2px solid #e0e0e0; padding-bottom: 6px; }
.sm-wrap h3 { color: #4f7d17; font-size: 1.0em; font-weight: 700; margin: 16px 0 2px; }
.sm-tabs { display: flex; gap: 4px; margin: 14px 0 0; border-bottom: 2px solid #6dac20; flex-wrap: wrap; }
.sm-tab { background: #eee; border: 1px solid #ccc; border-bottom: 0; border-radius: 8px 8px 0 0;
          padding: 9px 18px; font-size: 0.95em; color: #444 !important; text-decoration: none; display: inline-block; }
.sm-tab.sm-active { background: #6dac20; color: #fff !important; border-color: #6dac20; font-weight: 600; }
.sm-feld { margin: 14px 0; }
.sm-feld > label { display: block; font-weight: 600; font-size: 0.9em; color: #555; margin: 0 0 4px; }
/* Bedienelemente werden von jQuery Mobile umgebaut und bekommen einen eigenen
   Behaelter. Begrenzt man das Feld selbst, bleibt der Behaelter breit - man
   sieht ein schmales Feld in einem breiten weissen Kasten. Und beim
   Auswahlfeld liegt das unsichtbare <select> ueber dem Knopf und faengt die
   Klicks ab; wer es gestaltet, schiebt es weg. Deshalb wird ausschliesslich
   der Behaelter begrenzt. */
.sm-feld .ui-input-text, .sm-feld .ui-select, .sm-feld .ui-textinput { max-width: 520px; }
.sm-feld .ui-input-text input, .sm-feld .ui-input-text textarea { font-size: 0.95em; }
.sm-hilfe { font-size: 0.85em; color: #555; margin: 4px 0 0; max-width: 640px; }
.sm-step { border: 1px solid #ddd; border-left: 4px solid #6dac20; background: #fafafa;
    border-radius: 6px; padding: 12px 14px; margin: 12px 0; font-size: 0.92em; line-height: 1.5; }
.sm-tbl { border-collapse: collapse; width: 100%; margin: 8px 0; font-size: 0.9em; }
.sm-tbl th, .sm-tbl td { border: 1px solid #ccc; padding: 5px 7px; text-align: left; vertical-align: top; }
.sm-tbl th { background: #eef3e6; font-weight: 600; }
.sm-mono { font-family: Consolas, "Courier New", monospace; background: #f0f0f0;
    padding: 1px 4px; border-radius: 3px; font-size: 0.94em; word-break: break-all; }
.sm-pre { background: #f4f4f4; border: 1px solid #ccc; padding: 10px; font-size: 0.85em;
    overflow: auto; margin: 8px 0; }
.sm-knopfreihe { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0 4px; align-items: stretch; }
/* LoxBerry bringt jQuery Mobile mit. Das formatiert JEDES <button> mit eigenem
   Hintergrund UND eigenen Hover-Regeln. Ohne !important steht weisse Schrift
   auf hellgrauem Grund - und beim Ueberfahren weiss auf weiss. Die
   Hover-Farben unten sind kein Feinschliff, sondern Pflicht: fehlen sie, kommt
   der Hover-Zustand vom Rahmen und ist unlesbar. */
.sm-wrap .sm-knopfreihe .sm-btn, .sm-wrap a.sm-btn, .sm-wrap button.sm-btn {
    flex: 0 0 auto; min-width: 250px; text-align: center; display: inline-flex;
    align-items: center; justify-content: center; line-height: 1.25;
    padding: 10px 14px !important; border-radius: 6px !important;
    color: #fff !important; text-decoration: none !important; font-size: 0.92em;
    border: 0 !important; cursor: pointer; font-weight: 600 !important;
    text-shadow: none !important; box-shadow: none !important;
    opacity: 1 !important; margin: 0 !important; width: auto !important; }
/* Statuskacheln — bewusst ein anderer Name als sm-knopfreihe.
   Beide zu verwechseln hat am 26.07.2026 die Statusanzeige zerlegt. */
.sm-kacheln { display: flex; flex-wrap: wrap; gap: 10px; margin: 10px 0; }
.sm-kachel { border: 1px solid #ddd; border-radius: 10px; padding: 10px 14px; min-width: 130px; }
.sm-kachel b { display: block; font-size: 1.35em; color: #33691e; }

.sm-legende { display: flex; flex-wrap: wrap; gap: 14px; margin: 10px 0 2px; font-size: 0.86em; color: #555; }
.sm-legende span { display: inline-flex; align-items: center; gap: 6px; }
.sm-punkt { width: 13px; height: 13px; border-radius: 3px; display: inline-block; }
.sm-wrap .sm-btn.sm-b-lesen   { background: #6dac20 !important; }
.sm-wrap .sm-btn.sm-b-technik { background: #546e7a !important; }
.sm-wrap .sm-btn.sm-b-aktion  { background: #e0620d !important; }
/* Eigene Hover- und Fokusfarben je Gruppe - sonst uebernimmt der Rahmen. */
.sm-wrap .sm-btn.sm-b-lesen:hover,   .sm-wrap .sm-btn.sm-b-lesen:focus   { background: #5c9219 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-technik:hover, .sm-wrap .sm-btn.sm-b-technik:focus { background: #435962 !important; color: #fff !important; }
.sm-wrap .sm-btn.sm-b-aktion:hover,  .sm-wrap .sm-btn.sm-b-aktion:focus  { background: #b84f0a !important; color: #fff !important; }
.sm-punkt.sm-b-lesen   { background: #6dac20; }
.sm-punkt.sm-b-technik { background: #546e7a; }
.sm-punkt.sm-b-aktion  { background: #e0620d; }
/* Reiterinhalte: nur der aktive ist sichtbar.
   Ohne diese zwei Zeilen stehen alle fuenf Reiter untereinander.
   MIT ihnen und OHNE serverseitiges sm-active ist die Seite dagegen
   vollstaendig leer, sobald das Skript nicht laeuft - genau das war bis
   07.08.2026 der Fall. Die Klasse gehoert deshalb schon ins ausgelieferte
   HTML, siehe die Reiterleiste weiter unten. */
.sm-seite { display: none; padding-top: 4px; }
.sm-seite.sm-active { display: block; }
.sm-hinweis { border: 1px solid #cfe3b0; background: #f2f8ea; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-warnung { border: 1px solid #f0c9a0; background: #fdf4ec; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-an  { color: #1a7f1a; font-weight: 700; }
.sm-aus { color: #b00000; font-weight: 700; }
</style>
<style>
/* Aus VORLAGE_hausstandard.css.html, Abschnitte "Tabelle breiter als das
   Fenster" und "Auswahlfeld erkennen", dazu sm-fehler und sm-log wie in den
   Geschwisterlinien (Ecowitt-Weiche 0.9.33). */
.sm-breit { overflow-x: auto; -webkit-overflow-scrolling: touch; margin: 10px 0; }
.sm-breit .sm-tbl { margin: 0; min-width: 760px; }
.sm-wrap select {
    appearance: none; -webkit-appearance: none; -moz-appearance: none;
    background-image: url("data:image/svg+xml;charset=UTF-8,%3Csvg xmlns='http://www.w3.org/2000/svg' width='14' height='9' viewBox='0 0 14 9'%3E%3Cpath d='M1 1l6 6 6-6' fill='none' stroke='%234f7d17' stroke-width='2'/%3E%3C/svg%3E");
    background-repeat: no-repeat; background-position: right 10px center;
    padding-right: 32px; cursor: pointer; }
.sm-tbl select { padding-right: 28px; background-position: right 7px center; }
.sm-wrap input[type=text], .sm-wrap input[type=number], .sm-wrap input[type=file], .sm-wrap select {
  width: 100%; max-width: 520px; padding: 8px 10px; border: 1px solid #ccc; border-radius: 6px;
  font-size: 0.95em; box-sizing: border-box; }
.sm-wrap input[type=checkbox] { width: 17px; height: 17px; margin: 0 6px 0 0; vertical-align: middle; }
.sm-fehler { border: 1px solid #ef9a9a; background: #ffebee; border-radius: 6px;
    padding: 10px 12px; margin: 12px 0; font-size: 0.9em; }
.sm-log { background: #1e1e1e; color: #d4d4d4; font-family: Consolas, "Courier New", monospace;
    font-size: 0.82em; padding: 12px; border-radius: 8px; max-height: 480px; overflow: auto; white-space: pre-wrap; }
.sm-grau { color: #999; font-style: italic; }
/* Seitenbild aus dem Musterprojekt, wie Weatherdata 0.9.17. */
.sm-bild { margin: 12px 0; }
.sm-bild img { max-width: 100%; height: auto; border: 1px solid #ccc; border-radius: 4px; background: #fff; }
.sm-bild figcaption { font-size: .9em; color: #555; margin-top: 4px; }
</style>

<div class="sm-wrap">

<?php if ($frw_meldung !== '') { ?><div class="sm-hinweis"><?= frw_e($frw_meldung) ?></div><?php } ?>
<?php foreach ($frw_hinweise as $frw_h) { if (is_string($frw_h)) { ?><div class="sm-warnung"><?= frw_e($frw_h) ?></div><?php } } ?>
<?php foreach ($frw_fehler as $frw_fm) {
    if (is_array($frw_fm)) { ?>
<div class="sm-fehler"><?= frw_e(isset($frw_fm['kopf']) ? $frw_fm['kopf'] : '') ?><ul><?php
        foreach ((isset($frw_fm['punkte']) && is_array($frw_fm['punkte'])) ? $frw_fm['punkte'] : array() as $frw_pk) {
            if (is_string($frw_pk)) { ?><li><?= frw_e($frw_pk) ?></li><?php }
        } ?></ul></div>
<?php } elseif (is_string($frw_fm)) { ?><div class="sm-fehler"><?= frw_e($frw_fm) ?></div><?php }
} ?>

<?php /* Kopf: Stand des letzten Laufs aus stand.json - die Seite fragt dafuer keinen Datamanager. */
$frw_k_ts = isset($frw_stand['ts']) ? (int) $frw_stand['ts'] : 0; ?>
<table class="sm-tbl" style="max-width:640px">
<tr><th><?= frw_e(frw_t('KOPF.EIGENSCHAFT')) ?></th><th><?= frw_e(frw_t('KOPF.WERT')) ?></th></tr>
<tr><td><?= frw_e(frw_t('KOPF.DIENST')) ?></td><td class="<?= empty($frw_cfg['dienst_ein']) ? 'sm-aus' : 'sm-an' ?>"><?= frw_e(frw_dienst_satz($frw_cfg)) ?></td></tr>
<tr><td><?= frw_e(frw_t('KOPF.LETZTER_LAUF')) ?></td><td><?= $frw_k_ts > 0 ? frw_e(date('d.m.Y H:i:s', $frw_k_ts)) : '—' ?></td></tr>
<tr><td><?= frw_e(frw_t('KOPF.DATAMANAGER')) ?></td><td><?php
if (!$frw_dms) {
    echo frw_e(frw_t('KOPF.KEINE_ADRESSE'));
} else {
    foreach ($frw_dms as $frw_n => $frw_a) {
        $frw_d = isset($frw_stand['dm'][$frw_n]) ? $frw_stand['dm'][$frw_n] : null;
        $frw_txt = $frw_d === null ? frw_t('KOPF.NOCH_NICHT') : (!empty($frw_d['ok']) ? frw_t('KOPF.DM_OK') : sprintf(frw_t('KOPF.DM_FEHL'), (string) $frw_d['grund']));
        echo '<div>DM' . (int) $frw_n . ': <span class="' . ($frw_d !== null && !empty($frw_d['ok']) ? 'sm-an' : 'sm-aus') . '">' . frw_e($frw_txt) . '</span></div>';
    }
} ?></td></tr>
</table>

<!-- Die Reiterleiste steht AUSGESCHRIEBEN da (Hausstandard); ob Leiste,
     Bereiche und Positivliste dieselben Namen fuehren, zaehlt der Reiter
     Test nach. -->
<div class="sm-tabs">
	<a class="sm-tab<?= $frw_tab === 'tab-settings' ? ' sm-active' : '' ?>" data-ziel="tab-settings"
	   href="index.php?form=settings"><?= frw_e(frw_t('REITER.EINSTELLUNGEN')) ?></a>
	<a class="sm-tab<?= $frw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" data-ziel="tab-mqtt"
	   href="index.php?form=mqtt">MQTT</a>
	<a class="sm-tab<?= $frw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" data-ziel="tab-loxone"
	   href="index.php?form=loxone"><?= frw_e(frw_t('REITER.LOXONE')) ?></a>
	<a class="sm-tab<?= $frw_tab === 'tab-test' ? ' sm-active' : '' ?>" data-ziel="tab-test"
	   href="index.php?form=test"><?= frw_e(frw_t('REITER.TEST')) ?></a>
	<a class="sm-tab<?= $frw_tab === 'tab-log' ? ' sm-active' : '' ?>" data-ziel="tab-log"
	   href="index.php?form=log"><?= frw_e(frw_t('REITER.LOG')) ?></a>
</div>

<!-- ================= Einstellungen ================= -->
<div class="sm-seite<?= $frw_tab === 'tab-settings' ? ' sm-active' : '' ?>" id="tab-settings">
<div class="sm-hinweis"><?= frw_e(frw_t('EINST.WAS_IST_DAS')) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= frw_e(frw_t('LEGENDE.LESEN')) ?></span> <span><i class="sm-punkt sm-b-aktion"></i> <?= frw_e(frw_t('LEGENDE.AKTION')) ?></span></div>

<form action="index.php" method="post">
	<?php echo frw_fmt(); ?>
	<input data-role="none" type="hidden" name="activetab" value="tab-settings">
	<input data-role="none" type="hidden" name="formular" value="einstellungen">

<h2><?= frw_e(frw_t('EINST.H_DIENST')) ?></h2>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="dienst_ein" value="1"<?= !empty($frw_cfg['dienst_ein']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_DIENST')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_DIENST_HILFE')) ?></p>
</div>
<div class="sm-feld">
	<label><?= frw_e(frw_t('EINST.L_TAKT')) ?></label>
	<input data-role="none" type="number" name="takt_s" min="<?= (int) FRW_TAKT_MIN ?>" max="<?= (int) FRW_TAKT_MAX ?>" value="<?= (int) $frw_cfg['takt_s'] ?>">
	<p class="sm-hilfe"><?= frw_e(sprintf(frw_t('EINST.H_TAKT'), FRW_TAKT_VORGABE, FRW_TAKT_MIN, FRW_TAKT_MAX)) ?></p>
</div>
<div class="sm-feld">
	<label><?= frw_e(frw_t('EINST.L_TIMEOUT')) ?></label>
	<input data-role="none" type="number" name="timeout_s" min="<?= (int) FRW_TIMEOUT_MIN ?>" max="<?= (int) FRW_TIMEOUT_MAX ?>" value="<?= (int) $frw_cfg['timeout_s'] ?>">
	<p class="sm-hilfe"><?= frw_e(sprintf(frw_t('EINST.H_TIMEOUT'), FRW_TIMEOUT_VORGABE, FRW_PAUSE_S)) ?></p>
</div>

<h2><?= frw_e(frw_t('EINST.H_DM')) ?></h2>
<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_DM_HILFE')) ?></p>
<div class="sm-feld">
	<label><?= frw_e(sprintf(frw_t('EINST.L_DM'), 1)) ?></label>
	<input data-role="none" type="text" name="dm1_adresse" value="<?= frw_e($frw_cfg['dm1_adresse']) ?>" placeholder="192.0.2.31">
</div>
<div class="sm-feld">
	<label><?= frw_e(sprintf(frw_t('EINST.L_DM'), 2)) ?></label>
	<input data-role="none" type="text" name="dm2_adresse" value="<?= frw_e($frw_cfg['dm2_adresse']) ?>" placeholder="192.0.2.32">
</div>
<div class="sm-feld">
	<label><?= frw_e(sprintf(frw_t('EINST.L_DM'), 3)) ?></label>
	<input data-role="none" type="text" name="dm3_adresse" value="<?= frw_e($frw_cfg['dm3_adresse']) ?>">
</div>
<div class="sm-feld">
	<label><?= frw_e(sprintf(frw_t('EINST.L_DM'), 4)) ?></label>
	<input data-role="none" type="text" name="dm4_adresse" value="<?= frw_e($frw_cfg['dm4_adresse']) ?>">
</div>

<h2><?= frw_e(frw_t('EINST.H_GRUPPEN')) ?></h2>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="gruppe_wr" value="1"<?= !empty($frw_cfg['gruppe_wr']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_GRUPPE_WR')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_GRUPPE_WR')) ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="gruppe_energie" value="1"<?= !empty($frw_cfg['gruppe_energie']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_GRUPPE_ENERGIE')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_GRUPPE_ENERGIE')) ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="gruppe_speicher" value="1"<?= !empty($frw_cfg['gruppe_speicher']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_GRUPPE_SPEICHER')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_GRUPPE_SPEICHER')) ?></p>
</div>

<h2><?= frw_e(frw_t('EINST.H_MELDUNGEN')) ?></h2>
<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_MELDUNGEN_HILFE')) ?></p>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="meldung_stoerung" value="1"<?= !empty($frw_cfg['meldung_stoerung']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_MELD_STOERUNG')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_MELD_STOERUNG')) ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="meldung_ausfall" value="1"<?= !empty($frw_cfg['meldung_ausfall']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_MELD_AUSFALL')) ?></label>
	<p class="sm-hilfe"><?= frw_e(sprintf(frw_t('EINST.H_MELD_AUSFALL'), FRW_AUSFALL_MELDEN)) ?></p>
</div>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="meldung_speicher" value="1"<?= !empty($frw_cfg['meldung_speicher']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_MELD_SPEICHER')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_MELD_SPEICHER')) ?></p>
</div>
<div class="sm-feld">
	<label><?= frw_e(frw_t('EINST.L_SCHWELLE_STREUUNG')) ?></label>
	<input data-role="none" type="number" name="schwelle_soc_streuung" min="0" max="<?= (int) FRW_STREUUNG_MAX ?>" value="<?= (int) $frw_cfg['schwelle_soc_streuung'] ?>">
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_SCHWELLE_STREUUNG')) ?></p>
</div>
<div class="sm-feld">
	<label><?= frw_e(frw_t('EINST.L_SCHWELLE_ZELLEN')) ?></label>
	<input data-role="none" type="number" name="schwelle_zellen_mv" min="0" max="<?= (int) FRW_ZELLEN_MV_MAX ?>" value="<?= (int) $frw_cfg['schwelle_zellen_mv'] ?>">
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_SCHWELLE_ZELLEN')) ?></p>
</div>

<h2><?= frw_e(frw_t('EINST.H_EB')) ?></h2>
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="eb_anzeigen" value="1"<?= !empty($frw_cfg['eb_anzeigen']) ? ' checked' : '' ?>><?= frw_e(frw_t('EINST.L_EB')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('EINST.H_EB_HILFE')) ?></p>
</div>

<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="speichern" value="1"><?= frw_e(frw_t('EINST.K_SPEICHERN')) ?></button>
</div>
</form>

<h2><?= frw_e(frw_t('SICH.H')) ?></h2>
<div class="sm-hinweis"><?= frw_e(frw_t('SICH.ERKLAERUNG')) ?></div>
<div class="sm-warnung"><?= frw_e(frw_t('SICH.WARNUNG')) ?></div>
<div class="sm-knopfreihe">
	<!-- ZWEI GETRENNTE Formulare: das Sichern liefert einen Download, das
	     Zurueckspielen braucht multipart/form-data. -->
	<form action="index.php" method="post">
		<?php echo frw_fmt(); ?>
		<input data-role="none" type="hidden" name="activetab" value="tab-settings">
		<input data-role="none" type="hidden" name="formular" value="sichern">
		<button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="frw_sichern" value="1"><?= frw_e(frw_t('SICH.K_SICHERN')) ?></button>
	</form>
	<form action="index.php" method="post" enctype="multipart/form-data">
		<?php echo frw_fmt(); ?>
		<input data-role="none" type="hidden" name="activetab" value="tab-settings">
		<input data-role="none" type="hidden" name="formular" value="zurueck">
		<input data-role="none" type="file" name="frw_sicherung" accept=".json">
		<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="frw_zurueck" value="1"><?= frw_e(frw_t('SICH.K_ZURUECK')) ?></button>
	</form>
</div>
</div>

<!-- ================= MQTT ================= -->
<div class="sm-seite<?= $frw_tab === 'tab-mqtt' ? ' sm-active' : '' ?>" id="tab-mqtt">
<h2><?= frw_e(frw_t('MQTT.H')) ?></h2>
<div class="sm-step"><?= frw_e(frw_t('MQTT.ERKLAERUNG')) ?></div>
<div class="sm-legende"><span><i class="sm-punkt sm-b-aktion"></i> <?= frw_e(frw_t('LEGENDE.AKTION')) ?></span></div>
<form action="index.php" method="post">
	<?php echo frw_fmt(); ?>
	<input data-role="none" type="hidden" name="activetab" value="tab-mqtt">
	<input data-role="none" type="hidden" name="formular" value="mqtt">
<div class="sm-feld">
	<label><input data-role="none" type="checkbox" name="mqtt_ein" value="1"<?= !empty($frw_cfg['mqtt_ein']) ? ' checked' : '' ?>><?= frw_e(frw_t('MQTT.L_EIN')) ?></label>
	<p class="sm-hilfe"><?= frw_e(frw_t('MQTT.H_EIN')) ?></p>
</div>
<div class="sm-feld">
	<label><?= frw_e(frw_t('MQTT.L_PRAEFIX')) ?></label>
	<input data-role="none" type="text" name="mqtt_praefix" value="<?= frw_e($frw_pf) ?>" placeholder="<?= frw_e(FRW_MQTT_PRAEFIX_VORGABE) ?>">
	<p class="sm-hilfe"><?= frw_e(frw_t('MQTT.H_PRAEFIX')) ?></p>
</div>
<div class="sm-knopfreihe">
	<button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="mqtt_speichern" value="1"><?= frw_e(frw_t('MQTT.K_SPEICHERN')) ?></button>
</div>
</form>

<h3><?= frw_e(frw_t('MQTT.H_GATEWAY')) ?></h3>
<table class="sm-tbl" style="max-width:640px">
<tr><td><?= frw_e(frw_t('MQTT.T_FASSUNG')) ?></td><td><?= $frw_gw['fassung'] > 0 ? 'V' . (int) $frw_gw['fassung'] : frw_e(frw_t('MQTT.FASSUNG_UNBEKANNT')) ?></td></tr>
<tr><td><?= frw_e(frw_t('MQTT.T_AUTOSTART')) ?></td><td class="<?= $frw_gw['autostart'] ? 'sm-an' : 'sm-aus' ?>"><?= frw_e(frw_t($frw_gw['autostart'] ? 'MQTT.AUTOSTART_EIN' : 'MQTT.AUTOSTART_AUS')) ?></td></tr>
<tr><td><?= frw_e(frw_t('MQTT.T_UDP')) ?></td><td class="sm-mono"><?= (int) $frw_gw['udpport'] ?></td></tr>
<tr><td><?= frw_e(frw_t('MQTT.T_LAUF')) ?></td><td><?= isset($frw_stand['mqtt_zeilen']) ? frw_e(sprintf(frw_t('MQTT.LAUF_TEXT'), (int) $frw_stand['mqtt_gesendet'], (int) $frw_stand['mqtt_zeilen'])) : '—' ?></td></tr>
</table>
<?php if (!empty($frw_cfg['mqtt_ein']) && !$frw_gw['autostart']) { ?>
<div class="sm-warnung"><?= frw_e(frw_t('MQTT.AUTOSTART_WARNUNG')) ?></div>
<?php } ?>

<h3><?= frw_e(frw_t('MQTT.H_THEMEN')) ?></h3>
<p class="sm-hilfe"><?= frw_e(frw_t('MQTT.THEMEN_HILFE')) ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= frw_e(frw_t('MQTT.T_THEMA')) ?></th><th><?= frw_e(frw_t('MQTT.T_BEDEUTUNG')) ?></th><th><?= frw_e(frw_t('MQTT.T_EINHEIT')) ?></th><th><?= frw_e(frw_t('MQTT.T_RETAIN')) ?></th><th><?= frw_e(frw_t('MQTT.T_GRUPPE')) ?></th></tr>
<tr><td class="sm-mono"><?= frw_e($frw_pf) ?>/status/ok</td><td><?= frw_e(frw_t('THEMA.STATUS_OK')) ?></td><td></td><td><?= frw_e(frw_t('MQTT.RETAIN_NEIN')) ?></td><td><?= frw_e(frw_t('GRUPPE.IMMER')) ?></td></tr>
<tr><td class="sm-mono"><?= frw_e($frw_pf) ?>/status/ts</td><td><?= frw_e(frw_t('THEMA.STATUS_TS')) ?></td><td>s</td><td><?= frw_e(frw_t('MQTT.RETAIN_NEIN')) ?></td><td><?= frw_e(frw_t('GRUPPE.IMMER')) ?></td></tr>
<tr><td class="sm-mono"><?= frw_e($frw_pf) ?>/status/zaehler</td><td><?= frw_e(frw_t('THEMA.STATUS_ZAEHLER')) ?></td><td></td><td><?= frw_e(frw_t('MQTT.RETAIN_NEIN')) ?></td><td><?= frw_e(frw_t('GRUPPE.IMMER')) ?></td></tr>
<?php $frw_ft = frw_feldtabelle();
foreach (($frw_dms ? array_keys($frw_dms) : array(1)) as $frw_n) {
    $frw_w = frw_werte_dm($frw_n, isset($frw_stand['dm'][$frw_n]) ? $frw_stand['dm'][$frw_n] : array(), $frw_cfg, empty($frw_stand['dm'][$frw_n]['werte_zeit']));
    foreach ($frw_w as $frw_x) {
        $frw_f = $frw_ft[$frw_x['feld']]; ?>
<tr><td class="sm-mono"><?= frw_e($frw_pf . '/' . $frw_x['thema']) ?></td><td><?= frw_e(frw_t($frw_f[6])) ?><?= $frw_f[7] ? ' ' . frw_e(frw_t('MQTT.ABGELEITET')) : '' ?></td><td><?= frw_e($frw_f[2]) ?></td><td><?= frw_e(frw_t(frw_retain($frw_x['feld']) ? 'MQTT.RETAIN_JA' : 'MQTT.RETAIN_NEIN')) ?></td><td><?= frw_e(frw_t('GRUPPE.' . strtoupper($frw_f[0]))) ?></td></tr>
<?php }
} ?>
</table>
</div>
</div>

<!-- ================= Einbindung in Loxone ================= -->
<div class="sm-seite<?= $frw_tab === 'tab-loxone' ? ' sm-active' : '' ?>" id="tab-loxone">
<h2><?= frw_e(frw_t('LOX.H')) ?></h2>
<div class="sm-legende"><span><i class="sm-punkt sm-b-technik"></i> <?= frw_e(frw_t('LEGENDE.TECHNIK')) ?></span> <span><i class="sm-punkt sm-b-aktion"></i> <?= frw_e(frw_t('LEGENDE.AKTION')) ?></span></div>

<div class="sm-step"><h3><?= frw_e(frw_t('LOX.S1')) ?></h3><p class="sm-hilfe"><?= frw_e(frw_t('LOX.S1_TEXT')) ?></p>
<?php if (empty($frw_cfg['dienst_ein'])) { ?><div class="sm-warnung"><?= frw_e(frw_t('LOX.S1_DIENST_AUS')) ?></div><?php } ?>
</div>

<div class="sm-step"><h3><?= frw_e(frw_t('LOX.S2')) ?></h3>
<?php if ($frw_gw['fassung'] >= 2) { ?>
<div class="sm-hinweis"><?= frw_e(sprintf(frw_t('LOX.ABO_V2'), $frw_pf)) ?></div>
<?php } elseif ($frw_gw['fassung'] === 1) { ?>
<div class="sm-warnung"><?= frw_e(sprintf(frw_t('LOX.ABO_PFLICHT'), $frw_pf . '/#')) ?></div>
<?php } else { ?>
<div class="sm-warnung"><?= frw_e(sprintf(frw_t('LOX.ABO_PFLICHT'), $frw_pf . '/#')) ?></div>
<div class="sm-hilfe"><?= frw_e(sprintf(frw_t('LOX.ABO_V2'), $frw_pf)) ?></div>
<?php } ?>
</div>

<div class="sm-step"><h3><?= frw_e(frw_t('LOX.S3')) ?></h3><p class="sm-hilfe"><?= frw_e(frw_t('LOX.S3_TEXT')) ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th><?= frw_e(frw_t('LOX.T_NAME')) ?></th><th><?= frw_e(frw_t('LOX.T_BEDEUTUNG')) ?></th><th><?= frw_e(frw_t('MQTT.T_EINHEIT')) ?></th></tr>
<tr><td class="sm-mono"><?= frw_e(frw_gateway_name($frw_pf, 'status/ok')) ?></td><td><?= frw_e(frw_t('THEMA.STATUS_OK')) ?></td><td></td></tr>
<tr><td class="sm-mono"><?= frw_e(frw_gateway_name($frw_pf, 'status/ts')) ?></td><td><?= frw_e(frw_t('THEMA.STATUS_TS')) ?></td><td>s</td></tr>
<?php foreach (($frw_dms ? array_keys($frw_dms) : array(1)) as $frw_n) {
    foreach (frw_werte_dm($frw_n, isset($frw_stand['dm'][$frw_n]) ? $frw_stand['dm'][$frw_n] : array(), $frw_cfg, empty($frw_stand['dm'][$frw_n]['werte_zeit'])) as $frw_x) { ?>
<tr><td class="sm-mono"><?= frw_e(frw_gateway_name($frw_pf, $frw_x['thema'])) ?></td><td><?= frw_e(frw_t($frw_ft[$frw_x['feld']][6])) ?></td><td><?= frw_e($frw_ft[$frw_x['feld']][2]) ?></td></tr>
<?php }
} ?>
</table>
</div>
</div>

<div class="sm-step"><h3><?= frw_e(frw_t('LOX.S4')) ?></h3><p class="sm-hilfe"><?= frw_e(frw_t('LOX.S4_TEXT')) ?></p>
<p class="sm-hilfe"><?= frw_e(frw_t('LOX.HTTP_TEXT')) ?></p>
<?php foreach (($frw_dms ? array_keys($frw_dms) : array(1)) as $frw_n) { ?>
<p><span class="sm-mono"><?= frw_e(frw_endpunkt_adresse($frw_host, $frw_n)) ?></span></p>
<?php } ?>
<p class="sm-hilfe"><?= frw_e(frw_t('LOX.HTTP_MUSTER')) ?> <span class="sm-mono"><?= frw_e(frw_check('WR1_STATUS')) ?></span></p>
<p class="sm-hilfe"><?= frw_e(frw_t('LOX.VORLAGE_TEXT')) ?></p>
<?php foreach (($frw_dms ? array_keys($frw_dms) : array(1)) as $frw_n) { ?>
<form action="index.php" method="post">
	<?php echo frw_fmt(); ?>
	<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
	<input data-role="none" type="hidden" name="formular" value="vorlage">
	<input data-role="none" type="hidden" name="vorlage_dm" value="<?= (int) $frw_n ?>">
	<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-technik" type="submit" name="vorlage" value="1"><?= frw_e(sprintf(frw_t('LOX.K_VORLAGE'), (int) $frw_n)) ?></button></div>
</form>
<?php } ?>
<p class="sm-hilfe"><?= frw_e(frw_t('LOX.VORLAGE_DOPPELT')) ?></p>
</div>

<div class="sm-step"><h3><?= frw_e(frw_t('LOX.S5')) ?></h3><p class="sm-hilfe"><?= frw_e(frw_t('LOX.S5_TEXT')) ?></p>
<p><span class="sm-mono"><?= frw_e('http://' . $frw_host . '/plugins/' . $frw_p['plugin'] . '/index.php?aktion=jetzt&token=' . $frw_cfg['aktionstoken']) ?></span></p>
<form action="index.php" method="post">
	<?php echo frw_fmt(); ?>
	<input data-role="none" type="hidden" name="activetab" value="tab-loxone">
	<input data-role="none" type="hidden" name="formular" value="token">
	<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-aktion" type="submit" name="token_neu" value="1"><?= frw_e(frw_t('LOX.K_TOKEN_NEU')) ?></button></div>
	<p class="sm-hilfe"><?= frw_e(frw_t('LOX.TOKEN_WARNUNG')) ?></p>
</form>
</div>

<div class="sm-step"><h3><?= frw_e(frw_t('LOX.S6')) ?></h3><p class="sm-hilfe"><?= frw_e(frw_t('LOX.S6_TEXT')) ?></p>
<div class="sm-breit">
<table class="sm-tbl">
<tr><th>#</th><th><?= frw_e(frw_t('BAUSTEIN.T_TYP')) ?></th><th><?= frw_e(frw_t('BAUSTEIN.T_NAME')) ?></th><th><?= frw_e(frw_t('BAUSTEIN.T_PARAMETER')) ?></th><th><?= frw_e(frw_t('BAUSTEIN.T_EINGANG')) ?></th></tr>
<tr><td>1</td><td><?= frw_e(frw_t('BAUSTEIN.B1_TYP')) ?></td><td class="sm-mono"><?= frw_e(frw_gateway_name($frw_pf, 'status/ts')) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B1_PAR')) ?></td><td>—</td></tr>
<tr><td>2</td><td><?= frw_e(frw_t('BAUSTEIN.B2_TYP')) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B2_NAME')) ?></td><td><?= frw_e(sprintf(frw_t('BAUSTEIN.B2_PAR'), 3 * (int) $frw_cfg['takt_s'])) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B2_EIN')) ?></td></tr>
<tr><td>3</td><td><?= frw_e(frw_t('BAUSTEIN.B1_TYP')) ?></td><td class="sm-mono"><?= frw_e(frw_gateway_name($frw_pf, 'dm1/wr1/stoerung')) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B3_PAR')) ?></td><td>—</td></tr>
<tr><td>4</td><td><?= frw_e(frw_t('BAUSTEIN.B4_TYP')) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B4_NAME')) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B4_PAR')) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B4_EIN')) ?></td></tr>
<tr><td>5</td><td><?= frw_e(frw_t('BAUSTEIN.B1_TYP')) ?></td><td class="sm-mono"><?= frw_e(frw_gateway_name($frw_pf, 'dm1/sp1/soc_streuung')) ?></td><td><?= frw_e(frw_t('BAUSTEIN.B5_PAR')) ?></td><td>—</td></tr>
</table>
</div>
<p class="sm-hilfe"><?= frw_e(frw_t('BAUSTEIN.ERKLAERUNG')) ?></p>
<figure class="sm-bild">
<img src="einbindung_fronius_waechter.png" alt="<?= frw_e(frw_t('LOX.BILD_ALT')) ?>" loading="lazy">
<figcaption><?= frw_e(frw_t('LOX.BILD_UNTERSCHRIFT')) ?></figcaption>
</figure>
<p class="sm-hilfe"><?= frw_e(frw_t('LOX.MUSTERPROJEKT')) ?> <a href="https://github.com/timanders22/LoxBerry-Plugins-Musterprojekt" target="_blank" rel="noopener">LoxBerry-Plugins Musterprojekt</a></p>
</div>

<div class="sm-step"><h3><?= frw_e(frw_t('LOX.S7')) ?></h3><p class="sm-hilfe"><?= frw_e(frw_t('LOX.S7_TEXT')) ?></p></div>
</div>

<!-- ================= Test ================= -->
<div class="sm-seite<?= $frw_tab === 'tab-test' ? ' sm-active' : '' ?>" id="tab-test">
<h2><?= frw_e(frw_t('TEST.H')) ?></h2>
<div class="sm-legende"><span><i class="sm-punkt sm-b-lesen"></i> <?= frw_e(frw_t('LEGENDE.LESEN')) ?></span></div>
<?php
$frw_zeilen = array();
$frw_zeilen[] = array(frw_t('TEST.F_KONFIG'), frw_pruef_konfiguration());
$frw_zeilen[] = array(frw_t('TEST.F_HEIL'), frw_pruef_heil());
$frw_abgew = frw_config_abgewiesen();
$frw_zeilen[] = array(frw_t('TEST.F_WERTE'), $frw_abgew ? array('fehl', sprintf(frw_t('TEST.WERTE_FEHL'), implode(', ', $frw_abgew))) : array('ok', frw_t('TEST.WERTE_OK')));
$frw_zeilen[] = array(frw_t('TEST.F_REITER'), frw_pruef_reiter(__FILE__));
$frw_zeilen[] = array(frw_t('TEST.F_SMACTIVE'), frw_pruef_smactive(__FILE__));
$frw_zeilen[] = array(frw_t('TEST.F_FORM'), frw_pruef_formulare(__FILE__));
$frw_zeilen[] = array(frw_t('TEST.F_SUCH'), frw_pruef_suchmuster());
$frw_zeilen[] = array(frw_t('TEST.F_XML'), frw_pruef_vorlagen($frw_cfg, $frw_stand));
$frw_zeilen[] = array(frw_t('TEST.F_CRON'), frw_pruef_cron());
$frw_lauf_alter = isset($frw_stand['ts']) ? time() - (int) $frw_stand['ts'] : -1;
if (empty($frw_cfg['dienst_ein'])) {
    $frw_zeilen[] = array(frw_t('TEST.F_LAUF'), array('hinweis', frw_t('TEST.LAUF_AUS')));
} elseif ($frw_lauf_alter < 0) {
    $frw_zeilen[] = array(frw_t('TEST.F_LAUF'), array('hinweis', frw_t('TEST.LAUF_NIE')));
} else {
    $frw_zeilen[] = array(frw_t('TEST.F_LAUF'), array($frw_lauf_alter <= (int) $frw_cfg['takt_s'] + 120 ? 'ok' : 'fehl',
        sprintf(frw_t('TEST.LAUF_ALTER'), $frw_lauf_alter, (int) $frw_cfg['takt_s'])));
}
if ($frw_tab === 'tab-test') {
    $frw_zeilen[] = array(frw_t('TEST.F_ENDPUNKT'), frw_pruef_endpunkt($frw_cfg['aktionstoken']));
} else {
    $frw_zeilen[] = array(frw_t('TEST.F_ENDPUNKT'), array('hinweis', frw_t('TEST.EP_NUR_OFFEN')));
}
?>
<table class="sm-tbl">
<tr><th><?= frw_e(frw_t('TEST.T_FRAGE')) ?></th><th><?= frw_e(frw_t('TEST.T_ERGEBNIS')) ?></th></tr>
<?php $frw_rot = 0; $frw_offen = 0;
foreach ($frw_zeilen as $frw_z) {
    if ($frw_z[1][0] === 'fehl') { $frw_rot++; } elseif ($frw_z[1][0] === 'hinweis') { $frw_offen++; } ?>
<tr><td><?= frw_e($frw_z[0]) ?></td><td class="<?= $frw_z[1][0] === 'ok' ? 'sm-an' : ($frw_z[1][0] === 'fehl' ? 'sm-aus' : 'sm-grau') ?>"><?= frw_e(($frw_z[1][0] === 'ok' ? '✔ ' : ($frw_z[1][0] === 'fehl' ? '✘ ' : '– ')) . $frw_z[1][1]) ?></td></tr>
<?php } ?>
</table>
<p class="sm-hilfe"><?= frw_e(sprintf(frw_t('TEST.SUMME'), count($frw_zeilen), $frw_rot, $frw_offen)) ?></p>

<h3><?= frw_e(frw_t('TEST.H_STAND')) ?></h3>
<?php if (empty($frw_stand['dm'])) { ?>
<div class="sm-hinweis"><?= frw_e(frw_t('TEST.STAND_LEER')) ?></div>
<?php } else {
    foreach ($frw_stand['dm'] as $frw_n => $frw_d) { ?>
<h4>DM<?= (int) $frw_n ?> — <?= frw_e(!empty($frw_d['ok']) ? frw_t('KOPF.DM_OK') : sprintf(frw_t('KOPF.DM_FEHL'), (string) $frw_d['grund'])) ?></h4>
<table class="sm-tbl" style="max-width:760px">
<tr><td><?= frw_e(frw_t('TEST.ANTWORTZEITEN')) ?></td><td><?php foreach ((isset($frw_d['ms']) && is_array($frw_d['ms'])) ? $frw_d['ms'] : array() as $frw_an => $frw_ms) { echo frw_e($frw_an . ' ' . (int) $frw_ms . ' ms') . '<br>'; } ?></td></tr>
<?php foreach ((isset($frw_d['wr']) && is_array($frw_d['wr'])) ? $frw_d['wr'] : array() as $frw_id => $frw_w) { ?>
<tr><td><?= frw_e(sprintf(frw_t('TEST.WR'), $frw_id)) ?></td><td><?php
    if (isset($frw_w['status'])) { echo frw_e(sprintf(frw_t('TEST.WR_STATUS'), $frw_w['status'], frw_text_oder_code(frw_status_schluessel($frw_w['status']), $frw_w['status']))) . '<br>'; }
    if (isset($frw_w['fehler'])) { echo frw_e(sprintf(frw_t('TEST.WR_FEHLER'), $frw_w['fehler'], frw_text_oder_code(frw_fehler_schluessel($frw_w['fehler']), $frw_w['fehler']))) . '<br>'; }
    if (isset($frw_w['stoerung'])) { echo '<span class="' . ($frw_w['stoerung'] ? 'sm-aus' : 'sm-an') . '">' . frw_e(frw_t($frw_w['stoerung'] ? 'TEST.STOERUNG_JA' : 'TEST.STOERUNG_NEIN')) . '</span><br>'; }
    if (isset($frw_w['e_tag'], $frw_w['e_jahr'], $frw_w['e_gesamt'])) { echo frw_e(sprintf(frw_t('TEST.WR_ENERGIE'), $frw_w['e_tag'] / 1000, $frw_w['e_jahr'] / 1000, $frw_w['e_gesamt'] / 1000)); }
?></td></tr>
<?php }
    foreach ((isset($frw_d['sp']) && is_array($frw_d['sp'])) ? $frw_d['sp'] : array() as $frw_sid => $frw_s) { ?>
<tr><td><?= frw_e(sprintf(frw_t('TEST.SP'), $frw_sid)) ?></td><td><?= frw_e(sprintf(frw_t('TEST.SP_ZEILE'),
    isset($frw_s['soc']) ? $frw_s['soc'] : '-', isset($frw_s['anzahl']) ? $frw_s['anzahl'] : 0,
    isset($frw_s['soc_min']) ? $frw_s['soc_min'] : '-', isset($frw_s['soc_max']) ? $frw_s['soc_max'] : '-',
    isset($frw_s['u_diff_mv']) ? $frw_s['u_diff_mv'] : '-', isset($frw_s['zyklen_min']) ? $frw_s['zyklen_min'] : '-',
    isset($frw_s['zyklen_max']) ? $frw_s['zyklen_max'] : '-')) ?></td></tr>
<?php foreach ((isset($frw_s['module']) && is_array($frw_s['module'])) ? $frw_s['module'] : array() as $frw_mk => $frw_mo) { ?>
<tr><td><?= frw_e(sprintf(frw_t('TEST.MODUL'), $frw_mk)) ?></td><td><?= frw_e(sprintf(frw_t('TEST.MODUL_ZEILE'),
    isset($frw_mo['soc']) ? $frw_mo['soc'] : '-', isset($frw_mo['zyklen']) ? $frw_mo['zyklen'] : '-',
    isset($frw_mo['u_min']) ? frw_wert_text($frw_mo['u_min']) : '-', isset($frw_mo['u_max']) ? frw_wert_text($frw_mo['u_max']) : '-',
    isset($frw_mo['temp']) ? frw_wert_text(round($frw_mo['temp'], 1)) : '-',
    isset($frw_mo['zustand']) ? frw_t(frw_zelle_schluessel($frw_mo['zustand'])) : '-',
    isset($frw_mo['zustand_roh']) ? frw_wert_text($frw_mo['zustand_roh']) : '-')) ?></td></tr>
<?php }
    }
    if (!empty($frw_d['fehlt'])) { ?>
<tr><td><?= frw_e(frw_t('TEST.FEHLT')) ?></td><td><?= frw_e(implode(', ', $frw_d['fehlt'])) ?></td></tr>
<?php } ?>
</table>
<?php }
} ?>

<h3><?= frw_e(frw_t('TEST.H_MESSEN')) ?></h3>
<p class="sm-hilfe"><?= frw_e(sprintf(frw_t('TEST.MESSEN_TEXT'), (int) $frw_cfg['timeout_s'])) ?></p>
<form action="index.php" method="post">
	<?php echo frw_fmt(); ?>
	<input data-role="none" type="hidden" name="activetab" value="tab-test">
	<input data-role="none" type="hidden" name="formular" value="messen">
	<div class="sm-knopfreihe"><button data-role="none" class="sm-btn sm-b-lesen" type="submit" name="messen" value="1"><?= frw_e(frw_t('TEST.K_MESSEN')) ?></button></div>
</form>
<?php if (is_array($frw_messung) && isset($frw_messung['dm'])) { ?>
<table class="sm-tbl" style="max-width:640px">
<?php foreach ($frw_messung['dm'] as $frw_n => $frw_mm) { ?>
<tr><td>DM<?= (int) $frw_n ?></td><td class="<?= !empty($frw_mm['ok']) ? 'sm-an' : 'sm-aus' ?>"><?= frw_e(!empty($frw_mm['ok']) ? sprintf(frw_t('TEST.MESS_OK'), (int) $frw_mm['ms']) : sprintf(frw_t('TEST.MESS_FEHL'), (int) $frw_mm['ms'], (string) $frw_mm['grund'])) ?></td></tr>
<?php } ?>
</table>
<?php } ?>

<?php if (!empty($frw_cfg['eb_anzeigen'])) { $frw_eb = frw_eb_zustand(); ?>
<h3><?= frw_e(frw_t('EB.H')) ?></h3>
<?php if (!$frw_eb['da']) { ?>
<div class="sm-hinweis"><?= frw_e(frw_t('EB.NICHT_DA')) ?></div>
<?php } else { ?>
<table class="sm-tbl" style="max-width:640px">
<tr><td><?= frw_e(frw_t('EB.REGELUNG')) ?></td><td><?= frw_e($frw_eb['ein'] === null ? '—' : frw_t($frw_eb['ein'] ? 'EB.EIN' : 'EB.AUS')) ?></td></tr>
<tr><td><?= frw_e(frw_t('EB.TAKT')) ?></td><td><?= $frw_eb['takt'] === null ? '—' : (int) $frw_eb['takt'] . ' s' ?></td></tr>
<tr><td><?= frw_e(frw_t('EB.ALTER')) ?></td><td><?= $frw_eb['alter'] === null ? '—' : (int) $frw_eb['alter'] . ' s' ?></td></tr>
</table>
<p class="sm-hilfe"><?= frw_e(frw_t('EB.HILFE')) ?></p>
<?php }
} ?>
</div>

<!-- ================= Logdateien ================= -->
<div class="sm-seite<?= $frw_tab === 'tab-log' ? ' sm-active' : '' ?>" id="tab-log">
<h2><?= frw_e(frw_t('LOG.H')) ?></h2>
<div class="sm-hinweis"><?= frw_e(frw_t('LOG.RAMDISK')) ?></div>
<?php if ($frw_rahmen && method_exists('LBWeb', 'loglist_html')) { echo LBWeb::loglist_html(); } ?>
<?php foreach (array(array('LOG.H_PLUGIN', $frw_p['logdatei']), array('LOG.H_CRONERR', $frw_p['log'] !== '' ? $frw_p['log'] . '/cron.err' : '')) as $frw_ld) {
    $frw_zeilen_log = frw_log_ende($frw_ld[1], 200); ?>
<h3><?= frw_e(frw_t($frw_ld[0])) ?></h3>
<p class="sm-hilfe sm-mono"><?= frw_e($frw_ld[1] !== '' ? $frw_ld[1] : '—') ?></p>
<?php if ($frw_zeilen_log) { ?>
<div class="sm-log"><?= frw_e(implode("\n", $frw_zeilen_log)) ?></div>
<?php } else { ?>
<div class="sm-hilfe"><?= frw_e(frw_t($frw_ld[1] !== '' && is_file($frw_ld[1]) ? 'LOG.DATEI_LEER' : 'LOG.DATEI_FEHLT')) ?></div>
<?php }
} ?>
</div>

</div>

<script>
(function () {
	var reiter = document.querySelectorAll('.sm-tab');
	function zeige(id) {
		reiter.forEach(function (r) { r.classList.toggle('sm-active', r.dataset.ziel === id); });
		document.querySelectorAll('.sm-seite').forEach(function (s) { s.classList.toggle('sm-active', s.id === id); });
		document.querySelectorAll('input[name="activetab"]').forEach(function (f) { f.value = id; });
		if (history.replaceState) { history.replaceState(null, '', 'index.php?form=' + id.replace('tab-', '')); }
	}
	reiter.forEach(function (r) {
		r.addEventListener('click', function (e) {
			/* Nur der Reiter Test laedt neu - seine Netzpruefzeile laeuft nur, wenn er der offene ist. */
			if (r.dataset.ziel === 'tab-test') { return; }
			e.preventDefault(); zeige(r.dataset.ziel);
		});
	});
	zeige(<?= json_encode($frw_tab) ?>);
})();
</script>
<?php
if ($frw_rahmen) {
    LBWeb::lbfooter();
}
