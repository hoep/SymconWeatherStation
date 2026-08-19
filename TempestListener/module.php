<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Weather/autoload.php';

use Hoep\Weather\Engines\Meteo;
use Hoep\Weather\Observation;
use Hoep\Weather\Profiles;

/**
 * TempestListener (WXT) — hoert der WeatherFlow Tempest direkt zu.
 *
 * Die Station funkt ihre Saetze als UDP-Broadcast auf Port 50222 ins eigene Netz. Dieses
 * Modul haengt sich als Kind an einen UDP-Socket und liest mit — ohne Cloud, ohne Konto und
 * ohne ein fremdes Modul dazwischen.
 *
 * DER EIGENTLICHE GRUND ist nicht die Unabhaengigkeit, sondern die Aufloesung bei Blitzen.
 * Die Station sendet fuer JEDEN Schlag ein eigenes `evt_strike` mit eigenem Zeitstempel und
 * eigener Entfernung. Wer stattdessen die Sammelvariablen eines Stationsmoduls liest, bekommt
 * nur einen Zaehler je Minute und eine mittlere Entfernung — damit laesst sich ein einzelner
 * Blitz in 30 km nicht von einer Zellenpassage ueber dem Haus unterscheiden. Genau diese
 * Unterscheidung ist aber die Gewitterlage.
 *
 * Mehrere Zuhoerer stoeren einander nicht: ein UDP-Socket in IP-Symcon darf mehrere Kinder
 * haben, und ein Broadcast erreicht sie alle. Ein vorhandenes Tempest-Modul kann also
 * unveraendert weiterlaufen.
 *
 * Einheiten der Station: Wind in m/s, Druck in mb (= hPa), Regen als Menge der letzten
 * Minute in mm. Hier wird auf km/h, hPa und mm/h gebracht.
 */
class TempestListener extends IPSModule
{
    /** Feldfolge des Beobachtungssatzes `obs_st` laut Geraetebeschreibung. */
    private const OBS = [
        0 => 'zeit', 1 => 'lull', 2 => 'windAvg', 3 => 'gust', 4 => 'dir', 5 => 'interval',
        6 => 'druck', 7 => 'temp', 8 => 'feuchte', 9 => 'lux', 10 => 'uv', 11 => 'strahlung',
        12 => 'regenMin', 13 => 'nsArt', 14 => 'blitzDist', 15 => 'blitzZahl', 16 => 'volt',
        17 => 'reportInterval',
    ];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyString('Serial', '');       // leer = jede Station annehmen
        $this->RegisterPropertyBoolean('RapidWind', true); // Boeenwerte im 3-Sekunden-Takt
        $this->RegisterPropertyBoolean('Mirror', true);    // empfangene Werte als Variablen zeigen
        $this->RegisterPropertyBoolean('Logging', true);   // Messreihen archivieren
        // Die Tempest meldet den Druck AM AUFSTELLORT. Ohne Umrechnung passt er zu keiner
        // anderen Quelle - hier gemessen 962 gegen 1013 hPa.
        $this->RegisterPropertyBoolean('PressureAbsolute', true);
        $this->RegisterPropertyFloat('Altitude', 0.0);

        $this->RegisterVariableString('Data', 'Beobachtung (JSON)', '', 10);
        $this->RegisterVariableInteger('LastObs', 'Letzter Messsatz', '~UnixTimestamp', 20);
        $this->RegisterVariableInteger('Strikes1h', 'Blitze (letzte Stunde)', '', 30);
        $this->RegisterVariableString('StrikeLog', 'Blitze (JSON)', '', 31);
        $this->RegisterVariableInteger('Packets', 'Empfangene Sätze', '', 40);

        $this->RegisterAttributeString('Obs', '{}');
        $this->RegisterAttributeString('Wind', '{}');
        $this->RegisterAttributeString('Strikes', '[]');

        $this->ConnectParent('{82347F20-F541-41E1-AC5B-A636FD3AE2D8}');   // UDP Socket
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        // Die Blitzzahl je Stunde ist eine echte Messreihe — sie zeigt im Nachhinein, wie
        // stark eine Zelle war und wann sie durchgezogen ist.
        $aid = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        $vid = @$this->GetIDForIdent('Strikes1h');
        if ($aid && $vid && !AC_GetLoggingStatus($aid, $vid)) {
            AC_SetLoggingStatus($aid, $vid, true);
        }
        $this->SetStatus(102);
    }

    /**
     * Ein Datagramm vom Socket. Wird fuer JEDES Paket aufgerufen, also alle drei Sekunden —
     * hier darf nichts Teures passieren.
     */
    public function ReceiveData($JSONString)
    {
        $d = json_decode((string) $JSONString, true);
        $roh = (string) ($d['Buffer'] ?? '');
        if ($roh === '') {
            return '';
        }
        $p = json_decode($roh, true);
        if (!is_array($p) || !isset($p['type'])) {
            return '';
        }
        $serial = (string) $this->ReadPropertyString('Serial');
        if ($serial !== '' && (string) ($p['serial_number'] ?? '') !== $serial) {
            return '';
        }

        switch ($p['type']) {
            case 'obs_st':
                $this->obsSt($p);
                break;
            case 'rapid_wind':
                if ($this->ReadPropertyBoolean('RapidWind')) {
                    $this->rapidWind($p);
                }
                break;
            case 'evt_strike':
                $this->evtStrike($p);
                break;
        }
        return '';
    }

    // ==================================================================
    // Satzarten
    // ==================================================================

    private function obsSt(array $p): void
    {
        $o = $p['obs'][0] ?? null;
        if (!is_array($o)) {
            return;
        }
        $w = [];
        foreach (self::OBS as $i => $name) {
            $w[$name] = isset($o[$i]) && is_numeric($o[$i]) ? (float) $o[$i] : null;
        }
        $w['empfangen'] = time();
        $this->WriteAttributeString('Obs', json_encode($w));
        $this->SetValue('LastObs', (int) ($w['zeit'] ?? time()));
        $this->SetValue('Packets', $this->GetValue('Packets') + 1);
        $this->SetValue('Data', $this->GetObservation());
        if ($this->ReadPropertyBoolean('Mirror')) {
            $this->mirror();
        }
    }

    /**
     * `rapid_wind` kommt alle drei Sekunden. Gespeichert wird nur der Wert selbst, ohne die
     * Beobachtung neu zu bauen — sonst liefe die Instanz den ganzen Tag im Leerlauf heiss.
     */
    private function rapidWind(array $p): void
    {
        $e = $p['ob'] ?? null;
        if (!is_array($e) || count($e) < 3) {
            return;
        }
        $this->WriteAttributeString('Wind', json_encode([
            'zeit' => (int) $e[0],
            'kmh'  => round((float) $e[1] * 3.6, 1),
            'dir'  => (int) $e[2],
        ]));
    }

    /**
     * Ein einzelner Blitz. Das ist der Satz, wegen dem dieses Modul existiert: Zeitpunkt und
     * Entfernung je Schlag, statt eines Zaehlers je Minute.
     */
    private function evtStrike(array $p): void
    {
        $e = $p['evt'] ?? null;
        if (!is_array($e) || count($e) < 2) {
            return;
        }
        $zeit = (int) $e[0];
        $dist = (float) $e[1];
        $ring = json_decode($this->ReadAttributeString('Strikes'), true);
        if (!is_array($ring)) {
            $ring = [];
        }
        $ring[] = ['t' => $zeit, 'd' => $dist, 'e' => (int) ($e[2] ?? 0)];
        $jetzt = time();
        $ring = array_values(array_filter($ring, static fn($x) => ($jetzt - (int) $x['t']) <= 3600));
        // Ein Gewitter kann hunderte Schlaege bringen; der Speicher bleibt trotzdem klein.
        if (count($ring) > 500) {
            $ring = array_slice($ring, -500);
        }
        $this->WriteAttributeString('Strikes', json_encode($ring));
        $this->SetValue('Strikes1h', count($ring));
        $this->SetValue('StrikeLog', json_encode(array_slice($ring, -20)));
        $this->SetValue('Data', $this->GetObservation());
        $this->LogMessage(sprintf('Blitz in %.0f km', $dist), KL_NOTIFY);
    }

    // ==================================================================
    // Oeffentlich
    // ==================================================================

    /** Die aktuelle Beobachtung im Format der Wetter-Bibliothek. */
    public function GetObservation(): string
    {
        $w = json_decode($this->ReadAttributeString('Obs'), true);
        $o = new Observation('tempest-udp');
        if (is_array($w) && isset($w['zeit'])) {
            $ts = (int) $w['zeit'];
            $o->set('tempC', $w['temp'], $ts);
            $o->set('humPct', $w['feuchte'], $ts);
            $o->set('pressureHpa', $w['druck'], $ts);   // Stationsdruck, siehe Treiber
            $o->set('windAvgKmh', $w['windAvg'] === null ? null : round($w['windAvg'] * 3.6, 1), $ts);
            $o->set('windKmh', $w['windAvg'] === null ? null : round($w['windAvg'] * 3.6, 1), $ts);
            $o->set('gustKmh', $w['gust'] === null ? null : round($w['gust'] * 3.6, 1), $ts);
            $o->set('windDirDeg', $w['dir'], $ts);
            $o->set('illuminanceLux', $w['lux'], $ts);
            $o->set('uvIndex', $w['uv'], $ts);
            $o->set('radiationWm2', $w['strahlung'], $ts);
            $o->set('precipType', $w['nsArt'], $ts);
            $o->set('batteryV', $w['volt'], $ts);
            // Die Station meldet die Menge der letzten MINUTE — als Rate ist das mal 60.
            $o->set('rainRateMmH', $w['regenMin'] === null ? null : round($w['regenMin'] * 60, 2), $ts);
            if ($w['temp'] !== null && $w['feuchte'] !== null) {
                $o->set('dewC', Meteo::taupunkt((float) $w['temp'], (float) $w['feuchte']), $ts);
            }
        }
        // Boeenwert aus rapid_wind schlaegt den Minutenmittelwert, wenn er juenger ist.
        $rw = json_decode($this->ReadAttributeString('Wind'), true);
        if (is_array($rw) && isset($rw['kmh']) && (time() - (int) $rw['zeit']) < 60) {
            $o->set('windKmh', (float) $rw['kmh'], (int) $rw['zeit']);
            $o->set('windDirDeg', (float) $rw['dir'], (int) $rw['zeit']);
        }
        // Stationsdruck auf Meeresniveau bringen. Ohne Hoehe lieber gar keinen Wert als einen,
        // der zu jeder anderen Quelle um Dutzende Hektopascal danebenliegt.
        if ($o->has('pressureHpa') && $this->ReadPropertyBoolean('PressureAbsolute')) {
            $h = (float) $this->ReadPropertyFloat('Altitude');
            if ($h > 0.0) {
                $t0 = ($o->num('tempC') ?? 15.0) + 273.15;
                $o->set('pressureHpa',
                        round($o->num('pressureHpa') * pow(1 - (0.0065 * $h) / ($t0 + 0.0065 * $h), -5.257), 1),
                        $o->ts('pressureHpa'));
            } else {
                $o->remove('pressureHpa');
            }
        }

        // Blitze: der JUENGSTE Schlag mit seiner EIGENEN Entfernung und Zeit.
        $ring = json_decode($this->ReadAttributeString('Strikes'), true);
        if (is_array($ring) && $ring !== []) {
            $letzt = $ring[count($ring) - 1];
            $o->set('strikeTime', (int) $letzt['t'], (int) $letzt['t']);
            $o->set('strikeDistKm', (float) $letzt['d'], (int) $letzt['t']);
            $o->set('strikeCount', count($ring), time());
        }
        return json_encode($o->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Zeigt die empfangenen Werte als eigene Variablen.
     *
     * Ja, das ist doppelt zur Quelle und zur Station. Genau deshalb: an diesem Knoten will man
     * sehen, was die STATION SELBST gefunkt hat — ohne Zusammenfuehrung, ohne Umrechnung, ohne
     * eine andere Quelle dazwischen. Eine JSON-Zeichenkette beantwortet das nicht.
     */
    private function mirror(): void
    {
        Profiles::ensure();
        $a = json_decode($this->GetObservation(), true);
        if (!is_array($a)) {
            return;
        }
        $pos = 100;
        foreach (array_keys(Observation::QUANTITIES) as $ident) {
            $pos += 10;
            if (!isset($a[$ident]['wert'])) {
                continue;
            }
            [$label, $einheit] = Observation::QUANTITIES[$ident];
            $name = $label . ($einheit !== '' ? ' (' . $einheit . ')' : '');
            $var  = 'q_' . $ident;
            if ($ident === 'strikeTime') {
                $this->RegisterVariableInteger($var, $name, Profiles::forQuantity($ident), $pos);
                $this->SetValue($var, (int) $a[$ident]['wert']);
                continue;
            }
            $this->RegisterVariableFloat($var, $name, Profiles::forQuantity($ident), $pos);
            $this->SetValue($var, (float) $a[$ident]['wert']);
        }
        if ($this->ReadPropertyBoolean('Logging')) {
            $this->applyLogging(array_keys($a));
        }
    }

    /**
     * Archiviert die Messreihen. Zaehler und Kennungen bleiben aussen vor — sie sind Zustand,
     * keine Messreihe, und ein Blitzzeitpunkt als Mittelwert je Stunde ergibt nichts.
     */
    private function applyLogging(array $idents): void
    {
        static $nicht = ['strikeTime', 'precipType'];
        $aid = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        if (!$aid) {
            return;
        }
        foreach ($idents as $ident) {
            if ($ident === '' || $ident[0] === '_' || in_array($ident, $nicht, true)) {
                continue;
            }
            $vid = @$this->GetIDForIdent('q_' . $ident);
            if ($vid && !AC_GetLoggingStatus($aid, $vid)) {
                AC_SetLoggingStatus($aid, $vid, true);
            }
        }
    }

    /** Alle Blitze der letzten Stunde, einzeln — fuer die Gewitterauswertung. */
    public function GetStrikes(): string
    {
        return $this->ReadAttributeString('Strikes');
    }

    public function TestRead(): string
    {
        $w = json_decode($this->ReadAttributeString('Obs'), true);
        if (!is_array($w) || !isset($w['zeit'])) {
            return "Noch kein Messsatz empfangen.\n\n"
                 . "Die Station sendet obs_st nur EINMAL PRO MINUTE — nach dem Anlegen kann es "
                 . "also bis zu einer Minute dauern. Kommt danach nichts, prüfen: Ist die Instanz "
                 . "mit einem UDP-Socket auf Port 50222 verbunden, steht dort Broadcast und "
                 . "Adresswiederverwendung an, und liegt die Station im selben Netz?";
        }
        $alt = time() - (int) $w['zeit'];
        $s = sprintf("Letzter Messsatz vor %d s, %d Sätze empfangen.\n\n", $alt, $this->GetValue('Packets'));
        foreach ($w as $k => $v) {
            $s .= sprintf("  %-16s %s\n", $k, $v === null ? '--' : $v);
        }
        $ring = json_decode($this->ReadAttributeString('Strikes'), true);
        $s .= sprintf("\nBlitze in der letzten Stunde: %d\n", is_array($ring) ? count($ring) : 0);
        if (is_array($ring)) {
            foreach (array_slice($ring, -5) as $b) {
                $s .= sprintf("  %s   %.0f km\n", date('H:i:s', (int) $b['t']), (float) $b['d']);
            }
        }
        return $s . "\nAbgeleitete Beobachtung:\n" . $this->GetObservation();
    }

    public function GetConfigurationForm()
    {
        return json_encode(['elements' => [
            ['type' => 'Label', 'caption' =>
                'Hört der Tempest direkt zu: die Station funkt ihre Sätze als UDP-Broadcast auf '
                . 'Port 50222 ins eigene Netz. Diese Instanz muss dafür an einem UDP-Socket hängen, '
                . 'der auf 0.0.0.0:50222 lauscht, mit Broadcast und Adresswiederverwendung.'],
            ['type' => 'Label', 'caption' =>
                'Ein vorhandenes Tempest-Modul kann daneben unverändert weiterlaufen — ein Socket '
                . 'darf mehrere Kinder haben, und ein Broadcast erreicht sie alle.'],
            ['type' => 'ValidationTextBox', 'name' => 'Serial', 'caption' => 'Seriennummer (leer = jede Station)'],
            ['type' => 'CheckBox', 'name' => 'RapidWind', 'caption' => 'Windwerte im Drei-Sekunden-Takt übernehmen'],
            ['type' => 'CheckBox', 'name' => 'Mirror', 'caption' => 'Empfangene Werte als eigene Variablen zeigen'],
            ['type' => 'CheckBox', 'name' => 'Logging', 'caption' => 'Messreihen archivieren'],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'CheckBox', 'name' => 'PressureAbsolute', 'caption' => 'Luftdruck ist Stationsdruck'],
                ['type' => 'NumberSpinner', 'name' => 'Altitude', 'caption' => 'Höhe des Aufstellorts (m über NN)', 'digits' => 0],
            ]],
            ['type' => 'Label', 'caption' => 'Die Tempest meldet den Druck am Aufstellort. Ohne Höhenangabe '
                . 'wird er gar nicht geliefert — ein um Dutzende Hektopascal abweichender Wert wäre '
                . 'schlimmer als keiner.'],
            ['type' => 'Label', 'caption' =>
                'Der Beobachtungssatz kommt nur einmal pro Minute. Der Drei-Sekunden-Wind ist deshalb '
                . 'der einzige Weg zu einem wirklich aktuellen Windwert — für Beschattung und '
                . 'Markisenschutz ist das der Unterschied zwischen rechtzeitig und zu spät.'],
            ['type' => 'Button', 'caption' => 'Was ist angekommen?', 'onClick' => 'echo WXT_TestRead($id);'],
        ]]);
    }
}
