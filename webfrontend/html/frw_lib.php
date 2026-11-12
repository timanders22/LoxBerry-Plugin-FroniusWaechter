<?php
/**
 * Fronius-Waechter - gemeinsamer Unterbau
 *
 * Liest die Fronius Solar API v1 der eingetragenen Datamanager, NUR lesend:
 * Status- und Fehlercode je Wechselrichter, die geraetegezaehlten Energie-
 * staende und - wenn eingeschaltet - die Werte der Speichermodule. Kein
 * Modbus, kein Befehl, keine Steuerung.
 *
 * Rollen (Regeln/03, drei Aufgaben, drei Dateien):
 *   webfrontend/htmlauth/index.php   Oberflaeche
 *   bin/frw_dienst.php               Abruf im Minutentakt (Cron)
 *   webfrontend/html/index.php       Endpunkt fuer den Miniserver (liest nur
 *                                    den Stand, fragt nie einen Datamanager)
 *
 * Rueksicht auf andere Leser derselben Datamanager (im Haus: Einspeisebremse,
 * 5-s-Takt): je Datamanager eine Anfrage nach der anderen, nie zwei zugleich
 * (Sperre abruf.lock auch fuer den Messknopf im Reiter Test), 4 s Abstand
 * zwischen zwei Anfragen an denselben Datamanager (Solar API V1, 42,0410,2012,
 * Abschnitt 2.6 "Timeout"), kurze Zeitueberschreitung, grober Takt.
 *
 * (c) Fronius-Waechter Plugin Authors - MIT-Lizenz
 */

/* ------------------------------------------------------------------
 * Grenzen - jede steht genau hier (Regeln/04).
 * ------------------------------------------------------------------ */
define('FRW_FASSUNG', '0.9.2');
define('FRW_DM_MAX', 4);
/* Takt: Vorgabe 300 s. Status- und Fehlercode, Energiestaende und
   Modulwerte aendern sich im Minutenbereich nicht in einer Weise, die eine
   Meldung schneller machen muesste; der Wert haelt die Last auf dem
   Datamanager klein (3 bis 4 Anfragen je Lauf neben 12 je Minute der
   Einspeisebremse). Untergrenze 60 s (Cron-Takt), Obergrenze eine Stunde. */
define('FRW_TAKT_VORGABE', 300);
define('FRW_TAKT_MIN', 60);
define('FRW_TAKT_MAX', 3600);
/* Zeitueberschreitung je Anfrage. Gemessen 12.11.2026 nachts: hoechstens
   687 ms (Speicherabfrage am Hybrid), sonst unter 300 ms. */
define('FRW_TIMEOUT_VORGABE', 3);
define('FRW_TIMEOUT_MIN', 1);
define('FRW_TIMEOUT_MAX', 5);
/* Solar API V1, Abschnitt 2.6: "Up to 2 realtime requests are allowed to be
   performed in parallel with keeping a timeout of 4 seconds between two
   consecutive calls." Dieses Plugin stellt nie zwei zugleich und haelt den
   Abstand ein. */
define('FRW_PAUSE_S', 4);
define('FRW_MAX_ANTWORT', 262144);
define('FRW_GERAETE_NEU', 3600);
define('FRW_VOLLVERSAND', 1800);
define('FRW_MARKE_FRIST', 900);
define('FRW_AUSFALL_MELDEN', 3);
define('FRW_MQTT_PRAEFIX_VORGABE', 'froniuswaechter');
define('FRW_STREUUNG_MAX', 100);
define('FRW_ZELLEN_MV_MAX', 1000);

/* ------------------------------------------------------------------
 * Wurzel und Pfade
 * ------------------------------------------------------------------ */
function frw_wurzel()
{
    $lb = getenv('LBHOMEDIR');
    if ($lb !== false && $lb !== '') {
        return rtrim($lb, '/\\');
    }
    $d = __DIR__;
    for ($i = 0; $i < 8; $i++) {
        if (is_dir($d . '/config/plugins') && is_dir($d . '/data/plugins')
            && is_file($d . '/config/system/general.json')) {
            return $d;
        }
        $eltern = dirname($d);
        if ($eltern === $d) {
            break;
        }
        $d = $eltern;
    }
    return '';
}

function frw_paths()
{
    static $p = null;
    if ($p !== null) {
        return $p;
    }
    /* Am Geraet ist LBPPLUGINDIR keine Umgebungsvariable (Regeln/03); der
       Ordner ist die letzte Stufe des eigenen Pfades. */
    $ordner = basename(__DIR__);
    if ($ordner === '' || $ordner === '.' || $ordner === 'html' || $ordner === 'htmlauth'
        || $ordner === 'plugins' || $ordner === 'bin') {
        $ordner = 'froniuswaechter';
    }
    $lb = frw_wurzel();
    $p = array(
        'plugin'  => $ordner,
        'lbhome'  => $lb,
        'config'  => $lb !== '' ? $lb . '/config/plugins/' . $ordner : '',
        'log'     => $lb !== '' ? $lb . '/log/plugins/' . $ordner : '',
        'datadir' => $lb !== '' ? $lb . '/data/plugins/' . $ordner : '',
    );
    $p['cfgdatei'] = $p['config'] !== '' ? $p['config'] . '/froniuswaechter.json' : '';
    $p['sicherung'] = $lb !== '' ? $lb . '/config/plugins/' . $ordner . '.backup.json' : '';
    $p['logdatei'] = $p['log'] !== '' ? $p['log'] . '/froniuswaechter.log' : '';
    return $p;
}

/* ------------------------------------------------------------------
 * Vorgaben. Ab Werk aus: der Dienst, alle Meldungen, alle Schwellen, die
 * Speichermodule als Sendegruppe (Auftrag des Hausherrn, 12.11.2026).
 * ------------------------------------------------------------------ */
function frw_vorgaben()
{
    return array(
        'aktionstoken'     => '',
        'dienst_ein'       => 0,
        'takt_s'           => FRW_TAKT_VORGABE,
        'timeout_s'        => FRW_TIMEOUT_VORGABE,
        'dm1_adresse'      => '',
        'dm2_adresse'      => '',
        'dm3_adresse'      => '',
        'dm4_adresse'      => '',
        'gruppe_wr'        => 1,
        'gruppe_energie'   => 1,
        'gruppe_speicher'  => 0,
        'meldung_stoerung' => 0,
        'meldung_ausfall'  => 0,
        'meldung_speicher' => 0,
        'schwelle_soc_streuung' => 0,
        'schwelle_zellen_mv'    => 0,
        'mqtt_ein'         => 1,
        'mqtt_praefix'     => FRW_MQTT_PRAEFIX_VORGABE,
        'eb_anzeigen'      => 0,
    );
}

/** Schalter: nur 0 und 1 sind Werte. */
function frw_schalter_schluessel()
{
    return array('dienst_ein', 'gruppe_wr', 'gruppe_energie', 'gruppe_speicher', 'meldung_stoerung',
                 'meldung_ausfall', 'meldung_speicher', 'mqtt_ein', 'eb_anzeigen');
}

/* ------------------------------------------------------------------
 * Kleine Helfer
 * ------------------------------------------------------------------ */
function frw_e($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES, 'UTF-8');
}

function frw_xml($s)
{
    return htmlspecialchars((string) $s, ENT_QUOTES | ENT_XML1, 'UTF-8');
}

function frw_kurz($s, $n = 80)
{
    $s = (string) $s;
    $s = preg_replace('/[\x00-\x1F\x7F]/', ' ', $s);
    return strlen($s) > $n ? substr($s, 0, $n) . '...' : $s;
}

function frw_sprachdatei()
{
    $p = frw_paths();
    $kand = array();
    if ($p['lbhome'] !== '') {
        $kand[] = $p['lbhome'] . '/templates/plugins/' . $p['plugin'];
    }
    $kand[] = dirname(dirname(dirname(__DIR__))) . '/templates/plugins/' . basename(__DIR__);
    $kand[] = dirname(dirname(__DIR__)) . '/templates';
    $t = $kand[count($kand) - 1];
    foreach ($kand as $k) {
        if (is_dir($k . '/lang')) {
            $t = $k;
            break;
        }
    }
    $f = $t . '/lang/language_' . frw_sprache() . '.ini';
    return is_file($f) ? $f : $t . '/lang/language_de.ini';
}

function frw_sprache()
{
    static $l = null;
    if ($l !== null) {
        return $l;
    }
    $l = 'de';
    $p = frw_paths();
    $g = $p['lbhome'] !== '' ? frw_json_lesen($p['lbhome'] . '/config/system/general.json') : null;
    if (is_array($g) && isset($g['Base']['Lang']) && $g['Base']['Lang'] === 'en') {
        $l = 'en';
    }
    return $l;
}

function frw_t($schluessel)
{
    static $tab = null;
    if ($tab === null) {
        $tab = @parse_ini_file(frw_sprachdatei(), true);
        if (!is_array($tab)) {
            $tab = array();
        }
    }
    $teil = explode('.', (string) $schluessel, 2);
    if (count($teil) === 2 && isset($tab[$teil[0]][$teil[1]])) {
        return $tab[$teil[0]][$teil[1]];
    }
    return (string) $schluessel;
}

function frw_log($text)
{
    $p = frw_paths();
    if ($p['logdatei'] === '') {
        return;
    }
    if (!is_dir($p['log'])) {
        @mkdir($p['log'], 0775, true);
    }
    $f = $p['logdatei'];
    clearstatcache(true, $f);
    if (is_file($f) && filesize($f) > 262144) {
        $z = @file($f, FILE_IGNORE_NEW_LINES);
        if (is_array($z)) {
            @file_put_contents($f, implode("\n", array_slice($z, -800)) . "\n");
        }
    }
    @file_put_contents($f, date('Y-m-d H:i:s') . ' ' . $text . "\n", FILE_APPEND);
}

/** Die letzten Zeilen einer Datei, rueckwaerts mit fseek (Hausvorlage). */
function frw_log_ende($datei, $anzahl = 300, $block = 8192)
{
    if ($datei === '' || !is_file($datei)) {
        return array();
    }
    $fp = @fopen($datei, 'rb');
    if ($fp === false) {
        return array();
    }
    fseek($fp, 0, SEEK_END);
    $pos = ftell($fp);
    $puffer = '';
    $zeilen = array();
    while ($pos > 0 && count($zeilen) <= $anzahl) {
        $lese = (int) min($block, $pos);
        $pos -= $lese;
        fseek($fp, $pos, SEEK_SET);
        $puffer = fread($fp, $lese) . $puffer;
        $zeilen = explode("\n", $puffer);
    }
    fclose($fp);
    $zeilen = array_values(array_filter(array_map('rtrim', $zeilen), 'strlen'));
    return array_slice(array_reverse($zeilen), 0, $anzahl);
}

/* ------------------------------------------------------------------
 * JSON lesen und unteilbar schreiben (Rechte beim Anlegen, Regeln/03)
 * ------------------------------------------------------------------ */
function frw_json_lesen($f)
{
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    return is_array($d) ? $d : null;
}

function frw_json_schreiben($pfad, array $daten)
{
    if ($pfad === '') {
        return false;
    }
    $ordner = dirname($pfad);
    if (!is_dir($ordner) && !@mkdir($ordner, 0775, true) && !is_dir($ordner)) {
        return false;
    }
    $js = json_encode($daten, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    if ($js === false) {
        return false;
    }
    $tmp = $pfad . '.tmp.' . getmypid();
    $umask_alt = umask(0077);
    $fh = @fopen($tmp, 'c');
    umask($umask_alt);
    if ($fh === false) {
        return false;
    }
    @chmod($tmp, 0600);
    $ok = ftruncate($fh, 0) && @fwrite($fh, $js) === strlen($js);
    if (!@fflush($fh)) {
        $ok = false;
    }
    if (!@fclose($fh)) {
        $ok = false;
    }
    if (!$ok || !@rename($tmp, $pfad)) {
        @unlink($tmp);
        return false;
    }
    return true;
}

/* ------------------------------------------------------------------
 * Konfiguration: Inhalt heisst "Objekt mit Aktionstoken-Schluessel"
 * (Regeln/05, nach Inhalt, nicht nach Form).
 * ------------------------------------------------------------------ */
function frw_hat_inhalt($d)
{
    return is_array($d) && array_key_exists('aktionstoken', $d) && is_string($d['aktionstoken']);
}

/** Zustand beim ERSTEN Lesen, vor der Heilung (Regeln/05). */
function frw_config_zustand($neu = null)
{
    static $z = null;
    if ($neu !== null && $z === null) {
        $z = $neu;
    }
    return $z;
}

/** Namen der beim Lesen abgewiesenen Werte (erster Aufruf zaehlt). */
function frw_config_abgewiesen($neu = null)
{
    static $a = null;
    if ($neu !== null && $a === null) {
        $a = $neu;
    }
    return $a === null ? array() : $a;
}

function frw_config_heilen(array $p, $d)
{
    if ($p['cfgdatei'] === '') {
        return $d;
    }
    $da = is_file($p['cfgdatei']);
    $kaputt = $da && $d === null && trim((string) @file_get_contents($p['cfgdatei'])) !== '';
    $z = frw_json_lesen($p['sicherung']);
    if (!frw_hat_inhalt($z) && !$kaputt) {
        return $d;
    }
    if ($kaputt || (is_array($d) && count($d) > 0)) {
        $k = $p['cfgdatei'] . '.kaputt';
        if (@rename($p['cfgdatei'], $k)) {
            @chmod($k, 0600);
        }
    }
    if (!frw_hat_inhalt($z)) {
        frw_log('Konfiguration unlesbar und keine Zweitschrift mit Inhalt - der Stand liegt als '
            . basename($p['cfgdatei']) . '.kaputt, begonnen wird mit den Vorgaben.');
        return null;
    }
    if (!frw_json_schreiben($p['cfgdatei'], $z)) {
        frw_log('Konfiguration ohne Inhalt; die Zweitschrift liess sich nicht zurueckschreiben.');
        return $z;
    }
    frw_log('Konfiguration aus der Zweitschrift wiederhergestellt.');
    return $z;
}

/**
 * Die Konfiguration lesen. $erzeugen = false (Endpunkt, Dienst): liest nur,
 * legt nichts an, heilt nicht; ohne Inhalt null (Regeln/05).
 */
function frw_config($erzeugen = true)
{
    $p = frw_paths();
    $c = frw_vorgaben();
    $d = frw_json_lesen($p['cfgdatei']);
    if (!frw_hat_inhalt($d)) {
        if (!$erzeugen) {
            return null;
        }
        $da = $p['cfgdatei'] !== '' && is_file($p['cfgdatei']);
        $vorher = !$da ? 'fehlt' : ($d === null ? 'kaputt' : (count($d) === 0 ? 'leer' : 'ohne_token'));
        $d = frw_config_heilen($p, $d);
        if (frw_hat_inhalt($d)) {
            $vorher = ($vorher === 'kaputt') ? 'kaputt_zweitschrift' : 'zweitschrift';
        }
        frw_config_zustand($vorher);
    } else {
        frw_config_zustand('ok');
    }
    $je_gesetzt = false;
    $abgewiesen = array();
    if (is_array($d)) {
        foreach ($c as $k => $v) {
            if (array_key_exists($k, $d)) {
                list($ok, $wert) = frw_wert_pruefen($k, $d[$k]);
                if ($ok) {
                    $c[$k] = $wert;
                } else {
                    $abgewiesen[] = $k;
                }
            }
        }
        $je_gesetzt = array_key_exists('aktionstoken', $d);
    }
    /* Ein unzulaessiger gespeicherter Wert gilt nicht - das wird gesagt
       (Reiter Test, und einmal je Oberflaechenaufruf im Protokoll), nicht
       still zurechtgebogen. */
    frw_config_abgewiesen($abgewiesen);
    if ($abgewiesen && $erzeugen) {
        frw_log('Unzulaessige Werte in der Konfiguration, es gilt die Vorgabe: ' . implode(', ', $abgewiesen));
    }
    /* Selbsterzeugtes Geheimnis: genau einmal, unterschieden per
       array_key_exists() (Regeln/05). */
    if ($erzeugen && !$je_gesetzt) {
        $c['aktionstoken'] = frw_token_erzeugen();
        if (frw_config_speichern($c)) {
            frw_log('Aktionstoken angelegt (erste Einrichtung).');
        }
    }
    return $c;
}

function frw_config_speichern(array $c, &$zweit = null)
{
    $p = frw_paths();
    $zweit = false;
    $voll = frw_vorgaben();
    foreach ($voll as $k => $v) {
        if (array_key_exists($k, $c)) {
            $voll[$k] = $c[$k];
        }
    }
    if (!frw_json_schreiben($p['cfgdatei'], $voll)) {
        return false;
    }
    if (frw_hat_inhalt($voll)) {
        $zweit = frw_json_schreiben($p['sicherung'], $voll);
        if (!$zweit) {
            frw_log('Die Zweitschrift ' . $p['sicherung'] . ' liess sich nicht schreiben.');
        }
    }
    return true;
}

/**
 * Fehlende Vorgaben EINMAL in die Datei schreiben (Regeln/05,
 * vervollstaendigen statt ergaenzen). Nur mit Wurzel, nicht waehrend eines
 * Updates, nur bei einer Datei mit Inhalt. Rueckgabe: Liste der Namen.
 */
function frw_config_vervollstaendigen()
{
    $p = frw_paths();
    if ($p['lbhome'] === '' || $p['cfgdatei'] === '' || frw_upgrade_laeuft()) {
        return array();
    }
    $d = frw_json_lesen($p['cfgdatei']);
    if (!frw_hat_inhalt($d)) {
        return array();
    }
    $fehlend = array();
    foreach (frw_vorgaben() as $k => $w) {
        if (!array_key_exists($k, $d)) {
            $d[$k] = $w;
            $fehlend[] = $k;
        }
    }
    if (!$fehlend) {
        return array();
    }
    if (frw_config_speichern($d)) {
        frw_log('Konfiguration vervollstaendigt - ' . count($fehlend) . ' fehlende Vorgaben geschrieben: '
            . implode(', ', $fehlend));
    } else {
        frw_log('Konfiguration NICHT vervollstaendigt: Datei nicht schreibbar (' . count($fehlend) . ' Schluessel).');
    }
    return $fehlend;
}

function frw_upgrade_laeuft()
{
    $p = frw_paths();
    if ($p['datadir'] === '') {
        return false;
    }
    $marke = dirname($p['datadir']) . '/' . $p['plugin'] . '.upgrade_laeuft';
    clearstatcache(true, $marke);
    return is_file($marke) && abs(time() - (int) @filemtime($marke)) < FRW_MARKE_FRIST;
}

function frw_token_erzeugen()
{
    try {
        return bin2hex(random_bytes(16));
    } catch (Exception $e) {
        return substr(hash('sha256', uniqid('', true) . microtime(true)), 0, 32);
    }
}

/** Weit gefasst (Regeln/05): was ohne Kodierung in eine Adresse passt, 0-64. */
function frw_token_taugt($t)
{
    return is_string($t) && preg_match('/^[A-Za-z0-9_.\-]{0,64}$/', $t) === 1;
}

/**
 * Adresse eines Datamanagers: Rechnername oder IPv4, wahlweise :Port. Ohne
 * http:// und ohne Pfad. Rueckgabe '' = unzulaessig (abgewiesen, nie
 * zurechtgebogen).
 */
function frw_adresse_pruefen($a)
{
    if (!is_string($a)) {
        return '';
    }
    if ($a === '' || strlen($a) > 255) {
        return '';
    }
    if (preg_match('/^([A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?(?:\.[A-Za-z0-9](?:[A-Za-z0-9-]{0,62}[A-Za-z0-9])?)*)(?::([0-9]{1,5}))?$/', $a, $m) !== 1) {
        return '';
    }
    if (isset($m[2]) && $m[2] !== '' && ((int) $m[2] < 1 || (int) $m[2] > 65535)) {
        return '';
    }
    if (preg_match('/^[0-9.]+$/', $m[1]) === 1) {
        $teile = explode('.', $m[1]);
        if (count($teile) !== 4) {
            return '';
        }
        foreach ($teile as $t) {
            if ($t === '' || (int) $t > 255 || (strlen($t) > 1 && $t[0] === '0')) {
                return '';
            }
        }
    }
    return $a;
}

/**
 * Ein Wert fuer einen Schluessel: Positivliste je Schluessel, dieselbe fuer
 * Formular, Lesen und Zurueckspielen (Regeln/05). Rueckgabe
 * array(ok, wert, grund).
 */
function frw_wert_pruefen($k, $w)
{
    if (is_array($w) || is_object($w) || $w === null) {
        return array(false, null, sprintf(frw_t('SICH.WERT_FORM'), $k));
    }
    if (in_array($k, frw_schalter_schluessel(), true)) {
        if ($w === 0 || $w === 1 || $w === '0' || $w === '1' || $w === true || $w === false) {
            return array(true, ($w === 1 || $w === '1' || $w === true) ? 1 : 0, '');
        }
        return array(false, null, sprintf(frw_t('SICH.WERT_SCHALTER'), $k));
    }
    if ($k === 'aktionstoken') {
        return frw_token_taugt($w) ? array(true, (string) $w, '')
            : array(false, null, sprintf(frw_t('SICH.WERT_TOKEN'), strlen((string) $w)));
    }
    if (preg_match('/^dm[1-4]_adresse$/', $k) === 1) {
        if ($w === '') {
            return array(true, '', '');
        }
        $a = frw_adresse_pruefen($w);
        return $a !== '' ? array(true, $a, '') : array(false, null, sprintf(frw_t('SICH.WERT_ADRESSE'), $k, frw_kurz($w, 40)));
    }
    $grenzen = array(
        'takt_s'                => array(FRW_TAKT_MIN, FRW_TAKT_MAX),
        'timeout_s'             => array(FRW_TIMEOUT_MIN, FRW_TIMEOUT_MAX),
        'schwelle_soc_streuung' => array(0, FRW_STREUUNG_MAX),
        'schwelle_zellen_mv'    => array(0, FRW_ZELLEN_MV_MAX),
    );
    if (isset($grenzen[$k])) {
        $s = is_int($w) ? (string) $w : (is_string($w) ? trim($w) : '');
        if (preg_match('/^[0-9]{1,6}$/', $s) !== 1 || (int) $s < $grenzen[$k][0] || (int) $s > $grenzen[$k][1]) {
            return array(false, null, sprintf(frw_t('SICH.WERT_ZAHL'), $k, frw_kurz((string) $w, 20),
                $grenzen[$k][0], $grenzen[$k][1]));
        }
        return array(true, (int) $s, '');
    }
    if ($k === 'mqtt_praefix') {
        return frw_mqtt_praefix_taugt($w) ? array(true, (string) $w, '')
            : array(false, null, sprintf(frw_t('MQTT.PRAEFIX_UNGUELTIG'), frw_kurz((string) $w, 40)));
    }
    return array(false, null, sprintf(frw_t('SICH.FREMD'), frw_kurz($k, 40)));
}

function frw_mqtt_praefix_taugt($p)
{
    return is_string($p) && preg_match('/^[a-z0-9][a-z0-9_]{0,31}$/', $p) === 1;
}

/** Die eingetragenen Datamanager: Nummer => Adresse (nur gueltige). */
function frw_dm_liste(array $c)
{
    $l = array();
    for ($n = 1; $n <= FRW_DM_MAX; $n++) {
        $a = isset($c['dm' . $n . '_adresse']) ? frw_adresse_pruefen((string) $c['dm' . $n . '_adresse']) : '';
        if ($a !== '') {
            $l[$n] = $a;
        }
    }
    return $l;
}

/* ------------------------------------------------------------------
 * Sichern und Zurueckspielen (Muster WOLF ISM NG 3.0.8, Regeln/05)
 * ------------------------------------------------------------------ */
function frw_sicherung_bauen(array $c)
{
    $kopf = array(
        '_hinweis' => frw_t('SICH.KOPF_HINWEIS'),
        '_hinweis2' => frw_t('SICH.KOPF_HINWEIS2'),
        '_plugin'  => 'froniuswaechter ' . FRW_FASSUNG,
        '_stand'   => date('Y-m-d H:i'),
    );
    $daten = array();
    foreach (frw_vorgaben() as $k => $v) {
        $daten[$k] = array_key_exists($k, $c) ? $c[$k] : $v;
    }
    return $kopf + $daten;
}

/**
 * Rueckgabe array(neu|null, beanstandungen, anzahl). Eine halb gueltige
 * Datei aendert nichts; alle Beanstandungen werden gesammelt; fremde und
 * fehlende Schluessel sind Beanstandungen.
 */
function frw_sicherung_lesen($roh)
{
    $mangel = array();
    $daten = json_decode((string) $roh, true);
    if (!is_array($daten) || ($daten && array_keys($daten) === range(0, count($daten) - 1))) {
        return array(null, array(frw_t('SICH.KEIN_JSON')), 0);
    }
    $neu = frw_vorgaben();
    $bekannt = array_keys($neu);
    $anzahl = 0;
    foreach ($daten as $k => $w) {
        $k = (string) $k;
        if ($k !== '' && $k[0] === '_') {
            continue;
        }
        if (!in_array($k, $bekannt, true)) {
            $mangel[] = sprintf(frw_t('SICH.FREMD'), frw_kurz($k, 40));
            continue;
        }
        list($ok, $wert, $grund) = frw_wert_pruefen($k, $w);
        if (!$ok) {
            $mangel[] = $grund;
            continue;
        }
        $neu[$k] = $wert;
        $anzahl++;
    }
    $fehlend = array();
    foreach ($bekannt as $k) {
        if (!array_key_exists($k, $daten)) {
            $fehlend[] = $k;
        }
    }
    if ($fehlend) {
        $mangel[] = sprintf(frw_t('SICH.FEHLEND'), count($fehlend), implode(', ', $fehlend));
    }
    if ($anzahl === 0 && !$mangel) {
        $mangel[] = frw_t('SICH.LEER');
    }
    return array($mangel ? null : $neu, $mangel, $anzahl);
}

/* ------------------------------------------------------------------
 * Formularmerkmal und Wachposten (Hausvorlage)
 * ------------------------------------------------------------------ */
function frw_merkwort()
{
    static $wort = null;
    if ($wort !== null) {
        return $wort;
    }
    $verz = frw_paths()['datadir'];
    if ($verz === '') {
        return '';
    }
    $datei = $verz . '/formmerkwort';
    if (is_readable($datei)) {
        $roh = trim((string) @file_get_contents($datei));
        if (preg_match('/^[0-9a-f]{32,64}$/', $roh) === 1) {
            $wort = $roh;
            return $wort;
        }
    }
    try {
        $neu = bin2hex(random_bytes(24));
    } catch (Exception $e) {
        $neu = substr(hash('sha256', uniqid((string) mt_rand(), true) . microtime(true)), 0, 48);
    }
    if (!is_dir($verz)) {
        @mkdir($verz, 0775, true);
    }
    $tmp = $datei . '.tmp.' . getmypid();
    $umask_alt = umask(0077);
    $ok = @file_put_contents($tmp, $neu) !== false;
    umask($umask_alt);
    if ($ok) {
        @chmod($tmp, 0600);
        if (!@rename($tmp, $datei)) {
            @unlink($tmp);
        }
    }
    $wort = $neu;
    return $wort;
}

function frw_formtoken()
{
    $grund = frw_merkwort();
    return $grund === '' ? '' : hash_hmac('sha256', 'formular-v1', $grund);
}

function frw_fmt()
{
    return '<input data-role="none" type="hidden" name="fmt" value="'
        . htmlspecialchars(frw_formtoken(), ENT_QUOTES, 'UTF-8') . '">';
}

/** '' = durchgelassen, sonst der Grund. Leeres Soll passt nie. */
function frw_wachposten()
{
    if (!isset($_SERVER['REQUEST_METHOD']) || $_SERVER['REQUEST_METHOD'] !== 'POST') {
        return '';
    }
    $soll = frw_formtoken();
    $ist = (isset($_POST['fmt']) && is_string($_POST['fmt'])) ? $_POST['fmt'] : '';
    if ($ist === '' || $soll === '') {
        return frw_t('WACHE.FEHLT');
    }
    if (!hash_equals($soll, $ist)) {
        frw_log('Ein Formular ohne gueltiges Merkmal wurde abgewiesen.');
        return frw_t('WACHE.FALSCH');
    }
    return '';
}

/* ------------------------------------------------------------------
 * Einmalmeldung nach einem POST (Regeln/04)
 * ------------------------------------------------------------------ */
function frw_einmal_datei()
{
    $p = frw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/einmalmeldung.json' : '';
}

function frw_einmal_schreiben(array $m)
{
    $f = frw_einmal_datei();
    $m['zeit'] = time();
    return $f !== '' && frw_json_schreiben($f, $m);
}

function frw_einmal_lesen()
{
    $f = frw_einmal_datei();
    if ($f === '' || !is_file($f)) {
        return null;
    }
    $d = json_decode((string) @file_get_contents($f), true);
    @unlink($f);
    if (!is_array($d) || !isset($d['zeit']) || abs(time() - (int) $d['zeit']) > 120) {
        return null;
    }
    return $d;
}

/* ------------------------------------------------------------------
 * Abruf der Solar API: HTTP/1.0 GET ueber fsockopen, Zeitgrenze fuer
 * Verbindung UND Lesen, Groessengrenze. Rueckgabe Rumpf oder false;
 * $info: code, ms, grund (verbindung|zeit|http|gross|leer).
 * ------------------------------------------------------------------ */
function frw_abrufen($adresse, $pfad, $timeout, &$info = null)
{
    $info = array('code' => 0, 'ms' => 0, 'grund' => '', 'rumpf' => '');
    $a = frw_adresse_pruefen((string) $adresse);
    if ($a === '') {
        $info['grund'] = 'adresse';
        return false;
    }
    $host = $a;
    $port = 80;
    if (strpos($a, ':') !== false) {
        list($host, $port) = explode(':', $a, 2);
        $port = (int) $port;
    }
    $timeout = max(1, (int) $timeout);
    $beginn = microtime(true);
    /* Eine abgewiesene Verbindung ist ein erwartbarer Ausgang, keine Warnung:
       der Fehlerbehandler wird fuer diesen einen Aufruf ausgetauscht
       (Regeln/03, "Fehlerbehandler austauschen"); der Grund steht in $info. */
    set_error_handler(function () { return true; });
    $fp = fsockopen($host, $port, $eno, $etxt, $timeout);
    restore_error_handler();
    if (!$fp) {
        $info['ms'] = (int) round((microtime(true) - $beginn) * 1000);
        $info['grund'] = ($info['ms'] >= $timeout * 1000 - 50) ? 'zeit' : 'verbindung';
        return false;
    }
    stream_set_timeout($fp, $timeout);
    $anfrage = 'GET ' . $pfad . " HTTP/1.0\r\nHost: " . $host . "\r\nUser-Agent: froniuswaechter/" . FRW_FASSUNG
        . "\r\nAccept: application/json\r\nConnection: close\r\n\r\n";
    @fwrite($fp, $anfrage);
    $daten = '';
    $frist = $beginn + $timeout + 0.5;
    while (!feof($fp)) {
        $stueck = fread($fp, 8192);
        if ($stueck === false) {
            break;
        }
        $daten .= $stueck;
        $meta = stream_get_meta_data($fp);
        if (!empty($meta['timed_out']) || microtime(true) > $frist) {
            fclose($fp);
            $info['ms'] = (int) round((microtime(true) - $beginn) * 1000);
            $info['grund'] = 'zeit';
            return false;
        }
        if (strlen($daten) > FRW_MAX_ANTWORT + 4096) {
            fclose($fp);
            $info['ms'] = (int) round((microtime(true) - $beginn) * 1000);
            $info['grund'] = 'gross';
            return false;
        }
    }
    /* PHP 8.4 meldet nach einer Lesezeitueberschreitung feof() = true und
       verlaesst die Schleife ohne timed_out im Schleifenrumpf (Pruefstand F5a,
       12.11.2026: unter 7.4 "zeit", unter 8.4 "leer"). Deshalb hier noch
       einmal gefragt. */
    $meta = stream_get_meta_data($fp);
    fclose($fp);
    $info['ms'] = (int) round((microtime(true) - $beginn) * 1000);
    if (!empty($meta['timed_out']) || ($daten === '' && $info['ms'] >= $timeout * 1000 - 50)) {
        $info['grund'] = 'zeit';
        return false;
    }
    $teile = explode("\r\n\r\n", $daten, 2);
    if (preg_match('#^HTTP/\d\.\d\s+(\d{3})#', $teile[0], $m) === 1) {
        $info['code'] = (int) $m[1];
    }
    $rumpf = isset($teile[1]) ? $teile[1] : '';
    $info['rumpf'] = substr($rumpf, 0, 200);
    if ($info['code'] !== 200) {
        $info['grund'] = $info['code'] === 0 ? 'leer' : 'http';
        return false;
    }
    if (trim($rumpf) === '') {
        $info['grund'] = 'leer';
        return false;
    }
    return $rumpf;
}

/**
 * Antwort der Solar API zerlegen. Rueckgabe Body.Data (Feld) oder null;
 * $grund: '' | kein_json | status:<Code>. Statuscode 0 ist Erfolg
 * (Solar API V1, Abschnitt 2.5.2 "Common Response Header").
 */
function frw_api_daten($roh, &$grund = null)
{
    $grund = '';
    $d = json_decode((string) $roh, true);
    if (!is_array($d) || !isset($d['Body']) || !is_array($d['Body'])) {
        $grund = 'kein_json';
        return null;
    }
    $code = isset($d['Head']['Status']['Code']) ? $d['Head']['Status']['Code'] : null;
    if ($code !== 0) {
        $grund = 'status:' . (is_scalar($code) ? (string) $code : '?');
        return null;
    }
    return (isset($d['Body']['Data']) && is_array($d['Body']['Data'])) ? $d['Body']['Data'] : array();
}

/** Eine Zahl aus einer fremden Antwort: int/float, nie null, nie Text. */
function frw_zahl($v)
{
    if (is_int($v) || is_float($v)) {
        return is_finite((float) $v) ? $v : null;
    }
    return null;
}

/* ------------------------------------------------------------------
 * Bedeutungen der Codes - NUR aus Fronius-Unterlagen, mit Fundstelle.
 * ------------------------------------------------------------------ */

/** StatusCode: Solar API V1 (42,0410,2012), Abschnitt 4.6.5 "Meaning of
 *  numerical status codes". Unbekannt -> null (es wird nicht geraten). */
function frw_status_schluessel($code)
{
    if (!is_int($code)) {
        return null;
    }
    if ($code >= 0 && $code <= 6) {
        return 'STATUS.ANLAUF';
    }
    $t = array(7 => 'STATUS.BETRIEB', 8 => 'STATUS.STANDBY', 9 => 'STATUS.BOOT', 10 => 'STATUS.FEHLER',
               11 => 'STATUS.LEERLAUF', 12 => 'STATUS.BEREIT', 13 => 'STATUS.SCHLAF', 255 => 'STATUS.UNBEKANNT');
    return isset($t[$code]) ? $t[$code] : null;
}

/** ErrorCode (Statuscode am Geraet): nur die beiden Codes, deren Bedeutung
 *  in BEIDEN Fronius-Unterlagen dieser Anlage steht - Fronius Symo / Eco
 *  Bedienungsanleitung, "Class 3 status codes", und Fronius Energy Package
 *  (Symo Hybrid): 306 "Power low", 307 "DC low"; beide "are displayed
 *  routinely" morgens und abends und "do not indicate any kind of fault".
 *  -1 = kein gueltiger Code, 0 = keiner (Solar API V1, 4.6, ErrorCode). */
function frw_fehler_schluessel($code)
{
    if (!is_int($code)) {
        return null;
    }
    $t = array(-1 => 'FEHLERCODE.UNGUELTIG', 0 => 'FEHLERCODE.KEINER', 306 => 'FEHLERCODE.C306',
               307 => 'FEHLERCODE.C307');
    return isset($t[$code]) ? $t[$code] : null;
}

/** Codes, die laut Unterlage keine Stoerung sind. */
function frw_fehler_regulaer($code)
{
    return in_array($code, array(-1, 0, 306, 307), true);
}

/** Stoerung eines Wechselrichters: StatusCode 10 (Error) oder ein
 *  Fehlercode, der laut Unterlage nicht regulaer ist. */
function frw_stoerung($status, $fehler)
{
    if ($status === 10) {
        return 1;
    }
    if (is_int($fehler) && !frw_fehler_regulaer($fehler)) {
        return 1;
    }
    return 0;
}

/** Status_BatteryCell (Solar Battery, Abschnitt "Modules"): ein Byte 0xYX,
 *  Y = aktueller, X = vorheriger Zustand (Solar API V1, 4.9.7, Table 2).
 *  Gelesen wird der Zahlenwert als Byte (53 -> 0x35 -> aktuell 3). Diese
 *  Lesart passt zur einen Messung vom 12.11.2026 (Speicher laedt, Wert 53);
 *  sie ist am Tag beim Entladen gegenzupruefen (Bericht, Messanleitung). */
function frw_zelle_teile($roh)
{
    if (!is_int($roh) || $roh < 0 || $roh > 255) {
        return null;
    }
    return array(($roh >> 4) & 0x0F, $roh & 0x0F);
}

function frw_zelle_schluessel($nibble)
{
    $t = array(1 => 'ZELLE.Z1', 2 => 'ZELLE.Z2', 3 => 'ZELLE.Z3', 4 => 'ZELLE.Z4', 5 => 'ZELLE.Z5',
               6 => 'ZELLE.Z6', 7 => 'ZELLE.Z7', 9 => 'ZELLE.Z9', 10 => 'ZELLE.Z10', 11 => 'ZELLE.Z11',
               12 => 'ZELLE.Z12', 13 => 'ZELLE.Z13', 14 => 'ZELLE.Z14');
    return (is_int($nibble) && isset($t[$nibble])) ? $t[$nibble] : 'ZELLE.RESERVIERT';
}

function frw_text_oder_code($schluessel, $code)
{
    if ($schluessel === null) {
        return sprintf(frw_t('STATUS.NICHT_HINTERLEGT'), is_scalar($code) ? (string) $code : '-');
    }
    return frw_t($schluessel);
}

/* ------------------------------------------------------------------
 * Auswertung der Antworten (rein rechnend, ohne Netz - selbsttestbar)
 * ------------------------------------------------------------------ */

/** GetActiveDeviceInfo -> array('wr' => [ids], 'sp' => [ids], 'zaehler' => n) */
function frw_geraete_aus($daten)
{
    $g = array('wr' => array(), 'sp' => array(), 'zaehler' => 0);
    if (!is_array($daten)) {
        return $g;
    }
    foreach (array('Inverter' => 'wr', 'Storage' => 'sp') as $quelle => $ziel) {
        if (isset($daten[$quelle]) && is_array($daten[$quelle])) {
            foreach (array_keys($daten[$quelle]) as $id) {
                if (preg_match('/^[0-9]{1,3}$/', (string) $id) === 1) {
                    $g[$ziel][] = (int) $id;
                }
            }
        }
    }
    if (isset($daten['Meter']) && is_array($daten['Meter'])) {
        $g['zaehler'] = count($daten['Meter']);
    }
    sort($g['wr']);
    sort($g['sp']);
    return $g;
}

/** GetInverterInfo -> je Id status, fehler, stoerung, pvpower, dt. Keine
 *  Seriennummer, kein Eigenname (CustomName) - beides wird nie gespeichert. */
function frw_wr_aus($daten, array &$fehlt)
{
    $w = array();
    if (!is_array($daten)) {
        return $w;
    }
    foreach ($daten as $id => $e) {
        if (preg_match('/^[0-9]{1,3}$/', (string) $id) !== 1 || !is_array($e)) {
            continue;
        }
        $x = array();
        foreach (array('StatusCode' => 'status', 'ErrorCode' => 'fehler', 'PVPower' => 'pvpower', 'DT' => 'dt') as $q => $z) {
            if (!array_key_exists($q, $e)) {
                $fehlt[] = 'wr' . $id . '.' . $q;
                continue;
            }
            $v = frw_zahl($e[$q]);
            if ($v !== null) {
                $x[$z] = is_float($v) ? (int) round($v) : $v;
            }
        }
        if (isset($x['status']) || isset($x['fehler'])) {
            $x['stoerung'] = frw_stoerung(isset($x['status']) ? $x['status'] : null,
                                          isset($x['fehler']) ? $x['fehler'] : null);
        }
        $w[(int) $id] = $x;
    }
    ksort($w);
    return $w;
}

/** GetInverterRealtimeData Scope=System -> je Id e_tag, e_jahr, e_gesamt
 *  (Wh, CumulationInverterData, Solar API V1 4.1.5). */
function frw_energie_aus($daten, array &$fehlt)
{
    $e = array();
    if (!is_array($daten)) {
        return $e;
    }
    foreach (array('DAY_ENERGY' => 'e_tag', 'YEAR_ENERGY' => 'e_jahr', 'TOTAL_ENERGY' => 'e_gesamt') as $q => $z) {
        if (!isset($daten[$q]['Values']) || !is_array($daten[$q]['Values'])) {
            $fehlt[] = $q;
            continue;
        }
        foreach ($daten[$q]['Values'] as $id => $v) {
            if (preg_match('/^[0-9]{1,3}$/', (string) $id) !== 1) {
                continue;
            }
            $v = frw_zahl($v);
            if ($v !== null) {
                $e[(int) $id][$z] = (int) round($v);
            }
        }
    }
    ksort($e);
    return $e;
}

/** GetStorageRealtimeData Scope=System -> je Speicher Controller-Werte,
 *  Module und abgeleitete Groessen. Seriennummern werden nicht uebernommen. */
function frw_speicher_aus($daten, array &$fehlt)
{
    $s = array();
    if (!is_array($daten)) {
        return $s;
    }
    foreach ($daten as $id => $sp) {
        if (preg_match('/^[0-9]{1,3}$/', (string) $id) !== 1 || !is_array($sp)) {
            continue;
        }
        $x = array('module' => array());
        $ctl = (isset($sp['Controller']) && is_array($sp['Controller'])) ? $sp['Controller'] : null;
        if ($ctl === null) {
            $fehlt[] = 'sp' . $id . '.Controller';
        } else {
            foreach (array('StateOfCharge_Relative' => 'soc', 'Temperature_Cell' => 'temp',
                           'Voltage_DC' => 'u_dc', 'Current_DC' => 'i_dc') as $q => $z) {
                if (!array_key_exists($q, $ctl)) {
                    $fehlt[] = 'sp' . $id . '.Controller.' . $q;
                    continue;
                }
                $v = frw_zahl($ctl[$q]);
                if ($v !== null) {
                    $x[$z] = $v;
                }
            }
        }
        $mods = (isset($sp['Modules']) && is_array($sp['Modules'])) ? $sp['Modules'] : array();
        $k = 0;
        foreach ($mods as $m) {
            if (!is_array($m)) {
                continue;
            }
            $k++;
            $mx = array();
            foreach (array('StateOfCharge_Relative' => 'soc', 'CycleCount_BatteryCell' => 'zyklen',
                           'Voltage_DC_Minimum_Cell' => 'u_min', 'Voltage_DC_Maximum_Cell' => 'u_max',
                           'Temperature_Cell' => 'temp', 'Temperature_Cell_Maximum' => 'temp_max',
                           'Status_BatteryCell' => 'zustand_roh', 'Enable' => 'aktiv') as $q => $z) {
                if (!array_key_exists($q, $m)) {
                    $fehlt[] = 'sp' . $id . '.modul' . $k . '.' . $q;
                    continue;
                }
                $v = frw_zahl($m[$q]);
                if ($v !== null) {
                    $mx[$z] = $v;
                }
            }
            if (isset($mx['zustand_roh'])) {
                $tz = frw_zelle_teile(is_float($mx['zustand_roh']) ? (int) $mx['zustand_roh'] : $mx['zustand_roh']);
                if ($tz !== null) {
                    $mx['zustand'] = $tz[0];
                    $mx['zustand_vorher'] = $tz[1];
                }
            }
            $x['module'][$k] = $mx;
        }
        $x = $x + frw_speicher_ableiten($x['module']);
        $s[(int) $id] = $x;
    }
    ksort($s);
    return $s;
}

/** Abgeleitete Groessen - als abgeleitet gekennzeichnet (Feldtabelle). */
function frw_speicher_ableiten(array $module)
{
    $a = array('anzahl' => count($module));
    $soc = array();
    $umin = array();
    $umax = array();
    $zyk = array();
    $tmax = array();
    foreach ($module as $m) {
        if (isset($m['soc'])) { $soc[] = $m['soc']; }
        if (isset($m['u_min'])) { $umin[] = $m['u_min']; }
        if (isset($m['u_max'])) { $umax[] = $m['u_max']; }
        if (isset($m['zyklen'])) { $zyk[] = $m['zyklen']; }
        if (isset($m['temp_max'])) { $tmax[] = $m['temp_max']; }
    }
    if ($soc) {
        $a['soc_min'] = min($soc);
        $a['soc_max'] = max($soc);
        $a['soc_streuung'] = max($soc) - min($soc);
    }
    if ($umin && $umax) {
        $a['u_min'] = min($umin);
        $a['u_max'] = max($umax);
        $a['u_diff_mv'] = (int) round((max($umax) - min($umin)) * 1000);
    }
    if ($zyk) {
        $a['zyklen_min'] = min($zyk);
        $a['zyklen_max'] = max($zyk);
        $a['zyklen_abstand'] = max($zyk) - min($zyk);
    }
    if ($tmax) {
        $a['temp_max'] = round(max($tmax), 1);
    }
    return $a;
}

/* ------------------------------------------------------------------
 * Ein Datamanager, eine Anfrage nach der anderen, FRW_PAUSE_S dazwischen.
 * $warte ist austauschbar, damit der Selbsttest nicht schlaeft.
 * ------------------------------------------------------------------ */
function frw_dm_lesen($adresse, array $c, $alt, $jetzt, $holen = null, $warte = null)
{
    if ($holen === null) {
        $holen = 'frw_abrufen';
    }
    if ($warte === null) {
        $warte = function ($s) { sleep($s); };
    }
    $erg = array('zeit' => $jetzt, 'erreichbar' => 0, 'ok' => 0, 'grund' => '', 'ms' => array(),
                 'fehlt' => array(), 'wr' => array(), 'sp' => array(),
                 'geraete' => (is_array($alt) && isset($alt['geraete']) && is_array($alt['geraete'])) ? $alt['geraete'] : null);
    $anfragen = 0;
    $schritt = function ($name, $pfad) use (&$erg, &$anfragen, $adresse, $c, $holen, $warte) {
        if ($anfragen > 0) {
            $warte(FRW_PAUSE_S);
        }
        $anfragen++;
        $info = null;
        $roh = call_user_func_array($holen, array($adresse, $pfad, $c['timeout_s'], &$info));
        $erg['ms'][$name] = is_array($info) && isset($info['ms']) ? (int) $info['ms'] : 0;
        if ($roh === false) {
            $erg['grund'] = $name . ':' . (is_array($info) && isset($info['grund']) ? $info['grund'] : '?');
            return null;
        }
        $erg['erreichbar'] = 1;
        $g = '';
        $d = frw_api_daten($roh, $g);
        if ($d === null) {
            $erg['grund'] = $name . ':' . $g;
        }
        return $d;
    };
    $alter_geraete = (is_array($erg['geraete']) && isset($erg['geraete']['zeit'])) ? $jetzt - (int) $erg['geraete']['zeit'] : PHP_INT_MAX;
    if (!is_array($erg['geraete']) || $alter_geraete >= FRW_GERAETE_NEU || $alter_geraete < 0) {
        $d = $schritt('geraete', '/solar_api/v1/GetActiveDeviceInfo.cgi?DeviceClass=System');
        if ($d === null) {
            $erg['geraete'] = null;
            return $erg;
        }
        $erg['geraete'] = frw_geraete_aus($d) + array('zeit' => $jetzt);
    }
    $fehlt = array();
    $wr = array();
    if (!empty($c['gruppe_wr']) || !empty($c['meldung_stoerung'])) {
        $d = $schritt('info', '/solar_api/v1/GetInverterInfo.cgi');
        if ($d === null) {
            return $erg;
        }
        $wr = frw_wr_aus($d, $fehlt);
    }
    if (!empty($c['gruppe_energie'])) {
        $d = $schritt('energie', '/solar_api/v1/GetInverterRealtimeData.cgi?Scope=System');
        if ($d === null) {
            return $erg;
        }
        foreach (frw_energie_aus($d, $fehlt) as $id => $ew) {
            $wr[$id] = (isset($wr[$id]) ? $wr[$id] : array()) + $ew;
        }
    }
    $speicher_noetig = !empty($c['gruppe_speicher']) || (int) $c['schwelle_soc_streuung'] > 0
        || (int) $c['schwelle_zellen_mv'] > 0;
    if ($speicher_noetig && !empty($erg['geraete']['sp'])) {
        $d = $schritt('speicher', '/solar_api/v1/GetStorageRealtimeData.cgi?Scope=System');
        if ($d === null) {
            return $erg;
        }
        $erg['sp'] = frw_speicher_aus($d, $fehlt);
    }
    ksort($wr);
    $erg['wr'] = $wr;
    $erg['fehlt'] = $fehlt;
    $erg['ok'] = 1;
    $erg['grund'] = '';
    return $erg;
}

/* ------------------------------------------------------------------
 * Stand (data/plugins/<ordner>/stand.json) - ohne Seriennummern, ohne
 * Eigennamen, ohne Token.
 * ------------------------------------------------------------------ */
function frw_stand_datei()
{
    $p = frw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/stand.json' : '';
}

function frw_stand_lesen()
{
    $d = frw_json_lesen(frw_stand_datei());
    return is_array($d) ? $d : array();
}

function frw_jetzt_datei()
{
    $p = frw_paths();
    return $p['datadir'] !== '' ? $p['datadir'] . '/abruf.jetzt' : '';
}

/** Merker "beim naechsten Minutenlauf abrufen" (Endpunkt, mit Token). Der
 *  Endpunkt fragt selbst nie einen Datamanager. */
function frw_jetzt_merken()
{
    $f = frw_jetzt_datei();
    if ($f === '') {
        return false;
    }
    if (!is_dir(dirname($f))) {
        @mkdir(dirname($f), 0775, true);
    }
    return @file_put_contents($f, (string) time()) !== false;
}

/** Sperre fuer JEDEN Abruf eines Datamanagers (Dienst und Messknopf):
 *  nie zwei Anfragen dieses Plugins zugleich. */
function frw_abruf_sperre()
{
    $p = frw_paths();
    if ($p['datadir'] === '') {
        return false;
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $fh = @fopen($p['datadir'] . '/abruf.lock', 'c');
    if ($fh === false) {
        return false;
    }
    if (!flock($fh, LOCK_EX | LOCK_NB)) {
        fclose($fh);
        return false;
    }
    return $fh;
}

function frw_sperre_frei($fh)
{
    if (is_resource($fh)) {
        flock($fh, LOCK_UN);
        fclose($fh);
    }
}

/* ------------------------------------------------------------------
 * Themen und Felder - EINE Liste fuer MQTT, HTTP-Zeile, Namenstabelle,
 * Importvorlage und Selbsttest (Regeln/07).
 * Klasse: zustand (Geraet sagt es -> retained), messwert (nicht retained),
 * dienst (Aussage des Dienstes ueber sich -> nie retained).
 * ------------------------------------------------------------------ */
function frw_feldtabelle()
{
    return array(
        /* schluessel => array(gruppe, klasse, einheit, min, max, http-name, sprachschluessel, abgeleitet) */
        'erreichbar'      => array('dm', 'dienst', '', 0, 1, 'ERREICHBAR', 'THEMA.ERREICHBAR', 0),
        'ausfall_folge'   => array('dm', 'dienst', '', 0, 999999, 'AUSFALL', 'THEMA.AUSFALL', 0),
        'antwort_ms'      => array('dm', 'messwert', 'ms', 0, 60000, 'MS', 'THEMA.ANTWORT_MS', 0),
        'wr.status'       => array('wr', 'zustand', '', -1, 255, 'STATUS', 'THEMA.WR_STATUS', 0),
        'wr.fehler'       => array('wr', 'zustand', '', -1, 65535, 'FEHLER', 'THEMA.WR_FEHLER', 0),
        'wr.stoerung'     => array('wr', 'zustand', '', 0, 1, 'STOERUNG', 'THEMA.WR_STOERUNG', 1),
        'wr.e_tag'        => array('energie', 'messwert', 'Wh', 0, 2147483647, 'E_TAG', 'THEMA.E_TAG', 0),
        'wr.e_jahr'       => array('energie', 'messwert', 'Wh', 0, 2147483647, 'E_JAHR', 'THEMA.E_JAHR', 0),
        'wr.e_gesamt'     => array('energie', 'messwert', 'Wh', 0, 2147483647, 'E_GESAMT', 'THEMA.E_GESAMT', 0),
        'sp.soc'          => array('speicher', 'messwert', '%', 0, 100, 'SOC', 'THEMA.SP_SOC', 0),
        'sp.anzahl'       => array('speicher', 'zustand', '', 0, 64, 'MODULE', 'THEMA.SP_ANZAHL', 0),
        'sp.soc_min'      => array('speicher', 'messwert', '%', 0, 100, 'SOC_MIN', 'THEMA.SP_SOC_MIN', 1),
        'sp.soc_max'      => array('speicher', 'messwert', '%', 0, 100, 'SOC_MAX', 'THEMA.SP_SOC_MAX', 1),
        'sp.soc_streuung' => array('speicher', 'messwert', '%', 0, 100, 'SOC_STREUUNG', 'THEMA.SP_STREUUNG', 1),
        'sp.u_min'        => array('speicher', 'messwert', 'V', 0, 10, 'U_MIN', 'THEMA.SP_U_MIN', 1),
        'sp.u_max'        => array('speicher', 'messwert', 'V', 0, 10, 'U_MAX', 'THEMA.SP_U_MAX', 1),
        'sp.u_diff_mv'    => array('speicher', 'messwert', 'mV', 0, 10000, 'U_DIFF_MV', 'THEMA.SP_U_DIFF', 1),
        'sp.zyklen_min'   => array('speicher', 'messwert', '', 0, 100000, 'ZYK_MIN', 'THEMA.SP_ZYK_MIN', 1),
        'sp.zyklen_max'   => array('speicher', 'messwert', '', 0, 100000, 'ZYK_MAX', 'THEMA.SP_ZYK_MAX', 1),
        'sp.zyklen_abstand' => array('speicher', 'messwert', '', 0, 100000, 'ZYK_ABSTAND', 'THEMA.SP_ZYK_ABSTAND', 1),
        'sp.temp_max'     => array('speicher', 'messwert', '°C', -40, 100, 'TEMP_MAX', 'THEMA.SP_TEMP_MAX', 1),
        'sp.warn_streuung' => array('schwelle_streuung', 'zustand', '', 0, 1, 'WARN_STREUUNG', 'THEMA.SP_WARN_STREUUNG', 1),
        'sp.warn_zellen'  => array('schwelle_zellen', 'zustand', '', 0, 1, 'WARN_ZELLEN', 'THEMA.SP_WARN_ZELLEN', 1),
        'm.soc'           => array('speicher', 'messwert', '%', 0, 100, 'SOC', 'THEMA.M_SOC', 0),
        'm.zyklen'        => array('speicher', 'messwert', '', 0, 100000, 'ZYK', 'THEMA.M_ZYK', 0),
        'm.u_min'         => array('speicher', 'messwert', 'V', 0, 10, 'U_MIN', 'THEMA.M_U_MIN', 0),
        'm.u_max'         => array('speicher', 'messwert', 'V', 0, 10, 'U_MAX', 'THEMA.M_U_MAX', 0),
        'm.temp'          => array('speicher', 'messwert', '°C', -40, 100, 'TEMP', 'THEMA.M_TEMP', 0),
        'm.zustand'       => array('speicher', 'zustand', '', 0, 15, 'ZUSTAND', 'THEMA.M_ZUSTAND', 0),
        'm.zustand_roh'   => array('speicher', 'zustand', '', 0, 255, 'ZUSTAND_ROH', 'THEMA.M_ZUSTAND_ROH', 0),
    );
}

/** Ist eine Gruppe eingeschaltet? */
function frw_gruppe_an($gruppe, array $c)
{
    switch ($gruppe) {
        case 'dm':
            return true;
        case 'wr':
            return !empty($c['gruppe_wr']);
        case 'energie':
            return !empty($c['gruppe_energie']);
        case 'speicher':
            return !empty($c['gruppe_speicher']);
        case 'schwelle_streuung':
            return (int) $c['schwelle_soc_streuung'] > 0;
        case 'schwelle_zellen':
            return (int) $c['schwelle_zellen_mv'] > 0;
    }
    return false;
}

/** Wert als Text fuer Zeile und MQTT: Ganzzahl oder Dezimalpunkt. */
function frw_wert_text($v)
{
    if (is_int($v)) {
        return (string) $v;
    }
    if (is_float($v)) {
        $s = rtrim(rtrim(sprintf('%.3F', $v), '0'), '.');
        return $s === '-0' ? '0' : $s;
    }
    return '';
}

/**
 * Alle Werte eines Datamanagers als Liste:
 * array(array('thema' => 'dm1/wr1/status', 'http' => 'WR1_STATUS', 'feld' => 'wr.status', 'wert' => '7'), ...)
 * Nur Felder eingeschalteter Gruppen, nur vorhandene Werte (null geht nie hinaus).
 * Mit $muster = true: fuer jede Gruppe ein Musterwert '0', auch ohne Messung
 * (fuer Namenstabelle und Vorlage vor dem ersten Abruf).
 */
function frw_werte_dm($n, $dm, array $c, $muster = false)
{
    $tab = frw_feldtabelle();
    $aus = array();
    $setze = function ($feld, $thema, $http, $v, $ort = '') use (&$aus, $tab, $c, $muster) {
        if (!isset($tab[$feld]) || !frw_gruppe_an($tab[$feld][0], $c)) {
            return;
        }
        if ($muster) {
            $v = ($v === null) ? 0 : $v;
        }
        if ($v === null) {
            return;
        }
        $aus[] = array('thema' => $thema, 'http' => $http, 'feld' => $feld, 'wert' => frw_wert_text($v), 'ort' => $ort);
    };
    $p = 'dm' . $n . '/';
    $dm = is_array($dm) ? $dm : array();
    $setze('erreichbar', $p . 'erreichbar', 'ERREICHBAR', isset($dm['erreichbar']) ? (int) $dm['erreichbar'] : null);
    $setze('ausfall_folge', $p . 'ausfall_folge', 'AUSFALL', isset($dm['ausfall_folge']) ? (int) $dm['ausfall_folge'] : null);
    $ms = (isset($dm['ms']) && is_array($dm['ms']) && $dm['ms']) ? max(array_map('intval', $dm['ms'])) : null;
    $setze('antwort_ms', $p . 'antwort_ms', 'MS', $ms);
    $wr = (isset($dm['wr']) && is_array($dm['wr'])) ? $dm['wr'] : array();
    if ($muster && !$wr) {
        $wr = array(1 => array());
    }
    foreach ($wr as $id => $w) {
        foreach (array('status', 'fehler', 'stoerung', 'e_tag', 'e_jahr', 'e_gesamt') as $f) {
            $setze('wr.' . $f, $p . 'wr' . $id . '/' . $f, 'WR' . $id . '_' . $tab['wr.' . $f][5],
                isset($w[$f]) ? $w[$f] : null, 'WR' . $id);
        }
    }
    $sp = (isset($dm['sp']) && is_array($dm['sp'])) ? $dm['sp'] : array();
    if ($muster && !$sp && isset($dm['geraete']['sp']) && $dm['geraete']['sp']) {
        foreach ($dm['geraete']['sp'] as $sid) {
            $sp[$sid] = array('module' => array());
        }
    }
    foreach ($sp as $sid => $s) {
        $sp_p = $p . 'sp' . $sid . '/';
        $sh = 'SP' . $sid . '_';
        foreach (array('soc', 'anzahl', 'soc_min', 'soc_max', 'soc_streuung', 'u_min', 'u_max', 'u_diff_mv',
                       'zyklen_min', 'zyklen_max', 'zyklen_abstand', 'temp_max') as $f) {
            $setze('sp.' . $f, $sp_p . $f, $sh . $tab['sp.' . $f][5], isset($s[$f]) ? $s[$f] : null, 'SP' . $sid);
        }
        if (frw_gruppe_an('schwelle_streuung', $c) && isset($s['soc_streuung'])) {
            $setze('sp.warn_streuung', $sp_p . 'warn_streuung', $sh . 'WARN_STREUUNG',
                $s['soc_streuung'] >= (int) $c['schwelle_soc_streuung'] ? 1 : 0, 'SP' . $sid);
        } elseif ($muster) {
            $setze('sp.warn_streuung', $sp_p . 'warn_streuung', $sh . 'WARN_STREUUNG', 0, 'SP' . $sid);
        }
        if (frw_gruppe_an('schwelle_zellen', $c) && isset($s['u_diff_mv'])) {
            $setze('sp.warn_zellen', $sp_p . 'warn_zellen', $sh . 'WARN_ZELLEN',
                $s['u_diff_mv'] >= (int) $c['schwelle_zellen_mv'] ? 1 : 0, 'SP' . $sid);
        } elseif ($muster) {
            $setze('sp.warn_zellen', $sp_p . 'warn_zellen', $sh . 'WARN_ZELLEN', 0, 'SP' . $sid);
        }
        $mods = (isset($s['module']) && is_array($s['module'])) ? $s['module'] : array();
        foreach ($mods as $k => $m) {
            foreach (array('soc', 'zyklen', 'u_min', 'u_max', 'temp', 'zustand', 'zustand_roh') as $f) {
                $setze('m.' . $f, $sp_p . 'modul' . $k . '/' . $f, $sh . 'M' . $k . '_' . $tab['m.' . $f][5],
                    isset($m[$f]) ? $m[$f] : null, 'SP' . $sid . ' M' . $k);
            }
        }
    }
    return $aus;
}

/** Retain je Feld nach Klasse (Regeln/07, Hausstandard 03./19.09.2026). */
function frw_retain($feld)
{
    $tab = frw_feldtabelle();
    return isset($tab[$feld]) && $tab[$feld][1] === 'zustand';
}

/** Gateway-Name eines Themas (gemessen am Gateway V2, build_vi_name():
 *  '/', ' ', '%' -> '_'). */
function frw_gateway_name($praefix, $thema)
{
    return str_replace(array('/', ' ', '%'), '_', $praefix . '/' . $thema);
}

function frw_mqtt_praefix(array $c)
{
    return (isset($c['mqtt_praefix']) && frw_mqtt_praefix_taugt($c['mqtt_praefix'])) ? $c['mqtt_praefix']
        : FRW_MQTT_PRAEFIX_VORGABE;
}

function frw_mqtt_gateway()
{
    $aus = array('fassung' => 0, 'autostart' => 0, 'udpport' => 11884, 'gefunden' => 0);
    $lb = frw_paths()['lbhome'];
    $g = $lb !== '' ? frw_json_lesen($lb . '/config/system/general.json') : null;
    $m = (is_array($g) && isset($g['Mqtt']) && is_array($g['Mqtt'])) ? $g['Mqtt'] : null;
    if ($m === null) {
        return $aus;
    }
    $aus['gefunden'] = 1;
    $aus['fassung'] = isset($m['Gatewayversion']) && is_numeric($m['Gatewayversion']) ? (int) $m['Gatewayversion'] : 0;
    $aus['autostart'] = (isset($m['Gatewayautostart'])
        && in_array((string) $m['Gatewayautostart'], array('1', 'true'), true)) ? 1 : 0;
    $port = isset($m['Udpinport']) ? (int) $m['Udpinport'] : 0;
    if ($port > 0 && $port <= 65535) {
        $aus['udpport'] = $port;
    }
    return $aus;
}

/**
 * Zeilen an den UDP-Eingang des Gateways: "retain <thema> <wert>" fuer
 * Zustaende, "publish <thema> <wert>" sonst. Beide Befehle kennt das Gateway
 * V2 (gemessen am Geraet 12.11.2026, mqtt_gateway.py, handle_udp_in()) wie
 * V1. 5 ms zwischen zwei Datagrammen (Regeln/07). Gezaehlt wird "gesendet",
 * nicht "angekommen".
 */
function frw_mqtt_senden($praefix, array $zeilen, $port, $sender = null)
{
    if (!$zeilen) {
        return 0;
    }
    if ($sender !== null) {
        return (int) call_user_func($sender, $praefix, $zeilen, $port);
    }
    $fp = @fsockopen('udp://127.0.0.1', (int) $port, $eno, $etxt, 2);
    if (!$fp) {
        return 0;
    }
    $n = 0;
    $i = 0;
    foreach ($zeilen as $z) {
        if (preg_match('/^-?[0-9]+(\.[0-9]+)?$/', $z['wert']) !== 1) {
            continue;
        }
        if ($i++ > 0) {
            usleep(5000);
        }
        $s = ($z['retain'] ? 'retain ' : 'publish ') . $praefix . '/' . $z['thema'] . ' ' . $z['wert'] . "\n";
        if (@fwrite($fp, $s) === strlen($s)) {
            $n++;
        }
    }
    fclose($fp);
    return $n;
}

/* ------------------------------------------------------------------
 * Meldungen (LoxBerry-Benachrichtigung) - ab Werk aus, nur bei Wechsel.
 * ------------------------------------------------------------------ */
function frw_melden($text, $schwere)
{
    $lb = frw_paths()['lbhome'];
    if (!function_exists('notify_ext') && $lb !== '' && is_file($lb . '/libs/phplib/loxberry_log.php')) {
        require_once $lb . '/libs/phplib/loxberry_log.php';
    }
    if (!function_exists('notify_ext')) {
        frw_log('Meldung nicht abgesetzt (notify_ext fehlt): ' . $text);
        return false;
    }
    notify_ext(array('PACKAGE' => frw_paths()['plugin'], 'NAME' => 'Fronius-Waechter',
                     'MESSAGE' => $text, 'SEVERITY' => (int) $schwere));
    frw_log('Meldung abgesetzt: ' . $text);
    return true;
}

/**
 * Welche Meldungen faellig sind - rein rechnend: vergleicht alten und neuen
 * Stand und liefert array(array(text, schwere), ...). Der erste Lauf
 * (kein alter Stand) meldet nichts.
 */
function frw_meldungen_ermitteln($alt, $neu, array $c)
{
    $m = array();
    if (!is_array($alt) || !isset($alt['dm']) || !is_array($alt['dm'])) {
        return $m;
    }
    foreach ($neu['dm'] as $n => $dm) {
        $ad = isset($alt['dm'][$n]) && is_array($alt['dm'][$n]) ? $alt['dm'][$n] : null;
        if ($ad === null) {
            continue;
        }
        if (!empty($c['meldung_ausfall'])) {
            $vorher = isset($ad['ausfall_folge']) ? (int) $ad['ausfall_folge'] : 0;
            $jetzt = isset($dm['ausfall_folge']) ? (int) $dm['ausfall_folge'] : 0;
            if ($vorher < FRW_AUSFALL_MELDEN && $jetzt >= FRW_AUSFALL_MELDEN) {
                $m[] = array(sprintf(frw_t('MELDUNG.AUSFALL'), $n, $jetzt), 3);
            } elseif ($vorher >= FRW_AUSFALL_MELDEN && $jetzt === 0) {
                $m[] = array(sprintf(frw_t('MELDUNG.WIEDER_DA'), $n), 6);
            }
        }
        if (!empty($c['meldung_stoerung']) && !empty($dm['ok'])) {
            foreach ($dm['wr'] as $id => $w) {
                if (!isset($w['stoerung'])) {
                    continue;
                }
                $war = isset($ad['wr'][$id]['stoerung']) ? (int) $ad['wr'][$id]['stoerung'] : 0;
                if ($war === 0 && (int) $w['stoerung'] === 1) {
                    $m[] = array(sprintf(frw_t('MELDUNG.STOERUNG'), $n, $id,
                        isset($w['status']) ? $w['status'] : '-', isset($w['fehler']) ? $w['fehler'] : '-',
                        frw_text_oder_code(frw_fehler_schluessel(isset($w['fehler']) ? $w['fehler'] : null),
                            isset($w['fehler']) ? $w['fehler'] : '-')), 3);
                } elseif ($war === 1 && (int) $w['stoerung'] === 0) {
                    $m[] = array(sprintf(frw_t('MELDUNG.STOERUNG_WEG'), $n, $id), 6);
                }
            }
        }
        if (!empty($c['meldung_speicher']) && !empty($dm['ok'])) {
            foreach ($dm['sp'] as $sid => $s) {
                $as = isset($ad['sp'][$sid]) ? $ad['sp'][$sid] : array();
                if ((int) $c['schwelle_soc_streuung'] > 0 && isset($s['soc_streuung'])) {
                    $g = (int) $c['schwelle_soc_streuung'];
                    $war = isset($as['soc_streuung']) && $as['soc_streuung'] >= $g;
                    if (!$war && $s['soc_streuung'] >= $g) {
                        $m[] = array(sprintf(frw_t('MELDUNG.STREUUNG'), $n, $s['soc_streuung'], $g), 4);
                    }
                }
                if ((int) $c['schwelle_zellen_mv'] > 0 && isset($s['u_diff_mv'])) {
                    $g = (int) $c['schwelle_zellen_mv'];
                    $war = isset($as['u_diff_mv']) && $as['u_diff_mv'] >= $g;
                    if (!$war && $s['u_diff_mv'] >= $g) {
                        $m[] = array(sprintf(frw_t('MELDUNG.ZELLEN'), $n, $s['u_diff_mv'], $g), 4);
                    }
                }
            }
        }
    }
    return $m;
}

/* ------------------------------------------------------------------
 * EIN Lauf des Minutentakts (bin/frw_dienst.php)
 * ------------------------------------------------------------------ */
function frw_takt($holen = null, $warte = null, $sender = null, $jetzt = null)
{
    $p = frw_paths();
    if ($p['datadir'] === '' || $p['lbhome'] === '') {
        return array('ende' => 'ohne_wurzel');
    }
    if (frw_upgrade_laeuft()) {
        return array('ende' => 'upgrade');
    }
    $c = frw_config(false);
    if ($c === null) {
        return array('ende' => 'nicht_eingerichtet');
    }
    if (empty($c['dienst_ein'])) {
        return array('ende' => 'aus');
    }
    $liste = frw_dm_liste($c);
    if (!$liste) {
        return array('ende' => 'ohne_adresse');
    }
    if ($jetzt === null) {
        $jetzt = time();
    }
    if (!is_dir($p['datadir'])) {
        @mkdir($p['datadir'], 0775, true);
    }
    $takt_sperre = @fopen($p['datadir'] . '/takt.lock', 'c');
    if ($takt_sperre === false) {
        return array('ende' => 'sperre_fehlt');
    }
    if (!flock($takt_sperre, LOCK_EX | LOCK_NB)) {
        fclose($takt_sperre);
        return array('ende' => 'besetzt');
    }
    $alt = frw_stand_lesen();
    $vorher = isset($alt['ts']) ? (int) $alt['ts'] : 0;
    $jetzt_datei = frw_jetzt_datei();
    $sofort = $jetzt_datei !== '' && is_file($jetzt_datei);
    /* Faellig 5 s vor Ablauf: der Cron startet nicht auf die Sekunde genau
       (Regeln/03, Schranke unter dem Cron-Takt). */
    if (!$sofort && $vorher > 0 && $jetzt >= $vorher && $jetzt - $vorher < (int) $c['takt_s'] - 5) {
        flock($takt_sperre, LOCK_UN);
        fclose($takt_sperre);
        return array('ende' => 'wartet');
    }
    $abruf = frw_abruf_sperre();
    if ($abruf === false) {
        flock($takt_sperre, LOCK_UN);
        fclose($takt_sperre);
        return array('ende' => 'abruf_besetzt');
    }
    if ($sofort) {
        @unlink($jetzt_datei);
    }
    $neu = array('ts' => $jetzt, 'zaehler' => isset($alt['zaehler']) ? ((int) $alt['zaehler'] + 1) % 1000 : 0,
                 'fassung' => FRW_FASSUNG, 'takt_s' => (int) $c['takt_s'], 'dm' => array());
    $alle_ok = true;
    foreach ($liste as $n => $adr) {
        $ad = (isset($alt['dm'][$n]) && is_array($alt['dm'][$n])) ? $alt['dm'][$n] : null;
        /* Adresse geaendert: die zwischengespeicherte Geraeteliste gilt nicht. */
        if ($ad !== null && (!isset($ad['adresse_hash']) || $ad['adresse_hash'] !== md5($adr))) {
            $ad = null;
        }
        $e = frw_dm_lesen($adr, $c, $ad, $jetzt, $holen, $warte);
        $e['adresse_hash'] = md5($adr);
        $e['ausfall_folge'] = $e['ok'] ? 0 : (($ad !== null && isset($ad['ausfall_folge'])) ? (int) $ad['ausfall_folge'] + 1 : 1);
        if (!$e['ok'] && $ad !== null) {
            /* Bei Ausfall bleiben die letzten Werte im Stand, gekennzeichnet
               durch ok=0 und ihre Zeit - gesendet wird nur das Signal. */
            foreach (array('wr', 'sp') as $k) {
                if (isset($ad[$k]) && is_array($ad[$k])) {
                    $e[$k] = $ad[$k];
                }
            }
            $e['werte_zeit'] = isset($ad['werte_zeit']) ? (int) $ad['werte_zeit'] : 0;
        } else {
            $e['werte_zeit'] = $e['ok'] ? $jetzt : 0;
        }
        if (!$e['ok']) {
            $alle_ok = false;
        }
        $neu['dm'][$n] = $e;
    }
    frw_sperre_frei($abruf);
    $neu['ok'] = $alle_ok ? 1 : 0;

    /* MQTT: Lebenszeichen jeden Lauf; Werte bei Aenderung, alle
       FRW_VOLLVERSAND s alle. Bei Ausfall eines Datamanagers nur dessen
       Signal (erreichbar, ausfall_folge), nicht die alten Werte. */
    $gesendet = 0;
    $zeilen = array();
    $letzte = (isset($alt['letzte']) && is_array($alt['letzte'])) ? $alt['letzte'] : array();
    $voll_ts = isset($alt['voll_ts']) ? (int) $alt['voll_ts'] : 0;
    $praefix = frw_mqtt_praefix($c);
    $voll = $voll_ts <= 0 || $jetzt - $voll_ts >= FRW_VOLLVERSAND || $jetzt < $voll_ts
        || !isset($alt['praefix']) || $alt['praefix'] !== $praefix;
    if (!empty($c['mqtt_ein'])) {
        $zeilen[] = array('thema' => 'status/ok', 'wert' => (string) $neu['ok'], 'retain' => false);
        $zeilen[] = array('thema' => 'status/ts', 'wert' => (string) $jetzt, 'retain' => false);
        $zeilen[] = array('thema' => 'status/zaehler', 'wert' => (string) $neu['zaehler'], 'retain' => false);
        foreach ($neu['dm'] as $n => $dm) {
            $nur_signal = empty($dm['ok']);
            foreach (frw_werte_dm($n, $dm, $c) as $w) {
                if ($nur_signal && !in_array($w['feld'], array('erreichbar', 'ausfall_folge'), true)) {
                    continue;
                }
                $immer = in_array($w['feld'], array('erreichbar', 'ausfall_folge', 'antwort_ms'), true);
                if ($voll || $immer || !array_key_exists($w['thema'], $letzte) || (string) $letzte[$w['thema']] !== $w['wert']) {
                    $zeilen[] = array('thema' => $w['thema'], 'wert' => $w['wert'], 'retain' => frw_retain($w['feld']));
                }
            }
        }
        $gw = frw_mqtt_gateway();
        $gesendet = frw_mqtt_senden($praefix, $zeilen, $gw['udpport'], $sender);
        foreach ($zeilen as $z) {
            if (strpos($z['thema'], 'status/') !== 0) {
                $letzte[$z['thema']] = $z['wert'];
            }
        }
        if ($gesendet < count($zeilen)) {
            frw_log('MQTT: ' . $gesendet . ' von ' . count($zeilen) . ' Zeilen an den UDP-Eingang '
                . (int) $gw['udpport'] . ' gegeben.');
        }
    }
    $neu['letzte'] = $letzte;
    $neu['voll_ts'] = (!empty($c['mqtt_ein']) && $voll) ? $jetzt : $voll_ts;
    $neu['praefix'] = $praefix;
    $neu['mqtt_zeilen'] = count($zeilen);
    $neu['mqtt_gesendet'] = $gesendet;

    $meld = frw_meldungen_ermitteln($alt, $neu, $c);
    if ($sender === null) {
        foreach ($meld as $mm) {
            frw_melden($mm[0], $mm[1]);
        }
    }
    $neu['meldungen'] = count($meld);
    frw_json_schreiben(frw_stand_datei(), $neu);
    foreach ($neu['dm'] as $n => $dm) {
        $vorher_folge = isset($alt['dm'][$n]['ausfall_folge']) ? (int) $alt['dm'][$n]['ausfall_folge'] : 0;
        if (!$dm['ok'] && ($dm['ausfall_folge'] === 1 || $dm['ausfall_folge'] % 12 === 0)) {
            frw_log('Datamanager ' . $n . ': kein vollstaendiger Abruf (' . $dm['grund'] . '), '
                . $dm['ausfall_folge'] . ' Lauf/Laeufe in Folge.');
        } elseif ($dm['ok'] && $vorher_folge > 0) {
            frw_log('Datamanager ' . $n . ': antwortet wieder nach ' . $vorher_folge . ' Lauf/Laeufen.');
        }
    }
    flock($takt_sperre, LOCK_UN);
    fclose($takt_sperre);
    return array('ende' => 'gelaufen', 'stand' => $neu, 'zeilen' => $zeilen, 'meldungen' => $meld);
}

/* ------------------------------------------------------------------
 * HTTP-Zeile, Suchmuster, Importvorlage
 * ------------------------------------------------------------------ */
function frw_check($feld)
{
    return '\i;' . $feld . '=\i\v';
}

/** Alter der Werte eines Datamanagers in Sekunden, -1 = keine. */
function frw_alter($dm, $jetzt = null)
{
    if ($jetzt === null) {
        $jetzt = time();
    }
    if (!is_array($dm) || empty($dm['werte_zeit'])) {
        return -1;
    }
    $a = $jetzt - (int) $dm['werte_zeit'];
    return $a < 0 ? 0 : $a;
}

/**
 * Antwortzeile fuer Datamanager $n aus dem Stand:
 * FRW<n>;OK=..;ALTER=..;ZAEHLER=..;<Felder>
 * Rueckgabe array(http-code, zeile). Vor dem ersten Abruf 503 mit GRUND
 * (Regeln/07); OK=1 nur, wenn der letzte Lauf diesen Datamanager
 * vollstaendig gelesen hat und nicht aelter ist als drei Takte.
 */
function frw_zeile($n, array $stand, array $c, $jetzt = null)
{
    if ($jetzt === null) {
        $jetzt = time();
    }
    $n = (int) $n;
    $kopf = 'FRW' . $n;
    if ($n < 1 || $n > FRW_DM_MAX) {
        return array(400, $kopf . ';OK=0;GRUND=DM_UNBEKANNT');
    }
    if (!isset($stand['dm'][$n]) || !is_array($stand['dm'][$n])) {
        return array(503, $kopf . ';OK=0;GRUND=' . (empty($c['dienst_ein']) ? 'DIENST_AUS' : 'NOCH_KEIN_ABRUF'));
    }
    $dm = $stand['dm'][$n];
    $alter = frw_alter($dm, $jetzt);
    $grenze = 3 * max(FRW_TAKT_MIN, (int) $c['takt_s']);
    $ok = !empty($dm['ok']) && $alter >= 0 && $alter <= $grenze;
    $teile = array($kopf, 'OK=' . ($ok ? 1 : 0), 'ALTER=' . $alter,
                   'ZAEHLER=' . (isset($stand['zaehler']) ? (int) $stand['zaehler'] : -1));
    foreach (frw_werte_dm($n, $dm, $c) as $w) {
        $teile[] = $w['http'] . '=' . $w['wert'];
    }
    return array(200, implode(';', $teile));
}

/** Rechnername fuer Adressen: wie der Browser den LoxBerry erreicht. */
function frw_rechner()
{
    $h = (isset($_SERVER['HTTP_HOST']) && is_string($_SERVER['HTTP_HOST'])) ? $_SERVER['HTTP_HOST'] : '';
    return preg_match('/^[A-Za-z0-9.\-]{1,253}(:[0-9]{1,5})?$/', $h) === 1 ? $h : 'loxberry';
}

function frw_endpunkt_adresse($host, $n, $roh = false)
{
    return 'http://' . $host . '/plugins/' . frw_paths()['plugin'] . '/index.php?aktion=status&dm=' . (int) $n;
}

/** Einheit fuer die Vorlage: "<v> Wh" bzw. mit Nachkommastellen. */
function frw_einheit($feld)
{
    $tab = frw_feldtabelle();
    $e = isset($tab[$feld]) ? $tab[$feld][2] : '';
    $nk = in_array($feld, array('sp.u_min', 'sp.u_max', 'm.u_min', 'm.u_max'), true) ? '<v.3>'
        : (in_array($feld, array('sp.temp_max', 'm.temp'), true) ? '<v.1>' : '<v>');
    return trim($nk . ' ' . $e);
}

/**
 * Importvorlage "Virtueller HTTP-Eingang" fuer Datamanager $n. Bauform nach
 * XML_Vorlagen_0.9.10 (CRLF, Tabulator, Info templateType 2, Unit und
 * HintText je Befehl). Titel fest, Kommentare aus der Sprachdatei
 * (Regeln/07). Menge: aus der letzten Messung; vor der ersten Messung jedes
 * eingeschaltete Feld mit Hinweis im Wurzelkommentar.
 * Rueckgabe array(dateiname, xml).
 */
function frw_vorlage($n, $host, array $c, $stand = null)
{
    if (!is_array($stand)) {
        $stand = frw_stand_lesen();
    }
    $tab = frw_feldtabelle();
    $dm = (isset($stand['dm'][$n]) && is_array($stand['dm'][$n])) ? $stand['dm'][$n] : null;
    $gemessen = is_array($dm) && !empty($dm['werte_zeit']);
    $werte = frw_werte_dm($n, $dm === null ? array() : $dm, $c, !$gemessen);
    $kommentar = sprintf(frw_t('VORLAGE.KOPF'), $n)
        . ($gemessen ? '' : ' ' . frw_t('VORLAGE.KOPF_UNGEMESSEN'));
    $o = '<?xml version="1.0" encoding="utf-8"?>' . "\r\n";
    $o .= '<VirtualInHttp HintText="" Title="' . frw_xml('Fronius-Wächter DM' . $n) . '" Comment="'
        . frw_xml($kommentar) . '" Address="' . frw_xml(frw_endpunkt_adresse($host, $n)) . '" PollingTime="'
        . (int) max(60, (int) $c['takt_s']) . '">' . "\r\n";
    $o .= "\t" . '<Info templateType="2" minVersion="17010727"/>' . "\r\n";
    $befehle = array(
        array('OK', 'VORLAGE.OK', 0, 1, '<v>', ''),
        array('ALTER', 'VORLAGE.ALTER', -1, 2147483647, '<v> s', ''),
        array('ZAEHLER', 'VORLAGE.ZAEHLER', -1, 999, '<v>', ''),
    );
    foreach ($werte as $w) {
        $f = $tab[$w['feld']];
        $befehle[] = array($w['http'], $f[6], $f[3], $f[4], frw_einheit($w['feld']), $w['ort']);
    }
    foreach ($befehle as $b) {
        /* Der Kommentar wird in Config zum Anzeigenamen (Regeln/07): kurz, mit
           Vorsatz FRW<n> und Ort (WR1, SP1 M3), damit zwei Datamanager und
           acht Module unterscheidbar bleiben. */
        $o .= "\t" . '<VirtualInHttpCmd Title="' . frw_xml('FRW' . $n . ' ' . $b[0]) . '" Comment="'
            . frw_xml('FRW' . $n . ($b[5] !== '' ? ' ' . $b[5] : '') . ': ' . frw_t($b[1])) . '" Check="' . frw_xml(frw_check($b[0])) . '" Signed="'
            . ($b[2] < 0 ? 'true' : 'false') . '" Analog="true" SourceValLow="0" DestValLow="0" SourceValHigh="1"'
            . ' DestValHigh="1" DefVal="0" MinVal="' . (int) $b[2] . '" MaxVal="' . (int) $b[3] . '" Unit="'
            . frw_xml($b[4]) . '" HintText=""/>' . "\r\n";
    }
    $o .= '</VirtualInHttp>' . "\r\n";
    return array('VI_Fronius_Waechter_DM' . $n . '.xml', $o);
}

/* ------------------------------------------------------------------
 * Verzahnung mit der Einspeisebremse - NUR lesend, ab Werk aus.
 * Gelesen werden genau die Schluessel ein und takt der Konfiguration und
 * zeit/zaehler/ein ihres Stands; kein Zugangsdatum, kein Token.
 * ------------------------------------------------------------------ */
function frw_eb_zustand($jetzt = null)
{
    if ($jetzt === null) {
        $jetzt = time();
    }
    $lb = frw_paths()['lbhome'];
    $aus = array('da' => 0, 'ein' => null, 'takt' => null, 'alter' => null);
    if ($lb === '') {
        return $aus;
    }
    $cf = frw_json_lesen($lb . '/config/plugins/einspeisebremse/einspeisebremse.json');
    $st = frw_json_lesen($lb . '/data/plugins/einspeisebremse/stand.json');
    if ($cf === null && $st === null) {
        return $aus;
    }
    $aus['da'] = 1;
    if (is_array($cf)) {
        $aus['ein'] = isset($cf['ein']) && is_int($cf['ein']) ? $cf['ein'] : null;
        $aus['takt'] = isset($cf['takt']) && is_numeric($cf['takt']) ? (int) $cf['takt'] : null;
    }
    if (is_array($st) && isset($st['zeit']) && is_int($st['zeit'])) {
        $aus['alter'] = max(0, $jetzt - $st['zeit']);
    }
    return $aus;
}

/* ------------------------------------------------------------------
 * Pruefzeilen des Reiters Test. Jede liefert array(lage, text);
 * lage ok | fehl | hinweis ("nicht feststellbar" zaehlt nie als bestanden).
 * ------------------------------------------------------------------ */

/** Positivliste, Leiste und Bereiche in der eigenen Datei gegeneinander. */
function frw_pruef_reiter($datei)
{
    $s = is_file($datei) ? (string) @file_get_contents($datei) : '';
    if ($s === '') {
        return array('hinweis', frw_t('TEST.DATEI_FEHLT'));
    }
    $liste = array();
    if (preg_match('/\$frw_reiter = array\(([^)]*)\);/', $s, $m) === 1) {
        preg_match_all("/'(tab-[a-z]+)'/", $m[1], $x);
        $liste = $x[1];
    }
    preg_match_all('/data-ziel="(tab-[a-z]+)"/', $s, $y);
    /* [^\n]*? statt [^>]*: zwischen den beiden Namen steht ein ?> (Regeln/04,
       Suchmuster und PHP-Endmarke). */
    preg_match_all('/class="sm-seite<\?= \$frw_tab === \'(tab-[a-z]+)\'[^\n]*?id="(tab-[a-z]+)"/', $s, $z);
    $bereiche = $z[2];
    if (!$liste || !$y[1] || !$bereiche) {
        return array('fehl', sprintf(frw_t('TEST.REITER_LEER'), count($liste), count($y[1]), count($bereiche)));
    }
    $gleich = $liste === $y[1] && $liste === $bereiche && $z[1] === $z[2];
    return array($gleich ? 'ok' : 'fehl', sprintf(frw_t($gleich ? 'TEST.REITER_OK' : 'TEST.REITER_FALSCH'),
        count($liste), implode(', ', $liste), implode(', ', $y[1]), implode(', ', $bereiche)));
}

/** Setzt der Server sm-active an Leiste UND Bereichen? */
function frw_pruef_smactive($datei)
{
    $s = is_file($datei) ? (string) @file_get_contents($datei) : '';
    preg_match_all('/data-ziel="tab-([a-z]+)"/', $s, $y);
    $anzahl = count($y[1]);
    $leiste = preg_match_all('/class="sm-tab<\?=[^>]*sm-active/', $s);
    $bereiche = preg_match_all('/class="sm-seite<\?=[^>]*sm-active/', $s);
    if ($anzahl > 0 && $leiste >= $anzahl && $bereiche >= $anzahl) {
        return array('ok', sprintf(frw_t('TEST.SMACTIVE_OK'), $anzahl));
    }
    return array('fehl', sprintf(frw_t('TEST.SMACTIVE_FEHL'), $leiste, $bereiche, $anzahl));
}

/** Tragen alle Formulare das Merkmal? */
function frw_pruef_formulare($datei)
{
    $s = is_file($datei) ? (string) @file_get_contents($datei) : '';
    $gesamt = 0;
    $ohne = 0;
    if (preg_match_all('/<form\s/', $s, $y, PREG_OFFSET_CAPTURE)) {
        foreach ($y[0] as $f) {
            $gesamt++;
            $ende = strpos($s, '</form>', $f[1]);
            $blk = substr($s, $f[1], ($ende === false ? 400 : $ende - $f[1]));
            if (strpos($blk, 'frw_fmt()') === false) {
                $ohne++;
            }
        }
    }
    if ($gesamt === 0) {
        return array('fehl', frw_t('TEST.FORM_LEER'));
    }
    if ($ohne > 0) {
        return array('fehl', sprintf(frw_t('TEST.FORM_FEHL'), $ohne, $gesamt));
    }
    return array('ok', sprintf(frw_t('TEST.FORM_OK'), $gesamt));
}

/** Ist jedes Suchmuster einer Zeile eindeutig? Gemessen an einer Zeile mit
 *  allen Feldern (Musterstand, zwei Speicher, zwei Module). */
function frw_pruef_suchmuster()
{
    $c = frw_vorgaben();
    $c['gruppe_speicher'] = 1;
    $c['schwelle_soc_streuung'] = 10;
    $c['schwelle_zellen_mv'] = 50;
    $dm = array('erreichbar' => 1, 'ausfall_folge' => 0, 'ms' => array('info' => 1), 'ok' => 1, 'werte_zeit' => 1,
                'wr' => array(1 => array('status' => 7, 'fehler' => 0, 'stoerung' => 0, 'e_tag' => 1, 'e_jahr' => 2, 'e_gesamt' => 3),
                              2 => array('status' => 7, 'fehler' => 0, 'stoerung' => 0, 'e_tag' => 1, 'e_jahr' => 2, 'e_gesamt' => 3)),
                'sp' => array(1 => array('soc' => 1, 'anzahl' => 2, 'soc_min' => 1, 'soc_max' => 2, 'soc_streuung' => 1,
                    'u_min' => 3.1, 'u_max' => 3.2, 'u_diff_mv' => 100, 'zyklen_min' => 1, 'zyklen_max' => 2,
                    'zyklen_abstand' => 1, 'temp_max' => 20.0,
                    'module' => array(1 => array('soc' => 1, 'zyklen' => 1, 'u_min' => 3.1, 'u_max' => 3.2, 'temp' => 20.0, 'zustand' => 3, 'zustand_roh' => 53),
                                      11 => array('soc' => 1, 'zyklen' => 1, 'u_min' => 3.1, 'u_max' => 3.2, 'temp' => 20.0, 'zustand' => 3, 'zustand_roh' => 53)))));
    list(, $zeile) = frw_zeile(1, array('dm' => array(1 => $dm), 'zaehler' => 1), $c, 1);
    $namen = array('OK', 'ALTER', 'ZAEHLER');
    foreach (frw_werte_dm(1, $dm, $c) as $w) {
        $namen[] = $w['http'];
    }
    $doppelt = array();
    foreach ($namen as $nm) {
        $such = ';' . $nm . '=';
        $erst = strpos($zeile, $such);
        $soll = strpos($zeile, ';' . $nm . '=');
        if ($erst === false || substr_count($zeile, $such) !== 1 || $erst !== $soll) {
            $doppelt[] = $nm;
        }
    }
    if (count($namen) < 10) {
        return array('fehl', frw_t('TEST.SUCH_LEER'));
    }
    return $doppelt ? array('fehl', sprintf(frw_t('TEST.SUCH_FEHL'), implode(', ', $doppelt)))
        : array('ok', sprintf(frw_t('TEST.SUCH_OK'), count($namen)));
}

/** Sind die Vorlagen wohlgeformt (simplexml), CRLF und mit Tabulator? */
function frw_pruef_vorlagen(array $c, array $stand)
{
    if (!function_exists('simplexml_load_string')) {
        return array('hinweis', frw_t('TEST.XML_OHNE'));
    }
    $liste = frw_dm_liste($c);
    if (!$liste) {
        $liste = array(1 => '');
    }
    $n_ok = 0;
    $befehle = 0;
    foreach (array_keys($liste) as $n) {
        list(, $xml) = frw_vorlage($n, 'loxberry', $c, $stand);
        $alt = libxml_use_internal_errors(true);
        $x = simplexml_load_string($xml);
        libxml_use_internal_errors($alt);
        $crlf = substr_count($xml, "\r\n") === substr_count($xml, "\n");
        if ($x === false || !$crlf || strpos($xml, "\n\t<VirtualInHttpCmd") === false) {
            return array('fehl', sprintf(frw_t('TEST.XML_FEHL'), $n));
        }
        $befehle += count($x->VirtualInHttpCmd);
        $n_ok++;
    }
    return array('ok', sprintf(frw_t('TEST.XML_OK'), $n_ok, $befehle));
}

/** Antwortet der eigene Endpunkt? Echter Aufruf ueber 127.0.0.1. */
function frw_pruef_endpunkt($token)
{
    $p = frw_paths();
    if ($p['lbhome'] === '') {
        return array('hinweis', frw_t('TEST.EP_OHNE'));
    }
    $port = 80;
    $g = frw_json_lesen($p['lbhome'] . '/config/system/general.json');
    if (is_array($g) && isset($g['Webserver']['Port']) && (int) $g['Webserver']['Port'] > 0) {
        $port = (int) $g['Webserver']['Port'];
    }
    $pfad = '/plugins/' . rawurlencode($p['plugin']) . '/index.php?selftest=1'
        . ((is_string($token) && $token !== '') ? '&token=' . rawurlencode($token) : '');
    $info = null;
    $r = frw_abrufen('127.0.0.1:' . $port, $pfad, 3, $info);
    if ($r !== false) {
        return strpos($r, 'SELFTEST;OK=1') === 0 ? array('ok', frw_t('TEST.EP_OK'))
            : array('fehl', sprintf(frw_t('TEST.EP_FALSCH'), 200, frw_kurz($r, 60)));
    }
    if ($info['code'] > 0) {
        return array('fehl', sprintf(frw_t('TEST.EP_FALSCH'), $info['code'], frw_kurz($info['rumpf'], 60)));
    }
    return array('hinweis', sprintf(frw_t('TEST.EP_KEINE'), $info['grund']));
}

/** Steht der Cron-Eintrag als Datei (an allen Cron-Orten gesucht)? */
function frw_pruef_cron()
{
    $p = frw_paths();
    if ($p['lbhome'] === '') {
        return array('hinweis', frw_t('TEST.CRON_OHNE'));
    }
    $orte = array('cron.01min', 'cron.03min', 'cron.05min', 'cron.10min', 'cron.15min', 'cron.30min', 'cron.hourly');
    $gefunden = array();
    foreach ($orte as $o) {
        $f = $p['lbhome'] . '/system/cron/' . $o . '/' . $p['plugin'];
        if (is_file($f)) {
            $gefunden[] = $o;
        } elseif (is_dir($f)) {
            return array('fehl', sprintf(frw_t('TEST.CRON_VERZ'), $o));
        }
    }
    if ($gefunden === array('cron.01min')) {
        return array('ok', frw_t('TEST.CRON_OK'));
    }
    return array('fehl', sprintf(frw_t('TEST.CRON_FEHL'), $gefunden ? implode(', ', $gefunden) : '-'));
}

/** Konfiguration vollstaendig? Zwei Zahlen, im Fehlerfall die Namen. */
function frw_pruef_konfiguration()
{
    $p = frw_paths();
    $d = frw_json_lesen($p['cfgdatei']);
    $soll = array_keys(frw_vorgaben());
    if (!is_array($d)) {
        return array('fehl', frw_t('TEST.KONF_FEHLT'));
    }
    $fehlt = array();
    foreach ($soll as $k) {
        if (!array_key_exists($k, $d)) {
            $fehlt[] = $k;
        }
    }
    return $fehlt ? array('fehl', sprintf(frw_t('TEST.KONF_TEIL'), count($soll) - count($fehlt), count($soll), implode(', ', $fehlt)))
        : array('ok', sprintf(frw_t('TEST.KONF_OK'), count($soll), count($soll)));
}

/** Zustand der Konfiguration beim ersten Lesen (vor der Heilung). */
function frw_pruef_heil()
{
    $z = frw_config_zustand();
    $map = array('ok' => 'ok', 'fehlt' => 'hinweis', 'leer' => 'hinweis', 'ohne_token' => 'fehl',
                 'zweitschrift' => 'fehl', 'kaputt_zweitschrift' => 'fehl', 'kaputt' => 'fehl');
    $lage = ($z !== null && isset($map[$z])) ? $map[$z] : 'hinweis';
    return array($lage, frw_t('TEST.HEIL_' . strtoupper($z === null ? 'unbekannt' : $z)));
}

/** Eine Messung der Antwortzeit je Datamanager (Messknopf im Reiter Test):
 *  dieselbe Sperre wie der Dienst, eine Anfrage je Datamanager
 *  (GetInverterInfo), Abstand FRW_PAUSE_S zwischen zwei Datamanagern ist
 *  nicht noetig, wird aber nicht unterschritten, wenn zweimal derselbe
 *  eingetragen ist. */
function frw_antwortzeit_messen(array $c)
{
    $sperre = frw_abruf_sperre();
    if ($sperre === false) {
        return array('besetzt' => 1, 'dm' => array());
    }
    $aus = array('besetzt' => 0, 'dm' => array(), 'zeit' => time());
    $gesehen = array();
    foreach (frw_dm_liste($c) as $n => $adr) {
        if (isset($gesehen[$adr])) {
            sleep(FRW_PAUSE_S);
        }
        $gesehen[$adr] = 1;
        $info = null;
        $roh = frw_abrufen($adr, '/solar_api/v1/GetInverterInfo.cgi', $c['timeout_s'], $info);
        $g = '';
        $ok = $roh !== false && frw_api_daten($roh, $g) !== null;
        $aus['dm'][$n] = array('ok' => $ok ? 1 : 0, 'ms' => (int) $info['ms'], 'code' => (int) $info['code'],
                               'grund' => $ok ? '' : ($roh === false ? $info['grund'] : $g));
    }
    frw_sperre_frei($sperre);
    return $aus;
}

/* ------------------------------------------------------------------
 * Rechenkern-Selbsttest mit nachgebildeten Antworten. Aufbau, Schluessel
 * und Codes folgen den Antworten zweier Datamanager vom 12.11.2026; alle
 * Messwerte (Energien, Leistungen, Spannungen, Stroeme, Temperaturen,
 * Ladestaende, Kapazitaeten, Zyklen, Zeitstempel) sind seit 0.9.2 erfundene,
 * runde Werte. Seriennummern, Kennungen und Eigennamen stehen als x.
 * ------------------------------------------------------------------ */
function frw_probe_antworten()
{
    $kopf = '"Head":{"RequestArguments":{},"Status":{"Code":0,"Reason":"","UserMessage":""},"Timestamp":"2026-01-01T12:00:00+01:00"}';
    $modul = function ($soc, $zyk, $umin, $umax, $t) {
        return '{"Capacity_Maximum":1000,"Current_DC":1.0,"CycleCount_BatteryCell":' . $zyk . ',"DesignedCapacity":1000,'
            . '"Details":{"Manufacturer":"Sony","Model":"unknown","Serial":"xxxxxxxxxx"},"Enable":1,"StateOfCharge_Relative":' . $soc . ','
            . '"Status_BatteryCell":53,"Temperature_Cell":' . $t . ',"Temperature_Cell_Maximum":' . ($t + 1) . ','
            . '"Temperature_Cell_Minimum":' . ($t - 1) . ',"TimeStamp":1767265200,"Voltage_DC":50.0,'
            . '"Voltage_DC_Maximum_Cell":' . $umax . ',"Voltage_DC_Minimum_Cell":' . $umin . '}';
    };
    $mods = array($modul(20, 1900, 3.25, 3.3, 20), $modul(10, 1900, 3.2, 3.3, 20), $modul(20, 1900, 3.25, 3.3, 20),
                  $modul(30, 1900, 3.25, 3.3, 20), $modul(20, 2000, 3.25, 3.3, 20), $modul(20, 1900, 3.25, 3.3, 20),
                  $modul(30, 1600, 3.25, 3.3, 20), $modul(40, 1500, 3.25, 3.35, 20));
    return array(
        'hybrid' => array(
            'GetActiveDeviceInfo' => '{"Body":{"Data":{"Inverter":{"1":{"DT":99}},"Meter":{"0":{"DT":-1,"Serial":"xxxxxxxx"}},"Ohmpilot":{},"SensorCard":{},"Storage":{"1":{"DT":-1,"Serial":"unknown"}},"StringControl":{}}},' . $kopf . '}',
            'GetInverterInfo' => '{"Body":{"Data":{"1":{"CustomName":"xxxx","DT":99,"ErrorCode":0,"PVPower":4000,"Show":1,"StatusCode":7,"UniqueID":"xxxxxx"}}},' . $kopf . '}',
            'GetInverterRealtimeData' => '{"Body":{"Data":{"DAY_ENERGY":{"Unit":"Wh","Values":{"1":1000}},"PAC":{"Unit":"W","Values":{"1":0}},"TOTAL_ENERGY":{"Unit":"Wh","Values":{"1":50000000}},"YEAR_ENERGY":{"Unit":"Wh","Values":{"1":6000000}}}},' . $kopf . '}',
            'GetStorageRealtimeData' => '{"Body":{"Data":{"1":{"Controller":{"Capacity_Maximum":8000,"Current_DC":1.0,"DesignedCapacity":8000,"Details":{"Manufacturer":"Fronius","Model":"Fronius Solar Battery","Serial":"unknown"},"Enable":1,"StateOfCharge_Relative":20,"Temperature_Cell":20.0,"TimeStamp":1767265200,"Voltage_DC":400.0,"Voltage_DC_Maximum_Cell":3.35,"Voltage_DC_Minimum_Cell":3.2},"Modules":[' . implode(',', $mods) . ']}}},' . $kopf . '}',
        ),
        'symo' => array(
            'GetActiveDeviceInfo' => '{"Body":{"Data":{"Inverter":{"2":{"DT":123,"Serial":"xxxxxxxx"}},"Meter":{},"Ohmpilot":{},"SensorCard":{},"Storage":{},"StringControl":{}}},' . $kopf . '}',
            'GetInverterInfo' => '{"Body":{"Data":{"2":{"CustomName":"xxxx","DT":123,"ErrorCode":307,"PVPower":6000,"Show":1,"StatusCode":3,"UniqueID":"xxxxxxx"}}},' . $kopf . '}',
            'GetInverterRealtimeData' => '{"Body":{"Data":{"DAY_ENERGY":{"Unit":"Wh","Values":{"2":0}},"PAC":{"Unit":"W","Values":{"2":0}},"TOTAL_ENERGY":{"Unit":"Wh","Values":{"2":20000000}},"YEAR_ENERGY":{"Unit":"Wh","Values":{"2":7000000}}}},' . $kopf . '}',
            'GetStorageRealtimeData' => '{"Body":{"Data":{}},"Head":{"RequestArguments":{},"Status":{"Code":255,"Reason":"GetStorageRealtimeData request is not supported by this device.","UserMessage":""},"Timestamp":"2026-01-01T12:00:00+01:00"}}',
        ),
    );
}

/** Ein nachgebildeter Abruf: $art ist 'hybrid' oder 'symo'. */
function frw_probe_holer($art, array &$protokoll)
{
    $antw = frw_probe_antworten();
    return function ($adresse, $pfad, $timeout, &$info) use ($art, $antw, &$protokoll) {
        $protokoll[] = $pfad;
        $info = array('code' => 200, 'ms' => 10, 'grund' => '', 'rumpf' => '');
        foreach ($antw[$art] as $name => $roh) {
            if (strpos($pfad, '/' . $name . '.') !== false) {
                return $roh;
            }
        }
        $info['code'] = 404;
        $info['grund'] = 'http';
        return false;
    };
}

/**
 * Selbsttest des Rechenkerns: rechnet die nachgebildeten Antworten durch,
 * ohne Netz, ohne Schreiben. Rueckgabe array(zeilen, faelle, fehlschlaege).
 */
function frw_selbsttest()
{
    $zeilen = array();
    $faelle = 0;
    $fehl = 0;
    $pruef = function ($ok, $text) use (&$zeilen, &$faelle, &$fehl) {
        $faelle++;
        if (!$ok) {
            $fehl++;
        }
        $zeilen[] = ($ok ? '[OK]   ' : '[FEHL] ') . $text;
    };
    $c = frw_vorgaben();
    $c['gruppe_speicher'] = 1;
    $schlaf = 0;
    $warte = function ($s) use (&$schlaf) { $schlaf += $s; };

    $prot = array();
    $e = frw_dm_lesen('192.0.2.31', $c, null, 1000, frw_probe_holer('hybrid', $prot), $warte);
    $pruef($e['ok'] === 1, 'Hybrid: Abruf vollstaendig');
    $pruef(count($prot) === 4, 'Hybrid: vier Anfragen (Geraete, Info, Energie, Speicher), gezaehlt ' . count($prot));
    /* Die Untergrenze 4 s steht hier als Zahl aus der Unterlage (Solar API V1,
       2.6), nicht aus der Konstante: gegen die Konstante gerechnet bliebe die
       Zeile bei FRW_PAUSE_S = 0 gruen (Eichung E1, 12.11.2026). */
    $pruef(FRW_PAUSE_S >= 4 && $schlaf === 3 * FRW_PAUSE_S, 'Hybrid: drei Pausen zu mindestens 4 s zwischen vier Anfragen, gezaehlt ' . $schlaf . ' s');
    $pruef(isset($e['wr'][1]['status']) && $e['wr'][1]['status'] === 7 && $e['wr'][1]['stoerung'] === 0, 'Hybrid: Status 7, keine Stoerung');
    $pruef(isset($e['wr'][1]['e_jahr']) && $e['wr'][1]['e_jahr'] === 6000000 && $e['wr'][1]['e_gesamt'] === 50000000, 'Hybrid: Jahres- und Gesamtenergie in Wh');
    $s = isset($e['sp'][1]) ? $e['sp'][1] : array();
    $pruef(isset($s['anzahl']) && $s['anzahl'] === 8, 'Speicher: 8 Module');
    $pruef(isset($s['soc_min']) && $s['soc_min'] === 10 && $s['soc_max'] === 40 && $s['soc_streuung'] === 30, 'Speicher: SOC 10 bis 40, Streuung 30');
    $pruef(isset($s['u_diff_mv']) && $s['u_diff_mv'] === 150, 'Speicher: groesste Zellspannungsdifferenz 150 mV (3,35 - 3,20 V), gerechnet ' . (isset($s['u_diff_mv']) ? $s['u_diff_mv'] : '-'));
    $pruef(isset($s['zyklen_abstand']) && $s['zyklen_abstand'] === 500, 'Speicher: Zyklen 1500 bis 2000, Abstand 500');
    $pruef(isset($s['module'][1]['zustand']) && $s['module'][1]['zustand'] === 3 && $s['module'][1]['zustand_vorher'] === 5, 'Modul 1: Zustand 53 = 0x35 -> aktuell 3, vorher 5');
    $pruef(strpos(json_encode($e), 'xxxx') === false && strpos(json_encode($e), 'Serial') === false, 'Keine Seriennummer und kein Eigenname im Stand');

    $prot = array();
    $schlaf = 0;
    $e2 = frw_dm_lesen('192.0.2.32', $c, null, 1000, frw_probe_holer('symo', $prot), $warte);
    $pruef($e2['ok'] === 1 && count($prot) === 3, 'Symo: Abruf vollstaendig, ohne Speicherabfrage (3 Anfragen), gezaehlt ' . count($prot));
    $pruef(isset($e2['wr'][2]['fehler']) && $e2['wr'][2]['fehler'] === 307 && $e2['wr'][2]['stoerung'] === 0, 'Symo: Fehlercode 307 ist laut Unterlage keine Stoerung');
    $pruef(frw_stoerung(7, 999) === 1 && frw_stoerung(10, 0) === 1 && frw_stoerung(3, 306) === 0, 'Stoerung: unbekannter Code 999 und Status 10 ja, 306 nein');
    $pruef(frw_status_schluessel(42) === null && frw_fehler_schluessel(999) === null, 'Unbekannte Codes bekommen keinen erfundenen Text');

    $c2 = $c;
    $c2['gruppe_speicher'] = 0;
    $prot = array();
    $e3 = frw_dm_lesen('192.0.2.31', $c2, null, 1000, frw_probe_holer('hybrid', $prot), $warte);
    $pruef(count($prot) === 3 && !$e3['sp'], 'Speichermodule aus: keine Speicherabfrage, keine Modulwerte');
    $w3 = frw_werte_dm(1, $e3, $c2);
    $sp_themen = 0;
    foreach ($w3 as $w) {
        if (strpos($w['thema'], '/sp') !== false) {
            $sp_themen++;
        }
    }
    $pruef($sp_themen === 0, 'Speichermodule aus: kein Speicherthema');

    $w = frw_werte_dm(1, $e, $c);
    $null = 0;
    foreach ($w as $x) {
        if ($x['wert'] === '') {
            $null++;
        }
    }
    $pruef(count($w) > 70 && $null === 0, 'Werte des Hybrid: ' . count($w) . ' Themen, keines leer');
    $f = array();
    $nichts = frw_wr_aus(json_decode('{"1":{"StatusCode":null,"DT":99}}', true), $f);
    $pruef(!isset($nichts[1]['status']) && in_array('wr1.ErrorCode', $f, true), 'null wird nicht zu 0, ein fehlender Schluessel wird benannt');

    $kaputt = null;
    $holer_kaputt = function ($a, $p, $t, &$info) {
        $info = array('code' => 0, 'ms' => 3000, 'grund' => 'zeit', 'rumpf' => '');
        return false;
    };
    $e4 = frw_dm_lesen('192.0.2.31', $c, null, 1000, $holer_kaputt, $warte);
    $pruef($e4['ok'] === 0 && $e4['erreichbar'] === 0 && strpos($e4['grund'], 'zeit') !== false, 'Zeitueberschreitung: ok 0, erreichbar 0, Grund genannt');

    $stand = array('zaehler' => 5, 'dm' => array(1 => $e + array('werte_zeit' => 1000, 'ausfall_folge' => 0)));
    list($code, $zeile) = frw_zeile(1, $stand, $c, 1010);
    $pruef($code === 200 && strpos($zeile, 'FRW1;OK=1;ALTER=10;ZAEHLER=5;') === 0, 'Antwortzeile beginnt mit FRW1;OK=1;ALTER=10;ZAEHLER=5');
    $pruef(strpos($zeile, ';WR1_STATUS=7;') !== false && strpos($zeile, ';SP1_SOC_STREUUNG=30;') !== false, 'Antwortzeile traegt Status und Streuung');
    list($code2) = frw_zeile(2, $stand, $c, 1010);
    $pruef($code2 === 503, 'Kein Stand fuer DM2: HTTP 503');
    list(, $alt_zeile) = frw_zeile(1, $stand, $c, 1000 + 4 * FRW_TAKT_VORGABE);
    $pruef(strpos($alt_zeile, 'FRW1;OK=0;') === 0, 'Veralteter Stand: OK=0');

    list($name, $xml) = frw_vorlage(1, 'loxberry', $c, $stand);
    $x = function_exists('simplexml_load_string') ? @simplexml_load_string($xml) : null;
    $pruef($name === 'VI_Fronius_Waechter_DM1.xml' && ($x === null || $x !== false), 'Vorlage wohlgeformt' . ($x === null ? ' (simplexml fehlt - nicht gemessen)' : ''));
    $pruef(substr_count($xml, "\r\n") === substr_count($xml, "\n") && strpos($xml, "\r\n\t<Info templateType=\"2\" minVersion=\"17010727\"/>") !== false, 'Vorlage CRLF, Tabulator, Info templateType 2');
    $titel = array();
    preg_match_all('/<VirtualInHttpCmd Title="([^"]*)"/', $xml, $tm);
    $titel = $tm[1];
    $pruef(count($titel) === count(array_unique($titel)) && count($titel) > 20, 'Vorlage: ' . count($titel) . ' Befehle, Titel eindeutig');

    $r = frw_pruef_suchmuster();
    $pruef($r[0] === 'ok', 'Suchmuster: ' . $r[1]);

    $gesendet = array();
    $sender = function ($praefix, $zeilen, $port) use (&$gesendet) {
        $gesendet = $zeilen;
        return count($zeilen);
    };
    $retain_falsch = array();
    foreach (frw_feldtabelle() as $k => $f) {
        if (in_array($k, array('erreichbar', 'ausfall_folge', 'antwort_ms'), true) && frw_retain($k)) {
            $retain_falsch[] = $k;
        }
    }
    $pruef(!$retain_falsch, 'Aussagen des Dienstes sind nie retained');
    $pruef(frw_retain('wr.status') && !frw_retain('wr.e_tag') && !frw_retain('m.soc'), 'Retain: Status ja, Energie und Ladestand nein');
    $pruef(frw_gateway_name('froniuswaechter', 'dm1/wr1/status') === 'froniuswaechter_dm1_wr1_status', 'Gateway-Name: froniuswaechter_dm1_wr1_status');

    $alt = array('dm' => array(2 => array('ausfall_folge' => 2, 'wr' => array(2 => array('stoerung' => 0)), 'sp' => array())));
    $neu = array('dm' => array(2 => array('ok' => 1, 'ausfall_folge' => 0, 'wr' => array(2 => array('status' => 10, 'fehler' => 0, 'stoerung' => 1)), 'sp' => array())));
    $pruef(!frw_meldungen_ermitteln($alt, $neu, $c), 'Meldungen ab Werk aus: keine Meldung');
    $cm = $c;
    $cm['meldung_stoerung'] = 1;
    $pruef(count(frw_meldungen_ermitteln($alt, $neu, $cm)) === 1, 'Meldung Stoerung eingeschaltet: genau eine Meldung beim Wechsel');
    $pruef(!frw_meldungen_ermitteln(array(), $neu, $cm), 'Erster Lauf ohne alten Stand: keine Meldung');

    list($sn, $sm) = frw_sicherung_lesen(json_encode(frw_sicherung_bauen(frw_vorgaben())));
    $pruef($sn !== null && !$sm, 'Sicherung: eigene Datei wird angenommen');
    $halb = frw_sicherung_bauen(frw_vorgaben());
    $halb['takt_s'] = 5;
    list($hn, $hm) = frw_sicherung_lesen(json_encode($halb));
    $pruef($hn === null && count($hm) === 1, 'Sicherung halb gueltig (takt_s 5): abgelehnt mit einer Beanstandung');
    list($fn, $fm) = frw_sicherung_lesen('{"BASE.FRITZBOX":"x","USER1.MACS":"y"}');
    $pruef($fn === null && count($fm) >= 3, 'Sicherung fremd: abgelehnt, alle Beanstandungen gesammelt (' . count($fm) . ')');
    $pruef(frw_adresse_pruefen('192.0.2.31; rm -rf /') === '' && frw_adresse_pruefen('http://192.0.2.31') === ''
        && frw_adresse_pruefen('192.0.2.31:8080') === '192.0.2.31:8080' && frw_adresse_pruefen('256.1.1.1') === '', 'Adresspruefung: Befehl, Schema und 256 abgewiesen, Port angenommen');
    $vg = frw_vorgaben();
    $pruef($vg['dienst_ein'] === 0 && $vg['gruppe_speicher'] === 0 && $vg['meldung_stoerung'] === 0 && $vg['meldung_ausfall'] === 0
        && $vg['meldung_speicher'] === 0 && $vg['schwelle_soc_streuung'] === 0 && $vg['schwelle_zellen_mv'] === 0 && $vg['eb_anzeigen'] === 0,
        'Ab Werk aus: Dienst, Speichergruppe, drei Meldungen, zwei Schwellen, Einspeisebremse-Anzeige');
    return array($zeilen, $faelle, $fehl);
}
