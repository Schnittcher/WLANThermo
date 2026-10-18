# Changelog

## 19.10.2026 - Version 2.0.1

### Fixes
- Die Instanz lässt sich unter Symcon 9.0 wieder anlegen, vorher brach das Anlegen mit "Undefined constant" ab.

## 18.10.2026 - Version 2.0

### Neu
- Pitmaster mit Modus, Stellwert, Solltemperatur, Kanal und PID-Profil, alle steuerbar.
- Kanäle zeigen und ändern Name, Farbe und Fühlertyp, Fühlertypen erscheinen mit Namen.
- Neue Variablen für Cloud-Status, Einheit sowie für feste und verbundene Fühler.
- Kachel für die Kachel-Visualisierung mit Temperaturbalken, Alarmanzeige und Pitmaster. Min, Max, Alarm und Pitmaster-Werte lassen sich direkt in der Kachel ändern.
- Nicht verbundene Kanäle lassen sich in der Kachel ausblenden (Einstellung in der Instanz).
- Fahrenheit wird in den Temperaturvariablen berücksichtigt.
- Alle Variablen nutzen die neuen Darstellungen von Symcon 9 (Aufzählung, Schieberegler, Wertanzeige mit Einheit). Fühlertypen und PID-Profile zeigen die Namen aus dem Gerät.
- System-Einstellungen und PID-Profile lassen sich im Formular und per Funktion ändern.
- Instanz zeigt einen Status, wenn das MQTT Topic fehlt.

### Fixes
- Alarmart Summer und Push+Summer lassen sich wieder setzen.
- Kanäle ab Nummer 10 werden richtig bedient.
- Fehlende Werte im Datenpaket führen nicht mehr zu Fehlern.
- Achtung: Die Variablen werden beim Update auf die neuen Darstellungen umgestellt, Variablenprofile des Moduls entfallen. Min, Max und Soll erscheinen ohne feste Nachkommastelle, Laden als Ja/Nein. Wert, Name, Archiv und Verknüpfungen bleiben erhalten.
- Achtung: Die Idents der Variablen verlieren den Präfix `WLANThermoa_` (zum Beispiel `Min1` statt `WLANThermoa_Min1`). Variablen, Archiv und Verknüpfungen bleiben erhalten, Skripte, die Variablen über den Ident suchen, müssen angepasst werden.
