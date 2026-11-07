// The image, gallery and player blocks. Must match the allow-list in Edict\Content\Body.
import { Node } from '@tiptap/core';

const POLL_MS = 2000;

// The smallest version that still looks sharp in the editor.
export const preview = (media) => {
    const variants = media.variants ?? [];

    return (variants.find((variant) => variant.preset === 'poster')
        ?? variants.find((variant) => variant.preset === 'w960-webp')
        ?? variants.find((variant) => variant.preset === 'w480-webp')
        ?? variants.find((variant) => variant.preset === 'full'))?.url ?? null;
};

// Tiptap doesn't give function node views updateAttributes, so this sets them itself.
const attributeSetter = (editor, getPos, getNode) => (attrs) => {
    const position = getPos();
    if (typeof position === 'number') {
        editor.view.dispatch(editor.state.tr.setNodeMarkup(position, undefined, { ...getNode().attrs, ...attrs }));
    }
};

// Waits for stored media to finish processing, and reports on the way.
const whenReady = (id, status, noun, waiting, isGone, onReady) => {
    const look = async () => {
        if (isGone()) {
            return;
        }
        let media = null;
        try {
            const response = await fetch(`/admin/media/${id}`, { credentials: 'same-origin', redirect: 'manual' });
            if (response.status === 404) {
                status.textContent = `This ${noun} no longer exists.`;

                return;
            }
            media = response.ok ? await response.json() : null;
        } catch {
            media = null;
        }

        if (media === null) {
            status.textContent = `Could not load this ${noun}. Trying again…`;
            setTimeout(look, POLL_MS * 3);
        } else if (media.status === 'processing') {
            status.textContent = waiting;
            setTimeout(look, POLL_MS);
        } else if (media.status === 'failed') {
            status.textContent = `This ${noun} could not be processed: ${media.error ?? 'unknown error'}`;
            status.setAttribute('role', 'alert');
        } else {
            status.textContent = '';
            onReady(media);
        }
    };
    look();
};

const showMedia = (id, image, status, isGone) => whenReady(id, status, 'image', 'Processing…', isGone, (media) => {
    image.src = preview(media) ?? '';
    image.hidden = false;
});

// The real thing, so it can be checked before publishing.
const playerFor = (media) => {
    const variant = media.variants.find(({ preset }) => preset === 'h264' || preset === 'aac');
    if (variant === undefined) {
        return null;
    }
    const player = document.createElement(media.kind === 'video' ? 'video' : 'audio');
    player.controls = true;
    player.preload = 'metadata';
    player.src = variant.url;
    if (media.kind === 'video') {
        player.playsInline = true;
        player.poster = preview(media) ?? '';
    }

    return player;
};

const textField = (label, value, onInput) => {
    const wrapper = document.createElement('label');
    wrapper.textContent = label;
    const input = document.createElement('input');
    input.value = value;
    input.maxLength = 500;
    input.addEventListener('input', () => onInput(input.value));
    wrapper.append(input);

    return wrapper;
};

const removeButton = (label, onClick) => {
    const button = document.createElement('button');
    button.type = 'button';
    button.className = 'media-remove';
    button.textContent = '×';
    button.setAttribute('aria-label', label);
    button.addEventListener('click', onClick);

    return button;
};

const imageElement = () => {
    const image = document.createElement('img');
    image.hidden = true;
    image.alt = '';

    return image;
};

const ownEvents = {
    // The fields are ours. ProseMirror must not treat typing in them as editing the document.
    // Except for dropping a file: without the editor's handling, the browser opens it.
    stopEvent: (event) => ['INPUT', 'BUTTON', 'VIDEO', 'AUDIO'].includes(event.target.tagName)
        && !['dragover', 'drop'].includes(event.type),
    ignoreMutation: () => true,
};

export const MediaImage = Node.create({
    name: 'image',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return { mediaId: { default: null }, alt: { default: '' }, caption: { default: '' } };
    },

    parseHTML() {
        return [{
            tag: 'figure[data-media-id]',
            getAttrs: (element) => {
                const mediaId = Number(element.dataset.mediaId);

                return Number.isInteger(mediaId) && mediaId > 0
                    ? { mediaId, alt: element.dataset.alt ?? '', caption: element.dataset.caption ?? '' }
                    : false;
            },
        }];
    },

    renderHTML({ node }) {
        return ['figure', {
            'data-media-id': node.attrs.mediaId,
            'data-alt': node.attrs.alt,
            'data-caption': node.attrs.caption,
        }];
    },

    addNodeView() {
        return ({ node, getPos, editor }) => {
            let gone = false;
            let current = node;
            const updateAttributes = attributeSetter(editor, getPos, () => current);
            const dom = document.createElement('figure');
            dom.className = 'editor-media';
            const image = imageElement();
            const status = document.createElement('p');
            status.setAttribute('role', 'status');

            const altField = textField('Description for people who can\'t see it', node.attrs.alt, (alt) => updateAttributes({ alt }));
            const captionField = textField('Caption (optional)', node.attrs.caption, (caption) => updateAttributes({ caption }));
            dom.append(
                removeButton('Remove this image', () => {
                    const from = getPos();
                    editor.chain().focus().deleteRange({ from, to: from + 1 }).run();
                }),
                image,
                status,
                altField,
                captionField,
            );
            let shownId = node.attrs.mediaId;
            showMedia(shownId, image, status, () => gone || shownId !== current.attrs.mediaId);

            return {
                dom,
                ...ownEvents,
                // Undo and restoring a backup change the node from outside.
                update: (updated) => {
                    if (updated.type.name !== 'image') {
                        return false;
                    }
                    current = updated;
                    altField.querySelector('input').value = updated.attrs.alt;
                    captionField.querySelector('input').value = updated.attrs.caption;
                    if (updated.attrs.mediaId !== shownId) {
                        shownId = updated.attrs.mediaId;
                        image.hidden = true;
                        showMedia(shownId, image, status, () => gone || shownId !== current.attrs.mediaId);
                    }

                    return true;
                },
                destroy: () => {
                    gone = true;
                },
            };
        };
    },
});

// Audio or video. What the file holds decides which, and only processing finds out.
export const MediaPlayer = Node.create({
    name: 'player',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return { mediaId: { default: null }, caption: { default: '' } };
    },

    parseHTML() {
        return [{
            tag: 'figure[data-player-id]',
            getAttrs: (element) => {
                const mediaId = Number(element.dataset.playerId);

                return Number.isInteger(mediaId) && mediaId > 0
                    ? { mediaId, caption: element.dataset.caption ?? '' }
                    : false;
            },
        }];
    },

    renderHTML({ node }) {
        return ['figure', { 'data-player-id': node.attrs.mediaId, 'data-caption': node.attrs.caption }];
    },

    addNodeView() {
        return ({ node, getPos, editor }) => {
            let gone = false;
            let current = node;
            const updateAttributes = attributeSetter(editor, getPos, () => current);
            const dom = document.createElement('figure');
            dom.className = 'editor-media';
            const slot = document.createElement('div');
            const status = document.createElement('p');
            status.setAttribute('role', 'status');
            const captionField = textField('Caption (optional)', node.attrs.caption, (caption) => updateAttributes({ caption }));
            dom.append(
                removeButton('Remove this player', () => {
                    const from = getPos();
                    editor.chain().focus().deleteRange({ from, to: from + 1 }).run();
                }),
                slot,
                status,
                captionField,
            );

            let shownId = node.attrs.mediaId;
            const show = () => whenReady(
                shownId,
                status,
                'file',
                'Processing… A long video can take a while. You can keep writing, and even publish: it appears on the page once it is ready.',
                () => gone || shownId !== current.attrs.mediaId,
                (media) => {
                    const player = playerFor(media);
                    slot.replaceChildren(...(player === null ? [] : [player]));
                },
            );
            show();

            return {
                dom,
                ...ownEvents,
                update: (updated) => {
                    if (updated.type.name !== 'player') {
                        return false;
                    }
                    current = updated;
                    captionField.querySelector('input').value = updated.attrs.caption;
                    if (updated.attrs.mediaId !== shownId) {
                        shownId = updated.attrs.mediaId;
                        slot.replaceChildren();
                        show();
                    }

                    return true;
                },
                destroy: () => {
                    gone = true;
                },
            };
        };
    },
});

export const MediaGallery = Node.create({
    name: 'gallery',
    group: 'block',
    atom: true,
    draggable: true,

    addAttributes() {
        return { items: { default: [] } };
    },

    parseHTML() {
        return [{
            tag: 'div[data-media-gallery]',
            getAttrs: (element) => {
                try {
                    const items = JSON.parse(element.dataset.mediaGallery ?? '[]');

                    return Array.isArray(items) ? { items } : false;
                } catch {
                    return false;
                }
            },
        }];
    },

    renderHTML({ node }) {
        return ['div', { 'data-media-gallery': JSON.stringify(node.attrs.items) }];
    },

    addNodeView() {
        return ({ node, getPos, editor }) => {
            let gone = false;
            let current = node;
            let items = node.attrs.items;
            const updateAttributes = attributeSetter(editor, getPos, () => current);
            const dom = document.createElement('div');
            dom.className = 'editor-gallery';
            dom.setAttribute('role', 'group');
            dom.setAttribute('aria-label', 'Gallery');

            const save = () => updateAttributes({ items: items.map((item) => ({ ...item })) });

            let drawn = 0;
            const draw = () => {
                drawn += 1;
                dom.replaceChildren(removeButton('Remove this gallery', () => {
                    const from = getPos();
                    editor.chain().focus().deleteRange({ from, to: from + 1 }).run();
                }));
                items.forEach((item, position) => {
                    const cell = document.createElement('figure');
                    const image = imageElement();
                    const status = document.createElement('p');
                    status.setAttribute('role', 'status');
                    cell.append(
                        removeButton('Remove this image from the gallery', () => {
                            items = items.filter((_, index) => index !== position);
                            save();
                            draw();
                        }),
                        image,
                        status,
                        textField('Description', item.alt ?? '', (alt) => {
                            items[position] = { ...items[position], alt };
                            save();
                        }),
                    );
                    dom.append(cell);
                    const round = drawn;
                    showMedia(item.mediaId, image, status, () => gone || round !== drawn);
                });
            };
            draw();

            return {
                dom,
                ...ownEvents,
                // Undo and restoring a backup change the node from outside. Typing a
                // description comes back here too, and must not rebuild the fields.
                update: (updated) => {
                    if (updated.type.name !== 'gallery') {
                        return false;
                    }
                    current = updated;
                    if (JSON.stringify(updated.attrs.items) !== JSON.stringify(items)) {
                        items = updated.attrs.items;
                        draw();
                    }

                    return true;
                },
                destroy: () => {
                    gone = true;
                },
            };
        };
    },
});
