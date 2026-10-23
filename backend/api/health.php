<?php
declare(strict_types=1);
// Identificador público do contrato do iPlate Simples.
// Não expõe tokens, credenciais, dados de usuários ou detalhes internos.
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-IPlate-App: iplate_simples');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    echo json_encode(['success'=>false,'message'=>'Use GET.']);
    exit;
}
echo json_encode(['success'=>true,'app'=>'iplate_simples','contract_version'=>1],
    JSON_UNESCAPED_UNICODE);
