<?php
session_start();

function sanitizeCaseNumber($value) {
    return preg_replace('/[^A-Za-z0-9_-]/', '', (string)$value);
}

function scanRemoteDir($url, $depth = 0, $maxDepth = 2, &$visited = []) {
    $mp3_files = [];
    $url = rtrim($url, '/');

    if ($depth > $maxDepth || in_array($url, $visited, true)) return [];
    $visited[] = $url;

    $context = stream_context_create(['http' => ['timeout' => 5]]);
    $html = @file_get_contents($url, false, $context);
    if ($html === false) return [];

    preg_match_all('/href="([^"]+)"/i', $html, $matches);
    if (!empty($matches[1])) {
        foreach ($matches[1] as $link) {
            if ($link === '../' || $link === './') continue;

            if (preg_match('#^https?://#i', $link)) {
                $full_link = $link;
            } elseif (substr($link, 0, 1) === '/') {
                $full_link = 'http://192.168.1.154' . $link;
            } else {
                $full_link = $url . '/' . ltrim($link, '/');
            }

            if (preg_match('/\.mp3$/i', $link)) {
                $mp3_files[] = $full_link;
            } elseif (substr($link, -1) == '/') {
                $mp3_files = array_merge($mp3_files, scanRemoteDir($full_link, $depth + 1, $maxDepth, $visited));
            }
        }
    }
    return $mp3_files;
}

function findLocalRecordingUrl($caseNumber, $audioBaseUrl) {
    static $cached;
    if ($cached === null) {
        $cached = scanRemoteDir($audioBaseUrl);
    }

    foreach ($cached as $file) {
        if (stripos(basename($file), $caseNumber) !== false) {
            return $file;
        }
    }
    return '';
}

function buildNeoAuthHeaders() {
    $headers = ['Accept: application/json'];
    $token = getenv('ASC_NEO_API_TOKEN');
    $user = getenv('ASC_NEO_USERNAME');
    $pass = getenv('ASC_NEO_PASSWORD');
    $tenant = getenv('ASC_NEO_TENANT');

    if ($token) {
        $headers[] = 'Authorization: Bearer ' . trim($token);
    } elseif ($user && $pass) {
        $headers[] = 'Authorization: Basic ' . base64_encode($user . ':' . $pass);
    }

    if ($tenant) {
        $headers[] = 'X-Tenant: ' . trim($tenant);
    }

    return $headers;
}

function neoApiGetJson($url, array $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 15);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        curl_close($ch);
        return null;
    }

    $data = json_decode($body, true);
    curl_close($ch);
    return is_array($data) ? $data : null;
}

function buildNeoSearchUrl($baseUrl, $searchPath, $caseNumber) {
    $baseUrl = rtrim($baseUrl, '/');
    $searchPath = '/' . ltrim($searchPath, '/');

    if (strpos($searchPath, '{caseId}') !== false) {
        return $baseUrl . str_replace('{caseId}', rawurlencode($caseNumber), $searchPath);
    }

    $url = $baseUrl . $searchPath;
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . 'conversationParameters.callID=' . rawurlencode($caseNumber) . '&size=1';
}

function extractConversationId($payload) {
    $candidates = [];
    if (isset($payload['_embedded']['conversations'])) {
        $candidates = $payload['_embedded']['conversations'];
    } elseif (isset($payload['content'])) {
        $candidates = $payload['content'];
    } elseif (is_array($payload)) {
        $candidates = $payload;
    }

    foreach ($candidates as $conv) {
        if (!is_array($conv)) continue;
        if (!empty($conv['conversationId'])) return $conv['conversationId'];
        if (!empty($conv['id'])) return $conv['id'];
    }

    return '';
}

function buildNeoExportUrl($baseUrl, $exportPath, $conversationId) {
    $baseUrl = rtrim($baseUrl, '/');
    $exportPath = '/' . ltrim($exportPath, '/');

    if (strpos($exportPath, '{conversationId}') !== false) {
        $exportPath = str_replace('{conversationId}', rawurlencode($conversationId), $exportPath);
    } elseif (strpos($exportPath, '{id}') !== false) {
        $exportPath = str_replace('{id}', rawurlencode($conversationId), $exportPath);
    } else {
        $exportPath = rtrim($exportPath, '/') . '/' . rawurlencode($conversationId) . '/media';
    }

    return $baseUrl . $exportPath;
}

function streamNeoRecording($url, array $headers, $caseNumber) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    if (!empty($headers)) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false || $httpCode < 200 || $httpCode >= 300) {
        http_response_code(404);
        echo 'Recording not found.';
        return;
    }

    header('Content-Type: ' . ($contentType ?: 'audio/mpeg'));
    header('Content-Disposition: inline; filename="case-' . $caseNumber . '.mp3"');
    echo $body;
}

if (!defined('NEO_HELPERS_ONLY')) {
    $caseRaw = isset($_GET['case']) ? $_GET['case'] : '';
    $caseNumber = sanitizeCaseNumber($caseRaw);

    if ($caseNumber === '') {
        http_response_code(400);
        echo 'Missing case number.';
        exit;
    }

    $audioBaseUrl = getenv('LOCAL_AUDIO_BASE_URL') ?: 'http://192.168.1.154/secrecord';
    $localUrl = findLocalRecordingUrl($caseNumber, $audioBaseUrl);
    if ($localUrl) {
        header('Location: ' . $localUrl, true, 302);
        exit;
    }

    $neoBaseUrl = getenv('ASC_NEO_BASE_URL');
    if (!$neoBaseUrl) {
        http_response_code(404);
        echo 'Recording not found.';
        exit;
    }

    $neoSearchPath = getenv('ASC_NEO_SEARCH_PATH') ?: '/neoapi/conversations';
    $neoExportPath = getenv('ASC_NEO_EXPORT_PATH') ?: '/neoapi/export/conversations/{conversationId}/media';
    $headers = buildNeoAuthHeaders();

    $searchUrl = buildNeoSearchUrl($neoBaseUrl, $neoSearchPath, $caseNumber);
    $payload = neoApiGetJson($searchUrl, $headers);
    $conversationId = $payload ? extractConversationId($payload) : '';

    if (!$conversationId) {
        http_response_code(404);
        echo 'Recording not found.';
        exit;
    }

    $exportUrl = buildNeoExportUrl($neoBaseUrl, $neoExportPath, $conversationId);
    streamNeoRecording($exportUrl, $headers, $caseNumber);
}
