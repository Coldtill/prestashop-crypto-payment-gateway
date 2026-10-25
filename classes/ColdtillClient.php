<?php

if (!defined('_PS_VERSION_')) {
    exit;
}

/**
 * Talks to the Coldtill API and decides what a payment update means for an order. Nothing here depends on
 * PrestaShop, so tests/run.php can exercise it directly.
 */
class ColdtillClient
{
    public const PAID = 'paid';
    public const OVERPAID = 'overpaid';
    public const PARTIAL = 'partial';
    public const CANCEL = 'cancel';
    public const MANUAL = 'manual';
    public const MISMATCH = 'mismatch';
    public const NONE = 'none';

    private string $baseUrl;

    /** @var callable(string, string, array, ?string): array{0:int,1:string} */
    private $transport;

    public function __construct(string $baseUrl, private string $apiKey, ?callable $transport = null)
    {
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->transport = $transport ?? [self::class, 'curl'];
    }

    /**
     * @return array{host:string,reference_id:string,url:string}
     */
    public function createPayment(array $req): array
    {
        try {
            return $this->request('POST', '/payment', $req);
        } catch (RuntimeException $e) {
            // A project that limits return hosts rejects other stores; fall back to the project's Return URL.
            if (!isset($req['returnURL']) || $e->getCode() !== 400 || !str_contains($e->getMessage(), 'returnURL')) {
                throw $e;
            }
            unset($req['returnURL']);

            return $this->request('POST', '/payment', $req);
        }
    }

    public function getPayment(string $referenceId): array
    {
        return $this->request('GET', '/payment/reference/' . rawurlencode($referenceId));
    }

    private function request(string $method, string $path, ?array $body = null): array
    {
        $headers = ['API-Key: ' . $this->apiKey, 'Accept: application/json'];
        $json = null;
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
            $json = json_encode($body);
        }
        [$code, $raw] = ($this->transport)($method, $this->baseUrl . '/api/v1' . $path, $headers, $json);
        $data = json_decode($raw, true);
        if ($code < 200 || $code >= 300) {
            $msg = is_array($data) && isset($data['error']) ? (string) $data['error'] : 'HTTP ' . $code;
            throw new RuntimeException('Coldtill: ' . $msg, $code);
        }

        return is_array($data) ? $data : [];
    }

    /** @return array{0:int,1:string} */
    public static function curl(string $method, string $url, array $headers, ?string $body): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_CUSTOMREQUEST => $method,
            CURLOPT_HTTPHEADER => $headers,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 20,
            CURLOPT_CONNECTTIMEOUT => 20,
        ]);
        if ($body !== null) {
            curl_setopt($ch, CURLOPT_POSTFIELDS, $body);
        }
        $raw = curl_exec($ch);
        if ($raw === false) {
            $err = curl_error($ch);
            throw new RuntimeException('Coldtill is unreachable: ' . $err);
        }
        $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);

        return [$code, (string) $raw];
    }

    /** Only https keeps the API key private on the way to Coldtill; plain http is accepted for localhost testing. */
    public static function validBaseUrl(string $url): bool
    {
        if (filter_var($url, FILTER_VALIDATE_URL) === false) {
            return false;
        }
        $scheme = strtolower((string) parse_url($url, PHP_URL_SCHEME));
        $host = strtolower((string) parse_url($url, PHP_URL_HOST));

        return $scheme === 'https' || ($scheme === 'http' && in_array($host, ['localhost', '127.0.0.1'], true));
    }

    /** Servers send each header as X-Coldtill-* and as the pre-rename X-Keyhold-*. */
    public static function header(array $server, string $name): string
    {
        foreach (['HTTP_X_COLDTILL_', 'HTTP_X_KEYHOLD_'] as $prefix) {
            if (isset($server[$prefix . $name])) {
                return trim((string) $server[$prefix . $name]);
            }
        }

        return '';
    }

    /** Verifies the timestamped X-Coldtill-Signature-V2 header and rejects deliveries older than five minutes. */
    public static function verifySignature(string $rawBody, string $header, string $timestamp, string $secret, ?int $now = null): bool
    {
        if ($secret === '' || !str_starts_with($header, 'sha256=') || !ctype_digit($timestamp)) {
            return false;
        }
        if (abs(($now ?? time()) - (int) $timestamp) > 300) {
            return false;
        }
        $want = 'sha256=' . hash_hmac('sha256', $timestamp . '.' . $rawBody, $secret);

        return hash_equals($want, strtolower(trim($header)));
    }

    public static function verifyRequest(array $server, string $rawBody, string $secret, ?int $now = null): bool
    {
        return self::verifySignature($rawBody, self::header($server, 'SIGNATURE_V2'), self::header($server, 'TIMESTAMP'), $secret, $now);
    }

    /**
     * Maps a Coldtill payment state and amount to what the module does with an unpaid order.
     * $partiallyPaid says the order is already in the partial payment state.
     */
    public static function action(string $status, string $amount, float $orderTotal, bool $partiallyPaid): string
    {
        // Compared in cents: floats make 25.00 - 24.99 a hair over 0.01.
        if ($amount !== '' && abs((int) round((float) $amount * 100) - (int) round($orderTotal * 100)) > 1) {
            return self::MISMATCH;
        }
        switch ($status) {
            case 'FILLED':
                return self::PAID;
            case 'OVER_FILLED':
                return self::OVERPAID;
            case 'PARTIALLY_FILLED':
                return $partiallyPaid ? self::NONE : self::PARTIAL;
            case 'CANCELLED':
            case 'REPLACED':
                // Part of the money has arrived (a logged-in customer's next checkout replaces this payment), so a
                // person has to settle the order rather than have it cancelled.
                return $partiallyPaid ? self::MANUAL : self::CANCEL;
        }

        return self::NONE;
    }
}
