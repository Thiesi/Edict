// The '/' menu for inserting blocks.
import { Extension } from '@tiptap/core';
import Suggestion from '@tiptap/suggestion';

// "hint" is what typing at the start of a line does the same thing, where there is such a shortcut.
const BLOCKS = [
    { group: 'Text', title: 'Heading 2', hint: '##', run: (chain) => chain.setNode('heading', { level: 2 }) },
    { group: 'Text', title: 'Heading 3', hint: '###', run: (chain) => chain.setNode('heading', { level: 3 }) },
    { group: 'Text', title: 'Heading 4', hint: '####', run: (chain) => chain.setNode('heading', { level: 4 }) },
    { group: 'Text', title: 'Bullet list', hint: '-', run: (chain) => chain.toggleBulletList() },
    { group: 'Text', title: 'Numbered list', hint: '1.', run: (chain) => chain.toggleOrderedList() },
    { group: 'Text', title: 'Quote', hint: '>', run: (chain) => chain.toggleBlockquote() },
    { group: 'Text', title: 'Code block', hint: '```', run: (chain) => chain.toggleCodeBlock() },
    { group: 'Blocks', title: 'Divider', hint: '---', run: (chain) => chain.setHorizontalRule() },
    { group: 'Blocks', title: 'Table', run: (chain) => chain.insertTable({ rows: 3, cols: 3, withHeaderRow: true }) },
    { group: 'Media', title: 'Image, audio or video', action: 'chooseFiles' },
    { group: 'Media', title: 'Media library', action: 'openLibrary' },
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
        const parts = [];
        items.forEach((item, index) => {
            // A heading where a new group starts. It is no option, so the arrow keys pass over it.
            if (item.group !== items[index - 1]?.group) {
                const heading = document.createElement('div');
                heading.className = 'slash-group';
                heading.role = 'presentation';
                heading.textContent = item.group;
                parts.push(heading);
            }
            const button = document.createElement('button');
            button.type = 'button';
            button.role = 'option';
            const title = document.createElement('span');
            title.textContent = item.title;
            button.append(title);
            if (item.hint) {
                const hint = document.createElement('span');
                hint.className = 'slash-hint';
                hint.setAttribute('aria-hidden', 'true');
                hint.textContent = item.hint;
                button.append(hint);
            }
            button.setAttribute('aria-selected', String(index === selected));
            // mousedown, because a click would first move the focus away from the editor.
            button.addEventListener('mousedown', (event) => {
                event.preventDefault();
                choose(item);
            });
            parts.push(button);
        });
        menu.replaceChildren(...parts);
        menu.hidden = items.length === 0;
    };

    return {
        onStart: (props) => {
            menu = document.createElement('div');
            menu.className = 'slash-menu';
            menu.role = 'listbox';
            menu.setAttribute('aria-label', 'Blocks to insert');
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
