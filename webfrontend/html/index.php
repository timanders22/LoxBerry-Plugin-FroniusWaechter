<?php
/**
 * Fronius-Waechter - Endpunkt fuer den Miniserver
 *
 * Liest NUR den Stand, den der Dienst geschrieben hat; fragt nie einen
 * Datamanager (keine zusaetzliche Last, nie eine zweite Anfrage zugleich).
 *
 *   ?aktion=status&dm=N          offen (abfragend): Antwortzeile FRW<N>;OK=..
 *   ?aktion=jetzt&token=T        ausloesend: Merker "beim naechsten
 *                                Minutenlauf abrufen" - verlangt das Token
 *   ?selftest=1&token=T          beantwortet die Tokenfrage, loest nichts aus
 *
 * Ohne eingerichtetes Token lehnt jeder ausloesende Aufruf ab; der
 * unangemeldete Endpunkt legt nichts an (frw_config(false), Regeln/05).
 *
 * (c) Fronius-Waechter Plugin Authors - MIT-Lizenz
 */
ini_set('display_errors', '0');
header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');

if (!is_file(__DIR__ . '/frw_lib.php')) {
    error_log('froniuswaechter: frw_lib.php nicht gefunden, gesucht in: ' . __DIR__);
    http_response_code(500);
    echo "FRW;OK=0;GRUND=BIBLIOTHEK_FEHLT\n";
    exit;
}
require_once __DIR__ . '/frw_lib.php';

/** Ein GET-Feld als Zeichenkette; ein Feld (name[]=) ergibt ''. */
function frw_ep_feld($name)
{
    return (isset($_GET[$name]) && is_string($_GET[$name])) ? $_GET[$name] : '';
}

function frw_ep_ende($code, $zeile)
{
    http_response_code($code);
    echo $zeile . "\n";
    exit;
}

$frw_ep_c = frw_config(false);
$frw_ep_soll = ($frw_ep_c !== null && is_string($frw_ep_c['aktionstoken'])) ? $frw_ep_c['aktionstoken'] : '';
$frw_ep_ist = frw_ep_feld('token');
$frw_ep_token_ok = $frw_ep_soll !== '' && $frw_ep_ist !== '' && hash_equals($frw_ep_soll, $frw_ep_ist);

if (frw_ep_feld('selftest') === '1') {
    if ($frw_ep_soll === '') {
        frw_ep_ende(403, 'SELFTEST;OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
    }
    if (!$frw_ep_token_ok) {
        frw_ep_ende(403, 'SELFTEST;OK=0;ERR=TOKEN');
    }
    frw_ep_ende(200, 'SELFTEST;OK=1;TOKEN=OK');
}

$frw_ep_aktion = frw_ep_feld('aktion');
if ($frw_ep_aktion === '' || $frw_ep_aktion === 'status') {
    if ($frw_ep_c === null) {
        frw_ep_ende(503, 'FRW;OK=0;GRUND=NICHT_EINGERICHTET');
    }
    $frw_ep_dm = frw_ep_feld('dm');
    if (preg_match('/^[1-9]$/', $frw_ep_dm) !== 1) {
        frw_ep_ende(400, 'FRW;OK=0;GRUND=DM_FEHLT');
    }
    list($frw_ep_code, $frw_ep_zeile) = frw_zeile((int) $frw_ep_dm, frw_stand_lesen(), $frw_ep_c);
    frw_ep_ende($frw_ep_code, $frw_ep_zeile);
}

if ($frw_ep_aktion === 'jetzt') {
    if ($frw_ep_soll === '') {
        frw_ep_ende(403, 'JETZT;OK=0;ERR=KEIN_TOKEN_EINGERICHTET');
    }
    if (!$frw_ep_token_ok) {
        frw_ep_ende(403, 'JETZT;OK=0;ERR=TOKEN');
    }
    if (empty($frw_ep_c['dienst_ein'])) {
        frw_ep_ende(409, 'JETZT;OK=0;ERR=DIENST_AUS');
    }
    if (!frw_jetzt_merken()) {
        frw_ep_ende(500, 'JETZT;OK=0;ERR=SCHREIBEN');
    }
    frw_ep_ende(200, 'JETZT;OK=1');
}

frw_ep_ende(400, 'FRW;OK=0;ERR=AKTION_UNBEKANNT');
