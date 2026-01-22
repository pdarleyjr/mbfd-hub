<script>
    function onStart() {
        setTimeout(() => document.body.classList.add("grabbing"))
    }

    function onEnd() {
        document.body.classList.remove("grabbing")
    }

    function setData(dataTransfer, el) {
        dataTransfer.setData('id', el.id)
    }

    function onAdd(e) {
        const recordId = e.item.id
        const status = e.to.dataset.statusId
        const fromOrderedIds = [].slice.call(e.from.children).map(child => child.id)
        const toOrderedIds = [].slice.call(e.to.children).map(child => child.id)

        Livewire.dispatch('status-changed', {recordId, status, fromOrderedIds, toOrderedIds})
    }

    function onUpdate(e) {
        const recordId = e.item.id
        const status = e.from.dataset.statusId
        const orderedIds = [].slice.call(e.from.children).map(child => child.id)

        Livewire.dispatch('sort-changed', {recordId, status, orderedIds})
    }

    function initSortables() {
        const statuses = @js($statuses->pluck('id')->values()->toArray());
        statuses.forEach(status => {
            const el = document.querySelector(`[data-status-id='${status}']`);
            if (el && !el._sortableInstance) {
                el._sortableInstance = Sortable.create(el, {
                    group: 'filament-kanban',
                    ghostClass: 'opacity-50',
                    animation: 150,
                    onStart,
                    onEnd,
                    onUpdate,
                    setData,
                    onAdd,
                });
            }
        });
    }

    if (document.readyState === 'loading') {
        document.addEventListener('DOMContentLoaded', initSortables);
    } else {
        initSortables();
    }
    document.addEventListener('livewire:navigated', initSortables);
</script>
