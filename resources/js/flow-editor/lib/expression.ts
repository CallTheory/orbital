// Expression editor types + client-side tokenizer for syntax
// highlighting. Mirrors (a subset of) the PHP ExpressionParser's
// tokenizer — just enough to color identifiers / functions / strings
// / numbers / operators in the editor overlay.

export type Mode = 'expression' | 'template';

export type Token =
    | { kind: 'text';       text: string }  // plain text (template mode, outside {{ }})
    | { kind: 'brace';      text: string }  // {{ or }}
    | { kind: 'string';     text: string }
    | { kind: 'number';     text: string }
    | { kind: 'keyword';    text: string }  // true/false/null
    | { kind: 'function';   text: string }
    | { kind: 'slot';       text: string }
    | { kind: 'context';    text: string }
    | { kind: 'unknown';    text: string }  // identifier we couldn't classify
    | { kind: 'operator';   text: string }
    | { kind: 'punct';      text: string }
    | { kind: 'ws';         text: string };

export type KnownNames = {
    slots: Set<string>;
    contextRoots: Set<string>;   // first-segment context names (e.g. 'call', 'now', 'agent')
    functions: Set<string>;      // function names (case-folded)
};

const KEYWORDS = new Set(['true', 'false', 'null']);

/** Tokenize an expression (not a template) — just the stuff inside
 *  `{{ }}` or a whole `expression`-mode field. */
export function tokenizeExpression(source: string, known: KnownNames): Token[] {
    const out: Token[] = [];
    const n = source.length;
    let i = 0;

    while (i < n) {
        const c = source[i];

        // whitespace
        if (/\s/.test(c)) {
            let j = i;
            while (j < n && /\s/.test(source[j])) j++;
            out.push({ kind: 'ws', text: source.slice(i, j) });
            i = j;
            continue;
        }

        // strings
        if (c === '"' || c === "'") {
            const quote = c;
            let j = i + 1;
            while (j < n && source[j] !== quote) {
                if (source[j] === '\\' && j + 1 < n) j += 2;
                else j++;
            }
            if (j < n) j++; // closing quote
            out.push({ kind: 'string', text: source.slice(i, j) });
            i = j;
            continue;
        }

        // numbers
        if (/[0-9]/.test(c) || (c === '.' && /[0-9]/.test(source[i + 1] ?? ''))) {
            let j = i;
            while (j < n && /[0-9.]/.test(source[j])) j++;
            out.push({ kind: 'number', text: source.slice(i, j) });
            i = j;
            continue;
        }

        // identifier
        if (/[A-Za-z_]/.test(c)) {
            let j = i;
            while (j < n && /[A-Za-z0-9_]/.test(source[j])) j++;
            const word = source.slice(i, j);
            // Look ahead past whitespace for `(` → function call, or `.` → context path head.
            let k = j;
            while (k < n && /\s/.test(source[k])) k++;
            if (KEYWORDS.has(word)) {
                out.push({ kind: 'keyword', text: word });
            } else if (source[k] === '(') {
                out.push({ kind: 'function', text: word });
            } else if (source[k] === '.') {
                out.push({ kind: known.contextRoots.has(word) ? 'context' : 'unknown', text: word });
            } else if (known.slots.has(word)) {
                out.push({ kind: 'slot', text: word });
            } else if (known.contextRoots.has(word)) {
                out.push({ kind: 'context', text: word });
            } else {
                out.push({ kind: 'unknown', text: word });
            }
            i = j;
            continue;
        }

        // multi-char operators
        const two = source.slice(i, i + 2);
        if (['==', '!=', '<=', '>=', '&&', '||'].includes(two)) {
            out.push({ kind: 'operator', text: two });
            i += 2;
            continue;
        }

        if ('+-*/%<>!?:'.includes(c)) {
            out.push({ kind: 'operator', text: c });
            i++;
            continue;
        }

        if ('().,.'.includes(c)) {
            out.push({ kind: 'punct', text: c });
            i++;
            continue;
        }

        out.push({ kind: 'unknown', text: c });
        i++;
    }

    return out;
}

/** Tokenize a template — outside `{{ }}` is plain text; inside,
 *  delegate to `tokenizeExpression`. */
export function tokenizeTemplate(source: string, known: KnownNames): Token[] {
    const out: Token[] = [];
    const n = source.length;
    let i = 0;
    let textStart = 0;

    while (i < n) {
        if (source[i] === '{' && source[i + 1] === '{') {
            if (i > textStart) {
                out.push({ kind: 'text', text: source.slice(textStart, i) });
            }
            out.push({ kind: 'brace', text: '{{' });
            i += 2;
            // Find the closing `}}`.
            const close = source.indexOf('}}', i);
            const end = close === -1 ? n : close;
            const inner = source.slice(i, end);
            for (const t of tokenizeExpression(inner, known)) out.push(t);
            i = end;
            if (close !== -1) {
                out.push({ kind: 'brace', text: '}}' });
                i += 2;
            }
            textStart = i;
            continue;
        }
        i++;
    }
    if (textStart < n) {
        out.push({ kind: 'text', text: source.slice(textStart) });
    }

    return out;
}

export function escapeHtml(s: string): string {
    return s
        .replace(/&/g, '&amp;')
        .replace(/</g, '&lt;')
        .replace(/>/g, '&gt;');
}

/** Render a token stream as a string of `<span>`s with class names
 *  the CSS can color.  A trailing newline gets a U+200B so the
 *  overlay height matches the textarea's. */
export function renderHighlighted(tokens: Token[]): string {
    const spans = tokens.map((t) => {
        const cls = `oflow-tok oflow-tok--${t.kind}`;
        return `<span class="${cls}">${escapeHtml(t.text)}</span>`;
    });
    return spans.join('') + '\u200B';
}

/** Function names the editor recognises for highlighting. Mirrors the
 *  dispatch map in ExpressionEvaluator::callFunction. */
export const FUNCTION_NAMES: string[] = [
    'now', 'date', 'time', 'date_add', 'date_diff', 'date_part', 'format_date',
    'weekday', 'month', 'age', 'get_age', 'date_range', 'time_range',
    'upper', 'ucase', 'lower', 'lcase',
    'left', 'right', 'mid', 'substr',
    'index_of', 'instr', 'len', 'length',
    'replace', 'trim', 'ltrim', 'rtrim',
    'format_phone', 'normalize', 'split', 'concat',
    'abs', 'round', 'floor', 'ceil', 'sign', 'sgn', 'sqrt', 'sqr', 'min', 'max',
    'format_number', 'format_age', 'currency',
    'iif', 'if', 'is_empty', 'empty',
    'to_string', 'cstr', 'to_number', 'cdbl', 'to_bool', 'cbool', 'to_date', 'cdate',
];

/** Context-variable roots for highlighting (first segment of a
 *  dotted path). Matches what AgentFlowCompiler populates into the
 *  context dict server-side. */
export const CONTEXT_ROOTS: string[] = [
    'now', 'call', 'caller', 'agent', 'client',
];
