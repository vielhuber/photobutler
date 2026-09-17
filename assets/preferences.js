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
        if (['3', '4', '5', '6', '7', '8', '9'].includes(columns)) {
            document.documentElement.style.setProperty('--gallery-columns', columns);
            let observer = new MutationObserver(() => {
                let $option = document.querySelector(`#gallery-columns option[value="${columns}"]`);
                if (!$option) return;
                $option.selected = true;
                observer.disconnect();
            });
            observer.observe(document.documentElement, { childList: true, subtree: true });
        }
    } catch {}
}
