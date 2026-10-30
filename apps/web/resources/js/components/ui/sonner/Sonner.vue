<script lang="ts" setup>
import type { ToasterProps } from "vue-sonner"
import { CircleCheckIcon, InfoIcon, Loader2Icon, OctagonXIcon, TriangleAlertIcon, XIcon } from "@lucide/vue"
import { Toaster as Sonner } from "vue-sonner"
import { cn } from "@/lib/utils"

import 'vue-sonner/style.css';

const props = defineProps<ToasterProps>()
</script>

<template>
  <Sonner
    :class="cn('toaster group', props.class)"
    :style="{
      '--normal-bg': 'var(--popover)',
      '--normal-text': 'var(--popover-foreground)',
      '--normal-border': 'var(--border)',
      '--border-radius': 'var(--radius)',
      '--success-bg': 'var(--success-soft)',
      '--success-text': 'var(--success-soft-foreground)',
      '--success-border': 'color-mix(in oklch, var(--success) 35%, transparent)',
      '--info-bg': 'var(--info-soft)',
      '--info-text': 'var(--info-soft-foreground)',
      '--info-border': 'color-mix(in oklch, var(--info) 35%, transparent)',
      '--warning-bg': 'var(--warning-soft)',
      '--warning-text': 'var(--warning-soft-foreground)',
      '--warning-border': 'color-mix(in oklch, var(--warning) 45%, transparent)',
      '--error-bg': 'var(--destructive-soft)',
      '--error-text': 'var(--destructive-soft-foreground)',
      '--error-border': 'color-mix(in oklch, var(--destructive) 35%, transparent)',
    }"
    v-bind="props"
  >
    <template #success-icon>
      <CircleCheckIcon class="size-4" />
    </template>
    <template #info-icon>
      <InfoIcon class="size-4" />
    </template>
    <template #warning-icon>
      <TriangleAlertIcon class="size-4" />
    </template>
    <template #error-icon>
      <OctagonXIcon class="size-4" />
    </template>
    <template #loading-icon>
      <div>
        <Loader2Icon class="size-4 animate-spin" />
      </div>
    </template>
    <template #close-icon>
      <XIcon class="size-4" />
    </template>
  </Sonner>
</template>
