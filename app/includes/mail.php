<?php
/**
 * mail.php
 *
 * Sends email through the SMTP server in .env (SMTP_*), using PHPMailer.
 */

use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\Exception as MailException;

require_once __DIR__ . '/../vendor/autoload.php';

/** Why email can't be sent yet, or null if the SMTP settings look complete. */
function mail_problem() {
    if (SMTP_HOST === '') return 'SMTP_HOST is not set in .env.';
    if (MAIL_FROM === '') return 'Set MAIL_FROM (or SMTP_USER) in .env.';
    if (!in_array(SMTP_SECURITY, ['starttls', 'ssl', 'none'], true)) return 'SMTP_SECURITY must be starttls, ssl or none.';
    return null;
}

/**
 * Sends one HTML email (with a plain-text version) to every address in $recipients,
 * as BCC so they don't see each other. Throws RuntimeException if it fails.
 */
function mail_send(array $recipients, $subject, $html, $text) {
    if ($problem = mail_problem()) {
        throw new RuntimeException($problem);
    }
    if (!$recipients) {
        throw new RuntimeException('No recipients.');
    }
    $mail = new PHPMailer(true);
    try {
        $mail->isSMTP();
        $mail->Host = SMTP_HOST;
        $mail->Port = SMTP_PORT;
        $mail->Timeout = 30;
        if (SMTP_USER !== '') {
            $mail->SMTPAuth = true;
            $mail->Username = SMTP_USER;
            $mail->Password = SMTP_PASS;
        }
        if (SMTP_SECURITY === 'ssl') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS;
        } elseif (SMTP_SECURITY === 'starttls') {
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
        } else {
            $mail->SMTPSecure = '';
            $mail->SMTPAutoTLS = false;
        }
        $mail->CharSet = PHPMailer::CHARSET_UTF8;
        $mail->setFrom(MAIL_FROM, MAIL_FROM_NAME);
        foreach ($recipients as $address) {
            if (!$mail->addBCC($address)) {
                throw new RuntimeException("Not a valid email address: {$address}");
            }
        }
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body = $html;
        $mail->AltBody = $text;
        $mail->send();
    } catch (MailException $e) {
        throw new RuntimeException('Email failed: ' . $mail->ErrorInfo, 0, $e);
    }
}
