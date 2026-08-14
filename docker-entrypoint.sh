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

# Apache aborts with "More than one MPM loaded" if more than one process
# module is enabled. Something in the platform's runtime re-enables mpm_event
# after the image is built, so disabling it at build time does not survive -
# the symlinks have to be forced here, immediately before Apache starts.
# mod_php is not thread safe, so prefork is the one that must win.
rm -f /etc/apache2/mods-enabled/mpm_event.* \
      /etc/apache2/mods-enabled/mpm_worker.*
ln -sf /etc/apache2/mods-available/mpm_prefork.load /etc/apache2/mods-enabled/
ln -sf /etc/apache2/mods-available/mpm_prefork.conf /etc/apache2/mods-enabled/

# uploads/ is a mounted volume in production, so it arrives owned by root and
# the Dockerfile's build-time chown no longer applies to it. Apache runs as
# www-data and must be able to write college logos here.
mkdir -p /var/www/html/uploads/logos
chown -R www-data:www-data /var/www/html/uploads

apache2ctl -t 2>&1 || true

exec "$@"
