// Likes without reloading the page. Without JavaScript the form posts and comes back.
(() => {
    'use strict';

    const form = document.querySelector('form.like-form');
    if (form === null) {
        return;
    }
    const button = form.querySelector('.like-button');
    const count = form.querySelector('.like-count');
    const wanted = form.querySelector('input[name="like"]');
    const token = form.querySelector('input[name="_csrf"]');

    const show = ({ shown, mine }) => {
        button.setAttribute('aria-pressed', String(mine));
        wanted.value = mine ? '0' : '1';
        count.textContent = `${shown} ${shown === 1 ? 'like' : 'likes'}`;
    };

    form.addEventListener('submit', async (event) => {
        event.preventDefault();
        button.disabled = true;
        try {
            const response = await fetch(form.action, {
                method: 'POST',
                headers: { Accept: 'application/json', 'X-CSRF-Token': token.value },
                credentials: 'same-origin',
                redirect: 'manual',
                body: new URLSearchParams({ like: wanted.value }),
            });
            if (response.ok || response.status === 403) {
                show(await response.json());
            } else {
                // A session that ended, for instance: the plain form handles it.
                form.submit();
            }
        } catch {
            form.submit();
        } finally {
            button.disabled = false;
        }
    });
})();
