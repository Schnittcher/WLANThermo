<?php

declare(strict_types=1);

use PHPUnit\Framework\TestCase;

final class WLANThermoParserTest extends TestCase
{
    //ESP32 (Mini V2): Fuehler mit fixed und connected, Akku, drei Pitmaster-Modi
    private const ESP32_DATA = [
        'system'  => ['time' => 1598259582, 'unit' => 'C', 'soc' => 85, 'charge' => true, 'rssi' => -39, 'online' => 2],
        'channel' => [
            ['number' => 1, 'name' => 'Brust', 'typ' => 0, 'temp' => 63.4, 'min' => 60.0, 'max' => 75.0, 'alarm' => 2, 'color' => '#E24B4A', 'fixed' => false, 'connected' => true],
            ['number' => 12, 'name' => 'Kanal 12', 'typ' => 3, 'temp' => 999.0, 'min' => 10, 'max' => 35, 'alarm' => 0, 'color' => '#0C4C88', 'fixed' => true, 'connected' => false],
        ],
        'pitmaster' => [
            'type' => ['off', 'manual', 'auto'],
            'pm'   => [['id' => 0, 'channel' => 1, 'pid' => 1, 'value' => 38, 'set' => 110.0, 'typ' => 'auto']],
        ],
    ];

    //Nano V1: ohne Akku, ohne fixed und connected, mit autotune, Fahrenheit
    private const NANO_DATA = [
        'system'    => ['time' => 1, 'unit' => 'F', 'rssi' => -50, 'online' => 0],
        'channel'   => [['number' => 1, 'name' => 'Ä1', 'typ' => 1, 'temp' => 75.0, 'min' => 50.0, 'max' => 95.0, 'alarm' => 0, 'color' => '#FF0000']],
        'pitmaster' => [
            'type' => ['off', 'manual', 'auto', 'autotune'],
            'pm'   => [['id' => 0, 'channel' => 1, 'pid' => 0, 'value' => 10, 'set' => 90.0, 'typ' => 'autotune']],
        ],
    ];

    public function testTopicTypeAcceptsOnlyTheStatusTopicsOfTheHost(): void
    {
        $this->assertSame('data', WLANThermoParser::topicType('WLanThermo/mini-01/status/data', 'mini-01'));
        $this->assertSame('settings', WLANThermoParser::topicType('WLanThermo/mini-01/status/settings', 'mini-01'));
        $this->assertNull(WLANThermoParser::topicType('WLanThermo/other/status/data', 'mini-01'));
        $this->assertNull(WLANThermoParser::topicType('WLanThermo/mini-01/set/channels', 'mini-01'));
        $this->assertNull(WLANThermoParser::topicType('WLanThermo/mini-01/status/data/extra', 'mini-01'));
        $this->assertNull(WLANThermoParser::topicType('WLanThermo/mini-01/status/data', ''), 'ohne Topic wird nichts angenommen');
    }

    public function testTopicTypeTreatsTheHostAsPlainText(): void
    {
        $this->assertSame('data', WLANThermoParser::topicType('WLanThermo/mini.local/status/data', 'mini.local'));
        $this->assertNull(WLANThermoParser::topicType('WLanThermo/miniXlocal/status/data', 'mini.local'), 'der Punkt ist kein Platzhalter');
    }

    public function testDecodePayloadReadsHexEncodedUtf8Json(): void
    {
        $this->assertSame(['name' => 'Ärmel'], WLANThermoParser::decodePayload(bin2hex('{"name":"Ärmel"}')));
        $this->assertNull(WLANThermoParser::decodePayload('zz'), 'kein HEX');
        $this->assertNull(WLANThermoParser::decodePayload(bin2hex('kein json')));
        $this->assertNull(WLANThermoParser::decodePayload(bin2hex('42')), 'ein JSON-Wert ohne Objekt zaehlt nicht');
        $this->assertNull(WLANThermoParser::decodePayload(''));
    }

    public function testParseIdentSplitsChannelIdentsAboveNine(): void
    {
        $this->assertSame(['kind' => 'channel', 'field' => 'Min', 'number' => 1], WLANThermoParser::parseIdent('Min1'));
        $this->assertSame(['kind' => 'channel', 'field' => 'Alarm', 'number' => 12], WLANThermoParser::parseIdent('Alarm12'));
        $this->assertSame(['kind' => 'channel', 'field' => 'Color', 'number' => 24], WLANThermoParser::parseIdent('Color24'));
    }

    public function testParseIdentSplitsPitmasterIdents(): void
    {
        $this->assertSame(['kind' => 'pitmaster', 'field' => 'Set', 'id' => 0], WLANThermoParser::parseIdent('Pitmaster0_Set'));
        $this->assertSame(['kind' => 'pitmaster', 'field' => 'Pid', 'id' => 1], WLANThermoParser::parseIdent('Pitmaster1_Pid'));
    }

    public function testParseIdentIgnoresOtherIdents(): void
    {
        foreach (['Charge', 'SOC', 'Online', 'Min', 'Min1x', 'WLANThermoa_Min1', 'Pitmaster0_Foo', 'Pitmaster_Set', ''] as $ident) {
            $this->assertNull(WLANThermoParser::parseIdent($ident), $ident);
        }
    }

    public function testDataOfEsp32(): void
    {
        $result = WLANThermoParser::data(self::ESP32_DATA);

        $this->assertSame(['charge' => true, 'soc' => 85, 'rssi' => -39, 'online' => 2, 'unit' => 'C'], $result['system']);
        $this->assertSame([1, 12], array_keys($result['channels']));
        $this->assertSame(
            ['temp' => 63.4, 'min' => 60.0, 'max' => 75.0, 'typ' => 0, 'alarm' => 2, 'name' => 'Brust', 'color' => 0xE24B4A, 'fixed' => false, 'connected' => true],
            $result['channels'][1]
        );
        $this->assertSame(10.0, $result['channels'][12]['min'], 'ganze Zahlen werden zu Kommazahlen');
        $this->assertTrue($result['channels'][12]['fixed']);
        $this->assertFalse($result['channels'][12]['connected']);
        $this->assertSame([0 => ['mode' => 2, 'value' => 38, 'channel' => 1, 'pid' => 1, 'set' => 110.0]], $result['pitmasters']);
        $this->assertFalse($result['autotune']);
    }

    public function testDataOfNanoHasNoBatteryFixedConnectedButAutotune(): void
    {
        $result = WLANThermoParser::data(self::NANO_DATA);

        $this->assertSame(['rssi' => -50, 'online' => 0, 'unit' => 'F'], $result['system'], 'ohne Akku kein charge und soc');
        $this->assertArrayNotHasKey('fixed', $result['channels'][1]);
        $this->assertArrayNotHasKey('connected', $result['channels'][1]);
        $this->assertSame('Ä1', $result['channels'][1]['name']);
        $this->assertSame(3, $result['pitmasters'][0]['mode']);
        $this->assertTrue($result['autotune']);
    }

    public function testDataSkipsInvalidChannels(): void
    {
        $result = WLANThermoParser::data(['channel' => [
            ['name' => 'ohne Nummer', 'temp' => 20],
            ['number' => 0, 'temp' => 20],
            ['number' => -1, 'temp' => 20],
            ['number' => 'x', 'temp' => 20],
            'kein Array',
            ['number' => 3, 'temp' => 20],
        ]]);

        $this->assertSame([3], array_keys($result['channels']));
    }

    public function testDataDropsInvalidFieldsButKeepsTheValidOnes(): void
    {
        $result = WLANThermoParser::data(['channel' => [[
            'number' => 1,
            'temp'   => 'warm',
            'min'    => null,
            'max'    => 30,
            'alarm'  => 'laut',
            'color'  => 'rot',
            'name'   => 42,
        ]]]);

        $this->assertSame([1 => ['max' => 30.0]], $result['channels']);
    }

    public function testDataRejectsColorsThatAreNotSixDigitHex(): void
    {
        foreach (['#FFF', 'FF0000', '#GG0000', '#FF00000'] as $color) {
            $this->assertArrayNotHasKey('color', WLANThermoParser::data(['channel' => [['number' => 1, 'color' => $color]]])['channels'][1], $color);
        }
        $this->assertSame(0xFF0000, WLANThermoParser::data(['channel' => [['number' => 1, 'color' => '#ff0000']]])['channels'][1]['color'], 'Kleinbuchstaben');
    }

    public function testDataIgnoresUnknownUnitAndPitmasterModes(): void
    {
        $result = WLANThermoParser::data([
            'system'    => ['unit' => 'K'],
            'pitmaster' => ['pm' => [['id' => 1, 'typ' => 'turbo', 'value' => 5], ['typ' => 'off'], ['id' => 'x']]],
        ]);

        $this->assertSame([], $result['system']);
        $this->assertSame([1 => ['value' => 5]], $result['pitmasters']);
    }

    public function testDataOfAnEmptyOrForeignPayloadIsEmpty(): void
    {
        $empty = ['system' => [], 'channels' => [], 'pitmasters' => [], 'autotune' => false];

        $this->assertSame($empty, WLANThermoParser::data([]));
        $this->assertSame($empty, WLANThermoParser::data(['system' => 'x', 'channel' => 'x', 'pitmaster' => 'x']));
    }

    public function testSettingsDropsCredentialsAndSerial(): void
    {
        $result = WLANThermoParser::settings([
            'device'  => ['device' => 'mini', 'serial' => 'abc123', 'sw_version' => 'v1.0.9'],
            'system'  => ['unit' => 'C', 'host' => 'mini-01'],
            'sensors' => [],
            'pid'     => [],
            'iot'     => ['PMQpass' => 'geheim', 'CLtoken' => 'token'],
            'notes'   => ['ext' => ['token' => 'token']],
        ]);

        $this->assertSame(['device', 'system', 'sensors', 'pid'], array_keys($result));
        $this->assertSame(['device' => 'mini', 'sw_version' => 'v1.0.9'], $result['device']);
        $this->assertStringNotContainsString('geheim', json_encode($result));
    }

    public function testSensorOptionsOfEsp32AndNano(): void
    {
        $esp32 = ['sensors' => [['type' => 0, 'name' => '1000K/Maverick', 'fixed' => false], ['type' => 7, 'name' => 'Fantast', 'fixed' => false]]];
        $nano = ['sensors' => ['1000K/Maverick', 'Fantast']];

        $this->assertSame([[0, '1000K/Maverick'], [7, 'Fantast']], WLANThermoParser::sensorOptions($esp32));
        $this->assertSame([[0, '1000K/Maverick'], [1, 'Fantast']], WLANThermoParser::sensorOptions($nano));
        $this->assertSame([], WLANThermoParser::sensorOptions([]));
    }

    public function testPidOptions(): void
    {
        $settings = ['pid' => [['id' => 0, 'name' => 'TITAN'], ['id' => 1], 'kaputt', ['name' => 'ohne Id']]];

        $this->assertSame([[0, 'TITAN'], [1, '1']], WLANThermoParser::pidOptions($settings));
        $this->assertSame([], WLANThermoParser::pidOptions([]));
    }
}
