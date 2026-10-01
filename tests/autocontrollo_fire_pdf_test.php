<?php
$page=file_get_contents(__DIR__.'/../autocontrollo_antincendio.php');$pdf=file_get_contents(__DIR__.'/../reports/autocontrollo_fire_pdf.php');
foreach([[$page,'autocontrollo_fire_pdf.php?id=','PDF singolo'],[$page,'autocontrollo_fire_pdf.php?season=1','PDF cumulativo'],[$page,'bi-file-earmark-pdf','icona PDF'],[$pdf,"user_has_department(\$user, 'Amministrazione')",'protezione'],[$pdf,'NO — ANOMALIA','anomalie'],[$pdf,"setPaper('A4', 'portrait')",'formato PDF']] as [$source,$needle,$label])if($source===false||!str_contains($source,$needle))throw new RuntimeException('Funzionalità mancante: '.$label);
echo "Export PDF antincendio verificato.\n";
