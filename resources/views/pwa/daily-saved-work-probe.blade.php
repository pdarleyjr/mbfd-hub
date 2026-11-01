<!doctype html>
<html lang="en">
<head><meta charset="utf-8"><title>MBFD Hub</title></head>
<body>
<script>
    // Read-only count of unsent Daily work in this origin's storage. Never creates the database: opening a name that
    // does not exist would make an empty one and collide with Dexie's versions, so it is looked up first. Reads keys
    // only, so photos and signatures are never loaded, and only a number leaves this page.
    (function () {
        var parentOrigin = @json($parentOrigin);
        var DRAFT_WINDOW_MS = 14 * 24 * 60 * 60 * 1000;
        function answer(pending) {
            window.parent.postMessage({ type: 'MBFD_DAILY_SAVED_WORK', pending: pending }, parentOrigin);
        }
        function countWaiting(store, add) {
            if (!store.indexNames.contains('status')) return;
            var cursor = store.index('status').openKeyCursor();
            cursor.onsuccess = function () {
                var current = cursor.result;
                if (!current) return;
                // The same two states Daily's own queue watcher counts. Work parked for a decision is never sent on its own.
                if (current.key === 'pending' || current.key === 'processing') add(1);
                current.continue();
            };
        }
        function read(database) {
            var names = ['dailyCheckoutSubmissions', 'pendingSubmissions', 'dailyCheckoutDrafts'].filter(function (name) {
                return database.objectStoreNames.contains(name);
            });
            if (names.length === 0) { database.close(); answer(0); return; }
            var pending = 0;
            function add(count) { pending += count; }
            var transaction = database.transaction(names, 'readonly');
            names.forEach(function (name) {
                var store = transaction.objectStore(name);
                if (name !== 'dailyCheckoutDrafts') { countWaiting(store, add); return; }
                // A draft abandoned weeks ago should not nag forever.
                if (!store.indexNames.contains('timestamp')) return;
                var recent = store.index('timestamp').count(IDBKeyRange.lowerBound(Date.now() - DRAFT_WINDOW_MS));
                recent.onsuccess = function () { add(recent.result); };
            });
            transaction.oncomplete = function () { database.close(); answer(pending); };
            transaction.onerror = transaction.onabort = function () { database.close(); answer(0); };
        }
        try {
            if (!window.indexedDB || typeof indexedDB.databases !== 'function') { answer(0); return; }
            indexedDB.databases().then(function (list) {
                if (!list.some(function (entry) { return entry.name === 'mbfd-daily-checkout'; })) { answer(0); return; }
                var request = indexedDB.open('mbfd-daily-checkout');
                request.onsuccess = function () { read(request.result); };
                request.onerror = function () { answer(0); };
            }).catch(function () { answer(0); });
        } catch (_) { answer(0); }
    })();
</script>
</body>
</html>
