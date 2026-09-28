<?php

require_once __DIR__ . '/../core/product_notifications.php';

$jsonDepartments = product_department_names('["Bar","Resp. Bar"]');
$listDepartments = product_department_names('Bar, Resp. Bar');
if (!in_array('Resp. Bar', $jsonDepartments, true) || !in_array('Resp. Bar', $listDepartments, true)) {
    throw new RuntimeException('Il reparto Resp. Bar non viene riconosciuto.');
}

$message = product_deactivation_message(str_repeat('Prodotto molto lungo ', 20));
if (sms_utf8_length(sms_gsm7_sanitize($message)) > 160) {
    throw new RuntimeException('Il messaggio supera il limite di 160 caratteri.');
}

echo "Destinatari e messaggio disattivazione prodotto verificati.\n";
