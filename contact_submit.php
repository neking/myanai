<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');
require_once __DIR__ . '/db_connect.php';

// Only POST
if($_SERVER['REQUEST_METHOD'] !== 'POST'){
    echo json_encode(['ok'=>false,'message'=>'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
$name    = trim($input['name']    ?? '');
$email   = trim($input['email']   ?? '');
$phone   = trim($input['phone']   ?? '');
$message = trim($input['message'] ?? '');

// Validate
if(!$name || !$email || !$message){
    echo json_encode(['ok'=>false,'message'=>'Required fields missing']);
    exit;
}
if(!filter_var($email, FILTER_VALIDATE_EMAIL)){
    echo json_encode(['ok'=>false,'message'=>'Invalid email']);
    exit;
}

// NOTE: no htmlspecialchars() here — these values are stored as-is (plain
// text) and used in a plain-text email body below. Escaping is an
// output-context concern, not a storage concern; escaping before storage
// meant any apostrophe/quote in a submission (e.g. "I'd like to ask...")
// showed up as literal HTML entities (I&#039;d like...) in the notification
// email, which looked broken to a human reading it. If these values are ever
// rendered in an HTML admin view later, escape them there at render time.

try {
    // Save to DB — uses the shared, environment-configured connection
    // (db_connect.php) instead of a duplicate hardcoded password. That
    // password was already found committed in webhook.php earlier this
    // session; it was actually duplicated here too, reinforcing how
    // important rotating it is (code fixes don't erase git history).
    $pdo = getPDO();

    // Create table if not exists
    $pdo->exec("CREATE TABLE IF NOT EXISTS contact_submissions (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(120) NOT NULL,
        email VARCHAR(200) NOT NULL,
        phone VARCHAR(50),
        message TEXT NOT NULL,
        ip VARCHAR(45),
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) CHARACTER SET utf8mb4");

    $stmt = $pdo->prepare("INSERT INTO contact_submissions (name,email,phone,message,ip) VALUES (?,?,?,?,?)");
    $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? '';
    $stmt->execute([$name,$email,$phone,$message,$ip]);

    // Send email notification (if mail configured)
    $to      = 'hello@myanai.net';
    $subject = "MyanAi Contact: $name";
    $body    = "Name: $name\nEmail: $email\nPhone: $phone\n\nMessage:\n$message";
    $headers = "From: noreply@myanai.net\r\nReply-To: $email";
    @mail($to, $subject, $body, $headers);

    echo json_encode(['ok'=>true,'message'=>'Sent successfully']);

} catch(Exception $e){
    error_log('contact_submit error: '.$e->getMessage());
    echo json_encode(['ok'=>false,'message'=>'Server error']);
}
