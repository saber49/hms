# Sicherheitskonzept – Clood-X (HMS Multi-Tenant SaaS)

**Stand:** Juni 2026  ·  **Produktiv unter:** https://clood-x.com  ·  **Hosting:** IONOS VPS (Rechenzentrum Deutschland/EU)

Dieses Dokument belegt mit konkreten technischen Nachweisen, dass die Plattform
nach gängigen Sicherheits-Best-Practices und mit Blick auf die **DSGVO (DSGVO/GDPR)**
betrieben wird. Alle Angaben sind anhand des Quellcodes und der Live-Server-Konfiguration verifiziert.

---

## 1. Datenhaltung & DSGVO-Standort

- **Hosting in der EU/Deutschland:** Server bei IONOS (deutscher Anbieter), Datacenter DE.
  → **Datenresidenz in der EU**, keine Drittlandübermittlung der Stammdaten.
- **KI-Verarbeitung in der EU:** Inferenz-Endpunkt `de-txl` (IONOS, Frankfurt-Region) bzw.
  Google Vertex Region `europe-west3` (Frankfurt). → KI-Daten verlassen die EU nicht.
- **CDN/TLS:** Cloudflare als vorgelagerter Reverse-Proxy (DDoS-Schutz, WAF-fähig, TLS-Terminierung).

---

## 2. Mandantentrennung (Multi-Tenancy) – Kernpunkt für die DSGVO

Die strikte Trennung der Kundendaten ist das wichtigste Datenschutzmerkmal:

- **Physisch getrennte Datenbanken pro Mandant** – nicht nur eine `tenant_id`-Spalte,
  sondern je Kunde eine **eigene MySQL-Datenbank** (`hms_tenant_*`). Zentrale Datenbank
  `hms_central` enthält nur globale Stammdaten/Mandantenregister.
- **Kontrollierter Verbindungswechsel:** `TenantService::switchToTenant()` ist der **einzige**
  Weg, die Tenant-Verbindung zu binden; vorher wird der Zustand vollständig zurückgesetzt
  (`reset()` + `DB::purge()`), damit unter Long-Running-Workern (FrankenPHP/Octane)
  **kein Datenbankzustand zwischen Requests „durchsickert“**.
- **Tripwire-Middleware `EnsureTenantBoundary`:** prüft nach jedem Auth-Resolver, dass die
  aktive DB-Verbindung exakt zum Mandanten des **Tokens** passt (nicht zum DB-Datensatz).
  Bei jeder Abweichung: **Fail-Closed** (Request wird abgebrochen) + `Log::critical`.

> Ergebnis: Selbst bei einem Programmierfehler ist ein mandantenübergreifender
> Datenzugriff technisch unterbunden.

---

## 3. Authentifizierung & Passwörter

- **Passwort-Hashing:** `bcrypt` / `Hash::make()` (Laravel-Standard, Cost-Faktor 12, `$2y$12$`).
  Klartext-Passwörter werden **nie** gespeichert.
- **API-Tokens:** Bearer-Tokens werden **SHA-256-gehasht** in `personal_access_tokens`
  abgelegt (Sanctum-Schema). Das Klartext-Token verlässt nur einmal bei Ausgabe den Server.
- **Token-Bindung an Mandant:** Token tragen die autoritative `tenant_id` in den `abilities`;
  daraus wird der Mandant aufgelöst (Worker-App, NU-Portal, Web).
- **Sensible Felder geschützt:** `password_hash` ist in den Models `$hidden` und wird in
  API-Antworten entfernt (`unset()`); Audit-Logs **redacten** `password`, `remember_token`,
  `verification_token` (`UserAuditLogger`).
- **Schutz gegen User-Enumeration:** Passwort-Reset nutzt timing-gleiche Dummy-Hash-Prüfung,
  damit nicht erkennbar ist, ob ein Konto existiert.

---

## 4. Brute-Force- & Missbrauchsschutz (Rate Limiting)

| Schutz | Limit | Schlüssel |
|---|---|---|
| Login | **5 / Minute** | pro E-Mail/Login |
| Passwort-Reset | **5 / Stunde** | pro E-Mail **und** pro IP |
| API (angemeldet) | 600 / Minute | pro Benutzer |
| API (anonym/Bot) | 60 / Minute | pro IP |
| KI-Assistent | 20 / Minute, 500 / Tag | pro Benutzer + Mandant |

Reset wird **per E-Mail und per IP** begrenzt → ein Angreifer kann weder ein Opfer
aussperren noch viele Konten durchprobieren.

---

## 5. Server-Härtung (Live verifiziert)

- **Firewall (UFW):** Eingehend nur **80/443 ausschließlich von Cloudflare-IP-Ranges**
  sowie SSH (22). Anwendungsports (FrankenPHP 8000/8001) sind **nach außen nicht erreichbar**.
- **Datenbank & Cache nur lokal:** MySQL (`127.0.0.1:3306`/`33060`) und Redis
  (`127.0.0.1:6379`) lauschen **ausschließlich auf localhost** – kein externer Zugriff möglich.
- **SSH gehärtet:** `PasswordAuthentication no`, `PubkeyAuthentication yes`,
  `KbdInteractiveAuthentication no`, Root nur per Schlüssel (`without-password`).
  → **Nur Login per SSH-Key**, keine Passwort-Logins.
- **fail2ban aktiv** (sshd-Jail) – automatische Sperre nach Fehlversuchen.
- **TLS/HTTPS** durchgängig über Cloudflare; Origin nur hinter Cloudflare ansprechbar.

---

## 6. Anwendungssicherheit (Framework-Ebene)

- **SQL-Injection-Schutz:** durchgängig Eloquent/Query-Builder mit parametrisierten Queries;
  dynamische DB-Namen werden validiert (`validateDatabaseName`).
- **CORS restriktiv:** nur `clood-x.com` (+ Subdomains) und die Capacitor-App-Origin;
  `supports_credentials=false`.
- **Standard-Schutzschichten von Laravel:** CSRF-Schutz (Web), Cookie-Verschlüsselung,
  Eingabe-Trimming/Normalisierung, Request-Size-Limits.
- **Rollen & Rechte:** Middleware `permission`, `super_admin`, `subscription.active`
  trennen Zugriffe nach Rolle und Vertragsstatus.
- **Audit-Logging:** Änderungen an Benutzerdaten werden protokolliert (PII-redaktiert).

---

## 7. Verschlüsselung – Überblick

- **In Transit:** TLS 1.2/1.3 (Cloudflare ↔ Client und Cloudflare ↔ Origin).
- **Passwörter:** bcrypt (Einweg-Hash).
- **Tokens:** SHA-256-Hash at rest.
- **App-Secrets:** in `.env` (nicht im Code/Repo), `APP_KEY` für Laravel-Verschlüsselung.

---

## 8. Geplante/empfohlene weitere Härtung (Reifegrad-Roadmap)

Zur transparenten Darstellung – diese Punkte erhöhen das Niveau zusätzlich:

- **DMARC verschärfen:** aktuell `p=none` (Monitoring) → schrittweise `quarantine`/`reject`.
- **DB-Verschlüsselung at rest** (LUKS/Volume-Encryption) als zusätzliche Schicht.
- **Zentrales Secrets-Management** (statt `.env`-Dateien) für Schlüsselrotation.
- **Regelmäßige Backups + Restore-Tests** dokumentieren (DSGVO Art. 32: Verfügbarkeit).
- **Auftragsverarbeitungsvertrag (AVV/DPA)** mit IONOS & Cloudflare ablegen.

---

## 9. Zusammenfassung für das Management

Die Plattform erfüllt zentrale Anforderungen aus **DSGVO Art. 32 (Sicherheit der Verarbeitung)**:

- **Vertraulichkeit:** physische Mandantentrennung + Fail-Closed-Tripwire, bcrypt, gehashte Tokens.
- **Integrität:** parametrisierte Queries, RBAC, Audit-Logs.
- **Verfügbarkeit:** gehärteter Server, Firewall, fail2ban, Cloudflare-DDoS-Schutz.
- **Datenresidenz:** Hosting und KI-Verarbeitung in der **EU/Deutschland**.

Das System ist **produktiv, gehärtet und übernahmebereit**.
