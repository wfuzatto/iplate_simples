<?php
declare(strict_types=1);
if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }
require dirname(__DIR__) . '/lib/ExpressoReservationClient.php';
$passed = 0;

function check(bool $ok, string $name): void
{
    global $passed;
    if (!$ok) throw new RuntimeException('FAIL: ' . $name);
    $passed++;
}

function fixture(array $response, mixed $token = 'fixture-token'): ExpressoReservationClient
{
    return new ExpressoReservationClient(
        ['cpf' => 'fixture-cpf', 'password' => 'fixture-password'],
        static function (string $url, array $body) use ($response, $token): array {
            if (str_ends_with($url, '/obter_token')) {
                check($body === ['cpf' => 'fixture-cpf', 'password' => 'fixture-password'], 'CPF contract');
                return ['token' => $token];
            }
            check($body === ['token' => 'fixture-token', 'numero_reserva' => '2505371'], 'reservation contract');
            return $response;
        }
    );
}

$sample = ['reserva_id' => 55, 'numero_reserva' => '2505371',
    'hospede_nome' => 'Pessoa de teste', 'hospede_cpf' => '12345678901', 'data_checkin' => '2030-01-15',
    'data_checkout' => '2030-01-17', 'adultos' => 2, 'criancas' => 1, 'status' => 'confirmada'];
foreach (['data', 'reserva', 'root'] as $wrapper) {
    $json = $wrapper === 'root' ? $sample : [$wrapper => $sample];
    $r = fixture($json)->lookup('2505371');
    check($r['reservation_code'] === '2505371', 'code: ' . $wrapper);
    check($r['guest_count'] === 3 && $r['checkin_date'] === '2030-01-15', 'mapping: ' . $wrapper);
    check($r['guest_cpf'] === '12345678901', 'CPF guest mapped: ' . $wrapper);
    check($r['reservation_id'] === '55', 'preserve reservation id: ' . $wrapper);
}
$alphanumericId = $sample; $alphanumericId['reserva_id'] = 'A-55';
check(fixture(['reserva' => $alphanumericId])->lookup('2505371')['reservation_id'] === 'A-55', 'non-numeric id preserved');
$idOnly = $sample; unset($idOnly['numero_reserva']);
check(fixture(['data' => $idOnly])->lookup('2505371')['reservation_code'] === '2505371', 'id-only response');
$cancelled = $sample; $cancelled['status'] = 'cancelada';
check(fixture(['reserva' => $cancelled])->lookup('2505371') === [], 'cancelled not eligible');
foreach ([
    ['status' => 'fail'],
    ['data' => ['numero_reserva' => '9999999']],
    ['data' => []],
    ['success' => false],
    ['data' => array_merge($sample, ['status' => 'error'])],
] as $case) {
    try { fixture($case)->lookup('2505371'); throw new LogicException('Expected failure'); }
    catch (RuntimeException $e) { check(true, 'reject invalid response'); }
}
foreach ([null, '', 'null'] as $token) {
    try { fixture([], $token)->lookup('2505371'); throw new LogicException('Expected token failure'); }
    catch (RuntimeException $e) { check(true, 'reject bad token'); }
}
echo "PASS: {$passed} fixture assertions (without contacting Expresso).\n";
