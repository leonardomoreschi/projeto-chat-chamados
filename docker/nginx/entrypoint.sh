#!/bin/sh
# Habilita o bloco HTTPS só quando o certificado existe.
#
# Um `ssl_certificate` apontando para arquivo inexistente faz o nginx recusar a
# configuração inteira — o sistema ficaria fora do ar por causa de uma feature
# opcional. Por isso a decisão é em tempo de boot, não em tempo de build.
set -e

CERT=/etc/nginx/certs/chat.crt
CHAVE=/etc/nginx/certs/chat.key
DESTINO=/etc/nginx/conf.d/tls.conf

if [ -f "$CERT" ] && [ -f "$CHAVE" ]; then
    cp /etc/nginx/tls-disponivel/tls.conf "$DESTINO"
    echo "[nginx] TLS habilitado: $CERT"
else
    rm -f "$DESTINO"
    echo "[nginx] Sem certificado em $CERT — servindo apenas HTTP."
    echo "[nginx] ATENÇÃO: em origem insegura o navegador bloqueia notificações."
    echo "[nginx] Gere os certificados com scripts/gerar-certificados.sh."
fi

exec "$@"
