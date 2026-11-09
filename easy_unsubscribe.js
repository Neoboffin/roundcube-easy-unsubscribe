/* easy_unsubscribe 2.0.0 — toolbar command + Roundcube native popup menu.
 *
 * The menu is a <div class="popupmenu"> rebuilt every open from the
 * current rcmail.env.easy_unsubscribe_items, then shown via
 * rcmail.show_menu() — the same mechanism Mark / More use. That gives
 * us free desktop positioning, mobile sheet behaviour, and overflow-
 * popover compatibility without hand-rolling any of it. */
(function () {
    var MENU_ID = 'easy-unsubscribe-menu';

    function escHtml(s) {
        return String(s).replace(/[&<>"']/g, function (c) {
            return { '&':'&amp;', '<':'&lt;', '>':'&gt;', '"':'&quot;', "'":'&#39;' }[c];
        });
    }

    function ensureMenu(items) {
        var m = document.getElementById(MENU_ID);
        if (!m) {
            m = document.createElement('div');
            m.id = MENU_ID;
            m.className = 'popupmenu eu-unsub-popup';
            document.body.appendChild(m);
        }

        var rows = items.map(function (it) {
            return '<li role="menuitem">'
                 + '<a class="active eu-unsub-item" href="' + escHtml(it.href) + '"'
                 +     ' target="_blank" rel="noopener noreferrer">'
                 +   '<div class="label">' + escHtml(it.label) + '</div>'
                 +   '<div class="host">'  + escHtml(it.host)  + '</div>'
                 + '</a></li>';
        }).join('');

        m.innerHTML =
            '<h3 class="voice">Unsubscribe options</h3>'
          + '<ul class="menu listing" role="menu">' + rows + '</ul>';

        return m;
    }

    function openMenu(props, obj, event) {
        if (!window.rcmail) return;
        var items = rcmail.env && rcmail.env.easy_unsubscribe_items;
        if (!items || !items.length) return;

        ensureMenu(items);
        rcmail.show_menu(MENU_ID, true, event);
    }

    function syncEnabled() {
        if (!window.rcmail) return;
        var items = rcmail.env && rcmail.env.easy_unsubscribe_items;
        rcmail.enable_command('plugin.easy_unsubscribe', !!(items && items.length));
    }

    /* A menu item was clicked — let the <a> open its target, then
     * close the popup so a second click starts clean. */
    document.addEventListener('click', function (e) {
        if (!e.target || !e.target.closest) return;
        var a = e.target.closest('.eu-unsub-item');
        if (!a || !window.rcmail) return;
        setTimeout(function () {
            try { rcmail.show_menu(MENU_ID, false, e); } catch (_) {}
        }, 0);
    }, true);

    function bootstrap() {
        if (!window.rcmail) return;
        rcmail.register_command('plugin.easy_unsubscribe', openMenu, false);
        syncEnabled();
        if (rcmail.addEventListener) {
            rcmail.addEventListener('responseafter', syncEnabled);
            rcmail.addEventListener('actionafter',   syncEnabled);
        }
    }

    if (window.rcmail && rcmail.register_command) bootstrap();
    if (window.rcmail && rcmail.addEventListener) rcmail.addEventListener('init', bootstrap);
})();
