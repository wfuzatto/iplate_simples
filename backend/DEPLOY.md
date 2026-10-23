# Instalação do iPlate Simples na Prodatas Telecom (sem alterar o iPlate original)

**Separação dos sistemas:** o domínio Prodatas Telecom recebe login do operador,
cadastros, fotos de veículos e todos os anexos de danos. O serviço Vale Expresso
é consultado **somente pelo backend PHP da Prodatas** para validar reservas
via CPF/senha, sem receber fotos nem documentos de veículos.

O aplicativo sugere `https://prodatastelecom.com.br/iplate_simples/backend`
como URL de implantação futura, **ainda não confirmada em produção**.
Se o diretório real da hospedagem for diferente (por exemplo `/sites/...`),
ajuste o caminho nas configurações e use o botão de testar.

**O APK não funciona para login ou envio de fotos apenas com a URL do backend antigo.**
O novo aplicativo chama `api/health.php`, `api/login.php`,
`api/reservation-search.php` e `api/entry-create.php`, que devem existir
dentro de uma instalação **separada** do conteúdo de `backend/`.

## 1. Pasta HTTPS

Estrutura sugerida se o site público for `https://prodatastelecom.com.br/iplate_simples/backend`:

```text
public_html/
  iplate/                        <-- sistema original; não modificar
  iplate_simples/
    backend/
      api/
        health.php
        login.php
        reservation-search.php
        entry-create.php
      lib/
        ExpressoReservationClient.php
      config.php
      config.local.php             <-- criar no servidor, não subir para Git
      db.php
      schema.sql
```

No aplicativo, em **Configurações**, deixe o valor sugerido **somente se a implantação realmente utilizar esse caminho**:

```text
https://prodatastelecom.com.br/iplate_simples/backend
```

**Não** informe endereços da API Vale Expresso ou do iPlate antigo como URL de fotos:
- `https://prodatastelecom.com.br/iplate/backend/api/vehicle-entry-create.php`
- `https://prodatastelecom.com.br/iplate/backend/api/login.php` (ainda é o projeto antigo)
- `https://vale.expresso.app/api/obter_token` (autenticação Expresso feita no servidor)

O app suporta colar uma URL terminada em `/api/login.php` e extrai sua raiz,
mas ainda exige o endpoint de identificação **do backend novo**.

## 2. Testar pelo navegador

Abra a URL completa abaixo, substituindo o domínio e diretório instalados:

```text
https://prodatastelecom.com.br/iplate_simples/backend/api/health.php
```

O resultado esperado é exatamente um objeto JSON semelhante a:

```json
{"success":true,"app":"iplate_simples","contract_version":1}
```

Se aparecer 404, HTML, erro de PHP, redirecionamento ou resposta sobre placa,
marca, modelo e cinco fotos, a URL **não está apontando para a API correta**.
Verifique publicação, diretório e redirecionamentos (`.htaccess`) da hospedagem.

## 3. Banco de dados e credenciais

Execute `schema.sql` no banco que o novo backend acessará. As novas
vistorias vão para **`iplate_simples_entries`**, sem alterar a tabela
`iplate_vehicle_entries` da aplicação original.

Se desejar reutilizar os logins de operador (por exemplo o já cadastrado
no iPlate), configure o **mesmo banco** do iPlate original. O novo
backend valida usuários ativos via `iplate_users`; ele não recria
senhas nem altera os tokens. Caso use banco novo, use o CLI
`php backend/bin/create-user.php nome_operador "Nome completo"`.

Configure `config.local.php` conforme `config.local.example.php`,
incluindo CPF/senha da API Expresso no backend da Prodatas (jamais dentro do APK).
A autenticação Expresso usa `POST /api/obter_token`, com `cpf`
e `password`, seguida de `POST /api/reserva` usando token e código.

Para capturar e enviar as seis fotografias originais e os detalhes extras **na Prodatas**, configure
`IPLATE_SIM_UPLOAD_DIR` como **diretório existente e gravável fora do
public_html**. Defina também `upload_max_filesize` e `post_max_size`
compatíveis com seis fotos (por exemplo, 25M e 170M), além de limites do
proxy e servidor web. Não habilite listagem pública desse diretório.

## 4. Teste no celular

1. Instale a revisão mais recente do APK pelo workflow Android APK.
2. Toque na engrenagem, informe a raiz do backend recém-instalado e use
   **Testar endereço da API**. Se passar, salve a URL.
3. Entre com o usuário e senha do operador do banco configurado.
4. O cadastro de fotos pode ser aberto imediatamente com
   **Abrir cadastro das fotos antes da reserva**, mas o **envio** exige
   uma reserva real validada pelo Expresso.
5. A opção **Testar captura das 6 fotos (sem servidor)** na tela inicial
   permite testar câmera, OCR e zoom **offline**. Não autentica, consulta
   reservas nem envia fotos.

**Observação:** a busca por nome não faz parte do contrato público da
API Expresso. Use a base complementar de hotelaria, se disponível.

## 5. Fotos adicionais de detalhes de danos (v1.2)

O formulário Android mostra **+ Foto de detalhe** depois das seis fotos principais.
Cada foto extra pode receber uma legenda, por exemplo "amassado porta direita"
ou "para-brisa trincado", e pode ser ampliada antes do envio.

- Antes do primeiro envio, as fotos extras são gravadas junto da vistoria;
  depois do primeiro envio, o operador pode registrar novos detalhes na
  **mesma vistoria** usando **Enviar mais detalhes para o veículo #ID**.
- Não existe limite fixo de fotos **por veículo**; os uploads são divididos
  em lotes de até 24 imagens de detalhe, mais as seis fotos principais no
  primeiro registro. Todos os arquivos ficam no diretório privado sem
  redimensionamento/recompressão.
- As seis fotos principais ficam em `iplate_simples_entries`. As extras ficam
  em `iplate_simples_detail_photos`, ligadas pela coluna
  `entry_id` à vistoria correspondente, ordenadas por `sequence_no` e
  com `description` opcional. O backend grava imagens e banco em uma
  transação, limpando arquivos novos se a inserção falhar.
- Para continuar anexando depois da primeira gravação, é preciso manter a
  vistoria aberta no aplicativo. Não há tela de histórico/reabertura ainda.
- O endpoint `api/detail-photos-append.php` exige o token de operador
  e permite acréscimos apenas à vistoria criada pelo próprio operador.

### Atualização de banco existente

Execute **uma vez** a migração:

```sql
SOURCE /caminho/backend/migrations/2026-10-detail-photos.sql;
```

Alternativamente, copie o conteúdo SQL para o phpMyAdmin (se `SOURCE`
não for permitido). Isso cria somente a tabela nova e não altera as
vistorias anteriores.

### Ajustes de tamanho no PHP e no proxy

A partir desta versão, o servidor precisa aceitar até 30 arquivos no
primeiro envio (6 principais + 24 extras), por exemplo:

```ini
upload_max_filesize=25M
max_file_uploads=35
post_max_size=800M
max_input_time=600
```

**Valores máximos, não recomendação universal:** a memória, espaço, tempo e
limites do provedor devem ser dimensionados antes de adotar upload de
centenas de MB, incluindo `client_max_body_size` do proxy, caso exista.
Recomenda-se enviar em lotes pequenos quando houver rede móvel instável.
O usuário poderá continuar anexando em lotes após salvar.
