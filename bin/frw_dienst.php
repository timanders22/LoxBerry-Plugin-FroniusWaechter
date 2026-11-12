<?php
/**
 * Fronius-Waechter - Abruf im Minutentakt
 *
 * Aufruf: cron/cron.01min, jede Minute, als Benutzer loxberry. Tut nur etwas,
 * wenn im Reiter Einstellungen "Dienst einschalten" angehakt ist und
 * mindestens ein Datamanager eingetragen ist; der eingestellte Takt
 * (Vorgabe 300 s) entscheidet, ob dieser Lauf abruft. Die Arbeit steht in
 * frw_takt() in frw_lib.php.
 *
 *   php frw_dienst.php               ein Lauf
 *   php frw_dienst.php --selbsttest  Rechenkern mit nachgebildeten Antworten;
 *                                    fragt keinen Datamanager, sendet nichts,
 *                                    schreibt nichts
 *   php frw_dienst.php --vorgaben    die Vorgaben als JSON (Gegenstelle fuer
 *                                    die Pruefzeile im Reiter Test)
 *
 * (c) Fronius-Waechter Plugin Authors - MIT-Lizenz
 */

/* Die Bibliothek liegt im ANDEREN Baum:
     installiert  $LB/bin/plugins/<ordner>/frw_dienst.php
                  $LB/webfrontend/html/plugins/<ordner>/frw_lib.php
     im Archiv    <plugin>/bin/frw_dienst.php
                  <plugin>/webfrontend/html/frw_lib.php */
$frw_kandidaten = array();
$frw_lb = getenv('LBHOMEDIR');
if ($frw_lb !== false && $frw_lb !== '') {
    $frw_kandidaten[] = rtrim($frw_lb, '/\\') . '/webfrontend/html/plugins/' . basename(__DIR__) . '/frw_lib.php';
}
$frw_kandidaten[] = dirname(dirname(dirname(__DIR__))) . '/webfrontend/html/plugins/' . basename(__DIR__) . '/frw_lib.php';
$frw_kandidaten[] = dirname(__DIR__) . '/webfrontend/html/frw_lib.php';
$frw_geladen = '';
foreach ($frw_kandidaten as $frw_k) {
    if (is_file($frw_k)) {
        require_once $frw_k;
        $frw_geladen = $frw_k;
        break;
    }
}
if ($frw_geladen === '' || !function_exists('frw_takt')) {
    fwrite(STDERR, date('Y-m-d H:i:s') . ' frw_dienst.php: frw_lib.php nicht gefunden, gesucht in: '
        . implode(' | ', $frw_kandidaten) . "\n");
    exit(1);
}

$frw_arg = isset($argv[1]) ? (string) $argv[1] : '';
if ($frw_arg === '--selbsttest') {
    list($frw_zeilen, $frw_faelle, $frw_fehl) = frw_selbsttest();
    foreach ($frw_zeilen as $frw_z) {
        echo $frw_z . "\n";
    }
    /* Die Schlusszeile wird aus den ausgegebenen Zeilen gezaehlt, nicht aus
       einem mitlaufenden Zaehler (Regeln/02). */
    $frw_gezaehlt = 0;
    $frw_rot = 0;
    foreach ($frw_zeilen as $frw_z) {
        $frw_gezaehlt++;
        if (strpos($frw_z, '[FEHL]') === 0) {
            $frw_rot++;
        }
    }
    if ($frw_gezaehlt !== $frw_faelle || $frw_rot !== $frw_fehl) {
        echo 'Zaehlung uneinig: ' . $frw_gezaehlt . '/' . $frw_rot . ' Zeilen gegen ' . $frw_faelle . '/' . $frw_fehl . "\n";
    }
    echo 'Rechenkern Fronius-Waechter ' . FRW_FASSUNG . ': ' . $frw_gezaehlt . ' Faelle geprueft, ' . $frw_rot . " Fehlschlaege.\n";
    exit($frw_rot > 0 ? 1 : 0);
}
if ($frw_arg === '--vorgaben') {
    echo json_encode(frw_vorgaben()) . "\n";
    exit(0);
}
if ($frw_arg !== '') {
    fwrite(STDERR, "Unbekanntes Argument. Erlaubt: --selbsttest, --vorgaben\n");
    exit(2);
}

frw_config_vervollstaendigen();
$frw_erg = frw_takt();
exit(in_array($frw_erg['ende'], array('ohne_wurzel', 'sperre_fehlt'), true) ? 1 : 0);
