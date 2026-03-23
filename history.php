<?php
/**
 * history.php — Returns recent resume check history as JSON
 */

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

require_once 'config.php';
require_once 'db.php';

try {
    $db   = getDB();
    $stmt = $db->query("
        SELECT
            filename,
            score,
            keywords_found,
            DATE_FORMAT(created_at, '%d %b %Y, %h:%i %p') AS created_at
        FROM resume_checks
        ORDER BY created_at DESC
        LIMIT 20
    ");
    echo json_encode($stmt->fetchAll());
} catch (Exception $e) {
    echo json_encode([]);
}
