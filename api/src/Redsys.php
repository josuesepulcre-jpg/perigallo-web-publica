<?php
declare(strict_types=1);

namespace Perigallo\Ticketing;

use RuntimeException;

final class Redsys
{
    public function availablePaymentMethods(): array
    {
        return [
            ['id' => 'card', 'available' => true],
            ['id' => 'bizum', 'available' => $this->bizumEnabled()],
        ];
    }

    public function paymentMethod(?string $requested): string
    {
        $method = strtolower(trim((string) $requested));
        if ($method === '') {
            $method = 'card';
        }
        if (!in_array($method, ['card', 'bizum'], true)) {
            throw new RuntimeException('El método de pago seleccionado no está disponible.');
        }
        if ($method === 'bizum' && !$this->bizumEnabled()) {
            throw new RuntimeException('Bizum no está disponible en este momento. Elige tarjeta para continuar.');
        }
        return $method;
    }

    public function assertConfigured(): void
    {
        $environment = env_value('REDSYS_ENV', 'test');
        if (!in_array($environment, ['test', 'production'], true)) {
            throw new RuntimeException('REDSYS_ENV debe ser test o production.');
        }
        if (!env_value('REDSYS_MERCHANT_CODE') || !env_value('REDSYS_SECRET_KEY')) {
            throw new RuntimeException('Faltan las credenciales configuradas de Redsys para abrir la pasarela.');
        }
        if (!str_starts_with($this->paymentUrl(), 'https://')) {
            throw new RuntimeException('La URL de Redsys debe utilizar HTTPS.');
        }
    }

    public function assertSandboxConfigured(): void
    {
        if (env_value('REDSYS_ENV', 'test') !== 'test') {
            throw new RuntimeException('El recorrido de pruebas requiere REDSYS_ENV=test.');
        }
        if (env_value('PAYMENT_ENVIRONMENT', 'sandbox') !== 'sandbox') {
            throw new RuntimeException('El modo sandbox de pagos no está activado.');
        }
        $this->assertConfigured();
    }

    public function paymentUrl(): string
    {
        return env_value('REDSYS_ENV', 'test') === 'production'
            ? (env_value('REDSYS_PRODUCTION_URL') ?: 'https://sis.redsys.es/sis/realizarPago')
            : (env_value('REDSYS_TEST_URL') ?: 'https://sis-t.redsys.es:25443/sis/realizarPago');
    }

    public function terminal(): string
    {
        $terminal = trim((string) env_value('REDSYS_TERMINAL', '1'));
        if ($terminal === '' || !ctype_digit($terminal)) {
            throw new RuntimeException('El terminal Redsys debe contener solo dígitos.');
        }
        return str_pad(ltrim($terminal, '0') ?: '0', 3, '0', STR_PAD_LEFT);
    }

    private function bizumEnabled(): bool
    {
        return filter_var(env_value('REDSYS_BIZUM_ENABLED', 'false'), FILTER_VALIDATE_BOOLEAN);
    }

    public function buildRedirectFields(array $params): array
    {
        $merchantParameters = $this->encodeMerchantParameters($params);
        return [
            'Ds_SignatureVersion' => env_value('REDSYS_SIGNATURE_VERSION', 'HMAC_SHA256_V1'),
            'Ds_MerchantParameters' => $merchantParameters,
            'Ds_Signature' => $this->sign($merchantParameters, (string) $params['DS_MERCHANT_ORDER']),
        ];
    }

    public function encodeMerchantParameters(array $params): string
    {
        $json = json_encode($params, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
        if ($json === false) {
            throw new RuntimeException('No se pudieron codificar los parametros Redsys.');
        }
        return base64_encode($json);
    }

    public function decodeMerchantParameters(string $encoded): array
    {
        $decoded = base64_decode($this->toBase64($encoded), true);
        if ($decoded === false) {
            throw new RuntimeException('MerchantParameters invalido.');
        }
        $params = json_decode($decoded, true);
        if (!is_array($params)) {
            throw new RuntimeException('Respuesta Redsys invalida.');
        }
        return $this->normalize($params);
    }

    public function validateSignature(string $merchantParameters, string $signature, string $order): bool
    {
        $expected = $this->sign($merchantParameters, $order);
        return hash_equals($this->toBase64($expected), $this->toBase64($signature));
    }

    public function sign(string $merchantParameters, string $order): string
    {
        $key = $this->deriveKey($order);
        $version = env_value('REDSYS_SIGNATURE_VERSION', 'HMAC_SHA256_V1');
        $algo = $version === 'HMAC_SHA512_V2' ? 'sha512' : 'sha256';
        return base64_encode(hash_hmac($algo, $merchantParameters, $key, true));
    }

    private function deriveKey(string $order): string
    {
        $secret = env_value('REDSYS_SECRET_KEY');
        if (!$secret) {
            throw new RuntimeException('Clave Redsys no configurada.');
        }
        $key = base64_decode($secret, true);
        if ($key === false) {
            $key = $secret;
        }
        if (!function_exists('openssl_encrypt')) {
            throw new RuntimeException('OpenSSL es necesario para la firma Redsys.');
        }
        $blockSize = 8;
        $paddedOrder = str_pad($order, (int) (ceil(strlen($order) / $blockSize) * $blockSize), "\0");
        $derived = openssl_encrypt(
            $paddedOrder,
            'DES-EDE3-CBC',
            $key,
            OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
            "\0\0\0\0\0\0\0\0"
        );
        if ($derived === false) {
            throw new RuntimeException('No se pudo derivar la clave Redsys.');
        }
        return $derived;
    }

    private function normalize(array $params): array
    {
        $out = [];
        foreach ($params as $key => $value) {
            $out[strtoupper((string) $key)] = $value;
        }
        return $out;
    }

    private function toBase64(string $value): string
    {
        // Algunos proxies convierten el signo + de un formulario URL-encoded en un espacio.
        $value = str_replace(' ', '+', $value);
        $value = strtr($value, '-_', '+/');
        $pad = strlen($value) % 4;
        if ($pad) {
            $value .= str_repeat('=', 4 - $pad);
        }
        return $value;
    }
}
