FROM php:8.4-apache

RUN apt-get update \
    && apt-get install -y --no-install-recommends python3 python3-venv libsndfile1 ffmpeg libpq-dev \
    && rm -rf /var/lib/apt/lists/* \
    && docker-php-ext-install pdo_pgsql \
    && a2enmod rewrite

COPY requirements.txt /tmp/requirements.txt
RUN python3 -m venv /opt/venv && /opt/venv/bin/pip install --no-cache-dir -r /tmp/requirements.txt

RUN printf 'upload_max_filesize=4M\npost_max_size=4608K\nmax_execution_time=260\n' \
        > /usr/local/etc/php/conf.d/uploads.ini \
    && sed -i 's/AllowOverride None/AllowOverride All/' /etc/apache2/apache2.conf

ENV PYTHON=/opt/venv/bin/python NUMBA_CACHE_DIR=/tmp/numba PORT=80
EXPOSE 80

COPY . /var/www/html/
