/**
 * SPDX-License-Identifier: AGPL-3.0-or-later
 */

/**
 * Turn every rendered mention into a uniform `.unity-mention` pill so all
 * providers look alike (a bordered chip with a person icon + the name, no '@').
 *
 * Structured markers are always normalized: Jira's `span.unity-mention`, Asana's
 * `a[data-asana-gid]`, and GitLab's user anchors. When `heuristic` is true (the
 * plain-text GitHub markdown / Redmine textile branches, which carry no mention
 * markup) bare `@handle` tokens in text nodes are wrapped too.
 *
 * Idempotent: elements already inside a `.unity-mention` are skipped, so it is
 * safe to re-run on every render/observer tick.
 *
 * @param {HTMLElement|null} root the rendered subtree to process
 * @param {object} [options] options
 * @param {boolean} [options.heuristic] also wrap bare `@handle` text tokens
 */
export function stylizeMentions(root, { heuristic = false } = {}) {
	if (!root) {
		return
	}

	// Structured mentions: tag with the pill class and drop a leading '@'.
	root.querySelectorAll('span.unity-mention, a[data-asana-gid], a[data-reference-type="user"], a.gfm-project_member, a.user-mention')
		.forEach((el) => {
			el.classList.add('unity-mention')
			stripLeadingAt(el)
		})

	if (heuristic) {
		wrapBareMentions(root)
	}
}

/** Remove a single leading '@' from an element's visible text. */
function stripLeadingAt(el) {
	const first = firstTextNode(el)
	if (first && first.nodeValue.startsWith('@')) {
		first.nodeValue = first.nodeValue.slice(1)
	}
}

/** The first descendant text node with content, or null. */
function firstTextNode(el) {
	const walker = document.createTreeWalker(el, NodeFilter.SHOW_TEXT)
	let node = walker.nextNode()
	while (node && node.nodeValue === '') {
		node = walker.nextNode()
	}
	return node
}

// A mention token: '@' + handle, only when the '@' starts the string or follows
// whitespace / an opening paren (so emails like a@b don't match).
const MENTION_RE = /(^|[\s(])@([A-Za-z0-9._-]+)/g
// Ancestors inside which we must not wrap (already a link/pill/code).
const SKIP = new Set(['A', 'CODE', 'PRE'])

/** Wrap bare `@handle` tokens in text nodes with a `.unity-mention` span. */
function wrapBareMentions(root) {
	const walker = document.createTreeWalker(root, NodeFilter.SHOW_TEXT, {
		acceptNode(node) {
			if (!node.nodeValue || node.nodeValue.indexOf('@') === -1) {
				return NodeFilter.FILTER_REJECT
			}
			for (let p = node.parentElement; p && p !== root; p = p.parentElement) {
				if (SKIP.has(p.tagName) || p.classList.contains('unity-mention')) {
					return NodeFilter.FILTER_REJECT
				}
			}
			return NodeFilter.FILTER_ACCEPT
		},
	})
	const targets = []
	let node = walker.nextNode()
	while (node) {
		// MENTION_RE is global, so reset lastIndex before each stateful test().
		MENTION_RE.lastIndex = 0
		if (MENTION_RE.test(node.nodeValue)) {
			targets.push(node)
		}
		node = walker.nextNode()
	}
	targets.forEach(replaceMentionsInTextNode)
}

/** Replace `@handle` occurrences in one text node with pill spans. */
function replaceMentionsInTextNode(textNode) {
	const text = textNode.nodeValue
	MENTION_RE.lastIndex = 0
	const frag = document.createDocumentFragment()
	let last = 0
	let m
	while ((m = MENTION_RE.exec(text)) !== null) {
		const start = m.index + m[1].length // keep the boundary char before '@'
		if (start > last) {
			frag.appendChild(document.createTextNode(text.slice(last, start)))
		}
		const span = document.createElement('span')
		span.className = 'unity-mention'
		span.textContent = m[2]
		frag.appendChild(span)
		last = MENTION_RE.lastIndex
	}
	if (last < text.length) {
		frag.appendChild(document.createTextNode(text.slice(last)))
	}
	textNode.parentNode.replaceChild(frag, textNode)
}
