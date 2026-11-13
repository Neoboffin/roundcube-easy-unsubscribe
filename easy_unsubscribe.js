/* easy_unsubscribe 2.0.0 — toolbar command + Roundcube native popup menu.
 *
 * The menu is a <div class="popupmenu"> rebuilt every open from the
 * current rcmail.env.easy_unsubscribe, then shown via rcmail.show_menu()
 * — the same mechanism Mark / More use. That gives us free desktop
 * positioning, mobile sheet behaviour, and overflow-popover
 * compatibility without hand-rolling any of it.
 *
 * rcmail.env.easy_unsubscribe = { uid, folder, items: [...] } is sent by
 * the server for the message being viewed. The button is only enabled
 * while that same message is the one selected, so deselecting, moving,
 * deleting or switching folder never leaves it pointing at the links of
 * a message that is no longer shown. */
(function () {
    var MENU_ID = 'easy-unsubscribe-menu',
        DOMAIN  = 'easy_unsubscribe',
        CMD     = 'plugin.easy_unsubscribe';

    if (!window.rcmail || !rcmail.register_command) return;

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function label(name) {
        return rcmail.get_label(name, DOMAIN);
    }

    /* Host the browser will actually navigate to. Computed with the
     * browser's own URL parser so the label can't disagree with the
     * destination. */
    function webHost(href) {
        try { return new URL(href).host; } catch (_) { return ''; }
    }

    /* Is the message the data belongs to the one currently shown? */
    function isCurrent(d) {
        if (!d || !d.uid) return false;

        if (rcmail.env.action === 'show' || rcmail.env.action === 'preview') {
            return String(rcmail.env.uid) === d.uid;
        }

        var sel = rcmail.message_list ? rcmail.message_list.get_single_selection() : null;
        if (sel === null || sel === undefined) return false;
        sel = String(sel);

        /* Multi-folder search listings use "<uid>-<folder>" ids. */
        if (sel === d.uid + '-' + d.folder) return true;
        return sel === d.uid && rcmail.env.mailbox === d.folder;
    }

    function currentItems() {
        var d = rcmail.env.easy_unsubscribe;
        return isCurrent(d) && d.items && d.items.length ? d.items : null;
    }

    function syncEnabled() {
        rcmail.enable_command(CMD, !!currentItems());
    }

    function ensureMenu(items) {
        var m = document.getElementById(MENU_ID);
        if (!m) {
            m = document.createElement('div');
            m.id = MENU_ID;
            m.className = 'popupmenu eu-unsub-popup';
            document.body.appendChild(m);
        }

        var rows = items.map(function (it, i) {
            var host = it.type === 'mailto' ? it.host : webHost(it.href);
            return '<li role="menuitem">'
                 + '<a class="active eu-unsub-item" href="#" data-eu-idx="' + i + '">'
                 +   '<div class="label">' + escHtml(it.label) + '</div>'
                 +   '<div class="host">'  + escHtml(host)     + '</div>'
                 + '</a></li>';
        }).join('');

        m.innerHTML =
            '<h3 class="voice">' + escHtml(label('menu_title')) + '</h3>'
          + '<ul class="menu listing" role="menu">' + rows + '</ul>';

        /* Snapshot so a click acts on what was shown, even if the
         * selection changes while the confirm dialog is open. */
        m._euItems = items;
        return m;
    }

    function openMenu(props, obj, event) {
        var items = currentItems();
        if (!items) return;

        ensureMenu(items);
        rcmail.show_menu(MENU_ID, true, event);
    }

    function follow(it) {
        if (it.type === 'mailto') {
            /* Roundcube's compose parses mailto: URIs in _to, including
             * ?subject= and ?body=. */
            rcmail.open_compose_step({ _to: it.href });
        } else {
            window.open(it.href, '_blank', 'noopener,noreferrer');
        }
    }

    /* A menu item was clicked: close the popup, ask for confirmation,
     * then open the link or compose the unsubscribe email. */
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        var a = e.target.closest('.eu-unsub-item');
        if (!a) return;
        e.preventDefault();

        var menu = document.getElementById(MENU_ID),
            items = menu && menu._euItems,
            it = items && items[parseInt(a.getAttribute('data-eu-idx'), 10)];

        try { rcmail.show_menu(MENU_ID, false, e); } catch (_) {}
        if (!it) return;

        rcmail.confirm_dialog(escHtml(label('confirm')), DOMAIN + '.unsubscribe',
            function () { follow(it); });
    }, true);

    rcmail.register_command(CMD, openMenu, false);

    /* Sent by the server (from the preview iframe via parent.triggerEvent,
     * or directly in the full "show" view). */
    rcmail.addEventListener('easy_unsubscribe_update', function (d) {
        rcmail.env.easy_unsubscribe = d;
        syncEnabled();
    });

    rcmail.addEventListener('responseafter', syncEnabled);
    rcmail.addEventListener('actionafter',   syncEnabled);

    rcmail.addEventListener('init', function () {
        if (rcmail.message_list) {
            rcmail.message_list.addEventListener('select', syncEnabled);
        }
        syncEnabled();
    });
})();
