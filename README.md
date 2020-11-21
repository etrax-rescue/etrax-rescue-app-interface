# eTrax | rescue App Schnittstelle

Diese Serverapplikation ist die Schnittstelle zwischen der _eTrax | rescue_ [webapp](https://github.com/etrax-rescue/webapp) und der _eTrax | rescue_ [App](https://github.com/etrax-rescue/etrax-rescue-app). Sie implementiert die [API Spezifikation](https://github.com/etrax-rescue/etrax-rescue-app/blob/main/openapi.yaml) die sich die App erwartet, wenn sie mit dem Server kommuniziert. 

# Installation

Die Applikation wurde mithilfe des [Lumen Frameworks](https://lumen.laravel.com/) in php entwickelt. Bevor die Serverapplikation auf einem Server aufgesetzt werden kann muss [composer](https://getcomposer.org/) installiert sein.

```bash
git clone https://github.com/etrax-rescue/etrax-app-interface.git
cd etrax-app-interface/

# Installieren der php Dependencies
composer install
```

# Konfiguration

Die Konfiguration der Applikation wird mit einem Environment File (.env) vorgenommen. Um dieses einzurichten kann das Beispielfile _.env.example_ kopiert und anschließend editiert werden.

```bash
cp .env.example .env
```

Folgende Variablen müssen angepasst werden, bevor das App Interface einsatzbereit ist:

| Variable | Funktion |
| ------   | -------  |
| APP_KEY  | Der Schlüssel der für die Datenbankverschlüsselung verwendet wird (liegt in secure/secret.php) |
| ETRAX_BASE_PATH | Pfad (relativ zum _public_ Verzeichnis) zum eTrax \| rescue server webroot |
| STATUS_UPDATE_URL | URL des BOS Interfaces |
| SECURE_PATH | Relativer Pfad zum _secure_ Verzeichnis der eTrax \| rescue Installation |
| TOKEN_MAX_AGE | Maximal zulässige Gültigkeitsdauer des Zugriffstokens in Sekunden |
| MAX_CACHE_TIME | Maximaler Zeitrahmen in Sekunden in dem Bildressourcen in der App gecached werden |
| DB_* | Konfiguration der Verbindung mit der Datenbank auf der auch die Daten der eTrax \| rescue Webapp gespeichert sind |
