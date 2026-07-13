<template>
	<div class="unity-field">
		<label v-if="descriptor.type !== 'bool'" class="unity-field-label">
			{{ descriptor.name }}<span v-if="descriptor.required" class="unity-field-required" aria-hidden="true"> *</span>
		</label>

		<!-- Single / multi select -->
		<NcSelect v-if="isSelect"
			:options="descriptor.options || []"
			:multiple="descriptor.type === 'multiselect'"
			:close-on-select="descriptor.type !== 'multiselect'"
			label="name"
			:clearable="!descriptor.required"
			:model-value="selectedOptions"
			:input-label="descriptor.name"
			:placeholder="t('unity', 'Select…')"
			@update:model-value="onSelect" />

		<!-- Boolean -->
		<NcCheckboxRadioSwitch v-else-if="descriptor.type === 'bool'"
			:model-value="!!modelValue"
			@update:model-value="$emit('update:modelValue', $event)">
			{{ descriptor.name }}
		</NcCheckboxRadioSwitch>

		<!-- Date -->
		<input v-else-if="descriptor.type === 'date'"
			type="date"
			class="unity-field-input"
			:value="modelValue || ''"
			@input="$emit('update:modelValue', $event.target.value)">

		<!-- Numeric -->
		<NcTextField v-else-if="descriptor.type === 'int' || descriptor.type === 'float'"
			type="number"
			:step="descriptor.type === 'float' ? 'any' : '1'"
			:label="descriptor.name"
			:model-value="modelValue == null ? '' : String(modelValue)"
			@update:model-value="$emit('update:modelValue', $event)" />

		<!-- Multi-line text -->
		<textarea v-else-if="descriptor.type === 'textarea'"
			class="unity-field-textarea"
			:value="modelValue || ''"
			rows="4"
			@input="$emit('update:modelValue', $event.target.value)" />

		<!-- Single-line text (default) -->
		<NcTextField v-else
			:label="descriptor.name"
			:model-value="modelValue || ''"
			@update:model-value="$emit('update:modelValue', $event)" />

		<p v-if="descriptor.help" class="unity-field-help">{{ descriptor.help }}</p>
	</div>
</template>

<script>
import NcSelect from '@nextcloud/vue/components/NcSelect'
import NcTextField from '@nextcloud/vue/components/NcTextField'
import NcCheckboxRadioSwitch from '@nextcloud/vue/components/NcCheckboxRadioSwitch'

export default {
	name: 'DynamicField',
	components: { NcSelect, NcTextField, NcCheckboxRadioSwitch },
	props: {
		// FieldDescriptor: { id, name, type, required, options?, default?, value?, help? }
		descriptor: { type: Object, required: true },
		modelValue: { type: [String, Number, Boolean, Array], default: '' },
	},
	emits: ['update:modelValue'],
	computed: {
		isSelect() {
			return this.descriptor.type === 'select' || this.descriptor.type === 'multiselect'
		},
		// Map the stored id / id-array back to the option object(s) NcSelect binds to.
		selectedOptions() {
			const options = this.descriptor.options || []
			if (this.descriptor.type === 'multiselect') {
				const values = Array.isArray(this.modelValue) ? this.modelValue : []
				return options.filter((o) => values.includes(o.id))
			}
			return options.find((o) => o.id === this.modelValue) || null
		},
	},
	methods: {
		onSelect(value) {
			if (this.descriptor.type === 'multiselect') {
				this.$emit('update:modelValue', (value || []).map((o) => o.id))
			} else {
				this.$emit('update:modelValue', value ? value.id : '')
			}
		},
	},
}
</script>

<style scoped>
.unity-field {
	display: flex;
	flex-direction: column;
	gap: 4px;
}
.unity-field-label {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
.unity-field-required {
	color: var(--color-error);
}
.unity-field-input,
.unity-field-textarea {
	min-height: 36px;
	border-radius: var(--border-radius-element, 8px);
	padding: 6px 8px;
}
.unity-field-textarea {
	resize: vertical;
	font-family: inherit;
}
.unity-field-help {
	font-size: 0.85em;
	color: var(--color-text-maxcontrast);
}
</style>
