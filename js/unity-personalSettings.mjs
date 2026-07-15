(function() {
  "use strict";
  try {
    if (typeof document != "undefined") {
      var elementStyle = document.createElement("style");
      elementStyle.appendChild(document.createTextNode("/**\n * SPDX-FileCopyrightText: 2019 Nextcloud GmbH and Nextcloud contributors\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n/**\n * SPDX-FileCopyrightText: 2021 Nextcloud GmbH and Nextcloud contributors\n * SPDX-License-Identifier: AGPL-3.0-or-later\n */\n/*\n* Ensure proper alignment of the vue material icons\n*/\n.material-design-icon[data-v-cb828737] {\n  display: flex;\n  align-self: center;\n  justify-self: center;\n  align-items: center;\n  justify-content: center;\n}\n[data-v-cb828737] .password-field__input--secure-text {\n  -webkit-text-security: disc;\n}\n.unity-form[data-v-fb4cf15a] {\n	max-width: 480px;\n	margin-top: 16px;\n	padding: 16px;\n	border: 1px solid var(--color-border);\n	border-radius: var(--border-radius-large, 12px);\n	display: flex;\n	flex-direction: column;\n	gap: 10px;\n}\n.unity-form-label[data-v-fb4cf15a] {\n	font-size: 0.85em;\n	color: var(--color-text-maxcontrast);\n}\n.unity-select[data-v-fb4cf15a] {\n	min-height: 36px;\n	border-radius: var(--border-radius-element, 8px);\n}\n.unity-form-actions[data-v-fb4cf15a] {\n	display: flex;\n	align-items: center;\n	gap: 8px;\n	margin-top: 8px;\n}\n.unity-spacer[data-v-fb4cf15a] {\n	flex: 1;\n}\n.unity-test-result[data-v-fb4cf15a] {\n	font-size: 0.85em;\n	color: var(--color-error);\n}\n.unity-test-result.ok[data-v-fb4cf15a] {\n	color: var(--color-success);\n}\n.unity-token-help[data-v-fb4cf15a] {\n	font-size: 0.9em;\n}\n.unity-help-title[data-v-fb4cf15a] {\n	font-weight: bold;\n	margin-bottom: 4px;\n}\n.unity-help-auth[data-v-fb4cf15a] {\n	margin-bottom: 8px;\n}\n.unity-help-scopes[data-v-fb4cf15a],\n.unity-help-notes[data-v-fb4cf15a] {\n	margin: 4px 0 8px;\n	padding-inline-start: 18px;\n	list-style: disc;\n}\n.unity-help-scopes code[data-v-fb4cf15a] {\n	background: var(--color-background-dark);\n	border-radius: 4px;\n	padding: 1px 5px;\n}\n.unity-help-link[data-v-fb4cf15a] {\n	color: var(--color-primary-element);\n	font-weight: bold;\n}\n\n.unity-connections[data-v-817ddb89] {\n	margin: 16px 0;\n	max-width: 640px;\n}\n.unity-connection-row[data-v-817ddb89] {\n	display: flex;\n	align-items: center;\n	gap: 10px;\n	padding: 8px 0;\n	border-bottom: 1px solid var(--color-border);\n}\n.unity-connection-info[data-v-817ddb89] {\n	display: flex;\n	flex-direction: column;\n	flex: 1;\n}\n.unity-connection-sub[data-v-817ddb89] {\n	color: var(--color-text-maxcontrast);\n	font-size: 0.85em;\n}\n.unity-dot[data-v-817ddb89] {\n	width: 12px;\n	height: 12px;\n	border-radius: 50%;\n}"));
      document.head.appendChild(elementStyle);
    }
  } catch (e) {
    console.error("vite-plugin-css-injected-by-js", e);
  }
})();
const appName = "unity";
const appVersion = "0.10.9";
import { s as register, bG as t29, _ as _export_sfc, d as defineComponent, a4 as useModel, aw as getCapabilities, W as useTemplateRef, c as computed, o as watch, bi as debounce, bh as cancelableClient, bg as generateOcsUrl, E as t, as as logger, z as openBlock, B as createBlock, aG as createSlots, I as withCtx, M as renderSlot, H as createVNode, L as NcIconSvgWrapper, u as unref, bH as mdiEyeOff, bI as mdiEye, a7 as mergeProps, bJ as NcInputField, br as mergeModels, k as ref, bw as translate, aB as _export_sfc$1, bl as NcCheckboxRadioSwitch, bC as NcNoteCard, aj as NcLoadingIcon, N as NcButton, bf as _sfc_main$3, bn as generateUrl, bz as showSuccess, bx as showError, bK as TRACKERS, a9 as resolveComponent, A as createElementBlock, C as createBaseVNode, D as toDisplayString, F as withDirectives, bB as vModelSelect, ax as Fragment, aI as renderList, a2 as createCommentVNode, J as createTextVNode, O as normalizeClass, ad as normalizeStyle, aH as trackerById, at as loadState, bc as createApp, bF as translatePlural } from "./index-Dv5Bg82g.chunk.mjs";
register(t29);
const _sfc_main$2 = /* @__PURE__ */ defineComponent({
  __name: "NcPasswordField",
  props: /* @__PURE__ */ mergeModels({
    class: {},
    inputClass: { default: "" },
    id: {},
    label: {},
    labelOutside: { type: Boolean },
    placeholder: {},
    showTrailingButton: { type: Boolean, default: true },
    success: { type: Boolean },
    error: { type: Boolean },
    helperText: {},
    disabled: { type: Boolean },
    pill: { type: Boolean },
    checkPasswordStrength: { type: Boolean },
    minlength: { default: void 0 },
    asText: { type: Boolean }
  }, {
    "modelValue": { default: "" },
    "modelModifiers": {},
    "visible": { type: Boolean, ...{ default: false } },
    "visibleModifiers": {}
  }),
  emits: /* @__PURE__ */ mergeModels(["valid", "invalid"], ["update:modelValue", "update:visible"]),
  setup(__props, { expose: __expose, emit: __emit }) {
    const modelValue = useModel(__props, "modelValue");
    const visible = useModel(__props, "visible");
    const props = __props;
    const emit = __emit;
    __expose({
      focus,
      select
    });
    const { password_policy: passwordPolicy } = getCapabilities();
    const inputFieldInstance = useTemplateRef("inputField");
    const internalHelpMessage = ref("");
    const isValid = ref();
    const propsToForward = computed(() => {
      const all = { ...props };
      delete all.checkPasswordStrength;
      delete all.minlength;
      delete all.asText;
      delete all.error;
      delete all.helperText;
      delete all.inputClass;
      delete all.success;
      return all;
    });
    const minLengthWithPolicy = computed(() => {
      return props.minlength ?? (props.checkPasswordStrength ? passwordPolicy?.minLength : void 0) ?? void 0;
    });
    watch(modelValue, () => {
      isValid.value = void 0;
      internalHelpMessage.value = "";
    });
    watch(modelValue, debounce(checkPassword, 500));
    async function checkPassword() {
      if (!props.checkPasswordStrength || !modelValue.value) {
        return;
      }
      try {
        const { data } = await cancelableClient.post(generateOcsUrl("apps/password_policy/api/v1/validate"), { password: modelValue.value });
        isValid.value = data.ocs.data.passed;
        if (data.ocs.data.passed) {
          internalHelpMessage.value = t("Password is secure");
          emit("valid");
          return;
        }
        internalHelpMessage.value = data.ocs.data.reason;
        emit("invalid");
      } catch (error) {
        logger.error("Password policy returned an error", { error });
      }
    }
    function toggleVisibility() {
      visible.value = !visible.value;
    }
    function focus(options) {
      inputFieldInstance.value.focus(options);
    }
    function select() {
      inputFieldInstance.value.select();
    }
    return (_ctx, _cache) => {
      return openBlock(), createBlock(NcInputField, mergeProps(propsToForward.value, {
        ref: "inputField",
        modelValue: modelValue.value,
        "onUpdate:modelValue": _cache[0] || (_cache[0] = ($event) => modelValue.value = $event),
        error: __props.error || isValid.value === false,
        helperText: __props.helperText || internalHelpMessage.value,
        inputClass: [__props.inputClass, { "password-field__input--secure-text": !visible.value && __props.asText }],
        minlength: minLengthWithPolicy.value,
        success: __props.success || isValid.value === true,
        trailingButtonLabel: visible.value ? unref(t)("Hide password") : unref(t)("Show password"),
        type: visible.value || __props.asText ? "text" : "password",
        onTrailingButtonClick: toggleVisibility
      }), createSlots({
        "trailing-button-icon": withCtx(() => [
          createVNode(NcIconSvgWrapper, {
            path: visible.value ? unref(mdiEyeOff) : unref(mdiEye)
          }, null, 8, ["path"])
        ]),
        _: 2
      }, [
        !!_ctx.$slots.icon ? {
          name: "icon",
          fn: withCtx(() => [
            renderSlot(_ctx.$slots, "icon", {}, void 0, true)
          ]),
          key: "0"
        } : void 0
      ]), 1040, ["modelValue", "error", "helperText", "inputClass", "minlength", "success", "trailingButtonLabel", "type"]);
    };
  }
});
const NcPasswordField = /* @__PURE__ */ _export_sfc(_sfc_main$2, [["__scopeId", "data-v-cb828737"]]);
function stripSlash(url) {
  return (url || "").replace(/\/+$/, "");
}
function tokenHelp(tracker, baseUrl) {
  const base = stripSlash(baseUrl);
  switch (tracker) {
    case "jira": {
      const isCloud = base === "" || /atlassian\.net/i.test(base);
      if (!isCloud) {
        return {
          createUrl: base + "/secure/ViewProfile.jspa",
          createLabel: translate("unity", "Open your Jira profile → Personal Access Tokens"),
          auth: translate("unity", "This looks like Jira Server / Data Center. Authentication is a Bearer Personal Access Token (PAT) — paste the token into the API token field and leave the email blank."),
          scopes: [
            { name: translate("unity", "View issues"), purpose: translate("unity", "Browse projects, read issues, comments and worklogs") },
            { name: translate("unity", "Add comments & log work"), purpose: translate("unity", "Post comments and record time") }
          ],
          notes: [
            translate("unity", "Create the token in Jira → your avatar → Profile → Personal Access Tokens → Create token (requires Jira Server/DC 8.14+)."),
            translate("unity", "The PAT inherits your account permissions, so your Jira user must be able to Browse Projects, Add Comments and Log Work in the relevant projects."),
            translate("unity", "Descriptions and comments use wiki markup and are shown as plain text. Tempo Cloud is not used for Server connections.")
          ]
        };
      }
      return {
        createUrl: "https://id.atlassian.net/manage-profile/security/api-tokens",
        createLabel: translate("unity", "Create a Jira API token"),
        auth: translate("unity", "Jira Cloud: authentication is HTTP Basic — use your Atlassian account email as the username and the API token as the password."),
        scopes: [
          { name: "read:jira-work", purpose: translate("unity", "View issues, comments and worklogs") },
          { name: "write:jira-work", purpose: translate("unity", "Add comments and log work") }
        ],
        notes: [
          translate("unity", "A classic (unscoped) API token inherits your account permissions — your Jira user must be able to Browse Projects, View Issues, Add Comments and Log Work in the relevant projects."),
          translate("unity", "For a scoped API token, grant the two scopes listed above."),
          translate("unity", "The optional Tempo token is created separately in Jira → Tempo → Settings → API Integration and needs worklog read/write access.")
        ]
      };
    }
    case "github":
      return {
        createUrl: "https://github.com/settings/personal-access-tokens/new",
        createLabel: translate("unity", "Create a GitHub fine-grained token"),
        auth: translate("unity", "Authentication is a Bearer token. Leave the base URL as https://api.github.com for github.com, or use https://your-host/api/v3 for GitHub Enterprise."),
        scopes: [
          { name: "Issues: Read and write", purpose: translate("unity", "View issues & comments, and post comments") },
          { name: "Metadata: Read-only", purpose: translate("unity", "Required by GitHub for every fine-grained token") }
        ],
        notes: [
          translate("unity", "Fine-grained token: select the repositories you want to see, then grant the two repository permissions above."),
          translate("unity", 'Classic token alternative: select the "repo" scope (or "public_repo" for public repositories only).'),
          translate("unity", "GitHub has no time-tracking API, so time logging is disabled for GitHub connections.")
        ]
      };
    case "gitlab":
      return {
        createUrl: (base || "https://gitlab.com") + "/-/user_settings/personal_access_tokens",
        createLabel: translate("unity", "Create a GitLab personal access token"),
        auth: translate("unity", "Authentication uses the PRIVATE-TOKEN header. Base URL is https://gitlab.com or your self-hosted GitLab host."),
        scopes: [
          { name: "api", purpose: translate("unity", "Read issues and write comments / log time (read + write)") },
          { name: "read_api", purpose: translate("unity", "Read-only alternative — browsing only, no comments or time logging") }
        ],
        notes: [
          translate("unity", 'Choose the "api" scope to comment and log time; "read_api" is enough only if you want a read-only view.')
        ]
      };
    case "redmine":
      return {
        createUrl: (base || "") + "/my/account",
        createLabel: translate("unity", "Open your Redmine account page"),
        auth: translate("unity", "Authentication uses the X-Redmine-API-Key header. Your API access key is shown on My account → API access key."),
        scopes: [
          { name: translate("unity", "View issues"), purpose: translate("unity", "List and open issues") },
          { name: translate("unity", "Add notes"), purpose: translate("unity", "Post comments") },
          { name: translate("unity", "Log spent time"), purpose: translate("unity", "Record time entries") }
        ],
        notes: [
          translate("unity", 'An administrator must enable the REST API under Administration → Settings → API → "Enable REST web service".'),
          translate("unity", "The API key inherits your account permissions, so your Redmine role needs the project permissions listed above.")
        ]
      };
    case "asana":
      return {
        createUrl: "https://app.asana.com/0/my-apps",
        createLabel: translate("unity", "Create an Asana personal access token"),
        auth: translate("unity", "Authentication is a Bearer personal access token (PAT). The base URL is fixed to https://app.asana.com/api/1.0, so it is not needed."),
        scopes: [
          { name: translate("unity", "Default (full access)"), purpose: translate("unity", "A PAT inherits your account permissions — read tasks, post comments, manage attachments and log time in your workspaces") }
        ],
        notes: [
          translate("unity", 'Open your Asana profile settings → Apps → "Manage Developer Apps" → Personal access tokens → Create new token, then paste it into the API token field.'),
          translate("unity", "If your token can see more than one workspace, set the optional Workspace (GID) to pin the one Unity should use; otherwise the first workspace is used."),
          translate("unity", "Time tracking requires a paid Asana plan (Advanced or above) and cannot store a per-entry comment.")
        ]
      };
    default:
      return null;
  }
}
const _sfc_main$1 = {
  name: "ConnectionForm",
  components: { NcTextField: _sfc_main$3, NcPasswordField, NcButton, NcLoadingIcon, NcNoteCard, NcCheckboxRadioSwitch },
  props: {
    model: { type: Object, required: true }
  },
  emits: ["saved", "cancel"],
  data() {
    return {
      form: { ...this.model, settings: { allowLocalAddress: false, ...this.model.settings || {} } },
      trackers: TRACKERS,
      testing: false,
      testResult: null,
      saving: false
    };
  },
  computed: {
    isNew() {
      return !this.form.id;
    },
    needsUsername() {
      return this.form.tracker === "jira";
    },
    needsBaseUrl() {
      return this.form.tracker !== "asana";
    },
    valid() {
      if (this.needsBaseUrl && this.form.baseUrl.trim() === "") {
        return false;
      }
      if (this.isNew && this.form.token.trim() === "") {
        return false;
      }
      return true;
    },
    baseUrlLabel() {
      return this.t("unity", "Base URL");
    },
    baseUrlPlaceholder() {
      switch (this.form.tracker) {
        case "jira":
          return "https://your-site.atlassian.net";
        case "gitlab":
          return "https://gitlab.com";
        case "github":
          return "https://api.github.com";
        case "redmine":
          return "https://redmine.example.com";
        default:
          return "";
      }
    },
    tokenLabel() {
      return this.form.tracker === "redmine" ? this.t("unity", "API key") : this.t("unity", "API token");
    },
    help() {
      return tokenHelp(this.form.tracker, this.form.baseUrl);
    }
  },
  methods: {
    async test() {
      this.testing = true;
      this.testResult = null;
      try {
        const { data } = await cancelableClient.post(generateUrl("/apps/unity/connections/test"), {
          tracker: this.form.tracker,
          baseUrl: this.form.baseUrl,
          username: this.form.username,
          token: this.form.token,
          tempoToken: this.form.tempoToken,
          settings: this.form.settings,
          id: this.form.id
        });
        this.testResult = data;
      } catch (e) {
        this.testResult = { ok: false, message: this.t("unity", "Test failed") };
      } finally {
        this.testing = false;
      }
    },
    async save() {
      this.saving = true;
      try {
        const payload = {
          tracker: this.form.tracker,
          label: this.form.label,
          baseUrl: this.form.baseUrl,
          username: this.form.username,
          token: this.form.token,
          tempoToken: this.form.tempoToken,
          settings: this.form.settings
        };
        if (this.isNew) {
          await cancelableClient.post(generateUrl("/apps/unity/connections"), payload);
        } else {
          await cancelableClient.put(generateUrl("/apps/unity/connections/{id}", { id: this.form.id }), payload);
        }
        showSuccess(this.t("unity", "Connection saved"));
        this.$emit("saved");
      } catch (e) {
        showError(this.t("unity", "Could not save connection"));
      } finally {
        this.saving = false;
      }
    }
  }
};
const _hoisted_1$1 = { class: "unity-form" };
const _hoisted_2$1 = { class: "unity-form-label" };
const _hoisted_3$1 = ["disabled"];
const _hoisted_4$1 = ["value"];
const _hoisted_5$1 = { class: "unity-form-label" };
const _hoisted_6$1 = { value: "textile" };
const _hoisted_7 = { value: "markdown" };
const _hoisted_8 = { class: "unity-help-title" };
const _hoisted_9 = {
  key: 0,
  class: "unity-help-auth"
};
const _hoisted_10 = { class: "unity-help-scopes" };
const _hoisted_11 = { class: "unity-help-notes" };
const _hoisted_12 = ["href"];
const _hoisted_13 = { class: "unity-form-actions" };
function _sfc_render$1(_ctx, _cache, $props, $setup, $data, $options) {
  const _component_NcTextField = resolveComponent("NcTextField");
  const _component_NcPasswordField = resolveComponent("NcPasswordField");
  const _component_NcCheckboxRadioSwitch = resolveComponent("NcCheckboxRadioSwitch");
  const _component_NcNoteCard = resolveComponent("NcNoteCard");
  const _component_NcLoadingIcon = resolveComponent("NcLoadingIcon");
  const _component_NcButton = resolveComponent("NcButton");
  return openBlock(), createElementBlock("div", _hoisted_1$1, [
    createBaseVNode(
      "h3",
      null,
      toDisplayString($options.isNew ? _ctx.t("unity", "Add connection") : _ctx.t("unity", "Edit connection")),
      1
      /* TEXT */
    ),
    createBaseVNode(
      "label",
      _hoisted_2$1,
      toDisplayString(_ctx.t("unity", "Tracker")),
      1
      /* TEXT */
    ),
    withDirectives(createBaseVNode("select", {
      "onUpdate:modelValue": _cache[0] || (_cache[0] = ($event) => $data.form.tracker = $event),
      class: "unity-select",
      disabled: !$options.isNew
    }, [
      (openBlock(true), createElementBlock(
        Fragment,
        null,
        renderList($data.trackers, (tr) => {
          return openBlock(), createElementBlock("option", {
            key: tr.id,
            value: tr.id
          }, toDisplayString(tr.label), 9, _hoisted_4$1);
        }),
        128
        /* KEYED_FRAGMENT */
      ))
    ], 8, _hoisted_3$1), [
      [vModelSelect, $data.form.tracker]
    ]),
    createVNode(_component_NcTextField, {
      modelValue: $data.form.label,
      "onUpdate:modelValue": _cache[1] || (_cache[1] = ($event) => $data.form.label = $event),
      label: _ctx.t("unity", "Name")
    }, null, 8, ["modelValue", "label"]),
    $options.needsBaseUrl ? (openBlock(), createBlock(_component_NcTextField, {
      key: 0,
      modelValue: $data.form.baseUrl,
      "onUpdate:modelValue": _cache[2] || (_cache[2] = ($event) => $data.form.baseUrl = $event),
      label: $options.baseUrlLabel,
      placeholder: $options.baseUrlPlaceholder
    }, null, 8, ["modelValue", "label", "placeholder"])) : createCommentVNode("v-if", true),
    $options.needsUsername ? (openBlock(), createBlock(_component_NcTextField, {
      key: 1,
      modelValue: $data.form.username,
      "onUpdate:modelValue": _cache[3] || (_cache[3] = ($event) => $data.form.username = $event),
      label: _ctx.t("unity", "Account email")
    }, null, 8, ["modelValue", "label"])) : createCommentVNode("v-if", true),
    createVNode(_component_NcPasswordField, {
      modelValue: $data.form.token,
      "onUpdate:modelValue": _cache[4] || (_cache[4] = ($event) => $data.form.token = $event),
      label: $options.tokenLabel,
      placeholder: $options.isNew ? "" : _ctx.t("unity", "Leave blank to keep current token")
    }, null, 8, ["modelValue", "label", "placeholder"]),
    $data.form.tracker === "jira" ? (openBlock(), createBlock(_component_NcPasswordField, {
      key: 2,
      modelValue: $data.form.tempoToken,
      "onUpdate:modelValue": _cache[5] || (_cache[5] = ($event) => $data.form.tempoToken = $event),
      label: _ctx.t("unity", "Tempo API token (optional)"),
      placeholder: $options.isNew ? "" : _ctx.t("unity", "Leave blank to keep current token")
    }, null, 8, ["modelValue", "label", "placeholder"])) : createCommentVNode("v-if", true),
    createVNode(_component_NcCheckboxRadioSwitch, {
      type: "switch",
      modelValue: $data.form.settings.allowLocalAddress,
      "onUpdate:modelValue": _cache[6] || (_cache[6] = ($event) => $data.form.settings.allowLocalAddress = $event)
    }, {
      default: withCtx(() => [
        createTextVNode(
          toDisplayString(_ctx.t("unity", "Allow connecting to an internal/local server address")),
          1
          /* TEXT */
        )
      ]),
      _: 1
      /* STABLE */
    }, 8, ["modelValue"]),
    $data.form.settings.allowLocalAddress ? (openBlock(), createBlock(_component_NcNoteCard, {
      key: 3,
      type: "warning",
      class: "unity-token-help"
    }, {
      default: withCtx(() => [
        createTextVNode(
          toDisplayString(_ctx.t("unity", "This relaxes Nextcloud's protection against requests to private/internal addresses for this connection only. Enable it only for a tracker you trust on your own network.")),
          1
          /* TEXT */
        )
      ]),
      _: 1
      /* STABLE */
    })) : createCommentVNode("v-if", true),
    $data.form.tracker === "redmine" ? (openBlock(), createElementBlock(
      Fragment,
      { key: 4 },
      [
        createBaseVNode(
          "label",
          _hoisted_5$1,
          toDisplayString(_ctx.t("unity", "Text format")),
          1
          /* TEXT */
        ),
        withDirectives(createBaseVNode(
          "select",
          {
            "onUpdate:modelValue": _cache[7] || (_cache[7] = ($event) => $data.form.settings.textFormat = $event),
            class: "unity-select"
          },
          [
            createBaseVNode(
              "option",
              _hoisted_6$1,
              toDisplayString(_ctx.t("unity", "Textile (Redmine default)")),
              1
              /* TEXT */
            ),
            createBaseVNode(
              "option",
              _hoisted_7,
              toDisplayString(_ctx.t("unity", "Markdown")),
              1
              /* TEXT */
            )
          ],
          512
          /* NEED_PATCH */
        ), [
          [vModelSelect, $data.form.settings.textFormat]
        ])
      ],
      64
      /* STABLE_FRAGMENT */
    )) : createCommentVNode("v-if", true),
    $data.form.tracker === "asana" ? (openBlock(), createBlock(_component_NcTextField, {
      key: 5,
      modelValue: $data.form.settings.workspace,
      "onUpdate:modelValue": _cache[8] || (_cache[8] = ($event) => $data.form.settings.workspace = $event),
      label: _ctx.t("unity", "Workspace (GID, optional)"),
      placeholder: _ctx.t("unity", "Leave blank to use your first workspace")
    }, null, 8, ["modelValue", "label", "placeholder"])) : createCommentVNode("v-if", true),
    $options.help ? (openBlock(), createBlock(_component_NcNoteCard, {
      key: 6,
      type: "info",
      class: "unity-token-help"
    }, {
      default: withCtx(() => [
        createBaseVNode(
          "p",
          _hoisted_8,
          toDisplayString(_ctx.t("unity", "Required token permissions")),
          1
          /* TEXT */
        ),
        $options.help.auth ? (openBlock(), createElementBlock(
          "p",
          _hoisted_9,
          toDisplayString($options.help.auth),
          1
          /* TEXT */
        )) : createCommentVNode("v-if", true),
        createBaseVNode("ul", _hoisted_10, [
          (openBlock(true), createElementBlock(
            Fragment,
            null,
            renderList($options.help.scopes, (s) => {
              return openBlock(), createElementBlock("li", {
                key: s.name
              }, [
                createBaseVNode(
                  "code",
                  null,
                  toDisplayString(s.name),
                  1
                  /* TEXT */
                ),
                createTextVNode(
                  " — " + toDisplayString(s.purpose),
                  1
                  /* TEXT */
                )
              ]);
            }),
            128
            /* KEYED_FRAGMENT */
          ))
        ]),
        createBaseVNode("ul", _hoisted_11, [
          (openBlock(true), createElementBlock(
            Fragment,
            null,
            renderList($options.help.notes, (note, i) => {
              return openBlock(), createElementBlock(
                "li",
                { key: i },
                toDisplayString(note),
                1
                /* TEXT */
              );
            }),
            128
            /* KEYED_FRAGMENT */
          ))
        ]),
        createBaseVNode("a", {
          href: $options.help.createUrl,
          target: "_blank",
          rel: "noopener noreferrer",
          class: "unity-help-link"
        }, toDisplayString($options.help.createLabel) + " ↗ ", 9, _hoisted_12)
      ]),
      _: 1
      /* STABLE */
    })) : createCommentVNode("v-if", true),
    createBaseVNode("div", _hoisted_13, [
      createVNode(_component_NcButton, { onClick: $options.test }, createSlots({
        default: withCtx(() => [
          createTextVNode(
            " " + toDisplayString(_ctx.t("unity", "Test")),
            1
            /* TEXT */
          )
        ]),
        _: 2
        /* DYNAMIC */
      }, [
        $data.testing ? {
          name: "icon",
          fn: withCtx(() => [
            createVNode(_component_NcLoadingIcon, { size: 20 })
          ]),
          key: "0"
        } : void 0
      ]), 1032, ["onClick"]),
      $data.testResult ? (openBlock(), createElementBlock(
        "span",
        {
          key: 0,
          class: normalizeClass(["unity-test-result", { ok: $data.testResult.ok }])
        },
        toDisplayString($data.testResult.ok ? _ctx.t("unity", "OK") + ($data.testResult.user ? " – " + $data.testResult.user : "") : $data.testResult.message),
        3
        /* TEXT, CLASS */
      )) : createCommentVNode("v-if", true),
      _cache[10] || (_cache[10] = createBaseVNode(
        "span",
        { class: "unity-spacer" },
        null,
        -1
        /* CACHED */
      )),
      createVNode(_component_NcButton, {
        type: "tertiary",
        onClick: _cache[9] || (_cache[9] = ($event) => _ctx.$emit("cancel"))
      }, {
        default: withCtx(() => [
          createTextVNode(
            toDisplayString(_ctx.t("unity", "Cancel")),
            1
            /* TEXT */
          )
        ]),
        _: 1
        /* STABLE */
      }),
      createVNode(_component_NcButton, {
        type: "primary",
        disabled: $data.saving || !$options.valid,
        onClick: $options.save
      }, createSlots({
        default: withCtx(() => [
          createTextVNode(
            " " + toDisplayString(_ctx.t("unity", "Save")),
            1
            /* TEXT */
          )
        ]),
        _: 2
        /* DYNAMIC */
      }, [
        $data.saving ? {
          name: "icon",
          fn: withCtx(() => [
            createVNode(_component_NcLoadingIcon, { size: 20 })
          ]),
          key: "0"
        } : void 0
      ]), 1032, ["disabled", "onClick"])
    ])
  ]);
}
const ConnectionForm = /* @__PURE__ */ _export_sfc$1(_sfc_main$1, [["render", _sfc_render$1], ["__scopeId", "data-v-fb4cf15a"], ["__file", "/Users/jochen/Development/nextcloud-app-dev/app/unity/src/components/ConnectionForm.vue"]]);
const _sfc_main = {
  name: "PersonalSettings",
  components: { NcButton, ConnectionForm },
  data() {
    let initial = [];
    try {
      initial = loadState("unity", "unity-connections");
    } catch (e) {
      initial = [];
    }
    return {
      connections: Array.isArray(initial) ? initial : [],
      editing: null
    };
  },
  async mounted() {
    await this.refresh();
  },
  methods: {
    color(tracker) {
      return trackerById(tracker).color;
    },
    trackerLabel(tracker) {
      return trackerById(tracker).label;
    },
    async refresh() {
      try {
        const { data } = await cancelableClient.get(generateUrl("/apps/unity/connections"));
        this.connections = data;
      } catch (e) {
        showError(this.t("unity", "Could not load connections"));
      }
    },
    addNew() {
      this.editing = { id: "", tracker: "jira", label: "", baseUrl: "", username: "", token: "", tempoToken: "" };
    },
    edit(connection) {
      this.editing = { ...connection, token: "", tempoToken: "" };
    },
    async remove(connection) {
      try {
        await cancelableClient.delete(generateUrl("/apps/unity/connections/{id}", { id: connection.id }));
        showSuccess(this.t("unity", "Connection removed"));
        await this.refresh();
      } catch (e) {
        showError(this.t("unity", "Could not remove connection"));
      }
    },
    async onSaved() {
      this.editing = null;
      await this.refresh();
    }
  }
};
const _hoisted_1 = {
  id: "unity_prefs_content",
  class: "section"
};
const _hoisted_2 = { class: "settings-hint" };
const _hoisted_3 = { class: "unity-connections" };
const _hoisted_4 = { class: "unity-connection-info" };
const _hoisted_5 = { class: "unity-connection-sub" };
const _hoisted_6 = {
  key: 0,
  class: "settings-hint"
};
function _sfc_render(_ctx, _cache, $props, $setup, $data, $options) {
  const _component_NcButton = resolveComponent("NcButton");
  const _component_ConnectionForm = resolveComponent("ConnectionForm");
  return openBlock(), createElementBlock("div", _hoisted_1, [
    createBaseVNode(
      "h2",
      null,
      toDisplayString(_ctx.t("unity", "Unity — issue tracker connections")),
      1
      /* TEXT */
    ),
    createBaseVNode(
      "p",
      _hoisted_2,
      toDisplayString(_ctx.t("unity", "Connect your Jira, GitLab, Redmine, GitHub and Asana accounts. Tokens are stored encrypted and never shown again.")),
      1
      /* TEXT */
    ),
    createBaseVNode("div", _hoisted_3, [
      (openBlock(true), createElementBlock(
        Fragment,
        null,
        renderList($data.connections, (c) => {
          return openBlock(), createElementBlock("div", {
            key: c.id,
            class: "unity-connection-row"
          }, [
            createBaseVNode(
              "span",
              {
                class: "unity-dot",
                style: normalizeStyle({ backgroundColor: $options.color(c.tracker) })
              },
              null,
              4
              /* STYLE */
            ),
            createBaseVNode("div", _hoisted_4, [
              createBaseVNode(
                "strong",
                null,
                toDisplayString(c.label || c.baseUrl),
                1
                /* TEXT */
              ),
              createBaseVNode(
                "span",
                _hoisted_5,
                toDisplayString($options.trackerLabel(c.tracker)) + " · " + toDisplayString(c.baseUrl),
                1
                /* TEXT */
              )
            ]),
            createVNode(_component_NcButton, {
              type: "tertiary",
              onClick: ($event) => $options.edit(c)
            }, {
              default: withCtx(() => [
                createTextVNode(
                  toDisplayString(_ctx.t("unity", "Edit")),
                  1
                  /* TEXT */
                )
              ]),
              _: 1
              /* STABLE */
            }, 8, ["onClick"]),
            createVNode(_component_NcButton, {
              type: "tertiary",
              onClick: ($event) => $options.remove(c)
            }, {
              default: withCtx(() => [
                createTextVNode(
                  toDisplayString(_ctx.t("unity", "Delete")),
                  1
                  /* TEXT */
                )
              ]),
              _: 1
              /* STABLE */
            }, 8, ["onClick"])
          ]);
        }),
        128
        /* KEYED_FRAGMENT */
      )),
      $data.connections.length === 0 ? (openBlock(), createElementBlock(
        "p",
        _hoisted_6,
        toDisplayString(_ctx.t("unity", "No connections configured yet.")),
        1
        /* TEXT */
      )) : createCommentVNode("v-if", true)
    ]),
    createVNode(_component_NcButton, {
      type: "secondary",
      onClick: $options.addNew
    }, {
      default: withCtx(() => [
        createTextVNode(
          toDisplayString(_ctx.t("unity", "Add connection")),
          1
          /* TEXT */
        )
      ]),
      _: 1
      /* STABLE */
    }, 8, ["onClick"]),
    $data.editing ? (openBlock(), createBlock(_component_ConnectionForm, {
      key: 0,
      model: $data.editing,
      onSaved: $options.onSaved,
      onCancel: _cache[0] || (_cache[0] = ($event) => $data.editing = null)
    }, null, 8, ["model", "onSaved"])) : createCommentVNode("v-if", true)
  ]);
}
const PersonalSettings = /* @__PURE__ */ _export_sfc$1(_sfc_main, [["render", _sfc_render], ["__scopeId", "data-v-817ddb89"], ["__file", "/Users/jochen/Development/nextcloud-app-dev/app/unity/src/components/PersonalSettings.vue"]]);
const app = createApp(PersonalSettings);
app.mixin({ methods: { t: translate, n: translatePlural } });
app.mount("#unity_prefs");
//# sourceMappingURL=unity-personalSettings.mjs.map
