<?php
$dashboard=file_get_contents(__DIR__.'/../dashboard.php');if($dashboard===false)throw new RuntimeException('Dashboard non leggibile.');if(str_contains($dashboard,'Ordinabili oggi'))throw new RuntimeException('Il box Ordinabili oggi deve essere rimosso.');foreach(['Autocontrollo','Derattizzazione','Messa a Terra','Antincendio','Piscina','Temperature','Pulizia HACCP','Impianto Elettrico']as$label)if(!str_contains($dashboard,$label))throw new RuntimeException('Riga mancante: '.$label);foreach(['autocontrollo_rodent_next_date','autocontrollo_temperature_next_due','autocontrollo_temperature_is_available','autocontrollo_haccp_next_date','autocontrollo_electrical_schedule']as$helper)if(!str_contains($dashboard,$helper))throw new RuntimeException('Calcolo disponibilità mancante: '.$helper);echo "Box Autocontrollo dashboard verificato.\n";

// Load the dashboard's declared dependencies in isolation, before any other
// test or login include can accidentally supply a missing helper.
$dependencies = [];
preg_match_all("~require_once __DIR__ \\. '/core/(autocontrollo_[a-z_]+\\.php)';~", $dashboard, $dependencies);
foreach ($dependencies[1] as $dependency) require_once __DIR__ . '/../core/' . $dependency;
foreach (['autocontrollo_rodent_schedule', 'autocontrollo_rodent_next_date', 'autocontrollo_grounding_schedule', 'autocontrollo_fire_schedule', 'autocontrollo_fire_next_date', 'autocontrollo_pool_next_required_date', 'autocontrollo_temperature_next_due', 'autocontrollo_temperature_is_available', 'autocontrollo_haccp_schedule', 'autocontrollo_haccp_next_date', 'autocontrollo_electrical_schedule'] as $helper) {
    if (!function_exists($helper)) throw new RuntimeException('Dipendenza dashboard non caricata: ' . $helper);
}
$range = ['start' => '2026-05-01', 'end' => '2026-06-01'];
if (autocontrollo_rodent_next_date(autocontrollo_rodent_schedule($range), ['2026-05-01']) !== '2026-05-16') {
    throw new RuntimeException('Calcolo della prossima derattizzazione non valido.');
}
echo "Dipendenze dashboard caricate e calcolo derattizzazione verificato.\n";
