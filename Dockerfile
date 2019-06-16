FROM php:fpm

RUN apt-get update && apt-get install -y libpq-dev libicu-dev libgmp-dev zlib1g-dev libcurl4-openssl-dev libssl-dev unzip git
RUN docker-php-ext-install pdo_pgsql intl gmp
RUN pecl install raphf propro
RUN docker-php-ext-enable raphf propro
RUN pecl install pecl_http
RUN docker-php-ext-enable http

RUN curl https://getcomposer.org/installer | php -- --install-dir=/usr/local/bin --filename=composer

RUN mkdir -p /usr/src/app
WORKDIR /usr/src/app

COPY composer.json /usr/src/app/
COPY composer.lock /usr/src/app/

RUN composer install

COPY . /usr/src/app
