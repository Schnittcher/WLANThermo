<?php

declare(strict_types=1);

require_once __DIR__ . '/../libs/WLANThermoParser.php';

class WLANThermo extends IPSModuleStrict
{
    private const MQTT_DATA_ID = '{043EA491-0325-4ADD-8FC2-A30C8EEB4D3F}';
    private const MQTT_PARENT = '{C6D2AEB3-6E1F-4B2E-8E69-3A1A00246850}';
    //Schema 1: Idents ohne Praefix, Schema 2: native Darstellungen statt Variablenprofile
    private const SCHEMA_IDENTS = 1;
    private const SCHEMA_PRESENTATIONS = 2;
    private const OLD_IDENT_PREFIX = 'WLANThermoa_';
    private const MODES = WLANThermoParser::MODES;

    //Darstellungen (VARIABLE_PRESENTATION_*), als GUID, damit sie ohne Konstanten der Symcon-Version lesbar sind
    private const PRESENTATION_VALUE = '{3319437D-7CDE-699D-750A-3C6A3841FA75}';
    private const PRESENTATION_ENUMERATION = '{52D9E126-D7D2-2CBB-5E62-4CF7BA7C5D82}';
    private const PRESENTATION_SLIDER = '{6B9CAEEC-5958-C223-30F7-BD36569FC57A}';
    private const PRESENTATION_INPUT = '{6F477326-1683-A2FD-D2E7-477F366ECB62}';
    private const PRESENTATION_TEXTBOX = '{56696857-92B2-1780-16B8-EB6F09D4AEF7}';
    private const PRESENTATION_COLOR = '{05CC3CC2-A0B2-5837-A4A7-A07EA0B9DDFB}';

    public function Create(): void
    {
        //Never delete this line!
        parent::Create();
        $this->ConnectParent(self::MQTT_PARENT);

        $this->RegisterPropertyString('MQTTTopic', '');
        $this->RegisterPropertyBoolean('HideDisconnected', false);

        $this->RegisterAttributeInteger('SchemaVersion', 0);
        $this->RegisterAttributeString('Settings', '{}');
        $this->RegisterAttributeString('Unit', 'C');
        $this->RegisterAttributeBoolean('Autotune', false);

        $this->SetVisualizationType(defined('INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN') ? INSTANCE_VISUALIZATION_TYPE_HTML_FULLSCREEN : INSTANCE_VISUALIZATION_TYPE_HTML);
    }

    public function ApplyChanges(): void
    {
        //Never delete this line!
        parent::ApplyChanges();
        $this->ConnectParent(self::MQTT_PARENT);

        $this->migrateIdents();

        $this->ensureVariable('Charge', $this->Translate('Charge'), VARIABLETYPE_BOOLEAN, false);
        $this->ensureVariable('SOC', $this->Translate('SOC'), VARIABLETYPE_INTEGER, false);
        $this->ensureVariable('RSSI', $this->Translate('RSSI'), VARIABLETYPE_INTEGER, false);

        $this->migratePresentations();

        $topic = $this->topic();
        if ($topic === '') {
            //Filter, der nichts durchlaesst
            $this->SetReceiveDataFilter('^$');
            $this->SetStatus(104);
            return;
        }

        $this->SetReceiveDataFilter('.*' . preg_quote($topic) . '.*');
        $this->SetStatus(102);
        $this->updateTile();

        if ($this->HasActiveParent()) {
            $this->getSettings();
            $this->getData();
        }
    }

    public function ReceiveData(string $JSONString): string
    {
        $Data = json_decode($JSONString, true);
        if (!is_array($Data) || !isset($Data['Topic'], $Data['Payload']) || $this->topic() === '') {
            return '';
        }

        $type = WLANThermoParser::topicType((string) $Data['Topic'], $this->topic());
        if ($type === null) {
            return '';
        }
        $this->SendDebug('Topic', $Data['Topic'], 0);

        //Bei IPSModuleStrict ist der Payload im Datenfluss HEX-kodiert (UTF-8-Text), das Topic bleibt Klartext
        $Decoded = WLANThermoParser::decodePayload((string) $Data['Payload']);
        if ($Decoded === null) {
            $this->SendDebug('Payload', 'Kein gueltiger HEX-Payload oder kein JSON', 0);
            return '';
        }

        if ($type === 'data') {
            //Die Settings enthalten Zugangsdaten und werden deshalb nicht ausgegeben
            $this->SendDebug('Payload', json_encode($Decoded), 0);
            $this->processData($Decoded);
        } else {
            $this->processSettings($Decoded);
        }
        return '';
    }

    public function RequestAction(string $Ident, mixed $Value): void
    {
        $part = WLANThermoParser::parseIdent($Ident);
        if ($part !== null && $part['kind'] === 'channel' && in_array($part['field'], ['Min', 'Max', 'Alarm', 'Typ', 'Name', 'Color'], true)) {
            $channel = $part['number'];
            switch ($part['field']) {
                case 'Min':
                    $this->setMin($channel, (float) $Value);
                    break;
                case 'Max':
                    $this->setMax($channel, (float) $Value);
                    break;
                case 'Alarm':
                    $this->setAlarm($channel, (int) $Value);
                    break;
                case 'Typ':
                    $this->setTyp($channel, (int) $Value);
                    break;
                case 'Name':
                    $this->setName($channel, (string) $Value);
                    break;
                case 'Color':
                    $this->setColor($channel, (int) $Value);
                    break;
            }
            return;
        }

        if ($part !== null && $part['kind'] === 'pitmaster') {
            $id = $part['id'];
            switch ($part['field']) {
                case 'Mode':
                    $this->setPitmasterMode($id, (int) $Value);
                    break;
                case 'Value':
                    $this->setPitmasterValue($id, (int) $Value);
                    break;
                case 'Set':
                    $this->setPitmasterSet($id, (float) $Value);
                    break;
                case 'Channel':
                    $this->setPitmasterChannel($id, (int) $Value);
                    break;
                case 'Pid':
                    $this->setPitmasterPID($id, (int) $Value);
                    break;
            }
            return;
        }

        switch ($Ident) {
            case 'TileSetChannel':
                $this->tileSetChannel((string) $Value);
                break;
            case 'TilePitmasterMode':
                $this->tilePitmasterMode((string) $Value);
                break;
            case 'TilePitmaster':
                $this->tilePitmaster((string) $Value);
                break;
            case 'ApplySystem':
                $this->applySystemForm((string) $Value);
                break;
            case 'PIDSelect':
                $this->selectPIDProfile((int) $Value);
                break;
            case 'ApplyPID':
                $this->applyPIDForm((string) $Value);
                break;
            default:
                $this->SendDebug('RequestAction', 'Unbekannter Ident: ' . $Ident, 0);
        }
    }

    public function GetConfigurationForm(): string
    {
        $form = json_decode((string) file_get_contents(__DIR__ . '/form.json'), true);
        $settings = json_decode($this->ReadAttributeString('Settings'), true);
        if (!is_array($form)) {
            return '{}';
        }
        if (!is_array($settings) || $settings === []) {
            return json_encode($form);
        }

        $system = is_array($settings['system'] ?? null) ? $settings['system'] : [];
        $device = is_array($settings['device'] ?? null) ? $settings['device'] : [];

        $info = trim(($device['device'] ?? '') . ' ' . ($device['sw_version'] ?? ($system['version'] ?? '')));
        if ($info !== '') {
            $this->formSet($form['actions'], 'DeviceInfo', 'caption', $this->Translate('Device') . ': ' . $info);
        }
        foreach (['ap' => 'SysAp', 'host' => 'SysHost', 'language' => 'SysLanguage', 'unit' => 'SysUnit', 'autoupd' => 'SysAutoupd', 'prerelease' => 'SysPrerelease'] as $key => $field) {
            if (array_key_exists($key, $system)) {
                $this->formSet($form['actions'], $field, 'value', $system[$key]);
                $this->formSet($form['actions'], $field, 'visible', true);
            } else {
                $this->formSet($form['actions'], $field, 'visible', false);
            }
        }

        $options = [];
        foreach ((array) ($settings['pid'] ?? []) as $profile) {
            if (is_array($profile) && isset($profile['id'])) {
                $options[] = ['caption' => ($profile['name'] ?? '') . ' (' . $profile['id'] . ')', 'value' => (int) $profile['id']];
            }
        }
        $this->formSet($form['actions'], 'PIDId', 'options', $options);

        $aktors = [];
        foreach (array_values((array) ($settings['aktor'] ?? [])) as $index => $name) {
            $aktors[] = ['caption' => (string) $name, 'value' => $index];
        }
        $this->formSet($form['actions'], 'PIDAktor', 'options', $aktors);

        return json_encode($form);
    }

    public function GetVisualizationTile(): string
    {
        return (string) file_get_contents(__DIR__ . '/module.html')
            . '<script>handleMessage(' . json_encode(json_encode($this->tileData())) . ');</script>';
    }

    public function getData(): void
    {
        $this->publish('get/data', '');
    }

    public function getSettings(): void
    {
        $this->publish('get/settings', '');
    }

    public function setAlarm(int $channel, int $value): void
    {
        if ($value < 0 || $value > 3) {
            trigger_error('Alarm must be between 0 and 3', E_USER_WARNING);
            return;
        }
        $this->setChannel($channel, 'alarm', $value, 'Alarm' . $channel);
    }

    public function setMin(int $channel, float $value): void
    {
        if ($value < -30 || $value > 999.9) {
            trigger_error('Min must be between -30 and 999.9', E_USER_WARNING);
            return;
        }
        $this->setChannel($channel, 'min', $value, 'Min' . $channel);
    }

    public function setMax(int $channel, float $value): void
    {
        if ($value < -30 || $value > 999.9) {
            trigger_error('Max must be between -30 and 999.9', E_USER_WARNING);
            return;
        }
        $this->setChannel($channel, 'max', $value, 'Max' . $channel);
    }

    public function setTyp(int $channel, int $value): void
    {
        $this->setChannel($channel, 'typ', $value, 'Typ' . $channel);
    }

    public function setName(int $channel, string $value): void
    {
        if (mb_strlen($value) > 10) {
            trigger_error('Name must not be longer than 10 characters', E_USER_WARNING);
            return;
        }
        $this->setChannel($channel, 'name', $value, 'Name' . $channel);
    }

    public function setColor(int $channel, int $value): void
    {
        $this->setChannel($channel, 'color', sprintf('#%06X', $value & 0xFFFFFF), 'Color' . $channel);
    }

    public function setPitmasterMode(int $id, int $mode): void
    {
        $name = array_search($mode, self::MODES, true);
        if ($name === false) {
            trigger_error('Unknown pitmaster mode', E_USER_WARNING);
            return;
        }
        $this->setPitmaster($id, 'typ', $name, 'Pitmaster' . $id . '_Mode', $mode);
    }

    public function setPitmasterValue(int $id, int $value): void
    {
        if ($value < 0 || $value > 100) {
            trigger_error('Value must be between 0 and 100', E_USER_WARNING);
            return;
        }
        $this->setPitmaster($id, 'value', $value, 'Pitmaster' . $id . '_Value', $value);
    }

    public function setPitmasterSet(int $id, float $value): void
    {
        if ($value < 0 || $value > 999.9) {
            trigger_error('Set must be between 0 and 999.9', E_USER_WARNING);
            return;
        }
        $this->setPitmaster($id, 'set', $value, 'Pitmaster' . $id . '_Set', $value);
    }

    public function setPitmasterChannel(int $id, int $channel): void
    {
        $this->setPitmaster($id, 'channel', $channel, 'Pitmaster' . $id . '_Channel', $channel);
    }

    public function setPitmasterPID(int $id, int $pid): void
    {
        $this->setPitmaster($id, 'pid', $pid, 'Pitmaster' . $id . '_Pid', $pid);
    }

    /**
     * JSON-Objekt mit den zu aendernden Schluesseln: ap, host, language, unit, autoupd, prerelease.
     */
    public function setSystem(string $Settings): void
    {
        $input = json_decode($Settings, true);
        if (!is_array($input)) {
            trigger_error('Settings must be a JSON object', E_USER_WARNING);
            return;
        }

        $payload = [];
        foreach ($input as $key => $value) {
            switch ($key) {
                case 'ap':
                case 'host':
                    if (!is_string($value) || $value === '' || mb_strlen($value) > 13) {
                        trigger_error($key . ' must be 1 to 13 characters', E_USER_WARNING);
                        return;
                    }
                    $payload[$key] = $value;
                    break;
                case 'language':
                    if (!in_array($value, ['de', 'en'], true)) {
                        trigger_error('language must be de or en', E_USER_WARNING);
                        return;
                    }
                    $payload[$key] = $value;
                    break;
                case 'unit':
                    if (!in_array($value, ['C', 'F'], true)) {
                        trigger_error('unit must be C or F', E_USER_WARNING);
                        return;
                    }
                    $payload[$key] = $value;
                    break;
                case 'autoupd':
                case 'prerelease':
                    $payload[$key] = (bool) $value;
                    break;
                default:
                    trigger_error('Unknown key: ' . $key, E_USER_WARNING);
                    return;
            }
        }
        if ($payload === []) {
            return;
        }
        if (isset($payload['host'])) {
            $this->LogMessage($this->Translate('The host name changes the MQTT topic. Adjust the instance afterwards.'), KL_WARNING);
        }
        if ($this->publish('set/system', json_encode($payload))) {
            $this->getSettings();
        }
    }

    /**
     * JSON-Array mit PID-Profilen. Jedes Profil braucht id, weitere Schluessel sind optional.
     */
    public function setPID(string $Profiles): void
    {
        $input = json_decode($Profiles, true);
        if (!is_array($input)) {
            trigger_error('Profiles must be a JSON array', E_USER_WARNING);
            return;
        }
        if (array_keys($input) !== range(0, count($input) - 1)) {
            //einzelnes Objekt
            $input = [$input];
        }

        $types = [
            'name'   => 'string',
            'id'     => 'int',
            'aktor'  => 'int',
            'Kp'     => 'float',
            'Ki'     => 'float',
            'Kd'     => 'float',
            'DCmmin' => 'int',
            'DCmmax' => 'int',
            'opl'    => 'bool',
            'tune'   => 'bool',
            'jp'     => 'int',
            'SPmin'  => 'int',
            'SPmax'  => 'int',
            'link'   => 'int',
        ];

        $payload = [];
        foreach ($input as $profile) {
            if (!is_array($profile) || !isset($profile['id'])) {
                trigger_error('Each profile needs an id', E_USER_WARNING);
                return;
            }
            $entry = [];
            foreach ($profile as $key => $value) {
                if (!isset($types[$key])) {
                    trigger_error('Unknown key: ' . $key, E_USER_WARNING);
                    return;
                }
                switch ($types[$key]) {
                    case 'int':
                        $entry[$key] = (int) $value;
                        break;
                    case 'float':
                        $entry[$key] = (float) $value;
                        break;
                    case 'bool':
                        $entry[$key] = (bool) $value;
                        break;
                    default:
                        $entry[$key] = (string) $value;
                }
            }
            $payload[] = $entry;
        }
        if ($this->publish('set/pid', json_encode($payload))) {
            $this->getSettings();
        }
    }

    private function updateTile(): void
    {
        $this->UpdateVisualizationValue(json_encode($this->tileData()));
    }

    private function tileData(): array
    {
        $ids = [];
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $ids[IPS_GetObject($id)['ObjectIdent']] = $id;
        }
        $value = function (string $ident, mixed $default = null) use ($ids): mixed
        {
            return isset($ids[$ident]) ? GetValue($ids[$ident]) : $default;
        };

        $numbers = [];
        foreach (array_keys($ids) as $ident) {
            if (preg_match('/^(Temperature|Min|Max|Name)(\d+)$/', $ident, $m)) {
                $numbers[(int) $m[2]] = true;
            }
        }
        ksort($numbers);

        $hideDisconnected = $this->ReadPropertyBoolean('HideDisconnected');
        $channels = [];
        foreach (array_keys($numbers) as $n) {
            $color = $value('Color' . $n);
            $temp = $value('Temperature' . $n);
            $connected = (bool) $value('Connected' . $n, true);
            if ($hideDisconnected && (!$connected || $temp === null)) {
                continue;
            }
            $channels[] = [
                'number'    => $n,
                'name'      => (string) $value('Name' . $n, $this->Translate('Channel') . ' ' . $n),
                'color'     => $color === null ? '#888780' : sprintf('#%06X', (int) $color),
                'temp'      => $temp === null ? null : (float) $temp,
                'min'       => (float) $value('Min' . $n, 0),
                'max'       => (float) $value('Max' . $n, 0),
                'alarm'     => (int) $value('Alarm' . $n, 0),
                'connected' => $connected,
            ];
        }

        $pidNames = [];
        foreach (WLANThermoParser::pidOptions($this->settings()) as $option) {
            $pidNames[$option[0]] = $option[1];
        }

        $pitmasters = [];
        foreach (array_keys($ids) as $ident) {
            if (!preg_match('/^Pitmaster(\d+)_Mode$/', $ident, $m)) {
                continue;
            }
            $id = (int) $m[1];
            $channel = (int) $value('Pitmaster' . $id . '_Channel', 0);
            $pitmasters[] = [
                'id'          => $id,
                'mode'        => (int) $value($ident, 0),
                'set'         => (float) $value('Pitmaster' . $id . '_Set', 0),
                'value'       => (int) $value('Pitmaster' . $id . '_Value', 0),
                'channelName' => $channel > 0 ? (string) $value('Name' . $channel, $this->Translate('Channel') . ' ' . $channel) : '',
                'pid'         => $pidNames[(int) $value('Pitmaster' . $id . '_Pid', -1)] ?? '',
            ];
        }
        usort($pitmasters, fn (array $a, array $b): int => $a['id'] <=> $b['id']);

        return [
            'unit'       => $this->ReadAttributeString('Unit'),
            'system'     => [
                //Ohne Akku und ohne Empfang bleiben SOC und RSSI auf 0 und werden nicht angezeigt
                'soc'    => (int) $value('SOC', 0) > 0 ? (int) $value('SOC', 0) : null,
                'charge' => (bool) $value('Charge', false),
                'rssi'   => (int) $value('RSSI', 0) !== 0 ? (int) $value('RSSI', 0) : null,
                'online' => isset($ids['Online']) ? (int) GetValue($ids['Online']) : null,
            ],
            'channels'   => $channels,
            'pitmasters' => $pitmasters,
            'autotune'   => $this->ReadAttributeBoolean('Autotune'),
            'texts'      => [
                'notConnected' => $this->Translate('Not connected'),
                'settings'     => $this->Translate('Settings'),
                'min'          => $this->Translate('Min'),
                'max'          => $this->Translate('Max'),
                'alarm'        => $this->Translate('Alarm'),
                'alarm0'       => $this->Translate('Off'),
                'alarm1'       => $this->Translate('Push'),
                'alarm2'       => $this->Translate('Buzzer'),
                'alarm3'       => $this->Translate('Push-Buzzer'),
                'above'        => $this->Translate('Above max'),
                'below'        => $this->Translate('Below min'),
                'off'          => $this->Translate('Off'),
                'manual'       => $this->Translate('Manual'),
                'auto'         => $this->Translate('Auto'),
                'autotune'     => $this->Translate('Autotune'),
                'pitmaster'    => $this->Translate('Pitmaster'),
                'target'       => $this->Translate('Target temperature'),
                'value'        => $this->Translate('Control value'),
                'cancel'       => $this->Translate('Cancel'),
                'save'         => $this->Translate('Save'),
                'cloud'        => $this->Translate('Online'),
                'charging'     => $this->Translate('Charging'),
                'empty'        => $this->Translate('No channels received yet'),
            ],
        ];
    }

    private function tileSetChannel(string $json): void
    {
        $in = json_decode($json, true);
        if (!is_array($in) || !isset($in['channel'])) {
            return;
        }
        $n = (int) $in['channel'];
        if (isset($in['min']) && $this->identExists('Min' . $n) && abs((float) $in['min'] - (float) $this->GetValue('Min' . $n)) > 0.001) {
            $this->setMin($n, (float) $in['min']);
        }
        if (isset($in['max']) && $this->identExists('Max' . $n) && abs((float) $in['max'] - (float) $this->GetValue('Max' . $n)) > 0.001) {
            $this->setMax($n, (float) $in['max']);
        }
        if (isset($in['alarm']) && $this->identExists('Alarm' . $n) && (int) $in['alarm'] !== (int) $this->GetValue('Alarm' . $n)) {
            $this->setAlarm($n, (int) $in['alarm']);
        }
    }

    private function tilePitmasterMode(string $json): void
    {
        $in = json_decode($json, true);
        if (is_array($in) && isset($in['id'], $in['mode'])) {
            $this->setPitmasterMode((int) $in['id'], (int) $in['mode']);
        }
    }

    private function tilePitmaster(string $json): void
    {
        $in = json_decode($json, true);
        if (!is_array($in) || !isset($in['id'])) {
            return;
        }
        $id = (int) $in['id'];
        if (isset($in['set'])) {
            $this->setPitmasterSet($id, (float) $in['set']);
        }
        if (isset($in['value'])) {
            $this->setPitmasterValue($id, (int) $in['value']);
        }
    }

    private function topic(): string
    {
        //Waehrend eines Modul-Reloads kann ein Paket eintreffen, bevor die Properties registriert sind
        $topic = $this->ReadPropertyString('MQTTTopic');
        return is_string($topic) ? trim($topic) : '';
    }

    private function identExists(string $ident): bool
    {
        return @IPS_GetObjectIDByIdent($ident, $this->InstanceID) !== false;
    }

    /**
     * Darstellung einer Variable nach ihrem Ident, oder null, wenn die Variable keine eigene Darstellung braucht.
     * Hängt nur von Ident, Einheit und den Listen aus den Einstellungen des Geräts ab.
     */
    private function presentationFor(string $ident): ?array
    {
        $temperature = ' °' . $this->ReadAttributeString('Unit');

        if (preg_match('/^Temperature\d+$/', $ident)) {
            return ['PRESENTATION' => self::PRESENTATION_VALUE, 'DIGITS' => 1, 'SUFFIX' => $temperature];
        }
        if (preg_match('/^(Min|Max)\d+$|^Pitmaster\d+_Set$/', $ident)) {
            return ['PRESENTATION' => self::PRESENTATION_INPUT, 'SUFFIX' => $temperature];
        }
        if (preg_match('/^Alarm\d+$/', $ident)) {
            return $this->enumeration([
                [0, $this->Translate('Off'), 0xC8C8C8],
                [1, $this->Translate('Push'), 0xFA9EC8],
                [2, $this->Translate('Buzzer'), 0xF05656],
                [3, $this->Translate('Push-Buzzer'), 0xE00B0B],
            ]);
        }
        if (preg_match('/^Typ\d+$/', $ident)) {
            return $this->enumeration(array_map(fn (array $option): array => [$option[0], $option[1], -1], WLANThermoParser::sensorOptions($this->settings())));
        }
        if (preg_match('/^Pitmaster\d+_Pid$/', $ident)) {
            return $this->enumeration(array_map(fn (array $option): array => [$option[0], $option[1], -1], WLANThermoParser::pidOptions($this->settings())));
        }
        if (preg_match('/^Pitmaster\d+_Mode$/', $ident)) {
            $options = [
                [0, $this->Translate('Off'), 0xC8C8C8],
                [1, $this->Translate('Manual'), 0x1E90FF],
                [2, $this->Translate('Auto'), 0x00C800],
            ];
            if ($this->ReadAttributeBoolean('Autotune')) {
                $options[] = [3, $this->Translate('Autotune'), 0xFFC800];
            }
            return $this->enumeration($options);
        }
        if (preg_match('/^Pitmaster\d+_Value$/', $ident)) {
            return ['PRESENTATION' => self::PRESENTATION_SLIDER, 'MIN' => 0, 'MAX' => 100, 'STEP_SIZE' => 1, 'SUFFIX' => ' %'];
        }
        if (preg_match('/^Pitmaster\d+_Channel$/', $ident)) {
            return ['PRESENTATION' => self::PRESENTATION_INPUT];
        }
        if (preg_match('/^Name\d+$/', $ident)) {
            return ['PRESENTATION' => self::PRESENTATION_TEXTBOX];
        }
        if (preg_match('/^Color\d+$/', $ident)) {
            return ['PRESENTATION' => self::PRESENTATION_COLOR];
        }
        if (preg_match('/^(Fixed|Connected)\d+$/', $ident) || $ident === 'Charge') {
            return [
                'PRESENTATION' => self::PRESENTATION_VALUE,
                'OPTIONS'      => json_encode([
                    ['Value' => false, 'Caption' => $this->Translate('No'), 'IconValue' => '', 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false],
                    ['Value' => true, 'Caption' => $this->Translate('Yes'), 'IconValue' => '', 'IconActive' => false, 'ColorActive' => false, 'ContentColorActive' => false],
                ]),
            ];
        }
        if ($ident === 'SOC') {
            return ['PRESENTATION' => self::PRESENTATION_VALUE, 'DIGITS' => 0, 'SUFFIX' => ' %'];
        }
        if ($ident === 'RSSI') {
            return ['PRESENTATION' => self::PRESENTATION_VALUE, 'DIGITS' => 0, 'SUFFIX' => ' dBm'];
        }
        if ($ident === 'Online') {
            $intervals = [];
            foreach ([[0, $this->Translate('Not connected'), 0xF05656], [1, $this->Translate('Standby'), 0xFFC800], [2, $this->Translate('Connected'), 0x00C800]] as $state) {
                $intervals[] = [
                    'IntervalMinValue' => $state[0],
                    'IntervalMaxValue' => $state[0],
                    'ConstantActive'   => true,
                    'ConstantValue'    => $state[1],
                    'ColorActive'      => true,
                    'ColorValue'       => $state[2],
                ];
            }
            return ['PRESENTATION' => self::PRESENTATION_VALUE, 'DIGITS' => 0, 'INTERVALS_ACTIVE' => true, 'INTERVALS' => json_encode($intervals)];
        }
        return null;
    }

    /**
     * @param array $options Liste aus [Wert, Beschriftung, Farbe oder -1]
     */
    private function enumeration(array $options): array
    {
        $list = [];
        foreach ($options as $option) {
            $list[] = ['Value' => $option[0], 'Caption' => $option[1], 'IconValue' => '', 'IconActive' => false, 'Color' => $option[2]];
        }
        return ['PRESENTATION' => self::PRESENTATION_ENUMERATION, 'OPTIONS' => json_encode($list)];
    }

    private function settings(): array
    {
        $settings = json_decode($this->ReadAttributeString('Settings'), true);
        return is_array($settings) ? $settings : [];
    }

    /**
     * Setzt die Darstellung einer vorhandenen Variable neu. Name und Position bleiben wie sie sind.
     */
    private function applyPresentation(int $id, string $ident): bool
    {
        $presentation = $this->presentationFor($ident);
        if ($presentation === null) {
            return true;
        }
        $object = IPS_GetObject($id);
        $this->MaintainVariable($ident, $object['ObjectName'], IPS_GetVariable($id)['VariableType'], $presentation, $object['ObjectPosition'], true);
        return true;
    }

    /**
     * Aktualisiert die Darstellung aller Variablen, deren Ident zum Muster passt.
     */
    private function refreshPresentations(string $pattern): void
    {
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $object = IPS_GetObject($id);
            if ($object['ObjectType'] === OBJECTTYPE_VARIABLE && preg_match($pattern, $object['ObjectIdent'])) {
                $this->applyPresentation($id, $object['ObjectIdent']);
            }
        }
    }

    /**
     * Benennt die Idents der Version 1.1 (Praefix WLANThermoa_) um. Objekt-ID, Archiv und Verknuepfungen bleiben erhalten.
     */
    private function migrateIdents(): void
    {
        if ($this->ReadAttributeInteger('SchemaVersion') >= self::SCHEMA_IDENTS) {
            return;
        }

        $complete = true;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $object = IPS_GetObject($id);
            if ($object['ObjectType'] !== OBJECTTYPE_VARIABLE || strpos($object['ObjectIdent'], self::OLD_IDENT_PREFIX) !== 0) {
                continue;
            }
            $newIdent = substr($object['ObjectIdent'], strlen(self::OLD_IDENT_PREFIX));
            if ($newIdent === '') {
                continue;
            }
            if ($this->identExists($newIdent)) {
                $this->LogMessage('Ident ' . $newIdent . ' exists already, ' . $object['ObjectIdent'] . ' was not renamed', KL_WARNING);
                $complete = false;
                continue;
            }
            if (!IPS_SetIdent($id, $newIdent)) {
                $this->LogMessage('Ident ' . $object['ObjectIdent'] . ' could not be renamed', KL_ERROR);
                $complete = false;
            }
        }

        //Typ ist jetzt schreibbar
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $ident = IPS_GetObject($id)['ObjectIdent'];
            if (preg_match('/^Typ\d+$/', $ident)) {
                $this->EnableAction($ident);
            }
        }

        if ($complete) {
            $this->WriteAttributeInteger('SchemaVersion', self::SCHEMA_IDENTS);
        }
    }

    /**
     * Stellt die Variablen von Variablenprofilen auf native Darstellungen um. Wert, Name, Position, Archiv und Action bleiben.
     * Ein bestehendes Profil der Variable wird dabei nicht gelöscht, es bleibt für andere Variablen bestehen.
     */
    private function migratePresentations(): void
    {
        $version = $this->ReadAttributeInteger('SchemaVersion');
        if ($version < self::SCHEMA_IDENTS || $version >= self::SCHEMA_PRESENTATIONS) {
            return;
        }

        $complete = true;
        foreach (IPS_GetChildrenIDs($this->InstanceID) as $id) {
            $object = IPS_GetObject($id);
            if ($object['ObjectType'] !== OBJECTTYPE_VARIABLE) {
                continue;
            }
            if (!$this->applyPresentation($id, $object['ObjectIdent'])) {
                $this->LogMessage('Presentation of ' . $object['ObjectIdent'] . ' could not be set', KL_ERROR);
                $complete = false;
                continue;
            }
            //Profile pro Instanz aus Entwicklungsständen vor Version 2.0
            if (strpos(IPS_GetVariable($id)['VariableCustomProfile'], 'WLanThermo.') === 0) {
                IPS_SetVariableCustomProfile($id, '');
            }
        }
        foreach (['WLanThermo.Sensor.' . $this->InstanceID, 'WLanThermo.PID.' . $this->InstanceID] as $profile) {
            if (IPS_VariableProfileExists($profile)) {
                @IPS_DeleteVariableProfile($profile);
            }
        }

        if ($complete) {
            $this->WriteAttributeInteger('SchemaVersion', self::SCHEMA_PRESENTATIONS);
        }
    }

    private function ensureVariable(string $ident, string $name, int $type, bool $action): void
    {
        if ($this->identExists($ident)) {
            return;
        }
        $this->MaintainVariable($ident, $name, $type, $this->presentationFor($ident) ?? '', 0, true);
        if ($action) {
            $this->EnableAction($ident);
        }
    }

    private function setIfExists(string $ident, mixed $value): void
    {
        if ($this->identExists($ident)) {
            $this->SetValue($ident, $value);
        }
    }

    private function processData(array $data): void
    {
        $parsed = WLANThermoParser::data($data);

        $this->processSystem($parsed['system']);
        foreach ($parsed['channels'] as $number => $values) {
            $this->processChannel($number, $values);
        }
        if ($parsed['autotune'] && !$this->ReadAttributeBoolean('Autotune')) {
            $this->WriteAttributeBoolean('Autotune', true);
            $this->refreshPresentations('/^Pitmaster\d+_Mode$/');
        }
        foreach ($parsed['pitmasters'] as $id => $values) {
            $this->processPitmaster($id, $values);
        }
        $this->updateTile();
    }

    private function processSystem(array $system): void
    {
        if (isset($system['charge'])) {
            $this->SetValue('Charge', $system['charge']);
        }
        if (isset($system['soc'])) {
            $this->SetValue('SOC', $system['soc']);
        }
        if (isset($system['rssi'])) {
            $this->SetValue('RSSI', $system['rssi']);
        }
        if (isset($system['online'])) {
            $this->ensureVariable('Online', $this->Translate('Online'), VARIABLETYPE_INTEGER, false);
            $this->SetValue('Online', $system['online']);
        }
        if (isset($system['unit'])) {
            $this->ensureVariable('Unit', $this->Translate('Unit'), VARIABLETYPE_STRING, false);
            $this->SetValue('Unit', $system['unit']);
            $this->applyUnit($system['unit']);
        }
    }

    private function applyUnit(string $unit): void
    {
        if ($unit === $this->ReadAttributeString('Unit')) {
            return;
        }
        $this->WriteAttributeString('Unit', $unit);
        $this->refreshPresentations('/^((Temperature|Min|Max)\d+|Pitmaster\d+_Set)$/');
    }

    private function processChannel(int $n, array $values): void
    {
        $name = (isset($values['name']) && $values['name'] !== '') ? $values['name'] : $this->Translate('Channel') . ' ' . $n;

        //Ident, Name der Variable, Typ, steuerbar
        $variables = [
            'temp'      => ['Temperature', $name . ' ' . $this->Translate('Temperature'), VARIABLETYPE_FLOAT, false],
            'min'       => ['Min', $name . ' ' . $this->Translate('Min'), VARIABLETYPE_FLOAT, true],
            'max'       => ['Max', $name . ' ' . $this->Translate('Max'), VARIABLETYPE_FLOAT, true],
            'typ'       => ['Typ', $this->Translate('Typ Channel') . ' ' . $n, VARIABLETYPE_INTEGER, true],
            'alarm'     => ['Alarm', $this->Translate('Alarm Channel') . ' ' . $n, VARIABLETYPE_INTEGER, true],
            'name'      => ['Name', $this->Translate('Name Channel') . ' ' . $n, VARIABLETYPE_STRING, true],
            'color'     => ['Color', $this->Translate('Color Channel') . ' ' . $n, VARIABLETYPE_INTEGER, true],
            'fixed'     => ['Fixed', $this->Translate('Fixed Channel') . ' ' . $n, VARIABLETYPE_BOOLEAN, false],
            'connected' => ['Connected', $this->Translate('Connected Channel') . ' ' . $n, VARIABLETYPE_BOOLEAN, false],
        ];
        foreach ($variables as $key => [$prefix, $variableName, $type, $action]) {
            if (array_key_exists($key, $values)) {
                $this->ensureVariable($prefix . $n, $variableName, $type, $action);
                $this->SetValue($prefix . $n, $values[$key]);
            }
        }
    }

    private function processPitmaster(int $id, array $values): void
    {
        $label = $this->Translate('Pitmaster') . ' ' . $id . ' ';

        $variables = [
            'mode'    => ['Mode', $label . $this->Translate('Mode'), VARIABLETYPE_INTEGER],
            'value'   => ['Value', $label . $this->Translate('Value'), VARIABLETYPE_INTEGER],
            'set'     => ['Set', $label . $this->Translate('Set'), VARIABLETYPE_FLOAT],
            'channel' => ['Channel', $label . $this->Translate('Channel'), VARIABLETYPE_INTEGER],
            'pid'     => ['Pid', $label . $this->Translate('PID'), VARIABLETYPE_INTEGER],
        ];
        foreach ($variables as $key => [$suffix, $variableName, $type]) {
            if (array_key_exists($key, $values)) {
                $this->ensureVariable('Pitmaster' . $id . '_' . $suffix, $variableName, $type, true);
                $this->SetValue('Pitmaster' . $id . '_' . $suffix, $values[$key]);
            }
        }
    }

    private function processSettings(array $settings): void
    {
        $keep = WLANThermoParser::settings($settings);

        //Auswahllisten der Darstellungen nur neu setzen, wenn sich Fuehler oder PID-Profile geaendert haben
        $previous = $this->settings();
        $this->WriteAttributeString('Settings', json_encode($keep));
        if (($previous['sensors'] ?? null) !== ($keep['sensors'] ?? null)) {
            $this->refreshPresentations('/^Typ\d+$/');
        }
        if (($previous['pid'] ?? null) !== ($keep['pid'] ?? null)) {
            $this->refreshPresentations('/^Pitmaster\d+_Pid$/');
        }

        if (is_string($keep['system']['unit'] ?? null) && in_array($keep['system']['unit'], ['C', 'F'], true)) {
            $this->applyUnit($keep['system']['unit']);
        }
        $this->updateTile();
    }

    private function publish(string $subTopic, string $payload): bool
    {
        if ($this->topic() === '') {
            trigger_error($this->Translate('MQTT topic is not set'), E_USER_WARNING);
            return false;
        }
        if (!$this->HasActiveParent()) {
            trigger_error($this->Translate('No active MQTT parent'), E_USER_WARNING);
            return false;
        }

        $Data = [
            'DataID'           => self::MQTT_DATA_ID,
            'PacketType'       => 3,
            'QualityOfService' => 0,
            'Retain'           => false,
            'Topic'            => 'WLanThermo/' . $this->topic() . '/' . $subTopic,
            'Payload'          => bin2hex($payload),
        ];
        $this->SendDebug(__FUNCTION__ . ' Topic', $Data['Topic'], 0);
        $this->SendDebug(__FUNCTION__ . ' Payload', $payload, 0);
        $this->SendDataToParent(json_encode($Data));
        return true;
    }

    private function setChannel(int $channel, string $key, mixed $value, string $ident): void
    {
        if ($channel < 1 || $channel > 24) {
            trigger_error('Channel must be between 1 and 24', E_USER_WARNING);
            return;
        }
        if ($this->publish('set/channels', json_encode(['number' => $channel, $key => $value]))) {
            $this->setIfExists($ident, $key === 'color' ? (int) hexdec(substr((string) $value, 1)) : $value);
            $this->updateTile();
            $this->getData();
        }
    }

    /**
     * Die API verlangt vollstaendige Pitmaster-Objekte, fehlende Felder werden aus den Variablen ergaenzt.
     */
    private function setPitmaster(int $id, string $key, mixed $value, string $ident, mixed $variableValue): void
    {
        if ($id < 0 || $id > 1) {
            trigger_error('Pitmaster id must be 0 or 1', E_USER_WARNING);
            return;
        }

        $mode = $this->identExists('Pitmaster' . $id . '_Mode') ? (int) $this->GetValue('Pitmaster' . $id . '_Mode') : 0;
        $entry = [
            'id'      => $id,
            'channel' => $this->identExists('Pitmaster' . $id . '_Channel') ? (int) $this->GetValue('Pitmaster' . $id . '_Channel') : 1,
            'pid'     => $this->identExists('Pitmaster' . $id . '_Pid') ? (int) $this->GetValue('Pitmaster' . $id . '_Pid') : 0,
            'value'   => $this->identExists('Pitmaster' . $id . '_Value') ? (int) $this->GetValue('Pitmaster' . $id . '_Value') : 0,
            'set'     => $this->identExists('Pitmaster' . $id . '_Set') ? (float) $this->GetValue('Pitmaster' . $id . '_Set') : 0.0,
            'typ'     => (string) array_search($mode, self::MODES, true),
        ];
        $entry[$key] = $value;

        if ($this->publish('set/pitmaster', json_encode([$entry]))) {
            $this->setIfExists($ident, $variableValue);
            $this->updateTile();
            $this->getData();
        }
    }

    private function applySystemForm(string $json): void
    {
        $form = json_decode($json, true);
        $settings = json_decode($this->ReadAttributeString('Settings'), true);
        $current = is_array($settings['system'] ?? null) ? $settings['system'] : [];
        if (!is_array($form)) {
            return;
        }

        $changes = [];
        foreach ($form as $key => $value) {
            if (array_key_exists($key, $current) && $current[$key] !== $value) {
                $changes[$key] = $value;
            }
        }
        if ($changes === []) {
            $this->UpdateFormField('FormMessage', 'caption', $this->Translate('No changes'));
            return;
        }
        $this->setSystem(json_encode($changes));
        $this->UpdateFormField('FormMessage', 'caption', $this->Translate('Sent'));
    }

    private function selectPIDProfile(int $id): void
    {
        $settings = json_decode($this->ReadAttributeString('Settings'), true);
        foreach ((array) ($settings['pid'] ?? []) as $profile) {
            if (!is_array($profile) || (int) ($profile['id'] ?? -1) !== $id) {
                continue;
            }
            $fields = ['name' => 'PIDName', 'aktor' => 'PIDAktor', 'Kp' => 'PIDKp', 'Ki' => 'PIDKi', 'Kd' => 'PIDKd', 'DCmmin' => 'PIDDCmmin', 'DCmmax' => 'PIDDCmmax', 'jp' => 'PIDJp', 'opl' => 'PIDOpl', 'SPmin' => 'PIDSPmin', 'SPmax' => 'PIDSPmax', 'link' => 'PIDLink'];
            foreach ($fields as $key => $field) {
                $present = array_key_exists($key, $profile);
                $this->UpdateFormField($field, 'visible', $present);
                if ($present) {
                    $this->UpdateFormField($field, 'value', is_bool($profile[$key]) || is_string($profile[$key]) ? $profile[$key] : $profile[$key] + 0);
                }
            }
            return;
        }
    }

    private function applyPIDForm(string $json): void
    {
        $form = json_decode($json, true);
        if (!is_array($form) || !isset($form['id'])) {
            return;
        }
        $settings = json_decode($this->ReadAttributeString('Settings'), true);
        foreach ((array) ($settings['pid'] ?? []) as $profile) {
            if (is_array($profile) && (int) ($profile['id'] ?? -1) === (int) $form['id']) {
                //nur Felder senden, die das Geraet kennt
                $entry = ['id' => (int) $form['id']];
                foreach ($form as $key => $value) {
                    if (array_key_exists($key, $profile)) {
                        $entry[$key] = $value;
                    }
                }
                $this->setPID(json_encode([$entry]));
                $this->UpdateFormField('FormMessage', 'caption', $this->Translate('Sent'));
                return;
            }
        }
        $this->UpdateFormField('FormMessage', 'caption', $this->Translate('Select a profile first'));
    }

    private function formSet(array &$items, string $name, string $key, mixed $value): void
    {
        foreach ($items as &$item) {
            if (($item['name'] ?? '') === $name) {
                $item[$key] = $value;
            }
            foreach (['items', 'elements'] as $child) {
                if (isset($item[$child]) && is_array($item[$child])) {
                    $this->formSet($item[$child], $name, $key, $value);
                }
            }
        }
    }
}
