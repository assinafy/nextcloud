# Assinafy para Nextcloud

*Português · [Read in English](README.en.md)*

O Assinafy para Nextcloud envia arquivos PDF armazenados no Nextcloud para a API da Assinafy, acompanha documentos e termos de contrato e gerencia endpoints de webhook. Cada usuário conecta um workspace da Assinafy com OAuth 2.0 Authorization Code e PKCE; os tokens de acesso e de atualização ficam criptografados na configuração do Nextcloud desse usuário.

## Requisitos

- Nextcloud 31–35
- PHP 8.3, 8.4 ou 8.5 com JSON, mbstring e OpenSSL
- HTTPS no servidor Nextcloud e nos destinos de webhook
- Um cliente OAuth público da Assinafy com a URL de retorno exibida nas Configurações de administração do Nextcloud
- Um plano da Assinafy e permissões de membro que permitam as operações de assinatura, contratos e webhooks solicitadas

## Instalação

Quando o app estiver disponível na App Store do Nextcloud, instale o **Assinafy** em **Aplicativos**. Para instalar um pacote de versão manualmente, extraia-o em um diretório de apps do Nextcloud com permissão de escrita; o diretório instalado deve se chamar `assinafy`.

```sh
cd /caminho/para/nextcloud/apps
tar -xzf assinafy-0.3.1.tar.gz
php /caminho/para/nextcloud/occ app:enable assinafy
```

Os pacotes de versão são assinados para a App Store do Nextcloud e contêm um único diretório `assinafy/` na raiz.

## Configurar o OAuth

1. No Nextcloud, abra **Configurações de administração → Assinafy**.
2. Copie a URL de retorno exibida.
3. Cadastre na Assinafy um cliente OAuth público com exatamente essa URL de retorno. Não configure um segredo de cliente.
4. Conceda estes escopos: `documents:read`, `documents:write`, `templates:read`, `templates:write`, `account:read`, `webhooks:write`, `openid`, `profile`, `email` e `offline_access`.
5. Cole o ID do cliente OAuth no Nextcloud e salve.

A integração descobre os endpoints de autorização, token, revogação e recurso protegido. Ela aceita apenas o emissor configurado da Assinafy e endpoints HTTPS nas origens esperadas da Assinafy.

## Fluxo completo

1. Abra **Assinafy** na navegação de aplicativos do Nextcloud.
2. Selecione **Conectar à Assinafy**, autorize o conjunto fixo de escopos e escolha exatamente um workspace da Assinafy.
3. Em **Enviar**, navegue pelos arquivos do usuário atual no Nextcloud e selecione um PDF de até 25 MB.
4. Defina o título do documento na Assinafy e adicione de 1 a 50 signatários.
5. Escolha uma forma de verificação para cada signatário:

   - **E-mail:** verificação `Email` com notificação por e-mail.
   - **WhatsApp:** verificação `Whatsapp` com notificação por WhatsApp; é obrigatório um telefone em formato internacional.
   - **Identidade biométrica:** verificação `IdDocumentFaceMatchBasic`; é obrigatório um CPF válido.
   - **Certificado digital A1 / A3:** verificação `DigitalCertificate`; é obrigatório um CPF ou CNPJ válido, incluindo o formato de CNPJ alfanumérico.

   As verificações por e-mail e WhatsApp usam o canal de notificação correspondente. As verificações biométrica e por certificado digital podem notificar por e-mail ou WhatsApp; a notificação por WhatsApp exige um telefone em formato internacional.

6. Escolha assinatura em paralelo ou em ordem. Na assinatura em ordem, cada signatário recebe um `step` iniciado em 1. Um signatário com certificado digital que tenha cossignatários exige assinatura em ordem, para que cada signatário com certificado fique sozinho em sua etapa.
7. Opcionalmente, adicione a mensagem aos signatários, o prazo de assinatura e os termos do ciclo de vida do contrato.
8. Selecione **Revisar preço**. O app envia o PDF, aguarda o processamento dos metadados na Assinafy e solicita a estimativa atual da solicitação `virtual`.
9. Revise o total de créditos, os saldos, as cobranças detalhadas, o uso de documento extra e a disponibilidade de recursos.
10. Selecione **Enviar para assinatura**. O servidor obtém uma nova estimativa e recusa o envio quando algum componente da cobrança mudou ou os recursos são insuficientes.
11. Use **Documentos** para consultar o estado recente dos documentos na Assinafy.
12. Use **Contratos** para pesquisar os termos acompanhados, editá-los com a pré-condição obrigatória do `updated_at` atual, renová-los a partir do termo vigente ou encerrá-los com um motivo opcional.
13. Use **Webhooks** para criar, pausar, retomar, excluir ou rotacionar o segredo de um endpoint de webhook; consultar as entregas recentes; e reenviar uma entrega que falhou.

Quando a assinatura de webhooks estiver habilitada, guarde imediatamente o segredo `whsec_…` retornado. Aplicativos OAuth só o recebem quando a Assinafy o cria ou rotaciona. Os receptores devem verificar os cabeçalhos do Standard Webhooks sobre o corpo da requisição sem alterações e rejeitar carimbos de tempo antigos.

## Limites de operação

- O seletor de arquivos usa a API de arquivos do Nextcloud restrita ao usuário. Um ID de arquivo fora da árvore de arquivos do usuário autenticado é rejeitado.
- O envio usa `method: "virtual"`; nenhum campo de assinatura é posicionado no documento.
- A1 e A3 são mídias de certificado ICP-Brasil do lado do signatário, tratadas pelo mesmo método `DigitalCertificate`.
- Conflitos de e-mail, de telefone de WhatsApp ou de CPF/CNPJ de um contato interrompem o envio em vez de alterar silenciosamente um contato existente na Assinafy.
- Se a assinatura for criada, mas o armazenamento dos termos do contrato falhar, a resposta informa a solicitação de assinatura e, separadamente, o erro do contrato.
- A Assinafy não publica um campo atômico de preço máximo nem de idempotência para a criação da solicitação de assinatura. O app refaz a estimativa imediatamente antes da criação; ainda assim, uma perda de conexão durante a criação pode deixar o resultado final desconhecido. Consulte **Documentos** antes de iniciar outra solicitação a partir do mesmo arquivo.
- Ao desconectar, o app remove a autorização criptografada local antes de tentar revogar o token.
- Ao excluir uma conta de usuário do Nextcloud, o app remove a autorização criptografada e tenta revogar o token desse usuário antes da exclusão.

## Documentação

- [Guia do usuário](docs/USER.md) (em inglês)
- [Guia de administração](docs/ADMIN.md) (em inglês)
- [API da extensão](docs/API.md) (em inglês)
- [Desenvolvimento e versões](docs/DEVELOPMENT.md) (em inglês)
- [Política de segurança](SECURITY.md) (em inglês)

As referências de origem são a [API da Assinafy](https://api.assinafy.com.br/v1/docs) e o [guia de desenvolvimento de apps do Nextcloud](https://docs.nextcloud.com/server/stable/developer_manual/).

## Desenvolvimento

```sh
composer install
make check
```

O `make check` executa as verificações de sintaxe e estilo, os testes sem dependências e do PHPUnit, o Psalm contra a API OCP do Nextcloud, os testes de JavaScript e as verificações de metadados e traduções. O `make package` gera `build/assinafy-<versão>.tar.gz` a partir do `HEAD` com commit e o assina quando a chave e o certificado da App Store estão presentes; veja [Desenvolvimento e versões](docs/DEVELOPMENT.md).

## Licença

MIT
