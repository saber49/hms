#!/usr/bin/env bash
# Validate the /objects/contacts SQL directly against a tenant DB that has contacts.
set -u
BE=/var/www/hms-backend-staging; cd $BE
ENV_PW=$(grep -E '^DB_PASSWORD=' .env | cut -d= -f2- | tr -d '"'); ENV_USER=$(grep -E '^DB_USERNAME=' .env | cut -d= -f2- | tr -d '"')
DB=$(mysql -u"$ENV_USER" -p"$ENV_PW" -N -e "SELECT \`database\` FROM hms_central_staging.tenants" 2>/dev/null | while read db; do
  c=$(mysql -u"$ENV_USER" -p"$ENV_PW" -N "$db" -e "SELECT COUNT(*) FROM objekte WHERE TRIM(COALESCE(ansprechpartner,''))<>''" 2>/dev/null); [ "${c:-0}" -gt 0 ] && echo "$db"; done | head -n1)
echo "tenant with contacts: $DB"
mysql -u"$ENV_USER" -p"$ENV_PW" "$DB" 2>&1 <<'SQL' | grep -v 'Using a password'
SELECT TRIM(o.ansprechpartner) AS name,
       MAX(NULLIF(TRIM(o.kontakt_telefon), '')) AS telefon,
       MAX(NULLIF(TRIM(o.kontakt_email), ''))   AS email,
       COUNT(*) AS objekte,
       MAX(CASE WHEN o.kunden_id = 'K001' THEN 1 ELSE 0 END) AS same_kunde,
       GROUP_CONCAT(DISTINCT o.objektname ORDER BY o.objektname SEPARATOR ', ') AS objektnamen,
       GROUP_CONCAT(DISTINCT k.kundenname SEPARATOR ', ') AS kundennamen
  FROM objekte o LEFT JOIN kunden k ON o.kunden_id = k.kunde_id
 WHERE TRIM(COALESCE(o.ansprechpartner, '')) <> ''
   AND (o.ansprechpartner LIKE '%so%' OR o.kontakt_telefon LIKE '%so%' OR o.kontakt_email LIKE '%so%')
 GROUP BY name ORDER BY same_kunde DESC, objekte DESC, name LIMIT 8;
SQL
