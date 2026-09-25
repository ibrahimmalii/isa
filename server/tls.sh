#!/usr/bin/env bash
# Origin TLS for Cloudflare "Full (strict)": a Let's Encrypt cert on the server + an HTTPS server block.
# Run as root after DNS points at the server (proxied is fine: HTTP-01 passes through Cloudflare).
#   sudo DOMAIN=isa-skin.com [EMAIL=you@example.com] bash server/tls.sh   (EMAIL = optional expiry notices)
set -euo pipefail
DOMAIN="${DOMAIN:-isa-skin.com}"
EMAIL="${EMAIL:-}"
if [ -n "$EMAIL" ]; then ACCOUNT=(--email "$EMAIL" --no-eff-email); else ACCOUNT=(--register-unsafely-without-email); fi
CONF=/etc/nginx/sites-available/isa

certbot certonly --webroot -w /var/www/isa -d "$DOMAIN" -d "www.$DOMAIN" \
	"${ACCOUNT[@]}" --agree-tos --non-interactive --keep-until-expiring

# Add a 443 listener to the existing server block (once).
if ! grep -q "listen 443" "$CONF"; then
	sed -i "0,/listen \[::\]:80;/s||listen [::]:80;\n\tlisten 443 ssl http2;\n\tlisten [::]:443 ssl http2;\n\tssl_certificate /etc/letsencrypt/live/$DOMAIN/fullchain.pem;\n\tssl_certificate_key /etc/letsencrypt/live/$DOMAIN/privkey.pem;\n\tssl_protocols TLSv1.2 TLSv1.3;|" "$CONF"
fi
nginx -t -q
systemctl reload nginx

# Renewals reload nginx.
mkdir -p /etc/letsencrypt/renewal-hooks/deploy
printf '#!/bin/sh\nsystemctl reload nginx\n' > /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
chmod +x /etc/letsencrypt/renewal-hooks/deploy/reload-nginx
echo "TLS ready for $DOMAIN (set Cloudflare SSL/TLS mode to Full (strict))."
