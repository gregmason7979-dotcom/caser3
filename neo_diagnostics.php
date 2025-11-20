<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

define('NEO_HELPERS_ONLY', true);
require_once __DIR__ . '/neo_audio.php';

function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function curlWithMeta($url, array $headers) {
    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_TIMEOUT, 20);
    curl_setopt($ch, CURLOPT_HEADER, true);
    if ($headers) {
        curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
    }

    $body = curl_exec($ch);
    $error = curl_error($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $headerSize = curl_getinfo($ch, CURLINFO_HEADER_SIZE);
    $contentType = curl_getinfo($ch, CURLINFO_CONTENT_TYPE);
    curl_close($ch);

    if ($body === false) {
        return [
            'ok' => false,
            'httpCode' => $httpCode ?: 0,
            'contentType' => $contentType ?: '',
            'headers' => [],
            'body' => '',
            'error' => $error ?: 'Unknown cURL error',
        ];
    }

    $rawHeaders = substr($body, 0, $headerSize);
    $payload = substr($body, $headerSize);

    return [
        'ok' => $httpCode >= 200 && $httpCode < 300,
        'httpCode' => $httpCode,
        'contentType' => $contentType ?: '',
        'headers' => array_filter(array_map('trim', explode("\n", $rawHeaders))),
        'body' => $payload,
        'error' => '',
    ];
}

$defaults = [
    'case' => isset($_GET['case']) ? sanitizeCaseNumber($_GET['case']) : '29034',
    'base' => getenv('ASC_NEO_BASE_URL') ?: 'http://172.30.12.6',
    'search' => getenv('ASC_NEO_SEARCH_PATH') ?: '/neoapi/conversations',
    'export' => getenv('ASC_NEO_EXPORT_PATH') ?: '/neoapi/export/conversations/{conversationId}/media',
];

$input = [
    'case' => isset($_POST['case']) ? sanitizeCaseNumber($_POST['case']) : $defaults['case'],
    'base' => isset($_POST['base']) ? trim($_POST['base']) : $defaults['base'],
    'search' => isset($_POST['search']) ? trim($_POST['search']) : $defaults['search'],
    'export' => isset($_POST['export']) ? trim($_POST['export']) : $defaults['export'],
];

$attempted = false;
$results = [];
$headers = buildNeoAuthHeaders();
$headersForDisplay = array_map(function ($h) {
    if (stripos($h, 'Authorization:') === 0) return 'Authorization: (hidden)';
    return $h;
}, $headers);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $attempted = true;
    $caseNumber = $input['case'];
    $searchUrl = buildNeoSearchUrl($input['base'], $input['search'], $caseNumber);
    $searchResponse = curlWithMeta($searchUrl, $headers);
    $payload = json_decode($searchResponse['body'], true);
    $conversationId = $payload ? extractConversationId($payload) : '';

    $results['search'] = [
        'url' => $searchUrl,
        'httpCode' => $searchResponse['httpCode'],
        'contentType' => $searchResponse['contentType'],
        'headers' => $searchResponse['headers'],
        'ok' => $searchResponse['ok'],
        'error' => $searchResponse['error'],
        'bodyPreview' => $searchResponse['body'] === '' ? '' : substr($searchResponse['body'], 0, 1000),
        'conversationId' => $conversationId,
    ];

    if ($conversationId) {
        $exportUrl = buildNeoExportUrl($input['base'], $input['export'], $conversationId);
        $exportHead = curlWithMeta($exportUrl, $headers);
        $results['export'] = [
            'url' => $exportUrl,
            'httpCode' => $exportHead['httpCode'],
            'contentType' => $exportHead['contentType'],
            'headers' => $exportHead['headers'],
            'ok' => $exportHead['ok'],
            'error' => $exportHead['error'],
            'bodyPreview' => $exportHead['body'] === '' ? '' : substr($exportHead['body'], 0, 1000),
        ];
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <title>ASC Neo Diagnostics</title>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; background: #f6f7f9; color: #222; }
        h1 { margin-top: 0; }
        form { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); }
        label { display: block; margin: 10px 0 6px; font-weight: 600; }
        input[type="text"] { width: 100%; padding: 8px; border: 1px solid #ccc; border-radius: 4px; }
        button { margin-top: 12px; padding: 10px 14px; background: #0a6cf1; border: none; border-radius: 4px; color: #fff; cursor: pointer; }
        button:hover { background: #0959c7; }
        .card { background: #fff; padding: 16px; border-radius: 8px; box-shadow: 0 1px 4px rgba(0,0,0,0.08); margin-top: 16px; }
        pre { background: #0f172a; color: #e2e8f0; padding: 12px; border-radius: 6px; overflow-x: auto; }
        .status { display: inline-block; padding: 4px 8px; border-radius: 4px; font-size: 12px; }
        .ok { background: #daf5d7; color: #136333; }
        .fail { background: #fde0e0; color: #8b1f1f; }
        .muted { color: #555; font-size: 13px; }
    </style>
</head>
<body>
    <h1>ASC Neo Diagnostics</h1>
    <p class="muted">Use this page to test Neo search/export responses without downloading the audio. Defaults are pre-filled for your MiCC-E callID flow.</p>
    <form method="post">
        <label for="case">Case / callID</label>
        <input type="text" id="case" name="case" required value="<?php echo h($input['case']); ?>" />

        <label for="base">Neo base URL</label>
        <input type="text" id="base" name="base" required value="<?php echo h($input['base']); ?>" />

        <label for="search">Search path</label>
        <input type="text" id="search" name="search" required value="<?php echo h($input['search']); ?>" />

        <label for="export">Export path</label>
        <input type="text" id="export" name="export" required value="<?php echo h($input['export']); ?>" />

        <p class="muted">Auth headers pulled from env: ASC_NEO_API_TOKEN or ASC_NEO_USERNAME/PASSWORD, plus optional ASC_NEO_TENANT.</p>
        <button type="submit">Run Neo check</button>
    </form>

    <div class="card">
        <h2>Effective headers</h2>
        <pre><?php echo h(implode("\n", $headersForDisplay) ?: 'None'); ?></pre>
    </div>

    <?php if ($attempted): ?>
        <div class="card">
            <h2>Search request</h2>
            <p><strong>URL:</strong> <?php echo h($results['search']['url']); ?></p>
            <p><span class="status <?php echo $results['search']['ok'] ? 'ok' : 'fail'; ?>">HTTP <?php echo h($results['search']['httpCode']); ?></span> <?php if ($results['search']['error']) echo ' - ' . h($results['search']['error']); ?></p>
            <p><strong>Content-Type:</strong> <?php echo h($results['search']['contentType']); ?></p>
            <p><strong>Conversation ID found:</strong> <?php echo h($results['search']['conversationId'] ?: 'none'); ?></p>
            <h3>Raw response headers</h3>
            <pre><?php echo h($results['search']['headers'] ? implode("\n", $results['search']['headers']) : 'None'); ?></pre>
            <h3>Body (first 1000 chars)</h3>
            <pre><?php echo h($results['search']['bodyPreview'] ?: ''); ?></pre>
        </div>

        <?php if (!empty($results['export'])): ?>
            <div class="card">
                <h2>Export request</h2>
                <p><strong>URL:</strong> <?php echo h($results['export']['url']); ?></p>
                <p><span class="status <?php echo $results['export']['ok'] ? 'ok' : 'fail'; ?>">HTTP <?php echo h($results['export']['httpCode']); ?></span> <?php if ($results['export']['error']) echo ' - ' . h($results['export']['error']); ?></p>
                <p><strong>Content-Type:</strong> <?php echo h($results['export']['contentType']); ?></p>
                <h3>Raw response headers</h3>
                <pre><?php echo h($results['export']['headers'] ? implode("\n", $results['export']['headers']) : 'None'); ?></pre>
                <h3>Body preview (first 1000 chars)</h3>
                <pre><?php echo h($results['export']['bodyPreview'] ?: ''); ?></pre>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</body>
</html>
