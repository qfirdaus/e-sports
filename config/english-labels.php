<?php
/** English display labels for stored reference values. Never use these for persistence. */
function english_display_labels(): array {
    return [
        'Awam' => 'Public', 'Swasta' => 'Private', 'Luar Negara' => 'Overseas',
        'STAF' => 'Staff', 'PELAJAR' => 'Student',
        'PENGURUS' => 'Manager', 'JURULATIH' => 'Coach', 'ATLET' => 'Athlete',
        'PENOLONG PENGURUS' => 'Assistant Manager', 'PENOLONG JURULATIH' => 'Assistant Coach',
        'KETUA KONTINJEN' => 'Contingent Leader', 'PENYELARAS' => 'Coordinator',
        'JAWATANKUASA SUKARELAWAN' => 'Volunteer Committee',
        'JAWATANKUASA PELAKSANA' => 'Organising Committee',
        'JAWATANKUASA' => 'Committee',
        'LELAKI' => 'Male', 'WANITA' => 'Female', 'PEREMPUAN' => 'Female',
        'Aktif' => 'Active', 'Tidak Aktif' => 'Inactive', 'Tiada Rekod' => 'No Record',
        'Bolasepak' => 'Football', 'Bola Sepak' => 'Football',
        'Bola Tampar' => 'Volleyball', 'Bola Jaring' => 'Netball', 'Bola Keranjang' => 'Basketball',
        'Olahraga' => 'Athletics', 'Catur' => 'Chess', 'Ragbi 7s' => 'Rugby Sevens',
        'Ragbi 7 Sebelah' => 'Rugby Sevens', 'Tenpin Boling' => 'Tenpin Bowling',
        'Pentadbir' => 'Administrator', 'Penganjur' => 'Organiser', 'Hakim' => 'Judge',
        'Penonton' => 'Viewer', 'Pengguna Kontinjen' => 'Contingent User',
    ];
}

function english_label($value): string {
    $text = (string)$value;
    foreach (english_display_labels() as $original => $label) {
        if (strcasecmp(trim($text), $original) === 0) return $label;
    }
    return $text;
}

/** Translate sport/category descriptions only, never participant or institution names. */
function english_sport_label($value): string {
    $text = (string)$value;
    $terms = [
        'Bola Sepak 9-Sebelah' => '9-a-side Football', 'Ragbi 7 Sebelah' => 'Rugby Sevens',
        'Bola Tampar' => 'Volleyball', 'Bola Jaring' => 'Netball', 'Bola Keranjang' => 'Basketball',
        'Bola Sepak' => 'Football', 'Bolasepak' => 'Football', 'Olahraga' => 'Athletics',
        'Catur' => 'Chess', 'Ragbi 7s' => 'Rugby Sevens', 'Tenpin Boling' => 'Tenpin Bowling',
        'Berpasukan' => 'Team', 'Perseorangan' => 'Singles', 'Individu' => 'Individual',
        'Pasukan' => 'Team', 'Lelaki' => 'Men', 'Wanita' => 'Women', 'Perempuan' => 'Women',
    ];
    foreach ($terms as $original => $label) {
        $text = preg_replace('/\b' . preg_quote($original, '/') . '\b/iu', $label, $text);
    }
    return $text;
}
