<script setup lang="ts">
defineProps<{ message?: string | null }>();
</script>

<template>
    <p
        v-if="message"
        role="alert"
        data-test="input-error"
        class="text-sm font-medium text-destructive"
    >
        {{ message }}
    </p>
</template>
