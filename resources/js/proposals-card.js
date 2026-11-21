/*
| The card in the Claude chat (DESIGN.md, section 8). The view inlines this
| file. It reads what waits from the gateway with the card token, draws the
| same structure as resources/views/components/proposal-block.blade.php, and
| posts each decision back.
|
| Every string from the gateway is drawn as text through h(), never as HTML,
| because the titles, summaries and quotes are the agent's words and the mail
| is anyone's.
*/

const config = JSON.parse(document.getElementById('card-config').textContent);
const root = document.getElementById('card');

const LOADING = 'Loading';
const UNREACHABLE = 'The gateway could not be reached. Open the approval page instead.';
const EXPIRED = 'This card has expired. Decide on the approval page.';

const state = {
    /** The last answer of GET /card/proposals, with decided blocks swapped in. */
    data: null,
    /** A sentence about the whole card: loading, unreachable or expired. */
    notice: LOADING,
    /** After a 401 the card shows what it last knew, without buttons. */
    readOnly: false,
    /** Proposal id to a sentence about its last decision. */
    problems: new Map(),
    /** Proposal id to the reason typed so far, kept across redraws. */
    reasons: new Map(),
    /** Keys of the folds the user opened, kept across redraws. */
    open: new Set(),
    /** Ids of the proposals a decision is being recorded for, with the button pressed. */
    sending: new Map(),
    /** The sentences told to the agent so far. */
    told: [],
};

/** The host bridge, when the card runs inside a host. */
let bridge = null;

/** Builds an element. Strings among the children become text nodes. */
function h(tag, props, ...children) {
    const node = document.createElement(tag);

    for (const [key, value] of Object.entries(props ?? {})) {
        if (value === null || value === undefined || value === false) {
            continue;
        }

        if (key === 'class') {
            node.className = value;
        } else if (key.startsWith('on')) {
            node.addEventListener(key.slice(2), value);
        } else {
            node.setAttribute(key, value === true ? '' : String(value));
        }
    }

    node.append(...nodes(children));

    return node;
}

/** Children as nodes: arrays are flattened, strings become text nodes and empty values are left out. */
function nodes(children) {
    return children
        .flat(Infinity)
        .filter((child) => child !== null && child !== undefined && child !== false)
        .map((child) => (child instanceof Node ? child : document.createTextNode(String(child))));
}

/** A fold whose open state survives a redraw. */
function fold(key, summary, ...body) {
    const details = h('details', { class: 'gw-fold', open: state.open.has(key) }, h('summary', null, summary), h('div', { class: 'gw-fold-body' }, ...body));

    details.addEventListener('toggle', () => (details.open ? state.open.add(key) : state.open.delete(key)));

    return details;
}

function pageLink(text = 'Open the full page') {
    const url = state.data?.page_url ?? config.page;

    return h('a', {
        class: 'gw-link',
        href: url,
        target: '_blank',
        rel: 'noopener',
        onclick: (event) => {
            if (bridge) {
                event.preventDefault();
                bridge.openLink({ url }).catch(() => window.open(url, '_blank', 'noopener'));
            }
        },
    }, text);
}

function render() {
    const data = state.data;

    if (data === null) {
        root.replaceChildren(...nodes([
            h('p', { class: 'gw-state', 'data-tone': state.notice === LOADING ? null : 'bad', role: 'status' }, state.notice),
            state.notice === LOADING ? null : h('p', null, pageLink()),
        ]));

        return;
    }

    root.replaceChildren(...nodes([
        h('h1', { class: 'gw-heading' }, data.heading),
        state.notice ? h('p', { class: 'gw-state', 'data-tone': 'bad', role: 'status' }, state.notice) : null,
        data.groups.map(renderGroup),
        h('div', { class: 'gw-foot' },
            data.applied_by_rule.length > 0
                ? fold('applied', `Applied by rule (${data.applied_by_rule.length})`,
                    h('ul', { class: 'gw-applied' }, data.applied_by_rule.map((applied) => h('li', null, applied.title, ' ', h('span', { class: 'gw-rule' }, applied.rule)))))
                : h('span'),
            pageLink(),
        ),
    ]));
}

function renderGroup(group) {
    const message = group.message;
    const pending = group.proposals.filter((block) => block.status === 'pending');
    const head = message.subject === null
        ? [h('p', { class: 'gw-mail-head' }, message.id)]
        : [
            h('p', { class: 'gw-mail-head' }, h('span', { class: 'gw-sender' }, message.sender), ` · ${message.subject} · `, h('time', { datetime: message.date }, message.time)),
            fold(`mail:${message.id}`, 'The mail',
                h('div', { class: 'gw-mail-body' },
                    h('pre', null, message.body),
                    message.attachments.length > 0 ? h('p', { class: 'gw-attachments' }, message.attachments.join(', ')) : null,
                ),
            ),
        ];

    const sendingAll = pending.length > 0 && pending.every((block) => state.sending.has(block.id));

    return h('section', { class: 'gw-group' },
        h('div', { class: 'gw-mail' }, head),
        group.proposals.map(renderBlock),
        pending.length > 1 && !state.readOnly
            ? h('div', { class: 'gw-group-actions' },
                h('button', {
                    type: 'button',
                    class: 'gw-button gw-button--primary',
                    disabled: state.sending.size > 0,
                    onclick: () => decideAll(pending),
                }, sendingAll ? 'Recording' : `Yes to all ${pending.length}`))
            : null,
    );
}

function renderBlock(block) {
    const pending = block.status === 'pending';
    const decidable = pending && !state.readOnly;
    const problem = state.problems.get(block.id) ?? null;
    const head = h('div', { class: 'pb-head' },
        h('h3', { class: 'pb-title' }, block.title),
        block.line !== null ? h('p', { class: 'pb-line' }, block.line) : null,
    );

    if (!pending) {
        return h('article', { class: 'pb', 'data-status': block.status }, head, problem ? h('p', { class: 'pb-error', role: 'alert' }, problem) : null);
    }

    return h('article', { class: 'pb', 'data-status': block.status },
        head,
        block.because !== null ? h('p', { class: 'pb-because' }, block.because) : null,
        fold(`summary:${block.id}`, "The agent's summary", h('p', { class: 'pb-summary' }, block.summary)),
        renderEffect(block),
        decidable ? renderDecide(block, problem) : (problem ? h('p', { class: 'pb-error', role: 'alert' }, problem) : null),
    );
}

function renderEffect(block) {
    const effect = block.effect;
    const quote = block.quote;
    const hasNow = effect.rows.some((row) => row.now !== null);
    const cells = hasNow ? [h('span', { class: 'pb-corner' }), h('span', { class: 'pb-col' }, 'Now'), h('span', { class: 'pb-col' }, 'After')] : [];

    for (const row of effect.rows) {
        cells.push(h('span', { class: 'pb-label' }, row.label));

        if (hasNow) {
            cells.push(h('span', { class: 'pb-now' }, row.now));
        }

        cells.push(h('span', { class: 'pb-after' }, h('strong', null, row.after), row.note !== null ? h('span', { class: 'pb-note' }, row.note) : null));

        if (row.warning !== null) {
            cells.push(h('p', { class: 'pb-warning' }, row.warning));
        }
    }

    return h('section', { class: 'pb-effect' },
        h('h4', { class: 'pb-heading' }, 'If you say yes, Odoo changes this'),
        h('p', { class: 'pb-record' }, effect.kind !== null ? [h('span', { class: 'pb-kind' }, effect.kind), ' '] : null, effect.record),
        h('div', { class: hasNow ? 'pb-grid' : 'pb-grid pb-grid--values' }, cells),
        effect.notice !== null ? h('p', { class: 'pb-notice' }, effect.notice) : null,
        fold(`why:${block.id}`, 'Why, and where it says so',
            h('p', { class: 'pb-quote' }, `"${quote.text}"`),
            quote.document !== null ? h('p', { class: 'pb-source' }, `${quote.document}, ${quote.location}`) : null,
            quote.check !== null ? h('p', null, quote.check) : null,
            block.reasons.length > 0 ? h('ul', { class: 'pb-reasons' }, block.reasons.map((reason) => h('li', null, reason))) : null,
        ),
    );
}

function renderDecide(block, problem) {
    const sending = state.sending.get(block.id) ?? null;
    const busy = state.sending.size > 0;
    const no = h('button', {
        type: 'button',
        class: 'gw-button',
        disabled: busy || (state.reasons.get(block.id) ?? '').trim() === '',
        onclick: () => decideOne(block, 'reject'),
    }, sending === 'reject' ? 'Recording' : 'No');
    const reason = h('input', {
        type: 'text',
        class: 'gw-input',
        placeholder: 'Why, if no',
        'aria-label': 'Why, if no',
        maxlength: 2000,
        oninput: (event) => {
            state.reasons.set(block.id, event.target.value);
            no.disabled = busy || event.target.value.trim() === '';
        },
    });

    reason.value = state.reasons.get(block.id) ?? '';

    return h('form', { class: 'pb-decide', onsubmit: (event) => event.preventDefault() },
        reason,
        problem ? h('p', { class: 'pb-error', role: 'alert' }, problem) : null,
        h('div', { class: 'pb-buttons' },
            h('button', {
                type: 'button',
                class: 'gw-button gw-button--primary',
                disabled: busy,
                onclick: () => decideOne(block, 'approve'),
            }, sending === 'approve' ? 'Recording' : 'Yes, do it'),
            no,
        ),
    );
}

async function load() {
    try {
        const response = await fetch(`${config.gateway}/card/proposals`, {
            headers: { 'X-Card-Token': config.token, Accept: 'application/json' },
            cache: 'no-store',
        });

        if (response.status === 401) {
            state.notice = EXPIRED;
            state.readOnly = true;
        } else if (!response.ok) {
            state.notice = UNREACHABLE;
        } else {
            state.data = await response.json();
            state.notice = null;
        }
    } catch {
        state.notice = UNREACHABLE;
    }

    render();
}

/**
 * Posts one decision and redraws from the answer.
 *
 * @returns {Promise<string|null>} the sentence for the agent, or null when nothing was decided
 */
async function post(block, decision) {
    const reason = (state.reasons.get(block.id) ?? '').trim();
    let response;
    let body;

    try {
        response = await fetch(`${config.gateway}/card/proposals/${block.id}/decision`, {
            method: 'POST',
            headers: { 'X-Card-Token': config.token, 'Content-Type': 'application/json', Accept: 'application/json' },
            body: JSON.stringify({ decision, reason: reason === '' ? null : reason }),
            cache: 'no-store',
        });
        body = await response.json();
    } catch {
        state.problems.set(block.id, UNREACHABLE);

        return null;
    }

    if (response.status === 401) {
        state.notice = EXPIRED;
        state.readOnly = true;

        return null;
    }

    if (body.proposal) {
        replaceBlock(body.proposal);
    }

    if (typeof body.heading === 'string') {
        state.data.heading = body.heading;
        state.data.waiting = body.waiting;
    }

    if (body.error) {
        state.problems.set(block.id, body.error);
    } else {
        state.problems.delete(block.id);
        state.reasons.delete(block.id);
    }

    return body.sentence ?? null;
}

async function decideOne(block, decision) {
    state.sending.set(block.id, decision);
    render();

    const sentence = await post(block, decision);

    state.sending.delete(block.id);
    render();
    tell(sentence === null ? [] : [sentence]);
}

/** "Yes to all": one decision per pending proposal, each with its own audit rows. */
async function decideAll(blocks) {
    blocks.forEach((block) => state.sending.set(block.id, 'approve'));
    render();

    const sentences = [];

    for (const block of blocks) {
        if (state.readOnly) {
            break;
        }

        const sentence = await post(block, 'approve');

        if (sentence !== null) {
            sentences.push(sentence);
        }
    }

    state.sending.clear();
    render();
    tell(sentences);
}

function replaceBlock(updated) {
    for (const group of state.data.groups) {
        const index = group.proposals.findIndex((block) => block.id === updated.id);

        if (index !== -1) {
            group.proposals[index] = updated;
        }
    }
}

/** Tells the agent what was decided on this card. list_proposals stays the authority. */
function tell(sentences) {
    if (sentences.length === 0) {
        return;
    }

    state.told.push(...sentences);
    bridge?.updateModelContext({ content: [{ type: 'text', text: state.told.join(' ') }] }).catch(() => {});
}

// Outside a host there is no bridge, and the card still reads and decides on its own.
if (window.parent !== window && typeof createMcpApp === 'function') {
    createMcpApp(async (app) => {
        bridge = app;
        app.autoResize();
    }).catch(() => {
        bridge = null;
    });
}

render();
load();
