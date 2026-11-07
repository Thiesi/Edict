// The '/' menu for inserting blocks.
import { Extension } from '@tiptap/core';
import Suggestion from '@tiptap/suggestion';

const BLOCKS = [
    { title: 'Heading 2', run: (chain) => chain.setNode('heading', { level: 2 }) },
    { title: 'Heading 3', run: (chain) => chain.setNode('heading', { level: 3 }) },
    { title: 'Heading 4', run: (chain) => chain.setNode('heading', { level: 4 }) },
    { title: 'Bullet list', run: (chain) => chain.toggleBulletList() },
    { title: 'Numbered list', run: (chain) => chain.toggleOrderedList() },
    { title: 'Quote', run: (chain) => chain.toggleBlockquote() },
    { title: 'Code block', run: (chain) => chain.toggleCodeBlock() },
    { title: 'Divider', run: (chain) => chain.setHorizontalRule() },
    { title: 'Table', run: (chain) => chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }) },
    { title: 'Image, audio or video', action: 'chooseFiles' },
    { title: 'Media library', action: 'openLibrary' },
];

const renderMenu = () => {
    let menu = null;
    let items = [];
    let selected = 0;
    let choose = null;

    const place = (props) => {
        const rect = props.clientRect?.();
        if (rect) {
            menu.style.left = `${rect.left + window.scrollX}px`;
            menu.style.top = `${rect.bottom + window.scrollY + 4}px`;
        }
    };

    const draw = () => {
        menu.replaceChildren(...items.map((item, index) => {
            const button = document.createElement('button');
            button.type = 'button';
            button.role = 'option';
            button.textContent = item.title;
            button.setAttribute('aria-selected', String(index === selected));
            // mousedown, because a click would first move the focus away from the editor.
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(item);
            });

            return button;
        }));
        menu.hidden = items.length === 0;
    };

    return {
        onStart: (props) => {
            menu = document.createElement('div');
            menu.className = 'slash-menu';
            menu.role = 'listbox';
            document.body.append(menu);
            items = props.items;
            selected = 0;
            choose = props.command;
            draw();
            place(props);
        },
        onUpdate: (props) => {
            items = props.items;
            selected = 0;
            choose = props.command;
            draw();
            place(props);
        },
        onKeyDown: ({ event }) => {
            if (items.length === 0) {
                return false;
            }
            if (event.key === 'ArrowDown' || event.key === 'ArrowUp') {
                selected = (selected + (event.key === 'ArrowDown' ? 1 : items.length - 1)) % items.length;
                draw();

                return true;
            }
            if (event.key === 'Enter' || event.key === 'Tab') {
                choose(items[selected]);

                return true;
            }

            return false;
        },
        onExit: () => {
            menu?.remove();
            menu = null;
        },
    };
};

export const SlashCommands = Extension.create({
    name: 'slashCommands',

    addOptions() {
        return { actions: {} };
    },

    addProseMirrorPlugins() {
        const { actions } = this.options;

        return [
            Suggestion({
                editor: this.editor,
                char: '/',
                startOfLine: false,
                items: ({ query }) => BLOCKS.filter((block) => block.title.toLowerCase().includes(query.toLowerCase())),
                command: ({ editor, range, props }) => {
                    if (props.action) {
                        editor.chain().focus().deleteRange(range).run();
                        actions[props.action]();
                    } else {
                        props.run(editor.chain().focus().deleteRange(range)).run();
                    }
                },
                render: renderMenu,
            }),
        ];
    },
});
