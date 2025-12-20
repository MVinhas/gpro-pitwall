<?php

declare(strict_types=1);

namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    public function __construct(private readonly array $config)
    {
        foreach (['host', 'port', 'from', 'from_name'] as $key) {
            if (!array_key_exists($key, $this->config)) {
                throw new \InvalidArgumentException("Missing mail config key: {$key}");
            }
        }
    }

    public function sendVerificationCode(string $toEmail, string $code): bool
    {
        $subject = "{$code} is your verification code";
        $body = $this->renderHtml("Your verification code is:", $code, "This code expires in 10 minutes.");
        $altBody = "Your verification code is: {$code}";

        return $this->send($toEmail, $subject, $body, $altBody);
    }

    public function sendWelcomeEmail(string $toEmail, string $username): bool
    {
        $subject = "Welcome to GPRO Assistant!";
        $body = $this->renderHtml(
            "Welcome, {$username}!",
            "Account Verified",
            "Your account is now active. You can log in anytime using your username."
        );
        $altBody = "Welcome {$username}! Your account is now active.";

        return $this->send($toEmail, $subject, $body, $altBody);
    }

    private function createMailer(): PHPMailer
    {
        $mail = new PHPMailer(true);
        $mail->isSMTP();
        $mail->Host = $this->config['host'];
        $mail->Port = $this->config['port'];
        $mail->CharSet = 'UTF-8';

        if (!empty($this->config['user'])) {
            $mail->SMTPAuth = true;
            $mail->Username = $this->config['user'];
            $mail->Password = $this->config['pass'];
        } else {
            $mail->SMTPAuth = false;
        }

        if (!empty($this->config['encryption']) && $this->config['encryption'] !== 'null') {
            $mail->SMTPSecure = $this->config['encryption'];
        } else {
            $mail->SMTPAutoTLS = false;
        }

        $mail->setFrom($this->config['from'], $this->config['from_name']);

        return $mail;
    }


    private function send(string $to, string $subject, string $body, string $alt): bool
    {
        $mail = $this->createMailer();

        try {
            $mail->addAddress($to);
            $mail->isHTML(true);

            $mail->Subject = $subject;
            $mail->Body    = $body;
            $mail->AltBody = $alt;

            return $mail->send();
        } catch (Exception) {
            error_log('[EmailService Error] ' . $mail->ErrorInfo);
            return false;
        }
    }


    private function renderHtml(string $title, string $highlight, string $footer): string
    {
        return <<<HTML
<!DOCTYPE html>
<html>
<head>
    <style>
        body { font-family: -apple-system, sans-serif; background-color: #f4f4f5; margin: 0; padding: 0; }
        .container {
             max-width: 600px; 
             margin: 40px auto; 
             background: #fff; 
             padding: 40px 20px; 
             border-radius: 8px; 
             box-shadow: 0 4px 6px rgba(0,0,0,0.05); 
        }
        .header { text-align: center; margin-bottom: 30px; font-weight: bold; color: #0284c7; font-size: 24px; }
        .code-box { 
            background: #f0f9ff; 
            border: 1px solid #bae6fd; 
            border-radius: 6px; 
            padding: 20px; 
            text-align: center;
            margin: 30px 0; 
        }
        .code { font-family: monospace; font-size: 24px; font-weight: bold; color: #0284c7; }
        .text { color: #374151; line-height: 1.6; text-align: center; }
        .footer { margin-top: 40px; text-align: center; font-size: 12px; color: #9ca3af; }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">GPRO Assistant</div>
        <div class="text">{$title}</div>
        <div class="code-box"><span class="code">{$highlight}</span></div>
        <div class="text">{$footer}</div>
        <div class="footer">&copy; 2025 GPRO Assistant</div>
    </div>
</body>
</html>
HTML;
    }
}
