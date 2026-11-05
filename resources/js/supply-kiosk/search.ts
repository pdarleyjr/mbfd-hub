// Supply room search: plain TypeScript with no dependencies, so Node can load it for tests.
// About 300 items are scored by brute force on every keystroke; a query takes well under a millisecond or two.
import { RELATED, STOPWORDS, SYNONYM_GROUPS } from './search-dictionary.ts';

export interface SearchableItem {
    id: number;
    name: string;
    location_code?: string | null;
    location_label?: string | null;
    section_label?: string | null;
    category?: string | null;
    aliases?: readonly string[] | null;
    learned_aliases?: readonly string[] | null;
    item_numbers?: readonly string[] | null;
    popularity?: number | null;
}

export type Shorthand = Readonly<Record<string, readonly string[]>>;

// Field weights. Ranking: exact name > name word starts > aliases/shorthand > category/description > fuzzy.
const W_NAME = 100;
const W_NUMBER = 100;
const W_ALIAS = 75;
const W_CONCEPT = 70;
const W_CATEGORY = 40;
const W_RELATED = 35;
const W_LOCATION = 30;
// Match quality.
const Q_EXACT = 1;
const Q_PREFIX = 0.8;
const Q_JOINED = 0.9;
const Q_FUZZY_1 = 0.38;
const Q_SOUND = 0.35;
const Q_FUZZY_2 = 0.3;
const MIN_SCORE = 10;

type Posting = [itemIndex: number, weight: number];

export interface SearchIndex<T extends SearchableItem> {
    items: readonly T[];
    names: string[];
    phrases: Map<string, number>[];
    tokenIndex: Map<string, Posting[]>;
    conceptIndex: Map<number, Posting[]>;
    relatedIndex: Map<string, number[]>;
    wordGroups: Map<string, number[]>;
    phraseTerms: { tokens: string[]; group: number }[];
    vocab: string[];
    vocabSound: string[];
    vocabSet: Set<string>;
}

const SIZE_UNITS: Record<string, string> = { gauge: 'g', ga: 'g', g: 'g', french: 'fr', fr: 'fr', mm: 'mm', cm: 'cm', ml: 'ml', cc: 'ml', inch: 'inch' };
const SIZED = /^(\d+(?:\.\d+)?)(g|fr|mm|cm|ml|inch)$/;

/** Lowercase, strip accents, and join sizes ("18 gauge" -> "18g", "4 x 4" -> "4x4", "10 cc" -> "10ml"). */
export function normalize(text: string): string {
    return text
        .toLowerCase()
        .normalize('NFKD')
        .replace(/[̀-ͯ]/g, '')
        .replace(/(\d)\s*x\s*(\d)/g, '$1x$2')
        .replace(/(\d+(?:\.\d+)?)\s*(gauge|ga|g|french|fr|mm|cm|ml|cc|inch)\b/g, (_m, n: string, u: string) => `${n}${SIZE_UNITS[u]}`)
        .replace(/[^a-z0-9.]+/g, ' ')
        .trim();
}

export function tokenize(text: string): string[] {
    return normalize(text).match(/[a-z0-9]+(?:\.\d+)?[a-z0-9]*/g) ?? [];
}

/** A rough sound-alike key: "cathiter" and "catheter" both become "kthtr". */
export function soundKey(word: string): string {
    let s = word.replace(/[^a-z]/g, '');
    if (s === '') return '';
    s = s.replace(/ph/g, 'f').replace(/ck/g, 'k').replace(/q/g, 'k').replace(/x/g, 'ks').replace(/z/g, 's')
        .replace(/c(?=[eiy])/g, 's').replace(/c/g, 'k').replace(/dg/g, 'j').replace(/gh/g, 'g').replace(/kn/g, 'n');
    return (s[0] + s.slice(1).replace(/[aeiouyhw]/g, '')).replace(/(.)\1+/g, '$1');
}

let rowA = new Int32Array(64);
let rowB = new Int32Array(64);
let rowC = new Int32Array(64);

/** Damerau-Levenshtein (optimal string alignment) distance, giving up once it passes `max`. */
export function editDistance(a: string, b: string, max: number): number {
    const la = a.length;
    const lb = b.length;
    if (Math.abs(la - lb) > max) return max + 1;
    if (lb + 1 > rowA.length) { rowA = new Int32Array(lb + 1); rowB = new Int32Array(lb + 1); rowC = new Int32Array(lb + 1); }
    let before = rowA; let prev = rowB; let cur = rowC;
    for (let j = 0; j <= lb; j++) prev[j] = j;
    for (let i = 1; i <= la; i++) {
        cur[0] = i;
        let rowMin = i;
        for (let j = 1; j <= lb; j++) {
            const cost = a.charCodeAt(i - 1) === b.charCodeAt(j - 1) ? 0 : 1;
            let v = Math.min(prev[j] + 1, cur[j - 1] + 1, prev[j - 1] + cost);
            if (i > 1 && j > 1 && a.charCodeAt(i - 1) === b.charCodeAt(j - 2) && a.charCodeAt(i - 2) === b.charCodeAt(j - 1)) v = Math.min(v, before[j - 2] + 1);
            cur[j] = v;
            if (v < rowMin) rowMin = v;
        }
        if (rowMin > max) return max + 1;
        const spare = before; before = prev; prev = cur; cur = spare;
    }
    return prev[lb];
}

/** Item tokens plus the bare number of every size ("18g" also gives "18") and each joined word pair. */
function fieldTokens(text: string): { tokens: string[]; extra: string[] } {
    const tokens = tokenize(text);
    const extra: string[] = [];
    tokens.forEach((t, i) => {
        const sized = SIZED.exec(t);
        if (sized) extra.push(sized[1]);
        if (i > 0) extra.push(tokens[i - 1] + t);
    });
    return { tokens, extra };
}

function containsRun(haystack: readonly string[], needle: readonly string[]): boolean {
    outer: for (let i = 0; i + needle.length <= haystack.length; i++) {
        for (let k = 0; k < needle.length; k++) if (haystack[i + k] !== needle[k]) continue outer;
        return true;
    }
    return false;
}

function addPosting<K>(map: Map<K, Posting[]>, key: K, item: number, weight: number): void {
    const list = map.get(key);
    if (!list) { map.set(key, [[item, weight]]); return; }
    const last = list[list.length - 1];
    if (last[0] === item) { if (weight > last[1]) last[1] = weight; return; }
    list.push([item, weight]);
}

function buildGroups(shorthand: Shorthand): string[][][] {
    const groups: string[][][] = SYNONYM_GROUPS.map(terms => terms.map(tokenize).filter(t => t.length > 0));
    for (const [key, values] of Object.entries(shorthand ?? {})) {
        const terms = [key, ...(Array.isArray(values) ? values : [])].map(tokenize).filter(t => t.length > 0);
        if (terms.length > 1) groups.push(terms);
    }
    return groups;
}

/** Build once per bootstrap. Index each item by name, aliases, learned aliases, item numbers, section, category and location. */
export function buildSearchIndex<T extends SearchableItem>(items: readonly T[], shorthand: Shorthand = {}): SearchIndex<T> {
    const groups = buildGroups(shorthand);
    const tokenIndex = new Map<string, Posting[]>();
    const conceptIndex = new Map<number, Posting[]>();
    const relatedIndex = new Map<string, number[]>();
    const wordGroups = new Map<string, number[]>();
    const phraseTerms: { tokens: string[]; group: number }[] = [];
    groups.forEach((terms, g) => terms.forEach(tokens => {
        if (tokens.length === 1) wordGroups.set(tokens[0], [...(wordGroups.get(tokens[0]) ?? []), g]);
        else phraseTerms.push({ tokens, group: g });
    }));
    const names: string[] = [];
    const phrases: Map<string, number>[] = [];
    const strongTokens: Set<string>[] = [];
    const strongRuns: string[][][] = [];

    items.forEach((item, i) => {
        const name = fieldTokens(item.name);
        names.push(name.tokens.join(' '));
        const aliasTexts = [...(item.aliases ?? []), ...(item.learned_aliases ?? [])];
        const aliases = aliasTexts.map(fieldTokens);
        const categoryRuns = [item.section_label, item.category].filter((v): v is string => !!v).map(tokenize);
        const locationTokens = tokenize(`${item.location_code ?? ''} ${item.location_label ?? ''}`);
        const own = new Map<string, number>();
        phrases.push(own);
        own.set(names[i], 1000);
        aliases.forEach(a => { const p = a.tokens.join(' '); if (p && !own.has(p)) own.set(p, 400); });

        locationTokens.forEach(t => addPosting(tokenIndex, t, i, W_LOCATION));
        categoryRuns.flat().forEach(t => addPosting(tokenIndex, t, i, W_CATEGORY));
        aliases.forEach(a => { a.tokens.forEach(t => addPosting(tokenIndex, t, i, W_ALIAS)); a.extra.forEach(t => addPosting(tokenIndex, t, i, W_ALIAS * Q_JOINED)); });
        name.extra.forEach(t => addPosting(tokenIndex, t, i, SIZED.test(t) || /^\d/.test(t) ? W_NAME : W_ALIAS));
        name.tokens.forEach(t => addPosting(tokenIndex, t, i, W_NAME));
        for (const raw of item.item_numbers ?? []) {
            const number = normalize(String(raw)).replace(/\s+/g, '');
            if (number === '') continue;
            addPosting(tokenIndex, number, i, W_NUMBER);
            if (!own.has(number)) own.set(number, 1000);
        }

        const strong = new Set([...name.tokens, ...name.extra, ...aliases.flatMap(a => [...a.tokens, ...a.extra])]);
        const runs = [name.tokens, ...aliases.map(a => a.tokens)];
        strongTokens.push(strong);
        strongRuns.push(runs);
        groups.forEach((terms, g) => {
            for (const term of terms) {
                if (term.length === 1 ? strong.has(term[0]) : runs.some(run => containsRun(run, term))) { addPosting(conceptIndex, g, i, W_CONCEPT); return; }
            }
            for (const term of terms) {
                if (categoryRuns.some(run => containsRun(run, term))) { addPosting(conceptIndex, g, i, W_CATEGORY * 0.9); return; }
            }
        });
    });

    for (const [key, terms] of Object.entries(RELATED)) {
        const termTokens = terms.map(tokenize);
        const hits: number[] = [];
        items.forEach((_item, i) => {
            if (termTokens.some(t => (t.length === 1 ? strongTokens[i].has(t[0]) : strongRuns[i].some(run => containsRun(run, t))))) hits.push(i);
        });
        relatedIndex.set(key, hits);
    }

    const vocabSet = new Set<string>([...tokenIndex.keys(), ...wordGroups.keys(), ...Object.keys(RELATED)]);
    for (const { tokens } of phraseTerms) tokens.forEach(t => vocabSet.add(t));
    const vocab = [...vocabSet];
    return { items, names, phrases, tokenIndex, conceptIndex, relatedIndex, wordGroups, phraseTerms, vocab, vocabSound: vocab.map(soundKey), vocabSet };
}

/** Words in the index that a typed token could mean, with how sure we are (1 = exact). */
function alternatives<T extends SearchableItem>(index: SearchIndex<T>, token: string, isLast: boolean): Map<string, number> {
    const alts = new Map<string, number>();
    const put = (word: string, q: number) => { if ((alts.get(word) ?? 0) < q) alts.set(word, q); };
    const exact = index.vocabSet.has(token);
    if (exact) put(token, Q_EXACT);
    const allowPrefix = token.length >= 2 || isLast;
    const allowFuzzy = !exact && token.length >= 4 && !/\d/.test(token);
    const maxDistance = token.length >= 8 ? 2 : 1;
    const sound = allowFuzzy ? soundKey(token) : '';
    for (let v = 0; v < index.vocab.length; v++) {
        const word = index.vocab[v];
        if (allowPrefix && word.length > token.length && word.startsWith(token)) { put(word, Q_PREFIX); continue; }
        if (!allowFuzzy || word.length < 4) continue;
        const distance = editDistance(token, word, maxDistance);
        if (distance <= maxDistance) put(word, distance === 1 ? Q_FUZZY_1 : Q_FUZZY_2);
        // Sound-alike only backs up a near miss ("cathetur"), never a word that merely shares consonants.
        else if (sound !== '' && index.vocabSound[v] === sound && editDistance(token, word, maxDistance + 1) <= maxDistance + 1) put(word, Q_SOUND);
    }
    return alts;
}

function raise(best: Float64Array, postings: readonly Posting[] | undefined, q: number): void {
    if (!postings) return;
    for (const [i, weight] of postings) { const v = weight * q; if (v > best[i]) best[i] = v; }
}

export interface SearchHit<T> { item: T; score: number }

/** Score every item against the query. Returns the best matches, highest first; ties go to the most picked item. */
export function searchScored<T extends SearchableItem>(index: SearchIndex<T>, query: string, limit = 40): SearchHit<T>[] {
    const all = tokenize(query);
    const meaningful = all.filter(t => !STOPWORDS.has(t));
    const tokens = meaningful.length > 0 ? meaningful : all;
    const n = index.items.length;
    if (tokens.length === 0 || n === 0) return [];

    const perToken = tokens.map(() => new Float64Array(n));
    tokens.forEach((token, k) => {
        const best = perToken[k];
        for (const [word, q] of alternatives(index, token, k === tokens.length - 1)) {
            raise(best, index.tokenIndex.get(word), q);
            for (const g of index.wordGroups.get(word) ?? []) raise(best, index.conceptIndex.get(g), q);
            for (const i of index.relatedIndex.get(word) ?? []) if (W_RELATED * q > best[i]) best[i] = W_RELATED * q;
        }
    });
    // Two typed words that are one indexed word ("ez io" -> "ezio") or one dictionary phrase ("bag valve mask").
    for (let k = 1; k < tokens.length; k++) {
        const joined = index.tokenIndex.get(tokens[k - 1] + tokens[k]);
        if (joined) { raise(perToken[k - 1], joined, Q_JOINED); raise(perToken[k], joined, Q_JOINED); }
    }
    for (const { tokens: term, group } of index.phraseTerms) {
        for (let s = 0; s + term.length <= tokens.length; s++) {
            if (!term.every((t, k) => tokens[s + k] === t)) continue;
            for (let k = s; k < s + term.length; k++) raise(perToken[k], index.conceptIndex.get(group), Q_EXACT);
        }
    }

    const phrase = tokens.join(' ');
    const hits: SearchHit<T>[] = [];
    for (let i = 0; i < n; i++) {
        let score = 0;
        let matched = 0;
        for (const best of perToken) if (best[i] > 0) { score += best[i]; matched++; }
        if (matched === 0) continue;
        if (tokens.length > 1) score *= matched === tokens.length ? 1 : matched / tokens.length;
        if (matched === tokens.length && tokens.length > 1) score += 25 * tokens.length;
        score += index.phrases[i].get(phrase) ?? 0;
        if (phrase.length >= 2 && index.names[i] !== phrase && index.names[i].startsWith(phrase)) score += 150;
        if (score >= MIN_SCORE) hits.push({ item: index.items[i], score });
    }
    hits.sort((a, b) => b.score - a.score || (b.item.popularity ?? 0) - (a.item.popularity ?? 0) || a.item.name.localeCompare(b.item.name));
    return hits.slice(0, limit);
}

export function searchItems<T extends SearchableItem>(index: SearchIndex<T>, query: string, limit = 40): T[] {
    return searchScored(index, query, limit).map(hit => hit.item);
}
