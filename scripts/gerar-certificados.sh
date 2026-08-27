#!/usr/bin/env bash
#
# Gera a CA interna e o certificado do servidor para o Chat Interno.
#
# POR QUE ISTO EXISTE
# -------------------
# O navegador só expõe a Notification API e o Service Worker em "contexto
# seguro" (HTTPS ou localhost). Em http://<ip>:8188 o Chrome NEGA a permissão de
# notificação automaticamente — nenhum pop-up aparece, por melhor que seja o
# código do front. Ver docs/notificacoes.md.
#
# E um certificado autoassinado sem CA confiável NÃO resolve: mesmo depois de o
# usuário clicar em "aceitar o risco", o navegador se recusa a registrar o
# Service Worker numa origem com certificado não confiável. Por isso o caminho é
# uma CA interna, distribuída para as estações.
#
# USO
# ---
#   ./scripts/gerar-certificados.sh chat.empresa.local 192.168.0.50
#
# Gera em docker/nginx/certs/:
#   ca.crt    → distribuir para as estações (GPO no Windows)
#   ca.key    → GUARDAR EM LOCAL SEGURO. Quem tem esta chave emite certificado
#               confiável para qualquer site nas máquinas que confiam na CA.
#   chat.crt  → certificado do servidor (montado no nginx)
#   chat.key  → chave do servidor
#
# Reexecutar com a CA já existente reaproveita a CA e só reemite o certificado
# do servidor — as estações não precisam reinstalar nada.

set -euo pipefail

DOMINIO="${1:-}"
IP="${2:-}"

if [ -z "$DOMINIO" ]; then
    cat >&2 <<AJUDA
Uso: $0 <dominio> [ip]

  <dominio>  nome DNS pelo qual o sistema será acessado (ex.: chat.empresa.local)
  [ip]       IP da VM, opcional mas recomendado — permite acessar por
             https://<ip> sem erro de certificado

Exemplo:
  $0 chat.empresa.local 192.168.0.50
AJUDA
    exit 1
fi

DIRETORIO="$(cd "$(dirname "$0")/.." && pwd)/docker/nginx/certs"
mkdir -p "$DIRETORIO"
cd "$DIRETORIO"

# ── CA interna ───────────────────────────────────────────────────────────────
if [ -f ca.crt ] && [ -f ca.key ]; then
    echo "→ CA já existe em $DIRETORIO/ca.crt — reaproveitando."
else
    echo "→ Gerando CA interna (validade 10 anos)…"
    openssl req -x509 -newkey rsa:4096 -days 3650 -nodes \
        -keyout ca.key -out ca.crt \
        -subj "/C=BR/O=Chat Interno/CN=Chat Interno CA" 2>/dev/null
    chmod 600 ca.key
fi

# ── Certificado do servidor ──────────────────────────────────────────────────
echo "→ Gerando certificado do servidor para $DOMINIO${IP:+ e $IP}…"

openssl req -newkey rsa:2048 -nodes \
    -keyout chat.key -out chat.csr \
    -subj "/C=BR/O=Chat Interno/CN=$DOMINIO" 2>/dev/null

# SAN com DNS *e* IP: navegadores ignoram o CN desde 2017, e sem o IP no SAN o
# acesso por https://<ip> continua dando erro de certificado.
{
    printf 'subjectAltName = DNS:%s' "$DOMINIO"
    [ -n "$IP" ] && printf ', IP:%s' "$IP"
    printf '\nkeyUsage = digitalSignature, keyEncipherment\n'
    printf 'extendedKeyUsage = serverAuth\n'
} > chat.ext

# 825 dias é o teto aceito pelo Chrome e pelo Safari para certificado de servidor.
openssl x509 -req -in chat.csr -CA ca.crt -CAkey ca.key -CAcreateserial \
    -out chat.crt -days 825 -sha256 -extfile chat.ext 2>/dev/null

chmod 600 chat.key
rm -f chat.csr chat.ext

echo
echo "✓ Certificados gerados em $DIRETORIO"
echo
echo "Próximos passos:"
echo "  1. docker compose up -d --build nginx      # o entrypoint detecta o certificado"
echo "  2. Distribua o ca.crt para as estações:"
echo "       Windows/GPO: Computer Configuration → Policies → Windows Settings →"
echo "                    Security Settings → Public Key Policies →"
echo "                    Trusted Root Certification Authorities"
echo "       Firefox:     usa armazenamento próprio — ative"
echo "                    security.enterprise_roots.enabled ou importe a CA por política"
echo "  3. Acesse por https://$DOMINIO e confirme o cadeado SEM aviso"
echo "  4. Só então peça aos usuários para permitir as notificações"
echo
echo "⚠  Guarde ca.key em local seguro e NÃO versione este diretório."
