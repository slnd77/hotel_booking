<script setup lang="ts">
import type Quill from 'quill';
import 'quill/dist/quill.snow.css';
import { onBeforeUnmount, onMounted, ref, watch } from 'vue';

const props = withDefaults(
    defineProps<{
        modelValue?: string | null;
        placeholder?: string;
    }>(),
    {
        modelValue: '',
        placeholder: '',
    },
);

const emit = defineEmits<{
    'update:modelValue': [value: string];
}>();

const editorEl = ref<HTMLDivElement | null>(null);
let quill: Quill | null = null;

// Quill touches `document` as soon as it loads, so it's imported in the browser only (not during SSR).
onMounted(async () => {
    const { default: QuillEditor } = await import('quill');

    if (!editorEl.value) {
        return;
    }

    quill = new QuillEditor(editorEl.value, {
        theme: 'snow',
        placeholder: props.placeholder,
        modules: {
            toolbar: [
                [{ header: [1, 2, 3, 4, 5, 6, false] }],
                ['bold', 'italic', 'underline', 'strike'],
                [{ list: 'ordered' }, { list: 'bullet' }],
                [{ align: [] }, { indent: '-1' }, { indent: '+1' }],
                ['blockquote', 'code-block', 'link'],
                ['clean'],
            ],
        },
    });

    if (props.modelValue) {
        quill.root.innerHTML = props.modelValue;
    }

    quill.on('text-change', () => {
        const html = quill!.root.innerHTML;
        emit('update:modelValue', html === '<p><br></p>' ? '' : html);
    });
});

watch(
    () => props.modelValue,
    (value) => {
        if (
            quill &&
            value !== quill.root.innerHTML &&
            !(value === '' && quill.root.innerHTML === '<p><br></p>')
        ) {
            quill.root.innerHTML = value ?? '';
        }
    },
);

onBeforeUnmount(() => {
    quill = null;
});
</script>

<template>
    <div
        class="quill-editor-wrapper rounded-md border border-[#e3e3e0] dark:border-[#3E3E3A]"
    >
        <div ref="editorEl" />
    </div>
</template>

<style scoped>
.quill-editor-wrapper :deep(.ql-toolbar) {
    border: none;
    border-bottom: 1px solid #e3e3e0;
    border-radius: 0.375rem 0.375rem 0 0;
}

.quill-editor-wrapper :deep(.ql-container) {
    border: none;
    border-radius: 0 0 0.375rem 0.375rem;
    font-size: 0.875rem;
    min-height: 8rem;
}

@media (prefers-color-scheme: dark) {
    .quill-editor-wrapper :deep(.ql-toolbar) {
        border-bottom-color: #3e3e3a;
    }

    .quill-editor-wrapper :deep(.ql-toolbar .ql-stroke) {
        stroke: #a1a09a;
    }

    .quill-editor-wrapper :deep(.ql-toolbar .ql-fill) {
        fill: #a1a09a;
    }

    .quill-editor-wrapper :deep(.ql-toolbar .ql-picker-label) {
        color: #a1a09a;
    }

    .quill-editor-wrapper :deep(.ql-editor) {
        background-color: #0a0a0a;
        color: #ededec;
    }

    .quill-editor-wrapper :deep(.ql-editor.ql-blank::before) {
        color: #706f6c;
    }

    .quill-editor-wrapper :deep(.ql-picker-options) {
        background-color: #161615;
        color: #ededec;
    }
}
</style>
