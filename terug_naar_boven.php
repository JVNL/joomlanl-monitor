<?php
// terug_naar_boven.php
//
// Een zwevende "terug naar boven"-knop rechtsonderin, die pas verschijnt
// zodra er voldoende naar beneden is gescrold. Wordt op elke pagina
// ingeladen met een PHP include-aanroep, vlak vóór de sluitende
// body-tag van die pagina.
?>
<style>
/* Ruimte onderaan elke pagina reserveren, zodat de zwevende knop hieronder
   nooit over de laatste regel/knoppenrij van de eigenlijke inhoud heen valt. */
body {
    padding-bottom: 70px;
}

#terug-naar-boven {
    display: none;
    position: fixed;
    right: 25px;
    bottom: 25px;
    width: 44px;
    height: 44px;
    border-radius: 50%;
    background: #000;
    color: #fff;
    border: none;
    font-size: 20px;
    line-height: 1;
    cursor: pointer;
    box-shadow: 0 2px 8px rgba(0, 0, 0, 0.3);
    z-index: 9999;
    align-items: center;
    justify-content: center;
    transition: background 0.2s;
}

#terug-naar-boven:hover {
    background: #555;
}

#terug-naar-boven.zichtbaar {
    display: flex;
}

/* In donkere modus is een zwarte cirkel met witte pijl nauwelijks te
   onderscheiden van de rest van de (eveneens donkere) pagina - hier dus
   het omgekeerde kleurenschema: een lichtgrijze cirkel met zwarte pijl. */
html[data-thema="donker"] #terug-naar-boven {
    background: #cccccc;
    color: #000;
}

html[data-thema="donker"] #terug-naar-boven:hover {
    background: #e2e2e2;
}
</style>

<button type="button" id="terug-naar-boven" title="Terug naar boven" aria-label="Terug naar boven" onclick="window.scrollTo({ top: 0, behavior: 'smooth' });">↑</button>

<script>
(function () {
    const knop = document.getElementById('terug-naar-boven');
    if (!knop) {
        return;
    }

    window.addEventListener('scroll', function () {
        if (window.scrollY > 300) {
            knop.classList.add('zichtbaar');
        } else {
            knop.classList.remove('zichtbaar');
        }
    });
})();
</script>
