<?php

/**
 * Minimal Kubernetes API client (in-cluster by default).
 *
 * - Uses the Pod's ServiceAccount token + CA.
 * - Does NOT expose any Kubernetes credentials to the browser.
 */
class KubernetesClient
{
    private string $apiServer;
    private string $token;
    private string $caCertPath;
    private int $timeoutSeconds;

    /** Return env var only if set AND non-empty after trim. */
    private function getenvNonEmpty(string $name): ?string
    {
        $v = getenv($name);
        if ($v === false) {
            return null;
        }
        $v = trim((string)$v);
        return $v === '' ? null : $v;
    }

    public function __construct(?string $apiServer = null, ?string $token = null, ?string $caCertPath = null, int $timeoutSeconds = 10)
    {
        // Prefer explicit API server, then env, then in-cluster service.
        // Also accept standard in-cluster env vars if present.
        $api = $apiServer
            ?? $this->getenvNonEmpty('K8S_API_SERVER')
            ?? (
                ($this->getenvNonEmpty('KUBERNETES_SERVICE_HOST') && $this->getenvNonEmpty('KUBERNETES_SERVICE_PORT'))
                    ? ('https://' . $this->getenvNonEmpty('KUBERNETES_SERVICE_HOST') . ':' . $this->getenvNonEmpty('KUBERNETES_SERVICE_PORT'))
                    : 'https://kubernetes.default.svc'
            );
        $this->apiServer = rtrim($api, '/');
        $this->timeoutSeconds = $timeoutSeconds;

        $defaultTokenPath = '/var/run/secrets/kubernetes.io/serviceaccount/token';
        $defaultCaPath    = '/var/run/secrets/kubernetes.io/serviceaccount/ca.crt';

        // IMPORTANT: ignore empty env vars (""), otherwise they shadow the in-cluster token.
        $this->token = $token
            ?? $this->getenvNonEmpty('K8S_BEARER_TOKEN')
            ?? (is_readable($defaultTokenPath) ? trim((string)@file_get_contents($defaultTokenPath)) : '');

        $this->caCertPath = $caCertPath
            ?? $this->getenvNonEmpty('K8S_CA_CERT')
            ?? (is_readable($defaultCaPath) ? $defaultCaPath : '');

        if ($this->token === '') {
            $openBaseDir = (string)ini_get('open_basedir');
            $hint = $openBaseDir !== '' ? (' open_basedir=' . $openBaseDir) : '';
            throw new RuntimeException(
                'Kubernetes token introuvable (env K8S_BEARER_TOKEN vide/non défini et ' . $defaultTokenPath . ' non lisible).' . $hint
            );
        }
        if ($this->caCertPath === '' || !is_readable($this->caCertPath)) {
            $openBaseDir = (string)ini_get('open_basedir');
            $hint = $openBaseDir !== '' ? (' open_basedir=' . $openBaseDir) : '';
            throw new RuntimeException(
                'CA Kubernetes introuvable (' . $defaultCaPath . ' non lisible et env K8S_CA_CERT vide/non défini).' . $hint
            );
        }
    }

    /** GET JSON. */
    public function get(string $path): array
    {
        return $this->requestJson('GET', $path);
    }

    /** POST JSON. */
    public function post(string $path, array $payload, array $extraHeaders = []): array
    {
        return $this->requestJson('POST', $path, $payload, array_merge([
            'Content-Type: application/json',
        ], $extraHeaders));
    }

    /** DELETE (JSON response). */
    public function delete(string $path): array
    {
        return $this->requestJson('DELETE', $path);
    }

    /** PATCH JSON (strategic merge by default). */
    public function patch(string $path, array $payload, string $contentType = 'application/strategic-merge-patch+json'): array
    {
        return $this->requestJson('PATCH', $path, $payload, [
            'Content-Type: ' . $contentType,
        ]);
    }

    /** List deployments in a namespace. */
    public function listDeployments(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/apis/apps/v1/namespaces/{$ns}/deployments?limit=200");
    }

    /** List services in a namespace. */
    public function listServices(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/api/v1/namespaces/{$ns}/services?limit=500");
    }

    /** List ingresses in a namespace (networking.k8s.io/v1). */
    public function listIngresses(string $namespace): array
    {
        $ns = rawurlencode($namespace);
        return $this->get("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses?limit=500");
    }

    public function createIngress(string $namespace, array $ingress): array
    {
        $ns = rawurlencode($namespace);
        return $this->post("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses", $ingress);
    }

    public function patchIngress(string $namespace, string $name, array $payload, string $contentType = 'application/merge-patch+json'): array
    {
        $ns = rawurlencode($namespace);
        $nm = rawurlencode($name);
        return $this->patch("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses/{$nm}", $payload, $contentType);
    }

    public function deleteIngress(string $namespace, string $name): array
    {
        $ns = rawurlencode($namespace);
        $nm = rawurlencode($name);
        return $this->delete("/apis/networking.k8s.io/v1/namespaces/{$ns}/ingresses/{$nm}");
    }

    public function getDeployment(string $namespace, string $deployment): array
    {
        $ns = rawurlencode($namespace);
        $dp = rawurlencode($deployment);
        return $this->get("/apis/apps/v1/namespaces/{$ns}/deployments/{$dp}");
    }

    /** List pods in a namespace, optional label selector. */
    public function listPods(string $namespace, ?string $labelSelector = null): array
    {
        $ns = rawurlencode($namespace);
        $query = 'limit=500';
        if (is_string($labelSelector) && trim($labelSelector) !== '') {
            $query .= '&labelSelector=' . rawurlencode(trim($labelSelector));
        }
        return $this->get("/api/v1/namespaces/{$ns}/pods?{$query}");
    }

    /** Read logs from a pod. */
    public function getPodLogs(string $namespace, string $pod, ?string $container = null, int $tail = 200, bool $timestamps = true): string
    {
        $ns = rawurlencode($namespace);
        $pd = rawurlencode($pod);

        $tail = max(1, min($tail, 5000));
        $params = [
            'tailLines' => (string)$tail,
            'timestamps' => $timestamps ? 'true' : 'false',
        ];

        if (is_string($container) && trim($container) !== '') {
            $params['container'] = trim($container);
        }

        $path = "/api/v1/namespaces/{$ns}/pods/{$pd}/log?" . http_build_query($params, '', '&', PHP_QUERY_RFC3986);
        return $this->requestText('GET', $path);
    }

    /** "kubectl rollout restart" equivalent. */
    public function restartDeployment(string $namespace, string $deployment): array
    {
        $now = (new DateTimeImmutable('now', new DateTimeZone('UTC')))->format(DateTimeInterface::ATOM);
        $payload = [
            'spec' => [
                'template' => [
                    'metadata' => [
                        'annotations' => [
                            'kubectl.kubernetes.io/restartedAt' => $now,
                        ],
                    ],
                ],
            ],
        ];
        $ns = rawurlencode($namespace);
        $dp = rawurlencode($deployment);
        return $this->patch("/apis/apps/v1/namespaces/{$ns}/deployments/{$dp}", $payload);
    }

    public function getSecret(string $namespace, string $secret): array
    {
        $ns = rawurlencode($namespace);
        $sc = rawurlencode($secret);
        return $this->get("/api/v1/namespaces/{$ns}/secrets/{$sc}");
    }


    /**
     * Patch ONE key of a Secret (value will be base64-encoded).
     * NOTE: only use this if you deliberately granted secrets/patch in RBAC.
     */
    public function patchSecretDataKey(string $namespace, string $secret, string $key, string $valuePlain): array
    {
        $payload = [
            'data' => [
                $key => base64_encode($valuePlain),
            ],
        ];
        $ns = rawurlencode($namespace);
        $sc = rawurlencode($secret);
        return $this->patch("/api/v1/namespaces/{$ns}/secrets/{$sc}", $payload);
    }

    public function deleteSecretDataKey(string $namespace, string $secret, string $key): array
    {
        $ns = rawurlencode($namespace);
        $sc = rawurlencode($secret);
        $escapedKey = str_replace(['~', '/'], ['~0', '~1'], $key);

        return $this->patch(
            "/api/v1/namespaces/{$ns}/secrets/{$sc}",
            [
                [
                    'op' => 'remove',
                    'path' => '/data/' . $escapedKey,
                ],
            ],
            'application/json-patch+json'
        );
    }

    /**
     * Execute a command inside a Pod container via the Kubernetes WebSocket exec endpoint.
     *
     * Returns stdout/stderr/combined output and an exit code when the remote runtime provides one.
     */
    public function execInPod(string $namespace, string $pod, array $command, ?string $container = null): array
    {
        if ($command === []) {
            throw new RuntimeException('Commande exec vide.');
        }

        foreach ($command as $part) {
            if (!is_string($part) || $part === '') {
                throw new RuntimeException('Chaque argument de commande doit être une chaîne non vide.');
            }
        }

        $ns = rawurlencode($namespace);
        $pd = rawurlencode($pod);

        $params = [
            'stdin' => 'false',
            'stdout' => 'true',
            'stderr' => 'true',
            'tty' => 'false',
        ];
        if (is_string($container) && trim($container) !== '') {
            $params['container'] = trim($container);
        }

        $queryParts = [];
        foreach ($params as $key => $value) {
            $queryParts[] = rawurlencode($key) . '=' . rawurlencode($value);
        }
        foreach ($command as $part) {
            $queryParts[] = 'command=' . rawurlencode($part);
        }

        $path = "/api/v1/namespaces/{$ns}/pods/{$pd}/exec?" . implode('&', $queryParts);
        return $this->requestWebSocketExec($path);
    }

    private function requestJson(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $raw = $this->requestRaw($method, $path, $payload, array_merge([
            'Accept: application/json',
        ], $extraHeaders));

        $decoded = json_decode($raw['body'], true);
        if (!is_array($decoded)) {
            throw new RuntimeException('Réponse Kubernetes non-JSON (HTTP ' . $raw['status'] . ').');
        }

        if ($raw['status'] < 200 || $raw['status'] >= 300) {
            $msg = $decoded['message'] ?? ($decoded['status'] ?? 'Erreur Kubernetes');
            throw new RuntimeException('Kubernetes: ' . $msg . ' (HTTP ' . $raw['status'] . ').');
        }

        return $decoded;
    }

    private function requestText(string $method, string $path, ?array $payload = null, array $extraHeaders = []): string
    {
        $raw = $this->requestRaw($method, $path, $payload, array_merge([
            'Accept: text/plain, */*',
        ], $extraHeaders));

        if ($raw['status'] < 200 || $raw['status'] >= 300) {
            throw new RuntimeException('Kubernetes: récupération des logs impossible (HTTP ' . $raw['status'] . ').');
        }

        return $raw['body'];
    }

    private function requestRaw(string $method, string $path, ?array $payload = null, array $extraHeaders = []): array
    {
        $url = $this->apiServer . $path;

        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException('Impossible d\'initialiser cURL.');
        }

        $headers = array_merge([
            'Authorization: Bearer ' . $this->token,
        ], $extraHeaders);

        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => $method,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_TIMEOUT        => $this->timeoutSeconds,
            CURLOPT_CONNECTTIMEOUT => $this->timeoutSeconds,
            CURLOPT_CAINFO         => $this->caCertPath,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
        ]);

        if ($payload !== null) {
            $json = json_encode($payload, JSON_UNESCAPED_SLASHES);
            if ($json === false) {
                throw new RuntimeException('Payload JSON invalide.');
            }
            curl_setopt($ch, CURLOPT_POSTFIELDS, $json);
        }

        $raw = curl_exec($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($raw === false) {
            throw new RuntimeException('Erreur cURL: ' . $err);
        }

        return [
            'status' => $status,
            'body' => $raw,
        ];
    }

    private function requestWebSocketExec(string $path): array
    {
        $parts = parse_url($this->apiServer);
        if (!is_array($parts) || empty($parts['scheme']) || empty($parts['host'])) {
            throw new RuntimeException('URL du serveur Kubernetes invalide pour exec.');
        }

        $scheme = strtolower((string)$parts['scheme']);
        $host = (string)$parts['host'];
        $port = isset($parts['port']) ? (int)$parts['port'] : ($scheme === 'https' ? 443 : 80);
        $basePath = isset($parts['path']) ? rtrim((string)$parts['path'], '/') : '';
        $requestPath = ($basePath !== '' ? $basePath : '') . $path;

        $transport = $scheme === 'https' ? 'tls' : 'tcp';
        $address = $transport . '://' . $host . ':' . $port;

        $context = stream_context_create([
            'ssl' => [
                'cafile' => $this->caCertPath,
                'verify_peer' => $scheme === 'https',
                'verify_peer_name' => $scheme === 'https',
                'peer_name' => $host,
                'SNI_enabled' => $scheme === 'https',
            ],
        ]);

        $errno = 0;
        $errstr = '';
        $stream = @stream_socket_client(
            $address,
            $errno,
            $errstr,
            $this->timeoutSeconds,
            STREAM_CLIENT_CONNECT,
            $context
        );

        if (!is_resource($stream)) {
            throw new RuntimeException('Connexion exec impossible: ' . ($errstr !== '' ? $errstr : ('errno ' . $errno)));
        }

        stream_set_timeout($stream, $this->timeoutSeconds);

        $secKey = base64_encode(random_bytes(16));
        $headers = [
            "GET {$requestPath} HTTP/1.1",
            "Host: {$host}:{$port}",
            'Authorization: Bearer ' . $this->token,
            'Connection: Upgrade',
            'Upgrade: websocket',
            'Sec-WebSocket-Version: 13',
            'Sec-WebSocket-Key: ' . $secKey,
            'Sec-WebSocket-Protocol: v5.channel.k8s.io, v4.channel.k8s.io, channel.k8s.io',
            '',
            '',
        ];

        $written = fwrite($stream, implode("\r\n", $headers));
        if ($written === false || $written === 0) {
            fclose($stream);
            throw new RuntimeException('Handshake exec impossible: écriture socket échouée.');
        }

        $responseHeaders = '';
        while (!str_contains($responseHeaders, "\r\n\r\n")) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                fclose($stream);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Handshake exec expiré.');
                }
                throw new RuntimeException('Réponse exec incomplète pendant le handshake.');
            }
            $responseHeaders .= $chunk;
        }

        [$headerBlock, $buffer] = explode("\r\n\r\n", $responseHeaders, 2);
        $headerLines = preg_split("/\r\n/", $headerBlock) ?: [];
        $statusLine = $headerLines[0] ?? '';
        if (!preg_match('/^HTTP\/\d+\.\d+\s+(\d{3})\b/', $statusLine, $matches)) {
            fclose($stream);
            throw new RuntimeException('Réponse exec HTTP invalide.');
        }

        $status = (int)$matches[1];
        $headerMap = [];
        foreach (array_slice($headerLines, 1) as $line) {
            if (!str_contains($line, ':')) {
                continue;
            }
            [$name, $value] = explode(':', $line, 2);
            $headerMap[strtolower(trim($name))] = trim($value);
        }

        if ($status !== 101) {
            $body = $buffer;
            while (!feof($stream)) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    break;
                }
                $body .= $chunk;
            }
            fclose($stream);
            $body = trim($body);
            throw new RuntimeException(
                'Exec Kubernetes refusé (HTTP ' . $status . ')' . ($body !== '' ? ': ' . preg_replace('/\s+/', ' ', $body) : '.')
            );
        }

        $accept = $headerMap['sec-websocket-accept'] ?? '';
        $expectedAccept = base64_encode(sha1($secKey . '258EAFA5-E914-47DA-95CA-C5AB0DC85B11', true));
        if ($accept === '' || !hash_equals($expectedAccept, $accept)) {
            fclose($stream);
            throw new RuntimeException('Handshake exec invalide: Sec-WebSocket-Accept inattendu.');
        }

        $stdout = '';
        $stderr = '';
        $error = '';
        $exitCode = 0;
        $closeCode = null;
        $closeReason = '';

        while (true) {
            $frame = $this->readWebSocketFrame($stream, $buffer);
            if ($frame === null) {
                break;
            }

            if ($frame['opcode'] === 0x8) {
                [$closeCode, $closeReason] = $this->parseCloseFramePayload($frame['payload']);
                break;
            }

            if ($frame['opcode'] === 0x9) {
                $this->writeWebSocketFrame($stream, 0xA, $frame['payload']);
                continue;
            }

            if ($frame['opcode'] !== 0x1 && $frame['opcode'] !== 0x2) {
                continue;
            }

            $payload = $frame['payload'];
            if ($payload === '') {
                continue;
            }

            $channel = ord($payload[0]);
            $data = substr($payload, 1);

            if ($channel === 1) {
                $stdout .= $data;
            } elseif ($channel === 2) {
                $stderr .= $data;
            } elseif ($channel === 3) {
                $error .= $data;
                $parsed = $this->parseExecErrorPayload($data);
                if ($parsed !== null) {
                    $exitCode = $parsed['exitCode'];
                }
            }
        }

        $this->writeWebSocketFrame($stream, 0x8, pack('n', 1000));
        fclose($stream);

        if ($exitCode === 0 && $closeCode !== null && $closeCode !== 1000) {
            $exitCode = $closeCode;
        }

        return [
            'stdout' => $stdout,
            'stderr' => $stderr,
            'error' => $error !== '' ? $error : $closeReason,
            'exitCode' => $exitCode,
        ];
    }

    private function readWebSocketFrame($stream, string &$buffer): ?array
    {
        while (strlen($buffer) < 2) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                $meta = stream_get_meta_data($stream);
                if (!empty($meta['timed_out'])) {
                    throw new RuntimeException('Lecture exec expirée.');
                }
                return $buffer === '' ? null : throw new RuntimeException('Trame WebSocket incomplète.');
            }
            $buffer .= $chunk;
        }

        $b1 = ord($buffer[0]);
        $b2 = ord($buffer[1]);
        $opcode = $b1 & 0x0F;
        $masked = ($b2 & 0x80) !== 0;
        $payloadLen = $b2 & 0x7F;
        $offset = 2;

        if ($payloadLen === 126) {
            while (strlen($buffer) < $offset + 2) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Trame WebSocket incomplète (taille 16 bits).');
                }
                $buffer .= $chunk;
            }
            $payloadLen = unpack('n', substr($buffer, $offset, 2))[1];
            $offset += 2;
        } elseif ($payloadLen === 127) {
            while (strlen($buffer) < $offset + 8) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Trame WebSocket incomplète (taille 64 bits).');
                }
                $buffer .= $chunk;
            }
            $parts = unpack('N2', substr($buffer, $offset, 8));
            $payloadLen = ((int)$parts[1] << 32) | (int)$parts[2];
            $offset += 8;
        }

        $maskKey = '';
        if ($masked) {
            while (strlen($buffer) < $offset + 4) {
                $chunk = fread($stream, 4096);
                if ($chunk === false || $chunk === '') {
                    throw new RuntimeException('Trame WebSocket incomplète (mask).');
                }
                $buffer .= $chunk;
            }
            $maskKey = substr($buffer, $offset, 4);
            $offset += 4;
        }

        while (strlen($buffer) < $offset + $payloadLen) {
            $chunk = fread($stream, 4096);
            if ($chunk === false || $chunk === '') {
                throw new RuntimeException('Trame WebSocket incomplète (payload).');
            }
            $buffer .= $chunk;
        }

        $payload = substr($buffer, $offset, $payloadLen);
        $buffer = (string)substr($buffer, $offset + $payloadLen);

        if ($masked) {
            $payload = $this->applyWebSocketMask($payload, $maskKey);
        }

        return [
            'opcode' => $opcode,
            'payload' => $payload,
        ];
    }

    private function writeWebSocketFrame($stream, int $opcode, string $payload = ''): void
    {
        $length = strlen($payload);
        $frame = chr(0x80 | ($opcode & 0x0F));
        $maskKey = random_bytes(4);

        if ($length < 126) {
            $frame .= chr(0x80 | $length);
        } elseif ($length <= 0xFFFF) {
            $frame .= chr(0x80 | 126) . pack('n', $length);
        } else {
            $frame .= chr(0x80 | 127) . pack('N2', ($length >> 32) & 0xFFFFFFFF, $length & 0xFFFFFFFF);
        }

        $frame .= $maskKey . $this->applyWebSocketMask($payload, $maskKey);
        fwrite($stream, $frame);
    }

    private function applyWebSocketMask(string $payload, string $maskKey): string
    {
        $masked = '';
        $maskLen = strlen($maskKey);
        for ($i = 0, $len = strlen($payload); $i < $len; $i++) {
            $masked .= $payload[$i] ^ $maskKey[$i % $maskLen];
        }
        return $masked;
    }

    private function parseCloseFramePayload(string $payload): array
    {
        if (strlen($payload) < 2) {
            return [null, ''];
        }

        $code = unpack('n', substr($payload, 0, 2))[1];
        $reason = substr($payload, 2);
        return [$code, $reason];
    }

    private function parseExecErrorPayload(string $payload): ?array
    {
        $decoded = json_decode($payload, true);
        if (!is_array($decoded)) {
            return null;
        }

        $status = (string)($decoded['status'] ?? '');
        $details = $decoded['details'] ?? null;
        $causes = is_array($details) && is_array($details['causes'] ?? null) ? $details['causes'] : [];
        foreach ($causes as $cause) {
            if (!is_array($cause)) {
                continue;
            }
            $reason = (string)($cause['reason'] ?? '');
            $message = (string)($cause['message'] ?? '');
            if ($reason === 'ExitCode' && preg_match('/^-?\d+$/', $message)) {
                return ['exitCode' => (int)$message, 'status' => $status];
            }
        }

        return null;
    }
}
