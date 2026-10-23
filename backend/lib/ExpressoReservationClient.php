<?php
declare(strict_types=1);

/**
 * Contrato Expresso usado por Acqua Vale: CPF/senha -> token -> numero_reserva.
 * Credenciais exclusivamente no servidor; nunca incluídas no retorno JSON.
 */
final class ExpressoReservationClient
{
    private array $settings;
    private ?Closure $transport;

    public function __construct(array $settings, ?Closure $transport = null)
    {
        $this->settings = $settings;
        $this->transport = $transport;
    }

    public function lookup(string $code): array
    {
        $code = trim($code);
        if ($code === '') {
            throw new InvalidArgumentException('Informe o numero da reserva.');
        }

        $cpf = trim((string) ($this->settings['cpf'] ?? $this->settings['user'] ?? ''));
        $password = (string) ($this->settings['password'] ?? '');
        if ($cpf === '' || $password === '') {
            throw new RuntimeException('Configure o CPF e a senha da API Expresso no servidor.');
        }

        $tokenResponse = $this->post(
            (string) ($this->settings['token_url'] ?? 'https://vale.expresso.app/api/obter_token'),
            ['cpf' => $cpf, 'password' => $password],
            'autenticar'
        );

        $token = $this->read($tokenResponse, ['token']);
        if ($token === '') {
            throw new RuntimeException('A API Expresso nao forneceu um token valido.');
        }

        $response = $this->post(
            (string) ($this->settings['reservation_url'] ?? 'https://vale.expresso.app/api/reserva'),
            ['token' => $token, 'numero_reserva' => $code],
            'consultar reserva'
        );

        $apiStatus = strtolower($this->read($response, ['status']));
        if (in_array($apiStatus, ['fail', 'error'], true) || ($response['success'] ?? true) === false) {
            throw new RuntimeException('A API Expresso nao confirmou a reserva.');
        }

        $data = $response;
        foreach (['data', 'reserva'] as $key) {
            if (isset($response[$key]) && is_array($response[$key]) && !array_is_list($response[$key])) {
                $data = $response[$key];
                break;
            }
        }

        $id = $this->read($data, ['reserva_id', 'reservation_id', 'id']);
        $returnedCode = $this->read($data, [
            'numero_reserva', 'reservation_code', 'reserva', 'reservation', 'codigo', 'code',
        ]);
        if ($id === '' && $returnedCode === '') {
            throw new RuntimeException('Resposta Expresso sem identificacao valida da reserva.');
        }
        if ($returnedCode !== '' && $returnedCode !== $code) {
            throw new RuntimeException('A API retornou outra reserva, diferente da solicitada.');
        }

        $status = $this->read($data, ['status']);
        if (in_array(strtolower($status), ['fail', 'error'], true)) {
            throw new RuntimeException('A API Expresso rejeitou a consulta da reserva.');
        }
        if (in_array(strtolower($status), [
            'cancelada', 'cancelado', 'cancelled', 'canceled', 'no-show', 'noshow',
        ], true)) {
            return [];
        }

        $adults = $this->read($data, ['adultos', 'adults', 'numero_adultos', 'qt_pessoas']);
        $children = $this->read($data, ['criancas', 'children', 'numero_criancas']);
        $guestCount = $this->read($data, ['guest_count', 'total_hospedes']);
        if ($guestCount === '' && ($adults !== '' || $children !== '')) {
            $guestCount = (string) (max(0, (int) $adults) + max(0, (int) $children));
        }

        return [
            'reservation_id' => $id,
            'reservation_code' => $returnedCode !== '' ? $returnedCode : $code,
            'guest_name' => $this->read($data, [
                'hospede_nome', 'nome_hospede_principal', 'hospede_principal', 'guest_name', 'nome',
            ]),
            'guest_cpf' => $this->read($data, ['hospede_cpf', 'guest_cpf', 'cpf']),
            'checkin_date' => $this->read($data, ['data_checkin', 'checkin', 'checkin_date']),
            'checkout_date' => $this->read($data, ['data_checkout', 'checkout', 'checkout_date']),
            'guest_count' => max(0, (int) $guestCount),
            'status' => $status,
            'adults' => $adults,
            'children' => $children,
            'uh' => $this->read($data, ['uh', 'unidade_habitacional', 'room', 'apartamento']),
            'source' => 'expresso_api',
        ];
    }

    private function read(array $data, array $keys): string
    {
        foreach ($keys as $key) {
            $value = $data[$key] ?? null;
            if (is_string($value) || is_int($value) || is_float($value)) {
                $text = trim((string) $value);
                if ($text !== '' && strtolower($text) !== 'null') {
                    return $text;
                }
            }
        }
        return '';
    }

    private function post(string $url, array $payload, string $operation): array
    {
        if ($this->transport !== null) {
            $result = ($this->transport)($url, $payload);
            if (!is_array($result)) {
                throw new RuntimeException('Resposta de teste invalida.');
            }
            return $result;
        }
        if (!extension_loaded('curl')) {
            throw new RuntimeException('Extensao cURL indisponivel no servidor.');
        }
        if (parse_url($url, PHP_URL_SCHEME) !== 'https') {
            throw new RuntimeException('Endereco Expresso deve utilizar HTTPS.');
        }

        $timeout = max(5, min(45, (int) ($this->settings['timeout_seconds'] ?? 20)));
        $curl = curl_init($url);
        curl_setopt_array($curl, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => json_encode($payload, JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json; charset=utf-8',
                'Accept: application/json',
            ],
            CURLOPT_CONNECTTIMEOUT => min(10, $timeout),
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);
        $raw = curl_exec($curl);
        $http = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $curlError = curl_errno($curl);
        curl_close($curl);

        if ($raw === false || $curlError !== 0) {
            throw new RuntimeException("Falha de conexao com a API Expresso ao {$operation}.");
        }
        if ($http < 200 || $http >= 300) {
            throw new RuntimeException("A API Expresso retornou HTTP {$http} ao {$operation}.");
        }
        $json = json_decode((string) $raw, true);
        if (!is_array($json) || array_is_list($json)) {
            throw new RuntimeException("A API Expresso retornou JSON invalido ao {$operation}.");
        }
        return $json;
    }
}
