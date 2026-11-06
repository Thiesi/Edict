// Chunked uploads. A dropped connection retries the chunk, and the server's answer
// says where to continue, so a long upload survives a flaky line.
const RETRIES = 5;
const RETRY_DELAY_MS = 3000;

const wait = (ms) => new Promise((resolve) => {
    setTimeout(resolve, ms);
});

export class UploadError extends Error {}

export class Uploader {
    constructor(csrf, tray) {
        this.csrf = csrf; // A function, because a new session brings a new token.
        this.tray = tray;
        this.active = 0;
    }

    async request(method, url, body, contentType = 'application/json') {
        const response = await fetch(url, {
            method,
            headers: { 'Content-Type': contentType, 'X-CSRF-Token': this.csrf() },
            credentials: 'same-origin',
            redirect: 'manual',
            body,
        });
        const data = await response.json().catch(() => ({}));
        if (response.ok) {
            return data;
        }
        if (response.status >= 400 && response.status < 500) {
            throw new UploadError(data.error ?? 'The server refused this file.');
        }
        if (response.type === 'opaqueredirect') {
            throw new UploadError('You are signed out. Sign in again in another tab, then try this file again.');
        }

        throw new Error(`Server answered ${response.status}`);
    }

    // Retries what can recover: lost connections and server trouble, but not refusals.
    async patiently(work) {
        for (let attempt = 1; ; attempt += 1) {
            try {
                return await work();
            } catch (error) {
                if (error instanceof UploadError || attempt > RETRIES) {
                    throw error;
                }
                await wait(RETRY_DELAY_MS);
            }
        }
    }

    // Resolves with the stored media, or null if it failed and the tray says why.
    async upload(file) {
        const row = document.createElement('li');
        const label = document.createElement('span');
        label.textContent = file.name;
        const bar = document.createElement('progress');
        bar.max = 1;
        bar.value = 0;
        row.append(label, bar);
        this.tray.append(row);
        this.tray.hidden = false;
        this.active += 1;

        try {
            const started = await this.patiently(() => this.request('POST', '/admin/uploads', JSON.stringify({ name: file.name, size: file.size })));
            const url = `/admin/uploads/${started.id}`;

            let offset = 0;
            while (offset < file.size) {
                const from = offset;
                const chunk = file.slice(from, from + started.chunkBytes);
                offset = (await this.patiently(() => this.request('PUT', `${url}?offset=${from}`, chunk, 'application/octet-stream'))).received;
                bar.value = offset / file.size;
            }

            const media = await this.patiently(() => this.request('POST', `${url}/complete`, '{}'));
            row.remove();

            return media;
        } catch (error) {
            bar.remove();
            label.textContent = `${file.name}: ${error instanceof UploadError ? error.message : 'the upload failed. Please try again.'}`;
            label.setAttribute('role', 'alert');

            return null;
        } finally {
            this.active -= 1;
            this.tray.hidden = this.tray.children.length === 0;
        }
    }
}
