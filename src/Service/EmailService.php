<?php

declare(strict_types=1);

namespace App\Service;

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception;

class EmailService
{
    /** @param array<string, mixed> $config */
    public function __construct(private readonly array $config)
    {
        foreach (['host', 'port', 'from', 'from_name'] as $key) {
            if (!array_key_exists($key, $this->config)) {
                throw new \InvalidArgumentException("Missing mail config key: {$key}");
            }
        }
    }

    private function isDev(): bool
    {
        return !empty($this->config['is_dev']);
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
        $subject = "Welcome to GPRO Pitwall!";
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
        if ($this->isDev()) {
            return $this->writeDevEml($to, $subject, $body, $alt);
        }

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

    /**
     * Writes an RFC 5322 .eml file to var/mail/ instead of hitting SMTP.
     * Used in dev so the verification code is readable without external infra.
     * Filename: {YYYYmmddHHiiss}-{to-slug}.eml — sortable and human-scannable.
     */
    private function writeDevEml(string $to, string $subject, string $htmlBody, string $altBody): bool
    {
        $dir = $this->config['mail_dir'] ?? 'var/mail';
        if (!is_dir($dir) && !mkdir($dir, 0750, true) && !is_dir($dir)) {
            error_log('[EmailService dev] cannot create ' . $dir);
            return false;
        }

        $boundary = bin2hex(random_bytes(8));
        $fromAddr = $this->config['from'];
        $fromName = $this->config['from_name'];
        $date = date('r');

        $eml = "From: {$fromName} <{$fromAddr}>\r\n"
             . "To: {$to}\r\n"
             . "Subject: {$subject}\r\n"
             . "Date: {$date}\r\n"
             . "MIME-Version: 1.0\r\n"
             . "Content-Type: multipart/alternative; boundary=\"{$boundary}\"\r\n"
             . "\r\n"
             . "--{$boundary}\r\n"
             . "Content-Type: text/plain; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "\r\n"
             . $altBody . "\r\n"
             . "\r\n"
             . "--{$boundary}\r\n"
             . "Content-Type: text/html; charset=UTF-8\r\n"
             . "Content-Transfer-Encoding: 8bit\r\n"
             . "\r\n"
             . $htmlBody . "\r\n"
             . "\r\n"
             . "--{$boundary}--\r\n";

        $slug = preg_replace('/[^A-Za-z0-9._@-]+/', '_', $to) ?? 'recipient';
        $path = $dir . '/' . date('YmdHis') . '-' . $slug . '.eml';

        return file_put_contents($path, $eml) !== false;
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
        <div class="header">GPRO Pitwall</div>
        <div class="text">{$title}</div>
        <div class="code-box"><span class="code">{$highlight}</span></div>
        <div class="text">{$footer}</div>
        <div class="footer">&copy; 2026 GPRO Pitwall · <a href="https://gpro-pitwall.com">gpro-pitwall.com</a></div>
    </div>
</body>
</html>
HTML;
    }
}
