#!/usr/bin/env bash
# Add `location = /worker/ { return 302 /worker/index.php; }` to the prod + staging vhosts
# (the site-wide `index landing.html;` makes /worker/ answer 403). Safe: backup + nginx -t.
set -eu
for VH in /etc/nginx/sites-enabled/clood-x.com /etc/nginx/sites-enabled/staging.clood-x.com; do
  [ -f "$VH" ] || continue
  if grep -q 'location = /worker/' "$VH"; then echo "already present: $VH"; continue; fi
  cp -a "$VH" "$VH.bak-$(date +%Y%m%d%H%M%S)"
  # Insert right before the first `location /uploads/` block of the HTTPS server.
  python3 - "$VH" <<'PY'
import sys,re
p=sys.argv[1]; s=open(p).read()
snippet="    # Worker app: the directory URL has no landing.html â†’ send to the app entry.\n    location = /worker/ { return 302 /worker/index.php; }\n\n"
i=s.find("    location /uploads/")
if i<0: sys.exit("anchor not found in "+p)
open(p,"w").write(s[:i]+snippet+s[i:])
print("patched:",p)
PY
done
nginx -t && systemctl reload nginx && echo "nginx reloaded"
for u in https://clood-x.com/worker/ https://staging.clood-x.com/worker/; do printf '%-40s -> ' "$u"; curl -s -o /dev/null -w '%{http_code} -> %{redirect_url}\n' "$u"; done
curl -s -o /dev/null -w 'follow redirect: %{http_code}\n' -L https://clood-x.com/worker/
