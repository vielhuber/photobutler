export class DetailPreloader {
    static NEIGHBOURS = 2;
    static PARALLEL_DOWNLOADS = 2;

    queue = [];
    active = new Map();
    attempted = new Set();
    hoveredUrl = null;
    paused = false;
    disposed = false;

    update($cards, index = null, paused = false, $hovered = null) {
        if (this.disposed) return;
        let $candidates = [];
        if (index !== null && index >= 0 && index < $cards.length) {
            for (let distance = 1; distance <= DetailPreloader.NEIGHBOURS; distance++) {
                for (let neighbour of [index + distance, index - distance]) {
                    if ($cards[neighbour]) $candidates.push($cards[neighbour]);
                }
            }
        }
        let prioritizeHover = index === null && $cards.includes($hovered);
        if (prioritizeHover) $candidates.push($hovered);
        this.queue = [...new Set($candidates.map($card => `?photo=${$card.dataset.photo}&size=original`))];
        this.hoveredUrl = prioritizeHover ? this.queue[0] : null;
        for (let [url, $image] of this.active) {
            $image.fetchPriority = url === this.hoveredUrl ? 'high' : 'low';
        }
        this.paused = paused;
        this.drain();
    }

    drain() {
        if (this.paused || this.disposed) return;
        while (this.active.size < DetailPreloader.PARALLEL_DOWNLOADS && this.queue.length) {
            let url = this.queue.shift();
            if (this.attempted.has(url)) continue;
            this.attempted.add(url);
            let $image = new Image();
            this.active.set(url, $image);
            $image.fetchPriority = url === this.hoveredUrl ? 'high' : 'low';
            $image.onload = $image.onerror = () => {
                $image.onload = $image.onerror = null;
                this.active.delete(url);
                this.drain();
            };
            $image.src = url;
        }
    }

    dispose() {
        this.disposed = true;
        this.queue = [];
        for (let $image of this.active.values()) {
            $image.onload = $image.onerror = null;
            $image.removeAttribute('src');
        }
        this.active.clear();
    }
}
