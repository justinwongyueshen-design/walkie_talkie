/*
 * qr.js - a small QR code generator (byte mode, error correction level M, versions 1-10).
 * It is enough for URLs up to about 210 characters. No external library, no network.
 *
 * How a QR code is built (simplified):
 *   1. Turn the text into bytes and add a header (mode + length) and padding.
 *   2. Add Reed-Solomon error-correction bytes so damaged codes can still be read.
 *   3. Draw the fixed patterns (3 big squares, timing lines, alignment squares).
 *   4. Place the data bits in a zig-zag, apply a mask pattern, write the format bits.
 *
 * Usage:  WT.drawQr(canvasElement, 'https://example.com/')
 */
(function () {
    'use strict';
    window.WT = window.WT || {};

    // Index = version (1..10). Level M.
    const ECC_PER_BLOCK = [0, 10, 16, 26, 18, 24, 16, 18, 22, 22, 26];
    const NUM_BLOCKS = [0, 1, 1, 1, 2, 2, 4, 4, 4, 5, 5];
    const MAX_VERSION = 10;

    function rawDataModules(ver) {
        let result = (16 * ver + 128) * ver + 64;
        if (ver >= 2) {
            const numAlign = Math.floor(ver / 7) + 2;
            result -= (25 * numAlign - 10) * numAlign - 55;
            if (ver >= 7) result -= 36;
        }
        return result;
    }

    function dataCodewords(ver) {
        return Math.floor(rawDataModules(ver) / 8) - ECC_PER_BLOCK[ver] * NUM_BLOCKS[ver];
    }

    /* ----- Reed-Solomon over GF(256) ----- */
    function gfMul(x, y) {
        let z = 0;
        for (let i = 7; i >= 0; i--) {
            z = (z << 1) ^ ((z >>> 7) * 0x11D);
            z ^= ((y >>> i) & 1) * x;
        }
        return z;
    }

    function rsDivisor(degree) {
        const result = new Array(degree).fill(0);
        result[degree - 1] = 1;
        let root = 1;
        for (let i = 0; i < degree; i++) {
            for (let j = 0; j < degree; j++) {
                result[j] = gfMul(result[j], root);
                if (j + 1 < degree) result[j] ^= result[j + 1];
            }
            root = gfMul(root, 0x02);
        }
        return result;
    }

    function rsRemainder(data, divisor) {
        const result = new Array(divisor.length).fill(0);
        data.forEach((b) => {
            const factor = b ^ result.shift();
            result.push(0);
            divisor.forEach((coef, i) => { result[i] ^= gfMul(coef, factor); });
        });
        return result;
    }

    /* ----- build the data bytes ----- */
    function utf8Bytes(text) { return Array.from(new TextEncoder().encode(text)); }

    function buildData(bytes) {
        let ver = 1;
        while (ver <= MAX_VERSION) {
            const countBits = ver <= 9 ? 8 : 16;
            const needBits = 4 + countBits + bytes.length * 8;
            if (needBits <= dataCodewords(ver) * 8) break;
            ver++;
        }
        if (ver > MAX_VERSION) throw new Error('Text too long for this QR generator');

        const bits = [];
        const put = (value, len) => { for (let i = len - 1; i >= 0; i--) bits.push((value >>> i) & 1); };
        put(0x4, 4);                         // byte mode
        put(bytes.length, ver <= 9 ? 8 : 16); // character count
        bytes.forEach((b) => put(b, 8));

        const capacity = dataCodewords(ver) * 8;
        put(0, Math.min(4, capacity - bits.length)); // terminator
        while (bits.length % 8) bits.push(0);
        for (let pad = 0xEC; bits.length < capacity; pad ^= 0xEC ^ 0x11) put(pad, 8);

        const data = [];
        for (let i = 0; i < bits.length; i += 8) {
            let v = 0;
            for (let j = 0; j < 8; j++) v = (v << 1) | bits[i + j];
            data.push(v);
        }
        return { ver, data };
    }

    /* ----- split into blocks, add error correction, interleave ----- */
    function addEccAndInterleave(ver, data) {
        const numBlocks = NUM_BLOCKS[ver];
        const eccLen = ECC_PER_BLOCK[ver];
        const rawCodewords = Math.floor(rawDataModules(ver) / 8);
        const numShort = numBlocks - (rawCodewords % numBlocks);
        const shortLen = Math.floor(rawCodewords / numBlocks);
        const divisor = rsDivisor(eccLen);

        const blocks = [];
        for (let i = 0, k = 0; i < numBlocks; i++) {
            const len = shortLen - eccLen + (i < numShort ? 0 : 1);
            const dat = data.slice(k, k + len);
            k += len;
            const ecc = rsRemainder(dat, divisor);
            if (i < numShort) dat.push(0); // placeholder so all blocks have equal length
            blocks.push(dat.concat(ecc));
        }

        const result = [];
        for (let i = 0; i < blocks[0].length; i++) {
            blocks.forEach((block, j) => {
                if (i !== shortLen - eccLen || j >= numShort) result.push(block[i]);
            });
        }
        return result;
    }

    /* ----- the module matrix ----- */
    class Matrix {
        constructor(ver) {
            this.ver = ver;
            this.size = ver * 4 + 17;
            this.mod = Array.from({ length: this.size }, () => new Array(this.size).fill(false));
            this.fn = Array.from({ length: this.size }, () => new Array(this.size).fill(false));
        }

        setFn(x, y, dark) {
            this.mod[y][x] = dark;
            this.fn[y][x] = true;
        }

        drawFunctionPatterns() {
            const n = this.size;
            for (let i = 0; i < n; i++) {           // timing patterns
                this.setFn(6, i, i % 2 === 0);
                this.setFn(i, 6, i % 2 === 0);
            }
            this.finder(3, 3);
            this.finder(n - 4, 3);
            this.finder(3, n - 4);

            const pos = this.alignPositions();
            pos.forEach((cy, i) => pos.forEach((cx, j) => {
                const last = pos.length - 1;
                if ((i === 0 && j === 0) || (i === 0 && j === last) || (i === last && j === 0)) return;
                this.alignment(cx, cy);
            }));

            this.drawFormat(0); // reserves the format area (rewritten once the mask is known)
            this.drawVersion();
        }

        finder(cx, cy) {
            for (let dy = -4; dy <= 4; dy++) {
                for (let dx = -4; dx <= 4; dx++) {
                    const dist = Math.max(Math.abs(dx), Math.abs(dy));
                    const x = cx + dx;
                    const y = cy + dy;
                    if (x >= 0 && x < this.size && y >= 0 && y < this.size) this.setFn(x, y, dist !== 2 && dist !== 4);
                }
            }
        }

        alignment(cx, cy) {
            for (let dy = -2; dy <= 2; dy++) {
                for (let dx = -2; dx <= 2; dx++) this.setFn(cx + dx, cy + dy, Math.max(Math.abs(dx), Math.abs(dy)) !== 1);
            }
        }

        alignPositions() {
            const ver = this.ver;
            if (ver === 1) return [];
            const num = Math.floor(ver / 7) + 2;
            const step = Math.ceil((ver * 4 + 4) / (num * 2 - 2)) * 2;
            const result = [6];
            for (let pos = this.size - 7; result.length < num; pos -= step) result.splice(1, 0, pos);
            return result;
        }

        drawFormat(mask) {
            const n = this.size;
            const data = (0 << 3) | mask;        // error-correction level M has format value 0
            let rem = data;
            for (let i = 0; i < 10; i++) rem = (rem << 1) ^ ((rem >>> 9) * 0x537);
            const bits = ((data << 10) | rem) ^ 0x5412;
            const bit = (i) => ((bits >>> i) & 1) !== 0;

            for (let i = 0; i <= 5; i++) this.setFn(8, i, bit(i));
            this.setFn(8, 7, bit(6));
            this.setFn(8, 8, bit(7));
            this.setFn(7, 8, bit(8));
            for (let i = 9; i < 15; i++) this.setFn(14 - i, 8, bit(i));

            for (let i = 0; i < 8; i++) this.setFn(n - 1 - i, 8, bit(i));
            for (let i = 8; i < 15; i++) this.setFn(8, n - 15 + i, bit(i));
            this.setFn(8, n - 8, true);          // the always-dark module
        }

        drawVersion() {
            if (this.ver < 7) return;
            let rem = this.ver;
            for (let i = 0; i < 12; i++) rem = (rem << 1) ^ ((rem >>> 11) * 0x1F25);
            const bits = (this.ver << 12) | rem;
            for (let i = 0; i < 18; i++) {
                const dark = ((bits >>> i) & 1) !== 0;
                const a = this.size - 11 + (i % 3);
                const b = Math.floor(i / 3);
                this.setFn(a, b, dark);
                this.setFn(b, a, dark);
            }
        }

        /** Zig-zag placement of the data bits, two columns at a time, from the bottom right. */
        drawCodewords(codewords) {
            const n = this.size;
            let i = 0;
            for (let right = n - 1; right >= 1; right -= 2) {
                if (right === 6) right = 5;
                for (let vert = 0; vert < n; vert++) {
                    for (let j = 0; j < 2; j++) {
                        const x = right - j;
                        const upward = ((right + 1) & 2) === 0;
                        const y = upward ? n - 1 - vert : vert;
                        if (!this.fn[y][x] && i < codewords.length * 8) {
                            this.mod[y][x] = ((codewords[i >>> 3] >>> (7 - (i & 7))) & 1) !== 0;
                            i++;
                        }
                    }
                }
            }
        }

        applyMask(mask) {
            for (let y = 0; y < this.size; y++) {
                for (let x = 0; x < this.size; x++) {
                    let invert;
                    switch (mask) {
                        case 0: invert = (x + y) % 2 === 0; break;
                        case 1: invert = y % 2 === 0; break;
                        case 2: invert = x % 3 === 0; break;
                        case 3: invert = (x + y) % 3 === 0; break;
                        case 4: invert = (Math.floor(x / 3) + Math.floor(y / 2)) % 2 === 0; break;
                        case 5: invert = ((x * y) % 2) + ((x * y) % 3) === 0; break;
                        case 6: invert = (((x * y) % 2) + ((x * y) % 3)) % 2 === 0; break;
                        default: invert = (((x + y) % 2) + ((x * y) % 3)) % 2 === 0; break;
                    }
                    if (!this.fn[y][x] && invert) this.mod[y][x] = !this.mod[y][x];
                }
            }
        }

        /** Simplified penalty score: long runs and 2x2 blocks make a code harder to scan. */
        penalty() {
            const n = this.size;
            let score = 0;
            const run = (get) => {
                for (let a = 0; a < n; a++) {
                    let len = 1;
                    for (let b = 1; b < n; b++) {
                        if (get(a, b) === get(a, b - 1)) {
                            len++;
                            if (len === 5) score += 3; else if (len > 5) score++;
                        } else len = 1;
                    }
                }
            };
            run((a, b) => this.mod[a][b]);
            run((a, b) => this.mod[b][a]);
            for (let y = 0; y < n - 1; y++) {
                for (let x = 0; x < n - 1; x++) {
                    const c = this.mod[y][x];
                    if (c === this.mod[y][x + 1] && c === this.mod[y + 1][x] && c === this.mod[y + 1][x + 1]) score += 3;
                }
            }
            return score;
        }
    }

    /** Returns a square array of booleans (true = dark module). */
    function encode(text) {
        const { ver, data } = buildData(utf8Bytes(text));
        const codewords = addEccAndInterleave(ver, data);

        const m = new Matrix(ver);
        m.drawFunctionPatterns();
        m.drawCodewords(codewords);

        let best = 0;
        let bestScore = Infinity;
        for (let mask = 0; mask < 8; mask++) {
            m.applyMask(mask);
            m.drawFormat(mask);
            const score = m.penalty();
            if (score < bestScore) { bestScore = score; best = mask; }
            m.applyMask(mask); // undo
        }
        m.applyMask(best);
        m.drawFormat(best);
        return m.mod;
    }

    function drawQr(canvas, text) {
        const modules = encode(text);
        const border = 4;                    // quiet zone required by the QR standard
        const total = modules.length + border * 2;
        const scale = Math.max(1, Math.floor(canvas.width / total));
        canvas.width = canvas.height = total * scale;
        const g = canvas.getContext('2d');
        g.fillStyle = '#ffffff';
        g.fillRect(0, 0, canvas.width, canvas.height);
        g.fillStyle = '#000000';
        modules.forEach((row, y) => row.forEach((dark, x) => {
            if (dark) g.fillRect((x + border) * scale, (y + border) * scale, scale, scale);
        }));
    }

    window.WT.encodeQr = encode;
    window.WT.drawQr = drawQr;
})();
