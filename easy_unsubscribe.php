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

    /** Max (folder, uid) entries remembered by the stale-cache fallback. */
    private const CHECKED_MAX = 200;

    public $task = 'mail';

    /** Set once the top-level message's headers have been handled. */
    private $done = false;

    public function init()
    {
        $rcmail = rcmail::get_instance();

        $this->add_hook('storage_init',           [$this, 'storage_init']);
        $this->add_hook('message_headers_output', [$this, 'message_headers']);

        $this->include_stylesheet('easy_unsubscribe.css');
        $this->include_script('easy_unsubscribe.js');
        $this->add_texts('localization/', ['unsubscribe', 'confirm', 'menu_title']);

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
     * Pass the walkable URIs to the parent window so the toolbar button
     * (which lives in the parent window) can enable itself and populate
     * its popup.
     *
     * Routed via parent.triggerEvent because the preview pane renders
     * inside an iframe; Roundcube skips serializing js_env for framed
     * output, so a bare set_env from the iframe would never reach the
     * toolbar. The payload carries the uid + folder it belongs to so the
     * parent only enables the button while that message is selected.
     */
    public function message_headers($p)
    {
        /* The hook also fires for every embedded message/rfc822 part,
         * whose header object is built from the part and has no uid.
         * Only the top-level message may drive the toolbar button,
         * otherwise an attached .eml could supply the links. */
        $headers = $p['headers'] ?? null;
        if ($this->done
            || !($headers instanceof rcube_message_header)
            || empty($p['uid'])
            || (string) $headers->uid !== (string) $p['uid']
        ) {
            return $p;
        }
        $this->done = true;

        $raw = $headers->others['list-unsubscribe'] ?? null;
        if (!$raw) {
            $raw = $this->fetch_uncached($p['uid'], $p['folder'] ?? null);
        }

        $items = [];
        if ($raw) {
            if (is_array($raw)) $raw = implode(', ', $raw);
            $web   = $this->gettext('via_web');
            $email = $this->gettext('via_email');
            foreach ($this->parse_uris($raw) as $u) {
                $is_mail = stripos($u, 'mailto:') === 0;
                $items[] = [
                    'href'  => $u,
                    'type'  => $is_mail ? 'mailto' : 'web',
                    'label' => $is_mail ? $email : $web,
                    /* Web hosts are derived client-side from the same
                     * URL parser the browser navigates with. */
                    'host'  => $is_mail ? $this->mailto_address($u) : '',
                ];
            }
        }

        $data = [
            'uid'    => (string) $p['uid'],
            'folder' => (string) ($p['folder'] ?? ''),
            'items'  => $items,
        ];

        $rcmail = rcmail::get_instance();
        $rcmail->output->set_env('easy_unsubscribe', $data);
        $rcmail->output->command('parent.triggerEvent', 'easy_unsubscribe_update', $data);

        return $p;
    }

    /**
     * Fallback for stale message cache.
     *
     * Roundcube caches each message's header set. Any message cached
     * BEFORE this plugin's storage_init hook added LIST-UNSUBSCRIBE to
     * fetch_headers comes back without the header even if the email
     * carries one. A forced fetch bypasses the cache but does not write
     * back to it, so the result is remembered in the session to keep
     * this to one extra IMAP fetch per message per session rather than
     * one on every view of every message that has no such header.
     */
    private function fetch_uncached($uid, $folder)
    {
        $rcmail = rcmail::get_instance();
        if (!$rcmail->config->get('messages_cache') || !$folder) {
            /* No message cache: the headers came straight from IMAP
             * with our fetch_headers, so absence is authoritative. */
            return null;
        }

        $key = md5($folder . "\0" . $uid);
        $checked = $_SESSION['easy_unsubscribe_checked'] ?? [];
        if (array_key_exists($key, $checked)) {
            return $checked[$key];
        }

        $raw = null;
        try {
            $fresh = $rcmail->storage->get_message_headers($uid, $folder, true);
            if ($fresh && isset($fresh->others['list-unsubscribe'])) {
                $raw = $fresh->others['list-unsubscribe'];
                if (is_array($raw)) $raw = implode(', ', $raw);
            }
        } catch (\Throwable $e) {
            /* non-fatal; the button just stays disabled. */
            return null;
        }

        $checked[$key] = $raw;
        if (count($checked) > self::CHECKED_MAX) {
            $checked = array_slice($checked, -self::CHECKED_MAX, null, true);
        }
        $_SESSION['easy_unsubscribe_checked'] = $checked;

        return $raw;
    }

    /** Walkable URIs in order of preference: https > http > mailto. */
    private function parse_uris($raw)
    {
        if (!preg_match_all('/<([^>]+)>/', $raw, $m)) return [];
        $https = $http = $mailto = [];
        foreach ($m[1] as $u) {
            /* RFC 2369: whitespace inside the angle brackets is to be
             * ignored, and folded headers often split long URLs. */
            $u = preg_replace('/\s+/', '', $u);
            if (stripos($u, 'mailto:') === 0) {
                if (strlen($u) > 7) $mailto[] = $u;
            } elseif ($this->is_safe_web_uri($u)) {
                if (stripos($u, 'https:') === 0) $https[] = $u;
                else                             $http[]  = $u;
            }
        }
        return array_merge($https, $http, $mailto);
    }

    /**
     * Accept only plain http(s) URLs whose host both PHP and browsers
     * agree on: no backslashes (browsers treat them as path separators,
     * PHP does not) and no userinfo, which is the usual way to make a
     * link show one host and open another.
     */
    private function is_safe_web_uri($u)
    {
        if (!preg_match('~^https?://~i', $u)) return false;
        if (strpos($u, '\\') !== false)        return false;
        $parts = parse_url($u);
        if (!$parts || empty($parts['host']))   return false;
        if (isset($parts['user']) || isset($parts['pass'])) return false;
        return true;
    }

    /** Address part of a mailto: URI, without the ?subject=... query. */
    private function mailto_address($u)
    {
        $addr = substr($u, 7);
        $q = strpos($addr, '?');
        if ($q !== false) $addr = substr($addr, 0, $q);
        return rawurldecode($addr);
    }
}
