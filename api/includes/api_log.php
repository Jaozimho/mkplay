<?php

function apiLog(string $event, array $context = []): void
{
    static $requestId = null;
    if ($requestId === null) {
        $requestId = bin2hex(random_bytes(8));
        header('X-Request-ID: ' . $requestId);
    }

    $record = [
        'time' => gmdate('Y-m-d\TH:i:s\Z'),
        'request_id' => $requestId,
        'endpoint' => basename((string) ($_SERVER['SCRIPT_NAME'] ?? 'unknown')),
        'method' => (string) ($_SERVER['REQUEST_METHOD'] ?? 'unknown'),
        'event' => $event,
        'status' => http_response_code() ?: 200,
        'user_agent' => substr((string) ($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 240),
    ];

    foreach ($context as $key => $value) {
        if (is_scalar($value) || $value === null) {
            $record[$key] = is_string($value)
                ? apiSanitizePayload($value, (string) $key)
                : $value;
        }
    }

    try {
        $line = json_encode(
            $record,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        ) . PHP_EOL;
    } catch (JsonException $e) {
        error_log('API request logger could not encode a log record: ' . $e->getMessage());
        return;
    }

    $directory = dirname(__DIR__) . '/logs';
    if (!is_dir($directory) && !@mkdir($directory, 0750, true) && !is_dir($directory)) {
        error_log('API request logger could not create its log directory.');
        return;
    }

    apiAppendLogFile($directory . '/api.log', $line);

    $event = strtolower((string) ($record['event'] ?? ''));
    if ($event === 'api_request_received') {
        apiAppendLogFile($directory . '/requests.log', $line);
    } elseif (in_array($event, ['api_response_sent', 'response_sent'], true)) {
        apiAppendLogFile($directory . '/responses.log', $line);
    } elseif (preg_match('/error|failed|fatal|rejected/', $event) === 1) {
        apiAppendLogFile($directory . '/errors.log', $line);
    } else {
        apiAppendLogFile($directory . '/actions.log', $line);
    }

    if (in_array((string) ($record['endpoint'] ?? ''), [
        'getappuser.php',
        'getplaylist.php',
        'getupdate.php',
        'clientlog.php',
        'ads.php',
        'allads.php',
        'theme_config.php',
        'portals.php',
        'themes.php',
        'sport.php',
        'intro.php',
    ], true)) {
        apiAppendLogFile($directory . '/apk.log', $line);
    }
}

function apiAppendLogFile(string $path, string $line): void
{
    $maxBytes = 5 * 1024 * 1024;
    clearstatcache(true, $path);
    if (is_file($path) && filesize($path) + strlen($line) > $maxBytes) {
        $previous = $path . '.1';
        if (is_file($previous) && !@unlink($previous)) {
            error_log('API request logger could not remove a previous rotated log.');
            return;
        }
        if (!@rename($path, $previous)) {
            error_log('API request logger could not rotate a log file.');
            return;
        }
    }

    $written = @file_put_contents($path, $line, FILE_APPEND | LOCK_EX);
    if ($written === false) {
        error_log('API request logger could not append a log file.');
    }
}

function apiRegisterRuntimeLogging(): void
{
    static $registered = false;
    if ($registered) {
        return;
    }
    $registered = true;

    set_error_handler(static function (int $severity, string $message, string $file, int $line): bool {
        if (!(error_reporting() & $severity)) {
            return false;
        }

        apiLog('php_error', [
            'severity' => $severity,
            'message' => substr($message, 0, 1000),
            'file' => basename($file),
            'line' => $line,
        ]);
        return false;
    });

    register_shutdown_function(static function (): void {
        $error = error_get_last();
        if ($error === null || !in_array($error['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR, E_USER_ERROR], true)) {
            return;
        }

        http_response_code(500);
        apiLog('php_fatal', [
            'severity' => $error['type'],
            'message' => substr((string) $error['message'], 0, 1000),
            'file' => basename((string) $error['file']),
            'line' => (int) $error['line'],
        ]);
    });
}

function apiStartRequestLogging(): void
{
    static $started = false;
    if ($started) {
        return;
    }
    $started = true;
    apiRegisterRuntimeLogging();

    $contentLength = filter_var($_SERVER['CONTENT_LENGTH'] ?? null, FILTER_VALIDATE_INT);
    $bodyLimit = 65536;
    $body = @file_get_contents('php://input', false, null, 0, $bodyLimit);
    $bodyReadFailed = $body === false;
    $body = is_string($body) ? $body : '';
    $bodyTruncated = strlen($body) === $bodyLimit
        && ($contentLength === false || $contentLength === null || $contentLength > $bodyLimit);
    $requestData = null;
    $contentType = strtolower((string) ($_SERVER['CONTENT_TYPE'] ?? ''));
    $bodyJsonValid = null;

    if (str_contains($contentType, 'application/json') && $body !== '') {
        $requestData = json_decode($body, true);
        $bodyJsonValid = json_last_error() === JSON_ERROR_NONE;
    } elseif (str_contains($contentType, 'application/x-www-form-urlencoded')) {
        $requestData = $_POST;
    }

    $requestSummary = [
        'query' => apiSanitizePayload($_GET),
        'form' => apiSanitizePayload($_POST),
    ];
    if ($bodyJsonValid === true) {
        $requestSummary['body'] = apiSanitizePayload($requestData);
    } elseif (is_array($requestData)) {
        $requestSummary['body'] = apiSanitizePayload($requestData);
    } elseif ($body !== '') {
        $requestSummary['body'] = '[invalid JSON or non-JSON body omitted]';
    }

    apiLog('api_request_received', [
        'content_type' => $contentType,
        'content_length' => $contentLength === false ? null : $contentLength,
        'body_sample_bytes' => strlen($body),
        'body_sample_truncated' => $bodyTruncated,
        'body_read_failed' => $bodyReadFailed,
        'body_json_valid' => $bodyJsonValid,
        'query_fields' => apiPayloadFieldNames($_GET),
        'form_fields' => apiPayloadFieldNames($_POST),
        'body_fields' => apiPayloadFieldNames($requestData),
        'request_summary' => apiEncodeLogSummary($requestSummary),
    ]);

    ob_start();
    register_shutdown_function(static function (): void {
        $output = ob_get_contents();
        $output = is_string($output) ? $output : '';
        $status = http_response_code() ?: 200;
        $contentType = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Content-Type:') === 0) {
                $contentType = trim(substr($header, strlen('Content-Type:')));
            }
        }

        $responseSummary = apiSummarizeResponse($output);
        $location = '';
        foreach (headers_list() as $header) {
            if (stripos($header, 'Location:') === 0) {
                $location = apiSafeUrlInfo(trim(substr($header, strlen('Location:'))));
            }
        }

        apiLog('api_response_sent', [
            'response_status' => $status,
            'response_bytes' => strlen($output),
            'content_type' => $contentType,
            'response_summary' => $responseSummary,
            'redirect_to' => $location,
            'duration_ms' => isset($GLOBALS['api_request_started_at'])
                ? max(0, (int) round((microtime(true) - $GLOBALS['api_request_started_at']) * 1000))
                : null,
        ]);

        if (ob_get_level() > 0) {
            ob_end_flush();
        }
    });
    $GLOBALS['api_request_started_at'] = microtime(true);
}

function apiSanitizePayload(mixed $value, ?string $key = null, int $depth = 0): mixed
{
    if ($key !== null) {
        if (preg_match('/password|passwd|token|authorization|api[_-]?key|secret|device[_-]?key|private[_-]?key|pix[_-]?key|pin/i', $key) === 1) {
            return '[REDACTED]';
        }
        if (preg_match('/^(?:mac|mac_address)$/i', $key) === 1) {
            return apiMaskedMac(is_string($value) ? $value : null);
        }
        $normalizedKey = strtolower($key);
        if (preg_match('/(?:^|_)(?:url|uri)$/i', $key) === 1
            || str_ends_with($normalizedKey, '_url')
            || str_ends_with($normalizedKey, 'url')
            || str_ends_with($normalizedKey, 'uri')
            || str_ends_with($normalizedKey, 'image')) {
            return is_string($value) ? apiSafeUrlInfo($value) : '[omitted]';
        }
        if (strtolower($key) === 'data' && is_string($value)) {
            return '[encoded payload omitted; bytes=' . strlen($value) . ']';
        }
    }

    if ($depth >= 8) {
        return '[maximum depth reached]';
    }
    if (is_array($value)) {
        $safe = [];
        $count = 0;
        foreach ($value as $childKey => $childValue) {
            if ($count++ >= 100) {
                $safe['_truncated'] = true;
                break;
            }
            $safe[$childKey] = apiSanitizePayload(
                $childValue,
                is_string($childKey) ? $childKey : null,
                $depth + 1
            );
        }
        return $safe;
    }
    if (is_string($value)) {
        return substr(apiRedactClientLog($value), 0, 1000);
    }
    if (is_scalar($value) || $value === null) {
        return $value;
    }

    return '[' . get_debug_type($value) . ']';
}

function apiEncodeLogSummary(mixed $value): string
{
    try {
        $encoded = json_encode(
            $value,
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR
        );
    } catch (JsonException $e) {
        error_log('API request logger could not encode a request summary: ' . $e->getMessage());
        return '[summary unavailable]';
    }

    return strlen($encoded) > 30000
        ? '[summary omitted; bytes=' . strlen($encoded) . ']'
        : $encoded;
}

function apiSummarizeResponse(string $output): string
{
    if (strlen($output) > 131072) {
        return '[response summary omitted; bytes=' . strlen($output) . ']';
    }

    $decoded = json_decode($output, true);
    if (!is_array($decoded)) {
        return $output === '' ? '[empty response]' : '[non-JSON response omitted; bytes=' . strlen($output) . ']';
    }

    if (isset($decoded['data']) && is_string($decoded['data'])) {
        $encoded = $decoded['data'];
        $appJson = str_ends_with($encoded, 'aa')
            ? base64_decode(substr($encoded, 0, -2), true)
            : false;
        $appPayload = $appJson === false ? null : json_decode($appJson, true);
        if (is_array($appPayload)) {
            return apiEncodeLogSummary([
                'fields' => apiPayloadFieldNames($appPayload),
                'decoded_data' => apiSanitizePayload($appPayload),
            ]);
        }
        return apiEncodeLogSummary([
            'fields' => apiPayloadFieldNames($decoded),
            'data' => '[encoded payload omitted; bytes=' . strlen($encoded) . ']',
        ]);
    }

    return apiEncodeLogSummary(apiSanitizePayload($decoded));
}

function apiMaskedMac(?string $mac): string
{
    if ($mac === null || $mac === '') {
        return '';
    }

    $normalized = preg_replace('/[^A-Fa-f0-9]/', '', $mac);
    if (!is_string($normalized) || strlen($normalized) < 4) {
        return '[invalid]';
    }

    return '…' . strtoupper(substr($normalized, -4));
}

function apiSafeUrlInfo(string $url): string
{
    $parts = parse_url($url);
    if (!is_array($parts)) {
        return '[invalid-url]';
    }

    $host = (string) ($parts['host'] ?? '');
    $path = (string) ($parts['path'] ?? '');
    return $host === '' ? '[no-host]' : $host . $path;
}

function apiPayloadFieldNames(mixed $payload): string
{
    if (!is_array($payload)) {
        return '';
    }

    return implode(',', array_map('strval', array_keys($payload)));
}

function apiRedactClientLog(string $text): string
{
    $text = substr($text, 0, 5000);
    $text = preg_replace(
        '/((?:password|passwd|token|authorization|api[_-]?key|secret|device[_-]?key|private[_-]?key|pix[_-]?key|pin)\s*[:=]\s*)("[^"]*"|\'[^\']*\'|[^,\s;&]+)/i',
        '$1[REDACTED]',
        $text
    ) ?? '[redaction failed]';
    $text = preg_replace(
        '/([?&](?:password|passwd|token|api[_-]?key|secret|device[_-]?key|pin)=)[^&\s]*/i',
        '$1[REDACTED]',
        $text
    ) ?? '[redaction failed]';

    return $text;
}
