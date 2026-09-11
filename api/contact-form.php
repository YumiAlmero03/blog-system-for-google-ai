<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/support-api.php';
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    support_json(['ok'=>false,'error'=>'Method not allowed.'],405);
}
try {
    support_rate('contact',3);
    $id = support_contact_save(support_input());
    support_contact_notify($id);
    support_json(['ok'=>true,'message'=>'Your message has been received.'],201);
} catch (Throwable $error) { support_api_error($error); }
