// Regenerates the PWA PNG icons with zero third-party dependencies.
// Usage: node tools/generate-icons.mjs
import { deflateSync } from 'node:zlib';
import { mkdirSync, writeFileSync } from 'node:fs';
import { dirname, join } from 'node:path';

const OUT = join(process.cwd(), 'public', 'icons');
mkdirSync(OUT, { recursive: true });

const table = new Uint32Array(256);
for (let n = 0; n < 256; n++) {
    let c = n;
    for (let k = 0; k < 8; k++) c = (c & 1) ? 0xedb88320 ^ (c >>> 1) : c >>> 1;
    table[n] = c >>> 0;
}

function crc32(buffer) {
    let c = 0xffffffff;
    for (const byte of buffer) c = table[(c ^ byte) & 0xff] ^ (c >>> 8);
    return (c ^ 0xffffffff) >>> 0;
}

function chunk(type, data) {
    const length = Buffer.alloc(4);
    length.writeUInt32BE(data.length);
    const name = Buffer.from(type, 'ascii');
    const crc = Buffer.alloc(4);
    crc.writeUInt32BE(crc32(Buffer.concat([name, data])));
    return Buffer.concat([length, name, data, crc]);
}

function encodePng(size, rgba) {
    const header = Buffer.alloc(13);
    header.writeUInt32BE(size, 0);
    header.writeUInt32BE(size, 4);
    header[8] = 8;
    header[9] = 6; // RGBA
    const raw = Buffer.alloc((size * 4 + 1) * size);
    for (let y = 0; y < size; y++) {
        raw[y * (size * 4 + 1)] = 0; // filter: none
        rgba.copy(raw, y * (size * 4 + 1) + 1, y * size * 4, (y + 1) * size * 4);
    }
    return Buffer.concat([
        Buffer.from([137, 80, 78, 71, 13, 10, 26, 10]),
        chunk('IHDR', header),
        chunk('IDAT', deflateSync(raw, { level: 9 })),
        chunk('IEND', Buffer.alloc(0)),
    ]);
}

function fillRect(buf, size, x0, y0, x1, y1, color) {
    const left = Math.max(0, Math.floor(Math.min(x0, x1)));
    const right = Math.min(size - 1, Math.ceil(Math.max(x0, x1)));
    const top = Math.max(0, Math.floor(Math.min(y0, y1)));
    const bottom = Math.min(size - 1, Math.ceil(Math.max(y0, y1)));
    for (let y = top; y <= bottom; y++) {
        for (let x = left; x <= right; x++) {
            const i = (y * size + x) * 4;
            buf[i] = color[0]; buf[i + 1] = color[1]; buf[i + 2] = color[2]; buf[i + 3] = 255;
        }
    }
}

function fillRounded(buf, size, x0, y0, x1, y1, radius, color) {
    for (let y = Math.max(0, Math.floor(y0)); y <= Math.min(size - 1, Math.ceil(y1)); y++) {
        for (let x = Math.max(0, Math.floor(x0)); x <= Math.min(size - 1, Math.ceil(x1)); x++) {
            const dx = Math.max(x0 + radius - x, 0, x - (x1 - radius));
            const dy = Math.max(y0 + radius - y, 0, y - (y1 - radius));
            if (dx * dx + dy * dy <= radius * radius) {
                const i = (y * size + x) * 4;
                buf[i] = color[0]; buf[i + 1] = color[1]; buf[i + 2] = color[2]; buf[i + 3] = 255;
            }
        }
    }
}

function render(size, maskable) {
    const buf = Buffer.alloc(size * size * 4);
    const green = [43, 81, 70];
    const paper = [255, 254, 250];
    const ink = [123, 138, 128];
    const gold = [169, 130, 73];

    fillRounded(buf, size, 0, 0, size - 1, size - 1, maskable ? 0 : size * 0.22, green);

    const k = maskable ? 0.72 : 1;
    const ax = (v) => size * (0.5 + (v - 0.5) * k);
    const x0 = ax(0.27), x1 = ax(0.73), y0 = ax(0.20), y1 = ax(0.80);
    fillRounded(buf, size, x0, y0, x1, y1, size * 0.025 * k, paper);

    for (let i = 0; i < 4; i++) {
        const ly = y0 + (y1 - y0) * (0.30 + i * 0.155);
        const start = x0 + (x1 - x0) * 0.15;
        const end = x0 + (x1 - x0) * (i === 3 ? 0.58 : 0.84);
        fillRect(buf, size, start, ly, end, ly + Math.max(2, size * 0.014 * k), ink);
    }

    fillRect(buf, size, x1 - (x1 - x0) * 0.25, y0 - 1, x1 - (x1 - x0) * 0.11, y0 + (y1 - y0) * 0.21, gold);
    return encodePng(size, buf);
}

writeFileSync(join(OUT, 'icon-192.png'), render(192, false));
writeFileSync(join(OUT, 'icon-512.png'), render(512, false));
writeFileSync(join(OUT, 'icon-maskable-512.png'), render(512, true));
console.log('Generated icon-192.png, icon-512.png, icon-maskable-512.png');
