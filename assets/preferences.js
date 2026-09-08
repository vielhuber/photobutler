{
    try {
        let width = Number(localStorage.getItem('photobutler.sidebarWidth'));
        if (Number.isFinite(width) && width > 0) {
            let maximum = Math.max(210, Math.min(640, innerWidth - 360));
            document.documentElement.style.setProperty(
                '--sidebar-width',
                `${Math.round(Math.max(210, Math.min(maximum, width)))}px`
            );
        }
        let columns = localStorage.getItem('photobutler.galleryColumns');
        if (['5', '6', '7'].includes(columns)) {
            document.documentElement.style.setProperty('--gallery-columns', columns);
        }
    } catch {}
}
