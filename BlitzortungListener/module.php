<?php

declare(strict_types=1);

/**
 * BlitzortungListener (WXB) — Blitze mit Ort aus dem Blitzortung.org-Netz.
 *
 * Die Tempest am Haus misst je Schlag nur die Entfernung, nicht die Richtung. Das Netz von
 * Blitzortung.org ortet jeden Blitz per Laufzeitmessung vieler Stationen auf wenige hundert
 * Meter genau. Ein Community-Dienst reicht diese Blitze als MQTT weiter, gegliedert nach
 * Geohash - dasselbe, das die Home-Assistant-Integration nutzt. Ohne Konto.
 *
 * DIE LAST bleibt klein, weil das Abo nur die Geohash-Zellen rund um den Standort umfasst,
 * nicht den Weltstrom (dort kommen mehrere Blitze pro Sekunde). ReceiveData haengt jeden
 * Blitz nur an einen Puffer; das Zusammenfassen in Variablen macht ein Timer alle 10 s.
 *
 * Aufbau: Client Socket (blitzortung.ha.sed.pl:1883) -> MQTT Client -> dieses Modul. Die
 * Abos des MQTT Clients (Eigenschaft Subscriptions) setzt dieses Modul selbst, passend zu
 * Standort und Radius.
 *
 * Nutzung der Daten: Blitzortung.org erlaubt sie fuer private, nicht kommerzielle Zwecke.
 */
class BlitzortungListener extends IPSModule
{
    private const LOCATION_MODULE = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const MQTT_CLIENT     = '{F7A0DD2E-7684-95C0-64C2-D2A9DC47577B}';
    private const GEOHASH         = '0123456789bcdefghjkmnpqrstuvwxyz';

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyFloat('Latitude', 0.0);    // 0 = Standort aus Symcon
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->RegisterPropertyInteger('RadiusKm', 100);
        $this->RegisterPropertyInteger('WindowMin', 60);   // so lange bleiben Blitze in der Liste

        $this->RegisterVariableBoolean('Active', 'Gewitter in der Nähe', '~Alert', 10);
        $this->RegisterVariableInteger('Nearest', 'Nächster Blitz (km)', '', 20);
        $this->RegisterVariableInteger('Bearing', 'Richtung (°)', '', 21);
        $this->RegisterVariableString('BearingText', 'Richtung', '', 22);
        $this->RegisterVariableInteger('Count30', 'Blitze (30 min)', '', 30);
        $this->RegisterVariableInteger('LastStrike', 'Letzter Blitz', '~UnixTimestamp', 31);
        $this->RegisterVariableString('Buckets', 'Blitze je 5 min (JSON)', '', 40);
        $this->RegisterVariableString('Strikes', 'Blitze mit Ort (JSON)', '', 41);
        $this->RegisterVariableInteger('Received', 'Empfangene Blitze', '', 50);

        $this->RegisterAttributeString('Ring', '[]');
        $this->RegisterTimer('Update', 10000, 'WXB_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $topics = $this->topics();
        // Nur Nachrichten aus den eigenen Zellen durchlassen (der MQTT Client kann weitere
        // Kinder mit anderen Abos haben). Ohne Standort gar nichts.
        if ($topics) {
            $rx = implode('|', array_map(static fn($t) => preg_quote(rtrim($t, '#')), $topics));
            $this->SetReceiveDataFilter('.*"Topic":"(' . $rx . ').*');
        } else {
            $this->SetReceiveDataFilter('NICHTS_EMPFANGEN');
        }
        $this->syncParent($topics);
        $this->SetTimerInterval('Update', 10000);
        $this->Update();
    }

    /** Standort: eigene Eigenschaft, sonst die Location-Instanz von Symcon. */
    private function position(): array
    {
        $lat = $this->ReadPropertyFloat('Latitude');
        $lon = $this->ReadPropertyFloat('Longitude');
        if ($lat != 0.0 || $lon != 0.0) {
            return [$lat, $lon];
        }
        foreach (IPS_GetInstanceListByModuleID(self::LOCATION_MODULE) as $id) {
            $l = json_decode((string) @IPS_GetProperty($id, 'Location'), true);
            if (is_array($l) && isset($l['latitude'], $l['longitude'])) {
                return [(float) $l['latitude'], (float) $l['longitude']];
            }
        }
        return [0.0, 0.0];
    }

    private static function geohash(float $lat, float $lon, int $prec): string
    {
        $la = [-90.0, 90.0];
        $lo = [-180.0, 180.0];
        $out = '';
        $bit = 0;
        $ch = 0;
        $even = true;
        while (strlen($out) < $prec) {
            if ($even) {
                $m = ($lo[0] + $lo[1]) / 2;
                if ($lon > $m) { $ch |= (16 >> $bit); $lo[0] = $m; } else { $lo[1] = $m; }
            } else {
                $m = ($la[0] + $la[1]) / 2;
                if ($lat > $m) { $ch |= (16 >> $bit); $la[0] = $m; } else { $la[1] = $m; }
            }
            $even = !$even;
            if ($bit < 4) {
                $bit++;
            } else {
                $out .= self::GEOHASH[$ch];
                $bit = 0;
                $ch = 0;
            }
        }
        return $out;
    }

    /**
     * MQTT-Themen fuer alle Geohash-Zellen (Genauigkeit 3, etwa 156 x 110 km), die den
     * Kreis um den Standort beruehren. Der Dienst gliedert je Zeichen eine Ebene:
     * blitzortung/1.1/u/2/d/#.
     */
    private function topics(): array
    {
        [$lat, $lon] = $this->position();
        if ($lat == 0.0 && $lon == 0.0) {
            return [];
        }
        $r = max(10, min(300, $this->ReadPropertyInteger('RadiusKm')));
        $dLat = $r / 111.0;
        $dLon = $r / (111.0 * max(0.2, cos(deg2rad($lat))));
        $zellen = [];
        for ($a = $lat - $dLat; $a <= $lat + $dLat + 1e-9; $a += $dLat / 4) {
            for ($o = $lon - $dLon; $o <= $lon + $dLon + 1e-9; $o += $dLon / 4) {
                $zellen[self::geohash($a, $o, 3)] = true;
            }
        }
        $out = [];
        foreach (array_keys($zellen) as $g) {
            $out[] = 'blitzortung/1.1/' . implode('/', str_split($g)) . '/#';
        }
        sort($out);
        return $out;
    }

    /** Abos des uebergeordneten MQTT Clients angleichen (nur wenn sie abweichen). */
    private function syncParent(array $topics): void
    {
        $p = (int) (IPS_GetInstance($this->InstanceID)['ConnectionID'] ?? 0);
        if ($p <= 0 || !$topics || IPS_GetInstance($p)['ModuleInfo']['ModuleID'] !== self::MQTT_CLIENT) {
            return;
        }
        $soll = array_map(static fn($t) => ['Topic' => $t, 'QoS' => 0], $topics);
        $ist = json_decode((string) @IPS_GetProperty($p, 'Subscriptions'), true);
        if ($ist == $soll) {
            return;
        }
        IPS_SetProperty($p, 'Subscriptions', json_encode($soll));
        IPS_ApplyChanges($p);
        // Der MQTT Client abonniert nur beim Verbindungsaufbau. Damit neue Zellen sofort
        // gelten, den Socket darunter einmal schliessen und wieder oeffnen.
        $io = (int) (IPS_GetInstance($p)['ConnectionID'] ?? 0);
        if ($io > 0 && @IPS_GetProperty($io, 'Open') === true) {
            IPS_SetProperty($io, 'Open', false);
            IPS_ApplyChanges($io);
            IPS_SetProperty($io, 'Open', true);
            IPS_ApplyChanges($io);
        }
    }

    public function ReceiveData($JSONString)
    {
        $d = json_decode($JSONString, true);
        if (!is_array($d) || !isset($d['Payload'])) {
            return '';
        }
        $pl = $d['Payload'];
        $j = json_decode($pl, true);
        if (!is_array($j)) {
            $j = json_decode(mb_convert_encoding($pl, 'ISO-8859-1', 'UTF-8'), true);   // aeltere Kernel liefern Latin-1
        }
        if (!is_array($j) || !isset($j['lat'], $j['lon'], $j['time'])) {
            return '';
        }
        [$lat, $lon] = $this->position();
        $km = self::distanz($lat, $lon, (float) $j['lat'], (float) $j['lon']);
        if ($km > $this->ReadPropertyInteger('RadiusKm')) {
            return '';                                         // Zelle beruehrt den Kreis, der Blitz liegt ausserhalb
        }
        $t = (float) $j['time'] / 1e9;                         // Nanosekunden
        $puffer = $this->GetBuffer('Neu');
        $puffer .= json_encode(['t' => round($t, 1), 'd' => round($km, 1), 'b' => (int) round(self::richtung($lat, $lon, (float) $j['lat'], (float) $j['lon']))]) . "\n";
        if (strlen($puffer) < 200000) {
            $this->SetBuffer('Neu', $puffer);
        }
        return '';
    }

    /** Puffer uebernehmen, alte Blitze verwerfen, Variablen rechnen. Laeuft alle 10 s. */
    public function Update(): void
    {
        $ring = json_decode($this->ReadAttributeString('Ring'), true);
        if (!is_array($ring)) {
            $ring = [];
        }
        $neu = $this->GetBuffer('Neu');
        $this->SetBuffer('Neu', '');
        $zugang = 0;
        foreach (explode("\n", trim($neu)) as $z) {
            $x = json_decode($z, true);
            if (is_array($x)) {
                $ring[] = $x;
                $zugang++;
            }
        }
        $jetzt = microtime(true);
        $fenster = max(30, $this->ReadPropertyInteger('WindowMin')) * 60;
        $vorher = count($ring);
        $ring = array_values(array_filter($ring, static fn($x) => $jetzt - $x['t'] <= $fenster));
        usort($ring, static fn($a, $b) => $a['t'] <=> $b['t']);
        if (count($ring) > 3000) {
            $ring = array_slice($ring, -3000);
        }
        if ($zugang || count($ring) !== $vorher) {
            $this->WriteAttributeString('Ring', json_encode($ring));
        }
        if ($zugang) {
            $this->SetValue('Received', $this->GetValue('Received') + $zugang);
        }

        $r30 = array_filter($ring, static fn($x) => $jetzt - $x['t'] <= 1800);
        $r10 = array_filter($ring, static fn($x) => $jetzt - $x['t'] <= 600);
        $this->setIfChanged('Count30', count($r30));
        $this->setIfChanged('Active', count($r30) > 0);
        if ($ring) {
            $this->setIfChanged('LastStrike', (int) end($ring)['t']);
        }
        // Naechster Blitz und Richtung aus den letzten 10 Minuten (sonst den letzten 30):
        // die Richtung als Kreismittel, gewichtet mit der Naehe - damit zeigt der Pfeil auf
        // den Kern, nicht auf verstreute Einzelschlaege am Rand.
        $basis = $r10 ?: $r30;
        if ($basis) {
            $min = min(array_column($basis, 'd'));
            $sx = 0.0;
            $sy = 0.0;
            foreach ($basis as $x) {
                $g = 1.0 / max(1.0, $x['d']);
                $sx += $g * sin(deg2rad($x['b']));
                $sy += $g * cos(deg2rad($x['b']));
            }
            $deg = (int) round(fmod(rad2deg(atan2($sx, $sy)) + 360.0, 360.0));
            $this->setIfChanged('Nearest', (int) round($min));
            $this->setIfChanged('Bearing', $deg);
            $this->setIfChanged('BearingText', $deg . '° ' . self::himmel($deg));
        } else {
            $this->setIfChanged('BearingText', '–');
        }
        // Balken: sechs Fuenf-Minuten-Faecher, aeltestes links
        $faecher = [];
        for ($i = 6; $i >= 1; $i--) {
            $von = $jetzt - $i * 300;
            $bis = $von + 300;
            $n = count(array_filter($ring, static fn($x) => $x['t'] > $von && $x['t'] <= $bis));
            $faecher[] = ['-' . ($i * 5) . ' min', $n];
        }
        $this->setIfChanged('Buckets', json_encode($faecher));
        // Punkte fuer Polar-Darstellung: Richtung, Entfernung, Alter in Minuten (30 min)
        $pkt = array_map(static fn($x) => [$x['b'], $x['d'], (int) floor(($jetzt - $x['t']) / 60)], array_values($r30));
        if (count($pkt) > 400) {
            $pkt = array_slice($pkt, -400);
        }
        $this->setIfChanged('Strikes', json_encode($pkt));
    }

    private function setIfChanged(string $ident, $wert): void
    {
        if ($this->GetValue($ident) !== $wert) {
            $this->SetValue($ident, $wert);
        }
    }

    private static function distanz(float $a1, float $o1, float $a2, float $o2): float
    {
        $p1 = deg2rad($a1);
        $p2 = deg2rad($a2);
        $h = sin(($p2 - $p1) / 2) ** 2 + cos($p1) * cos($p2) * sin(deg2rad($o2 - $o1) / 2) ** 2;
        return 6371.0 * 2 * asin(min(1.0, sqrt($h)));
    }

    /** Anfangskurs vom Standort zum Blitz, 0 = Nord, 90 = Ost. */
    private static function richtung(float $a1, float $o1, float $a2, float $o2): float
    {
        $p1 = deg2rad($a1);
        $p2 = deg2rad($a2);
        $dl = deg2rad($o2 - $o1);
        $y = sin($dl) * cos($p2);
        $x = cos($p1) * sin($p2) - sin($p1) * cos($p2) * cos($dl);
        return fmod(rad2deg(atan2($y, $x)) + 360.0, 360.0);
    }

    private static function himmel(int $deg): string
    {
        $n = ['N', 'NNO', 'NO', 'ONO', 'O', 'OSO', 'SO', 'SSO', 'S', 'SSW', 'SW', 'WSW', 'W', 'WNW', 'NW', 'NNW'];
        return $n[(int) round($deg / 22.5) % 16];
    }

    /** Die Blitze der letzten Stunde als JSON [{t, d, b}] - fuer Skripte. */
    public function GetStrikes(): string
    {
        return $this->ReadAttributeString('Ring');
    }
}
