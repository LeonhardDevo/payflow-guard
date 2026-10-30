<script setup lang="ts">
import { Head, usePage } from '@inertiajs/vue3';
import { computed } from 'vue';

const props = defineProps<{ title: string; description: string }>();

const page = usePage();
const fullTitle = computed(() =>
    props.title.includes(page.props.name)
        ? props.title
        : `${props.title} · ${page.props.name}`,
);
</script>

<template>
    <Head :title="title">
        <meta
            head-key="description"
            name="description"
            :content="description"
        />
        <meta head-key="og:title" property="og:title" :content="fullTitle" />
        <meta
            head-key="og:description"
            property="og:description"
            :content="description"
        />
    </Head>
</template>
