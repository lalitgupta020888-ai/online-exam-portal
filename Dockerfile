# Online Exam Portal - production image
# Mirrors the XAMPP stack the project was written against: Apache + PHP 8.2.
FROM php:8.2-apache

# pdo_mysql is the only extension the code needs beyond the defaults
# (mbstring ships enabled in the official image).
RUN docker-php-ext-install pdo_mysql \
    && a2enmod rewrite \
    && rm -rf /var/lib/apt/lists/*

# Recommended production PHP settings, plus room for question-paper uploads.
RUN mv "$PHP_INI_DIR/php.ini-production" "$PHP_INI_DIR/php.ini" \
    && printf 'upload_max_filesize=16M\npost_max_size=16M\n' \
       > "$PHP_INI_DIR/conf.d/uploads.ini"

WORKDIR /var/www/html
COPY . /var/www/html

# Apache serves as www-data; it must be able to write uploaded college logos.
RUN chown -R www-data:www-data /var/www/html/uploads

# Railway assigns the port at runtime via $PORT; Apache is hardcoded to 80
# in the base image, so rewrite it on boot before handing over.
COPY docker-entrypoint.sh /usr/local/bin/
RUN chmod +x /usr/local/bin/docker-entrypoint.sh

ENTRYPOINT ["docker-entrypoint.sh"]
CMD ["apache2-foreground"]
