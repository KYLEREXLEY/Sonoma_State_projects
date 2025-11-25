<?php
// ---------- DB CONFIG ----------
$host = "localhost";   // Hostinger DB host/IP
$user = "";
$pass = "";
$db   = "";

$conn = new mysqli($host, $user, $pass, $db);
if ($conn->connect_error) {
    die("DB connection failed: " . $conn->connect_error);
}

$sql = "SELECT value, created_at FROM sensor_value ORDER BY created_at ASC LIMIT 500";
$result = $conn->query($sql);

$timestamps = [];
$values     = [];

if ($result) {
    while ($row = $result->fetch_assoc()) {
        $timestamps[] = $row['created_at'];
        $values[]     = (float)$row['value'];
    }
}
$conn->close();
?>
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>Potentiometer Data</title>
    <script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
    <style>
        body { font-family: Arial, sans-serif; margin: 20px; }
        #container { max-width: 900px; margin: auto; }
        canvas { width: 100%; height: 400px; }
    </style>
    <!-- Optional: auto-refresh every 10 seconds -->
    <meta http-equiv="refresh" content="10">
</head>
<body>
<div id="container">
    <h1>Potentiometer Readings</h1>
    <p>Topic: <code>testtopic/temp/outTopic/kyler</code></p>
    <canvas id="potChart"></canvas>
</div>

<script>
const labels = <?php echo json_encode($timestamps); ?>;
const dataValues = <?php echo json_encode($values); ?>;

const ctx = document.getElementById('potChart').getContext('2d');
const potChart = new Chart(ctx, {
    type: 'line',
    data: {
        labels: labels,
        datasets: [{
            label: 'Potentiometer value',
            data: dataValues,
            fill: false
        }]
    },
    options: {
        responsive: true,
        scales: {
            x: {
                title: { display: true, text: 'Time' }
            },
            y: {
                title: { display: true, text: 'ADC value (0–1023)' }
            }
        }
    }
});
</script>
</body>
</html>
