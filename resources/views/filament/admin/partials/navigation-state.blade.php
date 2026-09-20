<script>
    (() => {
        const versionKey = 'mbfd-admin-navigation-state-version';
        const version = @js($version);

        if (localStorage.getItem(versionKey) !== version) {
            localStorage.setItem('collapsedGroups', JSON.stringify(@js($groups)));
            localStorage.setItem(versionKey, version);
        }
    })();
</script>
