<?php
header('Content-Type: application/json');
echo json_encode([
    'success' => true,
    'message' => 'Real Estate CRM API is running.',
    'version' => '1.0'
]);
