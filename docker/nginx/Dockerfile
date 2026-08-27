FROM nginx:1.25-alpine

LABEL description="Nginx otimizado para Slim Framework"

# Healthcheck usa wget, então a ferramenta precisa existir na imagem
RUN apk add --no-cache wget

# Remover configuração padrão
RUN rm /etc/nginx/conf.d/default.conf

# Configuração: servidor HTTP + locations compartilhados.
COPY docker/nginx/default.conf /etc/nginx/conf.d/
COPY docker/nginx/snippets/ /etc/nginx/snippets/

# O bloco TLS fica FORA de conf.d: o entrypoint só o copia para lá se houver
# certificado montado em /etc/nginx/certs.
COPY docker/nginx/tls.conf /etc/nginx/tls-disponivel/tls.conf
COPY docker/nginx/entrypoint.sh /usr/local/bin/entrypoint.sh
RUN chmod +x /usr/local/bin/entrypoint.sh

# Health check
HEALTHCHECK --interval=30s --timeout=3s --retries=3 \
    CMD wget --quiet --tries=1 --spider http://localhost/health || exit 1

EXPOSE 80 443

ENTRYPOINT ["/usr/local/bin/entrypoint.sh"]
CMD ["nginx", "-g", "daemon off;"]
