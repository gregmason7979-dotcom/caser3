<?php
// mxone_csta_makecall.php
//
// Simple CSTA MakeCall helper for MX-ONE XML on 192.168.1.152:8882
// Requires: Application authentication disabled on the CSTA XML interface.

header('Content-Type: application/json');

// ---- CONFIG ----
$MXONE_HOST = '192.168.1.152';
$MXONE_PORT = 8882;
$LOG_FILE   = __DIR__ . '/csta_makecall.log';

// ---- INPUT ----
$from = isset($_GET['from']) ? trim($_GET['from']) : '';
$to   = isset($_GET['to'])   ? trim($_GET['to'])   : '';

if ($from === '' || $to === '') {
    echo json_encode([
        'success' => false,
        'message' => 'Missing from/to parameters'
    ]);
    exit;
}

// ---- BUILD XML BODY (ECMA-323 MakeCall) ----
// Note: simple form: callingDevice + calledDirectoryNumber, no nested elements.
$xmlBody =
    '<?xml version="1.0" encoding="UTF-8"?>' .
    '<MakeCall xmlns="http://www.ecma-international.org/standards/ecma-323/csta/ed4">' .
      '<callingDevice>' . htmlspecialchars($from, ENT_XML1) . '</callingDevice>' .
      '<calledDirectoryNumber>' . htmlspecialchars($to, ENT_XML1) . '</calledDirectoryNumber>' .
    '</MakeCall>';

// ---- BUILD CSTA 8-BYTE HEADER ----
// Format indicator: 0x0000 = TCP without SOAP
// Length: 2 bytes, big-endian, total length of header + XML
// Invoke ID: 4 ASCII digits (0001–9998)
$invokeId     = random_int(1, 9998);
$invokeIdStr  = str_pad((string)$invokeId, 4, '0', STR_PAD_LEFT);
$totalLength  = 8 + strlen($xmlBody);          // header (8 bytes) + XML body

$header  = "\x00\x00";                         // format indicator (TCP, no SOAP)
$header .= pack('n', $totalLength);           // 2-byte length, big-endian
$header .= $invokeIdStr;                      // 4 ASCII digits

$payload = $header . $xmlBody;

// ---- SEND TO MX-ONE ----
$errno = 0;
$errstr = '';
$fp = @fsockopen($MXONE_HOST, $MXONE_PORT, $errno, $errstr, 3.0);

if (!$fp) {
    $msg = "Socket error: $errstr ($errno)";
    @file_put_contents($LOG_FILE,
        date('Y-m-d H:i:s') . " ERROR $msg\n",
        FILE_APPEND
    );
    echo json_encode([
        'success' => false,
        'message' => $msg
    ]);
    exit;
}

stream_set_timeout($fp, 3);

// write payload
$bytesSent = fwrite($fp, $payload);
fflush($fp);

// try to read one response (if any)
$response = '';
while (!feof($fp)) {
    $chunk = fread($fp, 4096);
    if ($chunk === false || $chunk === '') {
        break;
    }
    $response .= $chunk;
    // MX-ONE MakeCallResponse is small, no need to loop forever
    if (strlen($response) > 8) {
        break;
    }
}

fclose($fp);

// Strip CSTA header from response (if we got anything)
$xmlResponse = '';
if (strlen($response) > 8) {
    $xmlResponse = substr($response, 8); // drop 8-byte CSTA header
}

// ---- LOG ----
$logLine = sprintf(
    "%s FROM=%s TO=%s LEN=%d INVOKE=%s XML=%s RESP=%s\n",
    date('Y-m-d H:i:s'),
    $from,
    $to,
    $totalLength,
    $invokeIdStr,
    $xmlBody,
    $xmlResponse
);
@file_put_contents($LOG_FILE, $logLine, FILE_APPEND);

// ---- JSON RESULT ----
echo json_encode([
    'success' => true,
    'message' => 'MakeCall sent to MX-ONE (with CSTA header)',
    'bytes_sent' => $bytesSent,
    'invoke_id' => $invokeIdStr,
    'raw_response_preview' => substr($xmlResponse, 0, 200)
]);
