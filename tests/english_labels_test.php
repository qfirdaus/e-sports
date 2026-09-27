<?php
require_once __DIR__ . '/../config/english-labels.php';

$cases = [
    [english_label('Awam'), 'Public'],
    [english_label('Swasta'), 'Private'],
    [english_label('Luar Negara'), 'Overseas'],
    [english_label('PENOLONG JURULATIH'), 'Assistant Coach'],
    [english_label('ketua kontinjen'), 'Contingent Leader'],
    [english_label('Perak'), 'Perak'], // State names must not become medal labels.
    [english_label('Universiti Malaya'), 'Universiti Malaya'],
    [english_label('Ali bin Ahmad'), 'Ali bin Ahmad'],
    [english_label(null), ''],
    [english_sport_label('Bola Tampar Wanita'), 'Volleyball Women'],
    [english_sport_label('100M Lelaki'), '100M Men'],
    [english_sport_label('Bola Sepak 9-Sebelah Lelaki'), '9-a-side Football Men'],
    [english_sport_label('MLBB Berpasukan'), 'MLBB Team'],
    [english_sport_label('Sepak Takraw'), 'Sepak Takraw'],
];
foreach ($cases as [$actual, $expected]) {
    if ($actual !== $expected) {
        fwrite(STDERR, "Expected {$expected}; got {$actual}\n");
        exit(1);
    }
}
echo count($cases) . " English display-label checks passed.\n";
