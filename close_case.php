<?php
session_start();

function sanitizeAgentExtension($value) {
    return preg_replace('/[^0-9*#+]/', '', (string)$value);
}

function appendAgentExtToUrl($url, $agentExtValue) {
    $agentExtValue = trim((string)$agentExtValue);
    if ($agentExtValue === '') {
        return $url;
    }
    $separator = (strpos($url, '?') === false) ? '?' : '&';
    return $url . $separator . 'ext=' . urlencode($agentExtValue);
}

function ensureAgentExtOnUrl($url, $agentExtValue) {
    $agentExtValue = trim((string)$agentExtValue);
    if ($agentExtValue === '') {
        return $url;
    }

    $parsed = parse_url($url);
    $query = isset($parsed['query']) ? $parsed['query'] : '';
    if ($query !== '') {
        parse_str($query, $params);
        if (!empty($params['ext'])) {
            return $url;
        }
    }

    return appendAgentExtToUrl($url, $agentExtValue);
}

function sanitizeRedirectTarget($value) {
    $value = trim((string)$value);
    if ($value === '') {
        return 'cases.php';
    }

    $value = str_replace(["\r", "\n"], '', $value);
    if (preg_match('/^(https?:)?\/\//i', $value)) {
        return 'cases.php';
    }

    if (!preg_match('/^[A-Za-z0-9_\-\.\/\?&=%+]*$/', $value)) {
        return 'cases.php';
    }

    return $value;
}

$agentExt = '';
$rawExt = '';
if (!empty($_REQUEST['ext'])) {
    $rawExt = $_REQUEST['ext'];
}

if ($rawExt !== '') {
    $agentExt = sanitizeAgentExtension($rawExt);
    if ($agentExt !== '') {
        $_SESSION['agent_ext'] = $agentExt;
        setcookie('agent_ext', $agentExt, time() + 31536000, '/');
    }
} elseif (!empty($_SESSION['agent_ext'])) {
    $agentExt = sanitizeAgentExtension($_SESSION['agent_ext']);
} elseif (!empty($_COOKIE['agent_ext'])) {
    $agentExt = sanitizeAgentExtension($_COOKIE['agent_ext']);
    if ($agentExt !== '') {
        $_SESSION['agent_ext'] = $agentExt;
    }
}

$caseNumber = '';
if (isset($_REQUEST['case'])) {
    $caseNumber = (string)$_REQUEST['case'];
} elseif (isset($_REQUEST['close_case'])) {
    $caseNumber = (string)$_REQUEST['close_case'];
}
$caseNumber = trim($caseNumber);
if ($caseNumber !== '') {
    $caseNumber = preg_replace('/[^0-9A-Za-z_-]/', '', $caseNumber);
}

$redirectTarget = sanitizeRedirectTarget($_REQUEST['redirect'] ?? 'cases.php');

if ($caseNumber === '') {
    header('Location: ' . ensureAgentExtOnUrl($redirectTarget, $agentExt));
    exit;
}

$serverName = "localhost";
$connectionOptions = [
    "Database" => "nextccdb",
    "Uid" => "sa",
    "PWD" => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if ($conn) {
    $update = "UPDATE mwcsp_caser SET status='Closed' WHERE case_number=?";
    sqlsrv_query($conn, $update, [$caseNumber]);
    sqlsrv_close($conn);
}

header('Location: ' . ensureAgentExtOnUrl($redirectTarget, $agentExt));
exit;
