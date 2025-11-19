<?php
$serverName = "localhost";
$connectionOptions = [
  "Database" => "nextccdb",
  "Uid"      => "sa",
  "PWD"      => '$olidus'
];
$conn = sqlsrv_connect($serverName, $connectionOptions);
if(!$conn) { die(print_r(sqlsrv_errors(), true)); }

function fetchServerDateContext() {
    $tzName = @date_default_timezone_get();
    try {
        $tz = new DateTimeZone($tzName ?: 'UTC');
    } catch (Exception $e) {
        $tz = new DateTimeZone('UTC');
    }

    $now = new DateTimeImmutable('now', $tz);
    return [$now, $tz];
}

list($serverNowObj, $serverTimezone) = fetchServerDateContext();

if (!function_exists('grantUploadAccess')) {
    function grantUploadAccess($path, $isDirectory = false) {
        static $account = null;
        static $owner = null;
        if (!is_string($path) || $path === '') {
            return;
        }
        if ($account === null) {
            $account = getenv('CASE_UPLOADS_ACCOUNT');
            if ($account === false) {
                $account = '';
            }
            $account = trim($account);
            if ($account === '') {
                $account = 'Users';
            }
        }
        if ($owner === null) {
            $owner = getenv('CASE_UPLOADS_OWNER');
            $owner = $owner === false ? '' : trim($owner);
        }
        if (!function_exists('exec') || stripos(PHP_OS_FAMILY, 'Windows') === false) {
            return;
        }
        if (!file_exists($path)) {
            return;
        }
        if ($account !== '') {
            $permission = $isDirectory ? '(OI)(CI)RX' : '(R)';
            $command = 'icacls ' . escapeshellarg($path) . ' /grant ' . escapeshellarg($account) . ':' . $permission;
            $output = [];
            $status = 0;
            @exec($command . ' 2>&1', $output, $status);
            if ($status !== 0 && !empty($output)) {
                error_log('icacls failed for ' . $path . ': ' . implode('; ', $output));
            }
        }
        if ($owner !== '') {
            $setOwner = 'icacls ' . escapeshellarg($path) . ' /setowner ' . escapeshellarg($owner);
            $ownerOutput = [];
            $ownerStatus = 0;
            @exec($setOwner . ' 2>&1', $ownerOutput, $ownerStatus);
            if ($ownerStatus !== 0 && !empty($ownerOutput)) {
                error_log('icacls setowner failed for ' . $path . ': ' . implode('; ', $ownerOutput));
            }
        }
    }
}

$case_number = $_GET['id'] ?? '';
if(!$case_number) { die("No case number provided."); }

// Fetch existing record
$sql = "SELECT * FROM mwcsp_caser WHERE case_number = ?";
$stmt = sqlsrv_query($conn, $sql, [$case_number]);
$case = sqlsrv_fetch_array($stmt, SQLSRV_FETCH_ASSOC);

if($_SERVER['REQUEST_METHOD'] === 'POST'){
    $newNote = isset($_POST['notes']) ? trim($_POST['notes']) : '';

    // attachments for this new note
    $attachmentLinks = [];
    if (!empty($_FILES['attachments']) && is_array($_FILES['attachments']['name'])) {
      $baseDir = __DIR__ . '/uploads/' . preg_replace('/[^A-Za-z0-9_\-]/', '_', $case_number);
      if (!is_dir($baseDir)) {
        @mkdir($baseDir, 0777, true);
      }
      grantUploadAccess($baseDir, true);
      for ($i=0; $i<count($_FILES['attachments']['name']); $i++) {
        if ($_FILES['attachments']['error'][$i] === UPLOAD_ERR_OK) {
          $tmp  = $_FILES['attachments']['tmp_name'][$i];
          $name = basename($_FILES['attachments']['name'][$i]);
          $stamp = date('Ymd_His');
          $safe = $stamp . '_' . preg_replace('/[^\w.\-]/', '_', $name);
          $dest = $baseDir . '/' . $safe;
          if (move_uploaded_file($tmp, $dest)) {
            grantUploadAccess($dest);
            $url = 'uploads/' . rawurlencode($case_number) . '/' . rawurlencode($safe);
            $displayName = htmlspecialchars($name, ENT_QUOTES, 'UTF-8');
            $href = htmlspecialchars($url, ENT_QUOTES, 'UTF-8');
            $attachmentLinks[] = '<a href="' . $href . '" class="attachment-link" data-filename="' . $displayName . '">' . $displayName . '</a>';
          }
        }
      }
    }
    if (!empty($attachmentLinks)) {
      $newNote = trim($newNote . (strlen($newNote)?' ':'') . 'Attachments: ' . implode(', ', $attachmentLinks));
    }

    if(!empty($newNote)) {
        $timestamp = (new DateTime('now', $serverTimezone))->format("Y-m-d H:i:s");
        $noteWithTime = "<b style='color:blue;'>[".$timestamp."]</b> ".$newNote;

        $params = [
            $_POST['spn'], $_POST['first_name'], $_POST['middle_name'], $_POST['family_name'],
            $_POST['phone_number'], $_POST['gender'], $_POST['disability'], $_POST['language'],
            $_POST['user_type'], $_POST['status'], $_POST['address'],
            $noteWithTime, $case_number
        ];

        $update = "UPDATE mwcsp_caser 
                   SET spn=?, first_name=?, middle_name=?, family_name=?, phone_number=?,
                       gender=?, disability=?, language=?, user_type=?, status=?, address=?,
                       notes = CONCAT(notes, CHAR(13)+CHAR(10), ?)
                   WHERE case_number=?";
        $res = sqlsrv_query($conn, $update, $params);
    } else {
        $params = [
            $_POST['spn'], $_POST['first_name'], $_POST['middle_name'], $_POST['family_name'],
            $_POST['phone_number'], $_POST['gender'], $_POST['disability'], $_POST['language'],
            $_POST['user_type'], $_POST['status'], $_POST['address'], $case_number
        ];

        $update = "UPDATE mwcsp_caser 
                   SET spn=?, first_name=?, middle_name=?, family_name=?, phone_number=?,
                       gender=?, disability=?, language=?, user_type=?, status=?, address=?
                   WHERE case_number=?";
        $res = sqlsrv_query($conn, $update, $params);
    }

    if($res){
        ?>
        <!DOCTYPE html>
        <html lang="en">
        <head>
        <meta charset="UTF-8"><title>Case Updated</title>
        <link rel="stylesheet" href="css/style.css">
        <style>
        body{font-family:Arial,sans-serif;background:#f7f9fc;margin:0}
        .container{max-width:600px;margin:60px auto;background:#fff;padding:30px;border-radius:10px;box-shadow:0 5px 15px rgba(0,0,0,.1);text-align:center}
        .success-msg{font-size:20px;color:#2e7d32;font-weight:600}
        </style>
        <script> setTimeout(function(){ window.location.href='cases.php'; }, 3000); </script>
        </head>
        <body>
        <div class="header">
          <a href="form.php">➕ New Case</a>
          <a href="cases.php">📋 Case List</a>
          <a href="search.php">🔍 Search Cases</a>
          <a href="dashboard.php">📊 Dashboard</a>
        </div>
        <div class="container"><p class="success-msg">✅ Case updated successfully.</p></div>
        </body></html>
        <?php
        exit;
    } else {
        die(print_r(sqlsrv_errors(), true));
    }
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<title>Edit Case</title>
<link rel="stylesheet" href="css/style.css">
<style>
body { font-family: Arial, sans-serif; background:#f7f9fc; margin:0; }
.container { max-width: 800px; margin: 20px auto; background:#fff; padding:20px; border-radius:10px; box-shadow:0 5px 15px rgba(0,0,0,.1); }
h2 { text-align:center; margin-bottom:15px; color:#0073e6; }
label { font-weight:bold; margin-bottom:3px; display:block; }
input, select, textarea { width:100%; box-sizing:border-box; padding:6px; margin-bottom:10px; border-radius:5px; border:1px solid #ccc; font-size:14px; }
textarea { resize:vertical; height:80px; }
.row { display:flex; flex-wrap:wrap; gap:10px; }
.col-half { flex:1 1 calc(50% - 5px); }
@media(max-width:600px){ .col-half{flex:1 1 100%;} }
.button-row { display:flex; justify-content:space-between; margin-top:20px; }
button { background:#0073e6; color:#fff; border:none; padding:10px 20px; border-radius:5px; cursor:pointer; font-size:14px; }
button:hover { background:#005bb5; }
/* header */
.header { background:#0073e6; padding:12px 20px; display:flex; align-items:center; gap:10px; }
.header a { color:#fff; text-decoration:none; padding:8px 16px; background:#005bb5; border-radius:5px; font-size:16px; font-weight:500; }
.header a.active { background:#003f7f; } .header a:hover { background:#003f7f; }
#modalNotes { white-space: pre-wrap; }
</style>
</head>
<body>
<div class="header">
  <a href="form.php">➕ New Case</a>
  <a href="cases.php">📋 Case List</a>
  <a href="search.php">🔍 Search Cases</a>
  <a href="dashboard.php">📊 Dashboard</a>
</div>

<div class="container">
<h2>Edit Case</h2>
<form method="post" enctype="multipart/form-data">
  <div class="row">
    <div class="col-half">
      <label>SPN:</label>
      <input type="text" name="spn" value="<?php echo htmlspecialchars($case['spn']); ?>">
    </div>
    <div class="col-half">
      <label>First Name:</label>
      <input type="text" name="first_name" value="<?php echo htmlspecialchars($case['first_name']); ?>">
    </div>
  </div>

  <div class="row">
    <div class="col-half">
      <label>Middle Name:</label>
      <input type="text" name="middle_name" value="<?php echo htmlspecialchars($case['middle_name']); ?>">
    </div>
    <div class="col-half">
      <label>Family Name:</label>
      <input type="text" name="family_name" value="<?php echo htmlspecialchars($case['family_name']); ?>">
    </div>
  </div>

  <div class="row">
    <div class="col-half">
      <label>Phone Number:</label>
      <input type="text" name="phone_number" value="<?php echo htmlspecialchars($case['phone_number']); ?>">
    </div>
    <div class="col-half">
      <label>Gender:</label>
      <input type="text" name="gender" value="<?php echo htmlspecialchars($case['gender']); ?>">
    </div>
  </div>

  <div class="row">
    <div class="col-half">
      <label>Disability:</label>
      <input type="text" name="disability" value="<?php echo htmlspecialchars($case['disability']); ?>">
    </div>
    <div class="col-half">
      <label>Language:</label>
      <input type="text" name="language" value="<?php echo htmlspecialchars($case['language']); ?>">
    </div>
  </div>

  <div class="row">
    <div class="col-half">
      <label>User Type:</label>
      <input type="text" name="user_type" value="<?php echo htmlspecialchars($case['user_type']); ?>">
    </div>
    <div class="col-half">
      <label>Status:</label>
      <select name="status">
        <option value="Open" <?php echo ($case['status']=='Open')?'selected':''; ?>>Open</option>
        <option value="Closed" <?php echo ($case['status']=='Closed')?'selected':''; ?>>Closed</option>
        <option value="Escalated" <?php echo ($case['status']=='Escalated')?'selected':''; ?>>Escalated</option>
      </select>
    </div>
  </div>

  <!-- NEW: Address -->
  <label>Address:</label>
  <input type="text" name="address" value="<?php echo htmlspecialchars($case['address'] ?? ''); ?>">

  <?php if (!empty($case['notes'])): ?>
    <label>Existing Notes:</label>
    <div style="border:1px solid #ccc; padding:10px; background:#f9f9f9; margin-bottom:10px; white-space:pre-wrap;">
      <?php echo $case['notes']; ?>
    </div>
  <?php endif; ?>

  <label>Add Note:</label>
  <textarea name="notes"></textarea>

  <label>Attachments (optional):</label>
  <input type="file" name="attachments[]" multiple>

  <div class="button-row">
    <button type="button" onclick="window.location.href='cases.php'">⬅ Back</button>
    <button type="submit">💾 Save Changes</button>
  </div>
</form>
</div>
</body>
</html>
