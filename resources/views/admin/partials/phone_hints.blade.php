{{-- Inline-Hinweis, wenn eine Mobilnummer im Telefon-Feld steht (oder umgekehrt).
     Spiegelt die Server-Validierung (App\Support\GermanPhone) im Browser. --}}
<script>
(function () {
    function norm(v) {
        v = (v || '').replace(/[^\d+]/g, '');
        v = v.replace(/^\+49/, '0').replace(/^0049/, '0');
        if (/^49\d{9,}$/.test(v)) v = '0' + v.slice(2);
        return v;
    }
    function isMobile(v) { return /^01[567]\d{5,}$/.test(norm(v)); }
    function isLandline(v) { return /^0[2-9]\d{3,}$/.test(norm(v)); }

    function attach(name, isWrong, message) {
        document.querySelectorAll('input[name="' + name + '"]').forEach(function (input) {
            var hint = document.createElement('div');
            hint.style.cssText = 'font-size:12px;color:#B3261E;margin-top:4px;display:none;';
            input.insertAdjacentElement('afterend', hint);
            function check() {
                if (input.value && isWrong(input.value)) {
                    hint.textContent = '⚠ ' + message;
                    hint.style.display = '';
                    input.style.borderColor = '#B3261E';
                } else {
                    hint.style.display = 'none';
                    input.style.borderColor = '';
                }
            }
            input.addEventListener('input', check);
            input.addEventListener('blur', check);
        });
    }

    attach('mobile', isLandline, 'Das sieht nach einer Festnetznummer aus – bitte ins Feld „Telefon" eintragen.');
    attach('phone', isMobile, 'Das sieht nach einer Mobilnummer aus – bitte ins Feld „Mobil" eintragen.');
})();
</script>
