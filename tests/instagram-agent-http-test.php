<?php
declare(strict_types=1);

/** HTTP boundary tests. The temporary auth router exists only on loopback, never in production. */
$root = dirname(__DIR__);
$temporary = sys_get_temp_dir() . '/perigallo-agent-http-' . bin2hex(random_bytes(8));
mkdir($temporary, 0700);
mkdir($temporary . '/sessions', 0700);
$socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
if ($socket === false) {
    throw new RuntimeException('Cannot reserve a local test port.');
}
$address = stream_socket_get_name($socket, false);
$port = (int) substr(strrchr($address, ':'), 1);
fclose($socket);
$router = <<<'PHP'
<?php
declare(strict_types=1);
$root = ROOT_PLACEHOLDER;
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/__session') {
    require $root . '/api/src/bootstrap.php';
    \Perigallo\Ticketing\AdminAuth::start();
    $_SESSION['admin'] = true;
    $_SESSION['role'] = ($_GET['role'] ?? '') === 'scanner' ? 'control_acceso' : 'admin';
    $_SESSION['operator'] = 'test-operator';
    $_SESSION['is_owner'] = ($_GET['role'] ?? '') === 'owner';
    json_response(['csrf' => $_SESSION['csrf']]);
    exit;
}
if ($path === '/health') {
    echo 'ok';
    exit;
}
if ($path === '/admin/instagram/agent/api.php') {
    require $root . '/admin/instagram/agent/api.php';
    exit;
}
if ($path === '/admin/instagram/agent/') {
    require $root . '/admin/instagram/agent/index.php';
    exit;
}
http_response_code(404);
PHP;
file_put_contents($temporary . '/router.php', str_replace('ROOT_PLACEHOLDER', var_export($root, true), $router));
$environment = getenv();
$environment['OPENAI_API_KEY'] = '';
$environment['INSTAGRAM_AGENT_STORAGE_DIR'] = $temporary . '/state';
$environment['INSTAGRAM_AGENT_OPENAI_KEY_FILE'] = $temporary . '/state/openai.key';
$environment['INSTAGRAM_AGENT_MODEL'] = 'gpt-5.4-mini';
$php = getenv('TEST_PHP_BINARY') ?: PHP_BINARY;
$process = proc_open(
    [$php, '-d', 'session.save_path=' . $temporary . '/sessions', '-S', '127.0.0.1:' . $port, $temporary . '/router.php'],
    [0 => ['pipe', 'r'], 1 => ['file', $temporary . '/server.log', 'a'], 2 => ['file', $temporary . '/server.log', 'a']],
    $pipes,
    $root,
    $environment
);
if (!is_resource($process)) {
    throw new RuntimeException('Cannot start local PHP test server.');
}
fclose($pipes[0]);
$base = 'http://127.0.0.1:' . $port;
$checks = 0;

function expect(bool $condition, string $label): void
{
    global $checks;
    $checks++;
    if (!$condition) {
        throw new RuntimeException('FAIL: ' . $label);
    }
}

function request(string $path, string $method = 'GET', ?array $body = null, ?string $cookieJar = null, ?string $csrf = null): array
{
    global $base;
    $handle = curl_init($base . $path);
    $headers = ['Accept: application/json'];
    if ($body !== null) {
        $headers[] = 'Content-Type: application/json';
    }
    if ($csrf !== null) {
        $headers[] = 'X-CSRF-Token: ' . $csrf;
    }
    curl_setopt_array($handle, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 2,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CUSTOMREQUEST => $method,
        CURLOPT_HTTPHEADER => $headers,
        CURLOPT_FOLLOWLOCATION => false,
    ]);
    if ($cookieJar !== null) {
        curl_setopt($handle, CURLOPT_COOKIEJAR, $cookieJar);
        curl_setopt($handle, CURLOPT_COOKIEFILE, $cookieJar);
    }
    if ($body !== null) {
        curl_setopt($handle, CURLOPT_POSTFIELDS, json_encode($body, JSON_THROW_ON_ERROR));
    }
    $raw = curl_exec($handle);
    $status = curl_getinfo($handle, CURLINFO_RESPONSE_CODE);
    curl_close($handle);
    return ['status' => $status, 'raw' => $raw, 'json' => is_string($raw) ? json_decode($raw, true) : null];
}

try {
    $ready = false;
    for ($attempt = 0; $attempt < 30; $attempt++) {
        if (request('/health')['status'] === 200) {
            $ready = true;
            break;
        }
        usleep(100000);
    }
    expect($ready, 'test server becomes available');
    $endpoint = '/admin/instagram/agent/api.php';
    expect(request($endpoint . '?op=state')['status'] === 401, 'anonymous state access rejected');
    expect(request('/admin/instagram/agent/')['status'] === 302, 'anonymous panel redirects to existing login');

    $scannerJar = $temporary . '/scanner.cookies';
    request('/__session?role=scanner', 'GET', null, $scannerJar);
    expect(request($endpoint . '?op=state', 'GET', null, $scannerJar)['status'] === 401, 'scanner role rejected');

    $adminJar = $temporary . '/admin.cookies';
    $adminSession = request('/__session?role=admin', 'GET', null, $adminJar);
    $adminCsrf = $adminSession['json']['csrf'];
    $state = request($endpoint . '?op=state', 'GET', null, $adminJar);
    expect($state['status'] === 200 && $state['json']['snapshot']['state']['mode'] === 'SIMULATION', 'administrator can inspect simulation');
    expect($state['json']['capabilities']['instagram_write'] === false, 'production writes disabled');
    expect($state['json']['capabilities']['ai']['configured'] === false, 'missing provider key reported honestly');
    expect($state['json']['capabilities']['scheduler']['active'] === false, 'absent scheduler not reported as active');
    expect(request($endpoint . '?op=pause', 'POST', [], $adminJar)['status'] === 419, 'CSRF required for mutations');
    expect(request($endpoint . '?op=pause', 'GET', null, $adminJar)['status'] === 405, 'GET cannot mutate');
    expect(request($endpoint . '?op=set_mode', 'POST', ['mode' => 'LIVE'], $adminJar, $adminCsrf)['status'] === 403, 'production mode cannot be enabled');
    expect(request($endpoint . '?op=publish_real', 'POST', [], $adminJar, $adminCsrf)['status'] === 403, 'real publication endpoint rejected');
    expect(request($endpoint . '?op=send_real', 'POST', [], $adminJar, $adminCsrf)['status'] === 403, 'real message endpoint rejected');
    expect(request($endpoint . '?op=save_settings', 'POST', ['schedule_enabled' => true], $adminJar, $adminCsrf)['status'] === 403, 'only owner changes schedule');
    expect(request($endpoint . '?op=configure_key', 'POST', ['key' => 'sk-' . str_repeat('x', 30)], $adminJar, $adminCsrf)['status'] === 403, 'only owner configures provider key');

    $draftInput = [
        'title' => 'Prueba de revisión',
        'format' => 'photo',
        'objective' => 'Validar el flujo',
        'caption' => 'Contenido ficticio para comprobar el panel.',
        'visual_brief' => 'Maqueta tipográfica de prueba',
        'slides' => [],
        'alt_text' => '',
        'cta' => '',
        'pending_facts' => [],
    ];
    $saved = request($endpoint . '?op=save_draft', 'POST', $draftInput, $adminJar, $adminCsrf);
    expect($saved['status'] === 200, 'authenticated draft persists');
    $draft = $saved['json']['draft'];
    $approved = request($endpoint . '?op=review_draft', 'POST', ['id' => $draft['id'], 'revision' => 1, 'decision' => 'approve'], $adminJar, $adminCsrf);
    expect($approved['status'] === 200, 'review operation works through HTTP');
    $simulated = request($endpoint . '?op=simulate_publish', 'POST', ['id' => $draft['id'], 'revision' => 1], $adminJar, $adminCsrf);
    expect($simulated['status'] === 200 && $simulated['json']['draft']['execution_status'] === 'SIMULATED', 'approved execution remains simulated');
    $edited = request($endpoint . '?op=save_draft', 'POST', ['id' => $draft['id'], 'revision' => 1, 'caption' => 'Revisión nueva, también ficticia.'], $adminJar, $adminCsrf);
    expect($edited['status'] === 200 && $edited['json']['draft']['approval'] === null, 'editing invalidates approval across controller');
    expect(request($endpoint . '?op=simulate_publish', 'POST', ['id' => $draft['id'], 'revision' => 2], $adminJar, $adminCsrf)['status'] === 409, 'changed version cannot reuse approval');
    $export = request($endpoint . '?op=export&id=' . $draft['id'], 'GET', null, $adminJar);
    expect($export['status'] === 200 && $export['json']['environment'] === 'SIMULATION', 'export retains simulation label');
    expect(request($endpoint . '?op=asset&id=..%2Fopenai.key', 'GET', null, $adminJar)['status'] === 422, 'asset traversal rejected');
    expect(request($endpoint . '?op=asset&id=' . str_repeat('a', 32) . '.svg', 'GET', null, $adminJar)['status'] === 422, 'SVG resources not served');

    $ownerJar = $temporary . '/owner.cookies';
    $ownerSession = request('/__session?role=owner', 'GET', null, $ownerJar);
    $ownerCsrf = $ownerSession['json']['csrf'];
    $privateKey = 'sk-' . str_repeat('x', 30);
    $keyResult = request($endpoint . '?op=configure_key', 'POST', ['key' => $privateKey], $ownerJar, $ownerCsrf);
    expect($keyResult['status'] === 200, 'owner can save key privately');
    expect(strpos($keyResult['raw'], $privateKey) === false, 'key is never returned');
    expect($keyResult['json']['capabilities']['ai']['configured'] === true, 'key presence reflected without inference claim');
    expect((fileperms($temporary . '/state/openai.key') & 0777) === 0600, 'private key file permissions');
    $freshState = request($endpoint . '?op=state', 'GET', null, $ownerJar);
    expect(strpos($freshState['raw'], $privateKey) === false, 'subsequent state does not expose key');
    expect(count($freshState['json']['snapshot']['state']['drafts']) === 1, 'state persists across separate sessions');
    $page = request('/admin/instagram/agent/', 'GET', null, $ownerJar);
    expect($page['status'] === 200 && str_contains($page['raw'], 'app.js'), 'authenticated application renders');
    echo 'Instagram agent HTTP boundaries: ' . $checks . ' checks passed. No provider requests made.' . PHP_EOL;
} finally {
    proc_terminate($process);
    proc_close($process);
    $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($temporary, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($iterator as $entry) {
        if ($entry->isDir() && !$entry->isLink()) {
            rmdir($entry->getPathname());
        } else {
            unlink($entry->getPathname());
        }
    }
    rmdir($temporary);
}
