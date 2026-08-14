#!/bin/sh
set -e

# Railway (and most PaaS hosts) tell the app which port to bind via $PORT.
PORT="${PORT:-80}"
sed -i "s/^Listen 80$/Listen ${PORT}/" /etc/apache2/ports.conf
sed -i "s/:80>/:${PORT}>/" /etc/apache2/sites-available/000-default.conf

# Quieten the "could not reliably determine the server's fully qualified
# domain name" warning that otherwise heads every boot log.
echo "ServerName localhost" > /etc/apache2/conf-available/servername.conf
a2enconf servername >/dev/null 2>&1 || true

exec "$@"
