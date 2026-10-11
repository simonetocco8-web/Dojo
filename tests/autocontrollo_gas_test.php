<?php
require_once __DIR__ . '/../core/autocontrollo_gas.php';
$range = ['start'=>'2026-06-01','end'=>'2026-08-15'];
$expected = ['2026-05-17','2026-06-16','2026-07-16','2026-08-15'];
if (autocontrollo_gas_schedule($range) !== $expected) throw new RuntimeException('Wrong thirty-day schedule or closing-day boundary.');
if (autocontrollo_gas_schedule(['start'=>'2026-02-30','end'=>'2026-09-01']) !== []) throw new RuntimeException('Invalid date accepted.');
if (autocontrollo_gas_next_date($expected, [$expected[0]]) !== $expected[1] || autocontrollo_gas_next_date($expected, $expected) !== null) throw new RuntimeException('Next due wrong.');
if (autocontrollo_gas_parse_liters('200,50') !== '200.50' || autocontrollo_gas_parse_liters('0') !== '0.00') throw new RuntimeException('Liters wrong.');
foreach (['-1','nan','1e3','2.333',''] as $value) {
    try { autocontrollo_gas_parse_liters($value); } catch (InvalidArgumentException $e) { continue; }
    throw new RuntimeException('Invalid liters accepted.');
}
foreach ([199,200,201] as $liters) {
    $html = autocontrollo_gas_report_html(['liters'=>$liters,'alert_threshold'=>200,'scheduled_date'=>'2026-05-17','completed_at'=>'2026-05-17 10:00:00','operator_name'=>'<Test>']);
    if (str_contains($html, '<u>') !== ($liters <= 200) || str_contains($html, '<Test>')) throw new RuntimeException('Threshold equality or escaping wrong.');
}
echo "Gas: calendario, litri e soglia inclusiva verificati.\n";
