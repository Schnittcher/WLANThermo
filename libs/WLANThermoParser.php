<?php

declare(strict_types=1);

/**
 * Liest die JSON-Daten des WLANThermo (ESP32 und Nano V1) und bereitet sie fuer das Modul auf.
 * Reine PHP-Logik ohne Symcon-Laufzeit, damit sie sich testen laesst. Das Ergebnis enthaelt nur gueltige, typisierte Werte,
 * fehlende oder ungueltige Felder fehlen im Ergebnis.
 */
final class WLANThermoParser
{
    public const MODES = ['off' => 0, 'manual' => 1, 'auto' => 2, 'autotune' => 3];

    /**
     * Art des Pakets aus dem Topic: data oder settings. Null bei anderen Topics.
     */
    public static function topicType(string $topic, string $host): ?string
    {
        if ($host === '') {
            return null;
        }
        if (preg_match('#^WLanThermo/' . preg_quote($host, '#') . '/status/(data|settings)$#', $topic, $match)) {
            return $match[1];
        }
        return null;
    }

    /**
     * Bei IPSModuleStrict ist der Payload im Datenfluss HEX-kodiert (UTF-8-Text).
     */
    public static function decodePayload(string $hex): ?array
    {
        $json = @hex2bin($hex);
        if ($json === false) {
            return null;
        }
        $decoded = json_decode($json, true);
        return is_array($decoded) ? $decoded : null;
    }

    /**
     * Zerlegt den Ident einer Variable in Kanal- oder Pitmaster-Teil.
     *
     * @return array|null ['kind' => 'channel', 'field' => 'Min', 'number' => 3] oder ['kind' => 'pitmaster', 'field' => 'Mode', 'id' => 0]
     */
    public static function parseIdent(string $ident): ?array
    {
        if (preg_match('/^(Temperature|Min|Max|Alarm|Typ|Name|Color|Fixed|Connected)(\d+)$/', $ident, $m)) {
            return ['kind' => 'channel', 'field' => $m[1], 'number' => (int) $m[2]];
        }
        if (preg_match('/^Pitmaster(\d+)_(Mode|Value|Set|Channel|Pid)$/', $ident, $m)) {
            return ['kind' => 'pitmaster', 'field' => $m[2], 'id' => (int) $m[1]];
        }
        return null;
    }

    /**
     * Die Werte aus status/data.
     *
     * @return array ['system' => [...], 'channels' => [Nummer => [...]], 'pitmasters' => [Id => [...]], 'autotune' => bool]
     */
    public static function data(array $data): array
    {
        $result = ['system' => [], 'channels' => [], 'pitmasters' => [], 'autotune' => false];

        if (is_array($data['system'] ?? null)) {
            $system = $data['system'];
            if (isset($system['charge'])) {
                $result['system']['charge'] = (bool) $system['charge'];
            }
            foreach (['soc', 'rssi', 'online'] as $key) {
                if (isset($system[$key]) && is_numeric($system[$key])) {
                    $result['system'][$key] = (int) $system[$key];
                }
            }
            if (isset($system['unit']) && in_array($system['unit'], ['C', 'F'], true)) {
                $result['system']['unit'] = $system['unit'];
            }
        }

        if (is_array($data['channel'] ?? null)) {
            foreach ($data['channel'] as $channel) {
                if (!is_array($channel) || !isset($channel['number']) || !is_numeric($channel['number']) || (int) $channel['number'] < 1) {
                    continue;
                }
                $values = [];
                foreach (['temp', 'min', 'max'] as $key) {
                    if (isset($channel[$key]) && is_numeric($channel[$key])) {
                        $values[$key] = (float) $channel[$key];
                    }
                }
                foreach (['typ', 'alarm'] as $key) {
                    if (isset($channel[$key]) && is_numeric($channel[$key])) {
                        $values[$key] = (int) $channel[$key];
                    }
                }
                if (isset($channel['name']) && is_string($channel['name'])) {
                    $values['name'] = $channel['name'];
                }
                if (isset($channel['color']) && is_string($channel['color']) && preg_match('/^#[0-9A-Fa-f]{6}$/', $channel['color'])) {
                    $values['color'] = (int) hexdec(substr($channel['color'], 1));
                }
                foreach (['fixed', 'connected'] as $key) {
                    if (isset($channel[$key])) {
                        $values[$key] = (bool) $channel[$key];
                    }
                }
                $result['channels'][(int) $channel['number']] = $values;
            }
        }

        if (is_array($data['pitmaster'] ?? null)) {
            $pitmaster = $data['pitmaster'];
            $result['autotune'] = in_array('autotune', (array) ($pitmaster['type'] ?? []), true);
            foreach ((array) ($pitmaster['pm'] ?? []) as $pm) {
                if (!is_array($pm) || !isset($pm['id']) || !is_numeric($pm['id'])) {
                    continue;
                }
                $values = [];
                if (isset($pm['typ']) && is_string($pm['typ']) && isset(self::MODES[$pm['typ']])) {
                    $values['mode'] = self::MODES[$pm['typ']];
                }
                foreach (['value', 'channel', 'pid'] as $key) {
                    if (isset($pm[$key]) && is_numeric($pm[$key])) {
                        $values[$key] = (int) $pm[$key];
                    }
                }
                if (isset($pm['set']) && is_numeric($pm['set'])) {
                    $values['set'] = (float) $pm['set'];
                }
                $result['pitmasters'][(int) $pm['id']] = $values;
            }
        }

        return $result;
    }

    /**
     * Die Einstellungen aus status/settings, die das Modul speichert. IoT und Benachrichtigungen enthalten Zugangsdaten
     * und die Seriennummer das Geraet eindeutig, sie werden nicht uebernommen.
     */
    public static function settings(array $settings): array
    {
        $keep = [];
        foreach (['device', 'system', 'sensors', 'pid', 'aktor', 'hardware', 'features'] as $key) {
            if (array_key_exists($key, $settings)) {
                $keep[$key] = $settings[$key];
            }
        }
        if (isset($keep['device']) && is_array($keep['device'])) {
            unset($keep['device']['serial']);
        }
        return $keep;
    }

    /**
     * Fuehlertypen als Liste aus [Wert, Name]. Der ESP32 liefert Objekte mit type und name, der Nano V1 eine Liste von Namen.
     *
     * @return array<int, array{0: int, 1: string}>
     */
    public static function sensorOptions(array $settings): array
    {
        $options = [];
        foreach (array_values((array) ($settings['sensors'] ?? [])) as $index => $sensor) {
            if (is_array($sensor)) {
                $options[] = [(int) ($sensor['type'] ?? $index), (string) ($sensor['name'] ?? $index)];
            } else {
                $options[] = [$index, (string) $sensor];
            }
        }
        return $options;
    }

    /**
     * PID-Profile als Liste aus [Id, Name].
     *
     * @return array<int, array{0: int, 1: string}>
     */
    public static function pidOptions(array $settings): array
    {
        $options = [];
        foreach ((array) ($settings['pid'] ?? []) as $profile) {
            if (is_array($profile) && isset($profile['id'])) {
                $options[] = [(int) $profile['id'], (string) ($profile['name'] ?? $profile['id'])];
            }
        }
        return $options;
    }
}
