<?php
//database credentials
$dbHost = "localhost";
$dbName = "name";
$dbUser = "username";
$dbPass = "password";

function h($s) { return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8'); }

try {
    $pdo = new PDO(
        "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4",
        $dbUser,
        $dbPass,
        [
            PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
// ---- Simple Base64 decode once before any logic ----
if (!empty($_SERVER['QUERY_STRING'])) {
    $raw = $_SERVER['QUERY_STRING'];
    $decoded = base64_decode($raw, true);
    if ($decoded !== false) {
        parse_str($decoded, $p);
        // merge decoded params into GET/POST so normal logic works
        $_GET  = $p + $_GET;
        $_POST = $p + $_POST;
    }
}

/* --- REGISTER MODE: add a node to sensor_register via URL/POST --- */
$regAction = $_POST['action'] ?? $_GET['action'] ?? null;
if ($regAction === 'register') {
    header('Content-Type: application/json');

    $regNode = $_POST['node_name'] ?? $_GET['node_name'] ?? null;
    $mfg     = $_POST['manufacturer'] ?? $_GET['manufacturer'] ?? null;
    $lon     = $_POST['longitude'] ?? $_GET['longitude'] ?? null;
    $lat     = $_POST['latitude']  ?? $_GET['latitude']  ?? null;

    // Basic validation
    if (!$regNode || !$mfg) {
        echo json_encode(['status'=>400,'message'=>'node_name and manufacturer are required']); exit;
    }
    // enforce <=10 chars 
    if (strlen($regNode) > 10 || strlen($mfg) > 10) {
        echo json_encode(['status'=>400,'message'=>'node_name and manufacturer must be ≤ 10 chars']); exit;
    }

    try {
        $stmt = $pdo->prepare("
            INSERT INTO sensor_register (node_name, manufacturer, longitude, latitude)
            VALUES (:n, :m, :lon, :lat)
        ");
        $stmt->execute([
            ':n'   => $regNode,
            ':m'   => $mfg,
            ':lon' => ($lon === null ? null : (float)$lon),
            ':lat' => ($lat === null ? null : (float)$lat),
        ]);

        echo json_encode([
            'status'       => 200,
            'message'      => 'Registered',
            'node_name'    => $regNode,
            'manufacturer' => $mfg,
            'longitude'    => ($lon === null ? null : (float)$lon),
            'latitude'     => ($lat === null ? null : (float)$lat)
        ]); 
        exit;
    } catch (PDOException $e) {
        if (!empty($e->errorInfo[1]) && $e->errorInfo[1] == 1062) {
            echo json_encode(['status'=>409,'message'=>'Node already registered']); exit;
        }
        echo json_encode(['status'=>500,'message'=>'Database error: '.$e->getMessage()]); exit;
    }
}

    /* --- API MODE: insert via POST/GET params and return JSON, then exit --- */
    // Accept POST first, fall back to GET
    $node = $_POST['node_name'] ?? $_GET['node_name'] ?? null;
    $temp = $_POST['temperature'] ?? $_GET['temperature'] ?? null;
    $hum  = $_POST['humidity']    ?? $_GET['humidity']    ?? null;
    $time = $_POST['time_received'] ?? $_GET['time_received'] ?? null;
    
    /* --- decode Base64 message if provided --- */
$rawInput = null;
// GET: raw query string after the "?" 
if (!empty($_SERVER['QUERY_STRING'])) {
    $rawInput = $_SERVER['QUERY_STRING'];
} 
// POST: raw body (for devices sending Base64 in the body)
elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $rawInput = file_get_contents('php://input');
}

if ($rawInput) {
    // Try to decode even if it’s not Base64 
    $decoded = base64_decode($rawInput, true);

    if ($decoded !== false && strpos($decoded, 'node_name=') !== false) {
        
        parse_str($decoded, $decodedParams);
        // Overwrite or set variables from decoded message
        $node = $decodedParams['node_name'] ?? $node;
        $temp = $decodedParams['temperature'] ?? $temp;
        $hum  = $decodedParams['humidity'] ?? $hum;
        $time = $decodedParams['time_received'] ?? $time;
    }
}
/* --- END BASE64 DECODE SECTION --- */

    if ($node !== null && $temp !== null && $hum !== null) {
        header('Content-Type: application/json');

        if (!is_numeric($temp) || !is_numeric($hum)) {
            echo json_encode(['status'=>400,'message'=>'temperature and humidity must be numeric']); exit;
        }
        $temp = (float)$temp; $hum = (float)$hum;

        if ($temp < -10 || $temp > 100) { echo json_encode(['status'=>422,'message'=>'Temperature out of range (-10..100 °C)']); exit; }
        if ($hum  <   0 || $hum  > 100) { echo json_encode(['status'=>422,'message'=>'Humidity out of range (0..100 %)']); exit; }

        if ($time) {
            $dt = DateTime::createFromFormat('Y-m-d H:i:s', $time);
            if (!$dt || $dt->format('Y-m-d H:i:s') !== $time) {
                echo json_encode(['status'=>400,'message'=>'Invalid time_received format, use YYYY-MM-DD HH:MM:SS']); exit;
            }
        }

        try {
            $chk = $pdo->prepare("SELECT 1 FROM sensor_register WHERE node_name = ?");
            $chk->execute([$node]);
            if (!$chk->fetchColumn()) { echo json_encode(['status'=>409,'message'=>'Node is not registered']); exit; }

            if ($time) {
                $sql  = "INSERT INTO sensor_data (node_name, time_received, temperature, humidity)
                         VALUES (:n, :t, :c, :h)";
                $args = [':n'=>$node, ':t'=>$time, ':c'=>$temp, ':h'=>$hum];
            } else {
                $sql  = "INSERT INTO sensor_data (node_name, temperature, humidity)
                         VALUES (:n, :c, :h)";
                $args = [':n'=>$node, ':c'=>$temp, ':h'=>$hum];
            }

            $stmt = $pdo->prepare($sql);
            $stmt->execute($args);

            echo json_encode([
                'status'        => 200,
                'message'       => 'Inserted',
                'node_name'     => $node,
                'time_received' => $time ?: 'DEFAULT CURRENT_TIMESTAMP',
                'temperature'   => $temp,
                'humidity'      => $hum
            ]);
            exit;
        } catch (PDOException $e) {
            if (!empty($e->errorInfo[1]) && $e->errorInfo[1] == 1062) { echo json_encode(['status'=>409,'message'=>'Duplicate (node_name, time_received)']); exit; }
            if (!empty($e->errorInfo[1]) && $e->errorInfo[1] == 1452) { echo json_encode(['status'=>409,'message'=>'Node is not registered (FK violation)']); exit; }
            echo json_encode(['status'=>500,'message'=>'Database error: '.$e->getMessage()]); exit;
        }
    }
    /* --- end API MODE --- */

    // --- PAGE MODE: fetch and render tables ---
    $regStmt = $pdo->query("
        SELECT node_name, manufacturer, longitude, latitude
        FROM sensor_register
        ORDER BY node_name ASC
    ");
    $registered = $regStmt->fetchAll();

    $dataStmt = $pdo->query("
        SELECT node_name, time_received, temperature, humidity
        FROM sensor_data
        ORDER BY node_name ASC, time_received ASC
    ");
    $readings = $dataStmt->fetchAll();

    $avgNode = 'node_1';
    $avgStmt = $pdo->prepare("
        SELECT AVG(temperature) AS avg_c, AVG(humidity) AS avg_h
        FROM sensor_data
        WHERE node_name = :n
    ");
    $avgStmt->execute([':n' => $avgNode]);
    $avgRow = $avgStmt->fetch();
    $avgC = ($avgRow && $avgRow['avg_c'] !== null) ? (float)$avgRow['avg_c'] : null;
    $avgF = ($avgC !== null) ? (($avgC * 9/5) + 32) : null;
    $avgH = ($avgRow && $avgRow['avg_h'] !== null) ? (float)$avgRow['avg_h'] : null;

} catch (Throwable $e) {
    http_response_code(500);
    echo "<h1>Database Error</h1>";
    echo "<pre>" . h($e->getMessage()) . "</pre>";
    exit;
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<title>Welcome to SSU IoT Lab</title>
<meta name="viewport" content="width=device-width, initial-scale=1">
<style>
    :root { --accent:#a6c34c; --stripe:#f6f9ec; --border:#d6e3a9; --text:#222; --muted:#666; }
    body { font-family:system-ui,-apple-system,Segoe UI,Roboto,Helvetica,Arial,sans-serif; color:var(--text);
           margin:2rem auto; max-width:900px; line-height:1.4; padding:0 1rem; background:#fff; }
    h1 { text-align:center; font-size:2.2rem; margin-bottom:.5rem; }
    .subhead { text-align:center; color:var(--muted); margin-bottom:2rem; }
    .card { margin:1.5rem 0 2.5rem; border:1px solid var(--border); border-radius:10px; overflow:hidden;
            box-shadow:0 4px 10px rgba(0,0,0,.04); }
    .card h2 { margin:0; padding:.9rem 1rem; background:#f4f8e6; border-bottom:1px solid var(--border); font-size:1.15rem; }
    table { width:100%; border-collapse:collapse; table-layout:fixed; }
    thead th { background:var(--accent); color:#fff; text-align:left; padding:.6rem .7rem; font-weight:700; }
    tbody td { padding:.55rem .7rem; border-bottom:1px solid var(--border); vertical-align:top; word-wrap:break-word; }
    tbody tr:nth-child(even) td { background:var(--stripe); }
    .muted { color:var(--muted); }
    .empty { padding:.9rem 1rem; color:var(--muted); }
    .avg { margin-top: .5rem; }
</style>
</head>
<body>

<h1>Welcome to SSU IoT Lab</h1>
<p class="subhead">Registered Sensor Nodes &amp; Data Received</p>

<div class="card">
    <h2>Registered Sensor Nodes</h2>
    <?php if (empty($registered)): ?>
        <div class="empty">No registered sensors found.</div>
    <?php else: ?>
        <table aria-label="Registered Sensor Nodes">
            <thead>
            <tr>
                <th style="width:18%">Name</th>
                <th style="width:22%">Manufacturer</th>
                <th style="width:30%">Longitude</th>
                <th style="width:30%">Latitude</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($registered as $r): ?>
                <tr>
                    <td><?= h($r['node_name']) ?></td>
                    <td><?= h($r['manufacturer']) ?></td>
                    <td><?= is_null($r['longitude']) ? '<span class="muted">—</span>' : h($r['longitude']) ?></td>
                    <td><?= is_null($r['latitude'])  ? '<span class="muted">—</span>' : h($r['latitude'])  ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<div class="card">
    <h2>Data Received</h2>
    <?php if (empty($readings)): ?>
        <div class="empty">No sensor readings found.</div>
    <?php else: ?>
        <table aria-label="Sensor Data Readings">
            <thead>
            <tr>
                <th style="width:18%">Node</th>
                <th style="width:30%">Time</th>
                <th style="width:26%">Temperature (°C)</th>
                <th style="width:26%">Humidity (%)</th>
            </tr>
            </thead>
            <tbody>
            <?php foreach ($readings as $d): ?>
                <tr>
                    <td><?= h($d['node_name']) ?></td>
                    <td>
                        <?php
                        $out = $d['time_received'];
                        try { $out = (new DateTime($d['time_received']))->format('Y-m-d H:i:s'); } catch (Throwable $e) {}
                        echo h($out);
                        ?>
                    </td>
                    <td><?= h(number_format((float)$d['temperature'], 2)) ?></td>
                    <td><?= h(number_format((float)$d['humidity'], 2)) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>

        <div class="avg">
            <?php if ($avgC === null || $avgH === null): ?>
                <p class="muted">No data available yet for <?= h($avgNode) ?>.</p>
            <?php else: ?>
                <p>The Average Temperature for <?= h($avgNode) ?> has been:
                    <strong><?= h(number_format($avgF, 2)) ?> F</strong>
                    (<?= h(number_format($avgC, 2)) ?> °C)
                </p>
                <p>The Average Humidity for <?= h($avgNode) ?> has been:
                    <strong><?= h(number_format($avgH, 2)) ?> %</strong>
                </p>
            <?php endif; ?>
        </div>
    <?php endif; ?>
</div>

<!-- Embedded chart card -->
<div class="card">
  <h2>Sensor Node node_1 – Temperature Over Time</h2>
  <div style="padding:1rem;">
    <canvas id="tempChart" height="120"></canvas>
  </div>
</div>

<!-- Load libs AFTER the canvas, then your chart code -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script src="/Chartjs/app.js?v=6"></script>

</body>
</html>
