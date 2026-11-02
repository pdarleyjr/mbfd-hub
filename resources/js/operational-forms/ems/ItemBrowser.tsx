import { useEffect, useMemo, useState } from 'react';
import { Button } from '@fluentui/react-components';
import { ClipboardList } from 'lucide-react';
import {
    filterEntered, formName, groupSections, isEntered, itemDomId, itemsLabel, lineKey, requestedInSections, searchResults, sectionNotes, variantCaption,
} from './emsLogic.js';
import type { EmsDraft, EmsForm, EmsFormDefinition, EmsFormSection } from './emsTypes';
import { CategoryPanel, ItemRow } from './FormParts';

/** Results shown at a time while searching; a broad word ("tube") can match hundreds of rows. */
const RESULT_PAGE = 60;

export interface ItemBrowserProps {
    definition: EmsFormDefinition;
    draft: EmsDraft;
    /** The words being searched for (already deferred); '' shows the lists. */
    query: string;
    enteredOnly: boolean;
    /** Keys that stay in "Entered only" even if just cleared, so a row does not vanish while it is being retyped. */
    pinned: ReadonlySet<string>;
    /** The list on screen (one at a time). */
    bagKey: string;
    onChooseBag: (key: string) => void;
    isOpen: (formKey: string, sectionKey: string) => boolean;
    onTogglePanel: (formKey: string, sectionKey: string) => void;
    onSetAllPanels: (form: EmsForm, open: boolean) => void;
    onJump: (formKey: string, sectionKey: string) => void;
    controlledNote: string;
    onType: (key: string, raw: string) => void;
    onBump: (key: string, delta: number) => void;
    onAnswer: (key: string, value: boolean | null) => void;
    /** Reports how many items match the search, for the count under the search field. */
    onMatches: (matches: number) => void;
}

const rowIdOf = (formKey: string, itemKey: string) => itemDomId(formKey, itemKey);

export function ItemBrowser(props: ItemBrowserProps) {
    const { definition, draft, query, enteredOnly, pinned, onMatches } = props;
    const searching = query.trim() !== '';
    const results = useMemo(() => (searching ? searchResults(definition.forms, query) : null), [definition.forms, query, searching]);
    const rows = useMemo(() => {
        if (!results) return null;
        return enteredOnly ? results.rows.filter((row) => isEntered(draft, row.form.key, row.item.item_key) || pinned.has(lineKey(row.form.key, row.item.item_key))) : results.rows;
    }, [results, enteredOnly, draft, pinned]);

    const total = rows?.length ?? 0;
    useEffect(() => { onMatches(total); }, [onMatches, total]);

    return <>
        {!searching && <ListSwitcher {...props} />}
        {rows ? <SearchResults {...props} rows={rows} /> : <Sections {...props} />}
    </>;
}

// ---------------------------------------------------------------- list switcher

/** One list on screen at a time (Airway Bag, ALS Med Bag/Box, ...), each with how many items are entered in it. */
function ListSwitcher({ definition, draft, bagKey, onChooseBag }: ItemBrowserProps) {
    if (definition.forms.length < 2) return null;
    return <nav id="ems-bags" className="ems-bags" aria-label="Choose a list">
        {definition.forms.map((form) => {
            const entered = requestedInSections(draft, form.key, form.sections);
            const total = form.sections.reduce((sum, section) => sum + section.items.length, 0);
            return <button key={form.key} type="button" className="ems-bag" aria-pressed={form.key === bagKey} onClick={() => onChooseBag(form.key)}>
                <span className="ems-bag-name">{formName(form)}</span>
                <span className="ems-bag-meta">{itemsLabel(total)}{entered > 0 && <span className="ems-count-chip">{entered} entered</span>}</span>
            </button>;
        })}
    </nav>;
}

// ---------------------------------------------------------------- search results

/** Every match across the lists of this form, flat, each saying which list and section it is in. */
function SearchResults({ draft, rows, controlledNote, onType, onBump, onAnswer, enteredOnly }: ItemBrowserProps & { rows: ReturnType<typeof searchResults>['rows'] }) {
    const [limit, setLimit] = useState(RESULT_PAGE);
    const signature = rows.length > 0 ? `${rows[0].form.key}|${rows[0].item.item_key}|${rows.length}` : 'none';
    useEffect(() => { setLimit(RESULT_PAGE); }, [signature]);
    if (rows.length === 0) return enteredOnly ? <p className="ems-hint ems-results-empty">None of the matching items has an entry yet. Turn off Entered only to see them.</p> : null;
    const shown = rows.slice(0, limit);
    return <section className="ems-results" aria-label="Search results">
        <ul className="ems-items ems-results-list">
            {shown.map(({ form, item, context }) => {
                const key = lineKey(form.key, item.item_key);
                return <ItemRow
                    key={key} rowId={rowIdOf(form.key, item.item_key)} itemKey={key} item={item} qty={draft.quantities[key]} answer={draft.answers[key]}
                    caption="" context={context} controlledNote={controlledNote} onType={onType} onBump={onBump} onAnswer={onAnswer}
                />;
            })}
        </ul>
        {rows.length > shown.length && <div className="ems-more">
            <Button onClick={() => setLimit((count) => count + RESULT_PAGE)}>Show more ({rows.length - shown.length} more)</Button>
        </div>}
    </section>;
}

// ---------------------------------------------------------------- the list on screen, section by section

function Sections({ definition, draft, enteredOnly, pinned, bagKey, isOpen, onTogglePanel, onSetAllPanels, onJump, controlledNote, onType, onBump, onAnswer }: ItemBrowserProps) {
    const baseForm = definition.forms.find((form) => form.key === bagKey) ?? definition.forms[0];
    const entered = useMemo(() => (enteredOnly && baseForm ? filterEntered([baseForm], draft, pinned).forms[0] ?? null : null), [enteredOnly, baseForm, draft, pinned]);
    const form = enteredOnly ? entered : baseForm;
    if (!baseForm) return null;
    if (!form) return <p className="ems-hint ems-results-empty">Nothing is entered on {formName(baseForm)} yet. Turn off Entered only to see the list.</p>;

    const groups = groupSections(form.sections);
    // Entered only shows what was entered, all open: there is nothing to collapse.
    const open = (sectionKey: string) => enteredOnly || isOpen(form.key, sectionKey);
    const jumpable = groups.map(({ section, children }) => {
        const all = [section, ...children];
        return { key: section.key, label: section.label, entered: requestedInSections(draft, form.key, all) };
    });
    return <section className="ems-form" aria-labelledby={`ems-form-${form.key}`}>
        <header className="ems-form-head">
            <h2 id={`ems-form-${form.key}`}><ClipboardList size={18} aria-hidden="true" /> {formName(form)}</h2>
            <p className="ems-form-source">Source form: {form.title}{form.version_label && form.version_label !== 'undated' ? `, ${form.version_label}` : ''}</p>
            {form.printed_note && <p className="ems-printed-note">{form.printed_note}</p>}
            {groups.length > 1 && <div className="ems-form-tools">
                <label htmlFor="ems-jump" className="sr-only">Jump to section</label>
                <select id="ems-jump" className="ems-select ems-jump" value="" onChange={(event) => { if (event.target.value !== '') onJump(form.key, event.target.value); }}>
                    <option value="">Jump to section</option>
                    {jumpable.map((target) => <option key={target.key} value={target.key}>{target.label}{target.entered > 0 ? ` (${target.entered} entered)` : ''}</option>)}
                </select>
                {!enteredOnly && <>
                    <Button appearance="subtle" onClick={() => onSetAllPanels(form, true)}>Open all sections</Button>
                    <Button appearance="subtle" onClick={() => onSetAllPanels(form, false)}>Close all sections</Button>
                </>}
            </div>}
        </header>
        {groups.map(({ section, children }) => {
            const all: EmsFormSection[] = [section, ...children];
            const total = all.reduce((sum, part) => sum + part.items.length, 0);
            return <CategoryPanel
                key={section.key} panelId={`ems-panel-${form.key}-${section.key}`} toggleId={`ems-toggle-${form.key}-${section.key}`} title={section.label} total={total}
                requested={requestedInSections(draft, form.key, all)} open={open(section.key)} notes={sectionNotes(section)} onToggle={() => onTogglePanel(form.key, section.key)}
            >
                {all.map((part, partIndex) => <div key={part.key} className="ems-subsection">
                    {partIndex > 0 && <h4 className="ems-subhead">{part.label}</h4>}
                    {partIndex > 0 && sectionNotes(part).map((note) => <p className="ems-section-note" key={note}>{note}</p>)}
                    <ul className="ems-items">
                        {part.items.map((item, index) => {
                            const key = lineKey(form.key, item.item_key);
                            return <ItemRow
                                key={item.item_key} rowId={rowIdOf(form.key, item.item_key)} itemKey={key} item={item}
                                qty={draft.quantities[key]} answer={draft.answers[key]} caption={variantCaption(part.items, index)} controlledNote={controlledNote}
                                onType={onType} onBump={onBump} onAnswer={onAnswer}
                            />;
                        })}
                    </ul>
                </div>)}
            </CategoryPanel>;
        })}
    </section>;
}
