#!/usr/bin/env python3
"""Rebuild a stand-in of a real gallery from a skeleton made by make-skeleton.sh, and try the "Files this gallery has made" delete on it.

    python3 -I tests/tools/skeleton/build_fixture.py SKELETON.tar.gz [--keep]

What it does, in a scratch folder (nothing of yours is touched; the skeleton holds no photo contents):
  1. rebuilds every folder and file named in the skeleton with a small stand-in file of the same name (a tiny valid JPEG for pictures), puts the
     gallery's own files back with their real contents, drops this repository's index.php in, and points the mapped folders at the rebuilt places;
  2. takes a checksum of every file and link;
  3. builds the plan with the gallery's own code (the owned_* functions are cut out of index.php and run in php), makes the ZIP from it (thumbnails
     included, so the round trip is exact), and runs the delete;
  4. checks: every file that is not the gallery's own is byte for byte the same, only files on the plan are gone, no link was followed;
  5. unzips the ZIP back over the result and checks the whole tree is byte for byte what it was.

It stands in for what the web page does after the password, the typed word and the list check: those gates are tested separately in tests/run.php.
"""
import hashlib, io, json, os, re, shutil, subprocess, sys, tarfile, tempfile, zipfile

HERE = os.path.dirname(os.path.abspath(__file__))
REPO = os.path.abspath(os.path.join(HERE, "..", "..", ".."))
# a 1x1 JPEG, so a stand-in picture is a real picture
JPEG = bytes.fromhex("ffd8ffe000104a46494600010100000100010000ffdb004300080606070605080707070909080a0c140d0c0b0b0c1912130f141d1a1f1e1d1a1c1c20242e2720222c231c1c2837292c30313434341f27393d38323c2e333432ffc0000b080001000101011100ffc4001f0000010501010101010100000000000000000102030405060708090a0bffc400b5100002010303020403050504040000017d01020300041105122131410613516107227114328191a1082342b1c11552d1f02433627282090a161718191a25262728292a3435363738393a434445464748494a535455565758595a636465666768696a737475767778797a838485868788898a92939495969798999aa2a3a4a5a6a7a8a9aab2b3b4b5b6b7b8b9bac2c3c4c5c6c7c8c9cad2d3d4d5d6d7d8d9dae1e2e3e4e5e6e7e8e9eaf1f2f3f4f5f6f7f8f9faffda0008010100003f00fbfd0fffd9")
PIC = (".jpg", ".jpeg", ".png", ".gif", ".webp", ".heic", ".mp4", ".mov")


def sha(path):
    h = hashlib.sha256()
    with open(path, "rb") as f:
        for chunk in iter(lambda: f.read(1 << 20), b""):
            h.update(chunk)
    return h.hexdigest()


def tree(roots):
    """{root label + relative path: checksum, or 'link:target', or 'dir'}"""
    out = {}
    for label, root in roots.items():
        for d, dirs, files in os.walk(root):
            for n in dirs + files:
                p = os.path.join(d, n)
                rel = label + "/" + os.path.relpath(p, root)
                out[rel] = "link:" + os.readlink(p) if os.path.islink(p) else ("dir" if os.path.isdir(p) else sha(p))
    return out


def build(skel, work):
    with tarfile.open(skel) as t:
        t.extractall(os.path.join(work, "skel"), filter="data") if sys.version_info >= (3, 12) else t.extractall(os.path.join(work, "skel"))
    sk = os.path.join(work, "skel")
    roots = {}
    real = {}
    for line in open(os.path.join(sk, "roots.txt")):
        label, path = line.rstrip("\n").split("\t", 1)
        roots[label] = os.path.join(work, "tree", label)
        real[label] = path
        os.makedirs(roots[label], exist_ok=True)
    for label, root in roots.items():
        for line in open(os.path.join(sk, "manifest-%s.txt" % label.replace("root", "root"))):
            parts = line.rstrip("\n").split("|", 4)
            if len(parts) < 5:
                continue
            kind, size, mtime, mode, path = parts
            rel = os.path.relpath(path, real[label]) if path != real[label] else "."
            dest = os.path.join(root, rel)
            if rel == ".":
                continue
            if kind == "directory":
                os.makedirs(dest, exist_ok=True)
            elif kind == "symbolic link":
                pass   # (made below from the symlinks list)
            else:
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                with open(dest, "wb") as f:
                    f.write(JPEG if dest.lower().endswith((".jpg", ".jpeg")) else b"stand-in for " + os.path.basename(dest).encode() + b"\n")
        sl = os.path.join(sk, "symlinks-%s.txt" % label)
        if os.path.exists(sl):
            for line in open(sl):
                if "|" not in line:
                    continue
                path, target = line.rstrip("\n").split("|", 1)
                dest = os.path.join(root, os.path.relpath(path, real[label]))
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                if not os.path.lexists(dest):
                    os.symlink(target, dest)
        # the gallery's own files, with their real contents and dates
        ours = os.path.join(sk, "ours", label)
        for d, _, files in os.walk(ours):
            for n in files:
                src = os.path.join(d, n)
                dest = os.path.join(root, os.path.relpath(src, ours))
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                shutil.copy2(src, dest)
    # this repository's index.php, and the mapped folders pointed at the rebuilt places
    shutil.copy2(os.path.join(REPO, "index.php"), os.path.join(roots["root0"], "index.php"))
    cfg = os.path.join(roots["root0"], ".gallery-config.php")
    if os.path.exists(cfg):
        text = open(cfg).read()
        for label in roots:
            if label != "root0":
                text = text.replace(real[label], roots[label])
        open(cfg, "w").write(text)
    return roots, real


def main():
    if len(sys.argv) < 2:
        print(__doc__); sys.exit(2)
    work = tempfile.mkdtemp(prefix="gallery-skeleton-test-")
    try:
        roots, real = build(sys.argv[1], work)
        before = tree(roots)
        cfgp = os.path.join(roots["root0"], ".gallery-config.php")
        # which folders are mapped: the gallery's own gallery_mounts(), cut out of index.php and run on the rebuilt places, with the
        # skeleton's own settings for EXTRA_FOLDERS and EXTRA_ROOTS (a copy of the rule here once missed EXTRA_ROOTS)
        mounts = {}
        cfg = {}
        if os.path.exists(cfgp):
            raw = open(cfgp).read().split("\n", 1)[-1]
            raw = re.sub(r"^\s*//.*$", "", raw, flags=re.M)
            try:
                cfg = json.loads(raw)
            except Exception as e:
                print("could not read the settings file:", e); sys.exit(1)
        src0 = open(os.path.join(REPO, "index.php")).read()
        ma = src0.index("function gallery_mounts() {"); mb = src0.index("\n}\n", ma) + 3
        def phpj(obj):
            return "json_decode('" + json.dumps(obj).replace("\\", "\\\\").replace("'", "\\'") + "', true)"
        mphp = os.path.join(work, "mounts.php")
        open(mphp, "w").write("<?php\ndefine('EXTRA_FOLDERS', " + phpj(cfg.get("EXTRA_FOLDERS") or {}) + "); define('EXTRA_ROOTS', " + phpj(cfg.get("EXTRA_ROOTS") or []) + ");\n" + src0[ma:mb] + "\necho json_encode((object) gallery_mounts());\n")
        mounts = json.loads(subprocess.run(["php", mphp], check=True, capture_output=True, text=True).stdout or "{}")
        if not mounts:
            print("note: the settings name no mapped folders, so only the gallery folder is tested")
        # the owned_* code, cut out of index.php, with the few helpers it leans on
        src = open(os.path.join(REPO, "index.php")).read()
        a = src.index("/** The files the gallery makes beside"); b = src.index("function files_admin_action")
        code = src[a:b]
        harness = os.path.join(work, "harness.php")
        folders = {}   # gallery path => real path, for every folder that has a picture or a video directly in it
        def scan(real_dir, rel):
            names = sorted(os.listdir(real_dir))
            if any(n.lower().endswith(PIC) and os.path.isfile(os.path.join(real_dir, n)) for n in names):
                folders[rel] = real_dir
            for n in names:
                p = os.path.join(real_dir, n)
                if os.path.isdir(p) and not os.path.islink(p) and not n.startswith("."):
                    scan(p, (rel + "/" if rel else "") + n)
        scan(roots["root0"], "")
        for name, path in mounts.items():
            if os.path.isdir(path):
                scan(path, name)
        folders.pop("", None)
        def phpjson(obj):   # a PHP array from a JSON value, written as a single-quoted string (no $ or \u surprises)
            return "json_decode('" + json.dumps(obj).replace("\\", "\\\\").replace("'", "\\'") + "', true)"
        php = ("<?php\nconst MEDIA_META_FILE = 'meta1.php'; const GALLERY_VERSION = 'x';\n"
               "function data_file_guard_line() { return \"<?php http_response_code(404); exit; ?>\\n\"; }\n"
               "$MAP = " + phpjson(folders) + ";\nfunction all_folder_paths($f) { global $MAP; return array_keys($MAP); }\n"
               "function resolve_real_path($d, $r) { global $MAP; return $MAP[$r] ?? null; }\n"
               "define('EXTRA_FOLDERS', " + phpjson(cfg.get("EXTRA_FOLDERS") or {}) + "); define('EXTRA_ROOTS', " + phpjson(cfg.get("EXTRA_ROOTS") or []) + ");\n" + src0[ma:mb] + "\n" + code +
               "\n$plan = owned_plan(" + phpjson(roots["root0"]) + ", []);\n"
               "$out = ['entries' => array_map(fn ($e) => ['rel' => $e['rel'], 'path' => $e['path'], 'kind' => $e['kind']], $plan['entries']), 'caches' => $plan['caches'], 'unknown' => $plan['unknown'], 'summary' => owned_summary($plan)];\n"
               "file_put_contents(" + phpjson(os.path.join(work, "plan.json")) + ", json_encode($out));\n"
               "if (($argv[1] ?? '') === 'delete') { $r = owned_delete($plan); file_put_contents(" + phpjson(os.path.join(work, "delete.json")) + ", json_encode($r)); }\n")
        open(harness, "w").write(php)
        subprocess.run(["php", harness], check=True)
        plan = json.load(open(os.path.join(work, "plan.json")))
        s = plan["summary"]
        print("plan: %d files and %d thumbnails (%d folders); left alone: %d" % (s["files"], s["thumbs"], s["folders"], len(plan["unknown"])))
        for r in s["rows"]:
            print("   %-52s %5d files  %9d bytes" % (r["label"], r["files"], r["bytes"]))
        for u in plan["unknown"][:10]:
            print("   left alone (looks like ours, is not on the list):", u)
        # the ZIP: every file of the plan and the thumbnails, laid out by gallery path
        label_of = {"": "root0"}
        zpath = os.path.join(work, "copy.zip")
        planned = set()
        with zipfile.ZipFile(zpath, "w") as z:
            for e in plan["entries"]:
                z.write(e["path"], e["rel"]); planned.add(e["path"])
            for c in plan["caches"]:
                for n in sorted(os.listdir(c["path"])):
                    if re.fullmatch(r"[0-9a-f]{32}\.(jpg|mp4)(\.tmp[0-9a-f]{6})?", n):
                        z.write(os.path.join(c["path"], n), c["rel"] + "/" + n); planned.add(os.path.join(c["path"], n))
        subprocess.run(["php", harness, "delete"], check=True)
        res = json.load(open(os.path.join(work, "delete.json")))
        print("delete:", res["text"])
        after = tree(roots)
        bad = []
        planned_rel = set()
        for label, root in roots.items():
            for p in planned:
                if p.startswith(root + "/"):
                    planned_rel.add(label + "/" + os.path.relpath(p, root))
        for k, v in before.items():
            if k in planned_rel:
                if k in after:
                    bad.append("still there: " + k)
            elif after.get(k) != v:
                if v == "dir" and k not in after and k.endswith(".gallery-cache"):
                    continue   # (an emptied .gallery-cache folder goes)
                bad.append("changed or gone and not on the plan: " + k)
        for k in after:
            if k not in before:
                bad.append("appeared: " + k)
        print("every file that is not on the plan is exactly as it was, and only planned files went:", "YES" if not bad else "NO")
        for x in bad[:20]:
            print("   ", x)
        # put it back: unzip over the places each name belongs to
        with zipfile.ZipFile(zpath) as z:
            for n in z.namelist():
                first = n.split("/", 1)[0]
                dest_root = roots["root0"]
                rel = n
                # a folder that is really in the gallery folder wins; otherwise the name is a mapped folder
                if first in mounts and not os.path.isdir(os.path.join(roots["root0"], first)):
                    dest_root = mounts[first]; rel = n.split("/", 1)[1] if "/" in n else n
                dest = os.path.join(dest_root, rel)
                os.makedirs(os.path.dirname(dest), exist_ok=True)
                with open(dest, "wb") as f:
                    f.write(z.read(n))
        again = tree(roots)
        diff = [k for k in set(before) | set(again) if before.get(k) != again.get(k)]
        print("after unzipping the copy over the result, the whole tree is byte for byte what it was:", "YES" if not diff else "NO (%d differences)" % len(diff))
        for x in sorted(diff)[:20]:
            print("   ", x)
        ok = not bad and not diff and res["ok"]
        print("RESULT:", "PASSED" if ok else "FAILED")
        sys.exit(0 if ok else 1)
    finally:
        if "--keep" in sys.argv:
            print("kept:", work)
        else:
            shutil.rmtree(work, ignore_errors=True)


if __name__ == "__main__":
    main()
