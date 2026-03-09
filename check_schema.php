<?php
require_once __DIR__ . '/config/config.php';
require_once __DIR__ . '/config/database.php';

try {
    $stmt = $dcmt_pdo->query('DESCRIBE dcmt_cashflows');
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($columns as $col) {
        echo $col['Field'] . ' | ' . $col['Type'] . ' | ' . $col['Null'] . ' | ' . $col['Default'] . "\n";
    }
} catch (PDOException $e) {
    echo "Error: " . $e->getMessage();
}
