<?php
// config/admin_notification.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/env.php';

class AdminNotification {
    private $mail;
    
    public function __construct() {
        date_default_timezone_set('Asia/Manila');
        
        $this->mail = new PHPMailer(true);
        $this->mail->isSMTP();
        $this->mail->Host       = $_ENV['SMTP_HOST'];
        $this->mail->SMTPAuth   = true;
        $this->mail->Username   = $_ENV['SMTP_USERNAME'];
        $this->mail->Password   = $_ENV['SMTP_PASSWORD'];
        $this->mail->SMTPSecure = $_ENV['SMTP_ENCRYPTION'];
        $this->mail->Port       = $_ENV['SMTP_PORT'];
        
        $this->mail->setFrom($_ENV['MAIL_FROM_ADDRESS'], $_ENV['MAIL_FROM_NAME']);
        $this->mail->addReplyTo($_ENV['MAIL_REPLYTO'], $_ENV['MAIL_REPLYTO_NAME']);
        
        $this->mail->isHTML(true);
        $this->mail->CharSet = 'UTF-8';
    }
    
    public function sendRegistrationAlert($userDetails) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($_ENV['ADMIN_EMAIL'], 'CWD AquaSense Admin');
            
            $this->mail->Subject = 'New User Registration Alert: ' . $userDetails['username'];
            
            $emailTemplate = $this->getRegistrationAlertTemplate($userDetails);
            
            $this->mail->Body = $emailTemplate;
            $this->mail->AltBody = $this->getRegistrationAlertPlainText($userDetails);
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Registration alert email could not be sent. Mailer Error: {$this->mail->ErrorInfo}");
            return false;
        }
    }

    
    private function getRegistrationAlertTemplate($userDetails) {
        $fullName = trim($userDetails['first_name'] . ' ' . ($userDetails['middle_name'] ?? '') . ' ' . $userDetails['last_name']);
        $fullName = trim($fullName);
        $regTimestamp = date('Y-m-d h:i A');  // 12-hour format without seconds
        
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>New User Registration - CWD AquaSense</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; line-height: 1.5; color: #1F2937; background-color: #F3F4F6; }
        .container { max-width: 600px; margin: 20px auto; background: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #DC2626; color: #FFFFFF; padding: 24px; text-align: center; }
        .header img { max-width: 48px; height: auto; margin-bottom: 12px; }
        .header h1 { font-size: 24px; font-weight: 600; margin: 0; }
        .content { padding: 32px; }
        .content h2 { font-size: 20px; font-weight: 600; margin-bottom: 16px; }
        .content p { margin-bottom: 16px; font-size: 16px; color: #4B5563; }
        .user-info { background: #FEF2F2; padding: 20px; border-radius: 8px; margin: 20px 0; border-left: 5px solid #DC2626; }
        .user-info table { width: 100%; border-collapse: collapse; }
        .user-info th, .user-info td { padding: 8px 12px; text-align: left; border-bottom: 1px solid #FECACA; }
        .user-info th { font-weight: 600; color: #991B1B; }
        .highlight {
            background: #FEF3C7;
            padding: 16px;
            border-radius: 8px;
            margin: 16px 0;
            border-left: 5px solid #D97706;
            color: #92400E;
        }
        .footer { background: #F9FAFB; padding: 24px; text-align: center; font-size: 14px; color: #6B7280; }
        .footer a { color: #1E40AF; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        @media (max-width: 600px) {
            .container { margin: 10px; }
            .content { padding: 20px; }
            .header { padding: 20px; }
            .header h1 { font-size: 20px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://scontent.fmnl30-3.fna.fbcdn.net/v/t39.30808-6/307024352_563812002211278_7537666014122218120_n.png?_nc_cat=105&ccb=1-7&_nc_sid=6ee11a&_nc_eui2=AeG74VMVxb-J_ykTIn9PFr0ewU04b9Q9_AjBTThv1D38CHFCIQydzoMTuJKEVpjtKh0_PyAhx8YOE4JqFxhFCBnd&_nc_ohc=iYzqBdH1hmIQ7kNvwFH7DJK&_nc_oc=AdkfNr8rJ6I8FEe5rYVE8bcmsVNG_Q1vvHbUkvr6h_2Gidrx9kw5GUs4fC5I-BxvEHI&_nc_zt=23&_nc_ht=scontent.fmnl30-3.fna&_nc_gid=w5PcwQTvGzD9DdMhoZ9Qqg&oh=00_AfbdyuI73IArAQIEZwxS-s3152O7JBNeDoNFGntEnM20Bw&oe=68D590D8" alt="CWD AquaSense Logo">
            <h1>New User Registration Alert</h1>
        </div>
        <div class="content">
            <h2>A new account has been created on CWD AquaSense.</h2>
            <p>Please review the details below for verification or any necessary actions.</p>
            <div class="user-info">
                <table>
                    <tr><th>Username</th><td>{$userDetails['username']}</td></tr>
                    <tr><th>Full Name</th><td>{$fullName}</td></tr>
                    <tr><th>Email</th><td>{$userDetails['email']}</td></tr>
                    <tr><th>Registration IP</th><td>{$userDetails['ip']}</td></tr>
                    <tr><th>Registration Date</th><td>{$regTimestamp}</td></tr>
                </table>
            </div>
            <div class="highlight">
                <p><strong>Action Required:</strong> Monitor for any suspicious activity. If needed, contact the user or take appropriate measures.</p>
            </div>
        </div>
        <div class="footer">
            <p><strong>Need Assistance?</strong> Reach out to Calamba Water District Support<br>
                <a href="mailto:calambawaterdistrict@yahoo.com">calambawaterdistrict@yahoo.com</a> | (049) 545-2863
            </p>
            <p>© 2025 Calamba Water District. All rights reserved.</p>
        </div>
    </div>
</body>
</html>
HTML;
    }
    
    private function getRegistrationAlertPlainText($userDetails) {
        $fullName = trim($userDetails['first_name'] . ' ' . ($userDetails['middle_name'] ?? '') . ' ' . $userDetails['last_name']);
        $fullName = trim($fullName);
        $regTimestamp = date('d F Y h:i A');  // 12-hour format without seconds
        
        return "New User Registration Alert\n\nA new account has been created on CWD AquaSense.\n\nDetails:\n- Username: {$userDetails['username']}\n- Full Name: {$fullName}\n- Email: {$userDetails['email']}\n- Registration IP: {$userDetails['ip']}\n- Registration Date: {$regTimestamp}\n\nPlease review for verification.\n\nBest regards,\nCWD AquaSense Team";
    }
}
?>