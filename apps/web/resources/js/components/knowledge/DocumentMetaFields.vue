<script setup lang="ts">
import { computed } from 'vue';
import ChipToggleGroup from '@/components/common/ChipToggleGroup.vue';
import SearchableSelect from '@/components/common/SearchableSelect.vue';
import TagsInput from '@/components/common/TagsInput.vue';
import InputError from '@/components/InputError.vue';
import { Input } from '@/components/ui/input';
import { Label } from '@/components/ui/label';
import { t, tOr } from '@/lib/i18n';
import type { SelectOption } from '@/types';

export type DocumentMeta = {
    title: string;
    department: string | null;
    roles: string[];
    tags: string[];
    version: string;
    effective_date: string;
};

const props = defineProps<{
    departments: string[];
    roles: SelectOption[];
    errors: Partial<Record<string, string>>;
    idPrefix: string;
}>();

const meta = defineModel<DocumentMeta>({ required: true });

const departmentOptions = computed(() =>
    props.departments.map((department) => ({
        value: department,
        label: department,
    })),
);

const roleOptions = computed(() =>
    props.roles.map((role) => ({
        value: role.value,
        label: tOr(`roles.${role.value}`, role.label),
    })),
);

const fieldError = (key: string): string | undefined =>
    props.errors[key] ??
    Object.entries(props.errors).find(([name]) =>
        name.startsWith(`${key}.`),
    )?.[1];
</script>

<template>
    <div class="grid gap-5">
        <div class="grid gap-2">
            <Label :for="`${idPrefix}-title`">{{
                t('ui.knowledge.fields.title')
            }}</Label>
            <Input
                :id="`${idPrefix}-title`"
                v-model="meta.title"
                name="title"
                maxlength="200"
                :placeholder="t('ui.knowledge.fields.title_placeholder')"
                :aria-invalid="Boolean(errors.title)"
            />
            <InputError :message="errors.title" />
        </div>

        <div class="grid grid-cols-1 gap-5 sm:grid-cols-2">
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-department`">{{
                    t('ui.knowledge.fields.department')
                }}</Label>
                <SearchableSelect
                    :id="`${idPrefix}-department`"
                    v-model="meta.department"
                    name="department"
                    :options="departmentOptions"
                    allow-custom
                    clearable
                    :placeholder="
                        t('ui.knowledge.fields.department_placeholder')
                    "
                    :invalid="Boolean(errors.department)"
                />
                <InputError :message="errors.department" />
            </div>
            <div class="grid gap-2">
                <Label :for="`${idPrefix}-version`">{{
                    t('ui.knowledge.fields.version')
                }}</Label>
                <Input
                    :id="`${idPrefix}-version`"
                    v-model="meta.version"
                    name="version"
                    maxlength="40"
                    placeholder="v1.2"
                    class="font-mono"
                    :aria-invalid="Boolean(errors.version)"
                />
                <InputError :message="errors.version" />
            </div>
        </div>

        <div class="grid gap-2 sm:max-w-[50%]">
            <Label :for="`${idPrefix}-effective`">{{
                t('ui.knowledge.fields.effective_date')
            }}</Label>
            <Input
                :id="`${idPrefix}-effective`"
                v-model="meta.effective_date"
                name="effective_date"
                type="date"
                :aria-invalid="Boolean(errors.effective_date)"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('ui.knowledge.fields.effective_hint') }}
            </p>
            <InputError :message="errors.effective_date" />
        </div>

        <div class="grid gap-2">
            <span class="text-sm font-medium">{{
                t('ui.knowledge.fields.roles')
            }}</span>
            <ChipToggleGroup
                v-model="meta.roles"
                :options="roleOptions"
                :label="t('ui.knowledge.fields.roles')"
            />
            <p class="text-xs text-muted-foreground">
                {{ t('ui.knowledge.fields.roles_hint') }}
            </p>
            <InputError :message="fieldError('roles')" />
        </div>

        <div class="grid gap-2">
            <Label :for="`${idPrefix}-tags`">{{
                t('ui.knowledge.fields.tags')
            }}</Label>
            <TagsInput
                :id="`${idPrefix}-tags`"
                v-model="meta.tags"
                name="tags"
                :placeholder="t('ui.knowledge.fields.tags_placeholder')"
            />
            <InputError :message="fieldError('tags')" />
        </div>
    </div>
</template>
