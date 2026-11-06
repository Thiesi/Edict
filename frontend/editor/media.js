// The image and gallery blocks. Must match the allow-list in Edict\Content\Body.
import { Node } from '@tiptap/core';

const POLL_MS = 2000;

// The smallest version that still looks sharp in the editor.
export const preview = (media) => {
    const variants = media.variants ?? [];

    return (variants.find((variant) => variant.preset === 'w960-webp')
        ?? variants.find((variant) => variant.preset === 'w480-webp')
        ?? variants.find((variant) => variant.preset === 'full'))?.url ?? null;
};

// Shows a stored image, and keeps asking until its processing has finished.
const showMedia = (id, image, status, isGone) => {
    const look = async () => {
        if (isGone()) {
            return;
        }
        let media = null;
        try {
            const response = await fetch(`/admin/media/${id}`, { credentials: 'same-origin', redirect: 'manual' });
            media = response.ok ? await response.json() : null;
        } catch {
            media = null;
        }

        if (media === null) {
            status.textContent = 'Could not load this image. Trying again…';
            setTimeout(look, POLL_MS * 3);
        } else if (media.status === 'processing') {
            status.textContent = 'Processing…';
            setTimeout(look, POLL_MS);
        } else if (media.status === 'failed') {
            status.textContent = `This image could not be processed: ${media.error ?? 'unknown error'}`;
            status.setAttribute('role', 'alert');
        } else {
            status.textContent = '';
            image.src = preview(media) ?? '';
            image.hidden = false;
        }
    };
    look();
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
    stopEvent: (event) => ['INPUT', 'BUTTON'].includes(event.target.tagName),
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
            getAttrs: (element) => ({
                mediaId: Number(element.dataset.mediaId),
                alt: element.dataset.alt ?? '',
                caption: element.dataset.caption ?? '',
            }),
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
        return ({ node, getPos, editor, updateAttributes }) => {
            let gone = false;
            const dom = document.createElement('figure');
            dom.className = 'editor-media';
            const image = imageElement();
            const status = document.createElement('p');
            status.setAttribute('role', 'status');

            dom.append(
                removeButton('Remove this image', () => {
                    const from = getPos();
                    editor.chain().focus().deleteRange({ from, to: from + 1 }).run();
                }),
                image,
                status,
                textField('Description for people who can\'t see it', node.attrs.alt, (alt) => updateAttributes({ alt })),
                textField('Caption (optional)', node.attrs.caption, (caption) => updateAttributes({ caption })),
            );
            showMedia(node.attrs.mediaId, image, status, () => gone);

            return {
                dom,
                ...ownEvents,
                update: (updated) => updated.type.name === 'image',
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
            getAttrs: (element) => ({ items: JSON.parse(element.dataset.mediaGallery ?? '[]') }),
        }];
    },

    renderHTML({ node }) {
        return ['div', { 'data-media-gallery': JSON.stringify(node.attrs.items) }];
    },

    addNodeView() {
        return ({ node, getPos, editor, updateAttributes }) => {
            let gone = false;
            let items = node.attrs.items;
            const dom = document.createElement('div');
            dom.className = 'editor-gallery';
            dom.setAttribute('role', 'group');
            dom.setAttribute('aria-label', 'Gallery');

            const save = () => updateAttributes({ items: items.map((item) => ({ ...item })) });

            const draw = () => {
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
                    showMedia(item.mediaId, image, status, () => gone);
                });
            };
            draw();

            return {
                dom,
                ...ownEvents,
                update: (updated) => updated.type.name === 'gallery',
                destroy: () => {
                    gone = true;
                },
            };
        };
    },
});
