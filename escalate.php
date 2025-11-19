<?php
// escalate.php — calls OpenMedia AddRequest, stores session id, marks case Escalated
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

$agentExt = '';
$rawExt = '';
if (isset($_GET['ext']) && $_GET['ext'] !== '') {
  $rawExt = $_GET['ext'];
} elseif (isset($_POST['ext']) && $_POST['ext'] !== '') {
  $rawExt = $_POST['ext'];
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

$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid"      => "sa",
  "PWD"      => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if (!$conn) { die(print_r(sqlsrv_errors(), true)); }

$case_number = isset($_GET['id']) ? $_GET['id'] : '';
if (!$case_number) { die("No case number provided."); }

function cleanSessionToken($value) {
  if (!is_string($value)) {
    return '';
  }

  $token = trim($value);
  if ($token === '') {
    return '';
  }

  $token = preg_replace('/\bSuccess\b/i', '', $token);
  $trimCharacters = ' :;|';
  $token = trim($token, $trimCharacters);
  if ($token === '') {
    return '';
  }

  if (preg_match_all('/[A-Za-z0-9\-]+/', $token, $matches) && !empty($matches[0])) {
    $candidates = $matches[0];
    for ($i = count($candidates) - 1; $i >= 0; $i--) {
      $candidate = $candidates[$i];
      if ($candidate !== '' && preg_match('/\d/', $candidate)) {
        return $candidate;
      }
    }
    $last = end($candidates);
    if ($last !== false) {
      return $last;
    }
  }

  return '';
}


// Fetch case (for IVR/labels)
$sql  = "SELECT TOP 1 * FROM mwcsp_caser WHERE case_number = ?";
$stmt = sqlsrv_query($conn, $sql, [$case_number]);
$case = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);
if (!$case) { die("Case not found."); }

$fullName = trim(($case['first_name'] ?? '').' '.($case['middle_name'] ?? '').' '.($case['family_name'] ?? ''));
$phone    = $case['phone_number'] ?? '';
$status   = $case['status'] ?? 'Open';

$endpoint = "http://192.168.1.154:12615/OpenMediaService";
$searchUrl  = "http://192.168.1.154/caser/search.php?term=".rawurlencode($case_number); // per your working design

// Build SOAP (3 IVR items max per your note)
$soapBody = '<?xml version="1.0" encoding="utf-8"?>'.
'<soap:Envelope xmlns:soap="http://schemas.xmlsoap.org/soap/envelope/" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance" xmlns:xsd="http://www.w3.org/2001/XMLSchema">'.
  '<soap:Header>'.
    '<LogonID xmlns="OpenMediaService">Admin</LogonID>'.
    '<Password xmlns="OpenMediaService">Admin</Password>'.
  '</soap:Header>'.
  '<soap:Body>'.
    '<AddRequest xmlns="http://tempuri.org/">'.
      '<request>'.
        '<ForceToPreferredAgent xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts">false</ForceToPreferredAgent>'.
        '<IVRInfo xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts">'.
          '<IVRInformation xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts"><Data>'.htmlspecialchars($searchUrl).'</Data><Label>CaseURL</Label></IVRInformation>'.
          '<IVRInformation xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts"><Data>'.htmlspecialchars($case_number).'</Data><Label>CaseNumber</Label></IVRInformation>'.
          '<IVRInformation xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts"><Data>'.htmlspecialchars($phone).'</Data><Label>Phone</Label></IVRInformation>'.
        '</IVRInfo>'.
        '<PreferredAgentID xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts">0</PreferredAgentID>'.
        '<PreferredAgentLogonID xsi:nil="true" xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts"/>'.
        '<PrivateData xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts"/>'.
        '<QueueStartTime xsi:nil="true" xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts"/>'.
        '<ServiceGroupID xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts">9</ServiceGroupID>'.
        '<ServiceGroupName xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts"/>'.
        '<SessionPriority xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts">0</SessionPriority>'.
        '<TenantID xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts">-1</TenantID>'.
        '<TypeOfSession xmlns="http://schemas.datacontract.org/2004/07/Solidus.OpenMedia.Contracts.DataContracts">0</TypeOfSession>'.
      '</request>'.
    '</AddRequest>'.
  '</soap:Body>'.
'</soap:Envelope>';

$ch = curl_init($endpoint);
curl_setopt_array($ch, [
  CURLOPT_RETURNTRANSFER => true,
  CURLOPT_POST           => true,
  CURLOPT_HTTPHEADER     => [
    'Content-Type: text/xml; charset=utf-8',
    'SOAPAction: "http://tempuri.org/IOpenMediaService/AddRequest"',
  ],
  CURLOPT_POSTFIELDS     => $soapBody,
  CURLOPT_TIMEOUT        => 10,
]);
$response = curl_exec($ch);
$httpInfo = curl_getinfo($ch);
$err      = curl_error($ch);
curl_close($ch);

$sessionId = null;
$statusToken = null;
$sessionCandidates = [];
$openMediaId = null;
$httpSuccess = (!$err && isset($httpInfo['http_code']) && $httpInfo['http_code'] == 200);

if ($httpSuccess) {
  if (preg_match('/<(?:[A-Za-z0-9_]+:)?OpenMediaID[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?OpenMediaID>/s', $response, $mOpen)) {
    $openMediaId = cleanSessionToken($mOpen[1]);
  }
  if (preg_match('/<(?:[A-Za-z0-9_]+:)?RequestID[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?RequestID>/s', $response, $m)) {
    $sessionCandidates[] = $m[1];
  }
  if (preg_match('/<(?:[A-Za-z0-9_]+:)?AddRequestResult[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?AddRequestResult>/s', $response, $mAdd)) {
    $sessionCandidates[] = $mAdd[1];
  }
  if (preg_match('/<(?:[A-Za-z0-9_]+:)?Status[^>]*>(.*?)<\/(?:[A-Za-z0-9_]+:)?Status>/s', $response, $mStatus)) {
    $statusToken = trim($mStatus[1]);
  }

  if ($xml = @simplexml_load_string($response)) {
    if ($openMediaId === null) {
      $xpathOpen = $xml->xpath('//*[local-name()="OpenMediaID"]');
      if ($xpathOpen && isset($xpathOpen[0])) {
        $openMediaId = cleanSessionToken((string)$xpathOpen[0]);
      }
    }

    if (empty($sessionCandidates)) {
      $xpathRequest = $xml->xpath('//*[local-name()="RequestID"]');
      if ($xpathRequest && isset($xpathRequest[0])) {
        $sessionCandidates[] = (string)$xpathRequest[0];
      }
      $xpathAdd = $xml->xpath('//*[local-name()="AddRequestResult"]');
      if ($xpathAdd && isset($xpathAdd[0])) {
        $sessionCandidates[] = (string)$xpathAdd[0];
      }
    }
    if ($statusToken === null) {
      $statusNodes = $xml->xpath('//*[local-name()="Status"]');
      if ($statusNodes && isset($statusNodes[0])) {
        $statusToken = trim((string)$statusNodes[0]);
      }
    }
  }

  if ($openMediaId !== null && $openMediaId !== '') {
    $sessionId = $openMediaId;
  }

  if ($sessionId === null || $sessionId === '') {
    foreach ($sessionCandidates as $candidate) {
      $cleaned = cleanSessionToken($candidate);
      if ($cleaned !== '') {
        $sessionId = $cleaned;
        break;
      }
    }
  }
}

$hasSessionId = is_string($sessionId) && $sessionId !== '';
$statusIsSuccess = is_string($statusToken) && stripos($statusToken, 'success') !== false;
$soapSucceeded = $httpSuccess && ($hasSessionId || $statusIsSuccess);

if ($soapSucceeded) {
  if ($hasSessionId) {
    sqlsrv_query(
      $conn,
      "UPDATE mwcsp_caser SET escalation_session_id=?, status='Escalated' WHERE case_number=?",
      [$sessionId, $case_number]
    );
    $msg = "✅ Escalated. Session ID: ".htmlspecialchars($sessionId);
  } else {
    sqlsrv_query(
      $conn,
      "UPDATE mwcsp_caser SET status='Escalated' WHERE case_number=?",
      [$case_number]
    );
    $msg = "✅ Escalated.";
  }
} else {
  $statusFragment = $statusToken ? (' Status: '.$statusToken) : '';
  $msg = "❌ Escalation failed. ".htmlspecialchars($err ?: ('HTTP '.$httpInfo['http_code'].$statusFragment));
}

sqlsrv_close($conn);

// Return to referrer after 3 seconds
$back = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
if ($back === '') {
  $back = 'cases.php';
}
$back = appendAgentExtToUrl($back, $agentExt);
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Escalate</title>
<link rel="stylesheet" href="css/style.css">
<style>
body { font-family: Arial, sans-serif; background:#f7f9fc; margin:0; }
.container { max-width: 600px; margin: 60px auto; background:#fff; padding:30px; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,0.1); text-align:center; }
.msg { font-size:18px; }
</style>
<script> setTimeout(function(){ window.location.href = <?php echo json_encode($back); ?>; }, 3000); </script>
</head>
<body>
<div class="header">
  <a href="<?php echo appendAgentExtToUrl('form.php', $agentExt); ?>">➕ New Case</a>
  <a href="<?php echo appendAgentExtToUrl('cases.php', $agentExt); ?>">📋 Case List</a>
  <a href="<?php echo appendAgentExtToUrl('search.php', $agentExt); ?>">🔍 Search Cases</a>
  <a href="<?php echo appendAgentExtToUrl('dashboard.php', $agentExt); ?>">📊 Dashboard</a>
</div>
<div class="container"><p class="msg"><?php echo $msg; ?></p></div>
</body>
</html>
