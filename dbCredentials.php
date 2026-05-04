<?php
$host     = "";
$database = "";
$user     = "";
$password = "";
$servertyp = "";

try {
    $dsn = "$servertyp:Server=$host;Database=$database";
    
    $DB = new PDO($dsn, $user, $password);

    $DB->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

} catch (PDOException $e) {
    throw new JobRouterException("Failed to connect: " . $e->getMessage());
}
?>