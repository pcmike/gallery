#!/usr/bin/env python3
"""Runs the blocks of Settings -> Server setup -> Web server rules through a real nginx and a real Apache.

The server suite checks the text of the blocks; this checks that the servers accept it and do what it says. It builds a gallery at /photos/,
reads the nginx and Apache blocks from its admin page (exactly as the Copy buttons give them), puts each in front of the gallery
(nginx with `nginx -t` and a live server; Apache with `-t` and a live server, the gallery behind it on PHP's built-in server) and asks for:
a photo by its address (refused), a data file (refused), the gallery, a tidy folder link, a tidy share address, and ?folder= (all reach the gallery).

Needs: php, curl, and the nginx and Apache programs. They are not installed system-wide: this unpacks the Debian packages into
~/.cache/gallery-test-tools/webservers (apt-get download, no root) the first time. Run:  python3 tests/tools/web_server_rules.py
"""
import glob, html, http.cookiejar, json, os, re, shutil, socket, subprocess, sys, tempfile, time, urllib.parse, urllib.request

HERE = os.path.dirname(os.path.abspath(__file__))
INDEX = os.path.abspath(os.path.join(HERE, "..", "..", "index.php"))
CACHE = os.path.expanduser("~/.cache/gallery-test-tools/webservers")
GUARD = "<?php http_response_code(404); exit; ?>\n"


def fetch_packages():
    """apt-get download the packages once and unpack them under CACHE (no root needed)."""
    os.makedirs(CACHE, exist_ok=True)
    for sub, pkgs in (("nginx", ["nginx"]), ("apache", ["apache2-bin", "libapr1t64", "libaprutil1t64", "libaprutil1-dbd-sqlite3", "libaprutil1-ldap"])):
        target = os.path.join(CACHE, sub)
        if os.path.exists(target):
            continue
        dl = tempfile.mkdtemp(prefix="gallery-debs-")
        try:
            subprocess.run(["apt-get", "download"] + pkgs, cwd=dl, check=True, stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
            os.makedirs(target)
            for deb in glob.glob(dl + "/*.deb"):
                subprocess.run(["dpkg", "-x", deb, target], check=True)
        finally:
            shutil.rmtree(dl, ignore_errors=True)


def free_port():
    s = socket.socket(); s.bind(("127.0.0.1", 0)); p = s.getsockname()[1]; s.close(); return p


def curl(port, path):
    out = subprocess.run(["curl", "-s", "-o", "-", "-D", "/dev/stderr", "-w", "\n%{http_code}", f"http://127.0.0.1:{port}{path}"], capture_output=True, text=True)
    body, _, code = out.stdout.rpartition("\n")
    return code.strip(), body, out.stderr


def main():
    fetch_packages()
    W = tempfile.mkdtemp(prefix="gallery-rules-")
    S = W + "/photos"
    os.makedirs(S + "/Rome")
    for p in (S + "/Rome/a.jpg", S + "/top.jpg"):
        subprocess.run(["php", "-r", '$i=imagecreatetruecolor(300,200); imagejpeg($i,$argv[1]);', p], check=True)
    pw = subprocess.run(["php", "-r", "echo password_hash($argv[1], PASSWORD_BCRYPT);", "rules-pw-1234"], capture_output=True, text=True).stdout
    shutil.copy(INDEX, S + "/index.php"); os.utime(S + "/index.php", (time.time() - 3600,) * 2)
    open(S + "/.gallery-config.php", "w").write(GUARD + json.dumps({"ADMIN_PASSWORD": pw, "AUTO_GROUP_BY_FOLDER": True, "ENABLE_SHARE_LINKS": True}) + "\n")
    php_port = free_port()
    php = subprocess.Popen(["php", "-S", f"127.0.0.1:{php_port}", "-t", W], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL)
    procs = [php]; failed = []
    try:
        time.sleep(1.0)
        jar = http.cookiejar.CookieJar()
        op = urllib.request.build_opener(urllib.request.HTTPCookieProcessor(jar))
        base = f"http://127.0.0.1:{php_port}/photos/"
        op.open(urllib.request.Request(base + "?admin", data=urllib.parse.urlencode({"access_code": "rules-pw-1234"}).encode())).read()
        page = op.open(base + "?admin").read().decode("utf-8", "replace")
        block = {}
        for kind in ("nginx", "apache"):
            m = re.search(r'<details class="fold wr-fold" data-wr-fold="%s".*?<pre><code>(.*?)</code></pre>' % kind, page, re.S)
            if not m:
                print("FAIL no %s block on the admin page" % kind); return 1
            block[kind] = html.unescape(m.group(1))

        checks = [
            ("a photo asked for by its address is refused", "/photos/Rome/a.jpg", lambda c, h, b: c in ("403", "404")),
            ("a photo at the gallery's top is refused", "/photos/top.jpg", lambda c, h, b: c in ("403", "404")),
            ("a data file is refused", "/photos/.gallery-config.php", lambda c, h, b: c in ("403", "404")),
            ("the gallery opens", "/photos/", lambda c, h, b: c == "200" and "gallery" in b.lower()),
            ("a tidy folder link opens the folder", "/photos/Rome/", lambda c, h, b: c == "200" and 'name="gallery-folder"' in b),
            ("a tidy share address reaches the gallery", "/photos/0000-probe-0000", lambda c, h, b: c == "200" and "X-Gallery-Link: invalid" in h),
            ("?folder= still works", "/photos/?folder=Rome", lambda c, h, b: c == "200" and 'name="gallery-folder"' in b),
        ]

        def run(label, port):
            for name, path, ok in checks:
                code, body, hdr = curl(port, path)
                good = ok(code, hdr, body)
                print(("PASS " if good else "FAIL ") + label + ": " + name + " (" + code + ")")
                if not good: failed.append(label + ": " + name)

        # --- nginx
        ng = CACHE + "/nginx/usr/sbin/nginx"
        np = free_port()
        open(W + "/ng.conf", "w").write(f"""daemon off; pid {W}/ng.pid; error_log {W}/ng-err.log; events {{ worker_connections 64; }}
http {{ access_log off; client_body_temp_path {W}/t1; proxy_temp_path {W}/t2; fastcgi_temp_path {W}/t3; uwsgi_temp_path {W}/t4; scgi_temp_path {W}/t5;
server {{ listen 127.0.0.1:{np}; root {W}; index index.php;
{block['nginx']}
location ~ \\.php$ {{ proxy_pass http://127.0.0.1:{php_port}; proxy_set_header Host $http_host; }}
}} }}""")
        r = subprocess.run([ng, "-t", "-c", W + "/ng.conf", "-p", W], capture_output=True, text=True)
        print(("PASS " if r.returncode == 0 else "FAIL ") + "nginx accepts the block (nginx -t)")
        if r.returncode != 0: failed.append("nginx -t: " + r.stderr[-300:])
        else:
            p = subprocess.Popen([ng, "-c", W + "/ng.conf", "-p", W], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL); procs.append(p); time.sleep(1.0)
            run("nginx", np); p.terminate(); p.wait(5)

        # --- Apache (the .htaccess is the block as it stands)
        AP = CACHE + "/apache"; M = AP + "/usr/lib/apache2/modules"; ap = AP + "/usr/sbin/apache2"
        apport = free_port()
        open(S + "/.htaccess", "w").write(block["apache"])
        mods = "mpm_prefork authz_core authz_host authn_core access_compat dir mime alias rewrite proxy proxy_http headers".split()
        open(W + "/ap.conf", "w").write(f"""ServerRoot "{W}"
PidFile {W}/ap.pid
ErrorLog {W}/ap-err.log
TypesConfig /dev/null
Listen 127.0.0.1:{apport}
""" + "".join(f"LoadModule {m}_module {M}/mod_{m}.so\n" for m in mods) + f"""DocumentRoot "{W}"
<Directory "{W}">
    AllowOverride All
    Require all granted
</Directory>
DirectoryIndex index.php
ProxyPassMatch "^/(photos/.*\\.php)$" "http://127.0.0.1:{php_port}/$1"
""")
        env = dict(os.environ, LD_LIBRARY_PATH=AP + "/usr/lib/x86_64-linux-gnu")
        r = subprocess.run([ap, "-f", W + "/ap.conf", "-t"], capture_output=True, text=True, env=env)
        print(("PASS " if r.returncode == 0 else "FAIL ") + "Apache accepts the config (apache2 -t)")
        if r.returncode != 0: failed.append("apache -t: " + (r.stderr + r.stdout)[-300:])
        else:
            p = subprocess.Popen([ap, "-f", W + "/ap.conf", "-X"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env); procs.append(p); time.sleep(1.5)
            run("Apache", apport); p.terminate(); p.wait(5)

        # --- Apache without mod_rewrite: the optional part is skipped, the needed part still works (the block's IfModule)
        conf = open(W + "/ap.conf").read().replace(f"LoadModule rewrite_module {M}/mod_rewrite.so\n", "")
        open(W + "/ap2.conf", "w").write(conf)
        p = subprocess.Popen([ap, "-f", W + "/ap2.conf", "-X"], stdout=subprocess.DEVNULL, stderr=subprocess.DEVNULL, env=env); procs.append(p); time.sleep(1.5)
        c1, _, _ = curl(apport, "/photos/Rome/a.jpg"); c2, b2, _ = curl(apport, "/photos/?folder=Rome")
        good = c1 == "403" and c2 == "200"
        print(("PASS " if good else "FAIL ") + "Apache without mod_rewrite: the block is still accepted and the needed rules work (%s, %s)" % (c1, c2))
        if not good: failed.append("apache without mod_rewrite")
        p.terminate(); p.wait(5)
    finally:
        for p in procs:
            try: p.terminate()
            except Exception: pass
        shutil.rmtree(W, ignore_errors=True)
    print("\n" + ("ALL PASSED" if not failed else "FAILED: " + "; ".join(failed)))
    return 1 if failed else 0


if __name__ == "__main__":
    sys.exit(main())
