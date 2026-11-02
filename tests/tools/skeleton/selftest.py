#!/usr/bin/env python3
"""Does the skeleton round trip understand every way of mapping folders?  (python3 -I tests/tools/skeleton/selftest.py)

Builds three small synthetic galleries (the gallery folder plus mapped folders), takes a skeleton of each with make-skeleton.sh, and runs
build_fixture.py on it:
  A. only EXTRA_FOLDERS (a named place: its folders sit under that name),
  B. only EXTRA_ROOTS (a place whose folders appear at the top),
  C. both, with a name clash (EXTRA_FOLDERS claims "a", so the folder "a" inside the EXTRA_ROOTS place is not mounted).
Each must print RESULT: PASSED, and the plan must find exactly the gallery's own files that are mapped (a copy of the mapping rule once missed
EXTRA_ROOTS, so a real gallery's mapped folders were not tested at all). Exits 0 only if all three pass.
"""
import json, os, re, shutil, subprocess, sys, tempfile

HERE = os.path.dirname(os.path.abspath(__file__))
GUARD = "<?php http_response_code(404); exit; ?>\n"
fails = 0


def ok(cond, msg, detail=""):
    global fails
    print(("  ok    " if cond else "  FAIL  ") + msg + ("" if cond else "  [" + detail + "]"))
    if not cond:
        fails += 1


def photos_folder(path, thumbs=3):
    """a folder with two pictures, its own data files and a few thumbnails"""
    os.makedirs(path + "/.gallery-cache", exist_ok=True)
    for n in ("one.jpg", "two.jpg"):
        open(os.path.join(path, n), "wb").write(b"\xff\xd8 stand-in " + n.encode())
    open(path + "/.gallery-items.php", "w").write(GUARD + json.dumps({"items": [], "groups": []}) + "\n")
    open(path + "/.gallery-cache/meta1.php", "w").write(GUARD + "{}\n")
    for i in range(thumbs):
        open(path + "/.gallery-cache/%032x.jpg" % (i + 1), "wb").write(b"thumb")


def case(name, make, expect_files, expect_thumbs, expect_folders):
    print("== " + name)
    work = tempfile.mkdtemp(prefix="gallery-skeleton-selftest-")
    try:
        gal = work + "/gallery"; os.makedirs(gal)
        mapped = make(work, gal)
        open(gal + "/index.php", "w").write("<?php // stand-in\n")
        out = work + "/out"; os.makedirs(out)
        r = subprocess.run(["sh", HERE + "/make-skeleton.sh", gal] + mapped + ["-o", out], capture_output=True, text=True)
        tar = [f for f in os.listdir(out) if f.endswith(".tar.gz")]
        ok(r.returncode == 0 and len(tar) == 1, "the skeleton is made", r.stderr[-200:])
        if not tar:
            return
        b = subprocess.run([sys.executable, "-I", HERE + "/build_fixture.py", out + "/" + tar[0]], capture_output=True, text=True)
        m = re.search(r"plan: (\d+) files and (\d+) thumbnails \((\d+) folders\); left alone: (\d+)", b.stdout)
        ok(b.returncode == 0 and "RESULT: PASSED" in b.stdout, "the round trip passes (nothing but the gallery's own files goes; the copy restores everything)", b.stdout[-400:] + b.stderr[-200:])
        got = tuple(int(x) for x in m.groups()) if m else None
        ok(got is not None and got[0] == expect_files and got[1] == expect_thumbs and got[2] == expect_folders, "the plan finds exactly the mapped places' own files: %d files, %d thumbnails, %d folders (the gallery folder counts as one)" % (expect_files, expect_thumbs, expect_folders), str(got))
    finally:
        shutil.rmtree(work, ignore_errors=True)


def cfg(gal, d):
    open(gal + "/.gallery-config.php", "w").write(GUARD + json.dumps(d) + "\n")
    open(gal + "/.gallery-items.php", "w").write(GUARD + json.dumps({"items": [], "groups": []}) + "\n")


def make_a(work, gal):
    place = work + "/archive"; photos_folder(place + "/a"); photos_folder(place + "/b"); os.makedirs(place, exist_ok=True)
    cfg(gal, {"AUTO_GROUP_BY_FOLDER": True, "EXTRA_FOLDERS": {"Trip": place}})
    return [place]


def make_b(work, gal):
    place = work + "/archive"; photos_folder(place + "/a"); photos_folder(place + "/b")
    cfg(gal, {"AUTO_GROUP_BY_FOLDER": True, "EXTRA_ROOTS": [place]})
    return [place]


def make_c(work, gal):
    place = work + "/archive"; photos_folder(place + "/a"); photos_folder(place + "/b")
    other = work + "/other"; photos_folder(other)
    cfg(gal, {"AUTO_GROUP_BY_FOLDER": True, "EXTRA_FOLDERS": {"a": other}, "EXTRA_ROOTS": [place]})
    return [place, other]


# the gallery folder has config + items (2 files); each mapped folder with pictures has items + meta (2 files) and 3 thumbnails
case("A. only EXTRA_FOLDERS (named: Trip/a and Trip/b inside)", make_a, 2 + 2 * 2, 2 * 3, 3)
case("B. only EXTRA_ROOTS (a and b at the top)", make_b, 2 + 2 * 2, 2 * 3, 3)
case("C. both, with a name clash (the named 'a' wins; 'a' inside the place is not mounted, only b is)", make_c, 2 + 2 * 2, 2 * 3, 3)
print("\n" + ("ALL SKELETON SELF-TESTS PASSED" if not fails else str(fails) + " SKELETON SELF-TEST(S) FAILED"))
sys.exit(1 if fails else 0)
