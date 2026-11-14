# HMS Mitarbeiter App — Deployment Guide

## Übersicht

Die Mitarbeiter-App ist eine eigenständige PWA (Progressive Web App) im Ordner `worker/`.
Sie teilt nur die Datenbank mit dem bestehenden HMS-System. Keine bestehenden Dateien werden verändert.

---

## 1. Datenbank vorbereiten

Zwei neue Spalten in der Tabelle `mitarbeiter` sind erforderlich:

```sql
ALTER TABLE mitarbeiter ADD COLUMN IF NOT EXISTS password_hash VARCHAR(255) DEFAULT NULL;
ALTER TABLE mitarbeiter ADD COLUMN IF NOT EXISTS app_zugang TINYINT(1) DEFAULT 0;
```

Standard-Passwort für alle aktiven Mitarbeiter setzen (HMS2026!):

```sql
UPDATE mitarbeiter
SET password_hash = '$2y$10$...', app_zugang = 1
WHERE aktiv = 1 AND (password_hash IS NULL OR password_hash = '');
```

> Alternativ: Die Datei `worker/migrate.php` im Browser aufrufen — sie führt alle drei Befehle automatisch aus.
> **Nach erfolgreicher Migration die Datei `migrate.php` vom Server löschen.**

---

## 2. Dateien hochladen

Den gesamten `worker/` Ordner auf den IONOS-Server hochladen, neben die bestehenden Ordner (`api/`, `assets/`, `config/` usw.).

### Ordnerstruktur auf dem Server nach dem Upload:

```
/                            ← Bestehend (unverändert)
├── api/
├── assets/
├── config/
├── index.php
├── login.php
├── ...
└── worker/                  ← NEU — komplett hochladen
    ├── config/
    │   └── database.php
    ├── api/
    │   ├── auth.php
    │   ├── shifts.php
    │   └── feed.php
    ├── assets/
    │   ├── css/
    │   │   └── app.css
    │   ├── js/
    │   │   └── app.js
    │   └── icons/
    │       ├── icon-192.png
    │       └── icon-512.png
    ├── index.php
    ├── login.php
    ├── manifest.json
    ├── sw.js
    └── migrate.php
```

---

## 3. Icons ersetzen

Die zwei Platzhalter-Dateien durch echte App-Icons ersetzen:

- `worker/assets/icons/icon-192.png` — 192×192 Pixel, PNG
- `worker/assets/icons/icon-512.png` — 512×512 Pixel, PNG

Diese Icons erscheinen auf dem Startbildschirm des Handys, wenn die App installiert wird.

---

## 4. Testen

Die App im Browser öffnen:

```
https://[domain]/worker/login.php
```

Anmeldedaten:
- **Dienstnummer:** Dienst-Nr. eines aktiven Mitarbeiters (z.B. D001)
- **Passwort:** HMS2026! (Standard nach Migration)

### Funktionen prüfen:

- **Login** — Anmeldung mit Dienstnummer + Passwort
- **Feed** — Begrüßung, heutige Schichten, Feed mit kommenden Wochen
- **Plan** — Wochenansicht mit Schichtkarten (erscheinen erst nach "Geplant" in der Dispo)
- **Profil** — Mitarbeiter-Info, Passwort ändern, Abmelden

### Zusammenspiel mit der Dispo prüfen:

1. Im Admin-System einen Einsatz öffnen
2. Mitarbeiter zuweisen (Checkboxen)
3. Notizen/Bemerkung eintragen
4. Auf "Geplant" klicken
5. In der Mitarbeiter-App prüfen: Schicht muss jetzt im Plan sichtbar sein

---

## 5. Aufräumen

Nach erfolgreichem Test:

- [ ] `worker/migrate.php` vom Server löschen
- [ ] Standard-Passwörter der Mitarbeiter ändern lassen (jeder Mitarbeiter über Profil → Passwort ändern)

---

## 6. App auf Handy installieren

Die Mitarbeiter können die App auf ihrem Handy installieren:

1. `https://[domain]/worker/` im Handy-Browser öffnen
2. Anmelden
3. Browser-Menü → "Zum Startbildschirm hinzufügen"
4. Die App erscheint als Icon auf dem Startbildschirm

---

## Technische Hinweise

- **Datenbankverbindung:** `worker/config/database.php` nutzt automatisch die bestehende `config/db_credentials.php`. Kein Doppelkonfiguration nötig.
- **Sessions:** Die Worker-App verwendet eigene Session-Keys (`worker_dienst_nr`, `worker_id` usw.), die nicht mit dem Admin-System kollidieren.
- **Sichtbarkeit:** Schichten erscheinen in der App **nur** wenn `einsaetze.pruefung = 'geplant'` gesetzt ist (= nach Klick auf "Geplant" in der Dispo).
- **Bestehende Dateien:** Es werden keine bestehenden Dateien verändert. Die App ist zu 100% additiv.
