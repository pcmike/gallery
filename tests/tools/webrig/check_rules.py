#!/usr/bin/env python3
"""
Runs the server rules the gallery writes for you (the tidy share address, custom names, /admin, a folder typed without its
closing slash) through a REAL nginx and a REAL Apache, both unprivileged, each talking to a real php-fpm. Checking the
text of a rule is not enough (an unquoted `{16}` once shipped that nginx refuses), so run this whenever a rule changes.

    python3 -I tests/tools/webrig/check_rules.py

It needs network the first time (it downloads the Debian packages with `apt-get download`, no root, into
~/.cache/gallery-test-tools/webrig) and PHP 8.4's extensions installed on the box (gd is used for the fixture photos).
Nothing is installed and nothing listens outside 127.0.0.1. The rules are taken from the gallery's own Settings page, so
what is tested is exactly what the admin tells you to paste.
"""
import glob, html, http.client, http.cookiejar, os, re, shutil, signal, socket, subprocess, sys, tempfile, time, urllib.parse, urllib.request

ROOT = os.path.dirname(os.path.dirname(os.path.dirname(os.path.dirname(os.path.abspath(__file__)))))
CACHE = os.path.expanduser('~/.cache/gallery-test-tools/webrig')
X = os.path.join(CACHE, 'x')
PKGS = ['nginx', 'php8.4-fpm', 'apache2-bin', 'apache2-data', 'apache2', 'libapr1t64', 'libaprutil1t64']
LIBS = os.path.join(X, 'usr/lib/x86_64-linux-gnu')
procs, failures, passes = [], [], 0


def check(desc, cond, detail=''):
    global passes
    if cond:
        passes += 1
        print('  ok    ' + desc)
    else:
        failures.append(desc)
        print('  FAIL  ' + desc + ('\n        ' + str(detail)[:500] if detail != '' else ''))


def free_port():
    s = socket.socket(); s.bind(('127.0.0.1', 0)); p = s.getsockname()[1]; s.close(); return p


def fetch_servers():
    if os.path.exists(os.path.join(X, 'usr/sbin/nginx')) and os.path.exists(os.path.join(X, 'usr/sbin/apache2')) and os.path.exists(os.path.join(X, 'usr/sbin/php-fpm8.4')):
        return
    os.makedirs(os.path.join(CACHE, 'deb'), exist_ok=True)
    subprocess.check_call(['apt-get', 'download'] + PKGS, cwd=os.path.join(CACHE, 'deb'))
    os.makedirs(X, exist_ok=True)
    for deb in glob.glob(os.path.join(CACHE, 'deb', '*.deb')):
        subprocess.check_call(['dpkg', '-x', deb, X])


def start(args, env=None, log=None):
    e = dict(os.environ); e.update(env or {})
    p = subprocess.Popen(args, env=e, stdout=open(log, 'w') if log else subprocess.DEVNULL, stderr=subprocess.STDOUT, preexec_fn=os.setsid)
    procs.append(p); return p


def stop_all():
    for p in procs:
        try: os.killpg(os.getpgid(p.pid), signal.SIGTERM)
        except Exception: pass
    time.sleep(0.5)
    for p in procs:
        try: os.killpg(os.getpgid(p.pid), signal.SIGKILL)
        except Exception: pass


def wait_port(port, secs=15):
    end = time.time() + secs
    while time.time() < end:
        try:
            socket.create_connection(('127.0.0.1', port), 0.3).close(); return True
        except OSError:
            time.sleep(0.2)
    return False


class Site:
    """One server (nginx or Apache) in front of the shared gallery folder."""
    def __init__(self, name, port):
        self.name, self.port, self.cookies = name, port, {}

    def raw(self, path, headers=None, method='GET', body=None, cookies=True):
        c = http.client.HTTPConnection('127.0.0.1', self.port, timeout=15)
        h = dict(headers or {})
        if cookies and self.cookies:
            h['Cookie'] = '; '.join('%s=%s' % kv for kv in self.cookies.items())
        c.request(method, path, body=body, headers=h)
        r = c.getresponse(); data = r.read().decode('utf-8', 'replace')
        for sc in r.msg.get_all('Set-Cookie') or []:
            k, _, v = sc.split(';')[0].partition('=')
            if 'expires=Thu, 01 Jan 1970' in sc or v in ('', 'deleted'):
                self.cookies.pop(k, None)
            else:
                self.cookies[k] = v
        c.close()
        return r.status, data, r

    def get(self, path, cookies=True):
        st, body, r = self.raw(path, cookies=cookies)
        return st, body, r.getheader('Location') or '', r

    def follow(self, path, hops=4):
        """GET, then follow redirects (to this server) up to `hops` times: the final status, body and the path it ended on."""
        for _ in range(hops + 1):
            st, body, loc, r = self.get(path)
            if st in (301, 302, 303, 307) and loc:
                path = urllib.parse.urlsplit(loc).path + ('?' + urllib.parse.urlsplit(loc).query if urllib.parse.urlsplit(loc).query else '')
                continue
            return st, body, path
        return st, body, path

    def post(self, path, fields, cookies=True):
        st, body, r = self.raw(path, {'Content-Type': 'application/x-www-form-urlencoded'}, 'POST', urllib.parse.urlencode(fields), cookies)
        return st, body, r.getheader('Location') or '', r


def main():
    fetch_servers()
    work = tempfile.mkdtemp(prefix='gallery-webrig-')
    www = os.path.join(work, 'www'); trip = os.path.join(www, 'trip'); os.makedirs(trip)
    try:
        run(work, www, trip)
    finally:
        stop_all()
        shutil.rmtree(work, ignore_errors=True)
    print('\n%d passed, %d failed' % (passes, len(failures)))
    sys.exit(1 if failures else 0)


def run(work, www, trip):
    # --- the gallery: /trip/ with two public folders, a file with no extension, and a site index above it
    shutil.copy(os.path.join(ROOT, 'index.php'), os.path.join(trip, 'index.php'))
    subprocess.check_call(['php', '-r', '''
        foreach (["Rome", "Paris"] as $d) { mkdir("%s/$d"); $im = imagecreatetruecolor(60, 40); imagejpeg($im, "%s/$d/photo-$d.jpg"); }
    ''' % (trip, trip)])
    open(os.path.join(trip, 'notes'), 'w').write('plain file')
    open(os.path.join(www, 'index.html'), 'w').write('<html><body>THE SITE INDEX</body></html>')
    cfg = lambda extra: open(os.path.join(trip, '.gallery-config.php'), 'w').write('<?php http_response_code(404); exit; ?>\n' + __import__('json').dumps(dict({'ADMIN_PASSWORD': 'rig-admin-pw-1', 'AUTO_GROUP_BY_FOLDER': True, 'ENABLE_SHARE_LINKS': True}, **extra)) + '\n')
    cfg({})

    # --- php-fpm
    sock = os.path.join(work, 'fpm.sock')
    open(os.path.join(work, 'fpm.conf'), 'w').write('[global]\npid=%s/fpm.pid\nerror_log=%s/fpm.log\ndaemonize=no\n[www]\nlisten=%s\npm=static\npm.max_children=4\nclear_env=no\nphp_admin_value[display_errors]=0\n' % (work, work, sock))
    start([os.path.join(X, 'usr/sbin/php-fpm8.4'), '-y', os.path.join(work, 'fpm.conf'), '-F'], {'PHP_INI_SCAN_DIR': '/etc/php/8.4/cli/conf.d', 'LD_LIBRARY_PATH': LIBS}, os.path.join(work, 'fpm.out'))
    t0 = time.time()
    while not os.path.exists(sock) and time.time() - t0 < 10: time.sleep(0.2)

    # --- nginx, with the owner's own other rules (the photo files, the folder rule, the data files) around the one the gallery writes
    nport = free_port(); aport = free_port()
    base_nginx = '''daemon off; pid %(w)s/nginx.pid; error_log %(w)s/nginx.err; worker_processes 1;
events { worker_connections 64; }
http {
  access_log off; client_body_temp_path %(w)s/nb; proxy_temp_path %(w)s/np; fastcgi_temp_path %(w)s/nf; uwsgi_temp_path %(w)s/nu; scgi_temp_path %(w)s/ns;
  server {
    listen 127.0.0.1:%(p)d; root %(www)s; index index.php index.html;
    location ~ /\\.gallery- { return 404; }
    location ~* ^/trip/.*\\.(jpe?g|png|gif|webp|bmp|heic|heif|mov|mp4|m4v)$ { internal; }
    location ~ ^/trip/.+/$ { rewrite ^/trip/(.+)/$ /trip/index.php?folder=$1 last; }
    %(rule)s
    location ~ \\.php$ { include %(w)s/fcgi.conf; fastcgi_pass unix:%(sock)s; }
    location / { try_files $uri $uri/ /index.html; }
  }
}
'''
    open(os.path.join(work, 'fcgi.conf'), 'w').write('fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;\nfastcgi_param QUERY_STRING $query_string;\nfastcgi_param REQUEST_METHOD $request_method;\nfastcgi_param CONTENT_TYPE $content_type;\nfastcgi_param CONTENT_LENGTH $content_length;\nfastcgi_param SCRIPT_NAME $fastcgi_script_name;\nfastcgi_param REQUEST_URI $request_uri;\nfastcgi_param DOCUMENT_URI $document_uri;\nfastcgi_param DOCUMENT_ROOT $document_root;\nfastcgi_param SERVER_PROTOCOL $server_protocol;\nfastcgi_param REMOTE_ADDR $remote_addr;\nfastcgi_param REMOTE_PORT $remote_port;\nfastcgi_param SERVER_ADDR $server_addr;\nfastcgi_param SERVER_PORT $server_port;\nfastcgi_param SERVER_NAME $server_name;\nfastcgi_param HTTP_COOKIE $http_cookie;\nfastcgi_param HTTP_USER_AGENT $http_user_agent;\n')

    # The first start uses a placeholder rule, only to read the rules the gallery writes from its own page.
    def nginx_conf(rule):
        open(os.path.join(work, 'nginx.conf'), 'w').write(base_nginx % {'w': work, 'p': nport, 'www': www, 'sock': sock, 'rule': rule})
    nginx_conf('')
    ng = start([os.path.join(X, 'usr/sbin/nginx'), '-p', work, '-c', os.path.join(work, 'nginx.conf')], {'LD_LIBRARY_PATH': LIBS}, os.path.join(work, 'nginx.out'))
    check('nginx starts', wait_port(nport), open(os.path.join(work, 'nginx.out')).read() if os.path.exists(os.path.join(work, 'nginx.out')) else '')
    site = Site('nginx', nport)
    st, _, _, r = site.post('/trip/index.php?admin', {'access_code': 'rig-admin-pw-1'})
    st, page, _, _ = site.get('/trip/index.php?admin')
    check('the admin opens through nginx', st == 200 and 'id="sl-root"' in page, st)
    nrule = re.search(r'<pre><code>(location ~ [^<]*?index\.php\?link=[^<]*?)</code></pre>', page, re.S)
    arule = re.search(r'<pre><code>(RewriteEngine On(?:(?!</code>)[^<])*?index\.php\?link=(?:(?!</code>)[^<])*?)</code></pre>', page, re.S)
    check('the gallery writes both rules on its Settings page', bool(nrule and arule))
    nrule_t, arule_t = html.unescape(nrule.group(1)), html.unescape(arule.group(1))
    print('  nginx rule:\n    ' + nrule_t.replace('\n', '\n    '))
    # --- restart nginx with the real rule, and check it with nginx -t first
    nginx_conf(nrule_t)
    t = subprocess.run([os.path.join(X, 'usr/sbin/nginx'), '-t', '-p', work, '-c', os.path.join(work, 'nginx.conf')], env=dict(os.environ, LD_LIBRARY_PATH=LIBS), capture_output=True, text=True)
    check('nginx -t accepts the rule the gallery writes', t.returncode == 0, t.stderr)
    stop_all(); procs.clear()
    start([os.path.join(X, 'usr/sbin/php-fpm8.4'), '-y', os.path.join(work, 'fpm.conf'), '-F'], {'PHP_INI_SCAN_DIR': '/etc/php/8.4/cli/conf.d', 'LD_LIBRARY_PATH': LIBS}, os.path.join(work, 'fpm.out'))
    t0 = time.time()
    while not os.path.exists(sock) and time.time() - t0 < 10: time.sleep(0.2)
    start([os.path.join(X, 'usr/sbin/nginx'), '-p', work, '-c', os.path.join(work, 'nginx.conf')], {'LD_LIBRARY_PATH': LIBS}, os.path.join(work, 'nginx.out'))
    wait_port(nport)

    # --- Apache: the same gallery folder, the rule in .htaccess beside the pretty-folder rule
    mods = X + '/usr/lib/apache2/modules'
    aconf = '''ServerRoot "%(w)s/apache"
PidFile "%(w)s/apache/pid"
Mutex file:%(w)s/apache default
DefaultRuntimeDir %(w)s/apache
Listen 127.0.0.1:%(p)d
ServerName localhost
ErrorLog "%(w)s/apache/error.log"
LogLevel warn rewrite:trace3
TypesConfig "%(w)s/apache/mime.types"
''' % {'w': work, 'p': aport}
    for m in ['mpm_event', 'authz_core', 'authz_host', 'dir', 'mime', 'alias', 'env', 'setenvif', 'rewrite', 'proxy', 'proxy_fcgi']:
        aconf += 'LoadModule %s_module %s/mod_%s.so\n' % (m, mods, m)
    aconf += '''DocumentRoot "%(www)s"
<Directory "%(www)s">
  AllowOverride All
  Require all granted
  DirectoryIndex index.php index.html
</Directory>
<FilesMatch "\\.php$">
  SetHandler "proxy:unix:%(sock)s|fcgi://localhost"
</FilesMatch>
''' % {'www': www, 'sock': sock}
    os.makedirs(os.path.join(work, 'apache'), exist_ok=True)
    open(os.path.join(work, 'apache', 'mime.types'), 'w').write('image/jpeg jpg jpeg\ntext/html html\n')
    open(os.path.join(work, 'apache.conf'), 'w').write(aconf)
    open(os.path.join(trip, '.htaccess'), 'w').write('RewriteEngine On\nRewriteRule ^(.+)/$ index.php?folder=$1 [B,L,QSA]\n' + arule_t.replace('RewriteEngine On\n', '', 1))   # (the folder rule is the one from the README, with no RewriteBase of its own)
    start([os.path.join(X, 'usr/sbin/apache2'), '-d', os.path.join(work, 'apache'), '-f', os.path.join(work, 'apache.conf'), '-DFOREGROUND'], {'LD_LIBRARY_PATH': LIBS}, os.path.join(work, 'apache.out'))
    up = wait_port(aport)
    check('Apache starts', up, open(os.path.join(work, 'apache.out')).read() + (open(os.path.join(work, 'apache', 'error.log')).read() if os.path.exists(os.path.join(work, 'apache', 'error.log')) else ''))
    if not up:
        return

    # --- links, made through nginx (the admin session is that server's)
    site = Site('nginx', nport)
    site.post('/trip/index.php?admin', {'access_code': 'rig-admin-pw-1'})
    st, page, _, _ = site.get('/trip/index.php?admin')
    csrf = (re.search(r'csrf_token" value="([a-f0-9]+)"', page) or re.search(r'data-csrf="([^"]+)"', page)).group(1)
    def make(label, address=''):
        f = {'csrf_token': csrf, 'action': 'link_create', 'label': label, 'title_mode': 'gallery', 'picture': '1', 'max_opens': '0', 'ends_at': '0', 'scope_type': 'all', 'locations': 'public', 'heads_up': 'off', 'downloads': 'off', 'zip': 'off', 'address': address}
        import json
        st, body, _, _ = site.post('/trip/index.php?admin', f)
        return json.loads(body)
    a = make('Dyllen and Brenden', 'dyllen-and-brenden'); b = make('Random one'); c = make('Later', 'later-folder')
    check('links are made: a custom name, a random one, and a name a folder will share', a.get('ok') and b.get('ok') and c.get('ok'), (a, b, c))
    tok = re.search(r'/([A-Za-z0-9]{16})$', b['urls']['short']).group(1)
    # a link whose random address was kept working when it got a name of its own
    d = make('Kept one'); tok2 = re.search(r'/([A-Za-z0-9]{16})$', d['urls']['short']).group(1)
    import json
    st, body, _, _ = site.post('/trip/index.php?admin', {'csrf_token': csrf, 'action': 'link_save', 'id': d['id'], 'label': 'Kept one', 'address': 'kept-one-now', 'keep_old': '1', 'title_mode': 'gallery', 'picture': '1', 'max_opens': '0', 'ends_at': '0', 'scope_type': 'all', 'locations': 'public', 'heads_up': 'off', 'downloads': 'off', 'zip': 'off'})
    check('a link is given a name of its own and keeps its random address working', json.loads(body).get('ok'), body[:200])
    # the folder arrives afterwards (an FTP upload), with the same name as the last link
    os.makedirs(os.path.join(trip, 'later-folder')); shutil.copy(os.path.join(trip, 'Rome', 'photo-Rome.jpg'), os.path.join(trip, 'later-folder', 'photo-later-folder.jpg'))

    def reset_throttle():
        for f in glob.glob(os.path.join(trip, '.gallery-login*')): os.unlink(f)   # (wrong tries from one server's checks must not lock the next one out)
    for srv in (Site('nginx', nport), Site('Apache', aport)):
        n = srv.name
        reset_throttle()
        print('-- ' + n + ' --')
        def opening(path): st, body, loc, r = srv.get(path); return st == 200 and 'Open the gallery' in body
        check('[%s] a custom name opens its link, in any case' % n, opening('/trip/dyllen-and-brenden') and opening('/trip/Dyllen-And-BRENDEN'))
        check('[%s] a random address still opens its link' % n, opening('/trip/' + tok))
        st, body, loc, r = srv.get('/trip/' + tok2)
        check('[%s] a kept old address sends people on to the present one, in the same short form (the address bar shows the new one)' % n, st == 302 and loc.endswith('/trip/kept-one-now'), (st, loc))
        check('[%s] ...and the present address opens the link' % n, opening('/trip/kept-one-now'))
        check('[%s] the probe address answers (the admin\'s check that tidy addresses work)' % n, (lambda r: r[0] == 200 and r[3].getheader('X-Gallery-Link') == 'invalid')(srv.get('/trip/0000-probe-0000')))
        st, body, loc, r = srv.get('/trip/Rome')
        if n == 'nginx':
            check('[%s] a real folder typed without its slash is sent on to the folder (by the gallery, in one step)' % n, st == 302 and loc.rstrip('/').endswith('/Rome'), (st, loc))
        fst, fbody, fpath = srv.follow('/trip/Rome')
        check('[%s] ...and the visitor ends on the folder\'s page (%s)' % (n, 'directly' if n == 'nginx' else 'Apache\'s own slash redirect comes first'), fst == 200 and fpath.split('?')[0] == '/trip/Rome/' and 'photo-Rome.jpg' in fbody, (fst, fpath))
        st, body, loc, r = srv.get('/trip/Rome/')
        check('[%s] the folder\'s own address (with the slash) opens the folder' % n, st == 200 and 'photo-Rome.jpg' in body, st)
        lst, lbody, lpath = srv.follow('/trip/later-folder')
        check('[%s] a folder added later with a link\'s name does not take the link\'s address: the link\'s opening page still comes up' % n, lst == 200 and 'Open the gallery' in lbody, (lst, lpath))
        st, body, loc, r = srv.get('/trip/later-folder/')
        check('[%s] ...and the name with a slash is still the folder' % n, st == 200 and 'photo-later-folder' in body and 'Open the gallery' not in body, st)
        for p in ('/trip/admin', '/trip/Admin', '/trip/admin/'):
            st, body, loc, r = srv.get(p)
            check('[%s] %s goes to the sign-in' % (n, p), st == 302 and '?reveal' in loc, (st, loc))
        st, body, loc, r = srv.get('/trip/rome')
        check('[%s] a name that is nothing (and not the folder in another case) is the invalid page, not the site index' % n, st == 404 and 'This link is not valid' in body and 'THE SITE INDEX' not in body, (st, body[:100]))
        st, body, loc, r = srv.get('/trip/notes')
        check('[%s] a file with no extension in the gallery folder is not served as a link' % n, 'plain file' not in body or n == 'Apache')

    if failures and os.environ.get('RIG_DEBUG'):
        print(open(os.path.join(trip, '.htaccess')).read()); print('\n'.join(l[l.index('mod_rewrite.c'):][:330] for l in open(os.path.join(work, 'apache', 'error.log')).read().split('\n') if 'Rome' in l and 'mod_rewrite' in l)[:6000])
    # --- a closed gallery, through each server
    cfg({'CLOSE_PUBLIC_GALLERY': True})
    for srv in (Site('nginx', nport), Site('Apache', aport)):
        n = srv.name
        reset_throttle()
        st, body, loc = srv.follow('/trip/Rome')
        check('[%s, closed] a stranger typing a folder without its slash ends on the invalid page (nothing says the folder exists)' % n, st == 404 and 'This link is not valid' in body and 'photo-Rome' not in body, (st, loc))
        st, body, loc, r = srv.get('/trip/Rome/')
        check('[%s, closed] ...and so does the folder\'s own address' % n, st == 404 and 'This link is not valid' in body)
        st, body, loc, r = srv.get('/trip/')
        check('[%s, closed] ...and the front page' % n, st == 404 and 'This link is not valid' in body)
        st, body, loc, r = srv.get('/trip/admin')
        check('[%s, closed] /trip/admin still goes to the sign-in' % n, st == 302 and '?reveal' in loc, (st, loc))
        check('[%s, closed] a link\'s own name still opens its page' % n, srv.get('/trip/dyllen-and-brenden')[0] == 200)
    # the owner signed in sees the gallery, through nginx
    own = Site('nginx', nport); own.post('/trip/index.php?reveal', {'access_code': 'rig-admin-pw-1'})
    st, body, loc, r = own.get('/trip/')
    check('[nginx, closed] the owner signed in sees the gallery', st == 200 and 'Rome' in body, st)


if __name__ == '__main__':
    main()
