# ============================================================
# Dockerfile – PrintManager-Native Web-Service
#
# Basiert auf php:8.3-apache und installiert alle benötigten
# PHP-Erweiterungen für die MariaDB/MySQL-Anbindung via PDO.
# Das Bild wird automatisch durch podman-compose gebaut.
# ============================================================
FROM php:8.3-apache

# -------------------------------------------------------
# PHP-Erweiterungen installieren
# docker-php-ext-install ist ein Helfer-Script im offiziellen
# PHP-Image, das Erweiterungen kompiliert und aktiviert.
# -------------------------------------------------------
RUN docker-php-ext-install \
        pdo \
        pdo_mysql \
        mysqli

# -------------------------------------------------------
# Apache mod_rewrite aktivieren (nützlich für saubere URLs)
# -------------------------------------------------------
RUN a2enmod rewrite

# -------------------------------------------------------
# PHP-Konfiguration anpassen
# -------------------------------------------------------
# Zeitzone setzen (für korrekte Datums-/Zeitangaben)
RUN echo "date.timezone = Europe/Berlin" > /usr/local/etc/php/conf.d/timezone.ini

# Fehleranzeige im Entwicklungsmodus aktivieren
# In Produktion auf 0 setzen und error_log verwenden
RUN echo "display_errors = On"  > /usr/local/etc/php/conf.d/errors.ini && \
    echo "error_reporting = E_ALL" >> /usr/local/etc/php/conf.d/errors.ini

# -------------------------------------------------------
# Quellcode in den Container kopieren
# (wird durch das Volume in podman-compose überschrieben,
#  sodass Änderungen sofort wirksam sind ohne Rebuild)
# -------------------------------------------------------
COPY html/ /var/www/html/
