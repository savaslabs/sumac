FROM composer/composer:1.1
MAINTAINER Kosta Harlan <kosta@savaslabs.com>

COPY . /app
WORKDIR /app
RUN composer install -n --prefer-dist

ENTRYPOINT ["php", "sumac.php"]
