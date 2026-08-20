# ============================================
# STAGE 1: Base com extensões PHP
# ============================================
FROM php:8.3-fpm-alpine AS base

# Metadados
LABEL maintainer="DevOps Team <devops@empresa.com>"
LABEL description="Chat Interno - PHP Runtime com extensões otimizadas"

# Instalar dependências do sistema e extensões PHP
RUN apk add --no-cache \
    # Bibliotecas necessárias
    libzip-dev \
    libpng-dev \
    libjpeg-turbo-dev \
    freetype-dev \
    icu-dev \
    oniguruma-dev \
    mysql-client \
    netcat-openbsd \
    linux-headers \
    # Ferramentas de build (serão removidas depois)
    $PHPIZE_DEPS \
    && docker-php-ext-configure gd --with-freetype --with-jpeg \
    && docker-php-ext-install -j$(nproc) \
        pdo_mysql \
        mysqli \
        zip \
        gd \
        intl \
        mbstring \
        sockets \
        pcntl \
        opcache \
    && apk del $PHPIZE_DEPS \
    && rm -rf /var/cache/apk/*

# Configurar PHP para produção
COPY docker/php/php.ini /usr/local/etc/php/conf.d/custom.ini

# ============================================
# STAGE 2: Composer + Dependências
# ============================================
FROM composer:2 AS dependencies

WORKDIR /app

# Copiar apenas arquivos de dependências primeiro (cache layer)
COPY composer.json composer.lock ./

# Instalar dependências em modo produção (sem dev)
RUN composer install \
    --no-dev \
    --no-scripts \
    --no-autoloader \
    --prefer-dist \
    --optimize-autoloader

# Copiar código-fonte
COPY . .

# Gerar autoloader otimizado
RUN composer dump-autoload --optimize --classmap-authoritative

# ============================================
# STAGE 3: Runtime Final
# ============================================
FROM base AS production

WORKDIR /var/www/html

# Criar usuário não-privilegiado (mesmo UID do www-data)
RUN addgroup -g 82 -S www-data 2>/dev/null || true \
    && adduser -u 82 -D -S -G www-data www-data 2>/dev/null || true

# Copiar código com dependências do stage anterior
COPY --from=dependencies --chown=www-data:www-data /app .

# Criar diretórios necessários com permissões corretas
RUN mkdir -p \
    public/uploads \
    var/logs \
    /var/lib/php/sessions \
    && chown -R www-data:www-data public/uploads var/logs /var/lib/php/sessions \
    && chmod -R 775 public/uploads \
    && chmod 700 /var/lib/php/sessions

# Expor porta do PHP-FPM
EXPOSE 9000

# Health check
HEALTHCHECK --interval=30s --timeout=3s --start-period=40s --retries=3 \
    CMD php-fpm -t || exit 1

# Entrypoint para ajustes dinâmicos
COPY --chmod=755 entrypoint.sh /usr/local/bin/entrypoint.sh
ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]

CMD ["php-fpm"]