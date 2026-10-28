FROM php:8.2-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-venv libsndfile1 ffmpeg \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_mysql

COPY requirements.txt /tmp/requirements.txt
RUN python3 -m venv /opt/venv && /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt

# Uploads up to the app's 9 MB limit, plus slack for the form fields.
RUN printf 'upload_max_filesize=10M\npost_max_size=12M\nmax_execution_time=300\n' \
        > /usr/local/etc/php/conf.d/uploads.ini \
    && a2enmod rewrite \
    && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

ENV PYTHON=/opt/venv/bin/python NUMBA_CACHE_DIR=/tmp/numba

COPY . /var/www/html/
RUN mkdir -p /var/www/html/uploads && chown -R www-data:www-data /var/www/html/uploads
