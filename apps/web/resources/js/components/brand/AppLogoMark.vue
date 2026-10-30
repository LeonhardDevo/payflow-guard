<script setup lang="ts">
import { useId } from 'reka-ui';
import type { HTMLAttributes } from 'vue';

defineProps<{ class?: HTMLAttributes['class'] }>();

const uid = useId();
const fillId = `pfg-fill-${uid}`;
const shineId = `pfg-shine-${uid}`;
</script>

<template>
    <svg
        viewBox="0 0 32 32"
        xmlns="http://www.w3.org/2000/svg"
        :class="$props.class"
        aria-hidden="true"
        focusable="false"
    >
        <defs>
            <linearGradient
                :id="fillId"
                x1="5"
                y1="3"
                x2="27"
                y2="29"
                gradientUnits="userSpaceOnUse"
            >
                <stop offset="0" stop-color="#34D399" />
                <stop offset="0.55" stop-color="#059669" />
                <stop offset="1" stop-color="#0F5F57" />
            </linearGradient>
            <linearGradient
                :id="shineId"
                x1="16"
                y1="2"
                x2="16"
                y2="16"
                gradientUnits="userSpaceOnUse"
            >
                <stop offset="0" stop-color="#FFFFFF" stop-opacity="0.35" />
                <stop offset="1" stop-color="#FFFFFF" stop-opacity="0" />
            </linearGradient>
        </defs>
        <path
            d="M16 2.25 26.75 6.1c.6.22 1 .79 1 1.43v7.02c0 6.86-4.63 12.6-11.33 14.96a1.3 1.3 0 0 1-.84 0C8.88 27.15 4.25 21.41 4.25 14.55V7.53c0-.64.4-1.21 1-1.43L16 2.25Z"
            :fill="`url(#${fillId})`"
        />
        <path
            d="M16 2.25 26.75 6.1c.6.22 1 .79 1 1.43v4.97C23 10.4 18.3 10.6 4.25 13.2V7.53c0-.64.4-1.21 1-1.43L16 2.25Z"
            :fill="`url(#${shineId})`"
        />
        <path
            d="M9.6 16.4c1.9-.1 3.1.9 4.6 2.9 2.2-4.1 4.9-7.1 8.3-8.9"
            fill="none"
            stroke="#FFFFFF"
            stroke-width="2.6"
            stroke-linecap="round"
            stroke-linejoin="round"
        />
        <circle cx="22.6" cy="10.3" r="1.5" fill="#FBBF24" />
    </svg>
</template>
