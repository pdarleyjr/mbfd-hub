import { useLayoutEffect, useMemo, useRef, useState, type CSSProperties, type RefObject } from 'react';
import type { Participant } from 'livekit-client';
import { computeGridLayout, computePresentationLayout, type Size } from './gallery-layout';
import type { GallerySlot } from './gallery-order';
import { ParticipantTile } from './ParticipantTile';
import { PlaceholderTile } from './PlaceholderTile';

interface ConferenceStageProps {
    slots: Array<GallerySlot<Participant>>;
    focusedIdentity: string | null;
    localIdentity: string | undefined;
    refreshKey: number;
    liveCount: number;
    onToggleFocus: (identity: string) => void;
}

function useElementSize(ref: RefObject<HTMLElement | null>): Size {
    const [size, setSize] = useState<Size>({ width: 0, height: 0 });

    useLayoutEffect(() => {
        const element = ref.current;
        if (!element) return;
        const measure = () => setSize((previous) =>
            previous.width === element.clientWidth && previous.height === element.clientHeight
                ? previous
                : { width: element.clientWidth, height: element.clientHeight });
        measure();
        if (typeof ResizeObserver === 'undefined') {
            window.addEventListener('resize', measure);

            return () => window.removeEventListener('resize', measure);
        }
        const observer = new ResizeObserver(measure);
        observer.observe(element);

        return () => observer.disconnect();
    }, [ref]);

    return size;
}

type StageVariables = CSSProperties & Record<`--vc-${string}`, string | number>;

export function ConferenceStage({ slots, focusedIdentity, localIdentity, refreshKey, liveCount, onToggleFocus }: ConferenceStageProps) {
    const galleryRef = useRef<HTMLDivElement>(null);
    const size = useElementSize(galleryRef);
    const focusedSlot = focusedIdentity === null
        ? undefined
        : slots.find((slot) => slot.kind === 'participant' && slot.identity === focusedIdentity);
    const thumbnails = useMemo(
        () => slots.filter((slot) => slot !== focusedSlot),
        [focusedSlot, slots],
    );

    const renderSlot = (slot: GallerySlot<Participant>) => slot.kind === 'placeholder'
        ? <PlaceholderTile key={slot.key} label={slot.label} identity={slot.identity} />
        : <ParticipantTile
            key={slot.key}
            participant={slot.participant}
            local={slot.identity === localIdentity}
            refreshKey={refreshKey}
            focused={slot.identity === focusedIdentity}
            onFocus={() => onToggleFocus(slot.identity)}
        />;

    if (focusedSlot) {
        const layout = computePresentationLayout(thumbnails.length, size);
        const style: StageVariables = {
            height: size.height,
            width: size.width,
            '--vc-focus-w': `${layout.focus.width}px`,
            '--vc-thumb-w': `${layout.thumb.width}px`,
        };
        const filmstripStyle: CSSProperties = layout.orientation === 'rail'
            ? { width: layout.filmstripExtent }
            : { height: layout.filmstripExtent };

        return (
            <div ref={galleryRef} className="vc-gallery vc-gallery--presentation" data-count={liveCount} data-layout={layout.orientation} aria-label="Presentation layout">
                {size.width > 0 && <div className={`vc-stage vc-stage--${layout.orientation}`} style={style}>
                    <div className="vc-stage__focus">{renderSlot(focusedSlot)}</div>
                    {thumbnails.length > 0 && <div className="vc-filmstrip" style={filmstripStyle} aria-label="Other participants">{thumbnails.map(renderSlot)}</div>}
                </div>}
            </div>
        );
    }

    const layout = computeGridLayout(slots.length, size);
    const style: StageVariables = {
        '--vc-cols': layout.columns,
        '--vc-tile-w': `${layout.tileWidth}px`,
    };

    return (
        <div ref={galleryRef} className="vc-gallery vc-gallery--grid" data-count={liveCount} data-slots={slots.length} data-scroll={layout.scroll} aria-label="Participant gallery" style={style}>
            {size.width > 0 && slots.map(renderSlot)}
        </div>
    );
}
