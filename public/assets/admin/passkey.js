// Passkey login and registration. The server speaks base64url; the browser's
// WebAuthn API wants ArrayBuffers.
(() => {
    'use strict';

    const toBytes = (text) => Uint8Array.from(atob(text.replace(/-/g, '+').replace(/_/g, '/')), (c) => c.charCodeAt(0));

    const toText = (buffer) => btoa(String.fromCharCode(...new Uint8Array(buffer)))
        .replace(/\+/g, '-').replace(/\//g, '_').replace(/=+$/, '');

    const post = async (url, body, csrf) => {
        const headers = { 'Content-Type': 'application/json' };
        if (csrf) {
            headers['X-CSRF-Token'] = csrf;
        }
        const response = await fetch(url, {
            method: 'POST',
            headers,
            credentials: 'same-origin',
            body: JSON.stringify(body),
        });
        const data = await response.json().catch(() => ({}));
        if (!response.ok) {
            throw new Error(data.error || 'Something went wrong. Please try again.');
        }

        return data;
    };

    // A cancelled browser prompt is a choice, not an error.
    const report = (element, error) => {
        element.textContent = error.name === 'NotAllowedError' ? 'The passkey request was cancelled.' : error.message;
    };

    const logIn = async (button) => {
        const { publicKey } = await post('/admin/passkey/options', {}, button.dataset.csrf);
        publicKey.challenge = toBytes(publicKey.challenge);

        const credential = await navigator.credentials.get({ publicKey });
        const result = await post('/admin/passkey/login', {
            id: toText(credential.rawId),
            clientDataJSON: toText(credential.response.clientDataJSON),
            authenticatorData: toText(credential.response.authenticatorData),
            signature: toText(credential.response.signature),
        }, button.dataset.csrf);

        window.location.assign(result.redirect);
    };

    const register = async (form) => {
        const csrf = form.dataset.csrf;
        const { publicKey } = await post('/admin/passkeys/options', {
            password: form.elements.password.value,
            code: form.elements.code.value,
        }, csrf);
        publicKey.challenge = toBytes(publicKey.challenge);
        publicKey.user.id = toBytes(publicKey.user.id);
        publicKey.excludeCredentials = (publicKey.excludeCredentials || [])
            .map((entry) => ({ ...entry, id: toBytes(entry.id) }));

        const credential = await navigator.credentials.create({ publicKey });
        const result = await post('/admin/passkeys', {
            name: form.elements.name.value,
            clientDataJSON: toText(credential.response.clientDataJSON),
            attestationObject: toText(credential.response.attestationObject),
        }, csrf);

        window.location.assign(result.redirect);
    };

    const supported = 'PublicKeyCredential' in window;

    const button = document.getElementById('passkey-login');
    if (button && supported) {
        const status = document.getElementById('passkey-status');
        button.hidden = false;
        button.addEventListener('click', () => {
            logIn(button).catch((error) => report(status, error));
        });
    }

    const form = document.getElementById('passkey-add');
    if (form) {
        const status = document.getElementById('passkey-status');
        if (!supported) {
            status.textContent = 'This browser does not support passkeys.';
        }
        form.addEventListener('submit', (event) => {
            event.preventDefault();
            register(form).catch((error) => report(status, error));
        });
    }
})();
