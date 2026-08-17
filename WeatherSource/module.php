<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/Weather/autoload.php';

use Hoep\Weather\Observation;
use Hoep\Weather\Profiles;
use Hoep\Weather\SourceFactory;

/**
 * WeatherSource (WXS) — EINE Wetterquelle: eine Station, ein Dienst, ein Satz Variablen.
 *
 * Jede Quelle ist eine eigene Instanz. Wer eine Davis und eine Tempest hat, legt zwei an;
 * die WeatherStation fuehrt sie dann je Groesse zusammen. Das ist der Grund fuer den Schnitt:
 * keine Station kann alles, und "welche Station soll es sein?" ist die falsche Frage.
 *
 * Jede Quelle zeigt ausserdem alles, was sie liefert, als eigene Variablen. Die Werte stehen
 * damit doppelt im Baum — hier je Station und noch einmal zusammengefuehrt in der
 * WeatherStation. Das ist Absicht: sonst sieht man einer Anlage nicht an, WAS eine einzelne
 * Station eigentlich misst und worin sich zwei Stationen unterscheiden. Gebunden wird an die
 * Station, nachgesehen wird hier. Wer es schlanker will, schaltet es im Formular ab.
 */
class WeatherSource extends IPSModule
{
    public function Create()
    {
        parent::Create();

        $this->RegisterPropertyString('Driver', 'davis-actdata');
        $this->RegisterPropertyInteger('Interval', 5);      // Sekunden, 0 = aus
        $this->RegisterPropertyInteger('MaxAge', 900);      // Werte aelter als das gelten als tot
        $this->RegisterPropertyBoolean('Mirror', true);     // Werte zusaetzlich als Variablen zeigen
        $this->RegisterPropertyBoolean('Logging', true);    // eigene Messreihen archivieren

        // Fuer JEDES Feld JEDES Treibers eine echte Eigenschaft anlegen. Symcon speichert
        // beim Uebernehmen nur, was hier registriert ist — ein Formularfeld ohne Eigenschaft
        // sieht aus, als wuerde es gespeichert, und ist beim naechsten Aufruf wieder leer.
        foreach (SourceFactory::all() as $id => $klasse) {
            foreach ($klasse::fields() as $f) {
                $name = self::propName($id, $f['name']);
                switch ($f['type']) {
                    case 'Integer':
                    case 'Variable':
                    case 'Instance':
                        $this->RegisterPropertyInteger($name, (int) $f['default']);
                        break;
                    case 'Float':
                        $this->RegisterPropertyFloat($name, (float) $f['default']);
                        break;
                    case 'Boolean':
                        $this->RegisterPropertyBoolean($name, (bool) $f['default']);
                        break;
                    default:
                        $this->RegisterPropertyString($name, (string) $f['default']);
                }
            }
        }

        $this->RegisterVariableString('Data', 'Beobachtung (JSON)', '', 10);
        $this->RegisterVariableInteger('LastRead', 'Zuletzt gelesen', '~UnixTimestamp', 20);
        $this->RegisterVariableString('Error', 'Letzter Fehler', '', 30);
        $this->RegisterVariableBoolean('Online', 'Erreichbar', '~Alert.Reversed', 40);

        $this->RegisterTimer('Poll', 0, 'WXS_Poll($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $iv = max(0, $this->ReadPropertyInteger('Interval'));
        $this->SetTimerInterval('Poll', $iv * 1000);
        $this->SetStatus(SourceFactory::has($this->ReadPropertyString('Driver')) ? 102 : 201);
    }

    // ==================================================================
    // Oeffentlich
    // ==================================================================

    /** Liest die Quelle und legt das Ergebnis in den Variablen ab. */
    public function Poll(): void
    {
        $o = $this->readSource($fehler);
        $this->SetValue('Data', json_encode($o->toArray(), JSON_UNESCAPED_UNICODE));
        $this->SetValue('Error', $fehler);
        $this->SetValue('Online', $fehler === '' && !$o->leer());
        if (!$o->leer()) {
            $this->SetValue('LastRead', time());
            if ($this->ReadPropertyBoolean('Mirror')) {
                $this->mirror($o);
            }
        }
    }

    /**
     * Die aktuelle Beobachtung als Array — so holt sich die WeatherStation ihre Daten.
     * Bewusst OHNE Zwischenspeicher: die Station entscheidet ueber den Takt, nicht die Quelle.
     */
    public function GetObservation(): string
    {
        return json_encode($this->readSource($f)->toArray(), JSON_UNESCAPED_UNICODE);
    }

    /**
     * Einzelne Blitze, sofern der Treiber sie hat. Leere Liste heisst "kann diese Quelle nicht" —
     * die Station faellt dann auf den letzten Blitz aus der Beobachtung zurueck.
     */
    public function GetStrikes(): string
    {
        $id = $this->ReadPropertyString('Driver');
        if (!SourceFactory::has($id)) {
            return '[]';
        }
        try {
            $t = SourceFactory::create($id, $this->driverConfig($id));
            return json_encode($t instanceof \Hoep\Weather\IStrikeSource ? $t->strikes() : []);
        } catch (\Throwable $e) {
            return '[]';
        }
    }

    /** Probelauf fuer das Formular: liest einmal und zeigt alles, was ankommt. */
    public function TestRead(): string
    {
        $t0 = microtime(true);
        $o  = $this->readSource($fehler);
        $ms = (int) round((microtime(true) - $t0) * 1000);

        $z = sprintf("Treiber: %s\nDauer: %d ms\nFehler: %s\n\n",
                     $this->ReadPropertyString('Driver'), $ms, $fehler !== '' ? $fehler : 'keiner');
        if ($o->leer()) {
            return $z . 'Es kam kein einziger Wert an.';
        }
        $z .= sprintf("%-16s %12s %-8s %s\n", 'Groesse', 'Wert', 'Einheit', 'Alter');
        foreach ($o->idents() as $id) {
            [$label, $einheit] = Observation::QUANTITIES[$id];
            $z .= sprintf("%-16s %12s %-8s %d s   (%s)\n",
                          $id, (string) $o->get($id), $einheit, $o->age($id), $label);
        }
        $fehlt = array_diff(array_keys(Observation::QUANTITIES), $o->idents());
        return $z . "\nNicht geliefert: " . ($fehlt === [] ? 'nichts' : implode(', ', $fehlt));
    }

    // ==================================================================
    // Intern
    // ==================================================================

    private function readSource(?string &$fehler = null): Observation
    {
        $fehler = '';
        $id = $this->ReadPropertyString('Driver');
        if (!SourceFactory::has($id)) {
            $fehler = 'Unbekannter Treiber: ' . $id;
            return new Observation($id);
        }
        try {
            $t = SourceFactory::create($id, $this->driverConfig($id));
            $o = $t->read();
            $fehler = $t->lastError();
            return $o;
        } catch (\Throwable $e) {
            $fehler = $e->getMessage();
            return new Observation($id);
        }
    }

    /** @return array<string,mixed> Feldwerte des gewaehlten Treibers */
    private function driverConfig(string $id): array
    {
        $c = [];
        foreach (SourceFactory::fields($id) as $f) {
            $name = self::propName($id, $f['name']);
            switch ($f['type']) {
                case 'Integer':
                case 'Variable':
                case 'Instance':
                    $c[$f['name']] = $this->ReadPropertyInteger($name);
                    break;
                case 'Float':
                    $c[$f['name']] = $this->ReadPropertyFloat($name);
                    break;
                case 'Boolean':
                    $c[$f['name']] = $this->ReadPropertyBoolean($name);
                    break;
                default:
                    $c[$f['name']] = $this->ReadPropertyString($name);
            }
        }
        return $c;
    }

    /**
     * Legt jede gelieferte Groesse zusaetzlich als eigene Variable an.
     *
     * Die Werte stehen dadurch DOPPELT im Baum — einmal hier je Quelle, einmal in der
     * zusammengefuehrten Station. Das ist Absicht: nur so ist im Objektbaum nachvollziehbar,
     * WAS eine einzelne Station eigentlich liefert und wie sie sich von der anderen
     * unterscheidet. Eine Zeichenkette voller JSON beantwortet diese Frage nicht.
     *
     * Angelegt wird erst, wenn eine Groesse zum ersten Mal auftaucht: welche das sind, weiss
     * man vor dem ersten Lesen nicht, und leere Variablen fuer alles waeren nur Rauschen.
     */
    private function mirror(Observation $o): void
    {
        Profiles::ensure();
        $pos = 100;
        foreach (array_keys(Observation::QUANTITIES) as $ident) {
            $pos += 10;
            if (!$o->has($ident)) {
                continue;
            }
            [$label, $einheit] = Observation::QUANTITIES[$ident];
            $name = $label . ($einheit !== '' ? ' (' . $einheit . ')' : '');
            $var  = 'q_' . $ident;
            if ($ident === 'strikeTime') {
                $this->RegisterVariableInteger($var, $name, Profiles::forQuantity($ident), $pos);
                $this->SetValue($var, (int) $o->get($ident));
                continue;
            }
            $this->RegisterVariableFloat($var, $name, Profiles::forQuantity($ident), $pos);
            $this->SetValue($var, (float) $o->num($ident));
        }
        if ($this->ReadPropertyBoolean('Logging')) {
            $this->applyLogging($o);
        }
    }

    /**
     * Archiviert die Messreihen DIESER Quelle.
     *
     * Auch das ist bewusst doppelt zur Station: erst getrennte Reihen je Station machen im
     * Nachhinein sichtbar, ob eine Station driftet, aussetzt oder systematisch anders misst
     * als die andere. In der zusammengefuehrten Reihe ist das nicht mehr zu erkennen.
     *
     * Nicht archiviert werden Zaehler und Kennungen (Blitzzeitpunkt, Niederschlagsart der
     * Station, Batteriespannung) — die sind Zustand, keine Messreihe.
     */
    private function applyLogging(Observation $o): void
    {
        static $nicht = ['strikeTime', 'precipType', 'pressureTrend'];
        // Aufsummierte Mengen gehoeren als ZAEHLER ins Archiv: sie steigen bis zum Stichtag und
        // beginnen dann wieder bei null. Als Mittelwert waere die Aggregation die durchschnittliche
        // Fuellhoehe des Zaehlers - eine Zahl ohne Aussage. Als Zaehler ist es die Menge je Zeitraum.
        static $zaehler = ['rainDayMm', 'rainMonthMm', 'rainYearMm', 'etDayMm'];
        $aid = @IPS_GetInstanceListByModuleID('{43192F0B-135B-4CE7-A0A7-1475603F3060}')[0] ?? 0;
        if (!$aid) {
            return;
        }
        foreach ($o->idents() as $ident) {
            if (in_array($ident, $nicht, true)) {
                continue;
            }
            $vid = @$this->GetIDForIdent('q_' . $ident);
            if (!$vid) {
                continue;
            }
            if (!AC_GetLoggingStatus($aid, $vid)) {
                AC_SetLoggingStatus($aid, $vid, true);
            }
            $istZaehler = in_array($ident, $zaehler, true);
            if (AC_GetAggregationType($aid, $vid) !== ($istZaehler ? 1 : 0)) {
                AC_SetAggregationType($aid, $vid, $istZaehler ? 1 : 0);
                if ($istZaehler) {
                    @AC_SetCounterIgnoreZeros($aid, $vid, true);
                }
                @AC_ReAggregateVariable($aid, $vid);
            }
        }
    }

    /** Eigenschaftsname aus Treiberkennung und Feld, ohne Zeichen, die Symcon nicht mag. */
    private static function propName(string $driverId, string $feld): string
    {
        return str_replace('-', '_', $driverId) . '__' . $feld;
    }

    // ==================================================================
    // Formular
    // ==================================================================

    public function GetConfigurationForm()
    {
        $aktiv = $this->ReadPropertyString('Driver');

        $el = [
            ['type' => 'Label', 'caption' =>
                'Eine Wetterquelle: eine Station oder ein Dienst. Wer mehrere hat, legt mehrere '
                . 'Instanzen an — die WeatherStation führt sie je Größe zusammen, statt eine davon '
                . 'auszuwählen. So liefert die genauere Station die Temperatur und die andere die '
                . 'Blitze, ohne dass jemand umschalten muss.'],
            ['type' => 'Select', 'name' => 'Driver', 'caption' => 'Art der Quelle',
             'options' => SourceFactory::options()],
            ['type' => 'RowLayout', 'items' => [
                ['type' => 'NumberSpinner', 'name' => 'Interval', 'caption' => 'Abruf alle (Sekunden, 0 = aus)',
                 'minimum' => 0, 'maximum' => 3600],
                ['type' => 'NumberSpinner', 'name' => 'MaxAge', 'caption' => 'Werte gelten höchstens (Sekunden)',
                 'minimum' => 30, 'maximum' => 86400],
            ]],
            ['type' => 'CheckBox', 'name' => 'Mirror',
             'caption' => 'Gelieferte Werte zusätzlich als eigene Variablen zeigen'],
            ['type' => 'CheckBox', 'name' => 'Logging',
             'caption' => 'Messreihen dieser Quelle archivieren'],
            ['type' => 'Label', 'caption' => 'Die Werte stehen dann doppelt im Baum — einmal hier je '
                . 'Station und einmal zusammengeführt in der WeatherStation. Das ist Absicht: nur so '
                . 'ist nachvollziehbar, was eine einzelne Station wirklich liefert und worin sich zwei '
                . 'Stationen unterscheiden.'],
            ['type' => 'Label', 'caption' => 'Der Abruftakt sollte zur Quelle passen: schneller abzufragen, '
                . 'als die Quelle neue Werte bildet, kostet nur Last. Die Geltungsdauer entscheidet, '
                . 'wann die Zusammenführung eine Quelle für tot hält und auf eine andere ausweicht.'],
        ];

        // Je Treiber ein aufklappbarer Block. Nur der gewaehlte ist offen — die anderen bleiben
        // sichtbar, damit man vor dem Umschalten sieht, was dort einzutragen waere.
        foreach (SourceFactory::all() as $id => $klasse) {
            $items = [];
            foreach ($klasse::fields() as $f) {
                $name = self::propName($id, $f['name']);
                $items[] = $this->formField($name, $f);
                if (!empty($f['hint'])) {
                    $items[] = ['type' => 'Label', 'caption' => $f['hint']];
                }
            }
            if ($items === []) {
                $items[] = ['type' => 'Label', 'caption' => 'Diese Quelle braucht keine Einstellungen.'];
            }
            $el[] = ['type' => 'ExpansionPanel', 'caption' => $klasse::label(),
                     'expanded' => ($id === $aktiv), 'items' => $items];
        }

        $el[] = ['type' => 'Label', 'caption' =>
            'Der Probelauf liest die Quelle einmal und zeigt jeden Wert samt Alter — auch die, '
            . 'die nicht ankommen. Das ist der schnellste Weg, eine falsche Adresse, eine falsche '
            . 'Einheit oder eine eingefrorene Station zu erkennen.'];
        $el[] = ['type' => 'Button', 'caption' => 'Probelauf', 'onClick' => 'echo WXS_TestRead($id);'];

        return json_encode([
            'elements' => $el,
            'status' => [
                ['code' => 102, 'icon' => 'active', 'caption' => 'Quelle eingerichtet'],
                ['code' => 201, 'icon' => 'error',  'caption' => 'Unbekannte Art der Quelle'],
            ],
        ]);
    }

    /** @param array{name:string,caption:string,type:string,default:mixed} $f */
    private function formField(string $prop, array $f): array
    {
        switch ($f['type']) {
            case 'Variable':
                return ['type' => 'SelectVariable', 'name' => $prop, 'caption' => $f['caption']];
            case 'Instance':
                return ['type' => 'SelectInstance', 'name' => $prop, 'caption' => $f['caption']];
            case 'Boolean':
                return ['type' => 'CheckBox', 'name' => $prop, 'caption' => $f['caption']];
            case 'Integer':
                return ['type' => 'NumberSpinner', 'name' => $prop, 'caption' => $f['caption']];
            case 'Float':
                return ['type' => 'NumberSpinner', 'name' => $prop, 'caption' => $f['caption'], 'digits' => 4];
            default:
                return ['type' => 'ValidationTextBox', 'name' => $prop, 'caption' => $f['caption']];
        }
    }
}
