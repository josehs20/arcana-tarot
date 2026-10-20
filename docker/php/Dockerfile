# syntax=docker/dockerfile:1

# PHP 8.4 FPM (Alpine) — versão documentada na stack do projeto (README/DECISIONS).
FROM php:8.4-fpm-alpine

# ---------------------------------------------------------------------------
# Extensões PHP e justificativas:
#   pdo_mysql : driver do MySQL 8.4 (banco principal)
#   redis     : cliente Redis (cache, filas, sessões e Laravel Reverb)
#   intl      : localização pt_BR (formatação de datas/números)
#   gd        : processamento/validação das imagens das cartas de Tarot
#   bcmath    : precisão em cálculos decimais auxiliares (financeiro)
#   pcntl     : controle de processos (queue worker, Reverb)
#   sockets   : servidor WebSocket do Laravel Reverb
#   zip       : manipulação de arquivos compactados (Composer/uploads)
# ---------------------------------------------------------------------------
RUN apk add --no-cache --virtual .build-deps \
        $PHPIZE_DEPS \
        icu-dev \
        libzip-dev \
        libpng-dev \
        libjpeg-turbo-dev \
        freetype-dev \
        libwebp-dev \
        linux-headers \
    && apk add --no-cache \
        icu-libs \
        libzip \
        libpng \
        libjpeg-turbo \
        freetype \
        libwebp \
    && docker-php-ext-configure gd \
        --with-freetype \
        --with-jpeg \
        --with-webp \
    && docker-php-ext-install -j"$(nproc)" \
        bcmath \
        gd \
        intl \
        pdo_mysql \
        pcntl \
        sockets \
        zip \
    && pecl install redis \
    && docker-php-ext-enable redis \
    && apk del .build-deps \
    && rm -rf /tmp/pear /var/cache/apk/*

# Composer para instalar dependências dentro do container, se necessário.
COPY --from=composer:2 /usr/bin/composer /usr/bin/composer

# Usuário não-root (uid/gid 1000), alinhado ao dono dos arquivos no host.
RUN addgroup -g 1000 appuser \
    && adduser -D -u 1000 -G appuser appuser

# Pool do PHP-FPM escutando na porta 9000 para o Nginx.
COPY docker/php/www.conf /usr/local/etc/php-fpm.d/www.conf

WORKDIR /var/www/html

EXPOSE 9000

CMD ["php-fpm"]
