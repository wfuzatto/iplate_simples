<?php
// Renomeie para config.local.php, fora do Git. Em produção, prefira variáveis de ambiente.
return [
    'db' => ['host'=>'127.0.0.1','port'=>3306,'database'=>'iplate',
             'username'=>'USUARIO_DB','password'=>'SENHA_DB'],
    'expresso' => ['cpf'=>'CPF_API_EXPRESSO','password'=>'SENHA_API_EXPRESSO'],
    'name_search' => false, // true apenas se houver a base local de hotelaria compatível
    'hotelaria_db' => ['host'=>'127.0.0.1','port'=>3306,'database'=>'hotelaria',
                      'username'=>'USUARIO_DB','password'=>'SENHA_DB'],
    'upload_dir' => '/caminho/fora/do/public_html/iplate_simples_uploads',
];
