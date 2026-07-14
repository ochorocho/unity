/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

// highlight.js is lazy-loaded so it stays out of the main bundle and only costs
// weight when a rendered body actually contains a code block. The `common`
// subset registers ~35 popular languages (js, ts, python, bash, json, …).
let hljsPromise = null
function loadHljs() {
	if (!hljsPromise) {
		hljsPromise = import('highlight.js/lib/common').then((m) => m.default)
	}
	return hljsPromise
}

/** Read a code language from the block's class/lang, e.g. `language-js` or `<pre lang="js">`. */
function detectLanguage(code, pre) {
	for (const el of [code, pre]) {
		if (!el) {
			continue
		}
		const cls = el.getAttribute('class') || ''
		const m = /(?:language|lang)-([\w+#-]+)/i.exec(cls)
		if (m) {
			return m[1].toLowerCase()
		}
	}
	const lang = pre?.getAttribute('lang')
	return lang ? lang.toLowerCase() : ''
}

/**
 * Syntax-highlight every code block inside `root` (a provider-rendered HTML
 * subtree). Uses the block's declared language when known, otherwise auto-detects.
 * Re-highlights uniformly (discarding any provider tokens) so all trackers look
 * the same as the NcRichText markdown path. Idempotent per element.
 *
 * @param {HTMLElement|null|undefined} root the container to scan
 */
export async function highlightCodeBlocks(root) {
	if (!root) {
		return
	}
	const blocks = [...root.querySelectorAll('pre')].filter((pre) => !pre.dataset.unityHl)
	if (blocks.length === 0) {
		return
	}
	const hljs = await loadHljs()
	for (const pre of blocks) {
		pre.dataset.unityHl = '1'
		const code = pre.querySelector('code')
		const target = code || pre
		const text = target.textContent || ''
		if (text.trim() === '') {
			continue
		}
		const language = detectLanguage(code, pre)
		try {
			const result = (language && hljs.getLanguage(language))
				? hljs.highlight(text, { language, ignoreIllegals: true })
				: hljs.highlightAuto(text)
			target.innerHTML = result.value
			target.classList.add('hljs')
		} catch (e) {
			// Leave the block as plain text on any highlighter error.
		}
	}
}
