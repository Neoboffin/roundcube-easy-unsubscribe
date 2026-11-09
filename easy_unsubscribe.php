<?php
/**
 * easy_unsubscribe — Roundcube plugin
 *
 * Adds an "Unsubscribe" button to the message-view toolbar. When the
 * message carries an RFC 2369 List-Unsubscribe header, clicking the
 * button opens a native Roundcube popup menu (same system Mark / More
 * use) listing every walkable URI — http(s) first, then mailto: — each
 * row showing a short label ("Via web" / "Via email") and the host.
 *
 * Works consistently in desktop, mobile, and the Elastic overflow
 * popover because the UI piggy-backs on rcmail.show_menu() instead of
 * hand-rolling positioning.
 *
 * @version 2.0.0
 */
class easy_unsubscribe extends rcube_plugin
{
    public const VERSION = '2.0.0';

    public $task = 'mail';

    public function init()
    {
        $rcmail = rcmail::get_instance();
        if ($rcmail->task !== 'mail') return;

        $this->add_hook('storage_init',           [$this, 'storage_init']);
        $this->add_hook('message_headers_output', [$this, 'message_headers']);

        $this->include_stylesheet('easy_unsubscribe.css');
        $this->include_script('easy_unsubscribe.js');
        $this->add_texts('localization/');

        if (in_array($rcmail->action, ['', 'show', 'preview'], true)) {
            $this->add_button([
                'type'          => 'link',
                'label'         => 'unsubscribe',
                'command'       => 'plugin.easy_unsubscribe',
                'class'         => 'button eu-unsub disabled',
                'classact'      => 'button eu-unsub',
                'classsel'      => 'button eu-unsub selected',
                'title'         => 'click_to_unsubscribe',
                'innerclass'    => 'inner',
                'domain'        => $this->ID,
                /* Triggers Elastic's right-chevron + flex layout in the
                 * mobile overflow menu, matching Mark / Reply-all /
                 * Forward — all of which open a submenu. */
                'aria-haspopup' => 'true',
            ], 'toolbar');
        }
    }

    /** Ask the IMAP driver to fetch the header we need. */
    public function storage_init($p)
    {
        $extra = 'LIST-UNSUBSCRIBE';
        $p['fetch_headers'] = isset($p['fetch_headers'])
            ? trim($p['fetch_headers'] . ' ' . $extra)
            : $extra;
        return $p;
    }

    /**
     * Pass the walkable URIs to the parent window's rcmail.env so the
     * toolbar button (which lives in the parent window) can enable
     * itself and populate its popup.
     *
     * Routed via parent.set_env + parent.enable_command because the
     * preview pane renders inside an iframe; Roundcube skips serializing
     * js_env for framed output, so a bare set_env from the iframe would
     * silently never reach the toolbar.
     */
    public function message_headers($p)
    {
        $items = [];

        $raw = $p['headers']->others['list-unsubscribe'] ?? null;

        /* Fallback for stale message cache.
         *
         * Roundcube caches each message's header set at receive time.
         * Any message cached BEFORE this plugin's storage_init hook
         * added LIST-UNSUBSCRIBE to fetch_headers will come back from
         * cache without the header, so the button stays disabled even
         * though the email really does carry one.
         *
         * When that happens, call get_message_headers(..., force=true)
         * which bypasses the message cache and writes the refreshed
         * entry back, so every subsequent view of the same uid lands
         * the header without this fallback firing again. */
        if (!$raw && !empty($p['uid']) && !empty($p['folder'])) {
            try {
                $fresh = rcmail::get_instance()->storage
                    ->get_message_headers($p['uid'], $p['folder'], true);
                if ($fresh && isset($fresh->others['list-unsubscribe'])) {
                    $raw = $fresh->others['list-unsubscribe'];
                }
            } catch (\Throwable $e) {
                /* non-fatal; the button just stays disabled. */
            }
        }
        if ($raw) {
            if (is_array($raw)) $raw = implode(', ', $raw);
            $uris = $this->parse_uris($raw);
            if ($uris) {
                $web   = $this->gettext('via_web');
                $email = $this->gettext('via_email');
                foreach ($uris as $u) {
                    $items[] = [
                        'href'  => $u,
                        'label' => stripos($u, 'mailto:') === 0 ? $email : $web,
                        'host'  => $this->display_host($u),
                    ];
                }
            }
        }

        $rcmail = rcmail::get_instance();
        $rcmail->output->set_env('easy_unsubscribe_items', $items);
        $rcmail->output->command('parent.set_env',
            'easy_unsubscribe_items', $items);
        $rcmail->output->command('parent.enable_command',
            'plugin.easy_unsubscribe', !empty($items));

        return $p;
    }

    /** Walkable URIs in order of preference: https > http > mailto. */
    private function parse_uris($raw)
    {
        if (!preg_match_all('/<\s*([^>]+?)\s*>/', $raw, $m)) return [];
        $https = $http = $mailto = [];
        foreach ($m[1] as $u) {
            $u = trim($u);
            if      (stripos($u, 'https:')  === 0) $https[]  = $u;
            elseif  (stripos($u, 'http:')   === 0) $http[]   = $u;
            elseif  (stripos($u, 'mailto:') === 0) $mailto[] = $u;
        }
        return array_merge($https, $http, $mailto);
    }

    private function display_host($u)
    {
        if (stripos($u, 'mailto:') === 0) return substr($u, 7);
        $host = parse_url($u, PHP_URL_HOST);
        return $host ?: $u;
    }
}
