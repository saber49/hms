# HMS Gruppe GmbH & Co. KG — Operations Management System

## Overview
A web-based operations pipeline system for managing customers, projects, orders, subcontractors, task deployments, and reporting.

**Flow:** Customer → Project → Incoming Order → Order → Tasks → Completion → Reports

## Tech Stack
- **Frontend:** HTML5, CSS3, JavaScript (Vanilla)
- **Backend:** PHP 7.4+
- **Database:** MySQL 5.7+ / MariaDB

## Setup Instructions

### 1. Database
1. Make sure MySQL/MariaDB is running (e.g., via XAMPP, WAMP, or Laragon)
2. Import the database schema:
   ```
   mysql -u root -p < database/schema.sql
   ```
   Or open `database/schema.sql` in phpMyAdmin and execute it.

### 2. Configuration
Edit `config/database.php` if your database credentials differ:
```php
$host = 'localhost';
$dbname = 'hms_system';
$username = 'root';
$password = '';
```

### 3. Run
Place the project folder in your web server's document root (e.g., `htdocs` for XAMPP) and open:
```
http://localhost/HMS%20System/
```

## Project Structure
```
HMS System/
├── api/                    # PHP REST API endpoints
│   ├── customers.php       # Kunden CRUD
│   ├── objects.php         # Objekte & Artikel CRUD
│   ├── subcontractors.php  # Nachunternehmer CRUD
│   ├── incoming_orders.php # Bestelleingang CRUD
│   ├── orders.php          # Bestellungen CRUD + conversion
│   ├── tasks.php           # Einsätze CRUD
│   ├── price_history.php   # Preishistorie
│   ├── reports.php         # Auswertung & Controlling
│   ├── disposition.php     # Dispo & Monatsabschluss
│   └── lists.php           # System lists / config
├── assets/
│   ├── css/style.css       # Main stylesheet
│   └── js/app.js           # Main application logic
├── config/
│   └── database.php        # DB connection & helpers
├── database/
│   └── schema.sql          # Full database schema
├── index.html              # Main dashboard (SPA)
├── order_form.html         # Printable order confirmation
└── README.md
```

## Modules
| Module | Description |
|--------|------------|
| **Kunden** | Customer master data |
| **Objekte & Artikel** | Projects/locations with pricing |
| **Nachunternehmer** | Subcontractor management |
| **Preishistorie** | Price change tracking |
| **Bestelleingang** | Incoming order wizard (5 steps) |
| **Bestellungen** | Official orders (converted from incoming) |
| **Einsätze** | Task/deployment execution records |
| **Dispo & Abschluss** | Monthly closure & bulk confirmation |
| **Auswertung** | Reports, KPIs, profit analysis |
| **Listen & Konfig** | Dropdown values & system settings |
