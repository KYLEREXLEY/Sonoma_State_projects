<?php
header('Content-Type: application/json');

$dbHost = "localhost";
$dbName = "name";         
$dbUser = "user";       
$dbPass = "password";

try{
  $pdo=new PDO("mysql:host=$dbHost;dbname=$dbName;charset=utf8mb4",$dbUser,$dbPass,[
    PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE=>PDO::FETCH_ASSOC
  ]);
  $stmt=$pdo->query("
    SELECT DATE_FORMAT(time_received,'%Y-%m-%d %H:%i:%s') AS time_received, temperature
    FROM sensor_data WHERE node_name='node_2' ORDER BY time_received ASC
  ");
  echo json_encode($stmt->fetchAll());
}catch(Throwable $e){
  http_response_code(500);
  echo json_encode(['error'=>$e->getMessage()]);
}
