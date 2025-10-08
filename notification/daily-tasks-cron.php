#!/usr/bin/php
<?php
//declare(strict_types=1);
require_once  '/home/bwlxtuul/dojo.villaggiotramonto.it/core/db.php';
require_once  '/home/bwlxtuul/dojo.villaggiotramonto.it/config/env.php';
$token='cmnsdbf2g5wsnbsbcHcnsdc';
require_once  '/home/bwlxtuul/dojo.villaggiotramonto.it/notification/send-notification-chat.php'; // <-- qui c'è la tua funzione



    // Imposta timezone locale (coerente con l’app)
    date_default_timezone_set('Europe/Rome');
    
    


    // Esempio: evita esecuzioni fuori fascia (facoltativo, cron già gestisce l’orario)
    $now = new DateTimeImmutable('now', new DateTimeZone('Europe/Rome'));

    
    $stmt = $pdo->prepare('SELECT * FROM `tasks` WHERE DATE(due_date) = CURDATE() AND deleted_at IS NULL;');
    $stmt->execute();
    $arrayTaskDiOggi = $stmt->fetchAll();
    
    
  $myfile = fopen("logs.txt", "wr") or die("Unable to open file!");


    foreach ($arrayTaskDiOggi as $task){
        notify($task['title'],$task['dipartimento'],$task['due_date'],$task['priority'],$task['description']);
    }


