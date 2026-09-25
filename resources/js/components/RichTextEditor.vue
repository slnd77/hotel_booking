<script setup lang="ts">
import StarterKit from '@tiptap/starter-kit';
import { EditorContent, useEditor } from '@tiptap/vue-3';
import { watch } from 'vue';

const props = defineProps<{
    modelValue: string;
}>();

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const editor = useEditor({
    content: props.modelValue,
    extensions: [StarterKit],
    editorProps: {
        attributes: {
            class: 'min-h-[6rem] px-3 py-2 text-sm text-[#1b1b18] focus:outline-none dark:text-[#EDEDEC] [&_ol]:list-decimal [&_ol]:pl-5 [&_p]:my-1 [&_ul]:list-disc [&_ul]:pl-5',
        },
    },
    onUpdate: ({ editor: current }) => {
        emit('update:modelValue', current.getHTML());
    },
});

watch(
    () => props.modelValue,
    (value) => {
        if (editor.value && value !== editor.value.getHTML()) {
            editor.value.commands.setContent(value, { emitUpdate: false });
        }
    },
);

function isActive(name: string, attributes?: Record<string, unknown>): boolean {
    return editor.value?.isActive(name, attributes) ?? false;
}
</script>

<template>
    <div
        class="rounded-md border border-[#e3e3e0] bg-white focus-within:border-[#f53003] focus-within:ring-1 focus-within:ring-[#f53003] dark:border-[#3E3E3A] dark:bg-[#0a0a0a]"
    >
        <div
            class="flex flex-wrap gap-1 border-b border-[#e3e3e0] p-1 dark:border-[#3E3E3A]"
            v-if="editor"
        >
            <button
                type="button"
                class="rounded px-2 py-1 text-xs font-semibold text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                :class="{ 'bg-[#f5f5f4] dark:bg-[#1D1D1B]': isActive('bold') }"
                @click="editor?.chain().focus().toggleBold().run()"
            >
                Bold
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-xs text-[#1b1b18] italic hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                :class="{
                    'bg-[#f5f5f4] dark:bg-[#1D1D1B]': isActive('italic'),
                }"
                @click="editor?.chain().focus().toggleItalic().run()"
            >
                Italic
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-xs text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                :class="{
                    'bg-[#f5f5f4] dark:bg-[#1D1D1B]': isActive('bulletList'),
                }"
                @click="editor?.chain().focus().toggleBulletList().run()"
            >
                Bullet list
            </button>
            <button
                type="button"
                class="rounded px-2 py-1 text-xs text-[#1b1b18] hover:bg-[#f5f5f4] dark:text-[#EDEDEC] dark:hover:bg-[#1D1D1B]"
                :class="{
                    'bg-[#f5f5f4] dark:bg-[#1D1D1B]': isActive('orderedList'),
                }"
                @click="editor?.chain().focus().toggleOrderedList().run()"
            >
                Numbered list
            </button>
        </div>
        <EditorContent :editor="editor" />
    </div>
</template>
