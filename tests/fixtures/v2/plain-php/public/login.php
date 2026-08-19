<?php

require_once __DIR__ . '/../src/AuthService.php';

$service = new Fixture\AuthService();
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $service->login((string) ($_POST['email'] ?? ''));
}
