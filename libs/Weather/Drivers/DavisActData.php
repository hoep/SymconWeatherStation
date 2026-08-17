<?php

declare(strict_types=1);

namespace Hoep\Weather\Drivers;

use Hoep\Weather\Engines\Meteo;
use Hoep\Weather\IWeatherSource;
use Hoep\Weather\Observation;

/**
 * DavisActData — liest `actData.txt`, das Textformat der Eusotec-/Eusoport-Steuersoftware
 * fuer Davis-Stationen (Vantage Pro 2 und verwandte).
 *
 * Die Datei ist eine einzige Zeile mit rund 90 durch Semikolon getrennten Feldern und wird
 * typischerweise alle 10 Sekunden neu geschrieben. Drei Eigenheiten, die man kennen muss,
 * sonst rechnet man falsch:
 *
 * - `---` bedeutet "dieser Sensor ist nicht angeschlossen". Das muss NULL werden und
 *   niemals 0 — sonst meldet eine Station ohne Strahlungssensor dauerhaft "bedeckt".
 * - Dezimaltrennzeichen ist das KOMMA.
 * - Der Zeitstempel steht in **UTC**, nicht in Ortszeit. Wer ihn ungeprueft uebernimmt,
 *   haelt bis zu zwei Stunden alte Werte fuer frisch.
 *
 * Einen Taupunkt liefert die Datei nicht; er wird aus Temperatur und Feuchte gerechnet
 * (Magnus-Formel) — das ist eine reine Umrechnung gemessener Groessen, keine Schaetzung.
 *
 * Feldbelegung gegen zwei Quellen geprueft: die Auswerteklasse der Software und den
 * Spaltenkopf der Archivdatei `davis.csv` derselben Installation.
 */
final class DavisActData implements IWeatherSource
{
    private const F_DATUM = 0,  F_ZEIT = 1,  F_DRUCK = 2,  F_T_INNEN = 3, F_RF_INNEN = 4,
                  F_T = 5,      F_WIND = 6,  F_WIND_M = 7, F_WINDRI = 8,  F_RF = 24,
                  F_REGEN_H = 32, F_UV = 33, F_STRAHLUNG = 34, F_REGEN_T = 37,
                  F_REGEN_M = 38, F_REGEN_J = 39, F_ET_T = 40, F_TREND = 51,
                  // Zusatzsensoren: Bodentemperatur 16..19, Bodenfeuchte 43..46,
                  // Blattfeuchte 47..50. Nicht angeschlossene Kanaele stehen auf `---`.
                  F_BODEN_T = 16, F_BODEN_F = 43, F_BLATT_F = 47;

    private string $url = '';
    private string $tz = 'UTC';
    private bool   $imperial = false;
    private int    $timeout = 5;
    private string $fehler = '';

    public static function id(): string
    {
        return 'davis-actdata';
    }

    public static function label(): string
    {
        return 'Davis über actData.txt (Eusotec/Eusoport-Software)';
    }

    public static function fields(): array
    {
        return [
            ['name' => 'Url', 'caption' => 'Adresse der actData.txt', 'type' => 'String',
             'default' => 'http://192.168.1.10/actData.txt',
             'hint' => 'Vollstaendige Adresse, z. B. http://<Rechner-mit-Eusoport>/actData.txt'],
            ['name' => 'Timezone', 'caption' => 'Zeitzone der Zeitstempel in der Datei', 'type' => 'String',
             'default' => 'UTC',
             'hint' => 'Die Eusotec-Software schreibt UTC. Nur aendern, wenn nachweislich Ortszeit drinsteht.'],
            ['name' => 'Imperial', 'caption' => 'Datei in Fahrenheit/Zoll/mph', 'type' => 'Boolean',
             'default' => false,
             'hint' => 'Nur setzen, wenn die Steuersoftware auf angelsaechsische Einheiten steht.'],
            ['name' => 'Timeout', 'caption' => 'Zeitgrenze (Sekunden)', 'type' => 'Integer', 'default' => 5],
        ];
    }

    public function configure(array $config): void
    {
        $this->url      = trim((string) ($config['Url'] ?? ''));
        $this->tz       = trim((string) ($config['Timezone'] ?? 'UTC')) ?: 'UTC';
        $this->imperial = (bool) ($config['Imperial'] ?? false);
        $this->timeout  = max(2, (int) ($config['Timeout'] ?? 5));
    }

    public function lastError(): string
    {
        return $this->fehler;
    }

    public function read(): Observation
    {
        $this->fehler = '';
        if ($this->url === '') {
            $this->fehler = 'keine Adresse konfiguriert';
            return new Observation(self::id());
        }
        $roh = @file_get_contents($this->url, false, stream_context_create([
            'http' => ['timeout' => $this->timeout, 'ignore_errors' => true,
                       'header' => "Connection: close\r\n"],
        ]));
        if ($roh === false || trim($roh) === '') {
            $this->fehler = 'nicht erreichbar: ' . $this->url;
            return new Observation(self::id());
        }
        return $this->parse($roh);
    }

    /** Getrennt vom Lesen, damit sich das Format ohne Netz pruefen laesst. */
    public function parse(string $roh): Observation
    {
        $f = explode(';', trim($roh));
        if (count($f) < 52) {
            $this->fehler = 'unerwartetes Format: nur ' . count($f) . ' Felder';
            return new Observation(self::id());
        }
        $ts = $this->zeitstempel($f[self::F_DATUM] ?? '', $f[self::F_ZEIT] ?? '');
        $o  = new Observation(self::id(), $ts);

        $t  = $this->temp($f, self::F_T);
        $rf = $this->zahl($f, self::F_RF);

        $o->set('tempC', $t, $ts);
        $o->set('humPct', $rf, $ts);
        $o->set('dewC', ($t !== null && $rf !== null) ? Meteo::taupunkt($t, $rf) : null, $ts);
        $o->set('tempInC', $this->temp($f, self::F_T_INNEN), $ts);
        $o->set('humInPct', $this->zahl($f, self::F_RF_INNEN), $ts);
        $o->set('pressureHpa', $this->druck($f, self::F_DRUCK), $ts);
        $o->set('pressureTrend', $this->zahl($f, self::F_TREND), $ts);
        $o->set('windKmh', $this->tempo($f, self::F_WIND), $ts);
        $o->set('windAvgKmh', $this->tempo($f, self::F_WIND_M), $ts);
        $o->set('windDirDeg', $this->zahl($f, self::F_WINDRI), $ts);
        $o->set('rainRateMmH', $this->menge($f, self::F_REGEN_H), $ts);
        $o->set('rainDayMm', $this->menge($f, self::F_REGEN_T), $ts);
        $o->set('rainMonthMm', $this->menge($f, self::F_REGEN_M), $ts);
        $o->set('rainYearMm', $this->menge($f, self::F_REGEN_J), $ts);
        $o->set('etDayMm', $this->menge($f, self::F_ET_T), $ts);
        $o->set('uvIndex', $this->zahl($f, self::F_UV), $ts);
        $o->set('radiationWm2', $this->zahl($f, self::F_STRAHLUNG), $ts);

        // Zusatzsensoren: nur was wirklich angeschlossen ist. Ein nicht belegter Kanal
        // steht auf `---` und wird damit zu null — er taucht dann gar nicht erst auf.
        for ($k = 0; $k < 4; $k++) {
            $o->set('soilTemp' . ($k + 1), $this->temp($f, self::F_BODEN_T + $k), $ts);
            $o->set('soilMoist' . ($k + 1), $this->zahl($f, self::F_BODEN_F + $k), $ts);
            $o->set('leafWet' . ($k + 1), $this->zahl($f, self::F_BLATT_F + $k), $ts);
        }

        if ($o->leer()) {
            $this->fehler = 'Datei gelesen, aber kein einziger Wert brauchbar';
        }
        return $o;
    }

    // ---- Feldzugriff -------------------------------------------------------

    /** `---`, leer oder `&#160;` heisst: Sensor nicht vorhanden. */
    private function zahl(array $f, int $i): ?float
    {
        $v = trim($f[$i] ?? '');
        if ($v === '' || $v === '---' || $v === '&#160;') {
            return null;
        }
        $v = str_replace(',', '.', $v);
        return is_numeric($v) ? (float) $v : null;
    }

    private function temp(array $f, int $i): ?float
    {
        $v = $this->zahl($f, $i);
        return ($v === null || !$this->imperial) ? $v : round(($v - 32) * 5 / 9, 1);
    }

    private function tempo(array $f, int $i): ?float
    {
        $v = $this->zahl($f, $i);
        return ($v === null || !$this->imperial) ? $v : round($v * 1.609344, 1);
    }

    private function menge(array $f, int $i): ?float
    {
        $v = $this->zahl($f, $i);
        return ($v === null || !$this->imperial) ? $v : round($v * 25.4, 2);
    }

    private function druck(array $f, int $i): ?float
    {
        $v = $this->zahl($f, $i);
        return ($v === null || !$this->imperial) ? $v : round($v * 33.8639, 1);
    }

    /** Datum `17.08.2026` und Zeit `08:52:10` in der konfigurierten Zeitzone. */
    private function zeitstempel(string $datum, string $zeit): int
    {
        $datum = trim($datum);
        $zeit  = trim($zeit);
        if ($datum === '' || $zeit === '') {
            return time();
        }
        try {
            $d = \DateTime::createFromFormat('d.m.Y H:i:s', $datum . ' ' . $zeit,
                                             new \DateTimeZone($this->tz));
            if ($d === false) {
                return time();
            }
            $ts = $d->getTimestamp();
            // Ein Zeitstempel aus der Zukunft oder aus dem letzten Jahrhundert bedeutet:
            // die Zeitzone stimmt nicht. Dann lieber die eigene Uhr als eine falsche Angabe.
            return ($ts > time() + 300 || $ts < time() - 86400 * 3) ? time() : $ts;
        } catch (\Throwable $e) {
            return time();
        }
    }
}
