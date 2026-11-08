FROM drupal:11-php8.4-apache

# Install necessary packages plus libxml2-dev for the PHP soap extension and
# libxslt1-dev for the xsl extension (required by itk-dev/serviceplatformen >= 1.9).
# soap is required transitively by itk-dev/serviceplatformen via
# wsdltophp/packagebase, which aabenforms_digital_post consumes.
RUN set -eux; \
    apt-get update; \
    apt-get install -y --no-install-recommends git nano default-mysql-client unzip libxml2-dev libxslt1-dev

# Core PHP extensions not preinstalled in the drupal:11-php8.4 base.
RUN docker-php-ext-install -j"$(nproc)" soap xsl

# Install Redis extension using pecl
RUN pecl install redis \
    && docker-php-ext-enable redis

# Create the necessary directories and set permissions
RUN mkdir -p /opt/drupal/web/sites/default/files && \
    mkdir -p /opt/drupal/web/sites/default/files/assets/js && \
    mkdir -p /opt/drupal/web/sites/default/files/translations && \
    mkdir -p /opt/drupal/backups && \
    chown -R www-data:www-data /opt/drupal/web/sites/default/files && \
    chmod -R 775 /opt/drupal/web/sites/default/files

# Copy files to /opt/drupal
COPY composer.json /opt/drupal/
COPY composer.lock /opt/drupal/
COPY config /opt/drupal/config/
COPY settings.php /opt/drupal/web/sites/default/settings.php
COPY web/modules/custom /opt/drupal/web/modules/custom/
COPY web/profiles/custom /opt/drupal/web/profiles/custom/

# Install PHP dependencies
WORKDIR /opt/drupal
RUN composer install --no-dev --no-interaction --optimize-autoloader

# Code is owned by root and read-only for the web server; only the paths
# Drupal writes to belong to www-data. A web-writable vendor/, core/ or
# settings.php would turn any file-write bug into persistent code execution.
RUN chown -R root:root /opt/drupal && \
    find /opt/drupal -type d -exec chmod 755 {} + && \
    find /opt/drupal -type f -exec chmod 644 {} + && \
    chmod 444 /opt/drupal/web/sites/default/settings.php && \
    chown -R www-data:www-data /opt/drupal/web/sites/default/files /opt/drupal/backups && \
    chmod -R 775 /opt/drupal/web/sites/default/files /opt/drupal/backups

# Set the working directory
WORKDIR /opt/drupal

# Ensure Apache runs as the correct user
CMD ["apache2-foreground"]
