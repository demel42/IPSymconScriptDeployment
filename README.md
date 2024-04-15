
[![IPS-Version](https://img.shields.io/badge/Symcon_Version-6.0+-red.svg)](https://www.symcon.de/service/dokumentation/entwicklerbereich/sdk-tools/sdk-php/)
![Code](https://img.shields.io/badge/Code-PHP-blue.svg)
[![License](https://img.shields.io/badge/License-CC%20BY--NC--SA%204.0-green.svg)](https://creativecommons.org/licenses/by-nc-sa/4.0/)

## Dokumentation

**Inhaltsverzeichnis**

1. [Funktionsumfang](#1-funktionsumfang)
2. [Voraussetzungen](#2-voraussetzungen)
3. [Installation](#3-installation)
4. [Funktionsreferenz](#4-funktionsreferenz)
5. [Konfiguration](#5-konfiguration)
6. [Anhang](#6-anhang)
7. [Versions-Historie](#7-versions-historie)

## 1. Funktionsumfang

Bereitstellung/Verteilung von Skripten für Symcon-Systeme; es kann sich um ein Git-Repository handeln oder um ein ZIP-Archiv.
Wenn man mehrere getrennte Bereiche hat, wird für jedes eine eigene Instanz benötigt.

Das Script-Archiv ist wie folgt aufgebaut:
- es gibt eine Datei namens _dictionary.json_, die drei Teile beinhaltet
  - "version": eine (beliebige) Bezeichnung der Version
  - "timestamp": UNIX-Timestamp des Veröffentlichungsdatums dieser Version
  - "files": ein Arry mit Elementen folgender Struktur
    - "filename": Name der Script-Datei, ist gleichzeitig der eindeutieg Ident
    - "location": Position im IPS-Baum, aufgebaut in der üblichen IPS-Syntax
    - "name": Name des Scriptes
    - "requires": Array mit Schlüsselworten (siehe unten)
- ein Verzeichnis "files", in dem ѕich die im _dictionary.json_ aufgeführten Dateien und dem o.g. "filename" finden.
- weitere Dateien werden ignoriert

Um in den Scripten Installations-spezifische Werte (z.B. aber nicht nur Objekt-ID's) genutzen zu können, gibt es ein Standardardscript [Basisfunktionen](docs/basefunctions.php),
das per **__autoload.php** geladen wird. Diese Basisfuktion **GetLocalConfig()** liefert zu einem übergebenen _Ident_ eine Wert zurück.
Das können zu inkludierende Scriptsammlungen sein, die mittels _include_/_require_ geladen werden
`require_once IPS_GetScriptFile(GetLocalConfig('GLOBAL_HELPER'));`
Oder andere, individuelle Angaben (wie Passwörter, Rechnernamen etc ...). 
Diese spezifischen Angaben finden sich dann ja korrekterweise nur in der lokalen Installation in dieser einen Datei, in den verteilten Scripten finden sich nur Platzhalter.

Um eine solche **__autoload.php** zu erzeugen, kann man sich zweier Hilfsfunktionen des Moduls bedienen, siehe [Autoload setzen](docs/generate_autoload.php).

Das o.g. Element "requires" enthält eine Liste der _Ident_, die in _GetLocalConfig()_ behandelt werden müssen - fehlen diese, wird das moniert.

## 2. Voraussetzungen

- IP-Symcon ab Version 6.0
- ein oder mehrere git-Repository oder ZIP-Archive mit den zu verteilenden Scripten

## 3. Installation

### a. Installation des Moduls

Im [Module Store](https://www.symcon.de/service/dokumentation/komponenten/verwaltungskonsole/module-store/) ist das Modul unter dem Suchbegriff *ScriptDeployment* zu finden.<br>
Alternativ kann das Modul über [Module Control](https://www.symcon.de/service/dokumentation/modulreferenz/module-control/) unter Angabe der URL `https://github.com/demel42/IPSymconScriptDeployment.git` installiert werden.

### b. Einrichtung in IPS

## 4. Funktionsreferenz

`string ScriptDeployment_ReadAutoload(int $InstanzID, string $err);`
Liefert den aktuellen Inhalt der *__autoload.php*, eventuelle Fehler stehen in _err_.

`bool ScriptDeployment_WriteAutoload(int $InstanzID, string $content, bool $overwrite, string $err);
Erzeugt oder überschreibt eine *__autoload.php* mit dem Inhalt _content_, eventuelle Fehler stehen in _err_.
die *__autoload.php* erfordert nach Änderungen/Anlage ein Reboot des IPS.

## 5. Konfiguration

### ScriptDeployment

#### Properties

| Eigenschaft               | Typ      | Standardwert   | Beschreibung |
| :------------------------ | :------  | :------------- | :----------- |
| Instanz deaktivieren      | boolean  | false          | Instanz temporär deaktivieren |
|                           |          |                | |
| Paketname                 | string   |                | aussagekräftige Bezeichung des Paketes |
| URL zum Herunterladen     | string   |                | optionale Angabe einer Web-Adresse, unter der das Paket per _wget_ heruntergeladen werden kann _[1]_ |
|                           |          |                | |
| Lokale Pfad               | string   |                | lokaler Pfad, an dem die Archive ausgepackt werden können etc _[2]_ |
|                           |          |                | |
| Uhrzeit                   |          |                | Uhrzeit zur zyklischen Prüfung _[3]_ |
|                           |          |                | |
| Mapping-Funktion          |          | GetLocalConfig | Angabe der Funktion zum Mapping von Schlüsselwerten _[4]_ |

_[1]_: wenn es sich um ein Git-Repository handelt, wäre die Adresse wie folgt: *https://*_github._*com/\<git-user>/\<repository>/archive/\<branch>.zip*

_[2]_: der Inhalt dieser Pfade ist nur temporär relevant und muss nicht erhalten bleiben, wird im zweifelsfall wieder hergestellt

_[3]_: es wird ggfs, die o,g, URL heruntergeladen und der Istzustand ermittelt; dieser wird in der Statusvariable _Zustand_ abgelegt (_synchronisiert_, _lokal geändert_, _aktualisierbar_, _unklar_, _fehlerhaft_).

_[4]_: die angegebene Funktion bekommt ein _Ident_ übergeben und liefert einen Schlüsselwert zurück (siehe auch [Basisfunktionen](docs/basefunctions.php)).

#### Aktionen

 - **Prüfung durchführen**<br>
Paket herunterladen und Prüfung durchführen
- **Fehlende Skripte suchen**<br>
Skripte der Tabelle, die noch nicht mit einem vorhandenen Skript verknüpft sind, anhand des Namens suchen - dient dazu, Systeme nachträglich einzubinden.
- **Abgleich durchführen**<br>
Anpassen der lokalen Installation an die aktuelle Version des Paketes. Lokal geänderte Script werden überschreiben, fehlende Skripte angelegt.

- **ZIP-Archiv laden**<br>
Falls das Paket nicht mittels _wget_ geholt werden kann, kann es mit dieser Funktion händisch geladen werden. Aufbau des ZIP-Archivs wie oben beschrieben.

_nur bei makiertem Eintrag:_
- **Skript öffnen**<br>
das Skript des markierten Eintrags öffnen
- **Eintrag anzeigen**<br>
markierten Eintrag anzeigen
- **Skript verknüpfen**<br>
den markierten Eintrag mit einem vorhandenen Skript verknüpfen
- **Eintrag löschen**<br>
Fehlerhaften Eintrag in der Liste der Dateien händisch löschen

Die Identifikation der Skripte erfolgt über den Skript-Dateinamen (in dem Archiv) sowie der Verknüpfung mit dem IPS-Skriptobjekt - festgehalten werden die in einem Medienobjekt _Liste der Skripte_.
Sollte diese Datei gelöscht werden, muss sie wieder erstellt werden (mittels der obigen Funktionen aber gut möglich).

Zwischen der Funktionen gibt es eine Liste der Skripte des Pakets mit Zustand der vorhergehenden Prüfung, etwas detaillierter in _Eintrag anzeigen_.

Es gibt zwei Medienobjekte _Archiv der neuen Version_ und _Archiv der lokalen Version_, die die jeweiligen Archive enthält sowie zwei Medienobjekte, die Diff's enthalten (_Lokale Änderungen_ und _Änderungen zur neuesten Version_).
Anmerkung: die Erzeugung der Differenzen gibt es zur Zeit noch nicht auf Windows-basierten Systemen - da fehlt ein entsprechendes Systemprogramm.

Als Beispiel für Pakete können [Basics](https://github.com/demel42/ips-deployment-basics) oder [Inventory](https://github.com/demel42/ips-deployment-inventory) dienen.

### Variablenprofile

Es werden folgende Variablenprofile angelegt:
* Integer<br>
ScriptDeployment.State

## 6. Anhang

### GUIDs
- Modul: `{9FBBBEB0-F06A-0938-6FD2-74DB4FE41387}`
- Instanzen:
  - ScriptDeployment: `{DD4FF2E3-F527-9F6A-80CA-34E1C7065917}`
- Nachrichten:

### Quellen

## 7. Versions-Historie

- 1.3 @ 15.04.2024 11:51
  - Fix: Prüfung von "REQUIRES" wurde unter Umständen nicht korrekt durchgeführt
  - Fix: mögliches Problem bei der initialen Einrichtung behoben
  - Fix: Erkennung von neu im Repository hinzugefügte Dateien korrigiert

- 1.2 @ 21.02.2024 11:12
  - Fix: Absicherung gegen fehlenden Medienobjekte/Medienobjekt-Dateien
  - update submodule CommonStubs

- 1.1 @ 07.02.2024 14:35 
  - Fix: Fehler im Info-Fenster des Eintrags

- 1.0 @ 06.02.2024 14:57
  - Initiale Version
