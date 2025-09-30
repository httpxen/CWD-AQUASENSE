<?php
// config/email.php
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

require_once __DIR__ . '/../config/env.php';

class EmailService {
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
    
    public function sendPasswordReset($to, $username, $resetToken) {
        try {
            // Generate reset link
            $resetLink = "http://localhost:8000/reset_password.php?token=" . $resetToken;
            
            $this->mail->clearAddresses();
            $this->mail->addAddress($to, $username);
            
            $this->mail->Subject = 'Password Reset Request - CWD AquaSense';
            
            $emailTemplate = $this->getPasswordResetTemplate($username, $resetLink);
            
            $this->mail->Body = $emailTemplate;
            $this->mail->AltBody = "Password Reset Request\n\nHello $username,\n\nYou requested a password reset for your CWD AquaSense account.\n\nPlease click the following link to reset your password:\n$resetLink\n\nThis link will expire in 1 hour. If you didn't request this, please contact support@calambawd.gov.ph.\n\nBest regards,\nCWD AquaSense Team";
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Email could not be sent. Mailer Error: {$this->mail->ErrorInfo}");
            return false;
        }
    }
    
    public function sendWelcomeEmail($to, $username) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($to, $username);
            
            $this->mail->Subject = 'Welcome to CWD AquaSense - Your Account Has Been Created!';
            
            $loginLink = "http://localhost:8000/login.php";
            $emailTemplate = $this->getWelcomeTemplate($username, $loginLink);
            
            $this->mail->Body = $emailTemplate;
            $this->mail->AltBody = "Welcome to CWD AquaSense!\n\nHello $username,\n\nYour account has been successfully created. You can now log in to the AquaSense Management System using your username and password.\n\nLogin here: $loginLink\n\nIf you have any questions, please contact our support team at support@calambawd.gov.ph.\n\nBest regards,\nCWD AquaSense Team";
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Welcome email could not be sent. Mailer Error: {$this->mail->ErrorInfo}");
            return false;
        }
    }
    
    private function getPasswordResetTemplate($username, $resetLink) {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Password Reset - CWD AquaSense</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; line-height: 1.5; color: #1F2937; background-color: #F3F4F6; }
        .container { max-width: 600px; margin: 20px auto; background: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #1E40AF; color: #FFFFFF; padding: 24px; text-align: center; }
        .header img { max-width: 48px; height: auto; margin-bottom: 12px; }
        .header h1 { font-size: 24px; font-weight: 600; margin: 0; }
        .content { padding: 32px; }
        .content h2 { font-size: 20px; font-weight: 600; margin-bottom: 16px; }
        .content p { margin-bottom: 16px; font-size: 16px; color: #4B5563; }
        .button {
            display: inline-block;
            padding: 14px 28px;
            background: #2563EB;
            color: #FFFFFF !important;
            text-decoration: none !important;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 0.3px;
            line-height: 1;
            box-shadow: 0 6px 14px rgba(37, 99, 235, 0.25);
            border: 0;
        }
        .button:hover { background: #1D4ED8; }
        .highlight {
            background: #EFF6FF;
            padding: 16px;
            border-radius: 8px;
            margin: 16px 0;
            border-left: 5px solid #2563EB;
            color: #1E3A8A;
        }
        .footer { background: #F9FAFB; padding: 24px; text-align: center; font-size: 14px; color: #6B7280; }
        .footer a { color: #1E40AF; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        @media (max-width: 600px) {
            .container { margin: 10px; }
            .content { padding: 20px; }
            .header { padding: 20px; }
            .header h1 { font-size: 20px; }
            .button { padding: 10px 20px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://scontent.fmnl30-3.fna.fbcdn.net/v/t39.30808-6/307024352_563812002211278_7537666014122218120_n.png?_nc_cat=105&ccb=1-7&_nc_sid=6ee11a&_nc_eui2=AeG74VMVxb-J_ykTIn9PFr0ewU04b9Q9_AjBTThv1D38CHFCIQydzoMTuJKEVpjtKh0_PyAhx8YOE4JqFxhFCBnd&_nc_ohc=iYzqBdH1hmIQ7kNvwFH7DJK&_nc_oc=AdkfNr8rJ6I8FEe5rYVE8bcmsVNG_Q1vvHbUkvr6h_2Gidrx9kw5GUs4fC5I-BxvEHI&_nc_zt=23&_nc_ht=scontent.fmnl30-3.fna&_nc_gid=w5PcwQTvGzD9DdMhoZ9Qqg&oh=00_AfbdyuI73IArAQIEZwxS-s3152O7JBNeDoNFGntEnM20Bw&oe=68D590D8" alt="CWD AquaSense Logo">
            <h1>Password Reset Request</h1>
        </div>
        <div class="content">
            <h2>Hello $username,</h2>
            <p>We received a request to reset your password for your CWD AquaSense account.</p>
            <div class="highlight">
                <p><strong>Need a new password?</strong> Click the button below to securely reset your password.</p>
            </div>
            <div style="text-align: center; margin: 24px 0;">
                <a href="$resetLink" class="button">Reset Password</a>
            </div>
            <p><small>This link will expire in 1 hour for security reasons.</small></p>
            <p>If you did not request a password reset, please ignore this email or contact our support team at <a href="mailto:support@calambawd.gov.ph">support@calambawd.gov.ph</a>.</p>
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

    private function getWelcomeTemplate($username, $loginLink) {
        return <<<HTML
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Welcome - CWD AquaSense</title>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body { font-family: 'Helvetica Neue', Arial, sans-serif; line-height: 1.5; color: #1F2937; background-color: #F3F4F6; }
        .container { max-width: 600px; margin: 20px auto; background: #FFFFFF; border-radius: 8px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.1); }
        .header { background: #1E40AF; color: #FFFFFF; padding: 24px; text-align: center; }
        .header img { max-width: 48px; height: auto; margin-bottom: 12px; }
        .header h1 { font-size: 24px; font-weight: 600; margin: 0; }
        .content { padding: 32px; }
        .content h2 { font-size: 20px; font-weight: 600; margin-bottom: 16px; }
        .content p { margin-bottom: 16px; font-size: 16px; color: #4B5563; }
        .button {
            display: inline-block;
            padding: 14px 28px;
            background: #10B981;
            color: #FFFFFF !important;
            text-decoration: none !important;
            border-radius: 10px;
            font-weight: 700;
            font-size: 16px;
            letter-spacing: 0.3px;
            line-height: 1;
            box-shadow: 0 6px 14px rgba(16, 185, 129, 0.25);
            border: 0;
        }
        .button:hover { background: #059669; }
        .highlight {
            background: #F0FDF4;
            padding: 16px;
            border-radius: 8px;
            margin: 16px 0;
            border-left: 5px solid #10B981;
            color: #065F46;
        }
        .footer { background: #F9FAFB; padding: 24px; text-align: center; font-size: 14px; color: #6B7280; }
        .footer a { color: #1E40AF; text-decoration: none; }
        .footer a:hover { text-decoration: underline; }
        @media (max-width: 600px) {
            .container { margin: 10px; }
            .content { padding: 20px; }
            .header { padding: 20px; }
            .header h1 { font-size: 20px; }
            .button { padding: 10px 20px; font-size: 14px; }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <img src="https://scontent.fmnl30-3.fna.fbcdn.net/v/t39.30808-6/307024352_563812002211278_7537666014122218120_n.png?_nc_cat=105&ccb=1-7&_nc_sid=6ee11a&_nc_eui2=AeG74VMVxb-J_ykTIn9PFr0ewU04b9Q9_AjBTThv1D38CHFCIQydzoMTuJKEVpjtKh0_PyAhx8YOE4JqFxhFCBnd&_nc_ohc=iYzqBdH1hmIQ7kNvwFH7DJK&_nc_oc=AdkfNr8rJ6I8FEe5rYVE8bcmsVNG_Q1vvHbUkvr6h_2Gidrx9kw5GUs4fC5I-BxvEHI&_nc_zt=23&_nc_ht=scontent.fmnl30-3.fna&_nc_gid=w5PcwQTvGzD9DdMhoZ9Qqg&oh=00_AfbdyuI73IArAQIEZwxS-s3152O7JBNeDoNFGntEnM20Bw&oe=68D590D8" alt="CWD AquaSense Logo">
            <h1>Welcome to AquaSense!</h1>
        </div>
        <div class="content">
            <h2>Hello $username,</h2>
            <p>Thank you for registering with Calamba Water District AquaSense Management System.</p>
            <div class="highlight">
                <p><strong>Your account is ready!</strong> You can now log in and start using the platform to submit complaints, provide feedback, and more.</p>
            </div>
            <div style="text-align: center; margin: 24px 0;">
                <a href="$loginLink" class="button">Sign In Now</a>
            </div>
            <p>If you have any questions or need assistance, please contact our support team at <a href="mailto:support@calambawd.gov.ph">support@calambawd.gov.ph</a>.</p>
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
}
?>