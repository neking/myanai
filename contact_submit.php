<?php
header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

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

// Sanitize
$name    = htmlspecialchars($name,    ENT_QUOTES, 'UTF-8');
$email   = htmlspecialchars($email,   ENT_QUOTES, 'UTF-8');
$phone   = htmlspecialchars($phone,   ENT_QUOTES, 'UTF-8');
$message = htmlspecialchars($message, ENT_QUOTES, 'UTF-8');

try {
    // Save to DB
    $pdo = new PDO(
        'mysql:host=localhost;port=3306;dbname=noodlehaus;charset=utf8mb4',
        'myanai_user','i0It2cUUSHiIbr3v1wZquVWOIZaHuudY',
        [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]
    );

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
