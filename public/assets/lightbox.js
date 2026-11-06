// Opens article images in a full-size overlay, with arrows, swipe and the keyboard.
// Without JavaScript each image is a plain link to its full-size file, so this only
// adds comfort.
(() => {
    'use strict';

    const links = [...document.querySelectorAll('a.lightbox')];
    if (links.length === 0 || typeof HTMLDialogElement === 'undefined') {
        return;
    }

    const SWIPE_PIXELS = 50;

    const dialog = document.createElement('dialog');
    dialog.className = 'lightbox-dialog';

    const image = document.createElement('img');
    const button = (label, text, className, action) => {
        const element = document.createElement('button');
        element.type = 'button';
        element.className = className;
        element.setAttribute('aria-label', label);
        element.textContent = text;
        element.addEventListener('click', action);

        return element;
    };

    let index = 0;

    const show = (next) => {
        index = (next + links.length) % links.length;
        const link = links[index];
        image.src = link.href;
        image.alt = link.querySelector('img')?.alt ?? '';
        image.width = Number(link.dataset.width);
        image.height = Number(link.dataset.height);
    };

    const close = button('Close', '×', 'lightbox-close', () => dialog.close());
    dialog.append(image, close);
    if (links.length > 1) {
        dialog.append(
            button('Previous image', '‹', 'lightbox-previous', () => show(index - 1)),
            button('Next image', '›', 'lightbox-next', () => show(index + 1)),
        );
    }
    document.body.append(dialog);

    links.forEach((link, position) => {
        link.addEventListener('click', (event) => {
            // Let people open it in a new tab if they ask for that.
            if (event.ctrlKey || event.metaKey || event.shiftKey || event.button !== 0) {
                return;
            }
            event.preventDefault();
            show(position);
            dialog.showModal();
            close.focus();
        });
    });

    // A click on the dark backdrop closes it. Dialogs close on Escape by themselves.
    dialog.addEventListener('click', (event) => {
        if (event.target === dialog) {
            dialog.close();
        }
    });

    dialog.addEventListener('close', () => {
        links[index].focus();
        image.removeAttribute('src');
    });

    dialog.addEventListener('keydown', (event) => {
        if (links.length > 1 && event.key === 'ArrowLeft') {
            show(index - 1);
        } else if (links.length > 1 && event.key === 'ArrowRight') {
            show(index + 1);
        }
    });

    let startX = null;
    dialog.addEventListener('touchstart', (event) => {
        startX = event.touches.length === 1 ? event.touches[0].clientX : null;
    }, { passive: true });
    dialog.addEventListener('touchend', (event) => {
        if (startX === null || links.length < 2) {
            return;
        }
        const distance = event.changedTouches[0].clientX - startX;
        if (Math.abs(distance) >= SWIPE_PIXELS) {
            show(index + (distance < 0 ? 1 : -1));
        }
        startX = null;
    });
})();
