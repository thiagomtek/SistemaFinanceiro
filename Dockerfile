ARG PHP_VERSION=7.4

# Biblioteca Web Push (mesma versao do ERP, via composer.lock). Estagio separado: a imagem final nao leva o Composer.
FROM composer:2.2 AS vendor
WORKDIR /app
COPY composer.json composer.lock ./
RUN composer install --no-dev --no-interaction --no-progress --optimize-autoloader --ignore-platform-reqs

# Mesma base do ERP (php:7.4-apache). O repositorio apt do Debian Bullseye foi arquivado, entao
# usamos o mesmo ajuste de sources e o instalador de extensoes do ERP. O codigo e compativel com PHP 7.4+.
FROM php:${PHP_VERSION}-apache

RUN echo "deb http://archive.debian.org/debian bullseye main contrib non-free" > /etc/apt/sources.list \
    && echo 'Acquire::Check-Valid-Until "false";' > /etc/apt/apt.conf.d/99no-check-valid-until

# qpdf: remove a senha de PDFs protegidos antes da leitura da fatura por IA
RUN apt-get update && apt-get install -y --no-install-recommends qpdf && rm -rf /var/lib/apt/lists/*

COPY --from=mlocati/php-extension-installer /usr/bin/install-php-extensions /usr/local/bin/
RUN install-php-extensions pdo_mysql mbstring zip opcache curl \
    && a2enmod headers

COPY docker/php.ini /usr/local/etc/php/conf.d/financeiro.ini
# zz-: carrega depois do security.conf do Debian (que define ServerTokens OS) e prevalece
COPY docker/apache-financeiro.conf /etc/apache2/conf-available/zz-financeiro.conf
RUN a2enconf zz-financeiro

# Somente o que deve ser servido: index.php e sistema/. sql/, tools/ e uploads ficam fora do DocumentRoot.
COPY index.php /var/www/html/index.php
COPY sistema /var/www/html/sistema
# Web Push: fora do DocumentRoot (o Apache nao serve /var/www/vendor)
COPY --from=vendor /app/vendor /var/www/vendor

ENV UPLOAD_DIR=/var/www/uploads
RUN mkdir -p /var/www/uploads && chown -R www-data:www-data /var/www/uploads
