#!/usr/bin/env bash
# Smoke-test the Controlling endpoints (dashboard, worker analysis, drill-down)
# against staging (default) or prod:  bash test-controlling-endpoints.sh [BASE_URL]
set -u
BASE="${1:-https://staging.clood-x.com}"

TOKEN=$(curl -s -X POST "$BASE/api/auth/demo-login" | sed -E 's/.*"token":"([^"]+)".*/\1/')
if [ -z "$TOKEN" ] || [ "${#TOKEN}" -gt 200 ]; then echo "FAIL: no token"; exit 1; fi
echo "token ok: ${TOKEN:0:10}..."
H="Authorization: Bearer $TOKEN"

q() { printf '%-64s -> ' "$1"; curl -s -o /tmp/r.json -w '%{http_code} ' "$BASE$1" -H "$H"; head -c 140 /tmp/r.json | tr '\n' ' '; echo; }
j() { python3 -c "import json,sys; d=json.load(open('/tmp/r.json')); $1" 2>/dev/null; }

q "/api/controlling/dashboard?period=month"
j "print('   dashboard keys:', sorted(d.keys()))"
q "/api/controlling/kpi?period=year"
q "/api/controlling/mitarbeiter-options"
DN=$(j "print(d[0]['dienst_nr'] if d else '')")
echo "   first dienst_nr: ${DN:-<none>}"
if [ -n "$DN" ]; then
  q "/api/controlling/mitarbeiter/$DN?period=year"
  j "print('   worker kpi:', d['kpi']); print('   by_objekt:', len(d['by_objekt']), 'einsaetze:', len(d['einsaetze']), 'by_weekday:', len(d['by_weekday']), 'by_start_hour:', len(d['by_start_hour']))"
  q "/api/controlling/kpi?period=year&dienst_nr=$DN"
fi

q "/api/controlling/breakdown/customers?period=year&limit=1"
KID=$(j "print(d['rows'][0]['dim_id'] if d.get('rows') else '')")
echo "   first kunde: ${KID:-<none>}"
if [ -n "$KID" ]; then
  q "/api/controlling/drilldown/kunde/$KID?period=year"
  j "print('   drill keys:', sorted(d.keys())); print('   breakdowns:', {k: len(v) for k, v in d['breakdowns'].items()}); print('   einsaetze:', len(d['einsaetze']), '| kpi einnahmen:', d['kpi']['einnahmen'], '| share:', d['share']['einnahmen_pct'], '% | stammdaten:', len(d['entity']['stammdaten']))"
fi
q "/api/controlling/breakdown/objects?period=year&limit=1"
OID=$(j "print(d['rows'][0]['dim_id'] if d.get('rows') else '')")
[ -n "$OID" ] && q "/api/controlling/drilldown/objekt/$OID?period=year" && j "print('   parent:', d['entity'].get('parent'))"
q "/api/controlling/breakdown/artikel?period=year&limit=1"
AID=$(j "print(d['rows'][0]['dim_id'] if d.get('rows') else '')")
[ -n "$AID" ] && q "/api/controlling/drilldown/artikel/$AID?period=year"
q "/api/controlling/breakdown/subcontractors?period=year&limit=1"
NID=$(j "print(d['rows'][0]['dim_id'] if d.get('rows') else '')")
[ -n "$NID" ] && q "/api/controlling/drilldown/nu/$NID?period=year"
[ -n "$DN" ] && q "/api/controlling/drilldown/mitarbeiter/$DN?period=year"
q "/api/controlling/drilldown/schichtart/tagdienst?period=year"
q "/api/controlling/breakdown/bereich?period=year"
BER=$(j "print(d['rows'][0]['dim_value'] if d.get('rows') else '')")
[ -n "$BER" ] && q "/api/controlling/drilldown/bereich/$(python3 -c "import urllib.parse,sys;print(urllib.parse.quote(sys.argv[1]))" "$BER")?period=year"
q "/api/controlling/drilldown/unknown/x?period=year"
q "/api/controlling/details?period=month&per_page=10"
j "print('   details row keys has ids:', all(k in d['rows'][0] for k in ('kunden_id','obj_id','artikel_id')) if d.get('rows') else 'no rows')"
q "/api/reports?monat=9&jahr=2026"
q "/api/dashboard/overview"
