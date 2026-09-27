<script>
(function () {
    var labels = <?php echo json_encode(english_display_labels(), JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT); ?>;
    window.englishLabel = function (value) {
        var text = String(value == null ? '' : value);
        var key = text.trim().toLowerCase();
        for (var original in labels) {
            if (original.toLowerCase() === key) return labels[original];
        }
        return text;
    };
    window.englishSportLabel = function (value) {
        var text = String(value == null ? '' : value);
        var terms = <?php
        // Reuse the PHP translator to keep the browser and print labels consistent.
        $terms = ['Bola Sepak 9-Sebelah', 'Ragbi 7 Sebelah', 'Bola Tampar', 'Bola Jaring', 'Bola Keranjang', 'Bola Sepak', 'Bolasepak', 'Olahraga', 'Catur', 'Ragbi 7s', 'Tenpin Boling', 'Berpasukan', 'Perseorangan', 'Individu', 'Pasukan', 'Lelaki', 'Wanita', 'Perempuan'];
        $sportLabels = [];
        foreach ($terms as $term) $sportLabels[$term] = english_sport_label($term);
        echo json_encode($sportLabels, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT);
        ?>;
        for (var term in terms) text = text.replace(new RegExp('\\b' + term + '\\b', 'gi'), terms[term]);
        return text;
    };
})();
</script>
