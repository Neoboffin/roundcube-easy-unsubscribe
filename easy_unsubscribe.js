function easy_unsubscribe_click(link) {

    if(confirm(rcmail.get_label('confirm', 'easy_unsubscribe'))) {

        if(link.dataset.href && /^(https?:|mailto:)/i.test(link.dataset.href)) {
            var w = window.open(link.dataset.href, '_blank', 'noopener');
            if(w) w.opener = null;
        }

    }

}
