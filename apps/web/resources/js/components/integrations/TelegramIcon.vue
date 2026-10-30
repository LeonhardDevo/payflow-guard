<script setup lang="ts">
defineProps<{ class?: string }>();
</script>

<template>
    <svg
        viewBox="0 0 24 24"
        :class="$props.class"
        aria-hidden="true"
        focusable="false"
    >
        <circle cx="12" cy="12" r="12" fill="#2AABEE" />
        <path
            fill="#FFFFFF"
            d="M5.4 11.8 16.8 7.4c.53-.2 1 .13.83.94l-1.94 9.14c-.14.65-.53.81-1.08.5l-3-2.21-1.44 1.39c-.16.16-.3.29-.6.29l.21-3.05 5.55-5.01c.24-.21-.05-.33-.37-.12l-6.86 4.32-2.96-.92c-.64-.2-.66-.64.14-.95Z"
        />
    </svg>
</template>
