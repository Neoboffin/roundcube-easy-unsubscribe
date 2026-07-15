function easy_unsubscribe_click(link) {

    if(confirm(rcmail.get_label('confirm', 'easy_unsubscribe'))) {

        if(link.dataset.href && /^(https?:|mailto:)/i.test(link.dataset.href)) window.open(link.dataset.href, '_blank');

    }

}
