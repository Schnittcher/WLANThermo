# WLANThermo
Dieses Modul verbindet ein WLANThermo (ESP32 und Nano V1) per MQTT mit IP-Symcon. Die Instanz legt Variablen für Kanäle, Pitmaster und Systemwerte an, sobald das Gerät sie sendet. Das WLANThermo muss dazu MQTT an einen MQTT Server oder Client in IP-Symcon senden, die Instanz wird manuell angelegt.

## Inhaltsverzeichnis
- [WLANThermo](#wlanthermo)
  - [Inhaltsverzeichnis](#inhaltsverzeichnis)
  - [1. Konfiguration](#1-konfiguration)
  - [2. Variablen](#2-variablen)
    - [2.1 System](#21-system)
    - [2.2 Kanäle](#22-kanäle)
    - [2.3 Pitmaster](#23-pitmaster)
    - [2.4 Kachel](#24-kachel)
  - [3. Funktionen](#3-funktionen)
  - [4. Spenden](#4-spenden)
  - [5. Lizenz](#5-lizenz)

## 1. Konfiguration

Feld | Beschreibung
------------ | ----------------
MQTT Topic | Hostname des WLANThermo, so wie er im Topic `WLanThermo/<Hostname>/…` steht.
Nicht verbundene Kanäle in der Kachel ausblenden | Kanäle ohne Fühler erscheinen nicht in der Kachel. Standard: aus, die Kanäle erscheinen abgeblendet. Die Variablen bleiben unverändert.

Im Konfigurationsformular stehen unter den Aktionen:

Aktion | Beschreibung
------------ | ----------------
Daten abrufen / Einstellungen abrufen | Fragt das Gerät sofort ab.
System | Ändert AP-Name, Hostname, Sprache, Einheit, automatische Updates und Pre-Release. Es werden nur geänderte Werte gesendet. Der Hostname bestimmt das MQTT Topic, nach einer Änderung muss das Topic der Instanz angepasst werden.
PID-Profile | Wählt ein Profil des Geräts und überträgt geänderte Werte. Felder, die das Gerät nicht kennt, werden ausgeblendet.

Die Einstellungen für MQTT und Cloud sowie die Benachrichtigungen des Geräts werden nicht über die Instanz geändert.

## 2. Variablen
Variablen entstehen, sobald das Gerät den jeweiligen Wert sendet. Sie nutzen die Darstellungen von Symcon 9: Temperaturen und Grenzen mit Einheit (C oder F, wie das Gerät sie meldet), Alarmart, Fühlertyp, Pitmaster-Modus und PID-Profil als Aufzählung mit den Namen aus dem Gerät, den Stellwert als Schieberegler. `<n>` ist die Kanalnummer, `<m>` die Pitmaster-Nummer.

### 2.1 System

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Laden | `Charge` | Ja/Nein | Akku wird geladen, nur bei Geräten mit Akku.
SOC | `SOC` | Ganzzahl (%) | Ladezustand.
RSSI | `RSSI` | Ganzzahl (dBm) | WLAN-Signalstärke.
Cloud | `Online` | Ganzzahl | 0 nicht verbunden, 1 Standby, 2 verbunden.
Einheit | `Unit` | Text | `C` oder `F`. Die Temperaturvariablen folgen der Einheit.

### 2.2 Kanäle

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Temperatur | `Temperature<n>` | Kommazahl | Aktuelle Temperatur, nur lesen.
Min | `Min<n>` | Kommazahl | Untere Alarmgrenze, steuerbar.
Max | `Max<n>` | Kommazahl | Obere Alarmgrenze, steuerbar.
Typ Kanal n | `Typ<n>` | Ganzzahl | Fühlertyp, steuerbar. Die Namen kommen aus den Einstellungen des Geräts.
Alarm Kanal n | `Alarm<n>` | Ganzzahl | 0 Aus, 1 Push, 2 Buzzer, 3 Push-Buzzer, steuerbar.
Name Kanal n | `Name<n>` | Text | Kanalname, höchstens 10 Zeichen, steuerbar.
Farbe Kanal n | `Color<n>` | Ganzzahl (Farbe) | Kanalfarbe, steuerbar.
Fester Fühler Kanal n | `Fixed<n>` | Ja/Nein | Der Fühlertyp ist fest und nicht änderbar (nur ESP32).
Verbunden Kanal n | `Connected<n>` | Ja/Nein | Funkfühler verbunden (nur ESP32).

### 2.3 Pitmaster

Variable | Ident | Typ | Beschreibung
------------ | ------------ | ------------ | ----------------
Pitmaster m Modus | `Pitmaster<m>_Mode` | Ganzzahl | 0 Aus, 1 Manuell, 2 Automatisch, 3 Autotune (nur wenn das Gerät es meldet), steuerbar.
Pitmaster m Stellwert | `Pitmaster<m>_Value` | Ganzzahl (%) | Stellwert, wirkt im Modus Manuell, steuerbar.
Pitmaster m Solltemperatur | `Pitmaster<m>_Set` | Kommazahl | Solltemperatur, wirkt im Modus Automatisch, steuerbar.
Pitmaster m Kanal | `Pitmaster<m>_Channel` | Ganzzahl | Kanal, auf den geregelt wird, wirkt im Modus Automatisch, steuerbar.
Pitmaster m PID-Profil | `Pitmaster<m>_Pid` | Ganzzahl | PID-Profil, die Namen kommen aus den Einstellungen des Geräts, steuerbar.

### 2.4 Kachel
Die Instanz hat eine Kachel für die Kachel-Visualisierung. Sie zeigt pro Kanal Name, Farbe, Temperatur und einen Balken zwischen Min und Max. Ein Strich markiert die aktuelle Temperatur, außerhalb der Grenzen wird die Karte rot und nennt "Über Max" oder "Unter Min". Kanäle ohne Fühler sind abgeblendet. Der Pitmaster zeigt Solltemperatur, Kanal, PID-Profil und Stellwert und schaltet den Modus um. Der Knopf mit den Reglern an jeder Karte öffnet einen Dialog für Min, Max und Alarm eines Kanals sowie für Solltemperatur und Stellwert des Pitmasters. Ab Symcon 9.1 ist die Kachel auch maximiert nutzbar, unter 9.0 bleibt die maximierte Ansicht leer.

## 3. Funktionen
Der Präfix der Funktionen ist `WTHERMO`. Ungültige Werte lösen eine Warnung aus und werden nicht gesendet.

`void WTHERMO_getData(integer $InstanzID);`
Fragt die aktuellen Werte ab.

`void WTHERMO_getSettings(integer $InstanzID);`
Fragt die Einstellungen des Geräts ab (Sensorliste, PID-Profile, Systemwerte).

`void WTHERMO_setMin(integer $InstanzID, integer $Kanal, float $Wert);`
`void WTHERMO_setMax(integer $InstanzID, integer $Kanal, float $Wert);`
Setzt die Alarmgrenze eines Kanals (-30 bis 999,9).

`void WTHERMO_setAlarm(integer $InstanzID, integer $Kanal, integer $Wert);`
Setzt die Alarmart eines Kanals (0 bis 3).

`void WTHERMO_setTyp(integer $InstanzID, integer $Kanal, integer $Wert);`
`void WTHERMO_setName(integer $InstanzID, integer $Kanal, string $Wert);`
`void WTHERMO_setColor(integer $InstanzID, integer $Kanal, integer $Farbe);`
Setzt Fühlertyp, Name und Farbe eines Kanals.

`void WTHERMO_setPitmasterMode(integer $InstanzID, integer $Pitmaster, integer $Modus);`
`void WTHERMO_setPitmasterValue(integer $InstanzID, integer $Pitmaster, integer $Wert);`
`void WTHERMO_setPitmasterSet(integer $InstanzID, integer $Pitmaster, float $Wert);`
`void WTHERMO_setPitmasterChannel(integer $InstanzID, integer $Pitmaster, integer $Kanal);`
`void WTHERMO_setPitmasterPID(integer $InstanzID, integer $Pitmaster, integer $Profil);`
Setzt Modus, Stellwert, Solltemperatur, Kanal und PID-Profil eines Pitmasters. Das Gerät erwartet vollständige Pitmaster-Daten, fehlende Werte ergänzt das Modul aus den Variablen.

`void WTHERMO_setSystem(integer $InstanzID, string $Einstellungen);`
Ändert Systemwerte. Der Text ist ein JSON-Objekt mit den Schlüsseln `ap`, `host`, `language`, `unit`, `autoupd` und `prerelease`, zum Beispiel `{"unit":"C"}`.

`void WTHERMO_setPID(integer $InstanzID, string $Profile);`
Ändert PID-Profile. Der Text ist ein JSON-Array mit Profilen, jedes braucht `id`, weitere Schlüssel sind `name`, `aktor`, `Kp`, `Ki`, `Kd`, `DCmmin`, `DCmmax`, `opl`, `tune`, `jp`, `SPmin`, `SPmax` und `link`.

## 4. Spenden
Dieses Modul ist für die nicht kommerzielle Nutzung kostenlos, Schenkungen als Unterstützung für den Autor werden hier akzeptiert:

[![PayPal](https://img.shields.io/badge/PayPal-Spenden-00457C?logo=paypal&logoColor=white&style=for-the-badge)](https://www.paypal.com/donate?hosted_button_id=EK4JRP87XLSHW) [![Amazon Wunschzettel](https://img.shields.io/badge/Amazon-Wunschzettel-FF9900?logo=amazon&logoColor=white&style=for-the-badge)](https://www.amazon.de/hz/wishlist/ls/3JVWED9SZMDPK?ref_=wl_share)

## 5. Lizenz

[CC BY-NC-SA 4.0](https://creativecommons.org/licenses/by-nc-sa/4.0/)
