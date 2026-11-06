// The full-screen article editor. Text is the one thing that must never get lost, so:
// - every change goes to localStorage at once, and to the server within seconds
// - a failed save keeps retrying and says so on screen
// - an expired session keeps the text and picks up the new token after signing in
// - a save based on an older version is refused rather than overwriting newer text
import './editor.css';

import { Editor } from '@tiptap/core';
import { BubbleMenu } from '@tiptap/extension-bubble-menu';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import StarterKit from '@tiptap/starter-kit';

import { SlashCommands } from './slash.js';

const SAVE_DELAY_MS = 3000;
const RETRY_DELAY_MS = 5000;

const app = document.getElementById('editor-app');
const state = JSON.parse(app.dataset.state);
const backupKey = `edict-draft-${state.id}`;
const field = (id) => document.getElementById(id);

const fields = {
    title: field('title'),
    slug: field('slug'),
    lang: field('lang'),
    teaser: field('teaser'),
    tags: field('tags'),
};

const statusLine = field('save-status');
const errorLine = field('editor-errors');

// Only what Edict\Content\Body allows. Adding a block type means changing both.
const editor = new Editor({
    element: field('body'),
    content: state.body,
    extensions: [
        StarterKit.configure({
            heading: { levels: [2, 3, 4] },
            underline: false,
            link: { openOnClick: false, autolink: true, HTMLAttributes: { rel: 'noopener', target: null } },
        }),
        TableKit.configure({ table: { resizable: false } }),
        Placeholder.configure({ placeholder: "Write, or type '/' for blocks" }),
        BubbleMenu.configure({ element: field('bubble-menu') }),
        SlashCommands,
    ],
    onUpdate: () => bodyUpdated(),
    onSelectionUpdate: () => {
        field('table-tools').hidden = !editor.isActive('table');
    },
});

const draft = () => ({
    title: fields.title.value,
    slug: fields.slug.value,
    lang: fields.lang.value,
    teaser: fields.teaser.value,
    tags: fields.tags ? fields.tags.value : '',
    body: editor.getJSON(),
});

/* Saving */

let dirty = false;
let saving = null;
let timer = null;
let stopped = false;

const setStatus = (text) => {
    statusLine.textContent = text;
};

const backup = () => {
    try {
        localStorage.setItem(backupKey, JSON.stringify({ version: state.version, draft: draft() }));
    } catch {
        // Private windows may refuse. The server save is the real one.
    }
};

const clearBackup = () => {
    try {
        localStorage.removeItem(backupKey);
    } catch {
        // See backup().
    }
};

const schedule = (delay) => {
    clearTimeout(timer);
    timer = setTimeout(save, delay);
};

// TipTap normalises a document as soon as the cursor is placed (a trailing paragraph,
// say). That isn't an edit: only content differing from what was last seen counts.
let lastBody = null;

function bodyUpdated() {
    const body = JSON.stringify(editor.getJSON());
    if (lastBody !== null && body !== lastBody) {
        lastBody = body;
        changed();
    }
}

function changed() {
    dirty = true;
    backup();
    if (!stopped) {
        setStatus('Unsaved changes');
        schedule(SAVE_DELAY_MS);
    }
}

const post = (path, body) => fetch(`/admin/articles/${state.id}/${path}`, {
    method: 'POST',
    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': state.csrf },
    credentials: 'same-origin',
    redirect: 'manual',
    body: JSON.stringify(body),
});

// A session that ended redirects (status 0 here), and a new one has a new token.
const refreshToken = async () => {
    const response = await fetch('/admin/csrf', { credentials: 'same-origin', redirect: 'manual' });
    if (!response.ok) {
        return false;
    }
    state.csrf = (await response.json()).csrf;

    return true;
};

async function attemptSave() {
    dirty = false;
    let response;
    try {
        setStatus('Saving…');
        response = await post('autosave', { version: state.version, draft: draft() });
    } catch {
        dirty = true;
        setStatus('Not saved: no connection. Retrying…');
        schedule(RETRY_DELAY_MS);

        return;
    }

    if (response.ok) {
        state.version = (await response.json()).version;
        if (dirty) {
            backup(); // Typed during the save: keep the newer text under the new version.
            schedule(SAVE_DELAY_MS);
        } else {
            clearBackup();
            setStatus(`Saved ${new Date().toLocaleTimeString()}`);
        }

        return;
    }

    dirty = true;
    if (response.status === 409) {
        stopped = true;
        setStatus('Not saved: this changed in another tab. Your text is kept here. Copy it, then reload.');

        return;
    }
    if (response.status === 403 || response.type === 'opaqueredirect') {
        const signedIn = await refreshToken().catch(() => false);
        setStatus(signedIn
            ? 'Not saved yet. Retrying…'
            : 'Not saved: you are signed out. Sign in again in another tab and keep this one open.');
    } else {
        setStatus('Not saved: the server had a problem. Retrying…');
    }
    schedule(RETRY_DELAY_MS);
}

function save() {
    clearTimeout(timer);
    if (saving === null && dirty && !stopped) {
        saving = attemptSave().finally(() => {
            saving = null;
        });
    }

    return saving ?? Promise.resolve();
}

// Saves until nothing is pending, or reports that it can't.
const flush = async () => {
    await saving;
    if (dirty) {
        await save();
    }

    return !dirty;
};

Object.values(fields).forEach((input) => input?.addEventListener('input', changed));

window.addEventListener('beforeunload', (event) => {
    if (dirty || saving !== null) {
        event.preventDefault();
    }
});

/* Text that never reached the server */

const offerRestore = () => {
    let saved = null;
    try {
        saved = JSON.parse(localStorage.getItem(backupKey));
    } catch {
        return;
    }
    if (saved === null) {
        return;
    }

    const banner = field('restore-banner');
    banner.hidden = false;
    field('restore-yes').addEventListener('click', () => {
        banner.hidden = true;
        Object.entries(fields).forEach(([name, input]) => {
            if (input && typeof saved.draft[name] === 'string') {
                input.value = saved.draft[name];
            }
        });
        editor.commands.setContent(saved.draft.body);
        changed();
    });
    field('restore-no').addEventListener('click', () => {
        banner.hidden = true;
        clearBackup();
    });
};

offerRestore();

/* Toolbar */

const commands = {
    bold: () => editor.chain().focus().toggleBold().run(),
    italic: () => editor.chain().focus().toggleItalic().run(),
    strike: () => editor.chain().focus().toggleStrike().run(),
    code: () => editor.chain().focus().toggleCode().run(),
    h2: () => editor.chain().focus().toggleHeading({ level: 2 }).run(),
    h3: () => editor.chain().focus().toggleHeading({ level: 3 }).run(),
    quote: () => editor.chain().focus().toggleBlockquote().run(),
    link: () => {
        const url = window.prompt('Link address (empty removes the link)', editor.getAttributes('link').href ?? '');
        if (url === null) {
            return;
        }
        const chain = editor.chain().focus().extendMarkRange('link');
        (url === '' ? chain.unsetLink() : chain.setLink({ href: url })).run();
    },
};

field('bubble-menu').addEventListener('click', (event) => {
    const button = event.target.closest('[data-command]');
    if (button) {
        commands[button.dataset.command]();
    }
});

field('table-tools').addEventListener('click', (event) => {
    const button = event.target.closest('[data-table]');
    if (button) {
        editor.chain().focus()[button.dataset.table]().run();
    }
});

field('fullscreen').addEventListener('click', () => {
    if (document.fullscreenElement) {
        document.exitFullscreen();
    } else {
        document.documentElement.requestFullscreen().catch(() => {
            setStatus('This browser refused full screen.');
        });
    }
});

/* Preview and publish: both act on the stored draft, so they save first */

field('preview').addEventListener('click', async (event) => {
    event.preventDefault();
    const tab = window.open('about:blank');
    await flush();
    if (tab) {
        tab.location = event.target.href;
    }
});

const showErrors = (messages) => {
    errorLine.textContent = messages.join(' ');
    errorLine.hidden = messages.length === 0;
    if (messages.length > 0 && fields.slug) {
        document.querySelector('.editor-details').open = true;
    }
};

field('publish')?.addEventListener('click', async () => {
    showErrors([]);
    if (!(await flush())) {
        showErrors(['Publishing has to wait until the text is saved.']);

        return;
    }

    let response;
    try {
        response = await post('publish', { version: state.version });
    } catch {
        showErrors(['No connection. Nothing was published.']);

        return;
    }

    const data = await response.json().catch(() => ({}));
    if (response.ok) {
        window.location.assign(data.redirect);
    } else if (data.errors) {
        showErrors(Object.values(data.errors));
    } else {
        showErrors([data.error ?? 'Nothing was published. Please reload the page and try again.']);
    }
});

editor.commands.focus('end');
lastBody = JSON.stringify(editor.getJSON());
