# Prompt 02 — Ambiente Docker Compose

Implemente ambiente local reproduzível para Arcana Tarot, respeitando a versão Laravel/PHP detectada e a documentação do projeto.

## Serviços desejados
- `app`: Laravel com PHP-FPM.
- `nginx`: servidor HTTP.
- `mysql`: MySQL 8.4 LTS.
- `redis`: cache e filas.
- `reverb`: processo Laravel Reverb.
- `queue`: worker de filas.
- Opcional em desenvolvimento: `mailpit` para testar e-mails.

## Requisitos
- Criar/ajustar `compose.yaml`, Dockerfiles e configuração Nginx.
- Usar volumes nomeados para dados persistentes.
- Healthchecks para serviços essenciais quando viável.
- Dependências entre serviços sem presumir que `depends_on` garante prontidão.
- Configurar o Laravel para usar nomes dos serviços Docker, não `localhost`, para MySQL/Redis dentro dos containers.
- Não usar `latest` em produção; fixe tags compatíveis.
- Não expor MySQL/Redis publicamente por padrão.
- Documentar comandos de subir, parar, logs, migrations e rebuild em `README.md`.
- Não colocar segredos reais no Compose.
- Não instalar extensões PHP sem justificar e validar compatibilidade.

## Verificações
Suba os serviços se Docker estiver disponível, verifique saúde, conexão Laravel-MySQL e Laravel-Redis. Se não puder executar Docker, declare explicitamente a limitação.
