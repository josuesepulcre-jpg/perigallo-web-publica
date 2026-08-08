<?php
declare(strict_types=1);

require __DIR__ . '/../api/src/bootstrap.php';

use Perigallo\Ticketing\Redsys;
use RuntimeException;

/** @param string|false $value */
function restore_env_value(string $key, string|false $value): void
{
    if ($value === false) {
        putenv($key);
        unset($_ENV[$key]);
        return;
    }
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

function set_test_env(string $key, ?string $value): void
{
    if ($value === null) {
        putenv($key);
        unset($_ENV[$key]);
        return;
    }
    putenv($key . '=' . $value);
    $_ENV[$key] = $value;
}

function assert_same(mixed $expected, mixed $actual, string $message): void
{
    if ($expected !== $actual) {
        throw new RuntimeException($message);
    }
}

function assert_throws_without_secret(callable $callback, string $secret, string $message): void
{
    try {
        $callback();
    } catch (RuntimeException $error) {
        if (str_contains($error->getMessage(), $secret)) {
            throw new RuntimeException('Un secreto se ha incluido en un error de configuracion.');
        }
        return;
    }
    throw new RuntimeException($message);
}

function expected_signature(string $secret, string $merchantParameters, string $order): string
{
    $key = base64_decode($secret, true);
    if ($key === false) {
        $key = $secret;
    }
    $paddedOrder = str_pad($order, (int) (ceil(strlen($order) / 8) * 8), "\0");
    $derived = openssl_encrypt(
        $paddedOrder,
        'DES-EDE3-CBC',
        $key,
        OPENSSL_RAW_DATA | OPENSSL_ZERO_PADDING,
        "\0\0\0\0\0\0\0\0"
    );
    if ($derived === false) {
        throw new RuntimeException('No se pudo preparar la firma esperada de prueba.');
    }
    return base64_encode(hash_hmac('sha256', $merchantParameters, $derived, true));
}

$keys = [
    'REDSYS_ENV',
    'REDSYS_MERCHANT_CODE',
    'REDSYS_TERMINAL',
    'REDSYS_TEST_URL',
    'REDSYS_PRODUCTION_URL',
    'REDSYS_TEST_SECRET_KEY',
    'REDSYS_PRODUCTION_SECRET_KEY',
    'REDSYS_SECRET_KEY',
    'REDSYS_SIGNATURE_VERSION',
];
$original = [];
foreach ($keys as $key) {
    $original[$key] = getenv($key);
}

try {
    foreach ([
        'REDSYS_MERCHANT_CODE' => '369570718',
        'REDSYS_TERMINAL' => '1',
        'REDSYS_TEST_URL' => 'https://sis-t.redsys.es:25443/sis/realizarPago',
        'REDSYS_PRODUCTION_URL' => 'https://sis.redsys.es/sis/realizarPago',
        'REDSYS_SIGNATURE_VERSION' => 'HMAC_SHA256_V1',
    ] as $key => $value) {
        set_test_env($key, $value);
    }

    $testSecret = base64_encode('01234567abcdefghABCDEFGH');
    $productionSecret = base64_encode('abcdefgh01234567HGFEDCBA');
    $legacySecret = base64_encode('ABCDEFGH76543210hgfedcba');
    $merchantParameters = base64_encode('{"DS_MERCHANT_AMOUNT":"100"}');
    $order = '260808000001';
    $redsys = new Redsys();

    // TEST 1: la clave específica de pruebas tiene prioridad sobre la legacy.
    set_test_env('REDSYS_ENV', 'test');
    set_test_env('REDSYS_TEST_SECRET_KEY', $testSecret);
    set_test_env('REDSYS_SECRET_KEY', $legacySecret);
    set_test_env('REDSYS_PRODUCTION_SECRET_KEY', $productionSecret);
    assert_same(expected_signature($testSecret, $merchantParameters, $order), $redsys->sign($merchantParameters, $order), 'TEST 1: no se uso la clave TEST.');
    $redsys->assertConfigured();

    // assertConfigured también debe comprobar terminal y URL segura, sin revelar claves.
    set_test_env('REDSYS_TERMINAL', 'invalid');
    assert_throws_without_secret(function () use ($redsys): void {
        $redsys->assertConfigured();
    }, $testSecret, 'El terminal invalido fue aceptado.');
    set_test_env('REDSYS_TERMINAL', '1');
    set_test_env('REDSYS_TEST_URL', 'http://example.invalid');
    assert_throws_without_secret(function () use ($redsys): void {
        $redsys->assertConfigured();
    }, $testSecret, 'Una URL Redsys sin HTTPS fue aceptada.');
    set_test_env('REDSYS_TEST_URL', 'https://sis-t.redsys.es:25443/sis/realizarPago');

    // TEST 2: TEST conserva el fallback legacy solo durante la migración.
    set_test_env('REDSYS_TEST_SECRET_KEY', null);
    assert_same(expected_signature($legacySecret, $merchantParameters, $order), $redsys->sign($merchantParameters, $order), 'TEST 2: no funciono el fallback legacy en TEST.');

    // TEST 3: producción utiliza exclusivamente su clave dedicada.
    set_test_env('REDSYS_ENV', 'production');
    set_test_env('REDSYS_PRODUCTION_SECRET_KEY', $productionSecret);
    assert_same(expected_signature($productionSecret, $merchantParameters, $order), $redsys->sign($merchantParameters, $order), 'TEST 3: no se uso la clave de produccion.');
    $redsys->assertConfigured();

    // TEST 4 y TEST 6: producción no permite fallback legacy ni expone la clave en el error.
    set_test_env('REDSYS_PRODUCTION_SECRET_KEY', null);
    assert_throws_without_secret(
        fn (): string => $redsys->sign($merchantParameters, $order),
        $legacySecret,
        'TEST 4: produccion acepto la clave legacy.'
    );
    assert_throws_without_secret(
        function () use ($redsys): void {
            $redsys->assertConfigured();
        },
        $legacySecret,
        'TEST 4: assertConfigured acepto la clave legacy en produccion.'
    );

    // TEST 5: las firmas cambian con la clave de cada entorno.
    set_test_env('REDSYS_ENV', 'test');
    set_test_env('REDSYS_TEST_SECRET_KEY', $testSecret);
    $testSignature = $redsys->sign($merchantParameters, $order);
    set_test_env('REDSYS_ENV', 'production');
    set_test_env('REDSYS_PRODUCTION_SECRET_KEY', $productionSecret);
    $productionSignature = $redsys->sign($merchantParameters, $order);
    if ($testSignature === $productionSignature) {
        throw new RuntimeException('TEST 5: ambos entornos generaron la misma firma.');
    }

    if (str_contains((string) file_get_contents(__DIR__ . '/../api/src/Redsys.php'), 'error_log(')) {
        throw new RuntimeException('TEST 6: Redsys no debe registrar secretos ni configuracion sensible.');
    }

    echo "Redsys environment-specific secret-key tests passed\n";
} finally {
    foreach ($original as $key => $value) {
        restore_env_value($key, $value);
    }
}
