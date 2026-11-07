// The article editor. Text is the one thing that must never get lost, so:
// - every change goes to localStorage at once, and to the server within seconds
// - a failed save keeps retrying and says so on screen
// - an expired session keeps the text and picks up the new token after signing in
// - a save based on an older version is refused rather than overwriting newer text
import './editor.css';

import { Editor } from '@tiptap/core';
import { BubbleMenu } from '@tiptap/extension-bubble-menu';
import { TableKit } from '@tiptap/extension-table';
import { Placeholder } from '@tiptap/extensions';
import { TextSelection } from '@tiptap/pm/state';
import StarterKit from '@tiptap/starter-kit';

import { MediaGallery, MediaImage, preview } from './media.js';
import { SlashCommands } from './slash.js';
import { Uploader } from './uploads.js';

const SAVE_DELAY_MS = 3000;
const RETRY_DELAY_MS = 5000;

const app = document.getElementById('editor-app');
const state = JSON.parse(app.dataset.state);
// Each open editor keeps its own backup, so one tab's save can't remove another's text.
const writer = crypto.randomUUID();
const backupPrefix = `edict-draft-${state.id}-`;
const backupKey = backupPrefix + writer;
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
        MediaImage,
        MediaGallery,
        SlashCommands.configure({
            actions: {
                chooseFiles: () => field('file-input').click(),
                openLibrary: () => openLibrary(),
            },
        }),
    ],
    editorProps: {
        // Word and Excel put a picture of the text on the clipboard as well. Text wins.
        handlePaste: (_view, event) => !event.clipboardData?.types.includes('text/html')
            && receiveFiles(event.clipboardData?.files, editor.state.selection.to),
        handleDrop: (view, event) => receiveFiles(
            event.dataTransfer?.files,
            view.posAtCoords({ left: event.clientX, top: event.clientY })?.pos ?? editor.state.selection.to,
        ),
    },
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
        localStorage.setItem(backupKey, JSON.stringify({ version: state.version, savedAt: Date.now(), draft: draft() }));
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
    document.querySelectorAll('input[name="_csrf"]').forEach((input) => {
        input.value = state.csrf;
    });

    return true;
};

async function attemptSave() {
    dirty = false;
    let response;
    try {
        setStatus('Saving…');
        response = await post('autosave', { version: state.version, writer, draft: draft() });
    } catch {
        dirty = true;
        setStatus('Not saved: no connection. Retrying…');
        schedule(RETRY_DELAY_MS);

        return;
    }

    if (response.ok) {
        const saved = await response.json().catch(() => null);
        if (saved === null) {
            dirty = true;
            setStatus('Not saved: the server had a problem. Retrying…');
            schedule(RETRY_DELAY_MS);

            return;
        }
        state.version = saved.version;
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
    backup();
    const failure = await response.clone().json().catch(() => ({}));
    if (response.status === 409) {
        stopped = true;
        setStatus(`Not saved: ${failure.error ?? 'this changed elsewhere.'} Your text is kept here. Copy it, then reload.`);

        return;
    }
    if (response.status === 400 || response.status === 413 || response.status === 422) {
        // Retrying can't help. The next edit tries again, and the text stays here.
        setStatus(`Not saved: ${failure.error ?? 'the server refused this text.'}`);

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
    if (dirty || saving !== null || uploader.active > 0) {
        event.preventDefault();
    }
});

/* Text that never reached the server */

const readBackups = () => {
    const backups = [];
    try {
        for (let i = 0; i < localStorage.length; i += 1) {
            const key = localStorage.key(i);
            if (key.startsWith(backupPrefix) && key !== backupKey) {
                backups.push({ key, ...JSON.parse(localStorage.getItem(key)) });
            }
        }
    } catch {
        return [];
    }

    return backups.sort((a, b) => (b.savedAt ?? 0) - (a.savedAt ?? 0));
};

const plainText = (node) => {
    if (node.type === 'text') {
        return node.text;
    }
    const inner = (node.content ?? []).map(plainText);

    return inner.join(node.type === 'doc' || node.type === 'bulletList' || node.type === 'orderedList' ? '\n\n' : '');
};

const removeBackup = (key) => {
    try {
        localStorage.removeItem(key);
    } catch {
        // See backup().
    }
};

// An offer stays until it is answered: typing on or saving doesn't touch these backups.
let offered = null;

const offerNextBackup = () => {
    [offered = null] = readBackups();
    field('restore-banner').hidden = offered === null;
    if (offered === null) {
        return;
    }

    const stale = offered.version !== state.version;
    field('restore-message').textContent = stale
        ? 'Unsaved text from another tab or an earlier session exists, but this article has changed since. Check it before restoring: it replaces what is here.'
        : 'Unsaved text from another tab or an earlier session exists.';
    field('restore-text').textContent = [offered.draft.title, plainText(offered.draft.body)].filter(Boolean).join('\n\n');
};

field('restore-yes').addEventListener('click', () => {
    Object.entries(fields).forEach(([name, input]) => {
        if (input && typeof offered.draft[name] === 'string') {
            input.value = offered.draft[name];
        }
    });
    editor.commands.setContent(offered.draft.body);
    removeBackup(offered.key);
    changed();
    offerNextBackup();
});

field('restore-no').addEventListener('click', () => {
    removeBackup(offered.key);
    offerNextBackup();
});

offerNextBackup();

/* Images */

const uploader = new Uploader(() => state.csrf, refreshToken, field('upload-tray'));

// Where uploads will land, moved along as the text changes during a long upload.
const landingSpots = new Set();
editor.on('transaction', ({ transaction }) => {
    landingSpots.forEach((spot) => {
        spot.position = transaction.mapping.map(spot.position);
    });
});

const insertImages = (ids, position) => {
    const content = ids.length === 1
        ? { type: 'image', attrs: { mediaId: ids[0] } }
        : { type: 'gallery', attrs: { items: ids.map((mediaId) => ({ mediaId, alt: '' })) } };
    // Whoever is filling in a title or description while a long upload finishes keeps typing there.
    if (document.activeElement?.matches('input:not([type="file"]), textarea')) {
        editor.commands.insertContentAt(position, content, { updateSelection: false });

        return;
    }
    editor.chain().focus().insertContentAt(position, content).run();
    // Inserting leaves the block selected, and the next key would replace it.
    const below = TextSelection.findFrom(editor.state.doc.resolve(editor.state.selection.to), 1, true);
    if (below) {
        editor.view.dispatch(editor.state.tr.setSelection(below).scrollIntoView());
    }
};

// One image becomes an image block, and several at once become a gallery. One at a
// time, so a whole folder doesn't saturate the line or reserve the disk all at once.
const uploadAll = async (files, spot) => {
    landingSpots.add(spot);
    const uploaded = [];
    for (const file of files) {
        uploaded.push(await uploader.upload(file));
    }
    landingSpots.delete(spot);

    const images = uploaded.filter((media) => media?.kind === 'image');
    if (images.length < uploaded.filter(Boolean).length) {
        const note = document.createElement('li');
        note.setAttribute('role', 'alert');
        note.textContent = 'Only images can go into an article so far. The other files were kept in the library.';
        field('upload-tray').append(note);
        field('upload-tray').hidden = false;
    }
    if (images.length > 0) {
        insertImages(images.map((media) => media.id), spot.position);
    }
};

function receiveFiles(list, position) {
    const files = [...(list ?? [])];
    if (files.length === 0) {
        return false;
    }
    uploadAll(files, { position });

    return true;
}

field('file-input').addEventListener('change', (event) => {
    receiveFiles(event.target.files, editor.state.selection.to);
    event.target.value = '';
});

/* The media library */

const panel = field('media-panel');
const grid = field('media-grid');
const toggle = field('media-toggle');
const insertButton = field('media-insert');
const moreButton = field('media-more');
let libraryPage = 1;
let chosen = [];

const addToLibrary = (media) => {
    const url = preview(media);
    if (media.kind !== 'image' || media.status !== 'ready' || url === null) {
        return;
    }
    const button = document.createElement('button');
    button.type = 'button';
    button.dataset.mediaId = media.id;
    button.title = media.name;
    button.setAttribute('aria-pressed', 'false');
    const image = document.createElement('img');
    image.src = url;
    image.alt = media.name;
    image.loading = 'lazy';
    button.append(image);
    button.addEventListener('click', () => {
        const selected = chosen.includes(media.id);
        chosen = selected ? chosen.filter((id) => id !== media.id) : [...chosen, media.id];
        button.setAttribute('aria-pressed', String(!selected));
        insertButton.disabled = chosen.length === 0;
    });
    grid.append(button);
};

const loadLibrary = async () => {
    const response = await fetch(`/admin/media?page=${libraryPage}`, { credentials: 'same-origin', redirect: 'manual' });
    if (!response.ok) {
        setStatus('The media library could not be loaded.');

        return;
    }
    const { media } = await response.json();
    media.forEach(addToLibrary);
    moreButton.hidden = media.length === 0;
    libraryPage += 1;
};

function openLibrary() {
    panel.hidden = false;
    toggle.setAttribute('aria-expanded', 'true');
    grid.replaceChildren();
    chosen = [];
    insertButton.disabled = true;
    libraryPage = 1;
    loadLibrary();
}

toggle.addEventListener('click', () => {
    if (panel.hidden) {
        openLibrary();
    } else {
        panel.hidden = true;
        toggle.setAttribute('aria-expanded', 'false');
    }
});
moreButton.addEventListener('click', loadLibrary);
field('media-upload').addEventListener('click', () => field('file-input').click());
insertButton.addEventListener('click', () => {
    if (chosen.length > 0) {
        insertImages(chosen, editor.state.selection.to);
    }
    grid.querySelectorAll('[aria-pressed="true"]').forEach((button) => button.setAttribute('aria-pressed', 'false'));
    chosen = [];
    insertButton.disabled = true;
});

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
        const entered = window.prompt('Link address (empty removes the link)', editor.getAttributes('link').href ?? '');
        if (entered === null) {
            return;
        }
        const url = entered.trim();
        // The same rules as Edict\\Content\\Body, which would drop anything else on saving.
        if (url !== '' && !/^(https?:\/\/|mailto:|tel:|#|www\.|\/(?!\/))/i.test(url)) {
            setStatus('That link needs to start with https://, www., mailto:, tel:, # or /.');

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

/* Preview and publish: both act on the stored draft, so they save first */

field('preview').addEventListener('click', async (event) => {
    event.preventDefault();
    const tab = window.open('about:blank');
    if (!(await flush())) {
        tab?.close();
        setStatus('The preview has to wait until the text is saved.');

        return;
    }
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
