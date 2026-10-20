#!/usr/bin/env python3
"""A reference QR code encoder and decoder, used only to check the gallery's own (JavaScript) one.

The gallery draws a QR code for a share link in the browser (the code in index.php's "share kit"). Nothing here is shipped.
This file does the same job independently in Python (byte mode, error correction level H, versions 1 to 20) and can also
read a finished picture of a code back: unmask, un-interleave, check every Reed-Solomon block, return the text. The browser
checks compare the two pictures module for module, and run the decoder over the browser's picture.

    python3 tests/tools/qr/qr_reference.py          # self-tests of the constants, a round trip, then "QR REFERENCE OK"

Built from the QR Code standard (ISO/IEC 18004), written in the same plain way as Project Nayuki's well-known generator.
"""
import sys

# Level H: error correction codewords per block, and the blocks as (count, data codewords) groups, for versions 1-20.
ECC_PER_BLOCK = {1: 17, 2: 28, 3: 22, 4: 16, 5: 22, 6: 28, 7: 26, 8: 26, 9: 24, 10: 28, 11: 24, 12: 28, 13: 22, 14: 24, 15: 24, 16: 30, 17: 28, 18: 28, 19: 26, 20: 28}
BLOCKS = {1: [(1, 9)], 2: [(1, 16)], 3: [(2, 13)], 4: [(4, 9)], 5: [(2, 11), (2, 12)], 6: [(4, 15)], 7: [(4, 13), (1, 14)], 8: [(4, 14), (2, 15)],
          9: [(4, 12), (4, 13)], 10: [(6, 15), (2, 16)], 11: [(3, 12), (8, 13)], 12: [(7, 14), (4, 15)], 13: [(12, 11), (4, 12)], 14: [(11, 12), (5, 13)],
          15: [(11, 12), (7, 13)], 16: [(3, 15), (13, 16)], 17: [(2, 14), (17, 15)], 18: [(2, 14), (19, 15)], 19: [(9, 13), (16, 14)], 20: [(15, 15), (10, 16)]}
MAX_VERSION = 20
MASKS = [lambda x, y: (x + y) % 2 == 0, lambda x, y: y % 2 == 0, lambda x, y: x % 3 == 0, lambda x, y: (x + y) % 3 == 0,
         lambda x, y: (x // 3 + y // 2) % 2 == 0, lambda x, y: x * y % 2 + x * y % 3 == 0, lambda x, y: (x * y % 2 + x * y % 3) % 2 == 0,
         lambda x, y: ((x + y) % 2 + x * y % 3) % 2 == 0]


def raw_codewords(v):
    """All the codewords (data and error correction) a version holds."""
    n = (16 * v + 128) * v + 64
    if v >= 2:
        a = v // 7 + 2
        n -= (25 * a - 10) * a - 55
    if v >= 7:
        n -= 36
    return n // 8


def data_codewords(v):
    return sum(c * d for c, d in BLOCKS[v])


def align_positions(v):
    if v == 1:
        return []
    n = v // 7 + 2
    step = 26 if v == 32 else -(-(v * 4 + 4) // (n * 2 - 2)) * 2
    return [6] + list(range(v * 4 + 10, 6, -step))[:n - 1][::-1]


def format_bits(mask):
    """15 bits: level H (10) and the mask, with their BCH check and the standard's XOR."""
    d = (2 << 3) | mask
    r = d
    for _ in range(10):
        r = (r << 1) ^ ((r >> 9) * 0x537)
    return ((d << 10) | r) ^ 0x5412


def version_bits(v):
    r = v
    for _ in range(12):
        r = (r << 1) ^ ((r >> 11) * 0x1F25)
    return (v << 12) | r


def gf_mul(x, y):
    z = 0
    for i in range(7, -1, -1):
        z = (z << 1) ^ ((z >> 7) * 0x11D)
        z ^= ((y >> i) & 1) * x
    return z


def rs_generator(degree):
    r = [0] * (degree - 1) + [1]
    root = 1
    for _ in range(degree):
        for j in range(degree):
            r[j] = gf_mul(r[j], root)
            if j + 1 < degree:
                r[j] ^= r[j + 1]
        root = gf_mul(root, 2)
    return r


def rs_remainder(data, gen):
    r = [0] * len(gen)
    for b in data:
        f = b ^ r.pop(0)
        r.append(0)
        for i, c in enumerate(gen):
            r[i] ^= gf_mul(c, f)
    return r


def function_map(v):
    """(dark, is_function) grids for a version with everything fixed drawn (finders, timing, alignment, dark module, version info);
    the format bits are reserved (drawn as light) and the data area is free."""
    size = v * 4 + 17
    dark = [[False] * size for _ in range(size)]
    fn = [[False] * size for _ in range(size)]

    def put(x, y, d):
        dark[y][x] = d
        fn[y][x] = True
    for i in range(size):
        put(6, i, i % 2 == 0)
        put(i, 6, i % 2 == 0)
    for cx, cy in ((3, 3), (size - 4, 3), (3, size - 4)):
        for dy in range(-4, 5):
            for dx in range(-4, 5):
                x, y = cx + dx, cy + dy
                if 0 <= x < size and 0 <= y < size:
                    put(x, y, max(abs(dx), abs(dy)) not in (2, 4))
    ap = align_positions(v)
    for i, cx in enumerate(ap):
        for j, cy in enumerate(ap):
            if (i == 0 and j == 0) or (i == 0 and j == len(ap) - 1) or (i == len(ap) - 1 and j == 0):
                continue
            for dy in range(-2, 3):
                for dx in range(-2, 3):
                    put(cx + dx, cy + dy, max(abs(dx), abs(dy)) != 1)
    for i in range(9):          # the format areas
        if i != 6:
            fn[i][8] = fn[8][i] = True
    for i in range(8):
        fn[8][size - 1 - i] = True
        fn[size - 1 - i][8] = True
    put(8, size - 8, True)      # the always-dark module
    if v >= 7:
        bits = version_bits(v)
        for i in range(18):
            a, b = size - 11 + i % 3, i // 3
            put(a, b, bool((bits >> i) & 1))
            put(b, a, bool((bits >> i) & 1))
    return dark, fn


def draw_format(grid, mask):
    size = len(grid)
    b = format_bits(mask)

    def g(i):
        return bool((b >> i) & 1)
    for i in range(6):
        grid[i][8] = g(i)
    grid[7][8] = g(6)
    grid[8][8] = g(7)
    grid[8][7] = g(8)
    for i in range(9, 15):
        grid[8][14 - i] = g(i)
    for i in range(8):
        grid[8][size - 1 - i] = g(i)
    for i in range(8, 15):
        grid[size - 15 + i][8] = g(i)
    grid[size - 8][8] = True


def penalty(g):
    size = len(g)
    p = 0
    for line in list(g) + [list(c) for c in zip(*g)]:
        run = 1
        for a in range(1, size):
            if line[a] == line[a - 1]:
                run += 1
            else:
                if run >= 5:
                    p += 3 + run - 5
                run = 1
        if run >= 5:
            p += 3 + run - 5
        s = ''.join('1' if t else '0' for t in line)
        p += 40 * (s.count('00001011101') + s.count('10111010000'))
    for y in range(size - 1):
        for x in range(size - 1):
            if g[y][x] == g[y][x + 1] == g[y + 1][x] == g[y + 1][x + 1]:
                p += 3
    dark = sum(map(sum, g))
    total = size * size
    k = -(-abs(dark * 20 - total * 10) // total) - 1
    return p + k * 10


def encode(text, mask=None):
    """-> (grid of booleans, version, mask). Raises ValueError when it won't fit in version 20."""
    data = text.encode('utf-8')
    for v in range(1, MAX_VERSION + 1):
        if 4 + (8 if v < 10 else 16) + 8 * len(data) <= data_codewords(v) * 8:
            break
    else:
        raise ValueError('too long')
    bits = []

    def put(val, n):
        bits.extend((val >> i) & 1 for i in range(n - 1, -1, -1))
    put(4, 4)
    put(len(data), 8 if v < 10 else 16)
    for b in data:
        put(b, 8)
    cap = data_codewords(v)
    put(0, min(4, cap * 8 - len(bits)))
    while len(bits) % 8:
        bits.append(0)
    cw = [int(''.join(map(str, bits[i:i + 8])), 2) for i in range(0, len(bits), 8)]
    pad = 0
    while len(cw) < cap:
        cw.append(0xEC if pad % 2 == 0 else 0x11)
        pad += 1
    gen = rs_generator(ECC_PER_BLOCK[v])
    blocks, k = [], 0
    for count, dl in BLOCKS[v]:
        for _ in range(count):
            d = cw[k:k + dl]
            k += dl
            blocks.append((d, rs_remainder(d, gen)))
    out = []
    for i in range(max(len(b[0]) for b in blocks)):
        for d, e in blocks:
            if i < len(d):
                out.append(d[i])
    for i in range(ECC_PER_BLOCK[v]):
        for d, e in blocks:
            out.append(e[i])
    assert len(out) == raw_codewords(v), 'codeword count'
    size = v * 4 + 17
    base, fn = function_map(v)
    allbits = [(c >> i) & 1 for c in out for i in range(7, -1, -1)]
    i, x, up = 0, size - 1, True
    while x > 0:
        if x == 6:
            x -= 1
        for y in (range(size - 1, -1, -1) if up else range(size)):
            for dx in (0, 1):
                xx = x - dx
                if not fn[y][xx]:
                    base[y][xx] = (allbits[i] == 1) if i < len(allbits) else False
                    i += 1
        up = not up
        x -= 2
    best = None
    for m in (range(8) if mask is None else [mask]):
        g = [row[:] for row in base]
        for yy in range(size):
            for xx in range(size):
                if not fn[yy][xx] and MASKS[m](xx, yy):
                    g[yy][xx] = not g[yy][xx]
        draw_format(g, m)
        sc = penalty(g)
        if best is None or sc < best[0]:
            best = (sc, m, g)
    return best[2], v, best[1]


def decode(grid):
    """Read a finished code back to its text, checking every Reed-Solomon block. Raises ValueError on any inconsistency."""
    size = len(grid)
    v = (size - 17) // 4
    if size != v * 4 + 17 or not 1 <= v <= MAX_VERSION:
        raise ValueError('size')
    bits = 0
    for i in range(6):
        bits |= (1 if grid[i][8] else 0) << i
    bits |= (1 if grid[7][8] else 0) << 6
    bits |= (1 if grid[8][8] else 0) << 7
    bits |= (1 if grid[8][7] else 0) << 8
    for i in range(9, 15):
        bits |= (1 if grid[8][14 - i] else 0) << i
    mask = None
    for m in range(8):
        if format_bits(m) == bits:
            mask = m
    if mask is None:
        raise ValueError('format bits')
    # the second copy of the format bits must agree
    b2 = 0
    for i in range(8):
        b2 |= (1 if grid[8][size - 1 - i] else 0) << i
    for i in range(8, 15):
        b2 |= (1 if grid[size - 15 + i][8] else 0) << i
    if b2 != bits:
        raise ValueError('format copies differ')
    if v >= 7:
        vb = 0
        for i in range(18):
            vb |= (1 if grid[size - 11 + i % 3][i // 3] else 0) << i
        if vb != version_bits(v):
            raise ValueError('version bits')
    _, fn = function_map(v)
    bitstream = []
    x, up = size - 1, True
    while x > 0:
        if x == 6:
            x -= 1
        for y in (range(size - 1, -1, -1) if up else range(size)):
            for dx in (0, 1):
                xx = x - dx
                if not fn[y][xx]:
                    bitstream.append(1 if (grid[y][xx] != MASKS[mask](xx, y)) else 0)
        up = not up
        x -= 2
    cws = [int(''.join(map(str, bitstream[i:i + 8])), 2) for i in range(0, len(bitstream) - 7, 8)]
    if len(cws) != raw_codewords(v):
        raise ValueError('codeword count %d != %d' % (len(cws), raw_codewords(v)))
    nblocks = sum(c for c, _ in BLOCKS[v])
    lens = [dl for c, dl in BLOCKS[v] for _ in range(c)]
    ecc = ECC_PER_BLOCK[v]
    data_blocks = [[] for _ in range(nblocks)]
    pos = 0
    for i in range(max(lens)):
        for bi in range(nblocks):
            if i < lens[bi]:
                data_blocks[bi].append(cws[pos])
                pos += 1
    ecc_blocks = [[] for _ in range(nblocks)]
    for i in range(ecc):
        for bi in range(nblocks):
            ecc_blocks[bi].append(cws[pos])
            pos += 1
    gen = rs_generator(ecc)
    for bi in range(nblocks):
        if rs_remainder(data_blocks[bi], gen) != ecc_blocks[bi]:
            raise ValueError('Reed-Solomon check failed in block %d' % bi)
    stream = [b for blk in data_blocks for b in blk]
    bs = ''.join(format(b, '08b') for b in stream)
    if bs[:4] != '0100':
        raise ValueError('not byte mode')
    cl = 8 if v < 10 else 16
    n = int(bs[4:4 + cl], 2)
    body = bs[4 + cl:4 + cl + 8 * n]
    return bytes(int(body[i:i + 8], 2) for i in range(0, len(body), 8)).decode('utf-8')


def selftest():
    # Published values: the format bits of level H with each mask, and the version information of versions 7-12.
    assert [format_bits(m) for m in range(2)] == [0x1689, 0x13BE], [hex(format_bits(m)) for m in range(2)]
    assert [version_bits(v) for v in (7, 8, 9, 10, 11, 12)] == [0x07C94, 0x085BC, 0x09A99, 0x0A4D3, 0x0BBF6, 0x0C762]
    # The block tables add up to the number of codewords each version really holds (the standard's own totals).
    totals = {1: 26, 2: 44, 3: 70, 4: 100, 5: 134, 6: 172, 7: 196, 8: 242, 9: 292, 10: 346, 11: 404, 12: 466, 13: 532, 14: 581, 15: 655, 16: 733,
              17: 815, 18: 901, 19: 991, 20: 1085}
    for v in range(1, MAX_VERSION + 1):
        assert raw_codewords(v) == totals[v], ('raw', v, raw_codewords(v))
        assert sum(c * (d + ECC_PER_BLOCK[v]) for c, d in BLOCKS[v]) == totals[v], ('blocks', v)
    assert align_positions(7) == [6, 22, 38] and align_positions(14) == [6, 26, 46, 66] and align_positions(20) == [6, 34, 62, 90], align_positions(14)
    # Round trips, across every version.
    for n in (5, 20, 40, 41, 56, 64, 80, 100, 120, 150, 200, 260, 300, 380):
        text = ('https://example.org/trip/' + 'Aa1Zz9' * 100)[:n]
        g, v, m = encode(text)
        assert decode(g) == text, ('round trip', n, v)
    g, v, m = encode('https://pcmike.net/trip/k7QpR2mXv9LwTb4e')
    assert v == 5, v
    try:
        encode('x' * 400)
        raise AssertionError('should not fit')
    except ValueError:
        pass
    print('QR REFERENCE OK')


if __name__ == '__main__':
    selftest()
    sys.exit(0)
