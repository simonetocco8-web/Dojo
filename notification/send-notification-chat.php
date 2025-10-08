<?php

require_once '/home/bwlxtuul/dojo.villaggiotramonto.it/core/auth.php';
require_once '/home/bwlxtuul/dojo.villaggiotramonto.it/core/security.php';
require_once '/home/bwlxtuul/dojo.villaggiotramonto.it/core/db.php';

start_session();
$env   = '/home/bwlxtuul/dojo.villaggiotramonto.it/config/env.php';
$base  = rtrim($env['app']['base_url'] ?? '', '/');
$pdo   = db();
$user  = current_user();

if (!$user && strcmp($token,'cmnsdbf2g5wsnbsbcHcnsdc')!== 0){
    
         header('Location: ' . $base . '/index.php?msg=auth'.$token); exit;
   
 }

function sendmessage($num,$msg){

    $params=array(
    'token' => 'cklktrew81apilr9',
    'to' => $num,
    'body' => $msg
    );
    
    
    $curl = curl_init();
    curl_setopt_array($curl, array(
      CURLOPT_URL => "https://api.ultramsg.com/instance143422/messages/chat",
      CURLOPT_RETURNTRANSFER => true,
      CURLOPT_ENCODING => "",
      CURLOPT_MAXREDIRS => 10,
      CURLOPT_TIMEOUT => 30,
      CURLOPT_SSL_VERIFYHOST => 0,
      CURLOPT_SSL_VERIFYPEER => 0,
      CURLOPT_HTTP_VERSION => CURL_HTTP_VERSION_1_1,
      CURLOPT_CUSTOMREQUEST => "POST",
      CURLOPT_POSTFIELDS => http_build_query($params),
      CURLOPT_HTTPHEADER => array(
        "content-type: application/x-www-form-urlencoded"
      ),
    ));
    
    $response = curl_exec($curl);
    $err = curl_error($curl);
    
    curl_close($curl);
    
    if ($err) {
      echo "cURL Error #:" . $err;
    } else {
      echo $response;
    }

}



function notify($titolo,$dipartimento,$data,$priority,$description){
    
    if(isToday($data)){
        $destinatari = getTelefoniByDipartimento($dipartimento);
  
        
        foreach($destinatari as $destinatario){

            switch ($priority) {
                case "bassa":
                    $messaggio="❗ ❗".$titolo." - ".$description;
                    break;
                case "media":
                    $messaggio="❗ ❗❗- ".$titolo." - ".$description;
                    break;
                case "alta":
                    $messaggio="❗❗❗ - ".$titolo." - ".$description;
                    break;
                case "urgente":
                    $messaggio="❗❗❗❗ - ".$titolo." - ".$description;
                    break;
            }
            sendmessage($destinatario,$messaggio);
        }
    }
    
    
}



function isToday(string $date): bool {
    // Controllo formato valido (AAAA-MM-DD)
    $d = DateTime::createFromFormat('Y-m-d', $date);
    if (!$d || $d->format('Y-m-d') !== $date) {
        return false; // Formato non valido
    }

    // Data odierna nel formato YYYY-MM-DD
    $today = date('Y-m-d');

    return $date === $today;
}




function getTelefoniByDipartimento(string $dipartimento): array {
    try {
        $sql = "SELECT telefono FROM users WHERE dipartimento = :dipartimento";
        $pdo   = db();
        $stmt = $pdo->prepare($sql);
        $stmt->bindParam(':dipartimento', $dipartimento, PDO::PARAM_STR);
        $stmt->execute();

        // Estrae tutti i numeri di telefono come array semplice
        $telefoni = $stmt->fetchAll(PDO::FETCH_COLUMN);

        return $telefoni ?: []; // restituisce array vuoto se nessun risultato
    } catch (PDOException $e) {
        error_log("Errore DB: " . $e->getMessage());
        return []; // fallback in caso di errore
    }
}

