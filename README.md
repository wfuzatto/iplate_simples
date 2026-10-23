# iPlate Simples

App Android para vistoria fotográfica de veículos vinculados a hóspedes, desenvolvido **separadamente** do [iPlate original](https://github.com/wfuzatto/iplate).

## Funcionalidades
- Login de operador e configurações de URL do servidor.
- Busca por número completo de reserva na API Expresso atualizada, **através do backend** (credenciais Expresso jamais ficam no APK).
- Busca por nome: requer habilitar o banco de hotelaria configurado no backend; o contrato público documentado da API Expresso **não** possui endpoint de busca por nome.
- Placa digitada ou reconhecida via OCR local ML Kit a partir da fotografia, editável pelo operador.
- Seis fotografias obrigatórias: placa, frente, lateral direita, lateral esquerda, traseira e teto.
- **+ Foto de detalhe**: fotos adicionais com descrição opcional (amassados, vidros trincados etc.), zoom, remoção antes do envio e anexação adicional após salvar o veículo; vínculo SQL pelo ID da vistoria.
- Arquivos originais JPEG da câmera preservados, upload multipart **sem redimensionamento/recompressão**, e visualização ampliável com pinça; prévia no Android usa amostragem para evitar estouro de memória.
- Sem QR Code, marca/modelo, FIPE ou consulta externa de veículos.

## Android
Abra a pasta `android/` no Android Studio ou execute `gradle :app:assembleDebug` usando Java 17 e Gradle 8.7. O APK de debug também é publicado como artefato no workflow **Android APK** do GitHub Actions. Aplicativo separado: `com.iplate.simples`.

## Backend
Instale o conteúdo de `backend/` em um diretório HTTPS próprio; não precisa alterar o código do iPlate original. Use MySQL e aplique `backend/schema.sql`. O servidor aceita usuários ativos da tabela `iplate_users` já existente (se usar o mesmo banco do iPlate) e grava as vistorias em `iplate_simples_entries`. Caso use novo banco, crie o usuário com hash `password_hash(PASSWORD_DEFAULT)` e token gerado aleatoriamente (64 hex), nunca com senha padrão.

Configure por variáveis de ambiente ou em `backend/config.local.php` (modelo em `backend/config.local.example.php`; **não versionar credenciais**):
- `IPLATE_DB_HOST`, `IPLATE_DB_NAME`, `IPLATE_DB_USER`, `IPLATE_DB_PASSWORD`
- `IPLATE_EXPRESSO_CPF`, `IPLATE_EXPRESSO_PASSWORD`
- `HOTELARIA_DB_HOST`, `HOTELARIA_DB_NAME`, `HOTELARIA_DB_USER`, `HOTELARIA_DB_PASSWORD` (opcional para nome)
- `IPLATE_SIM_NAME_SEARCH=1` habilita busca por nome no banco complementar com o esquema `reservas`, `reserva_hospedes`, `hospedes`.

Endpoints: `api/login.php` (POST form: username/password), `api/reservation-search.php` (POST JSON: api_token, term, mode=code|name), `api/entry-create.php` (POST multipart: api_token, plate, reservation, seis fotos e detalhes opcionais) e `api/detail-photos-append.php` (POST multipart para acrescentar detalhes a uma vistoria do operador já salva). **Instalações anteriores devem aplicar `backend/migrations/2026-10-detail-photos.sql` antes de enviar novas fotos.**

**Importante:** A pesquisa por nome exige base de hotelaria local e correspondência real entre o código dessa base e os códigos do Expresso. Resultados encontrados por nome são revalidados via Expresso por código antes de vincular a vistoria. Se não existir essa fonte, o app mostra a indisponibilidade em vez de fingir que o Expresso pesquisa nomes.

As seis fotos ocupam espaço e banda: ajuste `upload_max_filesize` e `post_max_size` do PHP (por exemplo 20M e 130M), `max_file_uploads` e a capacidade do servidor. Fotos de veículos e nomes são dados pessoais; proteja o diretório de upload com controle de acesso e políticas de retenção. Os dados não devem ser disponibilizados publicamente.

## Validação
A compilação Android/CI é uma verificação técnica, não substitui teste com conta Expresso, banco de hotelaria e câmera física. Não colocar fotos, senhas ou números de reservas reais no Git.
