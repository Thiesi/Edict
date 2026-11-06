import { createHmac } from 'node:crypto';

export const PASSWORD = process.env.E2E_ADMIN_PASSWORD;

const base32 = (text) => {
    const alphabet = 'ABCDEFGHIJKLMNOPQRSTUVWXYZ234567';
    let bits = '';
    for (const char of text.replace(/[\s=]/g, '').toUpperCase()) {
        bits += alphabet.indexOf(char).toString(2).padStart(5, '0');
    }

    return Buffer.from((bits.match(/.{8}/g) ?? []).map((byte) => parseInt(byte, 2)));
};

/**
 * The authenticator code. A code works only once and must be newer than the last,
 * so a second one within the same 30 seconds uses offset 1, which the server accepts.
 */
export const totp = (offset = 0) => {
    const step = Buffer.alloc(8);
    step.writeBigUInt64BE(BigInt(Math.floor(Date.now() / 30000) + offset));
    const hash = createHmac('sha1', base32(process.env.E2E_TOTP_KEY)).update(step).digest();
    const position = hash[hash.length - 1] & 0x0f;

    return String((hash.readUInt32BE(position) & 0x7fffffff) % 1000000).padStart(6, '0');
};
