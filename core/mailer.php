<?php
// core/mailer.php
function send_mail(string $to, string $subject, string $html): bool {
  $env = require __DIR__ . '/../config/env.php';
  $from = $env['mail']['from'] ?? 'no-reply@example.com';
  $from_name = $env['mail']['from_name'] ?? 'Admin';
  $headers = "MIME-Version: 1.0\r\n";
  $headers .= "Content-type:text/html;charset=UTF-8\r\n";
  $headers .= "From: " . sprintf('%s <%s>', $from_name, $from) . "\r\n";
  $ok = @mail($to, $subject, $html, $headers);
  if (!$ok) {
    $log = __DIR__ . '/../storage/mail.log';
    if (!is_dir(dirname($log))) @mkdir(dirname($log), 0775, true);
    file_put_contents($log, "[".date('c')."] TO:$to\nSUBJECT:$subject\n$html\n\n", FILE_APPEND);
    return true;
  }
  return true;
}
