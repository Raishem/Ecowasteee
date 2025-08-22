<?php
require_once 'config.php';

function sendPasswordResetEmail($email, $token) {
    $resetLink = BASE_URL . "/reset_password.php?token=" . urlencode($token);
    $subject = "EcoWaste Password Reset";
    
    $message = <<<EMAIL
    <html>
    <body>
        <h2>Password Reset Request</h2>
        <p>Click this link to reset your password:</p>
        <a href="$resetLink">$resetLink</a>
        <p>This link expires in 1 hour.</p>
    </body>
    </html>
    EMAIL;
    
    $headers = [
        'MIME-Version: 1.0',
        'Content-type: text/html; charset=utf-8',
        'From: ' . SMTP_FROM,
        'Reply-To: ' . SMTP_FROM
    ];
    
    // Using PHPMailer (recommended)
    if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
        $mail = new PHPMailer\PHPMailer\PHPMailer(true);
        
        try {
            $mail->isSMTP();
            $mail->Host = SMTP_HOST;
            $mail->Port = SMTP_PORT;
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
            $mail->SMTPSecure = SMTP_SECURE;
            
            $mail->setFrom(SMTP_FROM, SITE_NAME);
            $mail->addAddress($email);
            $mail->isHTML(true);
            $mail->Subject = $subject;
            $mail->Body = $message;
            
            return $mail->send();
        } catch (Exception $e) {
            error_log("Mailer Error: " . $mail->ErrorInfo);
            return false;
        }
    } 
    // Fallback to basic mail()
    else {
        return mail($email, $subject, $message, implode("\r\n", $headers));
    }
}