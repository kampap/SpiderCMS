<?php
// SpiderCMS Booking Autoload
// Ten plik nie zmienia treści strony. Tylko zamienia widoczny tekst [booking] na widget rezerwacji po stronie przeglądarki.
$endpoint = (defined('BASE_URL') ? rtrim(BASE_URL, '/') : '') . '/admin.php';
?>
<script>
(function(){
    function ready(fn){
        if(document.readyState !== 'loading') fn();
        else document.addEventListener('DOMContentLoaded', fn);
    }

    function findBookingShortcodes(root){
        var found = [];
        var walker = document.createTreeWalker(root || document.body, NodeFilter.SHOW_TEXT, {
            acceptNode: function(node){
                if(!node.nodeValue || node.nodeValue.indexOf('[booking') === -1) return NodeFilter.FILTER_REJECT;
                if(node.parentElement && ['SCRIPT','STYLE','TEXTAREA','INPUT'].includes(node.parentElement.tagName)) return NodeFilter.FILTER_REJECT;
                return NodeFilter.FILTER_ACCEPT;
            }
        });
        var n;
        while(n = walker.nextNode()) found.push(n);
        return found;
    }

    ready(function(){
        if(document.body.dataset.spidercmsBookingLoaded === '1') return;
        var nodes = findBookingShortcodes(document.body);
        if(!nodes.length) return;

        document.body.dataset.spidercmsBookingLoaded = '1';

        fetch(<?php echo json_encode($endpoint); ?> + '?action=booking_public_widget', {credentials:'same-origin'})
            .then(function(r){ return r.text(); })
            .then(function(html){
                nodes.forEach(function(node){
                    var text = node.nodeValue || '';
                    if(text.indexOf('[booking') === -1) return;
                    var wrapper = document.createElement('div');
                    wrapper.innerHTML = html;
                    var parent = node.parentNode;
                    if(!parent) return;

                    // Jeżeli w tym samym tekście jest coś oprócz shortcode, zachowujemy resztę.
                    var before = text.replace(/\[booking(?:\s+id="[^"]*")?\]/ig, '').trim();
                    if(before){
                        node.nodeValue = before + ' ';
                        parent.insertBefore(wrapper, node.nextSibling);
                    }else{
                        parent.replaceChild(wrapper, node);
                    }

                    wrapper.querySelectorAll('script').forEach(function(oldScript){
                        var s = document.createElement('script');
                        if(oldScript.src) s.src = oldScript.src;
                        s.text = oldScript.textContent;
                        document.body.appendChild(s);
                        oldScript.remove();
                    });
                });
            })
            .catch(function(){});
    });
})();
</script>