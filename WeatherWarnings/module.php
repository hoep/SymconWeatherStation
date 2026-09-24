<?php

declare(strict_types=1);

/**
 * WeatherWarnings (WXW) — amtliche Wetterwarnungen fuer einen Standort.
 *
 * ZWEI QUELLEN, je nach Land:
 *  · Oesterreich: GeoSphere Austria (vormals ZAMG), die Warnungen hinter warnungen.zamg.at.
 *    Abfrage per Koordinate, Aufloesung je GEMEINDE, Warnungen stundenweise.
 *  · sonst: Meteoalarm (Verbund der europaeischen Wetterdienste), je REGION (EMMA_ID, etwa
 *    IT006 Veneto). Der Feed kommt je Land; mehrere Instanzen desselben Landes teilen sich
 *    einen Abruf (Zwischenspeicher im Temp-Verzeichnis).
 *
 * Beide ohne Konto. Abgerufen wird im eigenen Timer, nie im Hook-Thread.
 *
 * Stufen einheitlich 1 gelb, 2 orange, 3 rot (Meteoalarm "gruen" = keine Warnung, entfaellt).
 * GeoSphere liefert stundenweise - aufeinanderfolgende Warnungen gleicher Art und Stufe
 * werden zu EINER zusammengefasst ("Gewitter gelb 15-17 Uhr" statt zwei Zeilen).
 *
 * Meldung: optional ein Skript (NotifyScript), das fuer jede NEUE Warnung ab NotifyLevel
 * einmal laeuft. Es bekommt WARN_ART, WARN_STUFE (1-3), WARN_STUFE_TEXT, WARN_VON, WARN_BIS,
 * WARN_TEXT und STANDORT in $_IPS. Das Modul selbst verschickt nichts.
 */
class WeatherWarnings extends IPSModule
{
    private const LOCATION_MODULE = '{45E97A63-F870-408A-B259-2933F7EABF74}';
    private const GEOSPHERE = 'https://warnungen.zamg.at/wsapp/api/getWarningsForCoords?lon=%s&lat=%s&lang=de';
    private const METEOALARM = 'https://feeds.meteoalarm.org/api/v1/warnings/feeds-%s';
    private const STUFE = [1 => 'gelb', 2 => 'orange', 3 => 'rot'];
    /** GeoSphere warntypid */
    private const GS_ART = [1 => 'Sturm', 2 => 'Regen', 3 => 'Schnee', 4 => 'Glatteis', 5 => 'Gewitter', 6 => 'Hitze', 7 => 'Kälte'];
    /** Meteoalarm awareness_type */
    private const MA_ART = [1 => 'Sturm', 2 => 'Schnee/Glätte', 3 => 'Gewitter', 4 => 'Nebel', 5 => 'Hitze', 6 => 'Kälte',
                            7 => 'Küstenereignis', 8 => 'Waldbrand', 9 => 'Lawinen', 10 => 'Regen', 12 => 'Hochwasser', 13 => 'Regen/Hochwasser'];
    /** EMMA_ID-Praefix -> Feed-Name bei Meteoalarm */
    private const MA_LAND = ['IT' => 'italy', 'AT' => 'austria', 'DE' => 'germany', 'SI' => 'slovenia', 'HR' => 'croatia',
                             'CH' => 'switzerland', 'FR' => 'france', 'ES' => 'spain', 'GR' => 'greece', 'CZ' => 'czechia',
                             'HU' => 'hungary', 'SK' => 'slovakia', 'PL' => 'poland', 'NL' => 'netherlands', 'BE' => 'belgium'];

    public function Create()
    {
        parent::Create();
        $this->RegisterPropertyFloat('Latitude', 0.0);      // 0 = Standort aus Symcon
        $this->RegisterPropertyFloat('Longitude', 0.0);
        $this->RegisterPropertyString('Provider', 'auto');  // auto | geosphere | meteoalarm
        $this->RegisterPropertyString('Region', '');        // Meteoalarm EMMA_ID, z. B. IT006
        $this->RegisterPropertyInteger('IntervalMin', 10);
        $this->RegisterPropertyInteger('NotifyScript', 0);
        $this->RegisterPropertyInteger('NotifyLevel', 3);

        $this->RegisterAttributeString('Notified', '[]');
        $this->RegisterTimer('Fetch', 0, 'WXW_Update($_IPS[\'TARGET\']);');
    }

    public function ApplyChanges()
    {
        parent::ApplyChanges();
        $this->profile();
        $this->RegisterVariableInteger('Level', 'Warnstufe jetzt', 'WXW.Stufe', 10);
        $this->RegisterVariableString('Text', 'Warnung jetzt', '', 11);
        $this->RegisterVariableInteger('Next', 'Nächste Warnung ab', '~UnixTimestamp', 12);
        $this->RegisterVariableString('Warnings', 'Warnungen (JSON)', '', 20);
        $this->RegisterVariableInteger('Fetched', 'Zuletzt abgerufen', '~UnixTimestamp', 30);
        $this->SetTimerInterval('Fetch', max(5, $this->ReadPropertyInteger('IntervalMin')) * 60000);
        if (IPS_GetKernelRunlevel() === KR_READY) {
            $this->Update();
        }
    }

    private function profile(): void
    {
        if (!IPS_VariableProfileExists('WXW.Stufe')) {
            IPS_CreateVariableProfile('WXW.Stufe', 1);
            IPS_SetVariableProfileValues('WXW.Stufe', 0, 3, 1);
            IPS_SetVariableProfileAssociation('WXW.Stufe', 0, 'keine', '', 0x63757b);
            IPS_SetVariableProfileAssociation('WXW.Stufe', 1, 'gelb', '', 0xf2c744);
            IPS_SetVariableProfileAssociation('WXW.Stufe', 2, 'orange', '', 0xf2903d);
            IPS_SetVariableProfileAssociation('WXW.Stufe', 3, 'rot', '', 0xf2685a);
        }
    }

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

    /** Welche Quelle: ausdruecklich gewaehlt, sonst Oesterreich (grob per Rahmen) -> GeoSphere. */
    private function provider(float $lat, float $lon): string
    {
        $p = $this->ReadPropertyString('Provider');
        if ($p === 'geosphere' || $p === 'meteoalarm') {
            return $p;
        }
        $at = $lat >= 46.35 && $lat <= 49.05 && $lon >= 9.5 && $lon <= 17.2;
        return ($at && $this->ReadPropertyString('Region') === '') ? 'geosphere' : 'meteoalarm';
    }

    private static function holen(string $url): ?string
    {
        $ctx = stream_context_create(['http' => ['timeout' => 20, 'header' => "User-Agent: SymconWeatherWarnings/1.0\r\n", 'ignore_errors' => true]]);
        $s = @file_get_contents($url, false, $ctx);
        $code = 0;
        foreach ((array) ($http_response_header ?? []) as $h) {
            if (preg_match('#^HTTP/\S+\s+(\d+)#', $h, $m)) { $code = (int) $m[1]; }
        }
        return ($s !== false && $code === 200) ? $s : null;
    }

    /** Abruf, zusammenfassen, Variablen setzen, neue Warnungen melden. Laeuft im Timer. */
    public function Update(): void
    {
        [$lat, $lon] = $this->position();
        if ($lat == 0.0 && $lon == 0.0) {
            $this->SetStatus(201);
            return;
        }
        $quelle = $this->provider($lat, $lon);
        $liste = $quelle === 'geosphere' ? $this->geosphere($lat, $lon) : $this->meteoalarm();
        if ($liste === null) {
            $this->SetStatus(202);                          // Abruf fehlgeschlagen: alten Stand behalten
            return;
        }
        $this->SetStatus(102);
        $jetzt = time();
        $heute = mktime(0, 0, 0);
        $liste = array_values(array_filter(self::zusammenfassen($liste), static fn($w) => $w['bis'] >= $heute));
        usort($liste, static fn($a, $b) => [$a['von'], -$a['stufe']] <=> [$b['von'], -$b['stufe']]);

        $aktiv = array_values(array_filter($liste, static fn($w) => $w['von'] <= $jetzt && $w['bis'] > $jetzt));
        $stufe = 0;
        $top = null;
        foreach ($aktiv as $w) {
            if ($w['stufe'] > $stufe) { $stufe = $w['stufe']; $top = $w; }
        }
        $kommend = array_values(array_filter($liste, static fn($w) => $w['von'] > $jetzt));
        $this->setze('Level', $stufe);
        $this->setze('Text', $top ? ($top['art'] . ' · ' . self::STUFE[$top['stufe']] . ' bis ' . self::uhr($top['bis'])) : 'keine Warnung');
        $this->setze('Next', $kommend ? $kommend[0]['von'] : 0);
        $this->setze('Warnings', json_encode($liste, JSON_UNESCAPED_UNICODE));
        $this->SetValue('Fetched', $jetzt);
        $this->melden($liste);
    }

    private function setze(string $ident, $wert): void
    {
        if ($this->GetValue($ident) !== $wert) { $this->SetValue($ident, $wert); }
    }

    private static function uhr(int $t): string
    {
        return date('Y-m-d', $t) === date('Y-m-d') ? date('H:i', $t) : (['So', 'Mo', 'Di', 'Mi', 'Do', 'Fr', 'Sa'][(int) date('w', $t)] . ' ' . date('H:i', $t));
    }

    private function geosphere(float $lat, float $lon): ?array
    {
        $s = self::holen(sprintf(self::GEOSPHERE, rawurlencode((string) $lon), rawurlencode((string) $lat)));
        $j = $s !== null ? json_decode($s, true) : null;
        if (!is_array($j)) {
            return null;
        }
        $out = [];
        foreach ((array) ($j['properties']['warnings'] ?? []) as $w) {
            $p = $w['properties'] ?? [];
            $r = $p['rawinfo'] ?? [];
            $st = (int) ($r['wlevel'] ?? $p['warnstufeid'] ?? 0);
            $typ = (int) ($r['wtype'] ?? $p['warntypid'] ?? 0);
            if ($st < 1 || !isset($r['start'], $r['end'])) { continue; }
            $out[] = ['art' => self::GS_ART[$typ] ?? 'Wetter', 'typ' => 'gs' . $typ, 'stufe' => min(3, $st),
                      'von' => (int) $r['start'], 'bis' => (int) $r['end'],
                      'text' => trim((string) ($p['text'] ?? '')), 'quelle' => 'GeoSphere Austria'];
        }
        return $out;
    }

    private function meteoalarm(): ?array
    {
        $region = strtoupper(trim($this->ReadPropertyString('Region')));
        $land = self::MA_LAND[substr($region, 0, 2)] ?? '';
        if ($region === '' || $land === '') {
            $this->SetStatus(203);                          // Region fehlt oder unbekanntes Land
            return null;
        }
        // Ein Feed je Land, geteilt ueber alle Instanzen: 9 Minuten wiederverwenden.
        $cache = sys_get_temp_dir() . '/wxw-meteoalarm-' . $land . '.json';
        $s = (is_file($cache) && time() - filemtime($cache) < 540) ? (string) @file_get_contents($cache) : null;
        if ($s === null || $s === '') {
            $s = self::holen(sprintf(self::METEOALARM, $land));
            if ($s === null) { return null; }
            @file_put_contents($cache . '.tmp', $s);
            @rename($cache . '.tmp', $cache);
        }
        $j = json_decode($s, true);
        if (!is_array($j)) {
            return null;
        }
        $out = [];
        foreach ((array) ($j['warnings'] ?? []) as $w) {
            $a = $w['alert'] ?? [];
            if (($a['msgType'] ?? '') === 'Cancel' || ($a['status'] ?? 'Actual') !== 'Actual') { continue; }
            $infos = (array) ($a['info'] ?? []);
            if (!$infos) { continue; }
            // Sprache: Deutsch, sonst Englisch, sonst die erste
            $info = null;
            foreach (['de', 'en'] as $sp) {
                foreach ($infos as $i) { if (stripos((string) ($i['language'] ?? ''), $sp) === 0) { $info = $i; break 2; } }
            }
            $info = $info ?? $infos[0];
            $treffer = false;
            foreach ((array) ($info['area'] ?? []) as $ar) {
                foreach ((array) ($ar['geocode'] ?? []) as $g) {
                    if (($g['valueName'] ?? '') === 'EMMA_ID' && strtoupper((string) $g['value']) === $region) { $treffer = true; }
                }
            }
            if (!$treffer) { continue; }
            $lvl = 0;
            $typ = 0;
            foreach ((array) ($info['parameter'] ?? []) as $pa) {
                if (($pa['valueName'] ?? '') === 'awareness_level') { $lvl = (int) $pa['value']; }
                if (($pa['valueName'] ?? '') === 'awareness_type') { $typ = (int) $pa['value']; }
            }
            $st = $lvl - 1;                                 // 2 gelb -> 1, 3 orange -> 2, 4 rot -> 3
            if ($st < 1) { continue; }                      // gruen = keine Warnung
            $von = strtotime((string) ($info['onset'] ?? $info['effective'] ?? ''));
            $bis = strtotime((string) ($info['expires'] ?? ''));
            if (!$von || !$bis) { continue; }
            $out[] = ['art' => self::MA_ART[$typ] ?? (string) ($info['event'] ?? 'Wetter'), 'typ' => 'ma' . $typ, 'stufe' => min(3, $st),
                      'von' => $von, 'bis' => $bis, 'text' => trim((string) ($info['description'] ?? $info['headline'] ?? '')),
                      'quelle' => 'Meteoalarm'];
        }
        return $out;
    }

    /** Aufeinanderfolgende Warnungen gleicher Art und Stufe zu einer zusammenfassen. */
    private static function zusammenfassen(array $l): array
    {
        usort($l, static fn($a, $b) => [$a['typ'], $a['stufe'], $a['von']] <=> [$b['typ'], $b['stufe'], $b['von']]);
        $out = [];
        foreach ($l as $w) {
            $n = count($out) - 1;
            if ($n >= 0 && $out[$n]['typ'] === $w['typ'] && $out[$n]['stufe'] === $w['stufe'] && $w['von'] <= $out[$n]['bis'] + 60) {
                $out[$n]['bis'] = max($out[$n]['bis'], $w['bis']);
                continue;
            }
            $out[] = $w;
        }
        foreach ($out as &$w) {
            $w['id'] = substr(md5($w['typ'] . '|' . $w['stufe'] . '|' . $w['von']), 0, 10);
        }
        return $out;
    }

    /** Jede Warnung ab NotifyLevel genau einmal an das Meldeskript geben. */
    private function melden(array $liste): void
    {
        $sid = $this->ReadPropertyInteger('NotifyScript');
        if ($sid <= 0 || !@IPS_ScriptExists($sid)) {
            return;
        }
        $ab = max(1, min(3, $this->ReadPropertyInteger('NotifyLevel')));
        $schon = json_decode($this->ReadAttributeString('Notified'), true) ?: [];
        $jetzt = time();
        $neu = false;
        foreach ($liste as $w) {
            if ($w['stufe'] < $ab || $w['bis'] <= $jetzt || isset($schon[$w['id']])) { continue; }
            $schon[$w['id']] = $w['bis'];
            $neu = true;
            IPS_RunScriptEx($sid, ['WARN_ART' => $w['art'], 'WARN_STUFE' => (string) $w['stufe'], 'WARN_STUFE_TEXT' => self::STUFE[$w['stufe']],
                                  'WARN_VON' => (string) $w['von'], 'WARN_BIS' => (string) $w['bis'], 'WARN_TEXT' => $w['text'],
                                  'STANDORT' => $this->standort()]);
        }
        $vorher = count($schon);
        $schon = array_filter($schon, static fn($bis) => $bis > $jetzt - 86400);
        if ($neu || count($schon) !== $vorher) {
            $this->WriteAttributeString('Notified', json_encode($schon));
        }
    }

    /** Standortname fuer Meldungen: der Knoten darueber, wenn er eine Instanz ist (HomeSuite-Standort). */
    private function standort(): string
    {
        $p = (int) IPS_GetParent($this->InstanceID);
        if ($p > 0 && @IPS_InstanceExists($p)) {
            return IPS_GetName($p);
        }
        return trim((string) preg_replace('/^Wetterwarnungen\s*/u', '', IPS_GetName($this->InstanceID))) ?: IPS_GetName($this->InstanceID);
    }

    /** Alle Warnungen (heute und kuenftig) als JSON - fuer Skripte und die Lage-Uebersicht. */
    public function GetWarnings(): string
    {
        return (string) $this->GetValue('Warnings');
    }
}
